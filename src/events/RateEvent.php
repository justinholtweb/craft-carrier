<?php

namespace justinholtweb\carrier\events;

use craft\commerce\elements\Order;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\models\Quote;
use yii\base\Event;

/**
 * Fired after a checkout price is worked out. Change `quote->price` to adjust it, or set
 * `quote->available` false to hide the method for this cart.
 */
class RateEvent extends Event
{
    public ?Order $order = null;

    public ?Method $method = null;

    public ?Quote $quote = null;
}
