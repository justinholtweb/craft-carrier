<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\events\LabelEvent;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\Parcel;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\models\Shipment;
use justinholtweb\carrier\models\ShipmentParcel;
use justinholtweb\carrier\Plugin;
use Throwable;
use yii\db\IntegrityException;

/**
 * Buying, voiding and printing labels.
 *
 * **`purchase()` is the only place a label is bought**, and it is claim-first. The shipment row
 * is written as `purchasing` *before* the carrier is called, under a unique claim key. A queue job
 * that is retried, a double-clicked button, two workers picking up the same bulk action — each
 * finds the claim and stops. A row left in `purchasing` (the process died mid-call) or answered
 * with silence (`uncertain`) is never bought again automatically: only a human can look at the
 * carrier's portal and say whether the label exists. A *refused* purchase (`failed`) is the one
 * state that may be retried, because the carrier said in so many words that it created nothing.
 */
class Labels extends Component
{
    /** @event LabelEvent before the carrier is called; cancel to stop the purchase */
    public const EVENT_BEFORE_PURCHASE = 'beforePurchase';

    /** @event LabelEvent after the carrier answered, whatever it said */
    public const EVENT_AFTER_PURCHASE = 'afterPurchase';

    /** @event LabelEvent after a label is voided */
    public const EVENT_AFTER_VOID = 'afterVoid';

    /**
     * Buy labels for an order.
     *
     * @param array{parcels?:Parcel[],serviceCode?:string,claimKey?:string,isReturn?:bool,labelFormat?:string,pointId?:string} $options
     */
    public function purchase(Order $order, Connection $connection, ?Method $method = null, array $options = []): Shipment
    {
        $claimKey = $options['claimKey'] ?? null;

        if ($claimKey !== null) {
            $existing = Plugin::getInstance()->getShipments()->getByClaimKey($claimKey);

            if ($existing && !$this->reclaim($existing)) {
                // Already bought, being bought, or uncertain. Whatever it is, it is not ours to
                // buy again.
                return $existing;
            }

            if ($existing) {
                return $this->run($existing, $order, $connection, $method, $options);
            }
        }

        $shipment = new Shipment([
            'orderId' => $order->id,
            'connectionId' => $connection->id,
            'methodId' => $method?->id,
            'carrier' => $connection->carrier,
            'status' => Shipment::STATUS_PURCHASING,
            'claimKey' => $claimKey,
            'createdById' => Craft::$app->has('user', true) ? Craft::$app->getUser()->getId() : null,
        ]);

        try {
            Plugin::getInstance()->getShipments()->insertClaim($shipment);
        } catch (IntegrityException) {
            // Another worker claimed this key between our read and our insert.
            return Plugin::getInstance()->getShipments()->getByClaimKey((string)$claimKey) ?? $shipment;
        }

        return $this->run($shipment, $order, $connection, $method, $options);
    }

    /**
     * The claim key bulk actions and automatic labelling use: one label per order per connection.
     * Manual purchases from the shipment screen carry a per-form nonce instead, so a merchant can
     * deliberately ship a second consignment.
     */
    public static function orderClaimKey(Order $order, Connection $connection, bool $isReturn = false): string
    {
        return ($isReturn ? 'return' : 'order') . ':' . $order->id . ':' . $connection->id;
    }

    /**
     * Take a refused claim back for another attempt. Atomic, so two retries cannot both win.
     */
    private function reclaim(Shipment $shipment): bool
    {
        if ($shipment->status !== Shipment::STATUS_FAILED) {
            return false;
        }

        $taken = Db::update(Table::SHIPMENTS, [
            'status' => Shipment::STATUS_PURCHASING,
            'error' => null,
        ], ['id' => $shipment->id, 'status' => Shipment::STATUS_FAILED]);

        if ($taken !== 1) {
            return false;
        }

        $shipment->status = Shipment::STATUS_PURCHASING;
        $shipment->error = null;

        return true;
    }

