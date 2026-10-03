<?php

namespace justinholtweb\carrier\services;

use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\Shipment;
use justinholtweb\carrier\models\ShipmentParcel;

/**
 * Shipment persistence: rows, parcels, label bytes, events. No carrier is ever called from here —
 * that is `Labels` and `Tracking`.
 */
class Shipments extends Component
{
    public function query(): Query
    {
        return (new Query())->from(Table::SHIPMENTS)->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function getById(int $id): ?Shipment
    {
        $row = (new Query())->from(Table::SHIPMENTS)->where(['id' => $id])->one();

        return $row ? new Shipment($row) : null;
    }

    public function getByClaimKey(string $key): ?Shipment
    {
        $row = (new Query())->from(Table::SHIPMENTS)->where(['claimKey' => $key])->one();

        return $row ? new Shipment($row) : null;
    }

    public function getByTrackingNumber(string $number): ?Shipment
    {
        $id = (new Query())->select(['id'])->from(Table::SHIPMENTS)->where(['trackingNumber' => $number])->scalar()
            ?: (new Query())->select(['shipmentId'])->from(Table::PARCELS)->where(['trackingNumber' => $number])->scalar();

        return $id ? $this->getById((int)$id) : null;
    }

    /**
     * @return Shipment[]
     */
    public function forOrder(int $orderId, bool $includeVoided = true): array
    {
        $query = $this->query()->where(['orderId' => $orderId]);

        if (!$includeVoided) {
            $query->andWhere(['not', ['status' => Shipment::STATUS_VOIDED]]);
        }

        return $this->hydrate($query->all());
    }

    /**
     * Shipments for many orders at once, for the order index column. Keyed by order id.
     *
     * @return array<int,Shipment[]>
     */
    public function forOrders(array $orderIds): array
    {
        $out = [];

        if ($orderIds === []) {
            return $out;
        }

        foreach ($this->hydrate($this->query()->where(['orderId' => $orderIds])->andWhere(['not', ['status' => Shipment::STATUS_VOIDED]])->all()) as $shipment) {
            $out[$shipment->orderId][] = $shipment;
        }

        return $out;
    }

    /**
     * @return Shipment[] shipments that need a human — interrupted, uncertain or refused
     */
    public function problems(int $limit = 200): array
    {
        $stale = Db::prepareDateForDb((new DateTime('now', new \DateTimeZone('UTC')))->modify('-10 minutes'));

        return $this->hydrate($this->query()
            ->where(['status' => [Shipment::STATUS_UNCERTAIN, Shipment::STATUS_FAILED]])
            ->orWhere(['and', ['status' => Shipment::STATUS_PURCHASING], ['<', 'dateCreated', $stale]])
            ->limit($limit)
            ->all());
    }

    public function problemCount(): int
    {
        $stale = Db::prepareDateForDb((new DateTime('now', new \DateTimeZone('UTC')))->modify('-10 minutes'));

        return (int)(new Query())->from(Table::SHIPMENTS)
            ->where(['status' => [Shipment::STATUS_UNCERTAIN, Shipment::STATUS_FAILED]])
            ->orWhere(['and', ['status' => Shipment::STATUS_PURCHASING], ['<', 'dateCreated', $stale]])
            ->count();
    }

    /**
     * Insert the claim row. Throws on a duplicate claim key — which is the point: the unique
     * index is what stops two workers buying one label, not a check either of them remembered.
     *
     * @throws \yii\db\IntegrityException when the claim key is already taken
     */
    public function insertClaim(Shipment $shipment): void
    {
        $now = Db::prepareDateForDb(new DateTime());
        $uid = StringHelper::UUID();

        Db::insert(Table::SHIPMENTS, array_merge($this->columns($shipment), [
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => $uid,
        ]));

        $shipment->id = (int)\Craft::$app->getDb()->getLastInsertID(Table::SHIPMENTS);
        $shipment->uid = $uid;
        $shipment->dateCreated = new DateTime();
    }

    public function update(Shipment $shipment): void
    {
        Db::update(Table::SHIPMENTS, $this->columns($shipment), ['id' => $shipment->id]);
    }

    /**
     * @param ShipmentParcel[] $parcels
     */
    public function saveParcels(Shipment $shipment, array $parcels): void
    {
        Db::delete(Table::PARCELS, ['shipmentId' => $shipment->id]);

        foreach ($parcels as $parcel) {
            $parcel->shipmentId = $shipment->id;
            Db::insert(Table::PARCELS, [
                'shipmentId' => $shipment->id,
                'sequence' => $parcel->sequence,
                'trackingNumber' => $parcel->trackingNumber,
                'weightG' => $parcel->weightG,
                'lengthMm' => $parcel->lengthMm,
                'widthMm' => $parcel->widthMm,
                'heightMm' => $parcel->heightMm,
                'box' => $parcel->box,
                'items' => json_encode($parcel->items),
            ]);
        }

        $shipment->setParcels($this->parcelsFor($shipment->id));
    }

    /**
     * @return ShipmentParcel[]
     */
    public function parcelsFor(int $shipmentId): array
    {
        return array_map(
            static fn(array $row) => new ShipmentParcel($row),
            (new Query())->from(Table::PARCELS)->where(['shipmentId' => $shipmentId])->orderBy(['sequence' => SORT_ASC])->all(),
        );
    }

    public function storeLabel(Shipment $shipment, Label $label, ?int $parcelId = null): int
    {
        Db::insert(Table::LABELS, [
            'shipmentId' => $shipment->id,
            'parcelId' => $parcelId,
            'kind' => $label->kind,
            'format' => $label->format,
            'trackingNumber' => $label->trackingNumber ?: null,
            'size' => strlen($label->data),
            'data' => base64_encode($label->data),
        ]);

        return (int)\Craft::$app->getDb()->getLastInsertID(Table::LABELS);
    }

    /**
     * Label rows without their bytes, for listing.
     */
    public function labelsFor(int $shipmentId): array
    {
        return (new Query())
            ->select(['id', 'shipmentId', 'parcelId', 'kind', 'format', 'trackingNumber', 'size', 'printedAt', 'dateCreated'])
            ->from(Table::LABELS)
            ->where(['shipmentId' => $shipmentId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    /**
     * Every label document of every shipment given, bytes decoded, in shipment then label order.
     *
     * @param int[] $shipmentIds
     * @return Label[]
     */
    public function labelDocuments(array $shipmentIds, ?string $kind = 'label'): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        $query = (new Query())->from(Table::LABELS)->where(['shipmentId' => $shipmentIds]);

        if ($kind !== null) {
            $query->andWhere(['kind' => $kind]);
        }

        $rows = $query->orderBy(['shipmentId' => SORT_ASC, 'id' => SORT_ASC])->all();
        $order = array_flip(array_values($shipmentIds));
        usort($rows, static fn($a, $b) => ($order[$a['shipmentId']] ?? 0) <=> ($order[$b['shipmentId']] ?? 0) ?: $a['id'] <=> $b['id']);

        return array_map(static fn(array $row) => new Label([
            'trackingNumber' => (string)$row['trackingNumber'],
            'format' => $row['format'],
            'kind' => $row['kind'],
            'data' => (string)base64_decode((string)$row['data']),
        ]), $rows);
    }

    public function getLabelRow(int $labelId): ?array
    {
        return (new Query())->from(Table::LABELS)->where(['id' => $labelId])->one() ?: null;
    }

    public function markPrinted(array $shipmentIds): void
    {
        if ($shipmentIds !== []) {
            Db::update(Table::LABELS, ['printedAt' => Db::prepareDateForDb(new DateTime())], ['shipmentId' => $shipmentIds, 'printedAt' => null]);
        }
    }

    public function deleteLabels(int $shipmentId): void
    {
        Db::delete(Table::LABELS, ['shipmentId' => $shipmentId]);
    }

    /**
     * @return array<int,array<string,mixed>> tracking events, newest first
     */
    public function eventsFor(int $shipmentId): array
    {
        return (new Query())->from(Table::EVENTS)->where(['shipmentId' => $shipmentId])
            ->orderBy(['occurredAt' => SORT_DESC, 'id' => SORT_DESC])->all();
    }

    /**
     * Drop label bytes past retention. The shipment row and its tracking stay: what was sent is
     * history; the PDF of it is a liability.
     */
    public function pruneLabels(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-$days days"));

        return (int)\Craft::$app->getDb()->createCommand()->delete(Table::LABELS, ['<', 'dateCreated', $cutoff])->execute();
    }

    /**
     * @return Shipment[]
     */
    public function hydrate(array $rows): array
    {
        return array_map(static fn(array $row) => new Shipment($row), $rows);
    }

    private function columns(Shipment $s): array
    {
        $date = static fn(?DateTime $d) => $d ? Db::prepareDateForDb($d) : null;

        return [
            'orderId' => $s->orderId,
            'connectionId' => $s->connectionId,
            'methodId' => $s->methodId,
            'carrier' => $s->carrier,
            'serviceCode' => $s->serviceCode,
            'serviceName' => $s->serviceName,
            'status' => $s->status,
            'claimKey' => $s->claimKey,
            'carrierShipmentId' => $s->carrierShipmentId,
            'trackingNumber' => $s->trackingNumber,
            'trackingStatus' => $s->trackingStatus,
            'trackingStatusText' => $s->trackingStatusText !== null ? mb_substr($s->trackingStatusText, 0, 255) : null,
            'labelFormat' => $s->labelFormat,
            'charge' => $s->charge,
            'currency' => $s->currency,
            'codAmount' => $s->codAmount,
            'codCurrency' => $s->codCurrency,
            'pointId' => $s->pointId,
            'point' => $s->point ? json_encode($s->point) : null,
            'isReturn' => $s->isReturn,
            'request' => json_encode($s->request),
            'data' => json_encode($s->data),
            'error' => $s->error,
            'estimatedDelivery' => $date($s->estimatedDelivery),
            'labelledAt' => $date($s->labelledAt),
            'shippedAt' => $date($s->shippedAt),
            'deliveredAt' => $date($s->deliveredAt),
            'voidedAt' => $date($s->voidedAt),
            'lastTrackedAt' => $date($s->lastTrackedAt),
            'nextTrackAt' => $date($s->nextTrackAt),
            'createdById' => $s->createdById,
        ];
    }
}
