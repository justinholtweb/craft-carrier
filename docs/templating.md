---
title: Templating and events
slug: templating
order: 75
summary: The craft.carrier Twig variable for checkout and account pages, and the PHP events for changing requests, prices, purchases and notifications.
---

# Templating and events

## `craft.carrier`

Everything a storefront template needs: the pickup-point picker for checkout, and shipments and
tracking for order-confirmation and account pages.

| | Returns |
|---|---|
| `shipments(order, includeReturns = false)` | The order's labelled shipments, oldest first. Refused, uncertain and voided ones are left out; so are returns, unless you ask |
| `events(shipment)` | The shipment's tracking history, newest first |
| `selection(order)` | The pickup point chosen for the order, or `null` |
| `method(order)` | The Carrier checkout method the order is on, or `null` |
| `pointMethods(order = null)` | Enabled methods on the order's store that deliver to a pickup point |
| `needsPoint(order)` | `true` when the order is on a pickup-point method with no point chosen |
| `pointPicker(order, options = {})` | The drop-in picker. Renders nothing unless the order is on a pickup-point method |
| `statusLabel(status)` | A tracking status's label: `inTransit` → "In transit" |
| `statusColor(status)` | A Craft status colour for it: `green`, `blue`, `orange`, `red`, `light`, `grey` |

### Shipments and tracking

```twig
{% set shipments = craft.carrier.shipments(order) %}

{% for shipment in shipments %}
    <section>
        <h3>{{ shipment.carrierName }} {{ shipment.serviceName }}</h3>
        <p>
            {% if shipment.trackingUrl %}
                <a href="{{ shipment.trackingUrl }}" rel="noopener">{{ shipment.trackingNumber }}</a>
            {% else %}
                {{ shipment.trackingNumber }}
            {% endif %}
            — {{ shipment.trackingStatusLabel }}
        </p>

        {% if shipment.deliveredAt %}
            <p>Delivered {{ shipment.deliveredAt|date('j M Y') }}</p>
        {% elseif shipment.estimatedDelivery %}
            <p>Expected {{ shipment.estimatedDelivery|date('j M Y') }}</p>
        {% endif %}

        <ol>
            {% for event in craft.carrier.events(shipment) %}
                <li>
                    {{ event.occurredAt ? event.occurredAt|datetime('short') }}
                    {{ event.statusLabel }}: {{ event.description }}
                    {{ event.location }}
                </li>
            {% endfor %}
        </ol>
    </section>
{% else %}
    <p>Not shipped yet.</p>
{% endfor %}
```

Useful shipment properties:

