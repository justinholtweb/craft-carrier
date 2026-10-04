# Carrier — Craft CMS 5 Plugin

## Project Overview

Carrier is a **shipping carrier gateway for Craft Commerce**. It owns:

- parcels on the order and packing
- checkout pricing (flat, weight table, or live with markup and a fallback)
- claim-first label purchase, label storage and printing
- tracking, with webhooks
- pickup points and the storefront picker
- collections and end-of-day closes
- the connection log and the control panel

Each carrier is a separate **free add-on plugin** that registers carrier classes. It is distributed
as `justinholtweb/craft-carrier`. Single edition, **$149 with a $129 renewal**. The model is
Erpy's. Prompted by craft-shipper#1, from a merchant in south-east Europe.

Plan: `docs/plan.md`. Add-on contract: `docs/writing-a-carrier.md`. API research behind the add-ons:
`docs/research/`.

## Tech Stack

- PHP 8.2+, Craft CMS 5.3+, Craft Commerce 5.0+, `ext-zip`
- No build step and no JS bundles. CP behaviour lives in inline `{% js %}`; the storefront picker
  is vanilla JS.

## Packages

| Package | Handle | Carriers |
|---|---|---|
| craft-carrier | `carrier` | `mock`, `store-pickup` |
| craft-carrier-ups / -fedex / -usps | `carrier-ups` … | `ups`, `fedex`, `usps` |
| craft-carrier-freight | `carrier-freight` | `freight-tforce`, `-odfl`, `-estes`, `-xpo`, `-rl`, `-saia` |
| craft-carrier-dpd | `carrier-dpd` | `dpd-easyship` |
| craft-carrier-gls | `carrier-gls` | `gls` |
| craft-carrier-boxnow | `carrier-boxnow` | `boxnow` |
| craft-carrier-posts | `carrier-posts` | `posta-hr`, `posta-si`, `posta-hu`, `elta` |

Add-on namespaces are `justinholtweb\carrier<slug>`. Each lives in a sibling directory with its
own git repo.

## The invariants

1. **`services\Orders::buildRequest()` is the only place an order becomes a `ShipmentRequest`.**
   Rates, labels, the CP preview and the console all share it. Plugins adjust the request through
   `EVENT_AFTER_BUILD_REQUEST`.
2. **`services\Labels::purchase()` is the only place a label is bought. It is claim-first**:
   - The shipment row is written as `purchasing`, under a unique `claimKey`, *before* the carrier
     is called. The unique index is the guarantee.
   - Only `failed` (refused: the carrier said it created nothing) may be reclaimed and retried.
   - `purchasing` and `uncertain` are never rebought automatically. They wait on Problems for a
     human, who resolves them with `Labels::resolve()`.
   - During a purchase the transport runs `setSafeRetriesOnly(true)`, so only a 429 is retried.
3. **`services\Tracking::apply()` is the only place tracking reaches a shipment.**
   - Scans are deduplicated by the unique `(shipmentId, hash)` index.
   - A status never moves backwards, except into an exception.
   - The order moves to "delivered" only when every live non-return shipment on it has arrived.

Shipment status meanings: `failed` means the carrier refused the label, so no label exists.
`uncertain` means no usable answer came back, so a label may exist. Carriers classify failures
with `ShipmentResult::fromResponse()`; the conformance suite enforces it.

## Data

Connections and methods are kept **in the database, not in project config**, so credentials never
deploy. Labels are base64 in `carrier_labels` (Craft Cloud has no shared disk, and labels carry
personal data) and are pruned by `labelRetentionDays`. Nothing has a foreign key to a Commerce
element.

Pickup points have three modes in `Capabilities`:

- `list`: synced daily into `carrier_points` and searched locally (bounding box + haversine).
- `search`: live search, cached for an hour. The search endpoint remembers what it showed in the
  session, so only those ids can be selected.
- `manual`: CSV import only (DPD EasyShip has no point API).

## Commerce traps this plugin works around

- `CarrierShippingRule` **extends the concrete `ShippingRule`**, because the adjuster type-hints
  it. The whole price goes in `baseRate`; everything else is zero.
- `getMatchingShippingRule()` is overridden, because Commerce memoizes it on the order number.
  `Rates` memoizes on a cart signature instead.
- The payment guard throws `PaymentException` from `Payments::EVENT_BEFORE_PROCESS_PAYMENT`. That is
  the one veto point before money moves, and the exception's message reaches the customer.

## Traps found building this

- **`details` is a reserved CP layout variable** (`_layouts/cp.twig` trims it as the details
  pane). Passing an array named `details` to a CP template 500s with "trim(): Argument #1 must be
  of type string, array given". The shipment screen uses `shipmentDetails`.
- `Order::getMatchingShippingMethods()` returns an **array** in Commerce 5, not a collection.
- A console script has no CP user, so element actions that check permissions need
  `getUser()->setIdentity()` in tests.
- MyGLS dates are `"\/Date(ms)\/"`. GLS sends a pre-encoded body rather than relying on the
  transport's JSON flags.
- The shared harness deadlocks on search-index writes when other suites run at the same time.
  `checks.php` retries element saves on a deadlock.

Vendor traps live in each carrier's class docblock, along with every **UNVERIFIED** item.

## Testing

No local PHP. Everything runs in the plugin-testing harness, through `tests/integration/run.sh`,
which starts the containers itself and never uses `ddev exec`:

```sh
tests/integration/run.sh /var/www/craft-carrier/tests/integration/checks.php     # engine, against the Mock carrier
tests/integration/run.sh /var/www/craft-carrier/tests/integration/carriers.php   # conformance suite, every carrier
tests/integration/run.sh /var/www/craft-carrier/tests/integration/smoke-fixtures.php && tests/integration/smoke.sh   # CP over HTTP
tests/integration/run.sh /var/www/craft-carrier/tests/integration/smoke-fixtures.php teardown
```

The conformance suite checks:

- declared ⇔ implemented features
- credentials on the wire
- the 4xx / 429 / 5xx / silence classification
- no secret in messages or the log
- empty-answer survival
- the happy path, from each add-on's `tests/fixtures.php`; an entry can also be
  `['double' => fn, 'settings' => [...]]`

## Conventions

- `Craft::t('carrier', …)` for every merchant-facing string, including in add-ons.
- Never nest a `<form>` in a full-page-form CP template; use `Craft.sendActionRequest`.
- Never mark plugin settings `required`.
- Anything on the checkout path fails open: rates use a fallback price or hide the method, and
  registration catches its own errors.
