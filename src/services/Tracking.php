<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\events\TrackingUpdateEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrier\models\Shipment;
use justinholtweb\carrier\Plugin;
use Throwable;

/**
 * Tracking: polling, webhooks and the order write-back.
 *
 * **`apply()` is the only place tracking reaches a shipment.** A poll, a webhook, the CP's refresh
 * button and the console all end there, so the rules hold for every path: a scan seen twice is one
 * row (the unique `(shipmentId, hash)` index), a status never moves backwards (an "in transit"
 * webhook arriving after "delivered" changes nothing), and an exception always shows.
 */
class Tracking extends Component
{
    /** @event TrackingUpdateEvent when a shipment's tracking status changes */
    public const EVENT_STATUS_CHANGED = 'statusChanged';

    /**
     * Shipments whose next poll is due.
     *
     * @return Shipment[]
     */
    public function due(int $limit = 200): array
    {
        $now = Db::prepareDateForDb(new DateTime());

        return Plugin::getInstance()->getShipments()->hydrate((new Query())
            ->from(Table::SHIPMENTS)
            ->where(['status' => Shipment::STATUS_LABELLED])
            ->andWhere(['not', ['trackingNumber' => null]])
            ->andWhere(['<=', 'nextTrackAt', $now])
            ->orderBy(['nextTrackAt' => SORT_ASC])
            ->limit($limit)
            ->all());
    }

    /**
     * Poll the carrier for a set of shipments, batching tracking numbers per connection the way
     * each carrier allows. Returns how many shipments were updated.
     *
     * @param Shipment[] $shipments
     */
    public function refresh(array $shipments): int
    {
        $byConnection = [];

        foreach ($shipments as $shipment) {
            if ($shipment->connectionId && $shipment->trackingNumber) {
                $byConnection[$shipment->connectionId][] = $shipment;
            }
        }

        $updated = 0;

        foreach ($byConnection as $group) {
            $connection = $group[0]->getConnection();
            $carrier = $connection?->getCarrier();

            if (!$carrier || !$carrier::capabilities()->has(Feature::TRACKING)) {
                foreach ($group as $shipment) {
                    $this->reschedule($shipment, null);
                }

                continue;
            }

            /** @var array<string,Shipment> $byNumber */
            $byNumber = [];

            foreach ($group as $shipment) {
                $byNumber[$shipment->trackingNumber] = $shipment;
            }

            foreach (array_chunk(array_keys($byNumber), $carrier::capabilities()->getTrackingBatchSize()) as $numbers) {
                try {
                    $infos = $carrier->track(array_map('strval', $numbers));
                } catch (Throwable $e) {
                    Plugin::getInstance()->getLog()->error($connection, 'Tracking failed: ' . $e->getMessage());
                    $infos = [];
                }

                $seen = [];

                foreach ($infos as $info) {
                    $shipment = $byNumber[$info->trackingNumber] ?? null;

                    if ($shipment && !$info->notFound) {
                        $updated += (int)$this->apply($shipment, $info, 'poll');
                    }

                    $seen[$info->trackingNumber] = true;
                }

                foreach ($numbers as $number) {
                    if (!isset($seen[$number])) {
                        $this->reschedule($byNumber[$number], null);
                    }
                }
            }
        }

        return $updated;
    }

    /**
     * Record what a carrier said about one shipment.
     *
     * @return bool whether anything changed
     */
    public function apply(Shipment $shipment, TrackingInfo $info, string $source = 'poll'): bool
    {
        $newEvents = 0;

        foreach ($info->events as $event) {
            $status = TrackingStatus::isValid($event->status) ? $event->status : TrackingStatus::IN_TRANSIT;

            $hash = $event->hash($info->trackingNumber);

            if ((new Query())->from(Table::EVENTS)->where(['shipmentId' => $shipment->id, 'hash' => $hash])->exists()) {
                continue;
            }

            try {
                Db::insert(Table::EVENTS, [
                    'shipmentId' => $shipment->id,
                    'trackingNumber' => $info->trackingNumber,
                    'hash' => $hash,
                    'status' => $status,
                    'code' => mb_substr($event->code, 0, 64),
                    'description' => mb_substr($event->description, 0, 1000),
                    'location' => mb_substr($event->location, 0, 255),
                    'occurredAt' => $event->occurredAt ? Db::prepareDateForDb($event->occurredAt) : null,
                    'source' => $source,
                ]);
                $newEvents++;
            } catch (\yii\db\IntegrityException) {
                // A concurrent poll and webhook stored the same scan first. The index did its job.
            } catch (Throwable $e) {
                Craft::warning('Carrier could not store a tracking event: ' . $e->getMessage(), 'carrier');
            }
        }

        $previous = $shipment->trackingStatus;
        $status = TrackingStatus::isValid($info->status) ? $info->status : $previous;

        // Never backwards; an exception is always news.
        if ($status !== TrackingStatus::EXCEPTION && TrackingStatus::rank($status) < TrackingStatus::rank($previous)) {
            $status = $previous;
        }

        $changed = $status !== $previous;
        $shipment->trackingStatus = $status;
        $shipment->trackingStatusText = $info->statusText ?: TrackingStatus::label($status);
        $shipment->estimatedDelivery = $info->estimatedDelivery ?? $shipment->estimatedDelivery;
        $shipment->lastTrackedAt = new DateTime();

        if (in_array($status, [TrackingStatus::IN_TRANSIT, TrackingStatus::OUT_FOR_DELIVERY, TrackingStatus::READY_FOR_PICKUP, TrackingStatus::DELIVERED], true)) {
            $shipment->shippedAt ??= $this->firstScan($info) ?? new DateTime();
        }

        if ($status === TrackingStatus::DELIVERED) {
            $shipment->deliveredAt ??= $info->deliveredAt ?? new DateTime();
        }

        $this->reschedule($shipment, $status, false);
        Plugin::getInstance()->getShipments()->update($shipment);

        if ($changed) {
            $this->trigger(self::EVENT_STATUS_CHANGED, new TrackingUpdateEvent([
                'shipment' => $shipment,
                'tracking' => $info,
                'previousStatus' => $previous,
                'newEvents' => $newEvents,
            ]));

            if ($status === TrackingStatus::DELIVERED) {
                $this->maybeMarkOrderDelivered($shipment);
            }
        }

        return $changed || $newEvents > 0;
    }

