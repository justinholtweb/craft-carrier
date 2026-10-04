---
title: Carrier for DPD
slug: dpd
order: 105
summary: DPD Croatia and DPD Slovenia through DPD's own EasyShip web service, with labels fetched at creation, cash on delivery, imported parcel shops, collections and the daily manifest.
---

Carrier for DPD connects Craft Commerce to **DPD Croatia** and **DPD Slovenia** through **DPD
EasyShip**, DPD's own portal and web service at `easyship.hr` and `easyship.si`. The add-on is
free; it needs Carrier, which is the paid part and owns packing, checkout methods, label storage,
bulk printing, the tracking schedule, the pickup-point picker and the log.

**Which EasyShip?** This is not Easyship.com, the unrelated multi-carrier aggregator. If you sign
in at `easyship.hr` or `easyship.si`, this is the right add-on. If you sign in at
`app.easyship.com`, it is not.

## Install

```sh
composer require justinholtweb/craft-carrier-dpd
php craft plugin/install carrier-dpd
```

Then **Carrier → Connections → New connection** and pick **DPD EasyShip**. The add-on has no
settings screen of its own; everything lives on the connection.

## What it does

| Feature | Supported | Notes |
|---|---|---|
| Live rates | No | EasyShip has no rates. Price DPD methods with a flat price or a weight table |
| Labels | Yes | Created and printed in the same purchase, one 14-digit parcel number per parcel |
| Void | Yes | Deletes an unprinted parcel, otherwise cancels it. Cancelled parcels drop off the manifest |
| Reprint | Yes, briefly | Only for about two minutes after the first print. See below |
| Returns | No | |
| Tracking | Yes | Latest status only, one parcel per call. No history and no timestamps |
| Pickup points | Yes, `manual` mode | DPD Pickup shops and lockers, imported from a CSV |
| Cash on delivery | Yes | In euros, with a choice of how it is split across parcels |
| Collections | Yes | At least 30 minutes' notice |
| Close day | Yes | Today's manifest PDF, plus the COD manifest when any shipment carries cash |
| Address validation | No | |
| International | Yes | DPD Home and DPD Classic |
| Batch printing | Yes | Several DPD PDFs print as one merged sheet from DPD |

## Connecting

DPD issues no separate API key: the add-on signs in with your EasyShip portal login.

| Field | What to put there |
|---|---|
| **EasyShip country** | Croatia (easyship.hr) or Slovenia (easyship.si): where your DPD contract is. The two accounts are separate. |
| **EasyShip username** / **EasyShip password** | The login you use at easyship.hr or easyship.si. Store the password in an environment variable. |
| **Cash on delivery across several parcels** | First parcel only, averaged across the parcels, or all parcels. EasyShip's `parcel_cod_type`; it only matters when one COD shipment has more than one parcel. Default: first parcel only. |
| **Print the ship-from address on labels** | Off by default: labels show the sender saved in your EasyShip profile. |

**There is no test system.** DPD documents no sandbox. Its own advice is to test in production and
delete the parcel while it is still unprinted. Because this add-on prints every label as soon as it
is created, a test parcel can only be cancelled, not deleted; it stays in EasyShip marked as
cancelled. Make your first shipment a real one you are ready to send, or ask DPD whether a test
account is available.

## Services

| Code | Service | International | COD parcel type |
|---|---|---|---|
| `home` | DPD Home (B2C) | Yes | `D-B2C`, or `D-COD-B2C` with COD |
| `classic` | DPD Classic (B2B) | Yes | `D`, or `D-COD` with COD |
| `pickup` | DPD Pickup parcel shop / locker | | `D-B2C-PSD`, or `D-B2C-COD-PSD` with COD |

At DPD, cash on delivery and parcel-shop delivery are not options on a parcel but **parcel types**.
The add-on picks the type from the service and whether there is cash to collect.

## Label formats

| Format | |
|---|---|
| **PDF, A4 sheet** | |
| **PDF, A6 thermal** | |
| **ZPL (Zebra thermal)** | |

### Labels vanish after two minutes

About two minutes after a parcel is first printed, EasyShip sends its data to DPD, and from then
on **every print of it returns an empty PDF**. So the add-on fetches the label in the same request
that creates the parcel, and Carrier stores those bytes. Your labels are always available from
Carrier. A later reprint that comes back empty is reported as an error explaining this, and Carrier
keeps the original.

