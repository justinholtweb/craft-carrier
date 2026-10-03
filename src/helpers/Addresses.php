<?php

namespace justinholtweb\carrier\helpers;

use craft\elements\Address as CraftAddress;
use justinholtweb\carrier\models\canonical\Address;

/**
 * Craft addresses in, carrier addresses out.
 */
final class Addresses
{
    /**
     * Turn a Craft address element into the canonical shape.
     *
     * `$phoneField` / `$houseNumberField` are custom-field handles the merchant named in settings.
     * Commerce 5 addresses have no phone attribute at all, and every carrier in this family wants
     * one for delivery notifications, so the field has to be configurable rather than guessed.
     */
    public static function fromCraft(
        ?CraftAddress $address,
        string $email = '',
        string $phoneField = '',
        string $houseNumberField = '',
    ): Address {
        if ($address === null) {
            return new Address(['email' => $email]);
        }

        $street1 = (string)$address->addressLine1;
        $houseNumber = $houseNumberField !== '' ? self::fieldValue($address, $houseNumberField) : '';

        if ($houseNumber === '') {
            [$street1, $houseNumber] = self::splitHouseNumber($street1);
        }

        $name = trim((string)($address->fullName ?: trim($address->firstName . ' ' . $address->lastName)));

        return new Address([
            'name' => $name,
            'company' => (string)$address->organization,
            'street1' => $street1,
            'street2' => trim((string)$address->addressLine2 . ' ' . (string)($address->addressLine3 ?? '')),
            'houseNumber' => $houseNumber,
            'city' => (string)$address->locality,
            'postcode' => (string)$address->postalCode,
            'region' => (string)$address->administrativeArea,
            'countryCode' => strtoupper((string)$address->countryCode),
            'phone' => $phoneField !== '' ? self::fieldValue($address, $phoneField) : '',
            'email' => $email,
            'residential' => (string)$address->organization === '',
        ]);
    }

    /**
     * Separate a trailing (or, as in France, leading) house number from a street line.
     *
     * "Ilica 242", "Ilica 242a", "Váci út 12/B", "Hauptstraße 5-7" → [street, number].
     * "12 Rue de Rivoli" → ["Rue de Rivoli", "12"]. A line with no recognisable number comes back
     * unchanged with an empty number, which the carriers that require one will then say so about,
     * in their words, on the Problems screen.
     */
    public static function splitHouseNumber(string $line): array
    {
        $line = trim(preg_replace('/\s+/', ' ', $line));

        if (preg_match('/^(.*?\D)\s*,?\s+(\d+[a-zA-Z]?(?:\s?[\/\-]\s?\d*[a-zA-Z]?)?)\.?$/u', $line, $m)) {
            return [trim($m[1], ' ,'), $m[2]];
        }

        if (preg_match('/^(\d+[a-zA-Z]?(?:[\/\-]\d*[a-zA-Z]?)?),?\s+(.+)$/u', $line, $m)) {
            return [$m[2], $m[1]];
        }

        return [$line, ''];
    }

    /**
     * Digits only, with a leading + kept. Carriers disagree on everything else in a phone number,
     * and every one of them accepts this.
     */
    public static function normalisePhone(string $phone, string $defaultCallingCode = ''): string
    {
        $phone = trim($phone);

        if ($phone === '') {
            return '';
        }

        $plus = str_starts_with($phone, '+') || str_starts_with($phone, '00');
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '00')) {
            $digits = substr($digits, 2);
        }

        if (!$plus && $defaultCallingCode !== '' && str_starts_with($digits, '0')) {
            return '+' . ltrim($defaultCallingCode, '+') . substr($digits, 1);
        }

        return ($plus ? '+' : '') . $digits;
    }

    private static function fieldValue(CraftAddress $address, string $handle): string
    {
        try {
            return trim((string)$address->getFieldValue($handle));
        } catch (\Throwable) {
            return '';
        }
    }
}
