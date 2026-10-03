<?php

namespace justinholtweb\carrier\events;

use craft\events\CancelableEvent;
use justinholtweb\carrier\models\Method;

/**
 * Fired around saving a checkout method.
 */
class MethodEvent extends CancelableEvent
{
    public ?Method $method = null;

    public bool $isNew = false;
}
