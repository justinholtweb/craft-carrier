<?php

namespace justinholtweb\carrier\models\canonical;

/**
 * What a pickup-point lookup asks for.
 *
 * A `list`-mode carrier is asked for a whole country, one page at a time, and ignores the
 * location fields. A `search`-mode carrier is asked for the points near a place.
 */
class PointQuery extends Document
{
    public string $countryCode = '';

    public string $postcode = '';

    public string $city = '';

    public string $street = '';

    public ?float $latitude = null;

    public ?float $longitude = null;

    public int $radiusKm = 10;

    public int $limit = 20;

    /** Restrict to these `PickupPoint::TYPE_*` values. Empty means any. */
    public array $types = [];

    /** The cursor from the previous page, for list-mode paging. */
    public ?string $cursor = null;
}