    private function run(Shipment $shipment, Order $order, Connection $connection, ?Method $method, array $options): Shipment
    {
        $shipments = Plugin::getInstance()->getShipments();
        $carrier = $connection->getCarrier();

        if (!$carrier instanceof Carrier) {
            return $this->refuse($shipment, Craft::t('carrier', 'The add-on for this carrier is not installed.'));
        }

        $capabilities = $carrier::capabilities();

        if (!$capabilities->has(Feature::LABELS)) {
            return $this->refuse($shipment, Craft::t('carrier', '{carrier} cannot create labels.', ['carrier' => $carrier::displayName()]));
        }

        try {
            $request = Plugin::getInstance()->getOrders()->buildRequest($order, $method, $connection, $options['parcels'] ?? null, 'label');
        } catch (Throwable $e) {
            return $this->refuse($shipment, $e->getMessage());
        }

        if (isset($options['serviceCode']) && $options['serviceCode'] !== '') {
            $request->serviceCode = (string)$options['serviceCode'];
        }

        if (!empty($options['labelFormat'])) {
            $request->labelFormat = (string)$options['labelFormat'];
        }

        if (isset($options['pointId']) && (string)$options['pointId'] !== $request->pointId) {
            $request->pointId = (string)$options['pointId'];
            $request->point = $request->pointId !== '' ? Plugin::getInstance()->getPoints()->find($connection, $request->pointId) : null;
        }

        $request->isReturn = (bool)($options['isReturn'] ?? false);

        if (isset($options['accessorials']) && is_array($options['accessorials'])) {
            $request->accessorials = array_values(array_intersect($options['accessorials'], \justinholtweb\carrier\base\Accessorial::all()));
        }

        $problem = $this->preflight($request, $carrier, $method);

        if ($problem !== null) {
            return $this->refuse($shipment, $problem);
        }

        $services = $carrier::services();
        $shipment->serviceCode = $request->serviceCode;
        $shipment->serviceName = $services[$request->serviceCode]['name'] ?? ($method?->name ?? null);
        $shipment->isReturn = $request->isReturn;
        $shipment->codAmount = $request->hasCod() ? $request->codAmount : null;
        $shipment->codCurrency = $request->hasCod() ? $request->codCurrency : null;
        $shipment->pointId = $request->pointId ?: null;
        $shipment->point = $request->pointId !== ''
            ? ($request->point?->toArray() ?? $this->pointSnapshot($connection, $request->pointId, $order))
            : [];
        $shipment->request = $request->toArray();
        $shipments->update($shipment);

        $event = new LabelEvent(['shipment' => $shipment, 'request' => $request]);
        $this->trigger(self::EVENT_BEFORE_PURCHASE, $event);

        if (!$event->isValid) {
            return $this->refuse($shipment, Craft::t('carrier', 'A plugin stopped this label from being bought.'));
        }

        // From here on the carrier may act. Retries are limited to answers that prove it did not.
        $transport = $carrier->transport();
        $transport->setSafeRetriesOnly(true)->setShipmentId($shipment->id);
        $requestsBefore = $transport->requestCount();

        try {
            $result = $carrier->createShipment($request);
        } catch (Throwable $e) {
            Craft::error('Carrier: ' . $carrier::displayName() . ' threw while creating a shipment: ' . $e->getMessage(), 'carrier');

            // An exception before any request left is a bug, not a maybe-label.
            $result = $transport->requestCount() === $requestsBefore
                ? ShipmentResult::rejected($e->getMessage())
                : ShipmentResult::uncertain($e->getMessage());
        } finally {
            $transport->setSafeRetriesOnly(false);
        }

        $this->record($shipment, $request, $result, $carrier, $order);

        $after = new LabelEvent(['shipment' => $shipment, 'request' => $request, 'result' => $result]);
        $this->trigger(self::EVENT_AFTER_PURCHASE, $after);

        return $shipment;
    }

