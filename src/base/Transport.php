<?php

namespace justinholtweb\carrier\base;

use Craft;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\Plugin;
use Throwable;

/**
 * Every byte Carrier exchanges with a carrier goes through here.
 *
 * That is deliberate and it is what makes the log trustworthy: there is no second path a
 * carrier could take that would miss being recorded, redacted, retried or throttled. It is also
 * what makes sixteen carriers without live accounts testable — swap in a recorded transport with
 * `setDouble()` and the carrier cannot tell the difference.
 */
class Transport
{
    /** Statuses worth another attempt: throttling, and the server having a bad moment. */
    private const RETRY_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    private ?Connection $connection = null;

    private ?AuthInterface $auth = null;

    private string $baseUri = '';

    private array $defaultHeaders = [];

    private int $timeout = 30;

    private int $maxAttempts = 4;

    /**
     * When true, only answers that prove the carrier did nothing are retried — a 429. Set while a
     * label is being bought: a timeout or a 502 after the request left this server may mean the
     * carrier created the label anyway, and buying it twice costs the merchant real money.
     */
    private bool $safeRetriesOnly = false;

    /** Requests per second the carrier will tolerate. 0 disables throttling. */
    private float $rateLimit = 0;

    private ?float $lastRequestAt = null;

    /** How many requests have actually been attempted — so the engine can tell "failed before
     *  sending anything" from "failed after the carrier may have acted". */
    private int $requestCount = 0;

    /** @var callable|null a stand-in that answers requests without touching the network */
    private $double = null;

    /** The shipment a request belongs to, so the shipment screen can show its own traffic. */
    private ?int $shipmentId = null;

