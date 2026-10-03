<?php

namespace justinholtweb\carrier\base;

use Craft;
use craft\web\Request;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\PickupRequest;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\Plugin;
use Throwable;

/**
 * The base every carrier add-on extends.
 *
 * A carrier that extends this has three jobs and no others: declare what it can do, describe its
 * credentials, and translate between Carrier's canonical documents and one API. Everything a
 * carrier is *not* asked to do — packing, pricing rules, retries, throttling, logging, redaction,
 * label storage, the claim that stops a label being bought twice, tracking dedup, the checkout
 * picker — is why this plugin costs money and the add-ons do not.
 *
 * Every feature method below refuses by default. Override exactly the ones `capabilities()`
 * declares; the conformance suite checks both directions.
 */
abstract class Carrier implements CarrierInterface
{
    protected Connection $connection;

    private ?Transport $transport = null;

    private ?AuthInterface $auth = null;

    // =========================================================================
    // Description
    // =========================================================================

    public static function region(): string
    {
        return '';
    }

    public static function description(): string
    {
        return '';
    }

    public static function setupUrl(): ?string
    {
        return null;
    }

    public static function settingsFields(): array
    {
        return [];
    }

    public static function services(): array
    {
        return [];
    }

    public static function trackingUrl(string $trackingNumber): ?string
    {
        return null;
    }

    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
        $this->transport = null;
        $this->auth = null;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    // =========================================================================
    // Features — each refuses until a carrier overrides it
    // =========================================================================

    public function rate(ShipmentRequest $request): RateResult
    {
        return RateResult::failed($this->unsupported(Feature::RATES));
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        return ShipmentResult::rejected($this->unsupported(Feature::LABELS));
    }

    public function cancelShipment(ShipmentRef $shipment): ActionResult
    {
        return ActionResult::failed($this->unsupported(Feature::CANCEL));
    }

    public function printLabels(array $shipments, string $format): LabelResult
    {
        return LabelResult::failed($this->unsupported(Feature::REPRINT));
    }

    public function track(array $trackingNumbers): array
    {
        return [];
    }

    public function pickupPoints(PointQuery $query): PointPage
    {
        return PointPage::failed($this->unsupported(Feature::POINTS));
    }

    public function requestPickup(PickupRequest $request): ActionResult
    {
        return ActionResult::failed($this->unsupported(Feature::PICKUPS));
    }

    public function closeDay(array $shipments): ActionResult
    {
        return ActionResult::failed($this->unsupported(Feature::CLOSE_DAY));
    }

    public function validateAddress(Address $address): AddressResult
    {
        return new AddressResult(error: $this->unsupported(Feature::ADDRESS_VALIDATION));
    }

    public function handleWebhook(Request $request): array
    {
        throw new \BadMethodCallException($this->unsupported(Feature::WEBHOOKS));
    }

    private function unsupported(string $feature): string
    {
        return Craft::t('carrier', '{carrier} does not support {feature}.', [
            'carrier' => static::displayName(),
            'feature' => mb_strtolower(Feature::label($feature)),
        ]);
    }

    // =========================================================================
    // Health
    // =========================================================================

    /**
     * Prove the credentials work, and say so in words a merchant can act on.
     *
     * A carrier with something better to say — an account name, a sandbox warning — should
     * override `probe()`, because a merchant staring at a failed connection needs a noun, not a 401.
     */
    public function test(): HealthResult
    {
        $startedAt = microtime(true);
        $auth = $this->auth();

        $missing = array_filter(
            Field::requiredNames(static::settingsFields()),
            fn(string $name) => in_array($this->setting($name), [null, ''], true),
        );

        if (($auth !== null && !$auth->isConfigured()) || $missing !== []) {
            return HealthResult::fail(
                Craft::t('carrier', 'Credentials are incomplete.'),
                [Craft::t('carrier', 'Fill in every required field above, then save before testing.')],
            );
        }

        try {
            $result = $this->probe();
        } catch (Throwable $e) {
            $result = HealthResult::fail($e->getMessage());
        }

        // A token endpoint's refusal ("Blocked Merchant") is the most useful sentence there is.
        if (!$result->ok && $auth !== null && method_exists($auth, 'lastError') && $auth->lastError()) {
            $result->hints[] = Craft::t('carrier', 'The carrier refused the credentials: {error}', ['error' => $auth->lastError()]);
        }

        foreach ($result->hints as $key => $hint) {
            $result->hints[$key] = $this->redactSecrets((string)$hint);
        }

        $result->durationMs = (int)round((microtime(true) - $startedAt) * 1000);
        $result->message = $this->redactSecrets($result->message);

        foreach ($result->details as $key => $value) {
            $result->details[$key] = is_string($value) ? $this->redactSecrets($value) : $value;
        }

        if ($this->isSandbox() && $result->ok) {
            $result->hints[] = Craft::t('carrier', 'This connection uses the carrier’s test environment. Labels will not be valid for shipping.');
        }

        return $result;
    }

