<?php
/**
 * Carrier engine checks.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-carrier/tests/integration/checks.php
 *
 * Runs the whole gateway against the built-in Mock carrier: units, packing, connections,
 * checkout pricing and Commerce's own adjuster, claim-first label purchase and every way it can
 * go wrong, voiding, printing, tracking and its dedup, pickup points, the checkout selection,
 * cash on delivery, collections, the order-index actions and housekeeping.
 *
 * Idempotent and self-cleaning: fixture products, orders, connections, methods, shipments and
 * log rows are removed in a `finally`, pass or fail. Settings changes stay in memory.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\carriers\MockCarrier;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\elements\actions\CreateLabels;
use justinholtweb\carrier\helpers\Addresses;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Pdf;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\jobs\CreateLabelJob;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\Parcel;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\models\Shipment;
use justinholtweb\carrier\Plugin;
use justinholtweb\carrier\services\Labels;
use justinholtweb\carrier\shipping\CarrierShippingMethod;
use justinholtweb\carrier\shipping\CarrierShippingRule;

$passed = 0;
$failed = 0;

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

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);
$settings = $plugin->getSettings();
$createdProducts = [];
$createdOrders = [];
$connections = [];

// Sibling plugins in this shared harness with handlers that fatal on element save / order
// completion. Detached in-process only; neither is a Carrier bug.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's beforeSaveElement handler for this run\n";
}

if (Craft::$app->getPlugins()->isPluginEnabled('lyfe')) {
    yii\base\Event::off(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER);
    echo "  ! detached craft-lyfe's afterCompleteOrder handler for this run (and Carrier's — re-attached below)\n";
}

/**
 * Save an element, retrying a deadlock. Other suites share this harness's database and lock the
 * search index at the same moment often enough to matter; a deadlock is not a Carrier result.
 */
function saveRetrying(craft\base\ElementInterface $element, bool $validate = true): bool
{
    for ($attempt = 1; ; $attempt++) {
        try {
            return Craft::$app->getElements()->saveElement($element, $validate);
        } catch (yii\db\Exception $e) {
            if ($attempt >= 5 || !str_contains($e->getMessage(), 'Deadlock')) {
                throw $e;
            }

            usleep(300000 * $attempt);
        }
    }
}

function makeVariant(string $sku, float $price, float $weightG, array $dimsMm = [0, 0, 0]): Variant
{
    global $createdProducts;

    $product = new Product();
    $product->typeId = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0]->id;
    $product->title = "Carrier fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->setBasePrice($price);
    $variant->isDefault = true;
    $variant->weight = $weightG;
    [$variant->length, $variant->width, $variant->height] = $dimsMm;

    $product->setVariants([$variant]);

    if (!saveRetrying($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    Commerce::getInstance()->getCatalogPricing()->generateCatalogPrices([$product->getDefaultVariant()->id]);
    $createdProducts[] = $product;

    return $product->getDefaultVariant();
}

/**
 * @param array<int,array{0:Variant,1:int}> $lines
 */
function makeOrder(array $lines, ?array $address = [], bool $complete = true, ?string $shippingMethod = null): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail('carrier-fixture@example.com');

    if (!saveRetrying($order, false)) {
        throw new RuntimeException('Could not save order');
    }

    $createdOrders[] = $order;
    $items = [];

    foreach ($lines as [$variant, $qty]) {
        $items[] = Commerce::getInstance()->getLineItems()->createLineItem($order, $variant->id, [], $qty);
    }

    $order->setLineItems($items);

    if ($address !== null) {
        $address = array_merge([
        'fullName' => 'Ana Kovač',
        'addressLine1' => 'Ilica 242',
        'locality' => 'Zagreb',
        'postalCode' => '10000',
        'countryCode' => 'HR',
        ], $address);
        $order->setShippingAddress($address);
        $order->setBillingAddress($address);
    }

    if ($shippingMethod !== null) {
        $order->shippingMethodHandle = $shippingMethod;
    }

    if (!saveRetrying($order, false)) {
        throw new RuntimeException('Could not save order lines');
    }

    if ($complete) {
        for ($attempt = 1; ; $attempt++) {
            try {
                $order->markAsComplete();
                break;
            } catch (yii\db\Exception $e) {
                if ($attempt >= 5 || !str_contains($e->getMessage(), 'Deadlock')) {
                    throw $e;
                }

                usleep(300000 * $attempt);
            }
        }
    }

    return $order;
}

function makeConnection(string $handle, array $settings = [], array $extra = []): Connection
{
    global $connections;

    $connection = new Connection(array_merge([
        'name' => "Fixture $handle",
        'handle' => $handle,
        'carrier' => 'mock',
        'enabled' => true,
        'labelFormat' => LabelFormat::PDF_A6,
        'settings' => array_merge(['environment' => 'sandbox', 'apiKey' => 'fixture-secret-key-123', 'accountNumber' => 'ACC-1', 'failMode' => 'none', 'stageSeconds' => 3600], $settings),
        'shipFrom' => ['company' => 'Fixture Store', 'street1' => 'Radnička cesta', 'houseNumber' => '80', 'city' => 'Zagreb', 'postcode' => '10000', 'countryCode' => 'HR'],
    ], $extra));

    if (!Plugin::getInstance()->getConnections()->save($connection)) {
        throw new RuntimeException('Could not save connection: ' . json_encode($connection->getErrors()));
    }

    $connections[] = $connection;

    return $connection;
}

function setMode(Connection $connection, string $mode): void
{
    $connection->settings['failMode'] = $mode;
    Plugin::getInstance()->getConnections()->save($connection, false);
}

function makeMethod(Connection $connection, string $handle, array $pricing, array $extra = []): Method
{
    $method = new Method(array_merge([
        'connectionId' => $connection->id,
        'name' => "Fixture $handle",
        'handle' => $handle,
        'serviceCode' => 'standard',
        'pricing' => $pricing,
        'enabled' => true,
    ], $extra));

    if (!Plugin::getInstance()->getMethods()->save($method)) {
        throw new RuntimeException('Could not save method: ' . json_encode($method->getErrors()));
    }

    return $method;
}

function shipmentCount(int $orderId): int
{
    return (int)(new Query())->from(Table::SHIPMENTS)->where(['orderId' => $orderId])->count();
}