    /**
     * What Carrier can tell is wrong before spending a request. Every message is a sentence the
     * merchant can act on.
     */
    private function preflight($request, Carrier $carrier, ?Method $method): ?string
    {
        $capabilities = $carrier::capabilities();
        $name = $carrier::displayName();

        if ($request->parcels === []) {
            return Craft::t('carrier', 'There is nothing to ship: no line item in this order is shippable.');
        }

        if (!$request->shipTo || $request->shipTo->countryCode === '') {
            return Craft::t('carrier', 'The order has no shipping address.');
        }

        if (!$request->shipFrom || $request->shipFrom->countryCode === '') {
            return Craft::t('carrier', 'There is no ship-from address. Set the store’s location in Commerce, or a ship-from address on the connection.');
        }

        if (!$capabilities->shipsFrom($request->shipFrom->countryCode)) {
            return Craft::t('carrier', '{carrier} does not ship from {country}.', ['carrier' => $name, 'country' => $request->shipFrom->countryCode]);
        }

        if ($request->isInternational() && !$capabilities->has(Feature::INTERNATIONAL)) {
            return Craft::t('carrier', '{carrier} only ships domestically.', ['carrier' => $name]);
        }

        if ($request->isReturn && !$capabilities->has(Feature::RETURNS)) {
            return Craft::t('carrier', '{carrier} cannot create return labels.', ['carrier' => $name]);
        }

        // Freight ships several handling units under one PRO, which is not "multi-piece" in the
        // parcel sense but is still one shipment.
        if (count($request->parcels) > 1 && !$capabilities->has(Feature::MULTI_PIECE) && !$capabilities->has(Feature::FREIGHT)) {
            return Craft::t('carrier', '{carrier} ships one parcel per label. Split this into separate shipments.', ['carrier' => $name]);
        }

        $max = $capabilities->getMaxParcelWeightG();

        foreach ($request->parcels as $i => $parcel) {
            if ($max > 0 && $parcel->weightG > $max) {
                return Craft::t('carrier', 'Parcel {n} weighs {w} kg; {carrier} accepts at most {max} kg.', [
                    'n' => $i + 1,
                    'w' => round($parcel->weightG / 1000, 2),
                    'carrier' => $name,
                    'max' => round($max / 1000, 2),
                ]);
            }
        }

        if ($method?->requiresPoint && $request->pointId === '') {
            return Craft::t('carrier', 'This method delivers to a pickup point, and none was chosen for this order.');
        }

        if ($request->serviceCode === '' && $carrier::services() !== []) {
            return Craft::t('carrier', 'Choose a service.');
        }

        $unsupported = array_diff($request->accessorials, $capabilities->getAccessorials());

        if ($unsupported !== []) {
            return Craft::t('carrier', '{carrier} cannot add: {list}.', ['carrier' => $name, 'list' => implode(', ', $unsupported)]);
        }

        return null;
    }

    private function record(Shipment $shipment, $request, ShipmentResult $result, Carrier $carrier, Order $order): void
    {
        $shipments = Plugin::getInstance()->getShipments();

        if (!$result->success) {
            $shipment->status = $result->uncertain ? Shipment::STATUS_UNCERTAIN : Shipment::STATUS_FAILED;

            // A partial purchase (USPS bought parcel 1 of 3; BOX NOW created the order but not its
            // labels) leaves ids a human needs to void or recover it. Keep them.
            if ($result->data !== [] || $result->trackingNumbers !== []) {
                $shipment->data = array_merge($shipment->data, $result->data, $result->trackingNumbers ? ['partialTrackingNumbers' => $result->trackingNumbers] : []);
                $shipment->carrierShipmentId = $result->shipmentId ?: $shipment->carrierShipmentId;
            }
            $shipment->error = $result->message ?: Craft::t('carrier', 'The carrier gave no reason.');
            $shipments->update($shipment);
            Plugin::getInstance()->getLog()->error($shipment->getConnection(), "Label for order {$order->reference}: {$shipment->error}");

            return;
        }

        $now = new DateTime();
        $parcels = [];

        foreach ($request->parcels as $i => $parcel) {
            $row = ShipmentParcel::fromParcel($parcel, $i);
            $row->trackingNumber = $result->trackingNumbers[$i] ?? $result->trackingNumbers[0] ?? null;
            $parcels[] = $row;
        }

        $shipment->status = Shipment::STATUS_LABELLED;
        $shipment->carrierShipmentId = $result->shipmentId ?: null;
        $shipment->trackingNumber = $result->trackingNumbers[0] ?? null;
        $shipment->trackingStatus = TrackingStatus::PRE_TRANSIT;
        $shipment->labelFormat = $result->labels[0]->format ?? $request->labelFormat;
        $shipment->charge = $result->charge;
        $shipment->currency = $result->currency ?: null;
        $shipment->data = $result->data;
        $shipment->error = $result->warnings !== [] ? implode("\n", $result->warnings) : null;
        $shipment->labelledAt = $now;
        $shipment->nextTrackAt = (clone $now)->modify('+' . max(1, Plugin::getInstance()->getSettings()->trackingIntervalHours) . ' hours');

        // The bytes go in first: a shipment marked labelled with no label is worse than one still
        // marked purchasing, which at least sends someone to look.
        $shipments->saveParcels($shipment, $parcels);
        $byNumber = [];

        foreach ($shipment->getParcels() as $parcel) {
            if ($parcel->trackingNumber) {
                $byNumber[$parcel->trackingNumber] = $parcel->id;
            }
        }

        foreach ($result->labels as $label) {
            if (!LabelFormat::bytesMatch($label->format, $label->data)) {
                $shipment->error = trim(($shipment->error ?? '') . "\n" . Craft::t('carrier', 'The carrier returned a {format} label that does not look like one. Check it before printing.', ['format' => $label->format]));
            }

            $parcelId = $label->parcelIndex !== null
                ? ($shipment->getParcels()[$label->parcelIndex]->id ?? null)
                : ($byNumber[$label->trackingNumber] ?? null);

            $shipments->storeLabel($shipment, $label, $parcelId);
        }

        $shipments->update($shipment);
        Plugin::getInstance()->getSelections()->forgetCache();

        $this->applyOrderStatus($order, Plugin::getInstance()->getSettings()->orderStatusOnLabel, Craft::t('carrier', 'Label created with {carrier}.', ['carrier' => $carrier::displayName()]));
    }

