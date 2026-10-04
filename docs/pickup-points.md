---
title: Pickup points
slug: pickup-points
order: 40
summary: Lockers, parcel shops, post offices and your own stores — where points come from, the storefront picker, and the endpoints behind it.
---

# Pickup points

A pickup point is anywhere a customer collects a parcel instead of having it delivered: a GLS
locker, a BOX NOW locker, a UPS Access Point, a post office, or one of your own shops. Carrier
gives every carrier the same picker, the same storage and the same rule: a customer on a
pickup-point method cannot pay until they have chosen one.

## Where points come from

Each carrier provides points in one of three ways.

| Mode | Carriers | How it works |
|---|---|---|
| **list** | GLS, BOX NOW, Hrvatska pošta, Magyar Posta, Pick up in store | The carrier publishes every point in a country. Carrier syncs the list into its own table and searches it locally |
| **search** | UPS, FedEx, USPS, Pošta Slovenije (opt-in) | The carrier only answers "what is near here". Carrier asks live, and caches each answer for an hour |
| **manual** | DPD EasyShip | The carrier has no point API. You import a CSV of points |

**List mode is the one to prefer**, and Carrier uses it wherever a carrier allows. Checkout never
waits on the carrier, and the picker keeps working when the carrier is down.

### Syncing

List carriers re-sync every `pointsSyncHours` (default 24), queued from ordinary page traffic or
run from cron:

```sh
php craft carrier/points/sync                      # every list connection that is due
php craft carrier/points/sync --connection=gls-hr  # one connection, now
php craft carrier/points/sync --force              # every point connection, due or not
```

**Sync now** on the connection screen does the same for one connection.

A sync covers the countries in the connection's **Pickup point countries** field, or the carrier's
home countries when it is blank. Points the carrier no longer lists are removed. If a sync fails
halfway, yesterday's points are kept — a stale locker list beats an empty picker.

### Importing a CSV

For DPD EasyShip, ask DPD for its parcel-shop and locker ids and import them under **Carrier →
Pickup points → Import from CSV**, or from the console:

```sh
php craft carrier/points/import dpd-hr points.csv
```

Columns are matched by header name, in any order. `id`, `name` and `country` are required.

```csv
id,name,type,street,city,postcode,country,latitude,longitude,hours,cod
HR12345,Tisak Ilica,shop,Ilica 242,Zagreb,10000,HR,45.8143,15.9420,Mon–Sat 7–20,1
HR67890,DPD Pickup paketomat Arena,locker,Avenija Dubrovnik 15,Zagreb,10020,HR,45.7720,15.9420,0–24,0
```

| Column | |
|---|---|
| `id` | The carrier's own id for the point. This is what goes on the label |
| `name` | Shown to the customer |
| `type` | `locker`, `shop`, `postOffice` or `store`. Anything else is read as `shop` |
| `street`, `city`, `postcode` | Shown to the customer, and searched |
| `country` | Two-letter code |
| `latitude`, `longitude` | Needed for "Use my location" and distance sorting |
| `hours` | Free text, shown in the picker |
| `cod` | `0`, `no`, `false` or `n` for a point that does not take cash on delivery. Anything else, or blank, means it does |

Semicolon-separated files work too. In the control panel, **Replace the points imported before**
deletes the previous import first. Imported points are never touched by a sync.

### Browsing and hiding points

**Carrier → Pickup points** lists every stored point, by connection, searchable by name, city,
postcode or id. **Hide** takes a point out of the picker without deleting it — a locker you know is
out of service, say. **Offer** puts it back.

### Pick up in store

The built-in **Pick up in store** carrier uses your **Commerce inventory locations** as its points:
every location with an address becomes a point, the same picker as a GLS locker. Its connection has
two settings:

- **Locations** — comma-separated inventory location handles customers may collect from. Blank
  means every location with an address.
- **Opening hours** — shown in the picker.

Its "label" is a collection slip to stick on the bag on the shelf, with the customer's name and
phone number, the order reference, and the amount to collect at the counter for a cash-on-delivery
order. Store pickups are not tracked.

## Setting up a pickup-point method

1. Make a connection for a carrier that has points, and sync or import them.
2. Make a checkout method on that connection with a pickup-point service, and switch on
   **Delivers to a pickup point**. See [Checkout methods](/plugins/craft-carrier/docs/checkout-methods).
3. Add the picker to your checkout.

## The storefront picker

Add one line to the shipping step of your checkout:

```twig
{{ craft.carrier.pointPicker(cart) }}
```

