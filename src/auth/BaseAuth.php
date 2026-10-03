<?php

namespace justinholtweb\carrier\auth;

use Craft;
use justinholtweb\carrier\base\AuthInterface;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\Plugin;

/**
 * Shared plumbing for the authentication strategies.
 *
 * The split that matters: short-lived credentials (access tokens, session cookies) live in
 * Craft's cache, long-lived ones (refresh tokens) live on the connection row. Putting an access
 * token in the database means a write on every refresh; putting a refresh token in the cache
 * means a cleared cache logs the merchant out of their carrier.
 */
abstract class BaseAuth implements AuthInterface
{
    protected Connection $connection;

    private ?Transport $http = null;

    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * The client a strategy uses for its own token traffic.
     *
     * It is a full Transport rather than a bare Guzzle call for two reasons: token requests are
     * exactly the ones a merchant most needs to see in the log, and a test double that could not
     * intercept the token exchange would only be testing half of an OAuth connector.
     */
    public function setTransport(Transport $transport): void
    {
        $this->http = $transport;
    }

    protected function http(): Transport
    {
        if ($this->http === null) {
            $this->http = (new Transport())->setConnection($this->connection);
        }

        return $this->http;
    }

    public function query(): array
    {
        return [];
    }

    public function reauthenticate(): bool
    {
        return false;
    }

    protected function setting(string $name, mixed $default = null): mixed
    {
        return $this->connection->getSetting($name, $default);
    }

    protected function cacheKey(string $suffix): string
    {
        return 'carrier:' . ($this->connection->uid ?: $this->connection->handle) . ':' . static::class . ':' . $suffix;
    }

    protected function cacheGet(string $suffix): mixed
    {
        $value = Craft::$app->getCache()->get($this->cacheKey($suffix));

        return $value === false ? null : $value;
    }

    protected function cacheSet(string $suffix, mixed $value, int $ttl): void
    {
        Craft::$app->getCache()->set($this->cacheKey($suffix), $value, max(1, $ttl));
    }

    protected function cacheForget(string $suffix): void
    {
        Craft::$app->getCache()->delete($this->cacheKey($suffix));
    }

    /** A long-lived credential off the connection row. */
    protected function token(string $name): ?string
    {
        $value = $this->connection->tokens[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function storeTokens(array $tokens): void
    {
        $this->connection->tokens = array_merge($this->connection->tokens, $tokens);
        Plugin::getInstance()->getConnections()->saveTokens($this->connection);
    }
}