    /** Redacted before anything is written to the log. */
    private array $secretValues = [];

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function setConnection(Connection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function setAuth(?AuthInterface $auth): self
    {
        $this->auth = $auth;

        return $this;
    }

    public function setBaseUri(string $uri): self
    {
        $this->baseUri = rtrim($uri, '/');

        return $this;
    }

    public function setDefaultHeaders(array $headers): self
    {
        $this->defaultHeaders = $headers;

        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = max(1, $seconds);

        return $this;
    }

    public function setMaxAttempts(int $attempts): self
    {
        $this->maxAttempts = max(1, $attempts);

        return $this;
    }

    public function setSafeRetriesOnly(bool $value): self
    {
        $this->safeRetriesOnly = $value;

        return $this;
    }

    public function setRateLimit(float $requestsPerSecond): self
    {
        $this->rateLimit = max(0, $requestsPerSecond);

        return $this;
    }

    public function setShipmentId(?int $shipmentId): self
    {
        $this->shipmentId = $shipmentId;

        return $this;
    }

    public function setSecretValues(array $values): self
    {
        $this->secretValues = array_values(array_filter($values, fn($v) => is_string($v) && strlen($v) >= 6));

        return $this;
    }

    /**
     * Answer requests from a callable instead of the network.
     *
     * The callable receives `(string $method, string $uri, array $options)` and returns a
     * `Response`. This is how the connector suite runs: recorded payloads from each vendor's
     * documentation, replayed through the identical code path a live tenant would take.
     */
    public function setDouble(?callable $double): self
    {
        $this->double = $double;

        return $this;
    }

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    public function hasDouble(): bool
    {
        return $this->double !== null;
    }

    public function get(string $uri, array $query = [], array $options = []): Response
    {
        return $this->request('GET', $uri, array_merge($options, ['query' => $query]));
    }

    public function post(string $uri, mixed $body = null, array $options = []): Response
    {
        return $this->request('POST', $uri, array_merge($options, ['json' => $body]));
    }

    public function patch(string $uri, mixed $body = null, array $options = []): Response
    {
        return $this->request('PATCH', $uri, array_merge($options, ['json' => $body]));
    }

    public function put(string $uri, mixed $body = null, array $options = []): Response
    {
        return $this->request('PUT', $uri, array_merge($options, ['json' => $body]));
    }

    public function delete(string $uri, array $options = []): Response
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * The one method. Everything above is sugar.
     */
    public function request(string $method, string $uri, array $options = []): Response
    {
        $method = strtoupper($method);
        $url = $this->absoluteUri($uri);
        $attempt = 0;
        $reauthenticated = false;

        while (true) {
            $attempt++;
            $this->throttle();

            $options = $this->applyAuth($options, $method, $url);
            $startedAt = microtime(true);
            $this->requestCount++;

            try {
                $response = $this->send($method, $url, $options);
            } catch (Throwable $e) {
                $response = $this->responseFromThrowable($e);
            }

            $response->durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            $response->attempt = $attempt;

            // Several of these carriers echo the credential back inside their own error message, and
            // a connector's health check hands that message straight to the merchant. Redaction
            // happens here, on failure bodies only: a successful body is data a connector has to
            // parse, and an error body never is.
            if (!$response->ok()) {
                $response->setBody($this->redact($response->body));
            }

            $this->record($method, $url, $options, $response);

            // One shot at fixing an expired token, then treat 401 as final. Retrying a genuinely
            // wrong credential just gets the merchant's carrier account locked out.
            if ($response->status === 401 && !$reauthenticated && $this->auth?->reauthenticate()) {
                $reauthenticated = true;
                continue;
            }

            if (!$this->shouldRetry($response, $attempt)) {
                return $response;
            }

            $this->sleep($this->backoffSeconds($response, $attempt));
        }
    }

    private function send(string $method, string $url, array $options): Response
    {
        if ($this->double !== null) {
            $response = ($this->double)($method, $url, $options);

            if (!$response instanceof Response) {
                throw new \RuntimeException('A transport double must return an ' . Response::class . '.');
            }

            return $response;
        }

        $client = Craft::createGuzzleClient([
            'timeout' => $this->timeout,
            'connect_timeout' => min(10, $this->timeout),
            'http_errors' => false,
        ]);

        $guzzleOptions = ['headers' => $options['headers'] ?? []];

        if (!empty($options['query'])) {
            $guzzleOptions['query'] = $options['query'];
        }

        if (array_key_exists('json', $options) && $options['json'] !== null) {
            $guzzleOptions['json'] = $options['json'];
        } elseif (array_key_exists('body', $options) && $options['body'] !== null) {
            $guzzleOptions['body'] = $options['body'];
        } elseif (!empty($options['form'])) {
            $guzzleOptions['form_params'] = $options['form'];
        }

        if (!empty($options['multipart'])) {
            $guzzleOptions['multipart'] = $options['multipart'];
        }

        if (!empty($options['auth']) && is_array($options['auth'])) {
            $guzzleOptions['auth'] = $options['auth'];
        }

        $guzzleResponse = $client->request($method, $url, $guzzleOptions);

        return new Response(
            status: $guzzleResponse->getStatusCode(),
            headers: $guzzleResponse->getHeaders(),
            body: (string)$guzzleResponse->getBody(),
        );
    }

    private function responseFromThrowable(Throwable $e): Response
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $psr = $e->getResponse();

            return new Response(
                status: $psr->getStatusCode(),
                headers: $psr->getHeaders(),
                body: (string)$psr->getBody(),
            );
        }

