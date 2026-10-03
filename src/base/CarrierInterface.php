<?php

namespace justinholtweb\carrier\base;

use craft\web\Request;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\PickupRequest;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\Connection;

/**
 * The contract every carrier add-on implements.
 *
 * The point of Carrier is that this interface is small and the engine behind it is not. A carrier
 * translates between one API's wire format and Carrier's canonical documents, and does nothing
 * else: no packing, no pricing rules, no queueing, no retrying, no logging, no label storage, no
 * CP screens, no checkout. All of that is the gateway's job, once, for everybody.
 *
 * Only the methods matching a declared `Feature` are ever called. `base\Carrier` supplies a
 * refusing default for each, and the conformance suite checks that a carrier overrides exactly
 * the ones it declares.
 */
interface CarrierInterface
{
    /** Stable, kebab-case, never changes — it is what connections store. */
    public static function handle(): string;

    /** The carrier's name, spelled the way its own marketing spells it. */
    public static function displayName(): string;

    /** Grouping in the carrier picker: "United States", "Europe", "Freight (US)". */
    public static function region(): string;

    /** One sentence a merchant can use to tell this apart from its siblings. */
    public static function description(): string;

    /** Where the merchant goes to get the credentials this carrier asks for. */
    public static function setupUrl(): ?string;

    public static function capabilities(): Capabilities;

    /** The credential form, in `base\Field` vocabulary. */
    public static function settingsFields(): array;

    /**
     * The services a method can choose from: `['code' => ['name' => …, 'international' => bool,
     * 'points' => bool], …]`. `points` marks a service that delivers to a pickup point rather
     * than a door.
     */
    public static function services(): array;

    /** The public tracking page for a number, or null if the carrier has none. */
    public static function trackingUrl(string $trackingNumber): ?string;

    public function setConnection(Connection $connection): void;

    public function getConnection(): Connection;

    public function rate(ShipmentRequest $request): RateResult;

    public function createShipment(ShipmentRequest $request): ShipmentResult;

    public function cancelShipment(ShipmentRef $shipment): ActionResult;

    /**
     * @param ShipmentRef[] $shipments
     */
    public function printLabels(array $shipments, string $format): LabelResult;

    /**
     * @param string[] $trackingNumbers never more than `Capabilities::getTrackingBatchSize()`
     * @return \justinholtweb\carrier\models\canonical\TrackingInfo[]
     */
    public function track(array $trackingNumbers): array;

    public function pickupPoints(PointQuery $query): PointPage;

    public function requestPickup(PickupRequest $request): ActionResult;

    /**
     * Close the day's consignments. Any manifest document comes back in the result's `data`
     * under `documents` as `Label` objects of kind `manifest`.
     *
     * @param ShipmentRef[] $shipments the open shipments being closed
     */
    public function closeDay(array $shipments): ActionResult;

    public function validateAddress(Address $address): AddressResult;

    /**
     * Verify and translate a tracking push. Throw to refuse a request that fails verification.
     *
     * @return \justinholtweb\carrier\models\canonical\TrackingInfo[]
     */
    public function handleWebhook(Request $request): array;

    /** Prove the credentials work, in a way a merchant can read. */
    public function test(): HealthResult;
}
