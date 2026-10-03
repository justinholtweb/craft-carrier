<?php

namespace justinholtweb\carrier\base;

use justinholtweb\carrier\models\canonical\Label;

/**
 * Labels fetched again, possibly several shipments in one document.
 */
class LabelResult
{
    /**
     * @param Label[] $labels
     */
    public function __construct(
        public array $labels = [],
        public ?string $error = null,
    ) {
    }

    public static function ok(array $labels): self
    {
        return new self(labels: array_values($labels));
    }

    public static function failed(string $error): self
    {
        return new self(error: $error);
    }

    public function successful(): bool
    {
        return $this->error === null;
    }
}
