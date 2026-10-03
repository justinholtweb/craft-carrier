<?php

namespace justinholtweb\carrier\base;

use justinholtweb\carrier\models\canonical\PickupPoint;

/**
 * One page of pickup points. A page with no `nextCursor` is the last one.
 */
class PointPage
{
    /**
     * @param PickupPoint[] $points
     */
    public function __construct(
        public array $points = [],
        public ?string $nextCursor = null,
        public ?string $error = null,
    ) {
    }

    public static function of(array $points, ?string $nextCursor = null): self
    {
        return new self(points: array_values($points), nextCursor: $nextCursor);
    }

    public static function failed(string $error): self
    {
        return new self(error: $error);
    }
}
