---
title: Tracking
slug: tracking
order: 60
summary: How Carrier follows a parcel until it arrives, the status vocabulary, the order write-back, and hooking notifications onto status changes.
---

# Tracking

Every labelled shipment is followed until it is delivered, returned or cancelled, or until it is
too old to bother with. Polling, webhooks, the **Refresh tracking** button and the console all end in
the same place, so the same rules hold whichever way a scan arrives.

## The statuses

Every carrier's tracking codes are mapped onto one small vocabulary. A merchant needs to know whether
a parcel has left, is moving, is waiting to be collected, has arrived or has a problem — not the
difference between "Departed from facility" and "Arrived at facility".

| Status | Label | |
|---|---|---|
| `preTransit` | Label created | Bought, not yet scanned by the carrier |
| `inTransit` | In transit | |
| `outForDelivery` | Out for delivery | |
| `readyForPickup` | Ready for pickup | Waiting at a locker, shop or post office |
| `delivered` | Delivered | Final |
| `exception` | Exception | Damaged, refused, address problem, held at customs |
| `returned` | Returned to sender | Final |
| `cancelled` | Cancelled | Final. Also what a voided label becomes |
| `unknown` | Unknown | |

The carrier's own wording is kept too: the shipment shows the carrier's latest status text, and each
scan in the history keeps the carrier's description, location and raw code.

Two rules apply to every update:

- **A status never moves backwards.** An "in transit" scan arriving after "delivered" — late
  webhooks and batched carrier feeds do this — adds the scan to the history and changes nothing else.
- **An exception always shows**, whatever came before it.

Each scan is stored once. A poll and a webhook reporting the same scan at the same moment produce
one row, because the database refuses the second.

## The polling schedule

| | |
|---|---|
| First poll | `trackingIntervalHours` (default 4) after the label is bought |
| In motion | Every `trackingIntervalHours` |
| Still "Label created" after two days | Three times less often — it is usually still on the shelf |
| Older than ten days | Four times less often |
| Delivered, returned or cancelled | Stops |
| Older than `trackingMaxDays` (default 45) | Stops, delivered or not |

Carrier batches tracking numbers per connection as far as each carrier allows — 100 at a time for
GLS, 30 for FedEx, one at a time for BOX NOW.

## What drives it: page views or cron

Craft has no scheduler. By default, an ordinary page view — any non-AJAX GET request, front end or
control panel — checks at most once every ten minutes whether anything is due, and if so pushes a
tracking job (and any pickup-point syncs that are due) onto the queue. The request that triggered it
pays for one cache read.

That is fine for a shop with steady traffic. For a quiet one, or to know exactly when it runs, use
cron:

```cron
*/30 * * * *  cd /path/to/site && php craft carrier/tracking/refresh
15 3 * * *    cd /path/to/site && php craft carrier/points/sync
```

`carrier/tracking/refresh` polls every shipment whose next check is due (up to 500 per run;
`--limit` changes that). Running it from cron and having page views queue jobs as well does no harm.
A due shipment is polled once, and a duplicate scan is stored once.

Either way, the queue has to be running for the page-view route. If tracking has stopped moving, the
queue is the first thing to check.

## By hand

- **Refresh tracking** on a shipment, on the order's shipment screen, polls it now.
- `php craft carrier/tracking/show <tracking number>` polls one shipment and prints its history.

## Webhooks

A carrier that pushes tracking shows a **Tracking webhook URL** on its connection. Paste it into the
carrier's portal and updates arrive as they happen. The carrier's add-on checks the signature;
a push can only update shipments bought on that connection. Polling continues as a safety net.

None of the carrier add-ons declares webhooks yet. BOX NOW, for one, can push tracking, but it
documents no way to verify that a push really came from BOX NOW, so the add-on polls instead.
The Mock carrier has a signed webhook if you want to try the flow.

## Writing back to the order

| Setting | Effect |
|---|---|
| `orderStatusOnLabel` | Moves the order to this status when a label is bought |
| `orderStatusOnDelivered` | Moves the order to this status when **every** shipment on it has been delivered |

"Every" matters. An order shipped as three parcels on three labels moves to delivered when the third
arrives, not the first. Return labels and voided shipments do not count.

Each change writes a message to the order history and sends whatever emails that status is set up
to send — which is the simplest way to tell a customer their parcel has arrived.

The order index gets a **Tracking** column: add it from the index's column settings to see each
order's tracking status at a glance.

## Notifications on every status change

For anything more than order-status emails — "out for delivery" texts, "ready for collection at your
locker" emails, a Slack message on every exception — listen for the tracking event. It fires only
when a shipment's status actually changes:

```php
use justinholtweb\carrier\events\TrackingUpdateEvent;
use justinholtweb\carrier\services\Tracking;
use yii\base\Event;

Event::on(Tracking::class, Tracking::EVENT_STATUS_CHANGED, function(TrackingUpdateEvent $event) {
    $shipment = $event->shipment;

    if ($shipment->trackingStatus === 'readyForPickup' && ($order = $shipment->getOrder())) {
        Craft::$app->getMailer()->compose()
            ->setTo($order->getEmail())
            ->setSubject("Your order {$order->reference} is ready to collect")
            ->setTextBody('It is waiting at ' . ($shipment->point['name'] ?? 'your pickup point') . '.')
            ->send();
    }
});
```

The event carries `shipment`, `tracking` (what the carrier said), `previousStatus` and `newEvents`
(how many new scans arrived). See [Templating and events](/plugins/craft-carrier/docs/templating).

## Showing tracking to customers

```twig
{% for shipment in craft.carrier.shipments(order) %}
    <p>
        {{ shipment.carrierName }}:
        <a href="{{ shipment.trackingUrl }}">{{ shipment.trackingNumber }}</a> —
        {{ shipment.trackingStatusLabel }}
    </p>
{% endfor %}
```

`trackingUrl` is the carrier's public tracking page, where the carrier has one. See
[Templating and events](/plugins/craft-carrier/docs/templating) for the full history.
