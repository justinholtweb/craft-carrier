<?php

namespace justinholtweb\carrier\base;

use Craft;

/**
 * The one status vocabulary every carrier's tracking codes are mapped onto.
 *
 * Small on purpose. A merchant needs to know whether a parcel has left, is moving, is waiting to
 * be collected, has arrived, or has a problem — not the difference between UPS's "Departed from
 * facility" and "Arrived at facility".
 */
final class TrackingStatus
{
    public const UNKNOWN = 'unknown';
    public const PRE_TRANSIT = 'preTransit';
    public const IN_TRANSIT = 'inTransit';
    public const OUT_FOR_DELIVERY = 'outForDelivery';
    public const READY_FOR_PICKUP = 'readyForPickup';
    public const DELIVERED = 'delivered';
    public const EXCEPTION = 'exception';
    public const RETURNED = 'returned';
    public const CANCELLED = 'cancelled';

    public static function all(): array
    {
        return [
            self::UNKNOWN, self::PRE_TRANSIT, self::IN_TRANSIT, self::OUT_FOR_DELIVERY,
            self::READY_FOR_PICKUP, self::DELIVERED, self::EXCEPTION, self::RETURNED, self::CANCELLED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /** Nothing more will happen: stop polling. */
    public static function isFinal(string $status): bool
    {
        return in_array($status, [self::DELIVERED, self::RETURNED, self::CANCELLED], true);
    }

    /**
     * How far along a status is. Used so an out-of-order webhook ("in transit" arriving after
     * "delivered") cannot move a shipment backwards. Exceptions are not ranked: a problem can
     * happen at any point and must always be shown.
     */
    public static function rank(string $status): int
    {
        return match ($status) {
            self::PRE_TRANSIT => 1,
            self::IN_TRANSIT => 2,
            self::OUT_FOR_DELIVERY => 3,
            self::READY_FOR_PICKUP => 4,
            self::DELIVERED, self::RETURNED, self::CANCELLED => 5,
            default => 0,
        };
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PRE_TRANSIT => Craft::t('carrier', 'Label created'),
            self::IN_TRANSIT => Craft::t('carrier', 'In transit'),
            self::OUT_FOR_DELIVERY => Craft::t('carrier', 'Out for delivery'),
            self::READY_FOR_PICKUP => Craft::t('carrier', 'Ready for pickup'),
            self::DELIVERED => Craft::t('carrier', 'Delivered'),
            self::EXCEPTION => Craft::t('carrier', 'Exception'),
            self::RETURNED => Craft::t('carrier', 'Returned to sender'),
            self::CANCELLED => Craft::t('carrier', 'Cancelled'),
            default => Craft::t('carrier', 'Unknown'),
        };
    }

    /** A Craft status-indicator colour. */
    public static function color(string $status): string
    {
        return match ($status) {
            self::PRE_TRANSIT => 'light',
            self::IN_TRANSIT, self::OUT_FOR_DELIVERY => 'blue',
            self::READY_FOR_PICKUP => 'orange',
            self::DELIVERED => 'green',
            self::EXCEPTION, self::RETURNED => 'red',
            default => 'grey',
        };
    }
}
