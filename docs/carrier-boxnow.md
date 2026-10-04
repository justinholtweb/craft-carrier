---
title: Carrier for BOX NOW
slug: boxnow
order: 107
summary: BOX NOW parcel lockers in Greece, Cyprus, Bulgaria, Croatia and Slovenia, on the BOX NOW Partner API, with locker delivery, cash on delivery, labels, cancellation, tracking and the whole country's locker list.
---

Carrier for BOX NOW connects Craft Commerce to **BOX NOW** parcel lockers through the BOX NOW
Partner API, in **Greece, Cyprus, Bulgaria, Croatia and Slovenia**. Every delivery goes to a locker.
The add-on is free; it needs Carrier, which is the paid part and owns packing, checkout methods, the
locker picker, label storage, the claim that stops a label being bought twice, the tracking
schedule and the log.

## Install

```sh
composer require justinholtweb/craft-carrier-boxnow
php craft plugin/install carrier-boxnow
```

Then **Carrier → Connections → New connection** and pick **BOX NOW**. The add-on has no settings
screen of its own; everything lives on the connection.

## What it does

| Feature | Supported | Notes |
|---|---|---|
| Live rates | No | BOX NOW does not quote prices. Price the method with a flat price or a weight table |
| Labels | Yes | Fetched per parcel. Several parcels per order, one compartment each |
| Void | Yes | Per parcel, until the parcel is delivered |
| Reprint | Yes | One document per order where BOX NOW can |
| Returns | No | Carrier does not create return labels; see **Allow the customer to return through BOX NOW** below |
| Tracking | Yes | Polled, one parcel per request |
| Pickup points | Yes, `list` mode | The whole country's lockers, synced daily |
| Cash on delivery | Yes | EUR, up to 4,999.99, if your account has the COD permission |
| Collections | No | Couriers collect from your registered warehouse on a schedule |
| Close day | No | BOX NOW has no manifest |
| Address validation | No | |
| International | No | BOX NOW delivers within one country |
| Heaviest parcel | 20 kg | And it must fit a compartment |
| Webhooks | No | See below |

## Connecting

BOX NOW issues partner credentials (a client ID and secret) per country at onboarding, together
with the id of the warehouse it registered for you.

| Field | What to put there |
|---|---|
| **Environment** | Production, or Sandbox / test, which uses BOX NOW's stage host. Stage parcels are never collected. |
| **Country** | Croatia, Slovenia, Greece, Bulgaria or Cyprus. BOX NOW issues credentials per country and delivers only within it. |
| **Client ID** / **Client secret** | From BOX NOW. |
| **Origin (warehouse) ID** | The id BOX NOW registered for your warehouse. Use `any-apm` if you drop parcels into any locker yourself. |
| **Partner ID** | Only when one login manages several partners. Sent as `X-PartnerID`. |
| **API URL** | Leave blank to use the standard host for the country and environment. Fill it in if BOX NOW gave you a different one. |
| **Locker compartment** | Smallest that fits the parcel's dimensions (the default), or always small, medium or large. |
| **Allow the customer to return through BOX NOW** | On by default. Passed to BOX NOW on each delivery. |

**Test connection** lists your warehouse ids if the one you entered is wrong.

**One connection, one country.** Slovenia uses the Croatian API host unless BOX NOW gave you
another URL. To ship in two countries, add two connections.

## Services

| Code | Service | Pickup point |
|---|---|---|
| `locker` | BOX NOW locker | Yes |

There is one service, and it always needs a locker. Put it on a checkout method that requires a
pickup point.

## Label formats

| Format | Notes |
|---|---|
| **PDF, A6 thermal** | |
| **ZPL (Zebra thermal)** | 200 dpi |

See [labels](/plugins/craft-carrier/docs/labels) for storage and bulk printing.

## Lockers

The connection's country is synced daily into the database from BOX NOW's public location feed, so
checkout searches lockers locally and never waits on BOX NOW. Only the connection's own country is
synced: a locker anywhere else is one the connection could never ship to. See
[pickup points](/plugins/craft-carrier/docs/pickup-points) for the storefront picker.

Lockers have no opening hours in the feed; they are presumed open around the clock. Some are inside
shops, which the locker's note says.

## Compartments

One parcel is one locker compartment:

| Size | Compartment |
|---|---|
| Small | 8 × 45 × 60 cm |
| Medium | 17 × 45 × 60 cm |
| Large | 36 × 45 × 60 cm |

With **Locker compartment** on its default, the smallest that fits the parcel's dimensions is
chosen. A parcel that fits no compartment, or weighs more than 20 kg, is refused before anything is
sent.

## Cash on delivery

COD needs the `codPayment` permission on your BOX NOW account, and the amount must be under 5,000.
There is no currency field: BOX NOW collects in the partner's currency, which is EUR in every BOX
NOW country (Bulgaria since 1 January 2026). A COD in any other currency is refused rather than
collected in the wrong money.

## Things to know about BOX NOW

- **Phones must be international**, such as `+385 91 …`. National numbers are completed with the
  connection country's code. The customer's name, phone and email are all required, because the
  locker PIN goes out by SMS and email. Commerce addresses have no phone field, so map one in
  Carrier's settings.
- **Order numbers are unique forever.** BOX NOW never accepts the same order number twice, even
  after a cancel. Carrier sends the order reference plus a short hash of what is being shipped, so
  a retry of the same shipment sends the same number and BOX NOW's own rule stops a duplicate
  delivery. If BOX NOW reports the number taken, Carrier asks what it holds: a cancelled
  predecessor moves on to `-2`, `-3`; a live one is reported as uncertain and waits on
  **Problems**, because it is almost certainly an earlier attempt whose answer was lost. If you
  really do ship an identical second consignment while the first is live, pass
  `options['boxnowOrderNumber']`.
- **A void cancels each parcel.** A delivery request has no cancel of its own. Once a parcel is
  delivered it cannot be cancelled.
- **There is no out-for-delivery state.** A parcel in the locker is ready for pickup.
- **No tracking webhooks.** BOX NOW can push tracking, but documents no way to sign or authenticate
  it, and an unauthenticated endpoint would let anyone mark an order delivered. Carrier polls
  instead.
- **BOX NOW being down** shows as a 503 from its locker bridge or geocoder.

## Not yet verified

This add-on was written against the BOX NOW Partner API manual (v1.65) and BOX NOW's official
Croatian and Slovenian WordPress plugin, without a live account. These points are marked
**UNVERIFIED** in the code. Test them on BOX NOW's stage host before you go live:

- Whether Slovenian credentials work on the Croatian host, and the other way round.
- That the token endpoint accepts only JSON.
- The field name of the delivery request's own id (`id` or `referenceNumber`; both are read).
- The PDF label's exact paper size (treated as A6), and the ZPL request header.
- The Cyprus locker feed's language code, and every stage locker feed except Croatia's.
- What a locker in state `only-delivery` means. Such lockers are kept.
- The full list of tracking states. Unknown ones are read as in transit.
- Whether tracking can ask for more than one parcel per request. It asks for one.
- Public tracking pages outside Croatia. `t.boxnow.hr` is used for every country.

See [all carriers](/plugins/craft-carrier/docs/carriers) for the rest of the family.
