---
title: Carrier for National Posts
slug: posts
order: 108
summary: Four south-east European national posts in one add-on (Hrvatska pošta, Pošta Slovenije, Magyar Posta and ELTA Courier), with labels, cash on delivery, Paketomat and PostaPont points, tracking and Magyar Posta's end-of-day close.
---

Carrier for National Posts connects Craft Commerce to four south-east European national posts:
**Hrvatska pošta**, **Pošta Slovenije**, **Magyar Posta** and **ELTA Courier**. They are four
unrelated APIs that happen to share a job. The add-on is free; it needs Carrier, which is the paid
part and owns packing, checkout methods, label storage, the claim that stops a label being bought
twice, the tracking schedule, the pickup-point picker and the log.

You need a business contract with each post you use. None of these APIs is open to the public, and
every post issues its own credentials.

## Install

```sh
composer require justinholtweb/craft-carrier-posts
php craft plugin/install carrier-posts
```

Then **Carrier → Connections → New connection**. All four posts appear in the picker. The add-on
has no settings screen of its own; everything lives on the connection.

## Carriers in this package

| Carrier | Handle | Country | Confidence |
|---|---|---|---|
| **Hrvatska pošta** (Paket 24) | `posta-hr` | Croatia | High |
| **Pošta Slovenije** | `posta-si` | Slovenia | Low to medium |
| **Magyar Posta** (MPL) | `posta-hu` | Hungary | High |
| **ELTA Courier** | `elta` | Greece | Low |

Confidence is how much of each driver could be checked against the post's own published
documentation. Nothing in this package has been run against a live account.

## What each post can do

| | Hrvatska pošta | Pošta Slovenije | Magyar Posta | ELTA Courier |
|---|---|---|---|---|
| Labels | PDF: A6, A4 four-up | **No** | PDF: A6, A6 four-up on A4; ZPL | PDF: A6, A4 |
| Cash on delivery | EUR | | HUF, whole forints | EUR |
| Multi-parcel | Yes | | Yes, up to 16 | No |
| Void | Yes | | Until the day is closed | **No** (call your branch) |
| Reprint | Yes | | Until the day is closed | Yes |
| Close day | | | **Required** | |
| Tracking | 20 per request | 10 per request | One per request | One per request |
| Pickup points | `list`: post offices and Paketomats | `search`, opt-in and unofficial | `list`: post offices, PostaPont, parcel terminals | |
| Sandbox | Yes | | Yes, a mock | Test account |

**None of the four has a rate API**, so checkout methods price from a flat price or a weight table
built from your contract. None does returns, collections or address validation through Carrier,
and all four ship domestically only.

## Hrvatska pošta

The HP Shipping Service API (DXWebAPI), product name Paket 24. Written against Hrvatska pošta's own
v1 documentation.

**Connecting.** Ask your Hrvatska pošta contract manager for the API username and password. Test
(`dxwebapit.posta.hr`) and production (`dxwebapi.posta.hr`) have separate credentials.

| Field | What to put there |
|---|---|
| **Environment** | Production, or Sandbox / test, which uses `dxwebapit.posta.hr`. |
| **Username** / **Password** | The API credentials from your contract manager. |
| **Parcels are handed over** | Collected from the ship-from address, at a post office, or at a Paketomat. |
| **Hand-over post office or Paketomat code** | Required when parcels are handed over at a post office or Paketomat. |
| **Paketomat compartment for parcels without dimensions** | M, S, X or L. Parcels with dimensions get the smallest compartment they fit. |

**Ports 9000 and 9020.** Sign-in uses port 9000 and everything else uses 9020. Shared hosting often
blocks outbound traffic to ports other than 443, and the symptom is a timeout rather than an error.
**Test connection** signs in and then runs a small point search, so it tells you which port is
blocked. Ask your host to allow `dxwebapi.posta.hr` on both.

**Services.**