        // A DNS failure or a timeout is not an HTTP status, but the engine only speaks statuses.
        // 0 means "no answer from the carrier", which is retried unless a label is being bought.
        return new Response(
            status: 0,
            headers: [],
            body: '',
            error: ($e instanceof TransferException ? 'Network: ' : '') . $e->getMessage(),
        );
    }

    private function applyAuth(array $options, string $method = 'GET', string $url = ''): array
    {
        $headers = array_merge($this->defaultHeaders, $options['headers'] ?? []);
        $query = $options['query'] ?? [];

        if (is_string($query)) {
            parse_str($query, $query);
        }

        // `'auth' => false` sends a request without credentials — a public feed on another host
        // must never receive the carrier's bearer token.
        if ($this->auth && ($options['auth'] ?? null) !== false) {
            $query = array_merge($this->auth->query(), is_array($query) ? $query : []);

            $authHeaders = $this->auth->headers();

            $headers = array_merge($authHeaders, $headers);
        }

        $options['headers'] = $headers;

        if ($query !== []) {
            $options['query'] = $query;
        }

        return $options;
    }

    private function shouldRetry(Response $response, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        if ($this->safeRetriesOnly) {
            return $response->status === 429;
        }

        return $response->status === 0 || in_array($response->status, self::RETRY_STATUSES, true);
    }

    /**
     * Honour `Retry-After` when the carrier sends one — several of these vendors mean it — and fall
     * back to exponential backoff with jitter so a fleet of sites does not stampede.
     */
    private function backoffSeconds(Response $response, int $attempt): float
    {
        $retryAfter = $response->header('Retry-After');

        if ($retryAfter !== null) {
            if (is_numeric($retryAfter)) {
                return min(60, (float)$retryAfter);
            }

            $timestamp = strtotime($retryAfter);

            if ($timestamp !== false) {
                return (float)max(0, min(60, $timestamp - time()));
            }
        }

        return min(30, (2 ** ($attempt - 1)) + (random_int(0, 1000) / 1000));
    }

    private function throttle(): void
    {
        if ($this->rateLimit <= 0) {
            return;
        }

        $minGap = 1 / $this->rateLimit;

        if ($this->lastRequestAt !== null) {
            $elapsed = microtime(true) - $this->lastRequestAt;

            if ($elapsed < $minGap) {
                $this->sleep($minGap - $elapsed);
            }
        }

        $this->lastRequestAt = microtime(true);
    }

    private function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        // Doubles are used by the test suite, which should not spend four seconds proving that
        // backoff works. The decision to wait is still exercised; only the waiting is skipped.
        if ($this->double !== null) {
            return;
        }

        usleep((int)round($seconds * 1_000_000));
    }

    private function absoluteUri(string $uri): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        return $this->baseUri . '/' . ltrim($uri, '/');
    }

    private function record(string $method, string $url, array $options, Response $response): void
    {
        // An unsaved connection (a test before the first save) has nowhere to file its log rows.
        if (!$this->connection || !$this->connection->id) {
            return;
        }

        try {
            Plugin::getInstance()->getLog()->request(
                connection: $this->connection,
                shipmentId: $this->shipmentId,
                method: $method,
                url: $this->redact($url),
                requestHeaders: $this->redactArray($options['headers'] ?? []),
                requestBody: $this->redact($this->bodyToString($options)),
                status: $response->status,
                responseBody: $this->redact($response->body),
                durationMs: $response->durationMs ?? 0,
                error: $response->error,
                attempt: $response->attempt ?? 1,
            );
        } catch (Throwable $e) {
            // A log write must never be the reason a sync fails.
            Craft::warning('Carrier could not record a request: ' . $e->getMessage(), 'carrier');
        }
    }

    private function bodyToString(array $options): string
    {
        if (array_key_exists('json', $options) && $options['json'] !== null) {
            return (string)json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (!empty($options['body'])) {
            return (string)$options['body'];
        }

        if (!empty($options['form'])) {
            return http_build_query($options['form']);
        }

        return '';
    }

    private function redact(string $value): string
    {
        if ($value === '' || $this->secretValues === []) {
            return $value;
        }

        return str_replace($this->secretValues, '••••••••', $value);
    }

    private function redactArray(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $flat = is_array($value) ? implode(', ', $value) : (string)$value;

            // Authorization, API keys and cookies never reach the log, whatever they contain.
            // `key` alone catches SAIA's RQ-Key and Azure's Ocp-Apim-Subscription-Key too.
            if (preg_match('/authorization|key|cookie|token|secret|password|signature/i', (string)$key)) {
                $out[$key] = '••••••••';
                continue;
            }

            $out[$key] = $this->redact($flat);
        }

        return $out;
    }
}
