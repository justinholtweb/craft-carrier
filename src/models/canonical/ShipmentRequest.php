<?php

namespace justinholtweb\carrier\models\canonical;

use DateTime;

/**
 * Everything a carrier needs to rate or ship one consignment.
 *
 * `services\Orders::buildRequest()` is the only place a Commerce order becomes one of these. The
 * same request is rated at checkout, previewed in the control panel and sent to buy the label,
 * so what the merchant sees is what the carrier gets.
 */
class ShipmentRequest extends Document
{
    protected const LISTS = [
        'parcels' => Parcel::class,
        'customsItems' => CustomsItem::class,
    ];

    public ?Address $shipFrom = null;

    public ?Address $shipTo = null;

    /** @var Parcel[] */
    public array $parcels = [];

    /** The carrier's own service code. Empty when rating every service ("rate shopping"). */
    public string $serviceCode = '';

    /**
     * A return label. The request still describes the *outbound* journey — `shipFrom` is the
     * store, `shipTo` the customer — and each carrier swaps the parties the way its API wants.
     */
    public bool $isReturn = false;

    /** Usually the order reference. Printed on the label, searchable in the carrier's portal. */
    public string $reference = '';

    /** A second reference — the order number when `reference` is something else. */
    public string $reference2 = '';

    /** ISO 4217, upper case. */
    public string $currency = '';

    /** The goods value, for customs and insurance. */
    public float $declaredValue = 0.0;

    /** The pickup point / parcel shop / locker id this goes to, when it does not go to a door. */
    public string $pointId = '';

    /**
     * The point itself, as the customer chose it, when Carrier knows it — so a search-mode
     * carrier (UPS Access Point, FedEx Hold at Location) does not have to look it up again.
     */
    public ?PickupPoint $point = null;

    /** Cash on delivery. 0 means none. */
    public float $codAmount = 0.0;

    public string $codCurrency = '';

    /** What the carrier prints on the COD remittance — usually the order number. */
    public string $codReference = '';

    /** `helpers\LabelFormat` value the label should be produced in. */
    public string $labelFormat = '';

    public ?DateTime $shipDate = null;

    /**
     * Normalised extras — see `base\Accessorial`. A carrier maps the ones it understands and
     * reports the ones it does not, rather than silently dropping a liftgate.
     *
     * @var string[]
     */
    public array $accessorials = [];

    /** @var CustomsItem[] */
    public array $customsItems = [];

    /** Free text for the courier: "leave with neighbour", gate codes. */
    public string $instructions = '';

    /** Carrier-specific extras a method or a plugin wants passed through untouched. */
    public array $options = [];

    /** The Commerce order this came from, for logging only. Carriers must never read it. */
    public ?int $orderId = null;

    public function totalWeightG(): int
    {
        return array_sum(array_map(static fn(Parcel $p) => $p->weightG, $this->parcels));
    }

    public function isInternational(): bool
    {
        return $this->shipFrom && $this->shipTo && !$this->shipFrom->isDomesticTo($this->shipTo);
    }

    public function hasCod(): bool
    {
        return $this->codAmount > 0;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }
}
