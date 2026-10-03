<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\RateResult;
use justinholtweb\carrier\events\RateEvent;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\models\Quote;
use justinholtweb\carrier\Plugin;
use Throwable;

/**
 * What a checkout method costs for a cart.
 *
 * Three rules shape this service:
 *
 * 1. **It fails open.** A carrier that is down, slow or refusing costs the merchant one method's
 *    live price — replaced by the method's fallback price, or the method hidden — never the
 *    checkout.
 * 2. **It memoizes on the cart, not the order number.** The signature covers every line's
 *    weight and dimensions and the destination, so a changed cart is re-priced and an
 *    unchanged one is not (Commerce recalculates a cart several times per request).
 * 3. **Live rates are cached** for `rateCacheMinutes`, because every cart page view would
 *    otherwise be a paid API call to UPS.
 */
class Rates extends Component
{
    /** @event RateEvent after a quote is worked out; adjust or withdraw it */
    public const EVENT_AFTER_QUOTE = 'afterQuote';

    /** @var array<string,Quote> */
    private array $_memo = [];

    public function quote(Order $order, Method $method): Quote
    {
        $signature = $this->signature($order, $method);

        if (isset($this->_memo[$signature])) {
            return $this->_memo[$signature];
        }

        try {
            $quote = $this->compute($order, $method, $signature);
        } catch (Throwable $e) {
            Craft::error("Carrier could not price {$method->handle}: " . $e->getMessage(), 'carrier');
            $quote = $this->fallback($method, Craft::t('carrier', 'Pricing failed.'));
        }

        $event = new RateEvent(['order' => $order, 'method' => $method, 'quote' => $quote]);
        $this->trigger(self::EVENT_AFTER_QUOTE, $event);

        return $this->_memo[$signature] = $event->quote;
    }

    /**
     * Every service a connection offers for an order, priced live. Used by the shipment screen's
     * "compare rates" button, never by checkout.
     */
    public function shop(Order $order, \justinholtweb\carrier\models\Connection $connection, ?array $parcels = null): RateResult
    {
        $carrier = $connection->getCarrier();

        if (!$carrier || !$carrier::capabilities()->has(Feature::RATES)) {
            return RateResult::failed(Craft::t('carrier', 'This carrier has no live rates.'));
        }

        $request = Plugin::getInstance()->getOrders()->buildRequest($order, null, $connection, $parcels, 'preview');
        $request->serviceCode = '';

        return $carrier->rate($request);
    }

    public function clearMemo(): void
    {
        $this->_memo = [];
    }

    private function compute(Order $order, Method $method, string $signature): Quote
    {
        $connection = $method->getConnection();

        if (!$connection || !$connection->enabled || !$connection->getCarrierClass()) {
            return Quote::unavailable(Craft::t('carrier', 'The connection is disabled or its add-on is missing.'));
        }

        $address = $order->getShippingAddress();
        $country = strtoupper((string)$address?->countryCode);
        $units = Plugin::getInstance()->getPacker()->units($order);

        if ($units === []) {
            return Quote::unavailable(Craft::t('carrier', 'Nothing in the cart ships.'));
        }

        $weightG = array_sum(array_column($units, 'weightG'));
        $reason = $this->failsConditions($order, $method, $country, $weightG);

        if ($reason !== null) {
            return Quote::unavailable($reason);
        }

        $freeOver = $method->pricingValue('freeOver');

        if ($freeOver !== null && (float)$order->getItemSubtotal() >= $freeOver) {
            return new Quote(available: true, price: 0.0, source: 'free', weightG: $weightG);
        }

        return match ($method->pricingMode()) {
            Method::PRICING_LIVE => $this->live($order, $method, $signature, $weightG),
            Method::PRICING_TABLE => $this->table($method, $weightG),
            default => new Quote(
                available: true,
                price: $this->round($method, (float)($method->pricingValue('flatRate') ?? 0)),
                source: 'flat',
                weightG: $weightG,
            ),
        };
    }

    private function failsConditions(Order $order, Method $method, string $country, int $weightG): ?string
    {
        $countries = array_filter(array_map('strtoupper', (array)($method->condition('countries') ?? [])));
        $excluded = array_filter(array_map('strtoupper', (array)($method->condition('excludeCountries') ?? [])));

        if ($countries !== [] && ($country === '' || !in_array($country, $countries, true))) {
            return Craft::t('carrier', 'Does not ship to this country.');
        }

        if ($country !== '' && in_array($country, $excluded, true)) {
            return Craft::t('carrier', 'Does not ship to this country.');
        }

        $weightUnit = \craft\commerce\Plugin::getInstance()->getSettings()->weightUnits;
        $min = $method->condition('minWeight');
        $max = $method->condition('maxWeight');

        if ($min !== null && $weightG < Units::toGrams((float)$min, $weightUnit)) {
            return Craft::t('carrier', 'The order is too light for this method.');
        }

        if ($max !== null && (float)$max > 0 && $weightG > Units::toGrams((float)$max, $weightUnit)) {
            return Craft::t('carrier', 'The order is too heavy for this method.');
        }

        $subtotal = (float)$order->getItemSubtotal();
        $minSubtotal = $method->condition('minSubtotal');
        $maxSubtotal = $method->condition('maxSubtotal');

        if ($minSubtotal !== null && $subtotal < (float)$minSubtotal) {
            return Craft::t('carrier', 'The order total is below this method’s minimum.');
        }

        if ($maxSubtotal !== null && (float)$maxSubtotal > 0 && $subtotal > (float)$maxSubtotal) {
            return Craft::t('carrier', 'The order total is above this method’s maximum.');
        }

        return null;
    }

