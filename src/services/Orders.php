<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\events\ShipmentRequestEvent;
use justinholtweb\carrier\helpers\Addresses;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\CustomsItem;
use justinholtweb\carrier\models\canonical\Parcel;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\Plugin;
use Throwable;

/**
 * The only place a Commerce order becomes a `ShipmentRequest`.
 *
 * Rates at checkout, the shipment screen's preview, the bulk "Create labels" action and the
 * console all come through `buildRequest()`, so the request the merchant previewed is byte for
 * byte the request the carrier is sent. Plugins adjust it through one event, not five.
 */
class Orders extends Component
{
    /** @event ShipmentRequestEvent after a request is built, before anyone uses it */
    public const EVENT_AFTER_BUILD_REQUEST = 'afterBuildRequest';

    /**
     * @param Parcel[]|null $parcels parcels the merchant edited on the shipment screen; null packs
     * @param string $purpose `rate`, `label` or `preview`, passed to the event
     */
    public function buildRequest(
        Order $order,
        ?Method $method = null,
        ?Connection $connection = null,
        ?array $parcels = null,
        string $purpose = 'label',
    ): ShipmentRequest {
        $connection ??= $method?->getConnection();
        $class = $connection?->getCarrierClass();
        $capabilities = $class ? $class::capabilities() : null;
        $settings = Plugin::getInstance()->getSettings();

        $email = (string)($order->getEmail() ?? '');
        $shipTo = Addresses::fromCraft($order->getShippingAddress(), $email, $settings->phoneField, $settings->houseNumberField);
        $shipTo->phone = Addresses::normalisePhone($shipTo->phone, $settings->defaultCallingCode);

        $request = new ShipmentRequest([
            'shipFrom' => $this->shipFrom($order, $connection),
            'shipTo' => $shipTo,
            'parcels' => $parcels ?? Plugin::getInstance()->getPacker()->pack($order, $method, $capabilities?->getMaxParcelWeightG() ?? 0),
            'serviceCode' => $method?->serviceCode ?? '',
            'reference' => (string)$order->reference ?: (string)$order->getShortNumber(),
            'reference2' => (string)$order->getShortNumber(),
            'currency' => strtoupper((string)$order->currency),
            'declaredValue' => round((float)$order->getItemSubtotal(), 2),
            'labelFormat' => $connection?->labelFormat ?? '',
            'shipDate' => new \DateTime('today', new \DateTimeZone(Craft::$app->getTimeZone())),
            'orderId' => $order->id,
        ]);

        if ($method !== null) {
            $request->accessorials = $method->accessorials();
        }

        // Freight prices handling units by class: give every unit the method's defaults where the
        // packer (which knows nothing of NMFC) left them blank.
        if ($capabilities?->has(Feature::FREIGHT)) {
            foreach ($request->parcels as $parcel) {
                $parcel->freightClass = $parcel->freightClass ?: (string)($method?->packing['freightClass'] ?? '');
                $parcel->packagingType = $parcel->packagingType ?: (string)($method?->packing['packagingType'] ?? 'pallet');
            }
        }

        $selection = $order->id ? Plugin::getInstance()->getSelections()->getForOrder($order->id) : null;

        if ($selection && (!$connection || $selection->connectionId === $connection->id)) {
            $request->pointId = $selection->pointId;
            $request->point = $selection->point ? new \justinholtweb\carrier\models\canonical\PickupPoint($selection->point) : null;
        }

        if ($capabilities?->has(Feature::COD) && $this->isCod($order)) {
            $request->codAmount = round(max(0.0, (float)$order->getOutstandingBalance()), 2);
            $request->codCurrency = $request->currency;
            $request->codReference = $request->reference;
        }

        if ($request->isInternational()) {
            $request->customsItems = $this->customsItems($order);
        }

        $request->instructions = trim((string)($order->message ?? ''));

        $event = new ShipmentRequestEvent([
            'order' => $order,
            'connection' => $connection,
            'method' => $method,
            'request' => $request,
            'purpose' => $purpose,
        ]);
        $this->trigger(self::EVENT_AFTER_BUILD_REQUEST, $event);

        return $event->request;
    }

    /**
     * Whether the customer is paying the courier. True when the order's gateway is one the
     * merchant listed as cash on delivery.
     */
    public function isCod(Order $order): bool
    {
        $handles = Plugin::getInstance()->getSettings()->codGateways;

        if ($handles === []) {
            return false;
        }

        try {
            $gateway = $order->getGateway();
        } catch (Throwable) {
            return false;
        }

        return $gateway !== null && in_array($gateway->handle, $handles, true);
    }

    /**
     * Where the parcels leave from: the connection's override, else the store's own location.
     */
    public function shipFrom(Order $order, ?Connection $connection): Address
    {
        $override = $connection?->getShipFromAddress();

        if ($override) {
            return $override;
        }

        $address = null;

        try {
            $address = \craft\commerce\Plugin::getInstance()
                ->getStoreSettings()
                ->getStoreSettingsById($order->storeId ?? $order->getStore()->id)
                ->getLocationAddress();
        } catch (Throwable $e) {
            Craft::warning('Carrier could not read the store location: ' . $e->getMessage(), 'carrier');
        }

        $settings = Plugin::getInstance()->getSettings();
        $from = Addresses::fromCraft($address, '', $settings->phoneField, $settings->houseNumberField);
        $from->residential = false;

        if ($from->company === '') {
            $from->company = (string)Craft::$app->getSites()->getPrimarySite()->getName();
        }

        return $from;
    }

    /**
     * @return CustomsItem[]
     */
    private function customsItems(Order $order): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $weightUnit = \craft\commerce\Plugin::getInstance()->getSettings()->weightUnits;
        $items = [];

        foreach ($order->getLineItems() as $lineItem) {
            if (method_exists($lineItem, 'getIsShippable') && !$lineItem->getIsShippable()) {
                continue;
            }

            $items[] = new CustomsItem([
                'description' => mb_substr((string)$lineItem->getDescription(), 0, 45),
                'quantity' => (int)$lineItem->qty,
                'unitValue' => round((float)$lineItem->salePrice, 2),
                'unitWeightG' => Units::toGrams((float)$lineItem->weight, $weightUnit),
                'hsCode' => $this->purchasableField($lineItem, $settings->hsCodeField),
                'originCountry' => strtoupper($this->purchasableField($lineItem, $settings->originCountryField) ?: $settings->defaultOriginCountry),
                'sku' => (string)$lineItem->getSku(),
            ]);
        }

        return $items;
    }

    /**
     * A custom field off the variant, falling back to its product — merchants put an HS code on
     * whichever one they think of first.
     */
    private function purchasableField(LineItem $lineItem, string $handle): string
    {
        if ($handle === '') {
            return '';
        }

        $purchasable = $lineItem->getPurchasable();

        foreach ([$purchasable, method_exists($purchasable, 'getOwner') ? $purchasable->getOwner() : null] as $element) {
            if ($element === null) {
                continue;
            }

            try {
                $value = $element->getFieldValue($handle);
            } catch (Throwable) {
                continue;
            }

            if (is_object($value) && method_exists($value, '__toString')) {
                $value = (string)$value;
            } elseif (is_object($value) && property_exists($value, 'value')) {
                $value = (string)$value->value;
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
