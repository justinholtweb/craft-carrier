---
title: Configuration
slug: configuration
order: 25
summary: The plugin settings screen field by field, overriding it from config/carrier.php, and permissions.
---

# Configuration

Carrier splits its settings in two. Anything that belongs to **one carrier account** lives on its
connection — see [Connections](/plugins/craft-carrier/docs/connections). What is left is on the plugin's settings screen,
because it should be true of every carrier you ship with.

**Carrier → Settings** (or **Settings → Plugins → Carrier**). Plugin settings hold no credentials,
so they go through project config like any other plugin's.

## Packing

| Setting | Default | |
|---|---|---|
| `packingStrategy` | `boxes` | How line items become parcels: `boxes`, `single` (everything in one parcel) or `perItem` (one parcel per unit). A checkout method can override it |
| `boxes` | none | Your cartons: name, length, width, height, empty weight and max weight, in the store's own units. The packer tries the smallest that fits first |
| `defaultItemWeight` | `0` | Weight assumed for a product with none, in store weight units. `0` sends what the product says, even if that is nothing |

With `boxes` chosen and no boxes defined, Carrier packs everything into one parcel. See
[Checkout methods](/plugins/craft-carrier/docs/checkout-methods#packing) for how each strategy works and where it falls short.

## Addresses

| Setting | Default | |
|---|---|---|
| `phoneField` | blank | Handle of a custom field on the address layout that holds a phone number |
| `houseNumberField` | blank | Handle of a separate house-number field, if your address form collects one |
| `defaultCallingCode` | blank | Completes local numbers: `385` turns `091 234 5678` into `+385912345678` |

**Commerce 5 addresses have no phone number.** Most carriers need one for delivery texts, and GLS
refuses parcel-shop and locker parcels without one. Add a plain text field to the address field
layout (**Settings → Addresses**), make sure your checkout fills it in, and put its handle here.

Several European carriers want the house number apart from the street. Without
`houseNumberField`, Carrier splits it off the street line: "Ilica 242a" becomes "Ilica" and
"242a", and "12 Main Street" becomes "Main Street" and "12".

## Customs

| Setting | Default | |
|---|---|---|
| `hsCodeField` | blank | Handle of a field holding an HS tariff code |
| `originCountryField` | blank | Handle of a field holding the country of origin |
| `defaultOriginCountry` | blank | Two-letter fallback country of origin |

Used only for international shipments. Carrier reads each field from the variant first and falls
back to its product, so put the field on whichever you prefer.

## Checkout

| Setting | Default | |
|---|---|---|
| `rateCacheMinutes` | `15` | How long a live rate is reused for an unchanged cart. `0` asks the carrier every time |
| `requirePointOnPayment` | on | Refuse payment on a pickup-point method until the customer has chosen a point |
| `codGateways` | none | Payment gateways that mean "the customer pays the courier" |

Every cart page view would otherwise be a paid API call; USPS allows about 60 an hour by default.
The cache is keyed on everything that can change a price — every line's quantity, weight and
dimensions, the destination, the currency and the method's own settings — so a changed cart is
always re-priced.

An order paid through a gateway in `codGateways` ships with **cash on delivery for its outstanding
balance**, on carriers that support COD. A Commerce *Manual* gateway named "Cash on delivery" is the
usual way to offer it. See [Labels](/plugins/craft-carrier/docs/labels#cash-on-delivery).

## After the order

| Setting | Default | |
|---|---|---|
| `autoLabelOnComplete` | off | Buy the label as soon as an order is completed, for orders on a Carrier method |
| `orderStatusOnLabel` | blank | Order status to move an order to when its label is bought. Blank leaves it alone |
| `orderStatusOnDelivered` | blank | Order status to move an order to when **every** shipment on it has been delivered |

Automatic labelling is always queued, never done inside the checkout request: a carrier having a
slow afternoon must not delay a customer's confirmation, and one being down must not fail a sale.

`autoLabelOnComplete` is off by default because a label costs money, and that should be a decision.

A status change writes a message to the order history ("Label created with GLS.", "Delivered.") and
sends whatever emails that status is set up to send. A status email that fails never undoes a label.

## Tracking

| Setting | Default | |
|---|---|---|
| `trackingEnabled` | on | Poll carriers for tracking |
| `trackingIntervalHours` | `4` | Hours between polls for a shipment in motion. Slows down as a shipment ages |
| `trackingMaxDays` | `45` | Stop polling a shipment this many days after its label, delivered or not |
| `pointsSyncHours` | `24` | Re-sync list-mode pickup points after this many hours |

See [Tracking](/plugins/craft-carrier/docs/tracking) for the schedule.

## Housekeeping

| Setting | Default | |
|---|---|---|
| `labelRetentionDays` | `120` | Delete label files after this many days. The shipment record and its tracking are kept. `0` keeps them forever |
| `logRequests` | on | Record carrier requests in the log at all |
| `logErrorsOnly` | off | Only record failures |
| `logBodies` | on | Record request and response bodies. Bodies are the useful part, and also the large part |
| `logRetentionDays` | `30` | |

Labels carry a customer's name, address and phone number, so they are not kept forever by default.
Both kinds of pruning run with Craft's garbage collection.

A log write never causes a purchase or a poll to fail. Secrets are removed before anything reaches
the log.

## Overriding settings from `config/carrier.php`

Craft merges a plugin's config file over its saved settings, and Carrier's settings work that way.
Create `config/carrier.php` and return any of the settings above. Values in the file win over the
settings screen, and Craft's multi-environment syntax works:

```php
<?php

use craft\helpers\App;

return [
    '*' => [
        'packingStrategy' => 'boxes',
        'boxes' => [
            ['name' => 'Small', 'length' => 30, 'width' => 20, 'height' => 10, 'tare' => 0.1, 'maxWeight' => 5],
            ['name' => 'Large', 'length' => 60, 'width' => 40, 'height' => 40, 'tare' => 0.4, 'maxWeight' => 20],
        ],
        'phoneField' => 'phone',
        'defaultCallingCode' => '385',
        'codGateways' => ['cashOnDelivery'],
        'orderStatusOnLabel' => 'shipped',
        'orderStatusOnDelivered' => 'delivered',
    ],
    'dev' => [
        // Never buy a label because a test order was completed locally.
        'autoLabelOnComplete' => false,
        'trackingEnabled' => false,
    ],
    'production' => [
        'autoLabelOnComplete' => App::parseBooleanEnv('$CARRIER_AUTO_LABEL') ?? false,
    ],
];
```

Box dimensions and weights are in the store's own units, as set in Commerce, the same as on the
settings screen.

Connections and checkout methods cannot be set here. They are stored in the database on purpose —
see [Connections](/plugins/craft-carrier/docs/connections#why-connections-are-in-the-database-not-project-config).

## Permissions

Carrier registers its own permissions, so a packing-bench user can print labels without being
able to see credentials:

- **View shipments and labels** — the Shipments screen, the order panel, printing
  - **Buy labels** — buy, retry, record a shipment made elsewhere, **Create shipping labels**
  - **Void labels and resolve problems** — void, mark voided, settle uncertain purchases
  - **Request collections and close the day**
- **Manage checkout methods**
- **Manage carrier connections and pickup points** — includes syncing and importing points
- **View the carrier log**

The settings screen needs an admin, and `allowAdminChanges` on.
