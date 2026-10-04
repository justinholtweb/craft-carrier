# Carrier

**Carrier is a shipping carrier gateway for Craft Commerce 5.** It provides:

- live and table rates at checkout
- label buying and printing, one at a time or in bulk from the order index
- a parcel-shop and locker picker for the storefront
- tracking written back to the order
- collections and end-of-day closes

Each carrier is a free add-on.

Carrier owns everything that has to be true of every carrier: parcels on the order, packing, the
claim that stops a label being bought twice, label storage, tracking, the pickup-point picker,
the connection log and the control panel. An add-on translates one carrier's API and does nothing
else.

## Carriers

| Add-on | Carriers | What it does |
|---|---|---|
| *built in* | Mock Carrier, Pick up in store | Testing without an account; collection from Commerce inventory locations |
| `carrier-ups` | UPS | Rates, labels, returns, tracking, Access Points, pickups, address validation |
| `carrier-fedex` | FedEx | Rates, labels, returns, tracking, Hold at Location, pickups, address validation |
| `carrier-usps` | USPS | Prices, labels, tracking, Post Office locations, pickups (USPS APIs v3) |
| `carrier-freight` | TForce Freight, Old Dominion, Estes, XPO, R+L, SAIA | LTL quotes, bills of lading, PRO tracking, pickups |
| `carrier-dpd` | DPD (EasyShip — HR, SI) | Labels, COD, parcel shops, collections, daily manifest |
| `carrier-gls` | GLS (MyGLS — HR, HU, CZ, RO, SI, SK, RS) | Labels, COD, parcel shops and lockers, tracking |
| `carrier-boxnow` | BOX NOW | Locker delivery, COD, labels, tracking |
| `carrier-posts` | Hrvatska pošta, Pošta Slovenije, Magyar Posta, ELTA Courier | Labels, COD, Paketomat and PostaPont, tracking, end-of-day close |

The add-ons were written against each carrier's published API documentation, without live
accounts. Each add-on's README and its carrier class list what could not be verified. Test every
connection against the carrier's sandbox before going live.

## Requirements

- Craft CMS 5.3+
- Craft Commerce 5.0+
- PHP 8.2+ with `zip`

## Setting up

1. Install Carrier and the add-ons for your carriers.
2. **Carrier → Connections**: add an account for each carrier, test it, and choose the label
   format that matches your printer. Connections are stored in the database rather than in project
   config, so sandbox credentials never reach production through a deploy.
3. **Carrier → Checkout methods**: decide what the customer chooses between ("GLS to your door",
   "BOX NOW locker", "UPS Ground"). Each method has a connection, a service and a price:
   - **Flat price.**
   - **Weight table.**
   - **Live rate** from the carrier, with an optional markup and rounding. If the carrier is down,
     the method falls back to a set price or is hidden. It never fails the checkout.

   Add conditions (countries, weight, subtotal) and a free-over threshold where you need them.
4. **Settings**: set up your boxes, your address phone field (Commerce addresses have none),
   cash-on-delivery gateways, and the order statuses to move to when an order is labelled and
   when it is delivered.

## The pickup-point picker

Add one line to the shipping step of your checkout:

```twig
{{ craft.carrier.pointPicker(cart) }}
```

It renders nothing unless the cart is on a method that delivers to a pickup point. When it does,
the customer searches by postcode, town or their location, and picks a locker or shop. The
customer can't pay until they have chosen a point. To change the markup, copy
`src/templates/_frontend/point-picker.twig` to `templates/_carrier/point-picker.twig`.

## Pickup points

Carriers provide points in one of three ways:

- **Synced daily** into the database, for carriers that publish a full list (GLS, BOX NOW and the
  posts). Searches run locally, so checkout never waits on the carrier.
- **Looked up live**, for carriers that only search near a place (UPS, FedEx and USPS).
- **Imported from CSV**, for carriers with no point API (DPD EasyShip).

The endpoints, if you'd rather build your own picker:

```
GET  /actions/carrier/points/search?method=<handle>&postcode=10000      (or lat & lng)
POST /actions/carrier/points/select   method=<handle>  pointId=<id>     (current cart only)
```

## Labels

Buy labels from **Carrier** on an order, or from the order index with **Create shipping labels**.

Every purchase is claimed before the carrier is called:

- **Retries and double-clicks** find the claim and stop, so they never buy a second label.
- **A refusal** (bad postcode, overweight parcel) can be retried once you have fixed the problem.
- **An interrupted or unanswered purchase**, where the label may or may not exist, is never bought
  again automatically. It waits on **Problems** until someone checks the carrier's portal.

**Print shipping labels** on the order index makes one print job:

| Labels selected | What you get |
|---|---|
| One label | The document as it is |
| Several ZPL labels | One ZPL stream |
| Several PNG labels | A browser print sheet, four to an A4 page or one per 4×6 |
| Several PDFs from one carrier that batch-prints | One merged sheet from the carrier (GLS, DPD) |
| Anything else | A ZIP |

Label files are stored in the database rather than in an asset volume: they carry a customer's
address, and Craft Cloud has no shared disk. They are pruned after a set number of days. The
shipment record and its tracking are kept.

## Tracking

Carrier polls each shipment until it is delivered:

- often at first, then less often as the shipment ages
- page views drive the polling, or you can run it from cron with `php craft carrier/tracking/refresh`
- carriers that push tracking can do that instead, through the webhook URL on the connection

Scans are stored once each. A late "in transit" never moves a delivered parcel backwards. When
every shipment on an order has arrived, the order moves to your delivered status.

```twig
{% for shipment in craft.carrier.shipments(order) %}
    <a href="{{ shipment.trackingUrl }}">{{ shipment.trackingNumber }}</a> — {{ shipment.trackingStatusLabel }}
{% endfor %}
```

## Console

```
php craft carrier/connections/list
php craft carrier/connections/test <handle>
php craft carrier/labels/create <order reference> [--connection=] [--service=] [--dry-run]
php craft carrier/tracking/refresh
php craft carrier/tracking/show <tracking number>
php craft carrier/points/sync [--connection=] [--force]
php craft carrier/points/import <connection> <file.csv>
```

## Writing a carrier

See [`docs/writing-a-carrier.md`](docs/writing-a-carrier.md). An add-on is one class per carrier, registered on
`Carriers::EVENT_REGISTER_CARRIERS`. The conformance suite, `tests/integration/carriers.php`,
must pass for it, at 0 failures.

## Licence

The Craft License. See [LICENSE.md](LICENSE.md). Carrier is commercial: $149, with $129 a year for updates, single edition. The carrier add-ons are free.