    private function refuse(Shipment $shipment, string $message): Shipment
    {
        $shipment->status = Shipment::STATUS_FAILED;
        $shipment->error = $message;

        if ($shipment->id) {
            Plugin::getInstance()->getShipments()->update($shipment);
        }

        return $shipment;
    }

    private function pointSnapshot(Connection $connection, string $pointId, Order $order): array
    {
        $point = Plugin::getInstance()->getPoints()->find($connection, $pointId);

        if ($point) {
            return $point->toArray();
        }

        $selection = $order->id ? Plugin::getInstance()->getSelections()->getForOrder($order->id) : null;

        return $selection && $selection->pointId === $pointId ? $selection->point : ['id' => $pointId];
    }

    /**
     * Void a label with the carrier, then locally.
     */
    public function void(Shipment $shipment): bool
    {
        if ($shipment->status !== Shipment::STATUS_LABELLED) {
            $shipment->addError('status', Craft::t('carrier', 'Only a labelled shipment can be voided.'));

            return false;
        }

        $connection = $shipment->getConnection();
        $carrier = $connection?->getCarrier();

        if (!$carrier || !$carrier::capabilities()->has(Feature::CANCEL)) {
            $shipment->addError('status', Craft::t('carrier', 'This carrier cannot void labels through its API. Void it in the carrier’s portal, then mark it voided here.'));

            return false;
        }

        if ($carrier instanceof Carrier) {
            $carrier->transport()->setShipmentId($shipment->id);
        }

        $result = $carrier->cancelShipment($shipment->getRef());

        if (!$result->success) {
            $shipment->addError('status', $result->message ?: Craft::t('carrier', 'The carrier refused to void this label.'));

            return false;
        }

        $this->markVoided($shipment);

        return true;
    }

    /**
     * Record a void that happened somewhere else — the carrier's portal, a phone call.
     */
    public function markVoided(Shipment $shipment): void
    {
        $shipment->status = Shipment::STATUS_VOIDED;
        $shipment->trackingStatus = TrackingStatus::CANCELLED;
        $shipment->voidedAt = new DateTime();
        $shipment->nextTrackAt = null;
        Plugin::getInstance()->getShipments()->update($shipment);

        $this->trigger(self::EVENT_AFTER_VOID, new LabelEvent(['shipment' => $shipment]));
    }

    /**
     * Resolve an uncertain or interrupted purchase after a human has checked the carrier.
     *
     * `notCreated` turns it into a refused claim, which may be retried. `created` records the
     * label the merchant found, by tracking number, and fetches its bytes if the carrier reprints.
     */
    public function resolve(Shipment $shipment, string $outcome, string $trackingNumber = ''): bool
    {
        if (!in_array($shipment->status, [Shipment::STATUS_UNCERTAIN, Shipment::STATUS_PURCHASING], true)) {
            return false;
        }

        if ($outcome === 'notCreated') {
            $shipment->status = Shipment::STATUS_FAILED;
            $shipment->error = Craft::t('carrier', 'Checked with the carrier: no label was created.');
            Plugin::getInstance()->getShipments()->update($shipment);

            return true;
        }

        if ($outcome !== 'created' || trim($trackingNumber) === '') {
            return false;
        }

        $shipment->status = Shipment::STATUS_LABELLED;
        $shipment->trackingNumber = trim($trackingNumber);
        $shipment->carrierShipmentId ??= $shipment->trackingNumber;
        $shipment->trackingStatus = TrackingStatus::PRE_TRANSIT;
        $shipment->labelledAt = new DateTime();
        $shipment->nextTrackAt = new DateTime();
        $shipment->error = Craft::t('carrier', 'Recorded by hand after checking with the carrier.');
        Plugin::getInstance()->getShipments()->update($shipment);

        $request = $shipment->getRequest();
        $parcels = [];

        foreach ($request->parcels as $i => $parcel) {
            $row = ShipmentParcel::fromParcel($parcel, $i);
            $row->trackingNumber = $i === 0 ? $shipment->trackingNumber : null;
            $parcels[] = $row;
        }

        Plugin::getInstance()->getShipments()->saveParcels($shipment, $parcels);
        $this->reprint($shipment);

        return true;
    }

