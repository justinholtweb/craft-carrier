---
title: Installation
slug: installation
order: 10
summary: Requirements, the gateway and the add-ons you install, and a first run with the Mock carrier.
---

# Installation

Carrier is two installs: the gateway, and the free add-on for each carrier you ship with.

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later, with the `zip` extension (bulk printing bundles labels into ZIP files)

## Install

```sh
composer require justinholtweb/craft-carrier
composer require justinholtweb/craft-carrier-gls     # one per carrier you use

php craft plugin/install carrier
php craft plugin/install carrier-gls
```

| Add-on | Package | Plugin handle |
|---|---|---|
| UPS | `justinholtweb/craft-carrier-ups` | `carrier-ups` |
| FedEx | `justinholtweb/craft-carrier-fedex` | `carrier-fedex` |
| USPS | `justinholtweb/craft-carrier-usps` | `carrier-usps` |
| US LTL freight (TForce, Old Dominion, Estes, XPO, R+L, SAIA) | `justinholtweb/craft-carrier-freight` | `carrier-freight` |
| DPD EasyShip (Croatia, Slovenia) | `justinholtweb/craft-carrier-dpd` | `carrier-dpd` |
| GLS (MyGLS) | `justinholtweb/craft-carrier-gls` | `carrier-gls` |
| BOX NOW | `justinholtweb/craft-carrier-boxnow` | `carrier-boxnow` |
| National posts (Hrvatska pošta, Pošta Slovenije, Magyar Posta, ELTA) | `justinholtweb/craft-carrier-posts` | `carrier-posts` |

An add-on registers one or more carriers with Carrier and does nothing else. It has no settings
screen, no nav item and no tables of its own. Everything it needs lives on the connection you are
about to make. See [Carriers](/plugins/craft-carrier/docs/carriers) for what each one does.

## Editions and price

Carrier has a single edition. There is no Lite.

| | |
|---|---|
| **Carrier** | $149, $129 to renew annually |
| **Every carrier add-on** | Free |

The add-ons are free because they are the small part. An add-on translates one carrier's API.
Everything that has to be true of every carrier — packing, checkout pricing, the claim that stops
a label being bought twice, label storage and printing, tracking, the pickup-point picker, the log
— is Carrier's, written once.

## Before you trust an add-on

The add-ons were written against each carrier's published API documentation, without live
accounts. Anything that could not be confirmed from a primary source is marked **UNVERIFIED** in the
add-on's README and in its carrier class. Test every connection against the carrier's sandbox (or,
where a carrier has none, with a parcel you void straight away) before you take real orders
through it.

## Your first run, with the Mock carrier

Two carriers ship with Carrier itself:

- **Mock Carrier** — a pretend carrier that never leaves the server. It quotes rates, makes labels,
  lists pickup points, accepts collections, closes the day and moves parcels through the tracking
  statuses on a timer.
- **Pick up in store** — customers collect from one of your Commerce inventory locations. See
  [Pickup points](/plugins/craft-carrier/docs/pickup-points).

Walk the whole product with the Mock before anyone has a carrier account:

1. **Carrier → Connections → New connection**, pick **Mock Carrier**. Type anything six characters
   or longer as the API key, switch it on, save, and press **Test connection**.
2. **Carrier → Checkout methods → New**, choose the Mock connection and the *Mock Standard* service,
   and set **Live rate from the carrier**. Add a second method on *Mock Locker* with **Delivers to a
   pickup point** on.
3. Add `{{ craft.carrier.pointPicker(cart) }}` to your checkout's shipping step and place a test
   order on each method.
4. On the order, open **Carrier** and press **Buy label**. Then try **Create shipping labels** and
   **Print shipping labels** from the order index.

The Mock connection's **Simulate a failure** setting makes it refuse every label, throttle, time
out after sending, or go down for rates and tracking. Use it to see what your team will see on the
[Problems screen](/plugins/craft-carrier/docs/labels) before it happens for real. **Seconds per tracking stage** controls how
fast a mock parcel moves from "Label created" to "Delivered".

It is also the fastest way to answer "is this Carrier, or is this the add-on?" If the same flow
works against the Mock and fails against your carrier, the problem is in the add-on or the
credentials.

## Uninstalling

Uninstalling an add-on leaves its connections, shipments and history in place. Reinstall it and
they come back. Uninstalling Carrier itself drops its tables.
