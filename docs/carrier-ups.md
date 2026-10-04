---
title: Carrier for UPS
slug: ups
order: 101
summary: UPS rates, ZPL and PNG labels, returns, tracking, Access Points, pickups and address validation through the UPS OAuth REST APIs, for shippers in the United States and Puerto Rico.
---

Carrier for UPS connects Craft Commerce to UPS through the OAuth REST APIs at
`onlinetools.ups.com`: Rating, Shipping, Void, Track, Locator, Pickup and Address Validation. The
add-on is free; it needs Carrier, which is the paid part and owns packing, checkout methods, label
storage, bulk printing, the tracking schedule, the pickup-point picker and the log.

It ships from the **United States and Puerto Rico**, in pounds and inches.

## Install

```sh
composer require justinholtweb/craft-carrier-ups
php craft plugin/install carrier-ups
```

Then **Carrier → Connections → New connection** and pick **UPS**. The add-on has no settings screen
of its own; everything lives on the connection.

## What it does

| Feature | Supported | Notes |
|---|---|---|
| Live rates | Yes | One service, or every service at once when a method shops. Negotiated rates when your account has them, with transit days and an estimated delivery date |
| Labels | Yes | Domestic and international, multi-package: one 1Z and one label per package |
| Void | Yes | The whole shipment, by its lead 1Z |
| Reprint | No | See [Label formats](#label-formats) |
| Returns | Yes | Print Return Labels (UPS return service 9) |
| Tracking | Yes | Full scan history, who signed, up to 10 numbers per request |
| Pickup points | Yes, `search` mode | UPS Access Points, looked up live |
| Collections | Yes | On-call pickups; the pickup request number (PRN) is the reference |
| Close day | No | |
| Address validation | Yes | Street level, with suggested corrections and residential/commercial classification |
| Cash on delivery | No | |
| International | Yes | Commercial invoice from Carrier's customs lines |
| Accessorials | Yes | Signature, adult signature, Saturday delivery, residential delivery |
| Heaviest parcel | 150 lb | The UPS small-package ceiling |

## Connecting

At [developer.ups.com](https://developer.ups.com/), sign in with your UPS account and create an
app. Add the products **Authorization (OAuth)**, **Rating**, **Shipping**, **Tracking**,
**Locator**, **Pickup** and **Address Validation**, and link your UPS shipper account to the app.
Copy the app's client ID and secret into environment variables.

| Field | What to put there |
|---|---|
| **Environment** | Production, or Sandbox / test. Sandbox uses UPS's Customer Integration Environment (`wwwcie.ups.com`) with the same app. Its labels are not valid for shipping. |
| **Client ID** / **Client secret** | From your app at developer.ups.com. |
| **UPS account number** | Your six-character shipper number, e.g. `A1B2C3`. Labels are billed to it, and it unlocks negotiated rates. |
| **Use negotiated rates** | On by default. Asks UPS for your account's contract rates. Accounts without them get published rates, and the checkout notes say "Published rate". |
| **Declare each parcel's value** | Off by default. Buys UPS declared-value coverage above the included $100, which UPS charges for. |
| **International duties and taxes** | Recipient pays (DDU), or Bill my UPS account (DDP). |

**Test connection** asks UPS for a fresh access token, which proves the client ID and secret
without touching an address or a parcel. If it fails in production but works in the sandbox, the
app has not yet been approved for production: both environments use the same app.

UPS access tokens last one hour (cut from four hours on 1 April 2026). Carrier caches them and
fetches a new one a minute before they expire.

## Services

| Code | Service | International |
|---|---|---|
| `03` | UPS Ground | |
| `12` | UPS 3 Day Select | |
| `02` | UPS 2nd Day Air | |
| `59` | UPS 2nd Day Air A.M. | |
| `13` | UPS Next Day Air Saver | |
| `01` | UPS Next Day Air | |
| `14` | UPS Next Day Air Early | |
| `93` | UPS Ground Saver | |
| `92` | UPS Ground Saver (under 1 lb) | |
| `11` | UPS Standard (Canada, Mexico) | Yes |
| `65` | UPS Worldwide Saver | Yes |
| `08` | UPS Worldwide Expedited | Yes |
| `07` | UPS Worldwide Express | Yes |
| `54` | UPS Worldwide Express Plus | Yes |
| `72` | UPS Worldwide Economy DDP | Yes |
| `17` | UPS Worldwide Economy DDU | Yes |

A checkout method set to shop all services only ever offers these. UPS's Shop answer can include
services your account cannot use, and those are filtered out. Ground Saver needs to be enabled on
your account.

## Label formats

UPS's Ship API draws GIF, ZPL, EPL and SPL. **It cannot produce a PDF or a PNG.** The connection
offers:

- **ZPL (Zebra thermal)**, 4×6. The best choice if you have a Zebra-compatible printer.
- **PNG image, 4×6 in**, converted from UPS's GIF on your server. It is only offered when PHP's GD
  extension is installed, because a format that cannot be produced is never offered. The image is
  passed on as UPS draws it, which may be landscape; print it rotated if your printer does not do
  that itself.

UPS can supply PDFs through a separate Label Recovery API, but its request shape could not be
confirmed without a live account, so **PDF and reprinting are not offered** rather than offered
unreliably. See [labels](/plugins/craft-carrier/docs/labels) for how Carrier stores and bulk-prints what UPS returns.

## Access Points

The UPS Access Point locator is searched live around the customer's postcode, town or location, so
there is nothing to sync or import. Points whose name says "Locker" are listed as lockers, and every
other point as a shop. When a checkout method requires a pickup point, the label is addressed to the
chosen Access Point ("hold for pickup at a UPS Access Point"). Carrier stores only the point's id,
so the add-on looks the point up again by that id when the label is bought.

**Access Point delivery is not priced separately.** The checkout shows the door-to-door rate for the
same service. See [pickup points](/plugins/craft-carrier/docs/pickup-points) for the storefront picker.

The point's opening hours reach templates exactly as UPS sends them, in `extra.operatingHours`. They
are not formatted.

## Returns

A return label swaps the parties: the customer's address becomes the ship-from, your store the
ship-to, and it is billed to your account. UPS allows **one package per return** from the United
States and Puerto Rico, so a multi-parcel return is refused with a message telling you to create
one return per parcel.

## Things to know about UPS

- **International needs customs lines and a phone number.** The commercial invoice is built from
  Carrier's customs lines; a shipment without any is refused rather than invented. UPS also needs
  a recipient phone. Commerce addresses have no phone field, so map one in Carrier's settings.
- **Indicators are presence flags.** UPS switches residential delivery, Saturday delivery and
  negotiated rates on by the key being present at all. Sending `"N"` turns them on. The add-on
  sends or omits them, so you never see this, but it matters if you read the raw requests in the
  log.
- **Every number is a string**, in both directions. Same reason: worth knowing when reading the log.
- **A void works on the lead 1Z.** That is the shipment id Carrier stores; voiding cancels every
  package in the shipment.
- **An unanswered label purchase is never bought again.** If UPS creates a shipment but returns no
  packages, or a package without a label, the shipment waits on **Problems** with a message to
  check UPS before buying again.
- **Pickups** default to a 09:00 ready time and a 17:00 close time when none is given, and to
  tomorrow when no date is given.
- **Tracking** maps UPS's status types: `M` is pre-transit, `I` in transit, `X` an exception, and
  `D` is either out for delivery or delivered, read from the scan.

## Not yet verified

This add-on was written against the official UPS OpenAPI specifications (version `v2409`) without
a live UPS account. These points are marked **UNVERIFIED** in the code. Test them in UPS's CIE
sandbox before you go live:

- The Ground Saver service codes `93` and `92`. They come from community sources and are missing
  from the current Ship specification.
- Tracking status types beyond the documented `D`, `I`, `M` and `X` (`MV`, `P`, `U`, `RS`), and
  reading "out for delivery" from a `D` scan with no delivery date.
- Access Point delivery: the `AlternateDeliveryAddress` fields, and looking a point up by its
  public id.
- Searching for Access Points by map coordinates when no postcode is given, and the shape of the
  opening hours.
- Pickups: the three-digit service code `003`, and marking the pickup address as an alternate
  address.
- Reference numbers: sent per package for US and Puerto Rico shipments and per shipment otherwise,
  because UPS rejects package references on most international lanes.
- Address validation outside the United States and Puerto Rico.
- The GIF-to-PNG conversion is not rotated, because UPS does not document which way its landscape
  image should be turned.

See [all carriers](/plugins/craft-carrier/docs/carriers) for the rest of the family.
