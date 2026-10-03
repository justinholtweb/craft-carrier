<?php

namespace justinholtweb\carrier\models;

use craft\base\Model;
use DateTime;
use justinholtweb\carrier\models\canonical\Parcel;

/**
 * One stored piece of a shipment.
 */
class ShipmentParcel extends Model
{
    public ?int $id = null;

    public ?int $shipmentId = null;

    public int $sequence = 0;

    public ?string $trackingNumber = null;

    public int $weightG = 0;

    public int $lengthMm = 0;

    public int $widthMm = 0;

    public int $heightMm = 0;

    public ?string $box = null;

    public array $items = [];

    public ?DateTime $dateCreated = null;

    public ?DateTime $dateUpdated = null;

    public ?string $uid = null;

    public function __construct($config = [])
    {
        if (array_key_exists('items', $config) && !is_array($config['items'])) {
            $config['items'] = json_decode((string)$config['items'], true) ?: [];
        }

        parent::__construct($config);
    }

    public static function fromParcel(Parcel $parcel, int $sequence): self
    {
        return new self([
            'sequence' => $sequence,
            'weightG' => $parcel->weightG,
            'lengthMm' => $parcel->lengthMm,
            'widthMm' => $parcel->widthMm,
            'heightMm' => $parcel->heightMm,
            'box' => $parcel->box ?: null,
            'items' => $parcel->items,
        ]);
    }
}
