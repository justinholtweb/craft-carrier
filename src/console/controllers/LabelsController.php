<?php

namespace justinholtweb\carrier\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\carrier\models\Shipment;
use justinholtweb\carrier\Plugin;
use justinholtweb\carrier\services\Labels;
use yii\console\ExitCode;

/**
 * Labels from the command line.
 */
class LabelsController extends Controller
{
    /** @var string|null Connection handle; defaults to the order's own Carrier method. */
    public ?string $connection = null;

    /** @var string|null Service code; defaults to the method's. */
    public ?string $service = null;

    /** @var bool Print the request that would be sent, and buy nothing. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['connection', 'service', 'dryRun']);
    }

    /**
     * Buy the label for an order, by reference or id. Claimed per order, so running it twice
     * buys one label.
     */
    public function actionCreate(string $order): int
    {
        $plugin = Plugin::getInstance();
        $element = ctype_digit($order)
            ? Order::find()->id((int)$order)->status(null)->one()
            : Order::find()->reference($order)->status(null)->one();

        if (!$element) {
            $this->stderr("No such order.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $method = $plugin->getMethods()->getByShippingMethodHandle($element->shippingMethodHandle);
        $connection = $this->connection ? $plugin->getConnections()->getByHandle($this->connection) : $method?->getConnection();

        if (!$connection) {
            $this->stderr("The order is not on a Carrier method; pass --connection.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($method && $method->connectionId !== $connection->id) {
            $method = null;
        }

        if ($this->dryRun) {
            $request = $plugin->getOrders()->buildRequest($element, $method, $connection, null, 'preview');

            if ($this->service) {
                $request->serviceCode = $this->service;
            }

            $this->stdout(json_encode($request->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

            return ExitCode::OK;
        }

        $shipment = $plugin->getLabels()->purchase($element, $connection, $method, array_filter([
            'claimKey' => Labels::orderClaimKey($element, $connection),
            'serviceCode' => $this->service ?? $method?->serviceCode,
        ]));

        if ($shipment->status !== Shipment::STATUS_LABELLED) {
            $this->stderr("{$shipment->getStatusLabel()}: {$shipment->error}\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Labelled: {$shipment->trackingNumber}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
