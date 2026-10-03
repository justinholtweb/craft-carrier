<?php
/**
 * Creates (or with `teardown`, removes) the fixtures the CP smoke test browses: a Mock connection,
 * a flat method, a labelled order, a refused one and synced points. Prints ids as JSON.
 */
$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\Plugin;

if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}
yii\base\Event::off(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER);

$plugin = Plugin::getInstance();
$state = '/var/www/craft-carrier/tests/integration/.smoke-state.json';

// The harness admin's password stops validating at intervals; reset it through the element.
$admin = craft\elements\User::find()->id(1)->status(null)->one();
$admin->newPassword = 'claudepassword';
Craft::$app->getElements()->saveElement($admin);

if (($argv[1] ?? '') === 'teardown') {
    $ids = json_decode((string)@file_get_contents($state), true) ?: [];

    foreach ($ids['orders'] ?? [] as $id) {
        craft\helpers\Db::delete('{{%carrier_shipments}}', ['orderId' => $id]);
        if ($o = Order::find()->id($id)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($o, true);
        }
    }

    if (!empty($ids['product']) && ($p = Product::find()->id($ids['product'])->status(null)->one())) {
        Craft::$app->getElements()->deleteElement($p, true);
    }

    if (!empty($ids['connection']) && ($c = $plugin->getConnections()->getById($ids['connection']))) {
        craft\helpers\Db::delete('{{%carrier_log}}', ['connectionId' => $c->id]);
        $plugin->getConnections()->delete($c);
    }

    @unlink($state);
    echo "torn down\n";
    exit(0);
}

$suffix = substr(md5((string)microtime(true)), 0, 6);
$connection = new Connection([
    'name' => 'Smoke Mock', 'handle' => "smokemock$suffix", 'carrier' => 'mock', 'enabled' => true,
    'labelFormat' => 'pdf-a6',
    'settings' => ['environment' => 'sandbox', 'apiKey' => 'smoke-secret-123', 'accountNumber' => 'SMOKE', 'failMode' => 'none', 'stageSeconds' => 3600, 'pointCountries' => 'HR'],
    'shipFrom' => ['company' => 'Smoke Store', 'street1' => 'Radnička cesta', 'houseNumber' => '80', 'city' => 'Zagreb', 'postcode' => '10000', 'countryCode' => 'HR'],
]);
$plugin->getConnections()->save($connection);
$method = new Method(['connectionId' => $connection->id, 'name' => 'Smoke flat', 'handle' => "smokeflat$suffix", 'serviceCode' => 'standard', 'pricing' => ['mode' => 'flat', 'flatRate' => 5], 'enabled' => true]);
$plugin->getMethods()->save($method);
$plugin->getPoints()->sync($connection);

$product = new Product(['typeId' => Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0]->id, 'title' => "Smoke $suffix", 'enabled' => true]);
$variant = new Variant(['sku' => "SMOKE-$suffix", 'isDefault' => true, 'weight' => 500]);
$variant->setBasePrice(25);
$product->setVariants([$variant]);
Craft::$app->getElements()->saveElement($product);
Commerce::getInstance()->getCatalogPricing()->generateCatalogPrices([$product->getDefaultVariant()->id]);

$orders = [];
foreach (['none', 'refuse'] as $mode) {
    $order = new Order(['storeId' => Commerce::getInstance()->getStores()->getPrimaryStore()->id, 'orderSiteId' => 1]);
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail('smoke@example.com');
    Craft::$app->getElements()->saveElement($order, false);
    $order->setLineItems([Commerce::getInstance()->getLineItems()->createLineItem($order, $product->getDefaultVariant()->id, [], 2)]);
    $order->setShippingAddress(['fullName' => 'Ana Kovač', 'addressLine1' => 'Ilica 242', 'locality' => 'Zagreb', 'postalCode' => '10000', 'countryCode' => 'HR']);
    $order->shippingMethodHandle = $method->getShippingMethodHandle();
    Craft::$app->getElements()->saveElement($order, false);
    $order->markAsComplete();
    $connection->settings['failMode'] = $mode;
    $plugin->getConnections()->save($connection, false);
    $plugin->getLabels()->purchase($order, $plugin->getConnections()->getById($connection->id), $method, ['claimKey' => 'smoke-' . $order->id]);
    $orders[] = $order->id;
}
$connection->settings['failMode'] = 'none';
$plugin->getConnections()->save($connection, false);

$ids = ['connection' => $connection->id, 'method' => $method->id, 'orders' => $orders, 'product' => $product->id];
file_put_contents($state, json_encode($ids));
echo json_encode($ids), "\n";
