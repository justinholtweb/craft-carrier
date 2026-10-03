<?php

namespace justinholtweb\carrier\base;

/**
 * The things a carrier can do. A carrier declares the ones it implements in `capabilities()`,
 * and the conformance suite fails it for declaring one it did not implement — or for
 * implementing one it forgot to declare, which would leave it invisible in the control panel.
 */
final class Feature
{
    /** Live prices for a consignment. */
    public const RATES = 'rates';

    /** Buy labels. */
    public const LABELS = 'labels';

    /** Void a label the merchant no longer needs. */
    public const CANCEL = 'cancel';

    /** Fetch existing labels again, several shipments in one document where the carrier can. */
    public const REPRINT = 'reprint';

    public const TRACKING = 'tracking';

    /** Parcel shops, lockers, post offices. */
    public const POINTS = 'points';

    /** Ask for a driver to come and collect. */
    public const PICKUPS = 'pickups';

    public const RETURNS = 'returns';

    /**
     * End-of-day close / manifest. Some carriers (Magyar Posta) ship nothing until the day's
     * consignments are closed; others (DPD EasyShip) print a manifest for the driver.
     */
    public const CLOSE_DAY = 'closeDay';

    /** Cash on delivery. */
    public const COD = 'cod';

    public const ADDRESS_VALIDATION = 'addressValidation';

    /** The carrier pushes tracking to us. */
    public const WEBHOOKS = 'webhooks';

    public const INTERNATIONAL = 'international';

    /** Several pieces under one shipment. Without it, each parcel is its own shipment. */
    public const MULTI_PIECE = 'multiPiece';

    /** LTL: handling units, freight class and accessorials matter. */
    public const FREIGHT = 'freight';

    /**
     * Which method a carrier has to implement for each feature that has one. Flags such as
     * `cod` and `international` change what a request may contain, not what can be called.
     */
    public const METHODS = [
        self::RATES => 'rate',
        self::LABELS => 'createShipment',
        self::CANCEL => 'cancelShipment',
        self::REPRINT => 'printLabels',
        self::TRACKING => 'track',
        self::POINTS => 'pickupPoints',
        self::PICKUPS => 'requestPickup',
        self::CLOSE_DAY => 'closeDay',
        self::ADDRESS_VALIDATION => 'validateAddress',
        self::WEBHOOKS => 'handleWebhook',
    ];

    public static function all(): array
    {
        return [
            self::RATES, self::LABELS, self::CANCEL, self::REPRINT, self::TRACKING, self::POINTS,
            self::PICKUPS, self::CLOSE_DAY, self::RETURNS, self::COD, self::ADDRESS_VALIDATION, self::WEBHOOKS,
            self::INTERNATIONAL, self::MULTI_PIECE, self::FREIGHT,
        ];
    }

    public static function label(string $feature): string
    {
        return match ($feature) {
            self::RATES => \Craft::t('carrier', 'Live rates'),
            self::LABELS => \Craft::t('carrier', 'Labels'),
            self::CANCEL => \Craft::t('carrier', 'Void labels'),
            self::REPRINT => \Craft::t('carrier', 'Reprint'),
            self::TRACKING => \Craft::t('carrier', 'Tracking'),
            self::POINTS => \Craft::t('carrier', 'Pickup points'),
            self::PICKUPS => \Craft::t('carrier', 'Collections'),
            self::CLOSE_DAY => \Craft::t('carrier', 'End-of-day close'),
            self::RETURNS => \Craft::t('carrier', 'Return labels'),
            self::COD => \Craft::t('carrier', 'Cash on delivery'),
            self::ADDRESS_VALIDATION => \Craft::t('carrier', 'Address validation'),
            self::WEBHOOKS => \Craft::t('carrier', 'Tracking webhooks'),
            self::INTERNATIONAL => \Craft::t('carrier', 'International'),
            self::MULTI_PIECE => \Craft::t('carrier', 'Multi-piece'),
            self::FREIGHT => \Craft::t('carrier', 'Freight (LTL)'),
            default => $feature,
        };
    }
}
