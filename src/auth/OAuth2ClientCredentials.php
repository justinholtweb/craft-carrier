<?php

namespace justinholtweb\carrier\auth;

use Craft;
use justinholtweb\carrier\models\Connection;

/**
 * OAuth 2.0 client credentials — the service-to-service grant.
 *
 * The shape UPS, FedEx, USPS, BOX NOW and most of the freight carriers use: no user sits in
 * front of a label purchase, so there is nobody to bounce through a consent screen.
 *
 * Access tokens are cached, never stored, and re-fetched a minute before they expire so a long
 * run does not die three pages from the end.
 */
class OAuth2ClientCredentials extends BaseAuth
{
    /** Refresh this many seconds before the token actually expires. */
    private const EXPIRY_SLACK = 60;

    /**
     * @param string|callable $tokenUrl a URL, or a closure given the Connection
     * @param string|callable|null $scope a scope string, or a closure given the Connection
     * @param array $extraParams anything else the vendor's token endpoint insists on
     */
    public function __construct(
        private mixed $tokenUrl,
        private mixed $scope = null,
        private string $clientIdField = 'clientId',
        private string $clientSecretField = 'clientSecret',
        private array $extraParams = [],
        private bool $sendCredentialsInBody = true,
        /** Extra headers for the token request only, e.g. UPS's `x-merchant-id`. */
        private array $tokenHeaders = [],
    ) {
    }

    /** Why the last token request failed, in the carrier's words — for the connection test. */
    private ?string $lastError = null;

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function headers(): array
    {
        $token = $this->accessToken();

        return $token !== null ? ['Authorization' => 'Bearer ' . $token] : [];
    }

    public function isConfigured(): bool
    {
        return (string)$this->setting($this->clientIdField) !== ''
            && (string)$this->setting($this->clientSecretField) !== '';
    }

    /**
     * A 401 on a client-credentials grant means the cached token went stale early — a tenant
     * revoked it, or the clock drifted. Drop it and let the next call mint a new one.
     */
    public function reauthenticate(): bool
    {
        $this->cacheForget($this->tokenKey());

        return $this->accessToken() !== null;
    }

    public function accessToken(): ?string
    {
        $cached = $this->cacheGet($this->tokenKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!$this->isConfigured()) {
            return null;
        }

        $params = array_merge([
            'grant_type' => 'client_credentials',
        ], $this->extraParams);

        $scope = $this->resolve($this->scope);

        if ($scope) {
            $params['scope'] = $scope;
        }

        $options = ['form' => $params];

        if ($this->sendCredentialsInBody) {
            $options['form']['client_id'] = (string)$this->setting($this->clientIdField);
            $options['form']['client_secret'] = (string)$this->setting($this->clientSecretField);
        } else {
            $options['headers'] = [
                'Authorization' => 'Basic ' . base64_encode(
                    $this->setting($this->clientIdField) . ':' . $this->setting($this->clientSecretField),
                ),
            ];
        }

        if ($this->tokenHeaders !== []) {
            $options['headers'] = array_merge($options['headers'] ?? [], array_map(
                fn($v) => is_callable($v) ? (string)$v($this->connection) : (string)$v,
                $this->tokenHeaders,
            ));
        }

        $response = $this->http()->request('POST', (string)$this->resolve($this->tokenUrl), $options);

        if (!$response->ok()) {
            $this->lastError = $response->errorMessage();
            Craft::warning('Carrier could not get an access token: ' . $this->lastError, 'carrier');

            return null;
        }

        $this->lastError = null;

        $token = (string)$response->at('access_token', '');

        if ($token === '') {
            return null;
        }

        $expiresIn = (int)$response->at('expires_in', 3600);
        $this->cacheSet($this->tokenKey(), $token, max(30, $expiresIn - self::EXPIRY_SLACK));

        return $token;
    }

    /**
     * The cache slot for the access token. It includes the token URL and client id, so switching a
     * connection from sandbox to production, or rotating its keys, never reuses the old token.
     */
    private function tokenKey(): string
    {
        return 'access:' . md5((string)$this->resolve($this->tokenUrl) . '|' . (string)$this->setting($this->clientIdField));
    }

    private function resolve(mixed $value): ?string
    {
        if (is_callable($value)) {
            return (string)$value($this->connection);
        }

        return $value === null ? null : (string)$value;
    }
}
