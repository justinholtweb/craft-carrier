<?php

namespace justinholtweb\carrier\events;

use craft\events\CancelableEvent;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\Shipment;

/**
 * Fired around buying a label. Cancelling the "before" event stops the purchase before the
 * carrier is called.
 */
class LabelEvent extends CancelableEvent
{
    public ?Shipment $shipment = null;

    public ?ShipmentRequest $request = null;

    /** Set on the "after" event only. */
    public ?ShipmentResult $result = null;
}
