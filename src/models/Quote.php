<?php

namespace justinholtweb\carrier\models;

/**
 * What a checkout method costs for one cart, and how that number came about.
 */
class Quote
{
    public function __construct(
        public bool $available = false,
        public float $price = 0.0,
        /** The carrier's own figure before markup, for live rates. */
        public ?float $carrierAmount = null,
        public ?int $transitDays = null,
        /** `live`, `flat`, `table`, `free`, `fallback`. */
        public string $source = '',
        /** Why the method is unavailable, or a note about how the price was reached. */
        public ?string $reason = null,
        public int $parcelCount = 0,
        public int $weightG = 0,
    ) {
    }

    public static function unavailable(string $reason): self
    {
        return new self(available: false, reason: $reason);
    }

    public function description(): string
    {
        if ($this->transitDays === null) {
            return '';
        }

        return \Craft::t('carrier', '{n, plural, =1{1 business day} other{# business days}}', ['n' => $this->transitDays]);
    }
}