    /**
     * Record a shipment made outside Carrier — in the carrier's own portal, at a post office
     * counter, by a carrier whose API cannot create labels (Pošta Slovenije) — so it is tracked
     * and written back to the order like any other. Recording the same number twice is a no-op.
     */
    public function recordExternal(Order $order, Connection $connection, string $trackingNumber, string $serviceCode = ''): Shipment
    {
        $trackingNumber = trim($trackingNumber);
        $claimKey = 'external:' . $order->id . ':' . $connection->id . ':' . mb_substr($trackingNumber, 0, 64);
        $existing = Plugin::getInstance()->getShipments()->getByClaimKey($claimKey);

        if ($existing) {
            return $existing;
        }

        $class = $connection->getCarrierClass();
        $now = new DateTime();
        $shipment = new Shipment([
            'orderId' => $order->id,
            'connectionId' => $connection->id,
            'carrier' => $connection->carrier,
            'serviceCode' => $serviceCode,
            'serviceName' => $class ? ($class::services()[$serviceCode]['name'] ?? null) : null,
            'status' => Shipment::STATUS_LABELLED,
            'claimKey' => $claimKey,
            'trackingNumber' => $trackingNumber,
            'carrierShipmentId' => $trackingNumber,
            'trackingStatus' => TrackingStatus::PRE_TRANSIT,
            'labelledAt' => $now,
            'nextTrackAt' => $now,
            'error' => Craft::t('carrier', 'Recorded by hand; the label was made outside Carrier.'),
            'createdById' => Craft::$app->has('user', true) ? Craft::$app->getUser()->getId() : null,
        ]);

        Plugin::getInstance()->getShipments()->insertClaim($shipment);
        Plugin::getInstance()->getShipments()->saveParcels($shipment, [new ShipmentParcel(['sequence' => 0, 'trackingNumber' => $trackingNumber])]);

        return $shipment;
    }

    /**
     * Fetch a shipment's labels again from the carrier and store them, for a carrier that
     * reprints. Returns false when there is nothing to fetch with.
     */
    public function reprint(Shipment $shipment, ?string $format = null): bool
    {
        $carrier = $shipment->getConnection()?->getCarrier();

        if (!$carrier || !$carrier::capabilities()->has(Feature::REPRINT)) {
            return false;
        }

        $result = $carrier->printLabels([$shipment->getRef()], $format ?? (string)$shipment->labelFormat);

        if (!$result->successful() || $result->labels === []) {
            return false;
        }

        Plugin::getInstance()->getShipments()->deleteLabels($shipment->id);

        foreach ($result->labels as $label) {
            Plugin::getInstance()->getShipments()->storeLabel($shipment, $label);
        }

        return true;
    }

