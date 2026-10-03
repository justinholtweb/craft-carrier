<?php

namespace justinholtweb\carrier\base;

/**
 * A transport-level answer, deliberately dumber than a PSR-7 response so a recorded fixture is
 * just as good as a live one.
 */
class Response
{
    public ?int $durationMs = null;

    public ?int $attempt = null;

    private ?array $decoded = null;

    public function __construct(
        public int $status = 0,
        public array $headers = [],
        public string $body = '',
        /** Set when the request never produced an HTTP status at all. */
        public ?string $error = null,
    ) {
    }

    /**
     * Replace the body, discarding anything already parsed from the old one.
     *
     * Used by the transport to redact credentials out of failure bodies before a connector — or
     * a merchant's health check — ever sees them.
     */
    public function setBody(string $body): void
    {
        $this->body = $body;
        $this->decoded = null;
    }

    public static function json(int $status, mixed $data, array $headers = []): self
    {
        return new self(
            status: $status,
            headers: array_merge(['Content-Type' => ['application/json']], $headers),
            body: (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    public static function xml(int $status, string $xml, array $headers = []): self
    {
        return new self(
            status: $status,
            headers: array_merge(['Content-Type' => ['application/xml']], $headers),
            body: $xml,
        );
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function unauthorized(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    /** The parsed JSON body, memoized. Returns an empty array for anything that is not JSON. */
    public function json_(): array
    {
        if ($this->decoded === null) {
            $decoded = json_decode($this->body, true);
            $this->decoded = is_array($decoded) ? $decoded : [];
        }

        return $this->decoded;
    }

    /** A value out of the JSON body by dotted path, without four levels of `??`. */
    public function at(string $path, mixed $default = null): mixed
    {
        $value = $this->json_();

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * The body as XML with every namespace prefix stripped, or null when it is not XML.
     *
     * SOAP carriers (ELTA, R+L) wrap their answers in two or three namespaces nobody needs to
     * address individually; stripping them lets a carrier write `$xml->Body->Result` instead of
     * registering prefixes for every call.
     */
    public function xmlBody(): ?\SimpleXMLElement
    {
        if ($this->body === '' || !str_contains($this->body, '<')) {
            return null;
        }

        $clean = preg_replace('/(<\/?)[\w\-]+:([\w\-]+)/', '$1$2', $this->body);
        $clean = preg_replace('/\s+xmlns(:[\w\-]+)?="[^"]*"/', '', (string)$clean);
        $clean = preg_replace('/\s[\w\-]+:([\w\-]+=)/', ' $1', (string)$clean);

        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: a carrier response has no business making this server fetch anything.
        $xml = simplexml_load_string((string)$clean, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return is_array($value) ? (string)reset($value) : (string)$value;
            }
        }

        return null;
    }

    /**
     * The most useful sentence available about why this failed, preferring whatever the carrier said
     * over anything Carrier could invent. Every vendor nests its message differently; this walks the
     * shapes they actually use.
     */
    public function errorMessage(): string
    {
        if ($this->error !== null) {
            return $this->error;
        }

        $json = $this->json_();

        foreach ([
            'error.message',
            'error.message.value',
            'error.error_description',
            'error_description',
            'message',
            'Message',
            'exceptionMessage',
            'detail',
            'title',
            'fault.faultstring',
        ] as $path) {
            $value = $this->at($path);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        // UPS nests its list under `response`; FedEx, USPS and most others put it at the top.
        $errors = $this->at('response.errors') ?? $this->at('errors') ?? $this->at('Errors');

        if (is_array($errors) && $errors !== []) {
            $first = reset($errors);

            if (is_array($first)) {
                foreach (['detail', 'message', 'Message', 'errorMessage', 'ErrorDescription', 'description'] as $key) {
                    if (!empty($first[$key])) {
                        return (string)$first[$key];
                    }
                }
            } elseif (is_string($first)) {
                return $first;
            }
        }

        if ($this->body !== '') {
            return 'HTTP ' . $this->status . ': ' . mb_substr(strip_tags($this->body), 0, 300);
        }

        return 'HTTP ' . $this->status;
    }
}