    private function table(Method $method, int $weightG): Quote
    {
        $weightUnit = \craft\commerce\Plugin::getInstance()->getSettings()->weightUnits;

        foreach ($method->table() as $row) {
            if ($weightG <= Units::toGrams($row['maxWeight'], $weightUnit)) {
                return new Quote(available: true, price: $this->round($method, $row['price']), source: 'table', weightG: $weightG);
            }
        }

        return Quote::unavailable(Craft::t('carrier', 'The order is heavier than the weight table goes.'));
    }

    private function live(Order $order, Method $method, string $signature, int $weightG): Quote
    {
        if (!$order->getShippingAddress()) {
            return $this->fallback($method, Craft::t('carrier', 'A shipping address is needed for a live rate.'));
        }

        $minutes = Plugin::getInstance()->getSettings()->rateCacheMinutes;
        $cacheKey = 'carrier:rate:' . $signature;
        $cached = $minutes > 0 ? Craft::$app->getCache()->get($cacheKey) : false;

        if (is_array($cached)) {
            return $this->priced($method, $cached['amount'], $cached['transitDays'], $cached['parcels'], $weightG);
        }

        $connection = $method->getConnection();
        $carrier = $connection->getCarrier();
        $request = Plugin::getInstance()->getOrders()->buildRequest($order, $method, $connection, null, 'rate');
        $result = $carrier->rate($request);

        if (!$result->successful()) {
            return $this->fallback($method, $result->error ?? '');
        }

        $rate = $request->serviceCode !== '' ? $result->forService($request->serviceCode) : $result->cheapest();

        if ($rate === null) {
            return $this->fallback($method, Craft::t('carrier', 'The carrier did not offer this service for this destination.'));
        }

        if ($minutes > 0) {
            Craft::$app->getCache()->set($cacheKey, [
                'amount' => $rate->amount,
                'transitDays' => $rate->transitDays,
                'parcels' => count($request->parcels),
            ], $minutes * 60);
        }

        return $this->priced($method, $rate->amount, $rate->transitDays, count($request->parcels), $weightG);
    }

    private function priced(Method $method, float $amount, ?int $transitDays, int $parcels, int $weightG): Quote
    {
        $price = $amount * (1 + (($method->pricingValue('markupPercent') ?? 0) / 100)) + ($method->pricingValue('markupFixed') ?? 0);

        return new Quote(
            available: true,
            price: $this->round($method, max(0.0, $price)),
            carrierAmount: $amount,
            transitDays: $transitDays,
            source: 'live',
            parcelCount: $parcels,
            weightG: $weightG,
        );
    }

    private function fallback(Method $method, string $reason): Quote
    {
        $fallback = $method->pricingValue('fallbackRate');

        if ($fallback === null) {
            return Quote::unavailable($reason);
        }

        return new Quote(available: true, price: $this->round($method, $fallback), source: 'fallback', reason: $reason);
    }

    /** Round up to the method's step — "always charge in 0.50s" — never down. */
    private function round(Method $method, float $price): float
    {
        $step = $method->pricingValue('roundUpTo');

        if ($step !== null && $step > 0) {
            $price = ceil(round($price / $step, 6)) * $step;
        }

        return round($price, 2);
    }

    /**
     * Everything that can change a price. Notably not the order number, which Commerce memoizes
     * on and which stays the same while the cart under it changes completely.
     */
    public function signature(Order $order, Method $method): string
    {
        $address = $order->getShippingAddress();
        $lines = [];

        foreach ($order->getLineItems() as $lineItem) {
            $lines[] = implode(':', [
                $lineItem->purchasableId, $lineItem->qty, $lineItem->weight,
                $lineItem->length, $lineItem->width, $lineItem->height, $lineItem->salePrice,
            ]);
        }

        sort($lines);

        return md5(json_encode([
            $method->id,
            $method->dateUpdated?->getTimestamp(),
            $method->pricing,
            $method->conditions,
            $lines,
            $address ? [$address->countryCode, $address->postalCode, $address->locality, $address->administrativeArea, $address->organization] : null,
            $order->currency,
        ]));
    }
}
