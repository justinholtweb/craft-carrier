<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\carrier\base\ActionResult;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\models\canonical\PickupRequest;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\models\Shipment;
use justinholtweb\carrier\Plugin;

/**
 * Courier collections and end-of-day closes.
 */
class Pickups extends Component
{
    /**
     * Ask a carrier to collect today's (or a given day's) labelled parcels for a connection.
     *
     * @param Shipment[] $shipments
     */
    public function request(Connection $connection, DateTime $date, array $shipments, string $readyTime = '09:00', string $closeTime = '17:00', string $instructions = ''): ActionResult
    {
        $carrier = $connection->getCarrier();

        if (!$carrier || !$carrier::capabilities()->has(Feature::PICKUPS)) {
            return ActionResult::failed(Craft::t('carrier', 'This carrier does not take collection requests through its API.'));
        }

        $address = $connection->getShipFromAddress();

        if (!$address && $shipments !== []) {
            $address = $shipments[0]->getRequest()->shipFrom;
        }

        if (!$address) {
            return ActionResult::failed(Craft::t('carrier', 'Set a ship-from address on the connection first.'));
        }

        $weight = 0;
        $parcels = 0;
        $numbers = [];

        foreach ($shipments as $shipment) {
            foreach ($shipment->getParcels() as $parcel) {
                $weight += $parcel->weightG;
                $parcels++;
            }

            array_push($numbers, ...$shipment->trackingNumbers());
        }

        $result = $carrier->requestPickup(new PickupRequest([
            'address' => $address,
            'date' => $date,
            'readyTime' => $readyTime,
            'closeTime' => $closeTime,
            'parcelCount' => max(1, $parcels),
            'totalWeightG' => $weight,
            'trackingNumbers' => $numbers,
            'instructions' => $instructions,
        ]));

        $this->record($connection, 'pickup', $date, $readyTime, $closeTime, $parcels, $shipments, $result);

        return $result;
    }

    /**
     * Close the day for a connection: every labelled shipment not yet closed.
     */
    public function closeDay(Connection $connection): ActionResult
    {
        $carrier = $connection->getCarrier();

        if (!$carrier || !$carrier::capabilities()->has(Feature::CLOSE_DAY)) {
            return ActionResult::failed(Craft::t('carrier', 'This carrier has no end-of-day close.'));
        }

        $shipments = array_values(array_filter(
            Plugin::getInstance()->getShipments()->hydrate((new Query())->from(Table::SHIPMENTS)
                ->where(['connectionId' => $connection->id, 'status' => Shipment::STATUS_LABELLED])
                ->andWhere(['>=', 'labelledAt', Db::prepareDateForDb((new DateTime())->modify('-7 days'))])
                ->all()),
            static fn(Shipment $s) => empty($s->data['closedAt']),
        ));

        if ($shipments === []) {
            return ActionResult::failed(Craft::t('carrier', 'There is nothing open to close.'));
        }

        $result = $carrier->closeDay(array_map(static fn(Shipment $s) => $s->getRef(), $shipments));

        if ($result->success) {
            foreach ($shipments as $shipment) {
                $shipment->data['closedAt'] = (new DateTime())->format(DATE_ATOM);
                Plugin::getInstance()->getShipments()->update($shipment);
            }
        }

        $this->record($connection, 'close', new DateTime(), null, null, count($shipments), $shipments, $result);

        return $result;
    }

    public function recent(int $limit = 50): array
    {
        return (new Query())->from(Table::PICKUPS)->orderBy(['dateCreated' => SORT_DESC])->limit($limit)->all();
    }

    private function record(Connection $connection, string $kind, DateTime $date, ?string $ready, ?string $close, int $parcels, array $shipments, ActionResult $result): void
    {
        Db::insert(Table::PICKUPS, [
            'connectionId' => $connection->id,
            'carrier' => $connection->carrier,
            'pickupDate' => $date->format('Y-m-d'),
            'readyTime' => $ready,
            'closeTime' => $close,
            'parcelCount' => $parcels,
            'reference' => $result->reference ?: null,
            'status' => ($kind === 'close' ? 'closed' : 'requested') . ($result->success ? '' : '-failed'),
            'shipmentIds' => json_encode(array_map(static fn(Shipment $s) => $s->id, $shipments)),
            'message' => $result->message,
            'createdById' => Craft::$app->has('user', true) ? Craft::$app->getUser()->getId() : null,
        ]);
    }
}