    /**
     * The cheapest request that proves the credentials work. Override in every carrier.
     */
    protected function probe(): HealthResult
    {
        return HealthResult::pass(Craft::t('carrier', 'Carrier loaded. It does not implement a connection test.'));
    }

    // =========================================================================
    // Plumbing
    // =========================================================================

    /**
     * The authentication strategy for this connection, built once.
     */
    public function auth(): ?AuthInterface
    {
        if ($this->auth === null) {
            $this->auth = $this->buildAuth();

            if ($this->auth !== null) {
                $this->auth->setConnection($this->connection);

                // The strategy gets its own transport: a token request cannot go through the
                // client that is waiting on it for a header.
                if (method_exists($this->auth, 'setTransport')) {
                    $this->auth->setTransport(
                        (new Transport())
                            ->setConnection($this->connection)
                            ->setSecretValues($this->secretValues()),
                    );
                }
            }
        }

        return $this->auth;
    }

    protected function buildAuth(): ?AuthInterface
    {
        return null;
    }

    /**
     * The HTTP client for this connection, built once per request so throttling and tokens
     * survive between calls.
     */
    public function transport(): Transport
    {
        if ($this->transport === null) {
            $this->transport = $this->buildTransport();
            $this->transport->setConnection($this->connection);
            $this->transport->setAuth($this->auth());
            $this->transport->setSecretValues($this->secretValues());
        }

        return $this->transport;
    }

    protected function buildTransport(): Transport
    {
        return new Transport();
    }

    /**
     * Replace the network with a callable. The engine never calls this; the test suites do.
     */
    public function useDouble(callable $double): void
    {
        $this->transport()->setDouble($double);

        $auth = $this->auth();

        if ($auth !== null && method_exists($auth, 'setTransport')) {
            $auth->setTransport(
                (new Transport())
                    ->setConnection($this->connection)
                    ->setSecretValues($this->secretValues())
                    ->setDouble($double),
            );
        }
    }

    /**
     * Strip any configured credential out of a string bound for a merchant's screen.
     */
    protected function redactSecrets(string $value): string
    {
        $secrets = $this->secretValues();

        return $secrets === [] ? $value : str_replace($secrets, '••••••••', $value);
    }

    /**
     * Every configured credential value, so the transport can keep them out of the log even when
     * a carrier helpfully echoes them back in an error message.
     */
    protected function secretValues(): array
    {
        $values = [];

        foreach (Field::secretNames(static::settingsFields()) as $name) {
            $value = $this->connection->getSetting($name);

            if (is_string($value) && strlen($value) >= 4) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * A connection setting, with `$ENV_VAR` already resolved.
     */
    protected function setting(string $name, mixed $default = null): mixed
    {
        return $this->connection->getSetting($name, $default);
    }

    protected function boolSetting(string $name, bool $default = false): bool
    {
        $value = $this->setting($name, $default);

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool)$value;
    }

    /**
     * Whether this connection points at the carrier's test environment. Every carrier that has
     * one uses the common `environment` field (see `Field::environment()`).
     */
    public function isSandbox(): bool
    {
        return $this->setting('environment', 'production') === 'sandbox';
    }

    /**
     * The label format a request should be produced in: what the request asked for if the
     * carrier can do it, else the connection's choice, else the carrier's first format.
     */
    protected function labelFormatFor(ShipmentRequest $request): string
    {
        $formats = static::capabilities()->getLabelFormats();

        foreach ([$request->labelFormat, $this->connection->labelFormat] as $candidate) {
            if ($candidate !== '' && in_array($candidate, $formats, true)) {
                return $candidate;
            }
        }

        return $formats[0] ?? LabelFormat::PDF_A6;
    }

    /**
     * Map a carrier status code onto the normalised vocabulary, case-insensitively.
     *
     * @param array<string,string> $map carrier code => TrackingStatus value
     */
    protected static function mapStatus(string $code, array $map, string $default = TrackingStatus::IN_TRANSIT): string
    {
        $needle = strtolower(trim($code));

        foreach ($map as $carrierCode => $status) {
            if (strtolower((string)$carrierCode) === $needle) {
                return $status;
            }
        }

        return $default;
    }

    /**
     * Parse a carrier's timestamp in the time zone it means. Several of these APIs send local
     * times without an offset; `$zone` is that carrier's home zone.
     */
    protected static function parseDate(?string $value, string $zone = 'UTC'): ?\DateTime
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Microsoft JSON dates: "/Date(1712345678000+0200)/" — MyGLS still sends these.
        if (preg_match('/\/Date\((-?\d+)([+-]\d{4})?\)\//', $value, $m)) {
            return (new \DateTime('@' . intdiv((int)$m[1], 1000)))->setTimezone(new \DateTimeZone('UTC'));
        }

        try {
            return new \DateTime($value, new \DateTimeZone($zone));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A short note in the connection log, for things a carrier knows and the engine cannot.
     */
    protected function note(string $message): void
    {
        Plugin::getInstance()->getLog()->note($this->connection, $message);
    }
}
