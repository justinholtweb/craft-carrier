<?php

namespace justinholtweb\carrier\base;

use justinholtweb\carrier\models\canonical\Rate;

/**
 * Every service a carrier priced, or why it could not.
 */
class RateResult
{
    /**
     * @param Rate[] $rates
     */
    public function __construct(
        public array $rates = [],
        public ?string $error = null,
        public array $warnings = [],
    ) {
    }

    public static function ok(array $rates, array $warnings = []): self
    {
        return new self(rates: array_values($rates), warnings: $warnings);
    }

    public static function failed(string $error): self
    {
        return new self(error: $error);
    }

    public function successful(): bool
    {
        return $this->error === null;
    }

    public function forService(string $serviceCode): ?Rate
    {
        foreach ($this->rates as $rate) {
            if (strcasecmp($rate->serviceCode, $serviceCode) === 0) {
                return $rate;
            }
        }

        return null;
    }

    public function cheapest(): ?Rate
    {
        $rates = $this->rates;
        usort($rates, static fn(Rate $a, Rate $b) => $a->amount <=> $b->amount);

        return $rates[0] ?? null;
    }
}
