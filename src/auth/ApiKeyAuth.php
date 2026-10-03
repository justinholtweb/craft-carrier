<?php

namespace justinholtweb\carrier\auth;

/**
 * A key in a header. The header name and any prefix vary per vendor, so both are constructor
 * arguments rather than another six near-identical classes.
 */
class ApiKeyAuth extends BaseAuth
{
    public function __construct(
        private string $keyField = 'apiKey',
        private string $header = 'Authorization',
        private string $prefix = 'Bearer ',
    ) {
    }

    public function headers(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        return [$this->header => $this->prefix . $this->setting($this->keyField)];
    }

    public function isConfigured(): bool
    {
        return (string)$this->setting($this->keyField) !== '';
    }
}
