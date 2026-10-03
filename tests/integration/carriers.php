<?php
/**
 * Carrier conformance suite (a TCK).
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-carrier/tests/integration/carriers.php [handle ...]
 *
 * Every carrier installed on this site is driven through the same checks, against a recorded
 * transport rather than a live account. Be plain about what that proves: the *request* a carrier
 * builds and the *contract* it honours. It cannot prove a vendor spells a field the way the
 * carrier expects — only a live account can.
 *
 * What it does catch is the class of bug that ships:
 * - advertising a feature it never implemented, or implementing one it forgot to declare;
 * - credentials that never reach the request;
 * - a refusal marked retryable, so the queue hammers the carrier;
 * - a 5xx or a timeout after sending reported as a clean refusal — which would let a retry buy
 *   a second label — or as a success;
 * - a credential echoed back to the merchant through an error message;
 * - "success" with no tracking number, or a label whose bytes are not the format it claims;
 * - tracking codes mapped outside the normalised vocabulary.
 *
 * Each add-on may ship `tests/fixtures.php` returning `['handle' => callable]` — a double that
 * answers with the vendor's documented sample responses — or `['handle' => ['double' => callable,
 * 'settings' => [...]]]` to switch on an opt-in setting for the happy path. Carriers with a
 * fixture also get the happy path: rates priced, labels bought, tracking mapped, points listed, a
 * label voided.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Parcel;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\Plugin;

$passed = 0;
$failed = 0;
$only = array_slice($argv, 1);

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

/**
 * Answers every request one way and remembers what it was asked. Token endpoints always get a
 * token, so OAuth carriers reach their real request.
 */
final class Recorder
{
    public array $requests = [];

    public function __construct(public string $mode = 'empty', public string $echo = '')
    {
    }

