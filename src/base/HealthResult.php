<?php

namespace justinholtweb\carrier\base;

/**
 * The answer to "is this connection actually wired up?", built to be shown to a merchant rather
 * than to a developer. A failing test says which credential to look at, not which class threw.
 */
class HealthResult
{
    public function __construct(
        public bool $ok = false,
        public string $message = '',
        /** Named facts worth showing — company name, API version, record counts. */
        public array $details = [],
        /** What to try next, in the merchant's language. */
        public array $hints = [],
        public ?int $durationMs = null,
    ) {
    }

    public static function pass(string $message, array $details = []): self
    {
        return new self(ok: true, message: $message, details: $details);
    }

    public static function fail(string $message, array $hints = [], array $details = []): self
    {
        return new self(ok: false, message: $message, details: $details, hints: $hints);
    }
}
