---
title: Checkout methods
slug: checkout-methods
order: 30
summary: What the customer chooses between at checkout — flat, table and live pricing, conditions, packing and freight defaults.
---

# Checkout methods

A checkout method is what the customer sees and picks: "GLS to your door", "BOX NOW locker",
"UPS Ground". Each one has a **connection**, a **service** and a way of arriving at a **price**.

**Carrier → Checkout methods → New method.**

## The method

| Field | |
|---|---|
| **Name** | What the customer sees at checkout |
| **Handle** | Commerce stores it on the order as `carrier-<handle>` |
| **Enabled** | A disabled method is never offered |
| **Store** | On a multi-store site: one store, or all of them |
| **Connection** | Which carrier account ships it |
| **Service** | The carrier's services, listed for the connection you chose. Services that deliver to a pickup point are marked |
| **Delivers to a pickup point** | The customer chooses a locker, parcel shop, post office or store, and cannot pay until they have. Only offered for carriers that have pickup points |

Drag methods on the index to set the order they are listed in.

## Price

### Flat price

One price, whatever the cart.

### Weight table

Rows of *up to this weight → this price*, in the store's weight unit. The first row whose weight
is not exceeded sets the price. A cart heavier than the last row is not offered the method at all.

| Up to (kg) | Price |
|---|---|
| 2 | 3.50 |
| 5 | 4.90 |
| 10 | 6.50 |
| 31.5 | 9.90 |

This is how most European carriers are priced: their APIs do not quote, and your contract has a
price list. GLS, DPD, BOX NOW and the national posts have no live rates.

### Live rate from the carrier

Carrier asks the carrier for a price for this cart, to this address, for the method's service. If
the method has no service set, the cheapest service the carrier offers is used.

| Field | |
|---|---|
| **Markup (%)** | Added to the carrier's figure first |
| **Markup (amount)** | Added after the percentage |
| **Fallback price** | Charged when the carrier cannot be reached, refuses, or does not offer the service to this address. **Blank hides the method instead** |

A method set to a live rate on a carrier with no rates API will not save.

Live rates need a shipping address. Until the customer has entered one, the method shows its
fallback price, or is hidden.

Live prices are cached for `rateCacheMinutes` (default 15) per cart, keyed on every line's quantity,
weight and dimensions and on the destination — so an unchanged cart costs one API call, and a
changed one is always re-priced. See [Configuration](/plugins/craft-carrier/docs/configuration#checkout).

**Pricing never fails a checkout.** A carrier that is down, slow or refusing costs you one
method's live price — replaced by the fallback, or the method hidden — and nothing else. The reason
is written to Craft's log.

### For every pricing mode

| Field | |
|---|---|
| **Free over** | Item subtotal at which this method becomes free. Blank for never |
| **Round up to** | `0.5` always charges in halves, `1` in whole units. Rounds up, never down. Blank for no rounding |

Free-over is checked after the method's conditions, so a method that does not ship to a country is
not offered there free either.

## When it is offered

| Condition | |
|---|---|
| **Only to countries** | Comma-separated codes: `HR, SI`. Blank for anywhere |
| **Never to countries** | Comma-separated codes |
| **Min / max weight** | In the store's weight unit, for the shippable contents of the cart |
| **Min / max subtotal** | On the item subtotal |

A method that fails a condition is simply not offered. Non-shippable line items, and line items
with free shipping, do not count towards the weight — the same rule Commerce's own shipping uses.

## Packing

Carrier turns the order's line items into parcels before it rates or ships them. The plugin
setting picks the default strategy; a method can choose its own.

| Strategy | What it does |
|---|---|
| **Pack into boxes** | Biggest items first, each into the first open box it fits (by its sorted edges, remaining volume and the box's max weight), otherwise into the smallest empty box that takes it. An item no box can take ships on its own |
| **Everything in one parcel** | One parcel. The smallest of your boxes that holds the lot by volume gives the dimensions; without one, the largest item's edges are used |
| **One parcel per item** | Every unit ships alone, in its own dimensions |

Boxes are set on the settings screen: name, length, width, height, empty weight and max weight,
in the store's own units. With no boxes defined, **Pack into boxes** behaves like **Everything in
one parcel**.

Carrier also respects the carrier's own parcel weight limit (31.5 kg, 150 lb, and so on): a parcel
that would exceed it is split.

The packer is honest about its limits. It uses volume as a proxy for geometry, not a 3D solver, so
it will occasionally decide two long items fit side by side when only one does. For orders of more
than 500 units it packs by weight alone. You can always change the parcels on the order's
shipment screen before you buy the label. See [Labels](/plugins/craft-carrier/docs/labels).

Products with no weight send no weight, unless you set **Weight for items with none** in the
settings. Most carriers bill by weight, so fill weights in.

## Freight methods

For an LTL carrier (TForce, Old Dominion, Estes, XPO, R+L, SAIA) the method has two more fields:

| Field | |
|---|---|
| **Freight class** | The NMFC class for every handling unit, as a string: `70`, `92.5`. A connection can set its own default too |
| **Handling unit** | Pallet, crate, carton or drum |

Each parcel the packer produces is one handling unit. Weight goes out in whole pounds and
dimensions in whole inches, both rounded up. A handling unit with no class, on a method and a
connection with no default class, is refused with the unit named — nothing is guessed. See
[LTL freight](/plugins/craft-carrier/docs/freight).

## Extras (accessorials)

**Always add** lists the extras this carrier understands: liftgate at pickup or delivery,
residential pickup or delivery, inside delivery, limited access, delivery appointment, notify
before delivery, hazardous materials, signature, adult signature and Saturday delivery. Every
shipment on the method is booked with the ones you tick.

Only what the carrier can do is listed, so a method cannot ask for a liftgate the carrier would
silently ignore. For freight, each carrier lists only the extras it can both price and print on the
bill of lading, because a liftgate that was quoted but left off the BOL is how surprise invoices
happen.

You can add extras to a single shipment on the order's shipment screen.

## How Commerce sees them

Carrier methods are registered with Commerce as shipping methods. They appear next to any
shipping methods you set up in Commerce itself, in `cart.availableShippingMethodOptions`, with
their price for the current cart. Nothing in your checkout templates needs to change to offer them.

```twig
{% for handle, option in cart.availableShippingMethodOptions %}
    <label>
        <input type="radio" name="shippingMethodHandle" value="{{ handle }}"
            {{- cart.shippingMethodHandle == handle ? ' checked' }}>
        {{ option.name }} — {{ option.priceAsCurrency }}
    </label>
{% endfor %}
```

A method that is unavailable for a cart — wrong country, too heavy, a live rate with no fallback
and a carrier that did not answer — is not in the list.

The whole price is charged as one shipping adjustment on the order. Carrier works out the price
itself, per cart, so Commerce's shipping zones and per-item rates are not involved.

If the method delivers to a pickup point, add the picker to the same step. See
[Pickup points](/plugins/craft-carrier/docs/pickup-points).

## Events

`Methods::EVENT_BEFORE_SAVE_METHOD` and `EVENT_AFTER_SAVE_METHOD` fire around saves, and
`Rates::EVENT_AFTER_QUOTE` lets you adjust or withdraw a price. See [Templating and events](/plugins/craft-carrier/docs/templating).
