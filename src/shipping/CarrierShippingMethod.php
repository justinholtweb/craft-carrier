<?php

namespace justinholtweb\carrier\shipping;

use Craft;
use craft\commerce\base\ShippingMethod;
use craft\commerce\base\ShippingRuleInterface;
use craft\commerce\elements\Order;
use Illuminate\Support\Collection;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\Plugin;

/**
 * How a Carrier method appears to Commerce at checkout.
 *
 * It owns no pricing: every number comes from the `Quote` the rates service worked out for this
 * cart. See `CarrierShippingRule` for why the price is reported the way it is.
 */
class CarrierShippingMethod extends ShippingMethod
{
    public ?Method $method = null;

    public function getType(): string
    {
        return Craft::t('carrier', 'Carrier');
    }

    public function getId(): ?int
    {
        return null;
    }

    public function getName(): string
    {
        return $this->method?->name ?? Craft::t('carrier', 'Shipping');
    }

    public function getHandle(): string
    {
        return $this->method?->getShippingMethodHandle() ?? 'carrier';
    }

    public function getCpEditUrl(): string
    {
        return $this->method?->getCpEditUrl() ?? '';
    }

    public function getIsEnabled(): bool
    {
        return (bool)$this->method?->enabled;
    }

    /**
     * @return Collection<ShippingRuleInterface>
     */
    public function getShippingRules(): Collection
    {
        return $this->method ? collect([new CarrierShippingRule(['carrierMethod' => $this->method])]) : collect();
    }

    public function getPriceForOrder(Order $order): float
    {
        return $this->method ? Plugin::getInstance()->getRates()->quote($order, $this->method)->price : 0.0;
    }

    public function matchOrder(Order $order): bool
    {
        if ($this->method === null) {
            return false;
        }

        // A completed order keeps its method, so the CP and the receipt can still name it after
        // the merchant changes the method's rules.
        if ($order->isCompleted && $order->shippingMethodHandle === $this->getHandle()) {
            return true;
        }

        return Plugin::getInstance()->getRates()->quote($order, $this->method)->available;
    }

    /**
     * Commerce memoizes the matching rule on the order *number*, which would answer a changed cart
     * from a stale price. The rates service memoizes on the whole cart, so ask it.
     */
    public function getMatchingShippingRule(Order $order): ?ShippingRuleInterface
    {
        if ($this->method === null || !$this->matchOrder($order)) {
            return null;
        }

        return new CarrierShippingRule([
            'carrierMethod' => $this->method,
            'quote' => Plugin::getInstance()->getRates()->quote($order, $this->method),
        ]);
    }
}
