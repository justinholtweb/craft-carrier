<?php

namespace justinholtweb\carrier\models\canonical;

/**
 * A postal address the way carriers want it, not the way Craft stores it.
 *
 * Craft's address element is CLDR-shaped (`addressLine1`, `locality`, `administrativeArea`); every
 * carrier API is shaped differently again. This is the one in-between both sides translate to.
 * `houseNumber` is split out because GLS, DPD and the posts ask for it separately, and a street
 * line that already contains it is what they get when the merchant's address form has no such
 * field — see `helpers\Addresses::splitHouseNumber()`.
 */
class Address extends Document
{
    public string $name = '';

    public string $company = '';

    public string $street1 = '';

    public string $street2 = '';

    public string $houseNumber = '';

    public string $city = '';

    public string $postcode = '';

    /** State, province or county code where the country uses them (US, CA, AU…). */
    public string $region = '';

    /** ISO 3166-1 alpha-2, upper case. */
    public string $countryCode = '';

    public string $phone = '';

    public string $email = '';

    public bool $residential = true;

    public function isDomesticTo(Address $other): bool
    {
        return strtoupper($this->countryCode) === strtoupper($other->countryCode);
    }

    /** The street line with the house number put back, for carriers that want one field. */
    public function streetLine(): string
    {
        return trim($this->street1 . ($this->houseNumber !== '' ? ' ' . $this->houseNumber : ''));
    }

    /** Name first, company as a fallback — every carrier requires *something* in the name field. */
    public function contactName(): string
    {
        return $this->name !== '' ? $this->name : $this->company;
    }
}
