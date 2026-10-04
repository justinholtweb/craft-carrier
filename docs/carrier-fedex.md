---
title: Carrier for FedEx
slug: fedex
order: 102
summary: FedEx rates, PDF, ZPL and PNG labels, returns, tracking, Hold at Location, pickups and address validation through the FedEx REST APIs, for shippers in the United States and Puerto Rico.
---

Carrier for FedEx connects Craft Commerce to FedEx through the REST APIs at `apis.fedex.com`: Rate,
Ship, Track, Pickup, Locations and Address Validation. The add-on is free; it needs Carrier, which
is the paid part and owns packing, checkout methods, label storage, bulk printing, the tracking
schedule, the pickup-point picker and the log.

It ships from the **United States and Puerto Rico**, in pounds and inches.

## Install

```sh
composer require justinholtweb/craft-carrier-fedex
php craft plugin/install carrier-fedex
```

Then **Carrier → Connections → New connection** and pick **FedEx**. The add-on has no settings
screen of its own; everything lives on the connection.

## What it does

| Feature | Supported | Notes |
|---|---|---|
| Live rates | Yes | One service, or every service at once when a method shops. Your account rates, falling back to list rates, with transit days and an estimated delivery date |
| Labels | Yes | Domestic and international, up to 40 packages in one shipment, one tracking number and label per package |
| Void | Yes | The whole shipment, by its master tracking number |
| Reprint | No | |
| Returns | Yes | Print return labels |
| Tracking | Yes | Up to 30 numbers per request, with scan history, delivery dates and who signed |
| Pickup points | Yes, `search` mode | FedEx Office stores and retail counters, looked up live, delivered with Hold at Location |
| Collections | Yes | On-call pickups, for FedEx Ground or FedEx Express |
| Close day | No | |
| Address validation | Yes | Street level, with FedEx's corrected address and residential/business classification |
| Cash on delivery | No | |
| International | Yes | Commodities and commercial-invoice terms from Carrier's customs lines |
| Accessorials | Yes | Signature, adult signature, Saturday delivery, residential delivery |
| Heaviest parcel | 150 lb | In your own packaging, Express and Ground alike |

## Connecting