| Code | Service | Pickup point |
|---|---|---|
| `26` | Paket 24 D+1 | |
| `29` | Paket 24 D+2 | |
| `32` | Paket 24 D+3 | |
| `38` | Paket 24 D+4 | |
| `26-PAK` | Paket 24 D+1 to a Paketomat | Yes |
| `26-PU` | Paket 24 D+1 to a post office | Yes |

**Label formats:** PDF, A6 thermal, and PDF, A4 sheet (four-up). No ZPL is documented.

**Pickup points.** Every post office and Paketomat in Croatia arrives in one answer, synced daily,
so checkout searches locally. Paketomats are listed as open 24/7; post offices carry their opening
hours. See [pickup points](/plugins/craft-carrier/docs/pickup-points).

**Things to know.**

- **Paketomat compartments.** Hrvatska pošta needs a compartment size, X, S, M or L. Carrier picks
  the smallest that fits the parcel's dimensions, and uses the connection's default for a parcel
  without dimensions. Force a size on a shipment with the request option `parcelSize`. A parcel too
  big for any compartment is refused.
- **Email addresses are capped at 25 characters.** A longer address is left off the shipment, with
  a warning, because a shortened address is a wrong address. Names, streets and cities are trimmed
  to the API's limits (city 25, name and street 50).
- **The house number is its own field.** "242a" is sent as `242` and `a`.
- **Point codes are sent exactly as given**, never cut to fit: a shortened code would name a
  different point.
- **Cancelling works by client reference**, not barcode. Hrvatska pošta needs that reference to be
  unique, and one order can have several shipments, so Carrier sends the order reference plus a
  short time stamp.
- **Cash on delivery is in euros only.** Any other currency is refused rather than mis-collected.
- **Errors arrive inside an HTTP 200**, per order. No error-code catalogue is published.
- **Labels, tracking and the point list are GET requests with a JSON body.** A proxy between your
  server and Hrvatska pošta that strips GET bodies makes them fail with a validation error.
- **Tracking times have no zone** and are read as Croatian time. Hrvatska pošta's public tracking
  page address is not known, so shipments have no tracking link.

## Pošta Slovenije

**Pošta Slovenije does not buy labels through Carrier.** Its shipment API (eSpremnica "eOddaja")
receives shipment data. It does not return a tracking number or a label:

- **Barcodes:** you number the parcels yourself, from a barcode range Pošta Slovenije assigns to
  you.
- **Labels:** you print them yourself, to a layout specification that comes with the contract and
  is not public.
- **Errors:** the data is checked asynchronously, and errors come back 15 to 30 seconds after you
  submit it.
- **Shipment codes:** the codes that define a shipment (parcel type, cash on delivery, Paketomat
  delivery) are issued with each contract and are not published.

Carrier only reports a label as bought when it holds a real tracking number and a label the post
will accept. That is not possible from public information, and a home-made label that looks right
but is refused at the counter is worse than no label. So this connection offers **no labels, voids,
reprints or cash on delivery**, and it has no services. Create your Pošta Slovenije shipments in
eSpremnica or the ePortal as you do now.

**Tracking is ready, but not yet usable from the control panel.** Carrier can track a Pošta
Slovenije barcode once that barcode is attached to a shipment. Carrier 5.0 has no screen for
attaching a barcode created outside Carrier yet, so until it does, the tracking half of this driver
has nothing to track.

**Connecting.** Three values from your Pošta Slovenije contract manager for the tracking API
(eSledenje). They are separate from your eSpremnica login.

| Field | What to put there |
|---|---|
| **Tracking user** | A GUID. Store it in an environment variable. |
| **Tracking password** | A GUID. Store it in an environment variable. |
| **User token** | The long user token Pošta Slovenije issued, exactly as given, without quotes. |
| **Use the unofficial posta.si point finder** | Off by default. See below. |

This uses the tracking API that replaced the old service on 1 October 2025. It covers the last 60
days, ten barcodes per request. There is no sandbox.

