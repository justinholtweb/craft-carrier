<?php

namespace justinholtweb\carrier\shipping;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\ShippingRule as CommerceShippingRule;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\models\Quote;
use justinholtweb\carrier\Plugin;

/**
 * The single Commerce shipping rule behind a Carrier method.
 *
 * **It extends Commerce's concrete `ShippingRule`**, because Commerce's shipping adjuster
 * type-hints the class, not the interface; a rule that only implements the interface fatals the
 * first time a cart with a shippable item is recalculated.
 *
 * **The whole price goes in `baseRate`** and every other rate is zero. The adjuster ignores
 * `getPriceForOrder()` when a rule matches and rebuilds the price per line item, and a carrier's
 * quote for a consignment does not divide per line item. Min and max are zero too, because the
 * quote is already final.
 */
class CarrierShippingRule extends CommerceShippingRule
{
    public ?Method $carrierMethod = null;

    public ?Quote $quote = null;

    public function init(): void
    {
        parent::init();

        $this->priority = 0;
        $this->perItemRate = 0;
        $this->weightRate = 0;
        $this->percentageRate = 0;
        $this->minRate = 0;
        $this->maxRate = 0;

        if ($this->carrierMethod !== null) {
            $this->name = $this->carrierMethod->name;
            $this->enabled = $this->carrierMethod->enabled;

            if ($this->carrierMethod->storeId) {
                $this->storeId = $this->carrierMethod->storeId;
            }
        }

        if ($this->quote !== null) {
            $this->baseRate = $this->quote->price;
            $this->description = $this->quote->description();
        }
    }

    public function matchOrder(Order $order): bool
    {
        return $this->_quote($order)?->available ?? false;
    }

    public function getIsEnabled(): bool
    {
        return (bool)$this->carrierMethod?->enabled;
    }

    /**
     * Lands on the order's shipping adjustment, which is where anyone reconciling an order against
     * a carrier invoice looks first.
     */
    public function getOptions(): array
    {
        return [
            'carrierMethod' => $this->carrierMethod?->handle,
            'carrierSource' => $this->quote?->source,
            'carrierAmount' => $this->quote?->carrierAmount,
            'carrierParcels' => $this->quote?->parcelCount,
        ];
    }

    public function getPercentageRate(?int $shippingCategoryId = null): float
    {
        return 0.0;
    }

    public function getPerItemRate(?int $shippingCategoryId = null): float
    {
        return 0.0;
    }

    public function getWeightRate(?int $shippingCategoryId = null): float
    {
        return 0.0;
    }

    public function getBaseRate(): float
    {
        return $this->quote?->price ?? 0.0;
    }

    public function getMaxRate(): float
    {
        return 0.0;
    }

    public function getMinRate(): float
    {
        return 0.0;
    }

    public function getDescription(): string
    {
        return $this->quote?->description() ?: ($this->carrierMethod?->name ?? Craft::t('carrier', 'Shipping'));
    }

    /**
     * Keeps the adjustment's source snapshot readable rather than an object graph.
     */
    public function fields(): array
    {
        return [
            'name',
            'description',
            'baseRate',
            'method' => fn() => $this->carrierMethod?->handle,
            'options' => fn() => $this->getOptions(),
        ];
    }

    private function _quote(Order $order): ?Quote
    {
        if ($this->quote === null && $this->carrierMethod !== null) {
            $this->quote = Plugin::getInstance()->getRates()->quote($order, $this->carrierMethod);
        }

        return $this->quote;
    }
}
