<?php

namespace justinholtweb\carrier\events;

use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrier\models\Shipment;
use yii\base\Event;

/**
 * Fired when tracking changes a shipment's status — the hook for a "your parcel is out for
 * delivery" email, an SMS, a Slack message.
 */
class TrackingUpdateEvent extends Event
{
    public ?Shipment $shipment = null;

    public ?TrackingInfo $tracking = null;

    public string $previousStatus = '';

    /** How many new scans this update added. */
    public int $newEvents = 0;
}