At [developer.fedex.com](https://developer.fedex.com/api/en-us/home.html), create a project with the
**Ship**, **Rate**, **Track**, **Address Validation**, **Locations** and **Pickup** APIs, and link
your FedEx shipping account to it. **FedEx issues separate keys for its sandbox and for
production.** Store them in environment variables.

| Field | What to put there |
|---|---|
| **Environment** | Production, or Sandbox / test (`apis-sandbox.fedex.com`). It must match the keys. |
| **API key** / **Secret key** | From your FedEx project. |
| **FedEx account number** | The nine-digit account labels are billed to. |
| **How parcels reach FedEx** | I drop them off, A regular scheduled pickup, or I book a pickup when I need one. FedEx prices some services differently depending on this. |
| **Pickups are for** | FedEx Ground or FedEx Express. FedEx books the two collections separately; Carrier books the one chosen here, and you book the other in FedEx Ship Manager. |
| **Declare each parcel's value** | Off by default. Raises FedEx's liability above the included $100, which FedEx charges for. |
| **International duties and taxes** | Recipient pays (DDU), or Bill my FedEx account (DDP). |

**Test connection** asks FedEx for a fresh access token, which proves the keys.

**Production labels need certification.** Before FedEx enables production shipping for a project,
it asks you to submit sample labels for approval. Plan for that before you go live.

**The sandbox is not the real world.** Sandbox rates are not your real prices, and sandbox tracking
only knows FedEx's published mock tracking numbers.

## Services

| Code | Service | International |
|---|---|---|
| `FEDEX_GROUND` | FedEx Ground | Yes |
| `GROUND_HOME_DELIVERY` | FedEx Home Delivery | |
| `FEDEX_EXPRESS_SAVER` | FedEx Express Saver | |
| `FEDEX_2_DAY` | FedEx 2Day | |
| `FEDEX_2_DAY_AM` | FedEx 2Day A.M. | |
| `STANDARD_OVERNIGHT` | FedEx Standard Overnight | |
| `PRIORITY_OVERNIGHT` | FedEx Priority Overnight | |
| `FIRST_OVERNIGHT` | FedEx First Overnight | |
| `FEDEX_INTERNATIONAL_CONNECT_PLUS` | FedEx International Connect Plus | Yes |
| `INTERNATIONAL_ECONOMY` | FedEx International Economy | Yes |
| `FEDEX_INTERNATIONAL_PRIORITY` | FedEx International Priority | Yes |
| `FEDEX_INTERNATIONAL_PRIORITY_EXPRESS` | FedEx International Priority Express | Yes |
| `INTERNATIONAL_FIRST` | FedEx International First | Yes |

**Ground and Home Delivery are one product to you, and two to FedEx.** FedEx refuses
`FEDEX_GROUND` to a residential address and `GROUND_HOME_DELIVERY` to a business. So:

- When a method shops every service, only the Ground that fits the customer's address is offered.
- When a method is set to either Ground, the right one is sent to FedEx for each address, and the
  rate and label are reported under the service the method asked for. The shipment notes when it
  was swapped.

**FedEx Ground Economy (`SMART_POST`) is not offered.** It needs a hub id and indicia that FedEx
assigns per account.

## Label formats

| Format | Notes |
|---|---|
| **PDF, 4×6 in thermal** | |
| **PDF, US Letter sheet** | The label on the top half of the page |
| **ZPL (Zebra thermal)** | 4×6 |
| **PNG image, 4×6 in** | |

Pick the one that matches your printer on the connection. See [labels](/plugins/craft-carrier/docs/labels) for storage and
bulk printing.

## Hold at Location

FedEx locations are searched live by ZIP code or city, so there is nothing to sync or import. FedEx
Office stores are listed as stores, and other counters (Walgreens, Dollar General, authorised ship
centres) as shops. When a checkout method requires a pickup point, the label is created with FedEx's
Hold at Location service for the chosen location, and it is never treated as a residential
delivery. See [pickup points](/plugins/craft-carrier/docs/pickup-points) for the storefront picker.

**Not every FedEx location accepts Hold at Location parcels.** If FedEx refuses one, the refusal is
shown on the shipment. Opening hours reach templates as FedEx sends them, in `extra.storeHours`.

## Returns

A return label swaps the parties: the customer becomes the shipper and your store the recipient.
It is billed to your account.

## Things to know about FedEx

- **A phone number for everyone.** FedEx requires one for every shipper and recipient. Commerce
  addresses have no phone field, so map one in Carrier's settings.
- **More than 40 parcels is refused.** FedEx creates up to 40 packages in one synchronous call.
  Beyond that it switches to an asynchronous job, which this add-on does not poll, so a larger
  shipment is refused before anything is sent. Split it.
- **International needs customs lines even to be rated**, not only to ship. A shipment without
  them is refused rather than invented.
- **Dimensions are whole inches**, at most 999. They are rounded up.
- **Tracking.** `CA` (cancelled by the sender) becomes cancelled and `HP` (ready at a Hold at
  Location) becomes ready for pickup. Delivery-change requests and return-label link events are
  informational, so the shipment keeps the status of the last real scan. A tracking number FedEx
  does not know is reported as not found.
- **An unanswered label purchase is never bought again.** It waits on **Problems** until somebody
  checks FedEx Ship Manager.

## Not yet verified

This add-on was written against the FedEx OpenAPI files and API Reference Guide without a live
FedEx account. These points are marked **UNVERIFIED** in the code. Test them in the FedEx sandbox
before you go live:

- Where the currency sits in a rate answer. Both documented places are read.
- Hold at Location: the `holdAtLocationDetail.locationId` field, and whether the location's address
  must be sent as well.
- Location search: the result count, location type and company name fields, and the shape of the
  opening hours.
- Pickups: which of the pickup type, total weight and package count are required (the pickup type
  is not sent), and that the ready time is read as the pickup location's local time.
- Address validation: the `DPV` and `Resolved` attributes as the strings `"true"` and `"false"`.
- Fields that were not in the research: signature options, Saturday delivery, declared value, the
  payor on shipping charges, and `deliveryDetails.receivedByName` for who signed.
- Street address limits (three lines of 35 characters).
- The current label-certification process.

See [all carriers](/plugins/craft-carrier/docs/carriers) for the rest of the family.
