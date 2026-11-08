<?php

namespace justinholtweb\carrier\models\canonical;

/**
 * A parcel shop, a locker, a post office or a store counter a customer can collect from.
 */
class PickupPoint extends Document
{
    public const TYPE_LOCKER = 'locker';
    public const TYPE_SHOP = 'shop';
    public const TYPE_POST_OFFICE = 'postOffice';
    public const TYPE_STORE = 'store';

    /**
     * @return array<string,string> type => merchant-facing label
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_LOCKER => \Craft::t('carrier', 'Lockers'),
            self::TYPE_SHOP => \Craft::t('carrier', 'Parcel shops'),
            self::TYPE_POST_OFFICE => \Craft::t('carrier', 'Post offices'),
            self::TYPE_STORE => \Craft::t('carrier', 'Store counters'),
        ];
    }

    /** The carrier's own id, which is what goes on the shipment. */
    public string $id = '';

    public string $type = self::TYPE_SHOP;

    public string $name = '';

    public string $street = '';

    public string $city = '';

    public string $postcode = '';

    public string $countryCode = '';

    public ?float $latitude = null;

    public ?float $longitude = null;

    /** Free-form opening hours, as the carrier gives them. */
    public string $hours = '';

    public bool $codAllowed = true;

    /** Heaviest parcel the point accepts, in grams. 0 means the carrier did not say. */
    public int $maxWeightG = 0;

    /** Anything else the carrier sent that a template might want. */
    public array $extra = [];

    /** Set by a search: kilometres from the place searched. */
    public ?float $distanceKm = null;

    public function label(): string
    {
        return trim($this->name . ', ' . $this->street . ', ' . $this->postcode . ' ' . $this->city, ', ');
    }
}