It renders nothing unless the cart is on a method that delivers to a pickup point, so it is safe to
include unconditionally. Put it where it will show once the customer has chosen a shipping method —
after the shipping-method form, or on the next step.

When it renders, it searches near the cart's shipping postcode straight away. The customer can
search by postcode or town, or press **Use my location**, then pick a point from the list. The choice
is saved to the cart at once; there is no form to submit.

The picker has no dependencies and no build step: a little HTML, a little CSS and vanilla
JavaScript. It filters out points that cannot take the order — a 25 kg cart is not shown a 20 kg
locker, and a cash-on-delivery order is not shown a point that does not take cash.

Options:

```twig
{{ craft.carrier.pointPicker(cart, { limit: 10 }) }}
```

`limit` caps the number of points listed (default 20). Your own template receives the whole
`options` hash, so you can pass anything else it needs.

When a point is chosen, the picker dispatches a bubbling `carrier:point-selected` DOM event with the
point as `detail`, for enabling a button or updating a summary:

```js
document.addEventListener('carrier:point-selected', (e) => {
    document.querySelector('#pay').disabled = false;
    console.log(e.detail.name, e.detail.id);
});
```

### Overriding the template

Copy `vendor/justinholtweb/craft-carrier/src/templates/_frontend/point-picker.twig` to your site's
`templates/_carrier/point-picker.twig` and change it however you like. Carrier uses yours whenever it
exists. It receives:

| Variable | |
|---|---|
| `cart` | The current order |
| `method` | The Carrier checkout method the cart is on |
| `selection` | The point already chosen, or `null`. `selection.getLabel()` is a one-line description |
| `options` | What you passed to `pointPicker()`, merged over `{ map: false, limit: 20 }` |

To show the points on a map, override the template and draw them with the map library you already
use — every point in the search response has `latitude` and `longitude`.

## The endpoints

If you would rather build your own picker, these are what the built-in one uses.

### Search

```
GET /actions/carrier/points/search?method=<method handle>&postcode=10000
Accept: application/json
```

| Parameter | |
|---|---|
| `method` | Required. The handle of an enabled checkout method that delivers to a pickup point |
| `postcode` or `city` | Search term. Defaults to the cart's shipping address |
| `lat` and `lng` | Search around a position instead |
| `country` | Two-letter code. Defaults to the cart's shipping country |
| `radius` | Kilometres around `lat`/`lng`, 1–50, default 10 |
| `limit` | 1–50, default 20 |

The request must send `Accept: application/json`. The endpoint is anonymous and read-only, and it
only answers for an enabled pickup-point method, so it cannot be used to list arbitrary
connections' points.

```json
{
    "points": [
        {
            "id": "HR12345",
            "type": "locker",
            "name": "GLS Paketomat Ilica",
            "street": "Ilica 242",
            "city": "Zagreb",
            "postcode": "10000",
            "countryCode": "HR",
            "latitude": 45.8143,
            "longitude": 15.942,
            "hours": "0–24",
            "distanceKm": 0.4,
            "label": "GLS Paketomat Ilica, Ilica 242, 10000 Zagreb"
        }
    ],
    "selected": null
}
```

`selected` is the id already chosen for this cart, if any. `distanceKm` is set when you searched by
position.

### Select

```
POST /actions/carrier/points/select
method=<method handle>&pointId=HR12345&CRAFT_CSRF_TOKEN=…
```

It acts on **the current session's cart only** — never an order id from the request, which would
let anyone change where someone else's parcel goes. With `Accept: application/json` it answers
`{"success": true, "pointId": "…", "label": "…"}`, or a 400 with `{"success": false, "error": "…"}`;
without it, it behaves like any Craft form action, with a flash message and a redirect.

Only a point Carrier knows can be chosen: a stored point for list and manual carriers, or, for a
search carrier, one the search endpoint showed this session. A forged id is refused.

The chosen point is stored against the order, and its details are copied onto the shipment when the
label is bought, so a point that later disappears from the carrier's list still prints correctly.

## The payment guard

With `requirePointOnPayment` on (the default), a customer on a pickup-point method who has not
chosen a point is stopped before any money moves, with the message "Choose a pickup point for your
delivery before paying." It is raised as a payment error, so it shows wherever your checkout shows
payment errors.

To disable the Pay button rather than wait for the error:

```twig
<button type="submit" {{ craft.carrier.needsPoint(cart) ? 'disabled' }}>Pay</button>
```

The same check runs before a label is bought, so an order that somehow reached completion without
a point is refused at label time with a clear message, not sent to the wrong place.

The guard only applies to storefront payments. An order paid in the control panel is not stopped.
