<?php
/**
 * Prints every registered carrier's capabilities, services and connection fields as JSON, grouped
 * by the add-on package that registers it.
 *
 * The add-on promo decks are built from this, so a slide saying what a carrier does is read out
 * of the carrier's own declaration rather than typed in by hand:
 *
 *   docker exec -w /var/www/html ddev-plugin-testing-web \
 *       php /var/www/craft-carrier/tests/tools/dump-carriers.php > promos/addons/carriers.json
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\Plugin;

$out = [];

foreach (Plugin::getInstance()->getCarriers()->all() as $handle => $class) {
    // justinholtweb\carrierups\carriers\UpsCarrier → "ups"; the gateway's own carriers → "carrier".
    $namespace = explode('\\', $class)[1] ?? 'carrier';
    $package = $namespace === 'carrier' ? 'carrier' : substr($namespace, strlen('carrier'));
    $capabilities = $class::capabilities();

    $out[$package][$handle] = [
        'class' => $class,
        'name' => $class::displayName(),
        'region' => $class::region(),
        'description' => $class::description(),
        'setupUrl' => $class::setupUrl(),
        'capabilities' => $capabilities->toArray(),
        'featureLabels' => array_map(Feature::label(...), $capabilities->features()),
        'services' => $class::services(),
        'fields' => $class::settingsFields(),
    ];
}

ksort($out);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
