<?php
/**
 * Creates (or with `teardown`, removes) the fixtures the Plugin Store screenshots are taken from:
 * two Mock connections and a store-pickup one, four checkout methods, a delivered order with its
 * whole tracking history, a locker order in transit, and a refused and an uncertain purchase for
 * the Problems screen. Prints ids as JSON.
 *
 * Every connection is on a built-in carrier and is named as one. Nothing here may look like a real
 * carrier's account.
 *
 *   tests/integration/run.sh /var/www/craft-carrier/tests/integration/promo-fixtures.php
 *   tests/integration/run.sh /var/www/craft-carrier/tests/integration/promo-fixtures.php teardown
 */
$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Db;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\Plugin;

if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}
yii\base\Event::off(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER);

$plugin = Plugin::getInstance();
$state = '/var/www/craft-carrier/tests/integration/.promo-state.json';

// The harness admin's password stops validating at intervals; reset it through the element.
$admin = craft\elements\User::find()->id(1)->status(null)->one();
$admin->newPassword = 'claudepassword';
Craft::$app->getElements()->saveElement($admin);

$teardown = static function() use ($plugin, $state): void {
    $ids = json_decode((string)@file_get_contents($state), true) ?: [];

    foreach ($ids['orders'] ?? [] as $id) {
        $shipmentIds = (new craft\db\Query())->select(['id'])->from(Table::SHIPMENTS)->where(['orderId' => $id])->column();

        foreach ([Table::PARCELS, Table::LABELS, Table::EVENTS, Table::LOG] as $table) {
            Db::delete($table, ['shipmentId' => $shipmentIds]);
        }

        Db::delete(Table::SHIPMENTS, ['orderId' => $id]);
        Db::delete(Table::SELECTIONS, ['orderId' => $id]);

        if ($o = Order::find()->id($id)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($o, true);
        }
    }

    if (!empty($ids['product']) && ($p = Product::find()->id($ids['product'])->status(null)->one())) {
        Craft::$app->getElements()->deleteElement($p, true);
    }

    foreach ($ids['connections'] ?? [] as $connectionId) {
        Db::delete(Table::METHODS, ['connectionId' => $connectionId]);
        Db::delete(Table::POINTS, ['connectionId' => $connectionId]);
        Db::delete(Table::PICKUPS, ['connectionId' => $connectionId]);

        if ($c = $plugin->getConnections()->getById($connectionId)) {
            $plugin->getConnections()->delete($c);
        }
    }

    @unlink($state);
};

// A second run replaces the first rather than doubling up.
$teardown();

if (($argv[1] ?? '') === 'teardown') {
    echo "torn down\n";
    exit(0);
}

$shipFrom = ['company' => 'Northside Bike Co. (demo)', 'street1' => 'N Tryon St', 'houseNumber' => '400', 'city' => 'Charlotte', 'state' => 'NC', 'postcode' => '28202', 'countryCode' => 'US'];

$demo = new Connection([
    'name' => 'Mock Carrier — demo', 'handle' => 'mockDemo', 'carrier' => 'mock', 'enabled' => true,
    'labelFormat' => 'pdf-4x6',
    'settings' => ['environment' => 'sandbox', 'apiKey' => 'demo-secret-123', 'accountNumber' => 'MOCK-001', 'failMode' => 'none', 'stageSeconds' => 28800, 'pointCountries' => 'US'],
    'shipFrom' => $shipFrom,
]);
$drills = new Connection([
    'name' => 'Mock Carrier — failure drills', 'handle' => 'mockDrills', 'carrier' => 'mock', 'enabled' => true,
    'labelFormat' => 'zpl',
    'settings' => ['environment' => 'sandbox', 'apiKey' => 'drill-secret-123', 'accountNumber' => 'MOCK-002', 'failMode' => 'none', 'stageSeconds' => 28800, 'pointCountries' => 'US'],
    'shipFrom' => $shipFrom,
]);
$store = new Connection([
    'name' => 'Pick up in store', 'handle' => 'storePickup', 'carrier' => 'store-pickup', 'enabled' => true,
    'labelFormat' => 'pdf-a6',
    'settings' => ['hours' => 'Mon–Sat 9:00–18:00'],
    'shipFrom' => $shipFrom,
]);

$connectionIds = [];
foreach ([$demo, $drills, $store] as $connection) {
    if (!$plugin->getConnections()->save($connection)) {
        fwrite(STDERR, "Could not save {$connection->name}: " . json_encode($connection->getErrors()) . "\n");
        continue;
    }
    $connectionIds[] = $connection->id;
}
file_put_contents($state, json_encode(['connections' => $connectionIds]));

$plugin->getPoints()->sync($demo);

$methods = [
    'door' => new Method(['connectionId' => $demo->id, 'name' => 'Courier to your door', 'handle' => 'promoDoor', 'serviceCode' => 'standard',
        'pricing' => ['mode' => 'live', 'markupPercent' => 10, 'roundUpTo' => 0.5, 'fallbackRate' => 7.5, 'freeOver' => 100],
        'conditions' => ['countries' => ['US'], 'maxWeight' => 30000]]),
    'locker' => new Method(['connectionId' => $demo->id, 'name' => 'Parcel locker', 'handle' => 'promoLocker', 'serviceCode' => 'locker',
        'requiresPoint' => true, 'pricing' => ['mode' => 'flat', 'flatRate' => 3.95]]),
    'express' => new Method(['connectionId' => $demo->id, 'name' => 'Express, next day', 'handle' => 'promoExpress', 'serviceCode' => 'express',
        'pricing' => ['mode' => 'table', 'table' => [['maxWeight' => 2000, 'price' => 9.5], ['maxWeight' => 10000, 'price' => 14], ['maxWeight' => 30000, 'price' => 22]]]]),
    'drills' => new Method(['connectionId' => $drills->id, 'name' => 'Courier (failure drills)', 'handle' => 'promoDrills', 'serviceCode' => 'standard',
        'pricing' => ['mode' => 'flat', 'flatRate' => 6]]),
];
if ($store->id) {
    $methods['store'] = new Method(['connectionId' => $store->id, 'name' => 'Collect from the shop', 'handle' => 'promoStore', 'serviceCode' => array_key_first($store->getCarrierClass()::services()),
        'requiresPoint' => false, 'pricing' => ['mode' => 'flat', 'flatRate' => 0]]);
}
foreach ($methods as $key => $method) {
    if (!$plugin->getMethods()->save($method)) {
        fwrite(STDERR, "Could not save method $key: " . json_encode($method->getErrors()) . "\n");
    }
}

