<?php

namespace justinholtweb\carrier\auth;

/**
 * HTTP Basic. Priority, several Sage products and every on-premise box ever shipped.
 */
class BasicAuth extends BaseAuth
{
    public function __construct(
        private string $usernameField = 'username',
        private string $passwordField = 'password',
    ) {
    }

    public function headers(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $credentials = base64_encode($this->setting($this->usernameField) . ':' . $this->setting($this->passwordField));

        return ['Authorization' => 'Basic ' . $credentials];
    }

    public function isConfigured(): bool
    {
        return (string)$this->setting($this->usernameField) !== ''
            && (string)$this->setting($this->passwordField) !== '';
    }
}
