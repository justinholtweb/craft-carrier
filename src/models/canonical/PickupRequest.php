<?php

namespace justinholtweb\carrier\models\canonical;

use DateTime;

/**
 * Asking the carrier to send a driver.
 */
class PickupRequest extends Document
{
    public ?Address $address = null;

    public ?DateTime $date = null;

    /** "09:00" */
    public string $readyTime = '09:00';

    /** "17:00" */
    public string $closeTime = '17:00';

    public int $parcelCount = 1;

    public int $totalWeightG = 0;

    /** The carrier's shipment ids or tracking numbers being collected, when it wants them. */
    public array $trackingNumbers = [];

    public string $instructions = '';
}
