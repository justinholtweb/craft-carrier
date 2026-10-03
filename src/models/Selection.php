<?php

namespace justinholtweb\carrier\models;

use craft\base\Model;
use DateTime;

/**
 * The pickup point a customer chose for a cart.
 */
class Selection extends Model
{
    public ?int $id = null;

    public ?int $orderId = null;

    public ?int $methodId = null;

    public ?int $connectionId = null;

    public string $pointId = '';

    /** The point as it was when chosen — what the confirmation email and the label use. */
    public array $point = [];

    public ?DateTime $dateCreated = null;

    public ?DateTime $dateUpdated = null;

    public ?string $uid = null;

    public function __construct($config = [])
    {
        if (array_key_exists('point', $config) && !is_array($config['point'])) {
            $config['point'] = json_decode((string)$config['point'], true) ?: [];
        }

        parent::__construct($config);
    }

    public function getLabel(): string
    {
        $p = $this->point;

        return trim(implode(', ', array_filter([
            $p['name'] ?? '',
            $p['street'] ?? '',
            trim(($p['postcode'] ?? '') . ' ' . ($p['city'] ?? '')),
        ])));
    }
}
