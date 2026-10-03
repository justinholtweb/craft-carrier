<?php

namespace justinholtweb\carrier\models\canonical;

/**
 * One line of a customs declaration. Built from line items for international shipments only.
 */
class CustomsItem extends Document
{
    public string $description = '';

    public int $quantity = 1;

    /** Value of one unit, in the shipment currency. */
    public float $unitValue = 0.0;

    /** Weight of one unit, in grams. */
    public int $unitWeightG = 0;

    public string $hsCode = '';

    /** ISO alpha-2 country of manufacture. */
    public string $originCountry = '';

    public string $sku = '';
}
