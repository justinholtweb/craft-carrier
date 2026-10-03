<?php

namespace justinholtweb\carrier\events;

use craft\commerce\elements\Order;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\models\Method;
use yii\base\Event;

/**
 * Fired once a Commerce order has been turned into a shipment request, before anyone rates or
 * ships it. The place to add a gate code, change a reference format or set a carrier option.
 */
class ShipmentRequestEvent extends Event
{
    public ?Order $order = null;

    public ?Connection $connection = null;

    public ?Method $method = null;

    public ?ShipmentRequest $request = null;

    /** `rate`, `label` or `preview`. */
    public string $purpose = '';
}