| | |
|---|---|
| `trackingNumber` | The first parcel's number. `trackingNumbers()` returns one per parcel |
| `trackingUrl` | The carrier's public tracking page, or `null` |
| `trackingStatus` | One of the [tracking statuses](/plugins/craft-carrier/docs/tracking#the-statuses) |
| `trackingStatusLabel` | Its label |
| `trackingStatusText` | The carrier's own latest wording |
| `carrierName`, `serviceName` | "GLS", "GLS ParcelShop" |
| `labelledAt`, `shippedAt`, `deliveredAt`, `estimatedDelivery` | Dates, or `null` |
| `point` | The pickup point it was sent to, as a hash (`name`, `street`, `city`, `postcode`, `hours`…), or empty |
| `codAmount`, `codCurrency` | Cash on delivery, if any |
| `isReturn` | Whether it is a return label |

Each event from `events()` has `occurredAt` (a UTC `DateTime`, or `null`), `status`,
`statusLabel`, `description` and `location`.

### The pickup point

```twig
{# Checkout shipping step #}
{{ craft.carrier.pointPicker(cart) }}

{# Order summary #}
{% set selection = craft.carrier.selection(cart) %}
{% if selection %}
    <p>Collect from: {{ selection.getLabel() }}</p>
    {% if selection.point.hours ?? false %}<p>{{ selection.point.hours }}</p>{% endif %}
{% endif %}

{# Payment step #}
{% if craft.carrier.needsPoint(cart) %}
    <p>Choose a pickup point before paying.</p>
{% endif %}
<button type="submit" {{ craft.carrier.needsPoint(cart) ? 'disabled' }}>Pay</button>
```

`pointMethods()` is handy for a shipping-method list that shows which options need a point:

```twig
{% set pointHandles = craft.carrier.pointMethods(cart)|map(m => m.shippingMethodHandle) %}

{% for handle, option in cart.availableShippingMethodOptions %}
    <label>
        <input type="radio" name="shippingMethodHandle" value="{{ handle }}">
        {{ option.name }} — {{ option.priceAsCurrency }}
        {% if handle in pointHandles %}<small>Collect from a locker or shop</small>{% endif %}
    </label>
{% endfor %}
```

A Carrier method's handle in Commerce is `carrier-` followed by the method's own handle.

See [Pickup points](/plugins/craft-carrier/docs/pickup-points) for the picker's options, overriding its template and the
endpoints behind it.

## PHP events

All events are standard Yii events. Register them in a module's or plugin's `init()`.

### Changing the shipment request — `Orders::EVENT_AFTER_BUILD_REQUEST`

There is one place a Commerce order becomes a shipment request, and rates, labels, the CP preview
and the console all go through it. This event fires after the request is built and before anyone
uses it: the place to add a gate code, change the reference, adjust a parcel or set a
carrier-specific option.

```php
use justinholtweb\carrier\events\ShipmentRequestEvent;
use justinholtweb\carrier\services\Orders;
use yii\base\Event;

Event::on(Orders::class, Orders::EVENT_AFTER_BUILD_REQUEST, function(ShipmentRequestEvent $event) {
    $request = $event->request;

    // Print the delivery note on the label.
    $note = $event->order->getFieldValue('deliveryNote');
    if ($note) {
        $request->instructions = mb_substr($note, 0, 60);
    }

    // Our own reference format.
    $request->reference = 'WEB-' . $event->order->reference;
});
```

The event has `order`, `connection`, `method` (may be `null`), `request` and `purpose` — `rate`,
`label` or `preview` — so you can change only what is bought, not what is quoted. Weights are in
grams and dimensions in millimetres on the request, whatever the store's units.

### Adjusting a price — `Rates::EVENT_AFTER_QUOTE`

Fires after a method has been priced for a cart. Change the price, or withdraw the method.

```php
use justinholtweb\carrier\events\RateEvent;
use justinholtweb\carrier\services\Rates;
use yii\base\Event;

Event::on(Rates::class, Rates::EVENT_AFTER_QUOTE, function(RateEvent $event) {
    $quote = $event->quote;

    // No Saturday courier for wholesale customers.
    if ($event->method->handle === 'ups-saturday' && $event->order->getCustomer()?->isInGroup('wholesale')) {
        $quote->available = false;
        $quote->reason = 'Not offered to wholesale accounts.';
    }

    // Round live rates to .95.
    if ($quote->source === 'live') {
        $quote->price = floor($quote->price) + 0.95;
    }
});
```

The quote has `available`, `price`, `carrierAmount` (the carrier's own figure before markup, for live
rates), `transitDays`, `source` (`live`, `flat`, `table`, `free` or `fallback`), `reason`,
`parcelCount` and `weightG`. Keep listeners quick and do not call out to other services: this runs
several times per cart page.

### Before and after a label — `Labels` events

`Labels::EVENT_BEFORE_PURCHASE` fires after the purchase is claimed and checked, just before the
carrier is called. Set `isValid` to `false` to stop it; the shipment is marked refused, and can be
retried later.

```php
use justinholtweb\carrier\events\LabelEvent;
use justinholtweb\carrier\services\Labels;
use yii\base\Event;

Event::on(Labels::class, Labels::EVENT_BEFORE_PURCHASE, function(LabelEvent $event) {
    // Never ship an order that is still under fraud review.
    if ($event->shipment->getOrder()?->getFieldValue('fraudHold')) {
        $event->isValid = false;
    }
});

Event::on(Labels::class, Labels::EVENT_AFTER_PURCHASE, function(LabelEvent $event) {
    if ($event->result?->success) {
        // Tell the warehouse system.
    }
});

Event::on(Labels::class, Labels::EVENT_AFTER_VOID, function(LabelEvent $event) {
    // $event->shipment was voided, through the API or by hand.
});
```

`EVENT_AFTER_PURCHASE` fires whatever the carrier said: check `$event->result->success`, or the
shipment's `status` (`labelled`, `failed` or `uncertain`). The event carries `shipment`, `request` and
`result`.

### Tracking changes — `Tracking::EVENT_STATUS_CHANGED`

Fires when a shipment's tracking status changes — not for every poll. The event has `shipment`,
`tracking` (what the carrier reported), `previousStatus` and `newEvents`.

```php
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\events\TrackingUpdateEvent;
use justinholtweb\carrier\services\Tracking;
use yii\base\Event;

Event::on(Tracking::class, Tracking::EVENT_STATUS_CHANGED, function(TrackingUpdateEvent $event) {
    if ($event->shipment->trackingStatus === TrackingStatus::EXCEPTION) {
        Craft::warning("Parcel {$event->shipment->trackingNumber} has a problem: {$event->shipment->trackingStatusText}", 'shipping');
    }
});
```

See [Tracking](/plugins/craft-carrier/docs/tracking#notifications-on-every-status-change) for a customer email example.

### Checkout methods — `Methods` events

`Methods::EVENT_BEFORE_SAVE_METHOD` and `Methods::EVENT_AFTER_SAVE_METHOD` fire around saving a
checkout method. The event has `method` and `isNew`; set `isValid` to `false` before the save to
stop it.

```php
use justinholtweb\carrier\events\MethodEvent;
use justinholtweb\carrier\services\Methods;
use yii\base\Event;

Event::on(Methods::class, Methods::EVENT_BEFORE_SAVE_METHOD, function(MethodEvent $event) {
    // House rule: every live-rate method needs a fallback price.
    $method = $event->method;
    if ($method->pricingMode() === 'live' && $method->pricingValue('fallbackRate') === null) {
        $method->addError('pricing', 'Set a fallback price.');
        $event->isValid = false;
    }
});
```

### Registering a carrier — `Carriers::EVENT_REGISTER_CARRIERS`

How an add-on adds its carriers. See [Writing a carrier](/plugins/craft-carrier/docs/writing-a-carrier).

```php
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\carrier\services\Carriers;
use yii\base\Event;

Event::on(Carriers::class, Carriers::EVENT_REGISTER_CARRIERS, function(RegisterComponentTypesEvent $event) {
    $event->types[] = MyCarrier::class;
});
```