    public function __invoke(string $method, string $url, array $options): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        if (preg_match('#oauth|/token|auth-sessions|/auth\b|authentication|login#i', $url)) {
            return Response::json(200, ['access_token' => 'tck-token', 'token' => 'tck-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
        }

        return match ($this->mode) {
            'refuse' => Response::json(400, ['message' => 'Invalid request for account ' . $this->echo, 'errors' => [['message' => 'Bad postcode; key ' . $this->echo]]]),
            'throttle' => new Response(429, ['Retry-After' => ['1']], '{"message":"Too many requests"}'),
            'down' => Response::json(503, ['message' => 'Service unavailable']),
            'timeout' => new Response(0, [], '', 'Network: timed out'),
            default => Response::json(200, []),
        };
    }

    public function haystack(): string
    {
        return json_encode($this->requests, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

function sampleRequest(Carrier $carrier, bool $international = false): ShipmentRequest
{
    $class = get_class($carrier);
    $origins = $class::capabilities()->getOriginCountries();
    $from = $origins[0] ?? 'US';
    $samples = [
        'US' => ['Charlotte', '28202', 'NC', '100 N Tryon St', '', '+17045551234'],
        'HR' => ['Zagreb', '10000', '', 'Ilica', '242', '+385912345678'],
        'SI' => ['Ljubljana', '1000', '', 'Slovenska cesta', '1', '+38641234567'],
        'HU' => ['Budapest', '1051', '', 'Nádor utca', '12', '+36301234567'],
        'GR' => ['Athens', '10552', '', 'Aiolou', '10', '+306912345678'],
        'CZ' => ['Praha', '11000', '', 'Na Příkopě', '5', '+420601234567'],
        'RO' => ['Bucuresti', '010011', '', 'Strada Lipscani', '3', '+40712345678'],
        'SK' => ['Bratislava', '81101', '', 'Obchodná', '7', '+421901234567'],
        'RS' => ['Beograd', '11000', '', 'Knez Mihailova', '9', '+381601234567'],
        'BG' => ['Sofia', '1000', '', 'Vitosha', '4', '+359881234567'],
        'CY' => ['Nicosia', '1010', '', 'Ledras', '2', '+35799123456'],
    ];
    $to = $international ? ($from === 'US' ? 'CA' : 'DE') : $from;
    $s = $samples[$from] ?? $samples['US'];
    $t = $samples[$to] ?? ['Berlin', '10115', '', 'Unter den Linden', '1', '+4930123456'];
    $services = array_keys($class::services());

    return new ShipmentRequest([
        'shipFrom' => new Address(['name' => 'Ship Room', 'company' => 'TCK Store', 'street1' => $s[3], 'houseNumber' => $s[4], 'city' => $s[0], 'postcode' => $s[1], 'region' => $s[2], 'countryCode' => $from, 'phone' => $s[5], 'email' => 'ship@example.com', 'residential' => false]),
        'shipTo' => new Address(['name' => 'Ana Kovač', 'street1' => $t[3], 'houseNumber' => $t[4], 'city' => $t[0], 'postcode' => $t[1], 'region' => $t[2], 'countryCode' => $to, 'phone' => $t[5], 'email' => 'ana@example.com']),
        'parcels' => [
            new Parcel(['weightG' => 1800, 'lengthMm' => 300, 'widthMm' => 200, 'heightMm' => 150, 'value' => 40, 'reference' => '1', 'freightClass' => '70', 'packagingType' => 'pallet', 'pieces' => 1]),
            new Parcel(['weightG' => 700, 'lengthMm' => 200, 'widthMm' => 150, 'heightMm' => 100, 'value' => 20, 'reference' => '2', 'freightClass' => '70', 'packagingType' => 'pallet', 'pieces' => 1]),
        ],
        'serviceCode' => $services[0] ?? '',
        'reference' => 'TCK-1001',
        'reference2' => '1001',
        'currency' => $from === 'US' ? 'USD' : ($from === 'HU' ? 'HUF' : 'EUR'),
        'declaredValue' => 60,
        'labelFormat' => $class::capabilities()->getLabelFormats()[0] ?? '',
        'shipDate' => new DateTime('tomorrow'),
    ]);
}

/**
 * A connection with every field filled — secrets with long recognisable values, so we can look for
 * them in requests and in anything shown back to a merchant.
 */
function tckConnection(string $class, array $overrides = []): Connection
{
    $settings = [];

    foreach ($class::settingsFields() as $field) {
        $name = $field['name'] ?? '';

        if ($name === '' || ($field['type'] ?? '') === 'copyable' || ($field['type'] ?? '') === 'heading') {
            continue;
        }

        $settings[$name] = match (true) {
            $name === 'environment' => 'sandbox',
            (bool)($field['secret'] ?? false) => 'tcksecret-' . $name . '-7Hq2',
            ($field['type'] ?? '') === 'select' => (string)($field['default'] ?? array_key_first($field['options'])),
            ($field['type'] ?? '') === 'boolean' => (bool)($field['default'] ?? false),
            ($field['type'] ?? '') === 'number' => $field['default'] ?: 1,
            default => ($field['default'] ?? '') !== '' ? $field['default'] : 'tck' . $name,
        };
    }

    $settings = array_merge($settings, $overrides);

    $connection = new Connection([
        'name' => 'TCK ' . $class::displayName(),
        'handle' => 'tck' . preg_replace('/[^a-z0-9]/i', '', $class::handle()),
        'carrier' => $class::handle(),
        'enabled' => true,
        'settings' => $settings,
    ]);

    $existing = Plugin::getInstance()->getConnections()->getByHandle($connection->handle);

    if ($existing) {
        Plugin::getInstance()->getConnections()->delete($existing);
    }

    Plugin::getInstance()->getConnections()->save($connection, false);

    return $connection;
}

function bound(string $class, Connection $connection, callable $double): Carrier
{
    /** @var Carrier $carrier */
    $carrier = new $class();
    $carrier->setConnection($connection);
    $carrier->useDouble($double);

    return $carrier;
}

function secretsOf(string $class): array
{
    $values = [];

    foreach (Field::secretNames($class::settingsFields()) as $name) {
        $values[] = 'tcksecret-' . $name . '-7Hq2';
    }

    return $values;
}

function leaks(string $text, array $secrets): ?string
{
    foreach ($secrets as $secret) {
        if (str_contains($text, $secret)) {
            return $secret;
        }
    }

    return null;
}

// =====================================================================================

$fixtures = [];

foreach (glob('/var/www/craft-carrier-*/tests/fixtures.php') ?: [] as $file) {
    $loaded = require $file;

    if (is_array($loaded)) {
        $fixtures += $loaded;
    }
}

$carriers = Plugin::getInstance()->getCarriers()->all();
$tested = 0;

foreach ($carriers as $handle => $class) {
    if ($only !== [] && !in_array($handle, $only, true)) {
        continue;
    }

    $tested++;
    $caps = $class::capabilities();
    echo "\n$handle — " . $class::displayName() . "\n";

    // --- Shape -------------------------------------------------------------------------------

    check('handle is stable kebab-case', fn() => (bool)preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $handle) ?: $handle);
    check('names itself, its region and what it is for', fn() => $class::displayName() !== '' && $class::region() !== '' && $class::description() !== '');
    check('settings fields are well formed', function() use ($class) {
        foreach ($class::settingsFields() as $field) {
            if (!isset($field['type'], $field['name'], $field['label'])) {
                return 'malformed: ' . json_encode($field);
            }

            if (preg_match('/secret|password|key|token/i', $field['name']) && !($field['secret'] ?? false) && !in_array($field['type'], ['select', 'boolean', 'heading', 'copyable'], true) && !preg_match('/^(accountNumber|apiKeyId|keyId|publicKey)$/', $field['name'])) {
                return "“{$field['name']}” looks like a credential but is not marked secret";
            }
        }

        return true;
    });
    check('services have names', function() use ($class) {
        foreach ($class::services() as $code => $service) {
            if (!is_string($code) && !is_int($code) || empty($service['name'])) {
                return 'bad service ' . json_encode([$code => $service]);
            }
        }

        return true;
    });
    check('label formats declared when labels are', fn() => !$caps->has(Feature::LABELS) || $caps->getLabelFormats() !== [] ?: 'no label formats');
    check('tracking URL, if any, is https and contains the number', function() use ($class, $caps) {
        $url = $class::trackingUrl('1Z999TCK0001');

        return $url === null || (str_starts_with($url, 'https://') && str_contains($url, '1Z999TCK0001')) ?: (string)$url;
    });

    // --- Declared ⇔ implemented ---------------------------------------------------------------

    foreach (Feature::METHODS as $feature => $method) {
        $declaredIn = (new ReflectionMethod($class, $method))->getDeclaringClass()->getName();
        $implemented = $declaredIn !== Carrier::class;

        if ($caps->has($feature) || $implemented) {
            check("declares $feature ⇔ implements $method()", fn() => $caps->has($feature) === $implemented
                ?: ($implemented ? "implements $method() but does not declare $feature" : "declares $feature but never implements $method()"));
        }
    }

    if (in_array($handle, ['mock', 'store-pickup'], true)) {
        continue;
    }

    $fixture = $fixtures[$handle] ?? null;
    $overrides = is_array($fixture) ? (array)($fixture['settings'] ?? []) : [];
    $fixture = is_array($fixture) ? ($fixture['double'] ?? null) : $fixture;
    $connection = tckConnection($class, $overrides);
    $secrets = secretsOf($class);
    $configured = array_values(array_filter(array_map(
        static fn($v) => is_string($v) && strlen($v) >= 4 ? $v : null,
        $connection->settings,
    )));

    // --- Credentials reach the wire -----------------------------------------------------------

    $probeRecorder = new Recorder();
    $probe = bound($class, $connection, $probeRecorder);

    check('the connection test makes a request and does not throw', function() use ($probe, $probeRecorder) {
        $result = $probe->test();

        return $probeRecorder->requests !== [] ?: 'test() made no request: ' . $result->message;
    });

    check('credentials reach the request', function() use ($probeRecorder, $configured) {
        $hay = $probeRecorder->haystack();

        if (str_contains($hay, 'tck-token')) {
            return true;
        }

        foreach ($configured as $value) {
            if (str_contains($hay, $value) || str_contains($hay, base64_encode($value)) || str_contains($hay, json_encode($value))) {
                return true;
            }
        }

        // Basic auth carries username:password base64'd together.
        foreach ($probeRecorder->requests as $request) {
            foreach ((array)($request['options']['headers'] ?? []) as $name => $header) {
                if (stripos((string)$name, 'authorization') !== false || preg_match('/key|token|user|pass/i', (string)$name)) {
                    return true;
                }
            }
        }

        return 'no configured credential or token found in any request';
    });

    // --- Refusals, throttles and silence ------------------------------------------------------

    if ($caps->has(Feature::LABELS)) {
        $request = sampleRequest($probe);

        if ($caps->has(Feature::POINTS) && ($class::services()[$request->serviceCode]['points'] ?? false)) {
            $request->pointId = 'TCK-POINT-1';
        }

        $refuse = new Recorder('refuse', $secrets[0] ?? 'nothing-secret');
        $result = bound($class, $connection, $refuse)->createShipment(clone $request);

        check('a 4xx is a refusal: not success, not retryable, not uncertain', fn() => !$result->success && !$result->retryable && !$result->uncertain
            ?: sprintf('success=%s retryable=%s uncertain=%s: %s', var_export($result->success, true), var_export($result->retryable, true), var_export($result->uncertain, true), $result->message));
        check('a refusal does not echo a credential', fn() => leaks((string)$result->message, $secrets) === null ?: 'leaked ' . leaks((string)$result->message, $secrets));
        check('a refusal says something', fn() => trim((string)$result->message) !== '' ?: 'empty message');

        $down = bound($class, $connection, new Recorder('down'))->createShipment(clone $request);
        check('a 503 after sending is uncertain — never a refusal that a retry could rebuy', fn() => !$down->success && $down->uncertain
            ?: sprintf('success=%s uncertain=%s retryable=%s', var_export($down->success, true), var_export($down->uncertain, true), var_export($down->retryable, true)));

        $timeout = bound($class, $connection, new Recorder('timeout'))->createShipment(clone $request);
        check('no answer at all is uncertain', fn() => !$timeout->success && $timeout->uncertain ?: 'uncertain=' . var_export($timeout->uncertain, true));

        $throttle = bound($class, $connection, new Recorder('throttle'))->createShipment(clone $request);
        check('a 429 is retryable and not uncertain', fn() => !$throttle->success && $throttle->retryable && !$throttle->uncertain
            ?: sprintf('retryable=%s uncertain=%s', var_export($throttle->retryable, true), var_export($throttle->uncertain, true)));

        $empty = bound($class, $connection, new Recorder('empty'))->createShipment(clone $request);
        check('an empty 200 is never a success', fn() => !$empty->success ?: 'reported success with ' . json_encode($empty->trackingNumbers));
    }

    if ($caps->has(Feature::RATES)) {
        check('rates survive an empty answer', function() use ($class, $connection, $probe) {
            $result = bound($class, $connection, new Recorder('empty'))->rate(sampleRequest($probe));

            return $result->rates === [] || !$result->successful() ?: 'invented rates from nothing';
        });
        check('rates report a 4xx as an error without the credential', function() use ($class, $connection, $probe, $secrets) {
            $result = bound($class, $connection, new Recorder('refuse', $secrets[0] ?? 'x'))->rate(sampleRequest($probe));

            return (!$result->successful() && leaks((string)$result->error, $secrets) === null) ?: 'error=' . var_export($result->error, true);
        });
    }

    if ($caps->has(Feature::TRACKING)) {
        check('tracking survives an empty answer', function() use ($class, $connection) {
            $infos = bound($class, $connection, new Recorder('empty'))->track(['TCK0000001']);

            foreach ($infos as $info) {
                if (!$info->notFound && !TrackingStatus::isValid($info->status)) {
                    return 'invalid status ' . $info->status;
                }
            }

            return true;
        });
        check('tracking survives the carrier being down', fn() => is_array(bound($class, $connection, new Recorder('down'))->track(['TCK0000001'])));
    }

    if ($caps->has(Feature::POINTS) && $caps->getPointsMode() === \justinholtweb\carrier\base\Capabilities::POINTS_MANUAL) {
        check('manual points never call the carrier', function() use ($class, $connection) {
            $recorder = new Recorder('empty');
            $page = bound($class, $connection, $recorder)->pickupPoints(new PointQuery(['countryCode' => 'HR']));

            return $page->points === [] && $recorder->requests === [] ?: 'made ' . count($recorder->requests) . ' requests';
        });
    } elseif ($caps->has(Feature::POINTS)) {
        check('points survive an empty answer', function() use ($class, $connection, $probe) {
            $page = bound($class, $connection, new Recorder('empty'))->pickupPoints(new PointQuery(['countryCode' => sampleRequest($probe)->shipTo->countryCode, 'postcode' => sampleRequest($probe)->shipTo->postcode]));

            return $page->points === [] && $page->nextCursor === null ?: 'invented points or a cursor from nothing';
        });
    }

    if ($caps->has(Feature::CANCEL)) {
        check('a refused void is a failure without the credential', function() use ($class, $connection, $secrets) {
            $result = bound($class, $connection, new Recorder('refuse', $secrets[0] ?? 'x'))->cancelShipment(new ShipmentRef(['shipmentId' => 'TCK1', 'trackingNumbers' => ['TCK1']]));

            return (!$result->success && leaks((string)$result->message, $secrets) === null) ?: 'success=' . var_export($result->success, true) . ' ' . $result->message;
        });
    }

    // --- The happy path, from the vendor's own sample responses --------------------------------

    if ($fixture === null) {
        echo "  · no recorded fixture: happy path not exercised\n";
    } else {
        $live = bound($class, $connection, $fixture);
        $request = sampleRequest($live);

        if ($caps->has(Feature::POINTS) && ($class::services()[$request->serviceCode]['points'] ?? false)) {
            $request->pointId = 'TCK-POINT-1';
        }

        if ($caps->has(Feature::RATES)) {
            check('rates: prices at least one service', function() use ($live, $request) {
                $result = $live->rate(clone $request);

                if (!$result->successful()) {
                    return 'error: ' . $result->error;
                }

                foreach ($result->rates as $rate) {
                    if ($rate->amount <= 0 || $rate->serviceCode === '' || strlen($rate->currency) !== 3) {
                        return 'bad rate ' . json_encode($rate->toArray());
                    }
                }

                return $result->rates !== [] ?: 'no rates';
            });
        }

        if ($caps->has(Feature::LABELS)) {
            $created = null;

            check('labels: a shipment is created with tracking numbers and real label bytes', function() use ($live, $request, &$created, $caps) {
                $created = $live->createShipment(clone $request);

                if (!$created->success) {
                    return 'refused: ' . $created->message;
                }

                if ($created->trackingNumbers === [] || in_array('', $created->trackingNumbers, true)) {
                    return 'no tracking numbers';
                }

                if (!$caps->has(Feature::MULTI_PIECE) && count($created->trackingNumbers) !== 1) {
                    return 'single-piece carrier returned ' . count($created->trackingNumbers) . ' numbers';
                }

                if ($created->labels === []) {
                    return 'no labels';
                }

                foreach ($created->labels as $label) {
                    if (!in_array($label->format, LabelFormat::all(), true)) {
                        return 'unknown format ' . $label->format;
                    }

                    if (!LabelFormat::bytesMatch($label->format, $label->data)) {
                        return "label bytes are not {$label->format}: " . substr(bin2hex(substr($label->data, 0, 8)), 0, 16);
                    }
                }

                return true;
            });

            if ($caps->has(Feature::CANCEL) && $created?->success) {
                check('cancel: the new shipment voids', fn() => $live->cancelShipment($created->ref($request->serviceCode))->success ?: 'void refused');
            }

            if ($caps->has(Feature::REPRINT) && $created?->success) {
                check('reprint: labels come back in a declared format', function() use ($live, $created, $request, $caps) {
                    $result = $live->printLabels([$created->ref($request->serviceCode)], $caps->getLabelFormats()[0]);

                    if (!$result->successful() || $result->labels === []) {
                        return 'error: ' . $result->error;
                    }

                    return LabelFormat::bytesMatch($result->labels[0]->format, $result->labels[0]->data) ?: 'bad bytes';
                });
            }
        }

        if ($caps->has(Feature::TRACKING)) {
            check('tracking: statuses and events map into the vocabulary', function() use ($live) {
                $infos = $live->track(['TCK0000001']);

                if ($infos === []) {
                    return 'nothing tracked';
                }

                foreach ($infos as $info) {
                    if ($info->notFound) {
                        continue;
                    }

                    if (!TrackingStatus::isValid($info->status) || $info->status === TrackingStatus::UNKNOWN && $info->events !== []) {
                        return 'status ' . $info->status;
                    }

                    foreach ($info->events as $event) {
                        if (!TrackingStatus::isValid($event->status)) {
                            return 'event status ' . $event->status;
                        }
                    }
                }

                return true;
            });
        }

        // Manual-mode points come from the merchant's CSV, never the carrier: nothing to fetch.
        if ($caps->has(Feature::POINTS) && $caps->getPointsMode() !== \justinholtweb\carrier\base\Capabilities::POINTS_MANUAL) {
            check('points: come back with ids, countries and types', function() use ($live, $request) {
                $page = $live->pickupPoints(new PointQuery(['countryCode' => $request->shipTo->countryCode, 'postcode' => $request->shipTo->postcode, 'latitude' => 45.81, 'longitude' => 15.98]));

                if ($page->error !== null) {
                    return 'error: ' . $page->error;
                }

                foreach ($page->points as $point) {
                    if ($point->id === '' || strlen($point->countryCode) !== 2 || $point->name === '') {
                        return 'bad point ' . json_encode($point->toArray());
                    }
                }

                return $page->points !== [] ?: 'no points';
            });
        }

        if ($caps->has(Feature::PICKUPS)) {
            check('pickups: a collection is booked', fn() => $live->requestPickup(new \justinholtweb\carrier\models\canonical\PickupRequest([
                'address' => $request->shipFrom,
                'date' => new DateTime('tomorrow'),
                'parcelCount' => 2,
                'totalWeightG' => 2500,
            ]))->success ?: 'refused');
        }
    }

    // --- Nothing secret was logged -------------------------------------------------------------

    check('the connection log never holds a credential', function() use ($connection, $secrets) {
        $rows = (new craft\db\Query())->from('{{%carrier_log}}')->where(['connectionId' => $connection->id])->all();
        $leak = leaks(json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secrets);

        return $leak === null ?: 'log contains ' . $leak;
    });

    Plugin::getInstance()->getConnections()->delete($connection);
}

echo "\n$tested carriers, $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