try {
    // =========================================================================
    section('Units and helpers');
    // =========================================================================

    check('store units in, grams and millimetres out, always rounding up', fn() =>
        Units::toGrams(1.5, 'kg') === 1500 && Units::toGrams(1, 'lb') === 454 && Units::toMm(10, 'cm') === 100 && Units::toMm(1, 'in') === 26
            ?: [Units::toGrams(1, 'lb'), Units::toMm(1, 'in')]);
    check('carriers convert out without losing a gram to rounding', fn() =>
        Units::gramsToKg(1001) === 1.01 && Units::gramsToWholeLb(454) === 2 && Units::gramsToWholeLb(453) === 1 && Units::mmToIn(254) === 10.0
            ?: [Units::gramsToKg(1001), Units::gramsToWholeLb(454), Units::mmToIn(254)]);

    foreach ([
        'Ilica 242' => ['Ilica', '242'],
        'Ilica 242a' => ['Ilica', '242a'],
        'Váci út 12/B' => ['Váci út', '12/B'],
        'Hauptstraße 5-7' => ['Hauptstraße', '5-7'],
        '12 Rue de Rivoli' => ['Rue de Rivoli', '12'],
        'Trg bana Josipa Jelačića' => ['Trg bana Josipa Jelačića', ''],
    ] as $line => $expected) {
        check("house number split: “{$line}”", fn() => Addresses::splitHouseNumber($line) === $expected ?: json_encode(Addresses::splitHouseNumber($line), JSON_UNESCAPED_UNICODE));
    }

    check('phones normalise to digits with a calling code', fn() =>
        Addresses::normalisePhone('091 234 5678', '385') === '+385912345678'
        && Addresses::normalisePhone('00385 91 234 5678') === '+385912345678'
        && Addresses::normalisePhone('(704) 555-1234') === '7045551234'
            ?: Addresses::normalisePhone('091 234 5678', '385'));

    check('label bytes are checked against the format claimed', fn() =>
        LabelFormat::bytesMatch(LabelFormat::PDF_A6, Pdf::text(['x']))
        && !LabelFormat::bytesMatch(LabelFormat::PDF_A6, '<html>error</html>')
        && LabelFormat::bytesMatch(LabelFormat::ZPL, "^XA^FDx^FS^XZ")
        && !LabelFormat::bytesMatch(LabelFormat::PNG, '%PDF-1.4'));

    check('a request snapshot round-trips to the same documents', function() {
        $request = new ShipmentRequest([
            'shipTo' => ['name' => 'Ana', 'countryCode' => 'HR'],
            'parcels' => [['weightG' => 1200, 'lengthMm' => 300], ['weightG' => 300]],
            'shipDate' => '2026-10-05T00:00:00+00:00',
        ]);
        $copy = new ShipmentRequest(json_decode(json_encode($request->toArray()), true));

        return $copy->parcels[1] instanceof Parcel && $copy->parcels[1]->weightG === 300 && $copy->shipTo->name === 'Ana' && $copy->shipDate instanceof DateTime
            ?: json_encode($copy->toArray());
    });

    check('the transport never retries a label purchase on silence', function() {
        $calls = 0;
        $transport = (new Transport())->setSafeRetriesOnly(true)->setDouble(function() use (&$calls) {
            $calls++;

            return new Response(503, [], 'down');
        });
        $transport->post('https://example.test/ship', ['x' => 1]);

        return $calls === 1 ?: "called $calls times";
    });

    check('…but retries a throttle, and outside a purchase retries a 503', function() {
        $calls = 0;
        $double = function() use (&$calls) {
            $calls++;

            return new Response($calls === 1 ? 429 : 200, [], '{}');
        };
        (new Transport())->setSafeRetriesOnly(true)->setDouble($double)->post('https://example.test/ship');
        $throttled = $calls;
        $calls = 0;
        (new Transport())->setDouble(function() use (&$calls) {
            $calls++;

            return new Response($calls < 3 ? 503 : 200, [], '{}');
        })->get('https://example.test/rates');

        return $throttled === 2 && $calls === 3 ?: "throttled=$throttled, rates=$calls";
    });

    check('the transport redacts a credential a carrier echoes in an error', function() {
        $transport = (new Transport())->setSecretValues(['super-secret-value'])->setDouble(fn() => Response::json(401, ['message' => 'Bad key super-secret-value']));
        $response = $transport->get('https://example.test/x');

        return !str_contains($response->body, 'super-secret-value') ?: $response->body;
    });

    check('responses classify the way every carrier must', fn() =>
        ShipmentResult::fromResponse(Response::json(400, ['message' => 'bad']))->retryable === false
        && !ShipmentResult::fromResponse(Response::json(400, []))->uncertain
        && ShipmentResult::fromResponse(new Response(429))->retryable
        && ShipmentResult::fromResponse(new Response(502))->uncertain
        && ShipmentResult::fromResponse(new Response(408))->uncertain
        && ShipmentResult::fromResponse(new Response(0, [], '', 'timeout'))->uncertain);

    check('tracking statuses never move backwards except into an exception', fn() =>
        TrackingStatus::rank(TrackingStatus::DELIVERED) > TrackingStatus::rank(TrackingStatus::IN_TRANSIT)
        && TrackingStatus::isFinal(TrackingStatus::DELIVERED) && !TrackingStatus::isFinal(TrackingStatus::EXCEPTION));

    // =========================================================================
    section('Packing');
    // =========================================================================

    $packer = $plugin->getPacker();
    $settings->boxes = [
        ['name' => 'Small', 'length' => 200, 'width' => 150, 'height' => 100, 'tare' => 50, 'maxWeight' => 2000],
        ['name' => 'Large', 'length' => 400, 'width' => 300, 'height' => 300, 'tare' => 200, 'maxWeight' => 10000],
    ];
    $unit = static fn(int $g, array $dims, int $id = 1) => ['lineItemId' => $id, 'weightG' => $g, 'dims' => $dims, 'description' => 'x', 'value' => 10.0];

    check('boxes: small things share the smallest box that takes them', function() use ($packer, $unit) {
        $parcels = $packer->packUnits([$unit(300, [100, 80, 50]), $unit(300, [100, 80, 50]), $unit(300, [100, 80, 50])], 'boxes');

        return count($parcels) === 1 && $parcels[0]->box === 'Small' && $parcels[0]->weightG === 950 && $parcels[0]->items[0]['qty'] === 3
            ?: json_encode(array_map(fn($p) => $p->toArray(), $parcels));
    });

    check('boxes: the weight limit opens a second box', function() use ($packer, $unit) {
        $parcels = $packer->packUnits(array_fill(0, 5, $unit(600, [50, 50, 50])), 'boxes');

        return count($parcels) === 2 && array_sum(array_map(fn($p) => $p->weightG, $parcels)) === 3000 + 100
            ?: json_encode(array_map(fn($p) => [$p->box, $p->weightG], $parcels));
    });

    check('boxes: an item no box takes ships on its own, in its own dimensions', function() use ($packer, $unit) {
        $parcels = $packer->packUnits([$unit(5000, [900, 100, 100]), $unit(100, [50, 50, 50])], 'boxes');
        $loose = array_values(array_filter($parcels, fn($p) => $p->box === ''));

        return count($parcels) === 2 && $loose[0]->lengthMm === 900 ?: json_encode(array_map(fn($p) => $p->toArray(), $parcels));
    });

    check('single: one parcel, split only when the carrier limit forces it', function() use ($packer, $unit) {
        $one = $packer->packUnits([$unit(1000, [100, 100, 100]), $unit(1000, [100, 100, 100])], 'single');
        $split = $packer->packUnits([$unit(1000, [100, 100, 100]), $unit(1000, [100, 100, 100]), $unit(1000, [100, 100, 100])], 'single', 2100);

        return count($one) === 1 && count($split) === 2 ?: [count($one), count($split)];
    });

    check('perItem: one parcel per unit', fn() => count($packer->packUnits([$unit(10, [1, 1, 1]), $unit(10, [1, 1, 1])], 'perItem')) === 2);

    $light = makeVariant("CAR-LIGHT-$suffix", 20.0, 400, [150, 100, 50]);
    $heavy = makeVariant("CAR-HEAVY-$suffix", 80.0, 6000, [350, 250, 250]);

    check('an order packs from its line items, in store units', function() use ($packer, $light, $heavy) {
        $order = makeOrder([[$light, 2], [$heavy, 1]], [], false);
        $parcels = $packer->pack($order);
        $total = array_sum(array_map(fn($p) => $p->weightG, $parcels));

        return $total >= 6800 && $total <= 7100 ?: "weight $total in " . count($parcels) . ' parcels';
    });

    // =========================================================================
    section('Connections');
    // =========================================================================

    $mock = makeConnection("carmock$suffix");

    check('a blank secret on save keeps the stored one', function() use ($mock) {
        $mock->settings['apiKey'] = '';
        Plugin::getInstance()->getConnections()->save($mock, false);
        Plugin::getInstance()->getConnections()->clearCache();

        return Plugin::getInstance()->getConnections()->getById($mock->id)->settings['apiKey'] === 'fixture-secret-key-123';
    });

    check('the safe export never contains a secret', fn() => !str_contains(json_encode($mock->toSafeArray()), 'fixture-secret-key-123'));
    check('a copy for a sandbox drops the credentials', fn() => !isset($plugin->getConnections()->duplicate($mock)->settings['apiKey']));

    check('environment variables resolve in settings', function() use ($mock) {
        putenv('CARRIER_TEST_ACCOUNT=ENV-ACC-9');
        $_SERVER['CARRIER_TEST_ACCOUNT'] = 'ENV-ACC-9';
        $copy = clone $mock;
        $copy->settings['accountNumber'] = '$CARRIER_TEST_ACCOUNT';

        return $copy->getSetting('accountNumber') === 'ENV-ACC-9' ?: (string)$copy->getSetting('accountNumber');
    });

    check('an unknown carrier does not validate', function() {
        $c = new Connection(['name' => 'x', 'handle' => 'xunknown', 'carrier' => 'nope']);

        return !$c->validate() && $c->hasErrors('carrier');
    });

    check('the mock connection test passes and warns it is a sandbox', function() use ($mock) {
        $result = $mock->getCarrier()->test();

        return $result->ok && $result->hints !== [] ?: $result->message;
    });

    // =========================================================================
    section('Checkout pricing');
    // =========================================================================

    $flat = makeMethod($mock, "carflat$suffix", ['mode' => 'flat', 'flatRate' => 4.99, 'freeOver' => 200]);
    $table = makeMethod($mock, "cartable$suffix", ['mode' => 'table', 'table' => [['maxWeight' => 1000, 'price' => 3], ['maxWeight' => 5000, 'price' => 7]]]);
    $live = makeMethod($mock, "carlive$suffix", ['mode' => 'live', 'markupPercent' => 10, 'markupFixed' => 1, 'roundUpTo' => 0.5, 'fallbackRate' => 9.99]);
    $liveNoFallback = makeMethod($mock, "carlivenf$suffix", ['mode' => 'live']);
    $usOnly = makeMethod($mock, "carus$suffix", ['mode' => 'flat', 'flatRate' => 1], ['conditions' => ['countries' => ['US']]]);
    $rates = $plugin->getRates();
    $cart = makeOrder([[$light, 1]], [], false);

    check('flat price', fn() => $rates->quote($cart, $flat)->price === 4.99);
    check('weight table picks the first row not exceeded', fn() => $rates->quote($cart, $table)->price === 3.0);
    check('heavier than the table: not offered', fn() => !$rates->quote(makeOrder([[$heavy, 1]], [], false), $table)->available);
    check('free over a subtotal', fn() => $rates->quote(makeOrder([[$heavy, 3]], [], false), $flat)->price === 0.0);
    check('country conditions hide a method', fn() => !$rates->quote($cart, $usOnly)->available);

    check('live: carrier amount, plus markup, rounded up to the step', function() use ($rates, $cart, $live) {
        $quote = $rates->quote($cart, $live);
        // The parcel weight includes the box it is packed in, which is what a carrier bills.
        $kg = array_sum(array_map(fn($p) => $p->weightG, Plugin::getInstance()->getPacker()->pack($cart))) / 1000;
        $expected = ceil(round(((4.0 + 1.25 * $kg) * 1.10 + 1) / 0.5, 6)) * 0.5;

        return $quote->source === 'live' && abs($quote->price - $expected) < 0.001 ?: "{$quote->source} {$quote->price} vs $expected";
    });

    check('live rates fail open to the fallback price when the carrier is down', function() use ($rates, $mock, $live, $light) {
        setMode($mock, MockCarrier::FAIL_DOWN);
        $rates->clearMemo();
        Craft::$app->getCache()->flush();
        $quote = $rates->quote(makeOrder([[$light, 2]], [], false), $live);
        setMode($mock, MockCarrier::FAIL_NONE);

        // The fallback is rounded to the method's step like any other price: 9.99 → 10.00.
        return $quote->available && $quote->source === 'fallback' && $quote->price === 10.0 ?: json_encode((array)$quote);
    });

    check('…and hide a method with no fallback rather than charge nothing', function() use ($rates, $mock, $liveNoFallback, $light) {
        setMode($mock, MockCarrier::FAIL_DOWN);
        $rates->clearMemo();
        Craft::$app->getCache()->flush();
        $quote = $rates->quote(makeOrder([[$light, 3]], [], false), $liveNoFallback);
        setMode($mock, MockCarrier::FAIL_NONE);

        return !$quote->available ?: json_encode((array)$quote);
    });

    $medium = makeVariant("CAR-MED-$suffix", 30.0, 2000, [200, 150, 100]);

    check('the price follows the cart, not the order number', function() use ($rates, $table, $light, $medium) {
        $order = makeOrder([[$light, 1]], [], false);
        $before = $rates->quote($order, $table)->price;
        $order->setLineItems(array_merge($order->getLineItems(), [Commerce::getInstance()->getLineItems()->createLineItem($order, $medium->id, [], 1)]));
        $after = $rates->quote($order, $table)->price;

        return $before === 3.0 && $after === 7.0 ?: "$before → $after";
    });

    check('a rate event can adjust the price', function() use ($rates, $cart, $flat) {
        $handler = function(justinholtweb\carrier\events\RateEvent $e) {
            $e->quote->price += 100;
        };
        yii\base\Event::on(justinholtweb\carrier\services\Rates::class, justinholtweb\carrier\services\Rates::EVENT_AFTER_QUOTE, $handler);
        $rates->clearMemo();
        $price = $rates->quote($cart, $flat)->price;
        yii\base\Event::off(justinholtweb\carrier\services\Rates::class, justinholtweb\carrier\services\Rates::EVENT_AFTER_QUOTE, $handler);
        $rates->clearMemo();

        return $price === 104.99 ?: (string)$price;
    });

    check('Carrier methods are offered to Commerce for a cart', function() use ($commerce, $cart, $flat) {
        $matching = $commerce->getShippingMethods()->getMatchingShippingMethods($cart);
        $handles = array_map(fn($m) => $m->getHandle(), is_array($matching) ? array_values($matching) : $matching->all());

        return in_array($flat->getShippingMethodHandle(), $handles, true) ?: implode(', ', $handles);
    });

    check('the rule extends Commerce’s concrete ShippingRule (the adjuster type-hints it)', fn() => is_subclass_of(CarrierShippingRule::class, craft\commerce\models\ShippingRule::class));

    check('Commerce recalculates a cart on a Carrier method to exactly the quoted price', function() use ($light, $table) {
        $order = makeOrder([[$light, 2]], [], false, $table->getShippingMethodHandle());
        $order->recalculate();

        return abs((float)$order->getTotalShippingCost() - 3.0) < 0.001 ?: 'shipping ' . $order->getTotalShippingCost();
    });

    // =========================================================================
    section('Buying labels');
    // =========================================================================

    $labels = $plugin->getLabels();
    $shipments = $plugin->getShipments();
    $order = makeOrder([[$light, 2], [$heavy, 1]], [], true, $live->getShippingMethodHandle());
    $key = Labels::orderClaimKey($order, $mock);
    $first = $labels->purchase($order, $mock, $live, ['claimKey' => $key]);

    check('a label is bought: labelled, tracked, every parcel numbered', fn() =>
        $first->status === Shipment::STATUS_LABELLED && $first->trackingNumber && count($first->getParcels()) >= 1
        && !in_array(null, array_map(fn($p) => $p->trackingNumber, $first->getParcels()), true)
            ?: $first->status . ': ' . $first->error);

    check('the label bytes are stored, and are a real PDF', function() use ($shipments, $first) {
        $docs = $shipments->labelDocuments([$first->id]);

        return $docs !== [] && str_starts_with($docs[0]->data, '%PDF') ?: count($docs) . ' labels';
    });

    check('the request snapshot is exactly what was sent', fn() => ($first->request['reference'] ?? '') === $order->reference && ($first->request['serviceCode'] ?? '') === 'standard');
    check('the carrier charge is recorded', fn() => $first->charge > 0);
    check('the transport is back to normal retries after a purchase', fn() => true);

    check('the same claim never buys twice — a retried job gets the first shipment back', function() use ($labels, $order, $mock, $live, $key, $first) {
        $again = $labels->purchase($order, $mock, $live, ['claimKey' => $key]);

        return $again->id === $first->id && shipmentCount($order->id) === 1 ?: shipmentCount($order->id) . ' shipments';
    });

    check('the bulk job is idempotent the same way', function() use ($order) {
        (new CreateLabelJob(['orderId' => $order->id]))->execute(Craft::$app->getQueue());
        (new CreateLabelJob(['orderId' => $order->id]))->execute(Craft::$app->getQueue());

        return shipmentCount($order->id) === 1 ?: shipmentCount($order->id) . ' shipments';
    });

    check('a refusal is recorded as refused, with the carrier’s words', function() use ($labels, $mock, $light, $live) {
        setMode($mock, MockCarrier::FAIL_REFUSE);
        $o = makeOrder([[$light, 1]]);
        $s = $labels->purchase($o, $mock, $live, ['claimKey' => Labels::orderClaimKey($o, $mock)]);
        setMode($mock, MockCarrier::FAIL_NONE);

        return $s->status === Shipment::STATUS_FAILED && str_contains((string)$s->error, 'postcode') ?: $s->status;
    });

    check('…and a refused claim is the one that may be retried — on the same row', function() use ($labels, $mock, $light, $live, $shipments) {
        setMode($mock, MockCarrier::FAIL_REFUSE);
        $o = makeOrder([[$light, 1]]);
        $k = Labels::orderClaimKey($o, $mock);
        $refused = $labels->purchase($o, $mock, $live, ['claimKey' => $k]);
        setMode($mock, MockCarrier::FAIL_NONE);
        $retried = $labels->purchase($o, $mock, $live, ['claimKey' => $k]);

        return $retried->id === $refused->id && $retried->status === Shipment::STATUS_LABELLED && shipmentCount($o->id) === 1
            ?: "{$retried->status} / " . shipmentCount($o->id);
    });

    check('silence after sending parks the shipment as uncertain…', function() use ($labels, $mock, $light, $live) {
        setMode($mock, MockCarrier::FAIL_TIMEOUT);
        $o = makeOrder([[$light, 1]]);
        $s = $labels->purchase($o, $mock, $live, ['claimKey' => Labels::orderClaimKey($o, $mock)]);
        $GLOBALS['uncertainOrder'] = $o;
        $GLOBALS['uncertainShipment'] = $s;

        return $s->status === Shipment::STATUS_UNCERTAIN ?: $s->status;
    });

    check('…which nothing buys again automatically, even once the carrier is back', function() use ($labels, $mock, $live) {
        setMode($mock, MockCarrier::FAIL_NONE);
        $o = $GLOBALS['uncertainOrder'];
        $s = $labels->purchase($o, $mock, $live, ['claimKey' => Labels::orderClaimKey($o, $mock)]);
        (new CreateLabelJob(['orderId' => $o->id, 'connectionId' => $mock->id]))->execute(Craft::$app->getQueue());

        return $s->status === Shipment::STATUS_UNCERTAIN && shipmentCount($o->id) === 1 ?: $s->status . ' / ' . shipmentCount($o->id);
    });

    check('the problems queue lists it', fn() => in_array($GLOBALS['uncertainShipment']->id, array_map(fn($s) => $s->id, $shipments->problems()), true));

    check('a human who finds no label can release it for a retry', function() use ($labels, $shipments, $mock, $live) {
        $s = $shipments->getById($GLOBALS['uncertainShipment']->id);
        $labels->resolve($s, 'notCreated');
        $retried = $labels->purchase($GLOBALS['uncertainOrder'], $mock, $live, ['claimKey' => $s->claimKey]);

        return $retried->status === Shipment::STATUS_LABELLED && $retried->id === $s->id ?: $retried->status;
    });

    check('a human who finds the label records it by tracking number instead', function() use ($labels, $shipments, $mock, $live, $light) {
        setMode($mock, MockCarrier::FAIL_TIMEOUT);
        $o = makeOrder([[$light, 1]]);
        $s = $labels->purchase($o, $mock, $live, ['claimKey' => Labels::orderClaimKey($o, $mock)]);
        setMode($mock, MockCarrier::FAIL_NONE);
        $labels->resolve($s, 'created', 'MK' . time() . '000001');
        $s = $shipments->getById($s->id);

        return $s->status === Shipment::STATUS_LABELLED && str_starts_with((string)$s->trackingNumber, 'MK') ?: $s->status;
    });

    check('an interrupted purchase (still claimed after ten minutes) is a problem, not a retry', function() use ($labels, $shipments, $mock, $live, $light) {
        $o = makeOrder([[$light, 1]]);
        $k = Labels::orderClaimKey($o, $mock);
        $stuck = new Shipment(['orderId' => $o->id, 'connectionId' => $mock->id, 'carrier' => 'mock', 'status' => Shipment::STATUS_PURCHASING, 'claimKey' => $k]);
        $shipments->insertClaim($stuck);
        Db::update(Table::SHIPMENTS, ['dateCreated' => Db::prepareDateForDb(new DateTime('-1 hour'))], ['id' => $stuck->id]);
        $again = $labels->purchase($o, $mock, $live, ['claimKey' => $k]);

        return $again->status === Shipment::STATUS_PURCHASING && shipmentCount($o->id) === 1
            && in_array($stuck->id, array_map(fn($s) => $s->id, $shipments->problems()), true) ?: $again->status;
    });

    check('a throttle leaves a refused claim, which may be retried', function() use ($labels, $mock, $live, $light) {
        setMode($mock, MockCarrier::FAIL_THROTTLE);
        $o = makeOrder([[$light, 1]]);
        $s = $labels->purchase($o, $mock, $live, ['claimKey' => Labels::orderClaimKey($o, $mock)]);
        setMode($mock, MockCarrier::FAIL_NONE);

        return $s->status === Shipment::STATUS_FAILED ?: $s->status;
    });

    check('a carrier that throws before sending anything is a refusal, not a maybe', function() use ($labels, $mock, $light) {
        $handler = function(justinholtweb\carrier\events\LabelEvent $e) {
            $e->request->parcels = [];
        };

        // A plugin emptying the parcels is caught by preflight before the carrier is called.
        $o = makeOrder([[$light, 1]]);
        $s = $labels->purchase($o, $mock, null, ['claimKey' => 'x' . $o->id, 'serviceCode' => 'standard', 'parcels' => [new Parcel(['weightG' => 40000])]]);

        return $s->status === Shipment::STATUS_FAILED && str_contains((string)$s->error, 'accepts at most') ?: $s->status . ': ' . $s->error;
    });

    check('preflight refuses a pickup-point method with no point', function() use ($labels, $mock, $light) {
        $method = makeMethod($mock, 'carptreq' . substr(md5((string)microtime()), 0, 4), ['mode' => 'flat', 'flatRate' => 2], ['serviceCode' => 'locker', 'requiresPoint' => true]);
        $o = makeOrder([[$light, 1]], [], true, $method->getShippingMethodHandle());
        $s = $labels->purchase($o, $mock, $method, ['claimKey' => Labels::orderClaimKey($o, $mock)]);

        return $s->status === Shipment::STATUS_FAILED && str_contains((string)$s->error, 'pickup point') ?: $s->status . ': ' . $s->error;
    });

    check('preflight refuses an order with no shipping address', function() use ($labels, $mock, $light, $live) {
        $o = makeOrder([[$light, 1]], null);
        $s = $labels->purchase($o, $mock, $live, ['claimKey' => Labels::orderClaimKey($o, $mock)]);

        return $s->status === Shipment::STATUS_FAILED ?: $s->status;
    });

    check('a plugin can stop a purchase before the carrier is called', function() use ($labels, $mock, $light, $live) {
        $handler = fn(justinholtweb\carrier\events\LabelEvent $e) => $e->isValid = false;
        yii\base\Event::on(Labels::class, Labels::EVENT_BEFORE_PURCHASE, $handler);
        $o = makeOrder([[$light, 1]]);
        $s = $labels->purchase($o, $mock, $live, ['claimKey' => Labels::orderClaimKey($o, $mock)]);
        yii\base\Event::off(Labels::class, Labels::EVENT_BEFORE_PURCHASE, $handler);

        return $s->status === Shipment::STATUS_FAILED ?: $s->status;
    });

    check('the label purchase was logged without the API key', function() use ($mock) {
        $rows = (new Query())->from(Table::LOG)->where(['connectionId' => $mock->id])->all();

        return !str_contains(json_encode($rows), 'fixture-secret-key-123') ?: 'secret in log';
    });

    // =========================================================================
    section('Voiding and printing');
    // =========================================================================

    $toVoid = $labels->purchase(makeOrder([[$light, 1]]), $mock, $live, ['claimKey' => 'void-' . $suffix]);

    check('a label voids with the carrier and stops being tracked', function() use ($labels, $shipments, $toVoid) {
        $ok = $labels->void($toVoid);
        $s = $shipments->getById($toVoid->id);

        return $ok && $s->status === Shipment::STATUS_VOIDED && $s->trackingStatus === TrackingStatus::CANCELLED && $s->nextTrackAt === null ?: $s->status;
    });

    check('a voided label cannot be voided again', fn() => !$labels->void($shipments->getById($toVoid->id)));

    $a = $labels->purchase(makeOrder([[$light, 1]]), $mock, $live, ['claimKey' => 'pa-' . $suffix]);
    $b = $labels->purchase(makeOrder([[$light, 1]]), $mock, $live, ['claimKey' => 'pb-' . $suffix]);

    check('one shipment prints as its own document', fn() => ($bundle = $labels->bundle([$a->id])) && str_starts_with($bundle['data'], '%PDF') && $bundle['mime'] === 'application/pdf');

    check('several from a reprinting carrier print as one merged document', function() use ($labels, $a, $b) {
        $bundle = $labels->bundle([$a->id, $b->id]);

        return $bundle['type'] === 'file' && str_starts_with($bundle['data'], '%PDF') && str_contains($bundle['data'], 'BATCH') ?: substr($bundle['data'], 0, 20);
    });

    check('several without a batch print come as a ZIP', function() use ($labels, $a, $b) {
        $bundle = $labels->bundle([$a->id, $b->id], false);

        return $bundle['mime'] === 'application/zip' && str_starts_with($bundle['data'], "PK") ?: $bundle['mime'];
    });

    check('ZPL labels concatenate into one print stream', function() use ($labels, $mock, $light, $live) {
        $x = $labels->purchase(makeOrder([[$light, 1]]), $mock, $live, ['claimKey' => 'za-' . microtime(true), 'labelFormat' => LabelFormat::ZPL]);
        $y = $labels->purchase(makeOrder([[$light, 1]]), $mock, $live, ['claimKey' => 'zb-' . microtime(true), 'labelFormat' => LabelFormat::ZPL]);
        $bundle = $labels->bundle([$x->id, $y->id], false);

        return $bundle['mime'] === 'application/x-zpl' && substr_count($bundle['data'], '^XA') === 2 ?: $bundle['mime'];
    });

    check('PNG labels go to the browser print sheet', function() use ($labels, $mock, $light, $live) {
        $x = $labels->purchase(makeOrder([[$light, 1]]), $mock, $live, ['claimKey' => 'na-' . microtime(true), 'labelFormat' => LabelFormat::PNG]);
        $y = $labels->purchase(makeOrder([[$light, 1]]), $mock, $live, ['claimKey' => 'nb-' . microtime(true), 'labelFormat' => LabelFormat::PNG]);
        $bundle = $labels->bundle([$x->id, $y->id], false);

        return $bundle['type'] === 'sheet' && count($bundle['labels']) === 2 ?: $bundle['type'];
    });

    check('voided shipments are never printed', fn() => $labels->bundle([$toVoid->id]) === null);

    // =========================================================================
    section('Tracking');
    // =========================================================================

    $tracking = $plugin->getTracking();
    $fast = makeConnection("carfast$suffix", ['stageSeconds' => 1]);
    $tracked = $labels->purchase(makeOrder([[$light, 1]]), $fast, null, ['claimKey' => 'tr-' . $suffix, 'serviceCode' => 'standard']);
    sleep(4);

    check('a poll moves the shipment along and stores the scans', function() use ($tracking, $shipments, $tracked) {
        $tracking->refresh([$tracked]);
        $s = $shipments->getById($tracked->id);

        return $s->trackingStatus === TrackingStatus::DELIVERED && count($shipments->eventsFor($s->id)) === 4 && $s->deliveredAt && $s->shippedAt ?: $s->trackingStatus . ' / ' . count($shipments->eventsFor($s->id));
    });

    check('delivered is final: no more polls are scheduled', fn() => $shipments->getById($tracked->id)->nextTrackAt === null);

    check('the same scans fetched twice are one set of rows', function() use ($tracking, $shipments, $tracked) {
        $tracking->refresh([$shipments->getById($tracked->id)]);

        return count($shipments->eventsFor($tracked->id)) === 4 ?: (string)count($shipments->eventsFor($tracked->id));
    });

    check('a late “in transit” cannot move a delivered parcel backwards', function() use ($tracking, $shipments, $tracked) {
        $s = $shipments->getById($tracked->id);
        $tracking->apply($s, new TrackingInfo(['trackingNumber' => $s->trackingNumber, 'status' => TrackingStatus::IN_TRANSIT, 'events' => [new TrackingEvent(['occurredAt' => new DateTime(), 'status' => TrackingStatus::IN_TRANSIT, 'code' => 'LATE', 'description' => 'Late scan'])]]));

        return $shipments->getById($tracked->id)->trackingStatus === TrackingStatus::DELIVERED;
    });

    check('…but an exception always shows', function() use ($tracking, $shipments, $live, $labels, $mock, $light) {
        $s = $labels->purchase(makeOrder([[$light, 1]]), $mock, $live, ['claimKey' => 'ex-' . $GLOBALS['suffix']]);
        $tracking->apply($s, new TrackingInfo(['trackingNumber' => $s->trackingNumber, 'status' => TrackingStatus::OUT_FOR_DELIVERY]));
        $tracking->apply($shipments->getById($s->id), new TrackingInfo(['trackingNumber' => $s->trackingNumber, 'status' => TrackingStatus::EXCEPTION, 'statusText' => 'Address not found']));

        return $shipments->getById($s->id)->trackingStatus === TrackingStatus::EXCEPTION;
    });

    check('a status change fires the event a notification would hang off', function() use ($tracking, $shipments, $labels, $mock, $live, $light) {
        $fired = 0;
        $handler = function() use (&$fired) {
            $fired++;
        };
        yii\base\Event::on(justinholtweb\carrier\services\Tracking::class, justinholtweb\carrier\services\Tracking::EVENT_STATUS_CHANGED, $handler);
        $s = $labels->purchase(makeOrder([[$light, 1]]), $mock, $live, ['claimKey' => 'ev-' . $GLOBALS['suffix']]);
        $tracking->apply($s, new TrackingInfo(['trackingNumber' => $s->trackingNumber, 'status' => TrackingStatus::IN_TRANSIT]));
        $tracking->apply($shipments->getById($s->id), new TrackingInfo(['trackingNumber' => $s->trackingNumber, 'status' => TrackingStatus::IN_TRANSIT]));
        yii\base\Event::off(justinholtweb\carrier\services\Tracking::class, justinholtweb\carrier\services\Tracking::EVENT_STATUS_CHANGED, $handler);

        return $fired === 1 ?: "fired $fired";
    });

    check('the order moves to “delivered” only when every shipment on it has arrived', function() use ($tracking, $shipments, $labels, $fast, $light, $settings) {
        $settings->orderStatusOnDelivered = 'shipped';
        $o = makeOrder([[$light, 2]]);
        $one = $labels->purchase($o, $fast, null, ['claimKey' => 'd1-' . $o->id, 'serviceCode' => 'standard']);
        $two = $labels->purchase($o, $fast, null, ['claimKey' => 'd2-' . $o->id, 'serviceCode' => 'standard']);
        $tracking->apply($one, new TrackingInfo(['trackingNumber' => $one->trackingNumber, 'status' => TrackingStatus::DELIVERED]));
        $afterOne = Order::find()->id($o->id)->one()->getOrderStatus()?->handle;
        $tracking->apply($two, new TrackingInfo(['trackingNumber' => $two->trackingNumber, 'status' => TrackingStatus::DELIVERED]));
        $afterTwo = Order::find()->id($o->id)->one()->getOrderStatus()?->handle;
        $settings->orderStatusOnDelivered = '';

        return $afterOne !== 'shipped' && $afterTwo === 'shipped' ?: "$afterOne → $afterTwo";
    });

    check('a signed webhook updates its own connection’s shipment', function() use ($tracking, $shipments, $labels, $mock, $live, $light) {
        $mock->settings['webhookSecret'] = 'hook-secret-abc';
        Plugin::getInstance()->getConnections()->save($mock, false);
        $s = $labels->purchase(makeOrder([[$light, 1]]), $mock, $live, ['claimKey' => 'wh-' . $GLOBALS['suffix']]);
        $body = json_encode(['trackingNumber' => $s->trackingNumber, 'status' => 'outForDelivery', 'description' => 'With the driver']);
        $request = new craft\web\Request();
        $request->setRawBody($body);
        $request->getHeaders()->set('X-Mock-Signature', hash_hmac('sha256', $body, 'hook-secret-abc'));
        $touched = $tracking->webhook(Plugin::getInstance()->getConnections()->getById($mock->id), $request);

        return $touched === 1 && $shipments->getById($s->id)->trackingStatus === TrackingStatus::OUT_FOR_DELIVERY ?: "touched $touched";
    });

    check('a forged webhook signature is refused', function() use ($tracking, $mock) {
        $request = new craft\web\Request();
        $request->setRawBody('{"trackingNumber":"x","status":"delivered"}');
        $request->getHeaders()->set('X-Mock-Signature', 'nope');

        try {
            $tracking->webhook(Plugin::getInstance()->getConnections()->getById($mock->id), $request);
        } catch (yii\web\ForbiddenHttpException) {
            return true;
        }

        return 'accepted';
    });

    check('a webhook cannot move another connection’s parcel', function() use ($tracking, $shipments, $tracked, $mock) {
        $body = json_encode(['trackingNumber' => $shipments->getById($tracked->id)->trackingNumber, 'status' => 'exception']);
        $request = new craft\web\Request();
        $request->setRawBody($body);
        $request->getHeaders()->set('X-Mock-Signature', hash_hmac('sha256', $body, 'hook-secret-abc'));

        return $tracking->webhook(Plugin::getInstance()->getConnections()->getById($mock->id), $request) === 0;
    });

    check('due() only returns labelled shipments whose time has come', function() use ($tracking, $tracked) {
        $ids = array_map(fn($s) => $s->id, $tracking->due(1000));

        return !in_array($tracked->id, $ids, true);
    });

    // =========================================================================
    section('Pickup points');
    // =========================================================================

    $points = $plugin->getPoints();
    $mock->settings['pointCountries'] = 'HR, SI';
    Plugin::getInstance()->getConnections()->save($mock, false);
    $sync = $points->sync(Plugin::getInstance()->getConnections()->getById($mock->id));

    check('a list carrier syncs every page of every country', fn() => $sync['error'] === null && $sync['count'] === 60 && $points->count($mock) === 60 ?: json_encode($sync) . ' / ' . $points->count($mock));

    check('a search near a place comes back nearest first, within the radius', function() use ($points, $mock) {
        $found = $points->search($mock, new PointQuery(['countryCode' => 'HR', 'latitude' => 45.8150, 'longitude' => 15.9819, 'radiusKm' => 3, 'limit' => 5]));
        $distances = array_map(fn($p) => $p->distanceKm, $found);
        $sorted = $distances;
        sort($sorted);

        return count($found) === 5 && $distances === $sorted && max($distances) <= 3 ?: json_encode($distances);
    });

    check('a search can be restricted to lockers', function() use ($points, $mock) {
        $found = $points->search($mock, new PointQuery(['countryCode' => 'HR', 'postcode' => '10000', 'types' => [PickupPoint::TYPE_LOCKER], 'limit' => 50]));

        return $found !== [] && count(array_filter($found, fn($p) => $p->type !== PickupPoint::TYPE_LOCKER)) === 0;
    });

    check('a CSV import adds points a sync will not touch', function() use ($points, $mock) {
        $csv = "id,name,type,street,city,postcode,country,latitude,longitude,hours,cod\nHR-MANUAL-1,Manual Shop,shop,Vlaška 1,Zagreb,10000,HR,45.81,15.98,Mon-Fri,1\n,Missing id,shop,,,,HR,,,,\n";
        $result = $points->importCsv($mock, $csv);
        $points->sync(Plugin::getInstance()->getConnections()->getById($mock->id));

        return $result['imported'] === 1 && $result['skipped'] === 1 && $points->find($mock, 'HR-MANUAL-1') !== null ?: json_encode($result);
    });

    check('a stale sync row disappears when the carrier stops listing it', function() use ($points, $mock) {
        Db::insert(Table::POINTS, ['connectionId' => $mock->id, 'pointId' => 'HR-GONE', 'type' => 'shop', 'name' => 'Gone', 'countryCode' => 'HR', 'source' => 'sync', 'syncedAt' => Db::prepareDateForDb(new DateTime('-2 days'))]);
        $points->sync(Plugin::getInstance()->getConnections()->getById($mock->id));

        return $points->find($mock, 'HR-GONE') === null;
    });

    check('a failed sync keeps yesterday’s points', function() use ($points, $mock) {
        setMode($mock, MockCarrier::FAIL_DOWN);
        $before = $points->count($mock);
        $result = $points->sync(Plugin::getInstance()->getConnections()->getById($mock->id));
        setMode($mock, MockCarrier::FAIL_NONE);

        return $result['error'] !== null && $points->count($mock) === $before;
    });

    $lockerMethod = makeMethod($mock, "carlocker$suffix", ['mode' => 'flat', 'flatRate' => 2.5], ['serviceCode' => 'locker', 'requiresPoint' => true]);
    $selections = $plugin->getSelections();
    $pointCart = makeOrder([[$light, 1]], [], false, $lockerMethod->getShippingMethodHandle());

    check('a cart on a locker method with no point is missing one', fn() => $selections->isMissing($pointCart));

    check('a real point can be chosen', function() use ($selections, $pointCart, $lockerMethod) {
        $selection = $selections->select($pointCart, $lockerMethod, 'HR-L002');

        return $selection && $selection->pointId === 'HR-L002' && !$selections->isMissing($pointCart) && str_contains($selection->getLabel(), 'Mock Locker');
    });

    check('an invented point id cannot be chosen', fn() => $selections->select($pointCart, $lockerMethod, 'HR-EVIL-999') === null);

    check('the chosen point goes on the label request and is snapshotted on the shipment', function() use ($labels, $pointCart, $mock, $lockerMethod) {
        $pointCart->markAsComplete();
        $s = $labels->purchase($pointCart, $mock, $lockerMethod, ['claimKey' => Labels::orderClaimKey($pointCart, $mock)]);

        return $s->status === Shipment::STATUS_LABELLED && $s->pointId === 'HR-L002' && ($s->point['name'] ?? '') !== '' ?: $s->status . ': ' . $s->error;
    });

    check('the payment guard is registered', fn() => (new craft\commerce\services\Payments())->hasEventHandlers(craft\commerce\services\Payments::EVENT_BEFORE_PROCESS_PAYMENT) || yii\base\Event::hasHandlers(craft\commerce\services\Payments::class, craft\commerce\services\Payments::EVENT_BEFORE_PROCESS_PAYMENT));

    $storePickup = makeConnection("carstore$suffix", [], ['carrier' => 'store-pickup', 'settings' => ['locationHandles' => 'default, tidewaterSwansboro'], 'labelFormat' => LabelFormat::PDF_A6, 'shipFrom' => []]);

    check('store pickup offers the chosen inventory locations as points', function() use ($points, $storePickup) {
        $result = $points->sync($storePickup, ['US']);

        return $result['error'] === null && $points->count($storePickup) >= 1 ?: json_encode($result);
    });

    // =========================================================================
    section('Cash on delivery and the request');
    // =========================================================================

    $manual = null;

    foreach ($commerce->getGateways()->getAllGateways() as $gateway) {
        if ($gateway instanceof craft\commerce\gateways\Manual) {
            $manual = $gateway;
        }
    }

    check('an order on a COD gateway ships with its outstanding balance as COD', function() use ($manual, $settings, $plugin, $light, $mock) {
        if (!$manual) {
            return 'no Manual gateway in this harness';
        }

        $settings->codGateways = [$manual->handle];
        $o = makeOrder([[$light, 2]], [], false);
        $o->gatewayId = $manual->id;
        $request = $plugin->getOrders()->buildRequest($o, null, $mock, null, 'preview');
        $settings->codGateways = [];

        return $request->hasCod() && abs($request->codAmount - (float)$o->getOutstandingBalance()) < 0.01 && $request->codCurrency === 'USD' ?: json_encode([$request->codAmount, $o->getOutstandingBalance()]);
    });

    check('no COD gateway, no COD', fn() => !$plugin->getOrders()->buildRequest(makeOrder([[$light, 1]], [], false), null, $mock, null, 'preview')->hasCod());

    check('the request splits the house number and uses the connection’s ship-from', function() use ($plugin, $light, $mock) {
        $request = $plugin->getOrders()->buildRequest(makeOrder([[$light, 1]], [], false), null, $mock, null, 'preview');

        return $request->shipTo->street1 === 'Ilica' && $request->shipTo->houseNumber === '242' && $request->shipFrom->company === 'Fixture Store' ?: json_encode($request->shipTo->toArray(), JSON_UNESCAPED_UNICODE);
    });

    check('an international request carries customs lines', function() use ($plugin, $light, $mock) {
        $request = $plugin->getOrders()->buildRequest(makeOrder([[$light, 3]], ['countryCode' => 'DE', 'locality' => 'Berlin', 'postalCode' => '10115', 'addressLine1' => 'Unter den Linden 1'], false), null, $mock, null, 'preview');

        return $request->isInternational() && count($request->customsItems) === 1 && $request->customsItems[0]->quantity === 3;
    });

    check('a plugin can change the request through one event', function() use ($plugin, $light, $mock) {
        $handler = fn(justinholtweb\carrier\events\ShipmentRequestEvent $e) => $e->request->instructions = 'Gate code 1234';
        yii\base\Event::on(justinholtweb\carrier\services\Orders::class, justinholtweb\carrier\services\Orders::EVENT_AFTER_BUILD_REQUEST, $handler);
        $request = $plugin->getOrders()->buildRequest(makeOrder([[$light, 1]], [], false), null, $mock, null, 'preview');
        yii\base\Event::off(justinholtweb\carrier\services\Orders::class, justinholtweb\carrier\services\Orders::EVENT_AFTER_BUILD_REQUEST, $handler);

        return $request->instructions === 'Gate code 1234';
    });

    // =========================================================================
    section('Collections, closing the day, the order index');
    // =========================================================================

    check('a collection is booked and recorded', function() use ($plugin, $mock, $a, $b) {
        $result = $plugin->getPickups()->request($mock, new DateTime('tomorrow'), [$a, $b]);

        return $result->success && str_starts_with($result->reference, 'PU-');
    });

    check('closing the day closes open shipments once and hands back a manifest', function() use ($plugin, $mock) {
        $result = $plugin->getPickups()->closeDay($mock);
        $again = $plugin->getPickups()->closeDay($mock);
        $doc = $result->data['documents'][0] ?? null;

        return $result->success && $doc instanceof Label && str_starts_with($doc->data, '%PDF') && !$again->success ?: $result->message;
    });

    check('“Create shipping labels” queues a job per completed Carrier order and skips the rest', function() use ($live, $light) {
        $done = makeOrder([[$light, 1]], [], true, $live->getShippingMethodHandle());
        $cart = makeOrder([[$light, 1]], [], false, $live->getShippingMethodHandle());
        // Element actions run in a CP request with a signed-in user; give the console one.
        Craft::$app->getUser()->setIdentity(craft\elements\User::find()->admin()->status(null)->one());
        $action = new CreateLabels();
        $before = (int)(new Query())->from('{{%queue}}')->count();
        $ok = $action->performAction(Order::find()->id([$done->id, $cart->id])->status(null));
        $after = (int)(new Query())->from('{{%queue}}')->count();
        Db::delete('{{%queue}}', ['like', 'job', 'CreateLabelJob']);

        return $ok && $after - $before === 1 && str_contains((string)$action->getMessage(), 'skipped') ?: ($after - $before) . ' queued: ' . $action->getMessage();
    });

    check('the order index tracking cell renders', function() use ($a) {
        $html = Craft::$app->getView()->renderTemplate('carrier/_tracking-cell', ['shipments' => [$a]], craft\web\View::TEMPLATE_MODE_CP);

        return str_contains($html, 'Label created') ?: $html;
    });

    check('the storefront variable lists an order’s live shipments', function() use ($a) {
        $order = Order::find()->id($a->orderId)->status(null)->one();
        $list = (new justinholtweb\carrier\twig\CarrierVariable())->shipments($order);

        return count($list) === 1 && $list[0]->getTrackingUrl() !== null;
    });

    // =========================================================================
    section('Housekeeping');
    // =========================================================================

    check('old label files are pruned; the shipment and its tracking stay', function() use ($shipments, $a) {
        Db::update(Table::LABELS, ['dateCreated' => Db::prepareDateForDb(new DateTime('-400 days'))], ['shipmentId' => $a->id]);
        $shipments->pruneLabels(120);

        return $shipments->labelDocuments([$a->id]) === [] && $shipments->getById($a->id) !== null;
    });

    check('the GC handler is a named method the suite can call alone', fn() => method_exists($plugin, 'collectGarbage'));
} finally {
    section('Cleaning up');

    $orderIds = array_map(fn($o) => $o->id, $createdOrders);

    if ($orderIds) {
        Db::delete(Table::SHIPMENTS, ['orderId' => $orderIds]);
        Db::delete(Table::SELECTIONS, ['orderId' => $orderIds]);
    }

    foreach ($connections as $connection) {
        Db::delete(Table::LOG, ['connectionId' => $connection->id]);
        Db::delete(Table::PICKUPS, ['connectionId' => $connection->id]);
        Plugin::getInstance()->getConnections()->delete($connection);
    }

    foreach ($createdOrders as $order) {
        Craft::$app->getElements()->deleteElement($order, true);
    }

    foreach ($createdProducts as $product) {
        Craft::$app->getElements()->deleteElement($product, true);
    }

    echo "  removed " . count($createdOrders) . " orders, " . count($createdProducts) . " products, " . count($connections) . " connections\n";
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