**Pickup points are off by default.** Pošta Slovenije has no public pickup-point API. With **Use
the unofficial posta.si point finder** on, Carrier searches by postcode or place name, live, using
the JSON behind the office finder on posta.si. It is not a supported interface and may change or
stop working without notice. Leave it off unless you offer Paketomat delivery and accept that risk.

## Magyar Posta

The MPL API v2, written against Magyar Posta's own published PDFs.

**Connecting.** Create an application at [devportal.posta.hu](https://devportal.posta.hu). Sandbox
and production keys are separate.

| Field | What to put there |
|---|---|
| **Environment** | Production, or Sandbox / test. The sandbox is a mock service with weaker validation. Each environment has its own key and secret (devportal → Applications). |
| **API key** / **API secret** | The consumer key and secret of your devportal application. |
| **Customer code** | Sent as `X-Accounting-Code` on every request. It maps one-to-one to the API key. |
| **Agreement code** | The contract the parcels are posted under, printed on your MPL contract. |
| **Bank account for COD** | Where MPL pays collected cash on delivery, e.g. `11112222-33334444-00000000`. |
| **COD paid out** | By bank transfer, or in cash. Bank transfer needs the account above. |
| **Webshop id** | Any short id, default `1`. MPL echoes it back, so one account can serve several shops. |
| **Days held for collection** | 5 or 10 days. |

**Services.**

| Code | Service | Pickup point |
|---|---|---|
| `A_175_UZL` | MPL Business Parcel | |
| `A_175_UZL-CS` | MPL Business Parcel to a parcel terminal | Yes |
| `A_175_UZL-PP` | MPL Business Parcel to a PostaPont | Yes |
| `A_175_UZL-PM` | MPL Business Parcel to a post office | Yes |
| `A_177_MPC` | MPL Postal Parcel | |
| `A_177_MPC-PM` | MPL Postal Parcel to a post office | Yes |

**Label formats:** PDF, A6 thermal; PDF, A4 sheet (A6 four-up); and ZPL, which is A6 because MPL
allows ZPL only on A6-type labels.

**Pickup points.** Post offices, PostaPont shops and parcel terminals, synced daily. See
[pickup points](/plugins/craft-carrier/docs/pickup-points).

**Close the day.** MPL dispatches nothing until the day's shipments are closed. Use **Close the
day** under **Carrier → Collections** once a day for each dispatch site, after the last label, not
per parcel. The close returns the posting list (a PDF) and indicative prices in HUF, which are
stored with the close. They are not rates, and Carrier does not quote with them.

**Once a shipment is closed, it disappears from MPL's shipping API.** You can no longer void or
reprint it, though tracking still works. Void and reprint before you close.

**Things to know.**

- **Parcel terminals** take one parcel each, at most 20 kg and at most 500,000 HUF cash on
  delivery. The compartment size, S (31 × 25 × 7 cm), M (50 × 31 × 16 cm) or L (50 × 31 × 35 cm),
  is chosen from the parcel's dimensions. A Postal Parcel takes one parcel, up to 10 kg. The
  heaviest parcel MPL takes is 40 kg.
- **Cash on delivery is in whole forints.** Any currency other than HUF is refused.
- **Multi-parcel consignments** take up to 16 parcels. The first tracking number is the consignment
  number, which is the number MPL tracks; the other parcels get their piece barcodes, which are all
  kept with the shipment.
- **Warnings still produce a label.** MPL returns errors and warnings in one list. A warning (such
  as error 33, "address cannot be identified") still returns a tracking number and shows as a
  shipment warning. MPL recommends fixing error 33 by voiding and resubmitting.
- **MPL ignores unknown fields**, so a mistake there is silently dropped rather than refused.
- **Tracking is one number per call.** MPL asks you to poll at most hourly and recommends every four
  hours.

## ELTA Courier

**Verify before production.** ELTA Courier publishes no API documentation. Its WSDLs and a PDF come
with the contract. This driver is built from public third-party integrations and needs checking
against a live account and the WSDLs in your contract bundle before you ship real parcels with it.

**Plain HTTP.** The web services run at `http://212.205.47.226:9003`: unencrypted HTTP to a bare IP
address. Your user code, password and customer code cross the internet in clear text on every
request. Your host must also allow outbound connections to port 9003; **Test connection** says so
when it cannot reach it.

**Connecting.** Ask your branch, on +30 210 607 3000 or at info@elta-courier.gr.

| Field | What to put there |
|---|---|
| **User code** | Digits only. The test account is `9999999`. |
| **Password** | The test account's is `9999999`. |
| **Customer (sender) code** | Your ELTA Courier customer code. The test account is `999999999`. |
| **Customer sub-code** | Only if ELTA Courier gave you one for this dispatch point. |
| **Service code** | The `pel_service` value from your contract. Default `1`, the standard service. |
| **Endpoint** | Default `http://212.205.47.226:9003`. Change it only if your contract WSDL names a different address. |

The test account runs on the same endpoint and does not support cash on delivery.

**Services.**

| Code | Service |
|---|---|
| `standard` | ELTA Courier |

**Label formats:** PDF, A6 thermal, and PDF, A4 sheet. The A4 layout does not line up with
perforated label sheets. Reprints fetch one voucher per call, so several ELTA labels are never
merged into one document.

**Things to know.**

- **No voids.** ELTA Courier's web services cannot cancel a voucher. Tell your local branch.
- **No pickup points.** ELTA has a store-by-postcode lookup, but its answer is undocumented and
  nothing in the voucher call sends a parcel to a store.
- **One parcel per voucher.** Carrier refuses a shipment of several parcels and asks you to split
  it, because the child vouchers ELTA creates for extra pieces cannot be read.
- **Buying a label takes two calls**: one creates the voucher and one fetches the PDF. If the
  voucher is created but the PDF cannot be fetched, the shipment is marked uncertain and the
  message gives the voucher number. Do not buy it again: ELTA cannot void it. Record the voucher as
  created and reprint it.
- **Cash on delivery is in euros only.**
- **Tracking has no status codes**, only Greek titles, which are mapped by keyword. A proof of
  delivery always means delivered, whatever the titles say. Titles that match no known phrase are
  written to the connection log so the mapping can be extended.

## Not yet verified

Every assumption that could not be checked against a primary source is marked **UNVERIFIED** in
that carrier's class. Test each post in its sandbox or test account before you go live; Pošta
Slovenije has none.

**Hrvatska pošta**

- The page size of the default label (taken to be A6).
- The Paketomat compartment dimensions. Hrvatska pošta publishes them on posta.hr; the values used
  are typical locker sizes, not Hrvatska pošta's own.
- That a post office's point id is its postcode. The documentation implies it but never says so.
- How long after creation a shipment can still be cancelled (presumably until it is collected).
- Hrvatska pošta's public tracking page.
- Reading "ready in a Paketomat", which has no scan of its own, from the parcel's history.

**Pošta Slovenije**

- The structure of the tracking answer. Pošta Slovenije has not published it and says the field
  names changed; Carrier reads the old service's field names, and maps statuses by Slovenian
  keyword because the status-code list is not public either.
- The tracking token's lifetime.
- Whether the public tracking link works without the per-contract `guid` parameter.
- How the point finder's single address line splits into postcode and city.

**Magyar Posta**

- The sandbox token URL.
- How the label reprint call encodes several tracking numbers.
- The answer to a successful void.
- The pickup-point fields beyond id, name, address and coordinates.
- The public tracking link.

**ELTA Courier**

- The voucher call's answer fields (`st_flag`, `st_title`, `vg_code`).
- The label call's PDF element (`b64_string`), and which credential it expects.
- The SOAP endpoint path and `SOAPAction`. The endpoint is editable on the connection.
- Whether the body elements are namespace-qualified, number formats and field lengths.
- The Greek tracking wording, and the date format of tracking events.

See [all carriers](/plugins/craft-carrier/docs/carriers) for the rest of the family.