If EasyShip creates a parcel but will not print it, the parcel is still unprinted and so still
deletable. It is deleted and the purchase can be retried. Only if that delete fails too does the
shipment wait on **Problems**, with the parcel number. See [labels](/plugins/craft-carrier/docs/labels).

## Parcel shops: the CSV import

**EasyShip has no list of parcel shops.** DPD's manual tells you to ask your local DPD office for
the PUDO ids, and DPD's public shop finder cannot be read by a server. So the list comes from you.

1. Ask DPD Croatia or DPD Slovenia for the parcel-shop list.
2. Save it as a CSV with a header row. `id`, `name` and `country` are required:

   ```
   id,name,type,street,city,postcode,country,latitude,longitude,hours,cod
   HR00006,DPD Pickup Tisak Ilica,shop,Ilica 120,Zagreb,10000,HR,45.8131,15.9601,Mon–Fri 07:00–20:00,1
   ```

   | Column | Meaning |
   |---|---|
   | `id` | The PUDO id: `HR` or `SI` followed by five digits, at most 7 characters |
   | `type` | `shop` or `locker`. Default `shop` |
   | `latitude` / `longitude` | Decimal degrees, for sorting by distance in the picker |
   | `cod` | `0` or `no` if the point does not take cash on delivery. Default yes |

   A semicolon-separated file works too.
3. Import it in **Carrier → Pickup points → Import** for your DPD connection, or from the console:

   ```sh
   php craft carrier/points/import <connection> dpd-points.csv
   ```

DPD connections use Carrier's **manual** point mode: nothing is ever synced, so imported points
change only when you import again. Re-import whenever DPD sends an updated list. You can also type
a PUDO id on the shipment screen. See [pickup points](/plugins/craft-carrier/docs/pickup-points) for the storefront picker.

Parcel-shop delivery needs a point id, and EasyShip also wants the parcel's weight, the recipient's
mobile number and the parcel's dimensions. Carrier checks all of these before sending.

## Cash on delivery

The amount is sent in euros, with a short payment purpose (at most 14 characters). A COD amount is
never sent on a non-COD parcel type. For a COD shipment of several parcels, the connection's
**Cash on delivery across several parcels** setting says how DPD splits it.

## Collections and close day

- **Collections** need at least 30 minutes' notice. A start time that is too soon is moved to 31
  minutes from now.
- **Close day** (**Close the day** under **Carrier → Collections**) fetches today's manifest PDF, plus the COD manifest when any shipment carries cash.
  DPD has no "close" call, because EasyShip transmits parcel data on its own. The manifest lists
  only parcels DPD has already received data for, so asked for straight after labelling, it can be
  empty.

## Things to know about DPD

- **The house number is a separate field.** Carrier splits "Ilica 242a" automatically and
  recognises Croatian "bb" (bez broja, no number). An address with no number at all is refused
  before anything is sent.
- **Home and parcel-shop delivery need a phone or email** for DPD's Predict notifications. Commerce
  addresses have no phone field, so map one in Carrier's settings.
- **Field lengths are short**: 35 characters, 30 for the sender. Longer values are cut. Diacritics
  are fine.
- **A void is in practice a cancel.** Labels are printed at creation, so a parcel can no longer be
  deleted; it stays in EasyShip as cancelled and drops off the manifest.
- **Tracking has no history.** EasyShip gives only the latest status of each parcel, with no time.
  Each distinct status becomes one undated event on the shipment.

## Not yet verified

This add-on was written against DPD's published *EasyShip Webservice User Manual* (v2.2, August
2025) without a live account, and **EasyShip has no sandbox**, so these points can only be
checked on a first live shipment. They are marked **UNVERIFIED** in the code:

- What an "empty PDF" from a late reprint looks like byte for byte. It is detected as no bytes,
  something that is not a PDF, or a PDF with no pages.
- The HTTP status of a validation error (believed to be 200 with `"status":"err"`; both are
  handled).
- What the COD split options (`firstonly`, `avg`, `all`) do on multi-parcel shipments.
- Whether a COD amount on a non-COD parcel type is ignored or refused. It is never sent.
- How dimensions apply to a multi-parcel parcel-shop shipment. The largest parcel's are sent.
- Whether a cancel works on a parcel that has been printed but not yet sent to DPD.
- Whether a customer collecting from a parcel shop shows as delivered (assumed).
- Whether DPD offers test accounts on request.

See [all carriers](/plugins/craft-carrier/docs/carriers) for the rest of the family.