$product = new Product(['typeId' => Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0]->id, 'title' => 'Wool cycling jersey (demo)', 'enabled' => true]);
$variant = new Variant(['sku' => 'DEMO-JERSEY-' . substr(md5((string)microtime(true)), 0, 4), 'isDefault' => true, 'weight' => 600, 'length' => 300, 'width' => 220, 'height' => 60]);
$variant->setBasePrice(64);
$product->setVariants([$variant]);
Craft::$app->getElements()->saveElement($product);
Commerce::getInstance()->getCatalogPricing()->generateCatalogPrices([$product->getDefaultVariant()->id]);

/**
 * Re-dates a Mock label: its tracking numbers encode the moment the label was made, so moving that
 * moment back lets the parcel move through the mock's statuses without waiting a day for it.
 */
$backdate = static function(int $shipmentId, int $seconds): void {
    $row = (new craft\db\Query())->from(Table::SHIPMENTS)->where(['id' => $shipmentId])->one();
    $fix = static fn(?string $n) => $n && preg_match('/^MK(\d{10})(.*)$/', $n, $m) ? 'MK' . ((int)$m[1] - $seconds) . $m[2] : $n;
    $when = Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify("-$seconds seconds"));

    Db::update(Table::SHIPMENTS, ['trackingNumber' => $fix($row['trackingNumber']), 'carrierShipmentId' => 'MOCK-' . $fix($row['trackingNumber']), 'labelledAt' => $when, 'dateCreated' => $when], ['id' => $shipmentId]);

    foreach ([Table::PARCELS, Table::LABELS] as $table) {
        foreach ((new craft\db\Query())->select(['id', 'trackingNumber'])->from($table)->where(['shipmentId' => $shipmentId])->all() as $r) {
            Db::update($table, ['trackingNumber' => $fix($r['trackingNumber']), 'dateCreated' => $when], ['id' => $r['id']]);
        }
    }
};

$people = [
    'delivered' => ['Dana Whitfield', '1420 East Blvd', 'Charlotte', 'NC', '28203', 2, 'door', 'none', 26 * 3600 + 900],
    'locker' => ['Marcus Bell', '901 S Kings Dr', 'Charlotte', 'NC', '28204', 1, 'locker', 'none', 9 * 3600],
    'refused' => ['Priya Raman', '77 Unknown Rd', 'Charlotte', 'NC', '28299', 1, 'drills', 'refuse', 0],
    'uncertain' => ['Tom Okafor', '2215 Park Rd', 'Charlotte', 'NC', '28203', 3, 'drills', 'timeout', 0],
];

$orders = [];
$shipments = [];
foreach ($people as $key => [$name, $street, $city, $state2, $zip, $qty, $methodKey, $failMode, $age]) {
    $method = $methods[$methodKey];
    $connection = $plugin->getConnections()->getById($method->connectionId);

    $order = new Order(['storeId' => Commerce::getInstance()->getStores()->getPrimaryStore()->id, 'orderSiteId' => 1]);
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail(strtolower(str_replace(' ', '.', $name)) . '@example.com');
    Craft::$app->getElements()->saveElement($order, false);
    $order->setLineItems([Commerce::getInstance()->getLineItems()->createLineItem($order, $product->getDefaultVariant()->id, [], $qty)]);
    $order->setShippingAddress(['fullName' => $name, 'addressLine1' => $street, 'locality' => $city, 'administrativeArea' => $state2, 'postalCode' => $zip, 'countryCode' => 'US']);
    $order->shippingMethodHandle = $method->getShippingMethodHandle();
    Craft::$app->getElements()->saveElement($order, false);
    $order->markAsComplete();
    $orders[$key] = $order->id;

    if ($method->requiresPoint) {
        $plugin->getSelections()->select($order, $method, 'US-L002');
    }

    $connection->settings['failMode'] = $failMode;
    $plugin->getConnections()->save($connection, false);
    $shipment = $plugin->getLabels()->purchase($order, $plugin->getConnections()->getById($connection->id), $method, ['claimKey' => 'promo-' . $order->id]);
    $shipments[$key] = $shipment->id;

    if ($age > 0 && $shipment->status === 'labelled') {
        $backdate($shipment->id, $age);
        $plugin->getTracking()->refresh([$plugin->getShipments()->getById($shipment->id)]);
    }

    $connection->settings['failMode'] = 'none';
    $plugin->getConnections()->save($connection, false);
}

$ids = [
    'connections' => $connectionIds,
    'methods' => array_map(static fn(Method $m) => $m->id, $methods),
    'orders' => $orders,
    'shipments' => $shipments,
    'statuses' => array_map(static fn(int $id) => $plugin->getShipments()->getById($id)?->status . '/' . $plugin->getShipments()->getById($id)?->trackingStatus, $shipments),
    'product' => $product->id,
];
file_put_contents($state, json_encode($ids));
echo json_encode($ids, JSON_PRETTY_PRINT), "\n";
