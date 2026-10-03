<?php

namespace justinholtweb\carrier\models;

use craft\base\Model;
use DateTime;

/**
 * One line of the connection log.
 */
class LogEntry extends Model
{
    public ?int $id = null;
    public ?int $connectionId = null;
    public ?int $shipmentId = null;
    public string $type = 'request';
    public ?string $method = null;
    public ?string $url = null;
    public ?string $requestHeaders = null;
    public ?string $requestBody = null;
    public ?int $status = null;
    public ?string $responseBody = null;
    public ?int $durationMs = null;
    public ?int $attempt = null;
    public ?string $message = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function isError(): bool
    {
        return $this->type === 'error' || ($this->status !== null && ($this->status === 0 || $this->status >= 400));
    }

    public function statusLabel(): string
    {
        if ($this->type !== 'request') {
            return ucfirst($this->type);
        }

        return $this->status === 0 ? 'No response' : (string)$this->status;
    }

    public function headers(): array
    {
        return json_decode((string)$this->requestHeaders, true) ?: [];
    }

    /**
     * The body pretty-printed when it is JSON, and left alone when it is not — XML and CSV bodies
     * are just as common in this corner of the world.
     */
    public function prettyBody(?string $body): string
    {
        $body = (string)$body;

        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $body;
    }
}