    /**
     * Handle a carrier push for a connection. Returns how many shipments it touched.
     */
    public function webhook(\justinholtweb\carrier\models\Connection $connection, \craft\web\Request $request): int
    {
        $carrier = $connection->getCarrier();

        if (!$carrier || !$carrier::capabilities()->has(Feature::WEBHOOKS)) {
            throw new \yii\web\NotFoundHttpException();
        }

        $touched = 0;

        foreach ($carrier->handleWebhook($request) as $info) {
            $shipment = Plugin::getInstance()->getShipments()->getByTrackingNumber($info->trackingNumber);

            // Only shipments this connection bought: a forged push must not be able to move
            // another carrier's parcel to "delivered".
            if ($shipment && $shipment->connectionId === $connection->id && !$info->notFound) {
                $touched += (int)$this->apply($shipment, $info, 'webhook');
            }
        }

        return $touched;
    }

    /**
     * When to poll next. Recently shipped parcels move fast and get checked often; old ones are
     * checked less; final ones and ones past `trackingMaxDays` not at all.
     */
    private function reschedule(Shipment $shipment, ?string $status, bool $save = true): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $status ??= $shipment->trackingStatus;
        $labelled = $shipment->labelledAt?->getTimestamp() ?? time();
        $ageDays = (time() - $labelled) / 86400;

        if (!$settings->trackingEnabled || TrackingStatus::isFinal($status) || $ageDays > $settings->trackingMaxDays) {
            $shipment->nextTrackAt = null;
        } else {
            $hours = max(1, $settings->trackingIntervalHours);

            // A label nobody has scanned yet is usually still on the shelf; no need to ask hourly.
            if ($status === TrackingStatus::PRE_TRANSIT && $ageDays > 2) {
                $hours *= 3;
            } elseif ($ageDays > 10) {
                $hours *= 4;
            }

            $shipment->nextTrackAt = (new DateTime())->modify("+$hours hours");
        }

        $shipment->lastTrackedAt = new DateTime();

        if ($save) {
            Plugin::getInstance()->getShipments()->update($shipment);
        }
    }

    private function firstScan(TrackingInfo $info): ?DateTime
    {
        $first = null;

        foreach ($info->events as $event) {
            if ($event->occurredAt && $event->status !== TrackingStatus::PRE_TRANSIT && ($first === null || $event->occurredAt < $first)) {
                $first = $event->occurredAt;
            }
        }

        return $first;
    }

    /**
     * Move the order on when every live shipment on it has arrived — not when the first of three
     * parcels has.
     */
    private function maybeMarkOrderDelivered(Shipment $shipment): void
    {
        $handle = Plugin::getInstance()->getSettings()->orderStatusOnDelivered;

        if ($handle === '' || !$shipment->orderId || $shipment->isReturn) {
            return;
        }

        foreach (Plugin::getInstance()->getShipments()->forOrder($shipment->orderId, false) as $sibling) {
            if (!$sibling->isReturn && $sibling->status === Shipment::STATUS_LABELLED && $sibling->trackingStatus !== TrackingStatus::DELIVERED) {
                return;
            }
        }

        $order = $shipment->getOrder();

        if ($order) {
            Plugin::getInstance()->getLabels()->applyOrderStatus($order, $handle, Craft::t('carrier', 'Delivered.'));
        }
    }
}
