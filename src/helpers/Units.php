<?php

namespace justinholtweb\carrier\helpers;

/**
 * Unit conversion, done once in and once out.
 *
 * Everything inside Carrier is grams and millimetres. Store units arrive through `toGrams()` /
 * `toMm()`; a carrier converts to whatever its API wants with the `gramsTo*` / `mmTo*` helpers.
 * Rounding is up, because a carrier that weighs a parcel at 1.01 kg bills it as more than 1 kg.
 */
final class Units
{
    private const GRAMS = [
        'g' => 1.0,
        'kg' => 1000.0,
        'lb' => 453.59237,
        'oz' => 28.349523125,
    ];

    private const MM = [
        'mm' => 1.0,
        'cm' => 10.0,
        'm' => 1000.0,
        'in' => 25.4,
        'ft' => 304.8,
    ];

    public static function toGrams(float $value, string $unit): int
    {
        return (int)ceil($value * (self::GRAMS[strtolower($unit)] ?? 1.0) - 1e-9);
    }

    public static function toMm(float $value, string $unit): int
    {
        return (int)ceil($value * (self::MM[strtolower($unit)] ?? 1.0) - 1e-9);
    }

    public static function gramsTo(int $grams, string $unit, int $precision = 2): float
    {
        $factor = self::GRAMS[strtolower($unit)] ?? 1.0;

        return self::ceilTo($grams / $factor, $precision);
    }

    public static function mmTo(int $mm, string $unit, int $precision = 1): float
    {
        $factor = self::MM[strtolower($unit)] ?? 1.0;

        return self::ceilTo($mm / $factor, $precision);
    }

    public static function gramsToKg(int $grams, int $precision = 2): float
    {
        return self::gramsTo($grams, 'kg', $precision);
    }

    public static function gramsToLb(int $grams, int $precision = 1): float
    {
        return self::gramsTo($grams, 'lb', $precision);
    }

    /** Whole pounds, rounded up — what every LTL carrier wants. */
    public static function gramsToWholeLb(int $grams): int
    {
        return (int)max(1, ceil($grams / self::GRAMS['lb'] - 1e-9));
    }

    public static function mmToCm(int $mm, int $precision = 0): float
    {
        return self::mmTo($mm, 'cm', $precision);
    }

    public static function mmToIn(int $mm, int $precision = 0): float
    {
        return self::mmTo($mm, 'in', $precision);
    }

    private static function ceilTo(float $value, int $precision): float
    {
        $factor = 10 ** $precision;

        return ceil($value * $factor - 1e-9) / $factor;
    }
}
