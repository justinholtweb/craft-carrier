---
title: Carriers
slug: carriers
order: 15
summary: Every carrier add-on, what each one can do, and why the add-ons are free.
---

# Carriers

Each carrier is a separate **free add-on plugin** that registers one or more carriers with Carrier.
Install the add-on and its carriers appear in the list when you make a connection.

| Add-on | Carriers | Package | Plugin handle |
|---|---|---|---|
| [UPS](/plugins/craft-carrier/docs/ups) | UPS | `justinholtweb/craft-carrier-ups` | `carrier-ups` |
| [FedEx](/plugins/craft-carrier/docs/fedex) | FedEx | `justinholtweb/craft-carrier-fedex` | `carrier-fedex` |
| [USPS](/plugins/craft-carrier/docs/usps) | USPS | `justinholtweb/craft-carrier-usps` | `carrier-usps` |
| [LTL freight](/plugins/craft-carrier/docs/freight) | TForce Freight, Old Dominion, Estes Express, XPO, R+L Carriers, SAIA | `justinholtweb/craft-carrier-freight` | `carrier-freight` |
| [DPD](/plugins/craft-carrier/docs/dpd) | DPD EasyShip (Croatia, Slovenia) | `justinholtweb/craft-carrier-dpd` | `carrier-dpd` |
| [GLS](/plugins/craft-carrier/docs/gls) | GLS via MyGLS (Croatia, Hungary, Czechia, Romania, Slovenia, Slovakia, Serbia) | `justinholtweb/craft-carrier-gls` | `carrier-gls` |
| [BOX NOW](/plugins/craft-carrier/docs/boxnow) | BOX NOW lockers (Greece, Cyprus, Bulgaria, Croatia, Slovenia) | `justinholtweb/craft-carrier-boxnow` | `carrier-boxnow` |
| [National posts](/plugins/craft-carrier/docs/posts) | Hrvatska pošta, Pošta Slovenije, Magyar Posta (MPL), ELTA Courier | `justinholtweb/craft-carrier-posts` | `carrier-posts` |

Each carrier's page covers the credentials it asks for and where to find them, its services, and
the vendor's own traps.

Two carriers ship with Carrier itself and need no add-on: **Mock Carrier**, for testing without an
account, and **Pick up in store**, which turns your Commerce inventory locations into collection
points. See [Installation](/plugins/craft-carrier/docs/installation) and [Pickup points](/plugins/craft-carrier/docs/pickup-points).

## What each carrier can do

This is what each carrier class declares. Carrier's screens are built from it, so you are never
offered a feature a carrier cannot do — a live-rate method on a carrier with no rates API will not
save, and a **Close the day** button only appears for carriers that have one.

| Carrier | Labels | Live rates | COD | Pickup points | Tracking | Collections | Close day |
|---|---|---|---|---|---|---|---|
| UPS | ZPL, PNG | yes | — | search (Access Points) | yes | yes | — |
| FedEx | PDF, ZPL, PNG | yes | — | search (Hold at Location) | yes | yes | — |
| USPS | PDF, ZPL, PNG | yes | — | search (Post Offices) | yes | yes | — |
| TForce Freight | BOL + PDF, ZPL | yes | — | — | yes (PRO) | yes | — |
| Old Dominion | BOL + PDF | yes | — | — | yes (PRO) | yes | — |
| Estes Express | BOL + PDF | yes | — | — | yes (PRO) | — | — |
| XPO | BOL + PDF | yes | — | — | yes (PRO) | — | — |
| R+L Carriers | BOL + PDF | yes | — | — | yes (PRO) | — | — |
| SAIA | BOL + PDF | yes | — | — | yes (PRO) | — | — |
| DPD EasyShip | PDF, ZPL | — | yes | manual (CSV import) | yes | yes | yes |
| GLS | PDF, ZPL | — | yes | list (shops, lockers) | yes | — | — |
| BOX NOW | PDF, ZPL | — | yes | list (lockers) | yes | — | — |
| Hrvatska pošta | PDF | — | yes | list (post offices, Paketomats) | yes | — | — |
| Magyar Posta | PDF, ZPL | — | yes | list (post offices, PostaPont, lockers) | yes | — | yes (required) |
| Pošta Slovenije | **no** | — | — | search (opt-in, unofficial) | yes | — | — |
| ELTA Courier | PDF | — | yes | — | yes | — | — |
| *Pick up in store* | collection slip (PDF) | — | yes | list (inventory locations) | — | — | — |
| *Mock Carrier* | PDF, ZPL, PNG | yes | yes | list | yes | yes | yes |

