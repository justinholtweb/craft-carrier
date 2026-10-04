---
title: Carrier for USPS
slug: usps
order: 103
summary: USPS prices, domestic, international and return labels, voids, reprints, tracking, carrier pickups, Hold for Pickup at a Post Office and US address validation, on the USPS APIs v3.
---

Carrier for USPS connects Craft Commerce to the United States Postal Service through the USPS APIs
v3 at `apis.usps.com`, not the retired Web Tools XML. The add-on is free; it needs Carrier, which is
the paid part and owns packing, checkout methods, label storage, bulk printing, the claim that stops
a label being bought twice, the tracking schedule, the pickup-point picker and the log.

It ships from the United States and its territories (US, PR, VI, GU, AS, MP, FM, MH, PW), in pounds
and inches.

## Install

```sh
composer require justinholtweb/craft-carrier-usps
php craft plugin/install carrier-usps
```

Then **Carrier → Connections → New connection** and pick **USPS**. The add-on has no settings
screen of its own; everything lives on the connection.

## What it does

| Feature | Supported | Notes |
|---|---|---|
| Live rates | Yes | Shipping Options prices every class in one call, once per distinct package |
| Labels | Yes | One call per package: USPS has no shipment grouping. See [Partial failure](#partial-failure) |
| Void | Yes | Cancelled at no charge, or a refund request when USPS has already processed the label |
| Reprint | Yes | Domestic and international |
| Returns | Yes | Pay-on-use return labels, domestic only |
| Tracking | Yes | Up to 35 numbers per request |
| Pickup points | Yes, `search` mode | Post Offices, looked up live, for the Hold for Pickup services |
| Collections | Yes | Free carrier pickups, from US addresses |
| Close day | No | |
| Address validation | Yes | US addresses and territories |
| Cash on delivery | No | |
| International | Yes | One package per shipment, with customs |
| Accessorials | Yes | Signature, adult signature |
| Heaviest parcel | 70 lb | |

## Connecting

You need two things from USPS:

1. **An app on the USPS developer portal**, which gives you a Consumer Key and Consumer Secret.
   Labels, international labels and carrier pickup are **separate access requests** on the portal;
   ask for each one you need.
2. **For labels, a USPS business account** with a CRID, a MID and a funded EPS (or permit) account,
   from the Business Customer Gateway. Prices, tracking and address checks work without it.

| Field | What to put there |
|---|---|
| **Environment** | Production, or Sandbox / test. Sandbox uses USPS's Testing Environment for Mailers (TEM). TEM labels are watermarked and not billed. |
| **Consumer Key** / **Consumer Secret** | From your app on the USPS developer portal. |
| **CRID** | Customer Registration ID, from the Business Customer Gateway. |
| **MID** | The Mailer ID used for labels. |
| **Manifest MID** | Leave blank to use the MID above. |
| **Payment account type** | EPS (Enterprise Payment System) or Permit. |
| **Payment account number** | The EPS account number, or the permit number. |
| **Permit ZIP Code** | Only for a permit account. |
| **Prices** | Commercial, Retail, or Contract (Negotiated Service Agreement). |
| **Where the carrier collects pickups** | Front door, back door, side door, knock on door / ring bell, mail room, office, reception, porch, mailbox, or other (explain in the pickup's instructions). |

**Test connection** reads which APIs your app has been granted, and says which are missing. When
the payment account is filled in, it also proves it can pay for a label.

**Two tokens, not one.** The OAuth token opens every API. A second **payment authorization token**,
minted from your CRID, MID and EPS account, is what pays for a label. Both last about eight hours
and are cached. The payment token is cached per connection and per account, so changing the
account never pays with the old one.

## Services

| Code | Service | Notes |
|---|---|---|
| `USPS_GROUND_ADVANTAGE` | USPS Ground Advantage | |
| `PRIORITY_MAIL` | Priority Mail | |
| `PRIORITY_MAIL_EXPRESS` | Priority Mail Express | |
| `MEDIA_MAIL` | Media Mail | |
| `USPS_GROUND_ADVANTAGE_HFP` | USPS Ground Advantage, Hold for Pickup | Delivered to a Post Office |
| `PRIORITY_MAIL_HFP` | Priority Mail, Hold for Pickup | Delivered to a Post Office |
| `PRIORITY_MAIL_EXPRESS_HFP` | Priority Mail Express, Hold for Pickup | Delivered to a Post Office |
| `FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE` | First-Class Package International Service | International |
| `PRIORITY_MAIL_INTERNATIONAL` | Priority Mail International | International |
| `PRIORITY_MAIL_EXPRESS_INTERNATIONAL` | Priority Mail Express International | International |

Prices are for your own box handed over at the counter. USPS also quotes flat-rate, cubic and
destination-entry prices, which need USPS packaging or a trip to a USPS facility; those are left
out. A domestic service sent to another country, or an international one sent at home, is refused
with a message naming the service.

## Label formats

| Format | Notes |
|---|---|
| **PDF, 4×6 in thermal** | USPS has no sheet size; the 4×6 PDF is the paper option |
| **ZPL (Zebra thermal)** | 203 dpi |
| **PNG image, 4×6 in** | |

See [labels](/plugins/craft-carrier/docs/labels) for storage and bulk printing.

## Hold for Pickup at a Post Office

Post Offices are searched live near the customer, so there is nothing to sync or import. See
[pickup points](/plugins/craft-carrier/docs/pickup-points) for the storefront picker.

Hold for Pickup is not a mail class of its own. It is Ground Advantage, Priority Mail or Priority
Mail Express delivered to the Post Office the customer chose, priced as that class. That is why the
`_HFP` services exist: put a checkout method that requires a pickup point on one of them.

**Hold for Pickup makes more fields mandatory.** USPS needs:

- the customer's email address and a 10-digit phone number;
- a first and last name, an email address and a phone number on your ship-from address.

Commerce addresses have no phone field, so map one in Carrier's settings. A label missing any of
these is refused before anything is bought.

## Partial failure

USPS buys labels one package at a time. If package 2 fails after package 1 was bought:

- **A refusal or throttle** voids package 1 at once. Only when USPS confirms every void cost
  nothing is the shipment reported as refused, so it can be fixed and retried. The voided numbers
  are in the message.
- **Anything else**, such as no answer for package 2, a refund dispute instead of a clean void, or
  a void that fails, marks the shipment **uncertain**, with every tracking number already bought in
  the message. Carrier never buys an uncertain shipment again on its own; it waits on **Problems**.

## Things to know about USPS

- **The quota is small.** USPS's default is 60 calls per hour per API. That is why rates come from
  one Shipping Options call per distinct package. Keep Carrier's rate cache on.
- **Dimensions are required**, for prices and for labels. A parcel without length, width and height
  is refused here rather than by USPS. Weight is decimal pounds, at most 70 lb.
- **International labels are one package per shipment**, because each carries its own customs
  form. Ship each package as its own shipment. An international label without customs lines is
  refused.
- **Returns are domestic only.**
- **The mailing date** must be today through seven days ahead (US Eastern); it is clamped to that
  window.
- **Pickups** are free and run Monday to Saturday, from US addresses only. They default to
  tomorrow when no date is given.
- **A label that would print without postage.** USPS's defaults hide the postage and the mail date
  on the label; the add-on turns both back on for outbound domestic labels.
- **TEM labels** are watermarked and not billed.

## Not yet verified

This add-on was written against the USPS v3 OpenAPI specifications and examples without a live
USPS account. These points are marked **UNVERIFIED** in the code. Test them in TEM before you go
live:

- The machinable thresholds that send a parcel as `NONSTANDARD` (longest side over 22 in, second
  over 18 in, third over 15 in, or over 25 lb). They come from the DMM, not the API specification.
- Hold for Pickup on Priority Mail and Priority Mail Express. USPS's example only shows Ground
  Advantage; the other two are offered because USPS's retail service has them. Also which street
  address the label should carry beside the Post Office id (the customer's is sent, as in USPS's
  example).
- That the payment-authorization `LABEL_OWNER` role accepts the account fields.
- USPS tracking event codes (Pub 199) and status categories. Unknown codes fall back to the event
  text.
- The meaning of every `DPVConfirmation` letter beyond Y, D, S and N.
- The 60-calls-per-hour default quota, which comes from USPS FAQ text rather than the
  specification.

See [all carriers](/plugins/craft-carrier/docs/carriers) for the rest of the family.
