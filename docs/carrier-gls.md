---
title: Carrier for GLS
slug: gls
order: 106
summary: GLS through the MyGLS API in Croatia, Hungary, Czechia, Romania, Slovenia, Slovakia and Serbia, with labels, cash on delivery, parcel shops and lockers synced daily, batch reprints and full tracking.
---

Carrier for GLS connects Craft Commerce to GLS through the **MyGLS API**, in the seven MyGLS
countries: **Croatia, Hungary, Czechia, Romania, Slovenia, Slovakia and Serbia**. The add-on is
free; it needs Carrier, which is the paid part and owns packing, checkout methods, label storage,
bulk printing, the tracking schedule, the pickup-point picker and the log.

## Install

```sh
composer require justinholtweb/craft-carrier-gls
php craft plugin/install carrier-gls
```

Then **Carrier → Connections → New connection** and pick **GLS**. The add-on has no settings screen
of its own; everything lives on the connection.

## What it does

| Feature | Supported | Notes |
|---|---|---|
| Live rates | No | MyGLS has no rates. Price GLS methods with a flat price or a weight table |
| Labels | Yes | The parcel is created and its label returned in one call, so there is never a created-but-unlabelled parcel to clean up |
| Void | Yes | GLS only deletes parcels it has not scanned yet |
| Reprint | Yes | Up to 99 parcels in one document. This is what gives Carrier's bulk print its A4 sheets of four |
| Returns | No | |
| Tracking | Yes | Full scan history, up to 100 parcels per request |
| Pickup points | Yes, `list` mode | Every GLS ParcelShop and ParcelLocker in the country, synced daily |
| Cash on delivery | Yes | Amount, reference and currency |
| Collections | No | The daily collection in your GLS contract picks parcels up |
| Close day | No | MyGLS has no manifest |
| Address validation | No | |
| International | Yes | GLS BusinessParcel |
| Multi-parcel | Yes | One MyGLS parcel with a piece count |
| Accessorials | Yes | Saturday delivery; notify before delivery (FlexDelivery) |

## Connecting

Each GLS country runs its own MyGLS, with separate logins.

| Field | What to put there |
|---|---|
| **MyGLS country** | The country your GLS contract is with. |
| **Environment** | Production, or Sandbox / test. The MyGLS test system (`api.test.mygls.…`) needs **separate test credentials** from your local GLS office. |
| **MyGLS username** | The email address you sign in to MyGLS with. |
| **MyGLS password** | Store it in an environment variable. |
| **Client number** | Your GLS client number, from the contract. It goes on every parcel. |
| **A4 sheet layout** | Four labels in a 2 × 2 grid, or four labels stacked. |
| **ZPL printer resolution** | 203 dpi or 300 dpi. |
| **Hide phone numbers on labels** | Leaves the phone numbers off the printed label. |

**Test connection** says "MyGLS refused the username or password" for a wrong login, even though
MyGLS answers it with HTTP 200.

## Services

| Code | Service | International | Pickup point |
|---|---|---|---|
| `parcel` | GLS BusinessParcel | Yes | |
| `psd` | GLS ParcelShop / ParcelLocker | | Yes |

For parcel-shop delivery, add a checkout method on the **GLS ParcelShop / ParcelLocker** service,
tick **requires a pickup point**, and sync points.

**Saturday delivery** is GLS's `SAT` service. **Notify before delivery** sends FlexDelivery by
email (`FDS`) and, when there is a phone number, by SMS (`FSS`).

## Label formats

| Format | Notes |
|---|---|
| **PDF, A4 sheet** | Four per sheet, in the layout chosen on the connection |
| **PDF, A6 thermal** | MyGLS's `Thermo` PDF |
| **ZPL (Zebra thermal)** | At 203 or 300 dpi |

Several GLS PDFs printed from the order index come back as one merged document from GLS. See
[labels](/plugins/craft-carrier/docs/labels).

**GLS labels are big.** MyGLS sends a label as a JSON list of bytes: one A4 page is about 300,000
integers. The add-on decodes it in chunks with a 180-second timeout, but if label purchases fail
with memory errors, raise PHP's `memory_limit` for queue workers.

## Pickup points

Points are synced daily into the database, so checkout searches them locally and never waits on
GLS. See [pickup points](/plugins/craft-carrier/docs/pickup-points) for the storefront picker. Croatia alone has about 1,280
points, roughly 1,080 lockers and 190 shops.

They come from the feed GLS's own checkout map widget reads,
`https://map.gls-croatia.com/data/deliveryPoints/{country}.json`. It needs no credentials and
serves all seven countries. Its `id` (for example `HR10000-PARCELLOC101`) is what Carrier sends as
the parcel-shop point id.

**That feed is public but unofficial and undocumented**, and GLS could change it without notice.
The official source, MyGLS's `GetDeliveryPoints` (added March 2026), is not used yet: it could not
be verified. If the feed ever breaks, Carrier keeps the last good sync rather than emptying the
picker, and imported points keep working.

Points that list neither cash nor card payment are marked as not accepting cash on delivery.

## Cash on delivery

The amount, a reference (the order's COD reference, or its reference) and the currency are sent on
the parcel.

## Things to know about GLS

- **Parcel-shop and locker recipients need a name, a phone number and an email address.** GLS
  refuses those parcels without all three, so Carrier checks before sending. Commerce addresses
  have no phone field, so map one in Carrier's settings.
- **The house number is digits only.** Letters and suffixes ("12a") go in a separate field with
  the address's second line. A house number of 0 is refused.
- **Everything is HTTP 200**, including a bad password and a refused parcel. The add-on reads each
  method's own error list, so you see the real error.
- **"Same request sent 5 times within 5 minutes"** (GLS error 31) means GLS already has identical
  requests and a parcel may exist. It is reported as uncertain, not as a refusal, and waits on
  **Problems**. So do GLS's internal errors, and any answer that created some parcels and refused
  others.
- **Every parcel has two ids**: MyGLS's database id, which is needed to reprint and delete, and the
  parcel number you track. Both are stored.
- **Parcels are collected by your contract's daily collection.** There is nothing to book and no
  manifest to close.

## Not yet verified

This add-on was written against GLS's published *MyGLS API for system integration* documentation
and GLS's official sample code, without a live MyGLS account. These points are marked
**UNVERIFIED** in the code. Test them on the MyGLS test system, or on a first live shipment:

- **The parcel-shop point id.** The map feed's `id` is sent. It has the same shape as the manual's
  own example and is what GLS's widget returns, but it was not tested against a live parcel.
- Whether cash on delivery also needs a `COD` service code. Like GLS's sample code, only the amount
  is sent.
- Whether the parcels of a multi-parcel shipment share one MyGLS id. Every id returned is stored and
  used to reprint and delete.
- Parcel dimensions, sent in whole centimetres.
- The page size of MyGLS's `Thermo` PDF (treated as A6), and whether ZPL arrives as text in the same
  byte list. Labels are checked byte by byte, and the format recorded is what actually came back.
- Whether a parcel can still be reprinted after GLS has scanned it. Carrier keeps the label from
  creation regardless.
- How scan codes 8, 9, 24, 54 and 59 should be classified.
- What the second pair of times in some opening-hours rows means (presumably a break). It is shown
  in brackets.
- Whether GLS refuses cash on delivery to a point that takes neither cash nor card.
- The format of GLS's public tracking page link.

See [all carriers](/plugins/craft-carrier/docs/carriers) for the rest of the family.