    /**
     * One printable download for any number of shipments.
     *
     * - One document: as it is.
     * - Several ZPL labels: concatenated — a Zebra prints a stream of `^XA…^XZ` blocks in order.
     * - Several PNG labels: an HTML print sheet (the controller renders it), so the browser can
     *   lay them out four to an A4 page or one per 4×6.
     * - Several PDFs from one connection whose carrier reprints in batches: the carrier is asked
     *   for one merged document, which is how GLS and DPD produce A4 sheets of four.
     * - Anything else: a ZIP. Merging arbitrary PDFs needs a PDF library Carrier does not ship.
     *
     * @param int[] $shipmentIds
     * @return array{type:string,filename:string,mime:string,data:string,labels?:Label[]}|null
     */
    public function bundle(array $shipmentIds, bool $preferCarrierBatch = true): ?array
    {
        $shipments = array_values(array_filter(array_map(
            static fn(int $id) => Plugin::getInstance()->getShipments()->getById($id),
            array_unique(array_map('intval', $shipmentIds)),
        ), static fn(?Shipment $s) => $s !== null && $s->status === Shipment::STATUS_LABELLED));

        if ($shipments === []) {
            return null;
        }

        $ids = array_map(static fn(Shipment $s) => $s->id, $shipments);
        $stamp = (new DateTime())->format('Ymd-His');

        if ($preferCarrierBatch && count($shipments) > 1) {
            $batch = $this->carrierBatch($shipments);

            if ($batch !== null) {
                Plugin::getInstance()->getShipments()->markPrinted($ids);

                return ['type' => 'file', 'filename' => "labels-$stamp." . LabelFormat::extension($batch->format), 'mime' => $batch->mimeType(), 'data' => $batch->data];
            }
        }

        $labels = Plugin::getInstance()->getShipments()->labelDocuments($ids);

        if ($labels === []) {
            return null;
        }

        Plugin::getInstance()->getShipments()->markPrinted($ids);
        $formats = array_unique(array_map(static fn(Label $l) => $l->format, $labels));

        if (count($labels) === 1) {
            $label = $labels[0];
            $name = $label->trackingNumber !== '' ? $label->trackingNumber : 'label';

            return ['type' => 'file', 'filename' => "$name." . LabelFormat::extension($label->format), 'mime' => $label->mimeType(), 'data' => $label->data];
        }

        if ($formats === [LabelFormat::ZPL]) {
            return ['type' => 'file', 'filename' => "labels-$stamp.zpl", 'mime' => 'application/x-zpl', 'data' => implode("\n", array_map(static fn(Label $l) => trim($l->data), $labels)) . "\n"];
        }

        if ($formats === [LabelFormat::PNG]) {
            return ['type' => 'sheet', 'filename' => '', 'mime' => 'text/html', 'data' => '', 'labels' => $labels];
        }

        return ['type' => 'file', 'filename' => "labels-$stamp.zip", 'mime' => 'application/zip', 'data' => $this->zip($labels)];
    }

    /**
     * @param Shipment[] $shipments
     */
    private function carrierBatch(array $shipments): ?Label
    {
        $connectionIds = array_unique(array_map(static fn(Shipment $s) => $s->connectionId, $shipments));

        if (count($connectionIds) !== 1) {
            return null;
        }

        $carrier = $shipments[0]->getConnection()?->getCarrier();
        $format = (string)$shipments[0]->labelFormat;

        if (!$carrier || !$carrier::capabilities()->has(Feature::REPRINT) || !LabelFormat::isPdf($format)) {
            return null;
        }

        try {
            $result = $carrier->printLabels(array_map(static fn(Shipment $s) => $s->getRef(), $shipments), $format);
        } catch (Throwable $e) {
            Craft::warning('Carrier batch print failed, falling back to stored labels: ' . $e->getMessage(), 'carrier');

            return null;
        }

        // Only a single merged document is worth preferring over the stored labels.
        if (!$result->successful() || count($result->labels) !== 1 || !LabelFormat::bytesMatch($result->labels[0]->format, $result->labels[0]->data)) {
            return null;
        }

        return $result->labels[0];
    }

    /**
     * @param Label[] $labels
     */
    private function zip(array $labels): string
    {
        $path = tempnam(sys_get_temp_dir(), 'carrier-labels');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $used = [];

        foreach ($labels as $i => $label) {
            $base = $label->trackingNumber !== '' ? preg_replace('/[^\w\-]/', '', $label->trackingNumber) : 'label-' . ($i + 1);
            $name = $base . '.' . LabelFormat::extension($label->format);

            while (isset($used[$name])) {
                $name = $base . '-' . StringHelper::randomString(4) . '.' . LabelFormat::extension($label->format);
            }

            $used[$name] = true;
            $zip->addFromString($name, $label->data);
        }

        $zip->close();
        $data = (string)file_get_contents($path);
        @unlink($path);

        return $data;
    }

    /**
     * Move an order to a configured status, writing a history message. Never throws: a status
     * email that fails to send must not unwind a label that was bought.
     */
    public function applyOrderStatus(Order $order, string $handle, string $message): void
    {
        if ($handle === '' || !$order->isCompleted) {
            return;
        }

        try {
            $status = \craft\commerce\Plugin::getInstance()->getOrderStatuses()->getOrderStatusByHandle($handle, $order->storeId);

            if (!$status || $order->orderStatusId === $status->id) {
                return;
            }

            $order->orderStatusId = $status->id;
            $order->message = $message;
            Craft::$app->getElements()->saveElement($order, false);
        } catch (Throwable $e) {
            Craft::error('Carrier could not change the order status: ' . $e->getMessage(), 'carrier');
        }
    }
}
