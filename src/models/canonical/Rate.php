<?php

namespace justinholtweb\carrier\models\canonical;

/**
 * One priced service a carrier offered.
 */
class Rate extends Document
{
    public string $serviceCode = '';

    public string $serviceName = '';

    /** The carrier's total, taxes and surcharges included, in `currency`. */
    public float $amount = 0.0;

    public string $currency = '';

    public ?int $transitDays = null;

    public ?string $estimatedDelivery = null;

    /** A carrier's quote id, which some freight carriers require when the BOL is created. */
    public string $quoteId = '';

    /** Notes worth showing the merchant — "residential surcharge applied". */
    public array $notes = [];
}
