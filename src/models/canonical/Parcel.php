<?php

namespace justinholtweb\carrier\models\canonical;

/**
 * One physical piece: a carton, an envelope, or — for freight — a handling unit.
 *
 * Everything is in grams and millimetres. Carriers convert on the way out with `helpers\Units`,
 * so no carrier ever has to know what units the store was configured in.
 */
class Parcel extends Document
{
    public int $weightG = 0;

    public int $lengthMm = 0;

    public int $widthMm = 0;

    public int $heightMm = 0;

    /** The merchant's name for the box it is packed in, if any. */
    public string $box = '';

    /** Declared value in the shipment's currency, for insurance. 0 means none declared. */
    public float $value = 0.0;

    public string $reference = '';

    public string $description = '';

    // Freight ---------------------------------------------------------------------------------

    /** `pallet`, `crate`, `carton`, `drum`… Only freight carriers read this. */
    public string $packagingType = '';

    /** How many identical pieces this handling unit holds. */
    public int $pieces = 1;

    /** NMFC freight class as a string — "77.5" is a class, 77.5 is a rounding error. */
    public string $freightClass = '';

    public string $nmfc = '';

    public bool $hazmat = false;

    /**
     * Which line items are in this parcel: `[['lineItemId' => 12, 'qty' => 2], …]`. Used for
     * customs, partial-fulfilment history and the packing slip, never by a carrier's API.
     */
    public array $items = [];

    public function hasDimensions(): bool
    {
        return $this->lengthMm > 0 && $this->widthMm > 0 && $this->heightMm > 0;
    }

    public function volumeMm3(): int
    {
        return $this->lengthMm * $this->widthMm * $this->heightMm;
    }
}
