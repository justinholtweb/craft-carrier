<?php

namespace justinholtweb\carrier\base;

use justinholtweb\carrier\models\canonical\Address;

/**
 * Whether an address is deliverable, and what the carrier would rather it said.
 */
class AddressResult
{
    /**
     * @param Address[] $suggestions
     */
    public function __construct(
        public bool $valid = false,
        public array $suggestions = [],
        public ?bool $residential = null,
        public array $messages = [],
        public ?string $error = null,
    ) {
    }
}
