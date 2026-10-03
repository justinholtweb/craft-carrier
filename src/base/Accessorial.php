<?php

namespace justinholtweb\carrier\base;

use Craft;

/**
 * Normalised extra services. Freight carriers price most of these; parcel carriers understand a
 * few (signature, Saturday delivery). A carrier maps what it knows and declares what it supports
 * in `Capabilities::accessorials()`, so a method cannot ask for a liftgate the carrier would
 * silently ignore.
 */
final class Accessorial
{
    public const LIFTGATE_PICKUP = 'liftgatePickup';
    public const LIFTGATE_DELIVERY = 'liftgateDelivery';
    public const RESIDENTIAL_PICKUP = 'residentialPickup';
    public const RESIDENTIAL_DELIVERY = 'residentialDelivery';
    public const INSIDE_DELIVERY = 'insideDelivery';
    public const LIMITED_ACCESS_PICKUP = 'limitedAccessPickup';
    public const LIMITED_ACCESS_DELIVERY = 'limitedAccessDelivery';
    public const APPOINTMENT = 'appointment';
    public const NOTIFY = 'notify';
    public const HAZMAT = 'hazmat';
    public const SIGNATURE = 'signature';
    public const ADULT_SIGNATURE = 'adultSignature';
    public const SATURDAY_DELIVERY = 'saturdayDelivery';

    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function labels(): array
    {
        return [
            self::LIFTGATE_PICKUP => Craft::t('carrier', 'Liftgate at pickup'),
            self::LIFTGATE_DELIVERY => Craft::t('carrier', 'Liftgate at delivery'),
            self::RESIDENTIAL_PICKUP => Craft::t('carrier', 'Residential pickup'),
            self::RESIDENTIAL_DELIVERY => Craft::t('carrier', 'Residential delivery'),
            self::INSIDE_DELIVERY => Craft::t('carrier', 'Inside delivery'),
            self::LIMITED_ACCESS_PICKUP => Craft::t('carrier', 'Limited access pickup'),
            self::LIMITED_ACCESS_DELIVERY => Craft::t('carrier', 'Limited access delivery'),
            self::APPOINTMENT => Craft::t('carrier', 'Delivery appointment'),
            self::NOTIFY => Craft::t('carrier', 'Notify before delivery'),
            self::HAZMAT => Craft::t('carrier', 'Hazardous materials'),
            self::SIGNATURE => Craft::t('carrier', 'Signature required'),
            self::ADULT_SIGNATURE => Craft::t('carrier', 'Adult signature required'),
            self::SATURDAY_DELIVERY => Craft::t('carrier', 'Saturday delivery'),
        ];
    }
}
