<?php

namespace justinholtweb\carrier\helpers;

/**
 * The smallest valid PDF that says something.
 *
 * Used by the Mock carrier, the store-pickup slip and the test suite. It exists so Carrier can
 * produce a real, openable, printable label without a PDF library dependency — the carriers make
 * the real ones.
 */
final class Pdf
{
    /**
     * @param string[] $lines one text line each, top to bottom
     * @param float $widthPt page width in points (A6 = 297.6, 4in = 288)
     * @param float $heightPt page height in points (A6 = 419.5, 6in = 432)
     */
    public static function text(array $lines, float $widthPt = 288.0, float $heightPt = 432.0): string
    {
        $stream = "BT /F1 12 Tf 18 " . ($heightPt - 36) . " Td 16 TL\n";

        foreach ($lines as $line) {
            $stream .= '(' . self::escape($line) . ") Tj T*\n";
        }

        $stream .= "ET\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.1F %.1F] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>', $widthPt, $heightPt),
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    private static function escape(string $text): string
    {
        // Helvetica's built-in encoding has no Croatian or Hungarian letters; transliterate rather
        // than print mojibake on a label a courier has to read.
        $ascii = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
