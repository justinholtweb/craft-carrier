<?php

namespace justinholtweb\carrier\helpers;

use Craft;

/**
 * The label formats Carrier knows how to store, print and bundle.
 *
 * Named by what a merchant has on the desk, not by what the carrier calls it: an A4 office
 * printer, an A6 or 4×6 thermal printer, a Zebra speaking ZPL.
 */
final class LabelFormat
{
    public const PDF_A4 = 'pdf-a4';
    public const PDF_A6 = 'pdf-a6';
    public const PDF_4X6 = 'pdf-4x6';
    public const PDF_LETTER = 'pdf-letter';
    public const ZPL = 'zpl';
    public const PNG = 'png';

    public static function all(): array
    {
        return [self::PDF_A4, self::PDF_A6, self::PDF_4X6, self::PDF_LETTER, self::ZPL, self::PNG];
    }

    public static function label(string $format): string
    {
        return match ($format) {
            self::PDF_A4 => Craft::t('carrier', 'PDF, A4 sheet'),
            self::PDF_A6 => Craft::t('carrier', 'PDF, A6 thermal'),
            self::PDF_4X6 => Craft::t('carrier', 'PDF, 4×6 in thermal'),
            self::PDF_LETTER => Craft::t('carrier', 'PDF, US Letter sheet'),
            self::ZPL => Craft::t('carrier', 'ZPL (Zebra thermal)'),
            self::PNG => Craft::t('carrier', 'PNG image, 4×6 in'),
            default => $format,
        };
    }

    public static function options(array $formats): array
    {
        $options = [];

        foreach ($formats as $format) {
            $options[] = ['value' => $format, 'label' => self::label($format)];
        }

        return $options;
    }

    public static function isPdf(string $format): bool
    {
        return str_starts_with($format, 'pdf');
    }

    public static function mimeType(string $format): string
    {
        return match (true) {
            self::isPdf($format) => 'application/pdf',
            $format === self::PNG => 'image/png',
            $format === self::ZPL => 'application/x-zpl',
            default => 'application/octet-stream',
        };
    }

    public static function extension(string $format): string
    {
        return match (true) {
            self::isPdf($format) => 'pdf',
            $format === self::PNG => 'png',
            $format === self::ZPL => 'zpl',
            default => 'bin',
        };
    }

    /**
     * Whether some bytes really are what a carrier claimed. Cheap magic-number checks, because a
     * carrier that returned an HTML error page as a "PDF" is a support ticket waiting to happen.
     */
    public static function bytesMatch(string $format, string $data): bool
    {
        if ($data === '') {
            return false;
        }

        return match (true) {
            self::isPdf($format) => str_starts_with($data, '%PDF'),
            $format === self::PNG => str_starts_with($data, "\x89PNG"),
            // ZPL starts with ^XA, sometimes after a ~ command or whitespace.
            $format === self::ZPL => str_contains(substr($data, 0, 200), '^XA'),
            default => true,
        };
    }
}