Some features are not in the table because fewer carriers have them:

- **Void through the API**: UPS, FedEx, USPS, Old Dominion, XPO, DPD, GLS, BOX NOW, Hrvatska
  pošta and Magyar Posta (until the day is closed). For anyone else, void in the carrier's portal
  and press **Mark voided** in Carrier.
- **Reprint** (fetch labels again from the carrier): USPS, DPD (for about two minutes after
  printing), GLS, BOX NOW, Hrvatska pošta, Magyar Posta and ELTA. Carrier stores every label it
  buys, so you rarely need it.
- **Return labels**: UPS, FedEx and USPS.
- **Address validation**: UPS, FedEx and USPS.
- **Tracking webhooks**: none of the add-ons declares them yet; tracking is polled. See
  [Tracking](/plugins/craft-carrier/docs/tracking).

### The three pickup-point modes

- **list** — the carrier publishes every point in a country. Carrier syncs it daily into its own
  table and searches it locally, so checkout never waits on the carrier and the picker keeps
  working when the carrier is down.
- **search** — the carrier only answers "what is near here", live. Answers are cached for an hour.
- **manual** — the carrier has no point API. You import a CSV of points from the carrier.

See [Pickup points](/plugins/craft-carrier/docs/pickup-points).

### Carriers without live rates

Most European carriers do not quote prices through their APIs: you agree a price list with them.
Price those methods with a flat price or a weight table. See
[Checkout methods](/plugins/craft-carrier/docs/checkout-methods).

### Pošta Slovenije

Pošta Slovenije's API receives shipment data but returns no tracking number or label: you number
parcels from a barcode range it assigns, and print to a layout that comes with your contract. So
Carrier does not buy Pošta Slovenije labels. Create the shipment in eSpremnica as you do now, then
use **Shipped some other way? Record its tracking number** on the order's shipment screen, and
Carrier tracks it like any other. See [Labels](/plugins/craft-carrier/docs/labels).

## How the add-ons were built

Every add-on was written against the carrier's published API documentation, without a live account.
Anything that could not be confirmed from a primary source is marked **UNVERIFIED** in the
add-on's README and in the docblock of its carrier class, where someone debugging that carrier will
find it.

**Test each connection against the carrier's sandbox before going live.** Most carriers here have
one; each carrier's page says which do not and what to do instead.

## Why the add-ons are free

An add-on's whole job is to turn Carrier's canonical shipment request into one carrier's API call,
and the answer back into labels, tracking numbers and statuses. It does not pack, price, queue,
retry, store labels, log, schedule or touch Commerce — because Carrier does, once, for all of them.
There is not enough left in an add-on to charge for, and pricing them separately would mean a
merchant shipping with four carriers paying four times for the same engine.

It is the same arrangement [Erpy](https://justinholt.com/plugins/craft-erpy) uses for its ERP
connectors, for the same reason.

## What Carrier checks for every add-on

Carrier ships a conformance suite that drives every installed carrier through the same checks
against recorded responses. It fails a carrier that:

- advertises a feature it does not implement, or implements one it does not advertise
- does not put its credentials on the request
- echoes a secret back in an error message or the log
- marks a refusal as retryable, or a timeout as a refusal
- returns a label whose bytes do not match the format it claimed
- reports success without a tracking number and a label
- throws, or invents a result, on an empty answer

## Your carrier is not on the list

A carrier is one class. See [Writing a carrier](/plugins/craft-carrier/docs/writing-a-carrier). If you would rather not write
it, email justin@justinholt.com — the list grows in the order people ask.
