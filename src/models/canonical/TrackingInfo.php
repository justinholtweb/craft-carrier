<?php

namespace justinholtweb\carrier\models\canonical;

use DateTime;

/**
 * What a carrier knows about one tracking number right now.
 */
class TrackingInfo extends Document
{
    protected const LISTS = ['events' => TrackingEvent::class];

    public string $trackingNumber = '';

    /** `base\TrackingStatus` value. */
    public string $status = '';

    /** The carrier's own words for the current status. */
    public string $statusText = '';

    public ?DateTime $estimatedDelivery = null;

    public ?DateTime $deliveredAt = null;

    public string $signedBy = '';

    /** @var TrackingEvent[] newest first or oldest first — the engine sorts. */
    public array $events = [];

    /** Set when the carrier answered but did not know this number. */
    public bool $notFound = false;
}
