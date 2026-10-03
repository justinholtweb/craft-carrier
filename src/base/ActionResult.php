<?php

namespace justinholtweb\carrier\base;

/**
 * The answer to a void or a collection request.
 */
class ActionResult
{
    public function __construct(
        public bool $success = false,
        public ?string $message = null,
        /** A confirmation number, when the carrier gives one. */
        public string $reference = '',
        public array $data = [],
    ) {
    }

    public static function ok(string $reference = '', ?string $message = null, array $data = []): self
    {
        return new self(success: true, message: $message, reference: $reference, data: $data);
    }

    public static function failed(string $message): self
    {
        return new self(success: false, message: $message);
    }
}
