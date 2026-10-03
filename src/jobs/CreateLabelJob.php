<?php

namespace justinholtweb\carrier\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use justinholtweb\carrier\models\Shipment;
use justinholtweb\carrier\Plugin;
use justinholtweb\carrier\services\Labels;

/**
 * Buy the label for one order, for the bulk action and automatic labelling.
 *
 * Safe to retry by construction: the purchase is claimed under `order:{id}:{connection}`, so a
 * retried job finds the claim and stops. It never throws for a carrier refusal — that would make
 * the queue retry a request the carrier will refuse identically — it leaves the shipment on the
 * Problems screen instead.
 */
class CreateLabelJob extends BaseJob
{
    public int $orderId = 0;

    /** The connection to ship with; null means the order's own Carrier method's connection. */
    public ?int $connectionId = null;

    public ?string $serviceCode = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $order = Order::find()->id($this->orderId)->status(null)->one();

        if (!$order) {
            return;
        }

        $method = $plugin->getMethods()->getByShippingMethodHandle($order->shippingMethodHandle);
        $connection = $this->connectionId
            ? $plugin->getConnections()->getById($this->connectionId)
            : $method?->getConnection();

        if (!$connection) {
            Craft::warning("Carrier: order {$order->reference} has no Carrier method and no connection was chosen.", 'carrier');

            return;
        }

        if ($method && $method->connectionId !== $connection->id) {
            $method = null;
        }

        $shipment = $plugin->getLabels()->purchase($order, $connection, $method, array_filter([
            'claimKey' => Labels::orderClaimKey($order, $connection),
            'serviceCode' => $this->serviceCode ?? $method?->serviceCode ?? $this->defaultService($connection),
        ], static fn($v) => $v !== null));

        $this->setProgress($queue, 1, $shipment->getStatusLabel());

        // A throttled purchase left nothing behind and is worth one more go later.
        if ($shipment->status === Shipment::STATUS_FAILED && str_contains((string)$shipment->error, 'throttl')) {
            Craft::$app->getQueue()->delay(300)->push(new self([
                'orderId' => $this->orderId,
                'connectionId' => $this->connectionId,
                'serviceCode' => $this->serviceCode,
            ]));
        }
    }

    private function defaultService(\justinholtweb\carrier\models\Connection $connection): ?string
    {
        $class = $connection->getCarrierClass();
        $services = $class ? array_keys($class::services()) : [];

        return $services[0] ?? null;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('carrier', 'Creating a shipping label for order {id}', ['id' => $this->orderId]);
    }
}
