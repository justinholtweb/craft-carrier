# Carrier — plan

**Carrier is a shipping carrier gateway for Craft Commerce 5.** It is a paid host plugin ($149, $129
renewal, single edition) plus a free add-on per carrier, and it follows Erpy's design.
The gateway owns everything that has to be true of every carrier: the parcel model on the order,
packing, live rates at checkout, label purchase and printing, the pickup-point picker, tracking
written back to the order, the log and the control panel. An add-on translates one carrier's API
and does nothing else.

Prompted by [craft-shipper#1](https://github.com/justinholtweb/craft-shipper/issues/1): a merchant
in south-east Europe runs DPD (EasyShip), GLS (MyGLS), the national post's lockers, BOX NOW lockers
and pick-up in store. ShipStation and Sendcloud do not serve that market. They maintain in-house
add-ons on a small gateway and would port them to a public one.

## Decisions (Justin, 2026-10-03)

- **$149 / $129 renewal, single edition.** There is no Lite edition.
- **Build the gateway and every add-on in one pass**, as Erpy was built.
- **National posts:** all of Hrvatska pošta, Pošta Slovenije, Magyar Posta and ELTA, as one
  `posts` package with four drivers. USPS gets its own add-on.
- **Freight:** the major US LTL carriers, called directly: TForce Freight, Old Dominion,
  Estes, XPO, R+L and SAIA, as one `freight` package with six drivers.

## Packages

| Package | Handle | Namespace | Carriers |
|---|---|---|---|
| `justinholtweb/craft-carrier` | `carrier` | `justinholtweb\carrier` | Mock, Store pickup (built in) |
| `justinholtweb/craft-carrier-ups` | `carrier-ups` | `justinholtweb\carrierups` | UPS |
| `justinholtweb/craft-carrier-fedex` | `carrier-fedex` | `justinholtweb\carrierfedex` | FedEx |
| `justinholtweb/craft-carrier-usps` | `carrier-usps` | `justinholtweb\carrierusps` | USPS |
| `justinholtweb/craft-carrier-freight` | `carrier-freight` | `justinholtweb\carrierfreight` | TForce Freight, ODFL, Estes, XPO, R+L, SAIA |
| `justinholtweb/craft-carrier-dpd` | `carrier-dpd` | `justinholtweb\carrierdpd` | DPD (EasyShip) |
| `justinholtweb/craft-carrier-gls` | `carrier-gls` | `justinholtweb\carriergls` | GLS (MyGLS) |
| `justinholtweb/craft-carrier-boxnow` | `carrier-boxnow` | `justinholtweb\carrierboxnow` | BOX NOW |
| `justinholtweb/craft-carrier-posts` | `carrier-posts` | `justinholtweb\carrierposts` | Hrvatska pošta, Pošta Slovenije, Magyar Posta, ELTA Courier |

That is 16 carrier drivers plus the two built-in carriers. Every add-on is registered through
`Carriers::EVENT_REGISTER_CARRIERS`, a `RegisterComponentTypesEvent`, so it is the same shape as
every other "register your types" event in Craft.

## The carrier contract

A carrier declares `Capabilities` and implements only what it declares. The conformance suite
enforces that. The features are:

| Feature | Method | Notes |
|---|---|---|
| `rates` | `rate(ShipmentRequest): RateResult` | live quotes; SEE carriers mostly have none, so methods price from tables |
| `labels` | `createShipment(ShipmentRequest): ShipmentResult` | one call buys every parcel's label |
| `cancel` | `cancelShipment(ShipmentRef): ActionResult` | void a label |
| `reprint` | `printLabels(ShipmentRef[], format): LabelResult` | batch print in one document when the carrier can |
| `tracking` | `track(string[] numbers): TrackingInfo[]` | batched; normalised statuses |
| `points` | `pickupPoints(PointQuery): PointPage` | `list` mode (whole country, synced daily) or `search` mode (near a place, live) |
| `pickups` | `requestPickup(PickupRequest): ActionResult` | courier collection |
| `returns` | `createShipment()` with `isReturn` | return labels |
| `cod` | `ShipmentRequest::$cod*` | cash on delivery, essential in SEE |
| `addressValidation` | `validateAddress(Address): AddressResult` | |
| `webhooks` | `handleWebhook(Request): TrackingInfo[]` | push tracking |
| `international` / `multiPiece` / `freight` | flags | freight requests carry handling units, freight class and accessorials |

Label formats are a fixed vocabulary (`pdf-a4`, `pdf-a6`, `pdf-4x6`, `pdf-letter`, `zpl`, `png`).
A carrier lists the formats it supports and the connection picks one.

## Invariants

1. **`services\Orders::buildRequest()` is the only place a Commerce order becomes a
   `ShipmentRequest`.** Rates, labels, the CP preview and the console all share it.
2. **`services\Labels::purchase()` is the only place a label is bought.** A label costs money,
   so it is **claim first**: a shipment row goes in as `purchasing` *before* the carrier is called.
   A job that finds its row still `purchasing` does **not** buy again. It parks the row on the
   Problems screen ("the carrier may have created this label — check, then void or retry"),
   because only a human can see the carrier's portal.
3. **`services\Tracking::apply()` is the only place tracking reaches a shipment.** Polling,
   webhooks, the CP refresh button and the console all end there. Events are deduplicated by a
   unique `(shipmentId, hash)` index, not by a remembered check.

## Data model (database, not project config)

Connections hold credentials, so they live in the database. Project config would deploy a
sandbox UPS account to production. Methods reference connections by id, so they live there too.
Boxes and settings are plain plugin settings.

- `carrier_connections`: credentials, label format, ship-from override, tokens.
- `carrier_methods`: the checkout shipping methods. Each has a connection, a service code,
  pricing (live ± markup, flat, weight table, free over), conditions (countries, weight) and
  whether it requires a pickup point.
- `carrier_shipments`: one label purchase. Holds status, tracking status, COD, the selected point
  snapshot, the request snapshot, charge, and the error text.
- `carrier_parcels`: one piece, with its own tracking number, dimensions and items.
- `carrier_labels`: the label bytes, base64 in the database. Craft Cloud and load-balanced hosts
  have no shared filesystem, and labels carry personal data that must never be in a public volume.
  Pruned after N days.
- `carrier_events`: tracking events, unique on `(shipmentId, hash)`.
- `carrier_points`: pickup points synced from `list`-mode carriers. Searched locally with a
  bounding box plus haversine, so checkout never waits on a carrier.
- `carrier_selections`: the pickup point chosen on a cart, one per order.
- `carrier_pickups`: courier collections requested.
- `carrier_log`: every request and response, redacted at the transport.

Nothing has a foreign key to a Commerce element. Shipments cascade from their connection being
deleted? **No.** Shipment history outlives a deleted connection, which keeps a `connector` handle.

## Commerce integration

- **Shipping methods** come from `ShippingMethods::EVENT_REGISTER_AVAILABLE_SHIPPING_METHODS`.
  `CarrierShippingRule` **extends Commerce's concrete `ShippingRule`**, because the adjuster
  type-hints it. The whole price goes in `baseRate`, everything else returns zero, and
  `getMatchingShippingRule()` is overridden because Commerce memoizes it on the order number.
- **Rates fail open.** A carrier that is down falls back to the method's fallback price, or hides
  the method. Quotes are memoized on a cart signature (lines, weights, dimensions, destination)
  and cached for `rateCacheMinutes`.
- **The pickup point is required** for a method that says so. A payment is refused with a clear
  message through `Payments::EVENT_BEFORE_PROCESS_PAYMENT`, the one place a missing point can
  stop a checkout before money moves.
- **COD**: an order paid through a gateway listed in `codGateways` ships with COD equal to its
  outstanding balance.
- **Order screen**: a panel through the `cp.commerce.order.edit.details` hook, plus a full
  shipment screen at `carrier/orders/<id>` (parcels, service, rate shop, buy, void, print, track).
- **Order index**: "Create labels" and "Print labels" element actions, plus a Tracking column.
- **Order status**: optional status changes when a label is bought and when the parcel is delivered.

## Packing

Store units are converted once on the way in and once on the way out (`helpers\Units`).
Everything internal is in grams and millimetres. There are three strategies: one parcel, one
parcel per unit, and boxes (first-fit decreasing by volume, capped by the box's weight limit and
the carrier's). Non-shippable and free-shipping lines are excluded, as Commerce's adjuster does.

## Front end

- `carrier/points/search` (GET, anonymous, JSON, cached) and `carrier/points/select` (POST,
  CSRF, acts on the **current cart only**, never an order id from the request).
- `craft.carrier.pointPicker(cart)` renders a drop-in widget the site can override.
  `craft.carrier.shipments(order)` and `craft.carrier.trackingUrl(...)` cover the customer's account pages.

## Scheduling

Craft has no scheduler. A cache-guarded sweep is pushed at most once every few minutes, from
any request. It queues tracking refreshes for shipments that are due (back-off by age, and it
stops at a final status) and a daily point sync per `list`-mode connection. The console
commands `carrier/tracking/refresh` and `carrier/points/sync` do the same work for cron.

## Verification

- `tests/integration/checks.php` runs the engine against the Mock carrier in the plugin-testing
  harness: packing, units, rate pricing and fail-open, methods at checkout, claim-first purchase
  and stuck-claim handling, void, print, tracking dedup and status mapping, points sync and
  search, selection and the payment veto, COD, element actions, CP screens.
- `tests/integration/carriers.php` is the **conformance suite**. Every installed carrier is
  driven through the same checks against a recorded transport. A carrier must implement what
  it advertises, put credentials on the request, never echo a secret back, mark a 4xx
  refusal unretryable, map tracking codes onto the normalised set, and return a label whose
  bytes match the format it claimed.

Like Erpy's connectors, the add-ons are written against published API documentation with no live
accounts. The research is in `docs/research/`, and anything unverified is marked there and in
each carrier's docblock.
