---
title: Carrier for LTL Freight
slug: freight
order: 104
summary: Six US less-than-truckload carriers in one add-on (TForce Freight, Old Dominion, Estes, XPO, R+L Carriers and SAIA), with quotes, bills of lading, PRO tracking and, for two of them, pickups.
---

Carrier for LTL Freight connects Craft Commerce to six US less-than-truckload carriers, called
directly: **TForce Freight**, **Old Dominion**, **Estes Express**, **XPO**, **R+L Carriers** and
**SAIA**. The add-on is free; it needs Carrier, which is the paid part and owns packing, checkout
methods, document storage, the claim that stops a bill of lading being created twice, the tracking
schedule and the log.

## Install

```sh
composer require justinholtweb/craft-carrier-freight
php craft plugin/install carrier-freight
```

Then **Carrier → Connections → New connection**. All six carriers appear in the picker. The add-on
has no settings screen of its own; everything lives on the connection.

## What each carrier does

| Carrier | Quotes | Bill of lading | Label formats | Void | Pickups | Tracking | Test environment | Ships from |
|---|---|---|---|---|---|---|---|---|
| **TForce Freight** | Yes | Yes | PDF, US Letter; PDF, 4×6; ZPL | | Yes | Yes | Yes (CIE) | US |
| **Old Dominion** | Yes | NMFTA eBOL | PDF, US Letter | Yes | Yes | Yes | Yes (QA host) | US, CA |
| **Estes Express** | Yes | NMFTA eBOL | PDF, US Letter; PDF, 4×6 | | | Yes | Yes (UAT host) | US, CA |
| **XPO** | Yes | Yes | PDF, US Letter | Yes | | Yes | Test mode on bills of lading only | US, CA |
| **R+L Carriers** | Yes | NMFTA eBOL | PDF, US Letter | | | Yes | None | US, CA |
| **SAIA** | Yes, unverified | NMFTA eBOL | PDF, US Letter; PDF, 4×6 | | | Yes, unverified | Yes (pilot gateway) | US |

"PDF, US Letter" is the Avery label sheet. None of the six does returns, cash on delivery, pickup
points, close day or address validation, and none declares multi-piece (see the PRO below).

## How freight maps onto Carrier

LTL is not parcel shipping with heavier boxes, and the add-on treats it accordingly.

- **Each parcel is one handling unit**: a pallet, a crate or a drum. Weight goes out in whole
  pounds and dimensions in whole inches, both rounded up so the shipment is never under-declared.
  Set the freight values on the parcel: `packagingType`, `pieces`, `freightClass`, `nmfc` and
  `hazmat`. Packaging words such as pallet, skid, crate, carton, box, drum, bag, tote, pail, roll,
  reel, bundle and loose are understood; anything else is sent as a pallet.
- **Freight class is checked, not guessed.** "77.5", "77,5" and "92.50" are all accepted. "77" is
  refused, because it is not one of the eighteen NMFC classes. A handling unit without a class uses
  the connection's **Default freight class**; if that is None too, the shipment is refused and names
  the handling unit.
- **NMFC subs keep their leading zero.** `123456-03`, `123456 03` and `12345603` all become item
  `123456`, sub `03`. An integer sub is how "03" becomes 3 and a reclass invoice.
- **The bill of lading is the purchase.** Creating a shipment creates a BOL. The BOL PDF is stored
  as a document of kind `bol`, and any shipping labels as kind `label`. See [labels](/plugins/craft-carrier/docs/labels).
- **The PRO is the tracking number.** There is one PRO per shipment however many pallets it has.
- **Accessorials are offered only when they can be both priced and printed.** Each carrier maps
  them twice, once for its rating vocabulary and once for its BOL vocabulary, and declares only
  those it can map in both. A liftgate that was quoted but left off the BOL, or the reverse, is how
  merchants get surprise invoices.
- **Residential comes only from the accessorials.** Residential pickup and residential delivery are
  driven by those two accessorials, never by the address's residential flag: that flag defaults to
  true for checkout addresses, and LTL carriers charge real money for it.
- **A hazmat handling unit makes the shipment hazmat**, whether or not anyone ticked the
  accessorial.
- **Quote numbers.** Old Dominion, Estes, R+L and SAIA honour a quoted price only when the BOL cites
  the quote number. Carrier does not yet carry the checkout quote through to label time, so these
  four ask for a fresh quote for the shipped service when the BOL is created, and cite that. If the
  re-quote fails, the BOL is still created, with a warning that the carrier will bill its tariff
  rate. To skip the re-quote, pass `options['quoteId']` on the request.
- **A BOL that exists but cannot be completed is uncertain, never a refusal.** If a carrier accepts
  the BOL but returns no PRO, or returns a PRO with no readable PDF, a retry would create a second
  BOL. The shipment waits on **Problems** with the carrier's ids in the message.

### Settings every freight connection shares

| Field | What to put there |
|---|---|
| **Default freight class** | Used for any handling unit that arrives without a class. Leave it as None to refuse such shipments rather than guess. |
| **Commodity description** | Printed on the bill of lading when a handling unit has no description of its own. Default `General merchandise`. |

## TForce Freight

Formerly UPS Freight. Rating, BOLs, pickups and tracking on `api.tforcefreight.com`, behind a
Microsoft identity platform (Azure AD) client-credentials token.

**Connecting.** At developer.tforcefreight.com, go to **Profile → Configure My Client**.

| Field | What to put there |
|---|---|
| **Environment** | Production, or Sandbox / test. The sandbox is TForce's Customer Integration Environment: the same host with `api-version=cie-v1`. CIE rates are not your contract rates. |
| **Client ID** | From Configure My Client. |
| **Client secret** | Shown only once, when the client is created. Store it in an environment variable. |

**Services.**

| Code | Service |
|---|---|
| `standard` | TForce Freight LTL |
| `guaranteed` | TForce Freight LTL Guaranteed |
| `accelerated` | TForce Freight Accelerated Guaranteed |

**Accessorials:** liftgate at pickup and delivery, limited access pickup and delivery, inside
delivery, notify before delivery, residential pickup and delivery, hazardous materials.

**Things to know.**

- **A misspelled or unknown property rejects the whole request.** Every body is built from
  documented keys only, and empty values are stripped. Test in CIE first.
- **Label formats.** The 4×6 thermal label is the only one that allows ZPL; the Letter PDF is the
  Avery sheet.
- **A 403 is a quota** (it resets after five minutes), and a 429 a rate limit (one minute). Both
  carry Retry-After, which Carrier honours.
- **No void.** TForce documents cancelling a pickup, not a BOL. Cancel a BOL with TForce.
- **No re-quote.** TForce returns a quote number, but no BOL field cites one.

## Old Dominion

REST rating, the NMFTA eBOL, pickups and tracking. Production `api.odfl.com`, QA `apiq.odfl.com`.

**Connecting.**

| Field | What to put there |
|---|---|
| **Environment** | Production, or Sandbox / test, which uses ODFL's QA host. Ask API@odfl.com for QA access. |
| **ODFL.com username** / **ODFL.com password** | Your ODFL.com login. It is exchanged for a session token that lasts an hour. |
| **ODFL account number** | Your bill-to account, for contract pricing. Digits only. |

**Services.**

| Code | Service |
|---|---|
| `standard` | Old Dominion LTL |
| `guaranteed-pm` | Old Dominion Guaranteed by 5 PM |
| `guaranteed-noon` | Old Dominion Guaranteed by Noon |

**Accessorials:** liftgate at pickup and delivery, residential pickup and delivery, inside
delivery, limited access pickup and delivery, delivery appointment, notify before delivery,
hazardous materials.

**Things to know.**

- **Bad credentials are a 400**, "Invalid Credentials", not a 401. So is a disabled account.
- **Appointment and notify cannot be combined.** ODFL refuses call-for-appointment together with
  arrival notice. With both asked for, notify is dropped and you are warned.
- **Each service is rated in its own request**, because ODFL only returns a quote number for a
  single service and rate type. A rate type ODFL cannot price comes back empty inside a successful
  answer, with its own error message.
- **Tracking errors arrive inside an HTTP 200.** "No results found" is read from the body.

## Estes Express

Rating, the NMFTA eBOL and tracking. Production `cloudapi.estes-express.com`, test
`uat-cloudapi.estes-express.com`.

**Getting credentials takes three steps.**

1. Email WebSupport@estes-express.com. Estes sends a client ID and secret, separate for test and
   production.
2. Call `POST /v1/api-key` once per environment with that client ID and secret. It returns the
   **API key**, and it replaces the emailed secret.
3. Every call then authenticates with that key plus your MyEstes login. The add-on does this part.

| Field | What to put there |
|---|---|
| **Environment** | Production, or Sandbox / test (`uat-cloudapi.estes-express.com`). Estes issues separate credentials and API keys for each. |
| **API key** | From step 2. |
| **MyEstes username** / **MyEstes password** | Your MyEstes login. |
| **Estes account number** | Your shipper account, for contract pricing. |

**Services.**

| Code | Service |
|---|---|
| `standard` | Estes LTL Standard |

Guaranteed, volume and exclusive-use levels need a tare weight that Carrier's parcels do not carry,
so only standard LTL is offered.

**Accessorials:** liftgate at pickup and delivery, residential pickup and delivery, inside
delivery, limited access pickup, delivery appointment, hazardous materials.

**Things to know.**

- **No limited access delivery.** Estes has no general rating code for it, only site types such as
  construction sites, so it is not offered: the BOL would print a service nobody priced.
- **No notify.** It is not on Estes's BOL list.
- **Alaska** has its own accessorial codes at Estes, and they are not mapped.
- **Every answer carries an error object**, with code 0 on success, and it is checked even on a
  200. A missing rate comes with Estes's reasons.
- **No pickups and no void.** Book and cancel pickups with Estes.

## XPO

Rating, BOLs, BOL and label PDFs, voids and tracking.

**Connecting.** Email LTLWebAPISupport@xpo.com from your registered XPO web user to subscribe.

| Field | What to put there |
|---|---|
| **Consumer key** / **Consumer secret** | From XPO when you subscribe. |
| **XPO LTL web username** / **XPO LTL web password** | Your XPO LTL web login. Tokens last 12 hours. |
| **XPO account (acctInstId)** | Your shipper account instance id, for contract pricing. |
| **Test mode** | XPO has no sandbox. Test mode marks bills of lading as tests; rating and tracking always use production. |

**Services.**

| Code | Service |
|---|---|
| `standard` | XPO LTL |
| `guaranteed` | XPO Guaranteed |

**Accessorials:** liftgate at pickup and delivery, residential pickup and delivery, inside
delivery, notify before delivery, delivery appointment, hazardous materials.

**Things to know.**

- **There is no sandbox.** With **Test mode** on, BOLs are marked as tests. A test-mode BOL cannot
  be cancelled.
- **No PRO comes back when the BOL is created.** XPO assigns one afterwards, and the add-on reads it
  back. If it is still empty, the shipment is uncertain: the BOL exists, but there is no number
  anyone can track. Find it in XPO's portal by the BOL instance id in the message. If you have
  pre-assigned PROs, pass `options['proNumber']` and the problem does not arise.
- **No limited access**, because XPO's rating list does not show it.
- **COD has been disabled at XPO** since 29 May 2025.
- **Rate limits are per token**, from 5 to 100 calls a minute depending on your tier. Carrier
  honours XPO's 429 and Retry-After.
- **No pickups.** Book them with XPO.

## R+L Carriers

Rating, the NMFTA eBOL, BOL and label printing, and tracking, on `api.rlc.com`.

**Connecting.** At rlcarriers.com, go to **Resources → API Overview → Access API Key**. The
account must be enabled to accept external rate requests.

| Field | What to put there |
|---|---|
| **API key** | From rlcarriers.com. R+L has no test environment: every bill of lading is real. |

**Services.**

| Code | Service |
|---|---|
| `standard` | R+L Standard LTL |

R+L's guaranteed service codes are not published, so only standard is offered.

**Accessorials:** liftgate at pickup and delivery, residential pickup and delivery, inside
delivery, limited access pickup and delivery, delivery appointment, hazardous materials.

**Things to know.**

- **No sandbox.** Every BOL you create is real. Try your setup with quotes first.
- **A wrong API key is a 400**, not a 401, so it reads as a refusal. For a BOL that is correct:
  nothing was created.
- **No notify.** R+L publishes no notify-before-delivery service.
- **PROs can carry a letter prefix**, such as `I875682972`.
- **When the BOL answer carries no documents**, the add-on fetches the BOL and labels separately.
- **No pickups and no void.** Book and cancel them with R+L.

## SAIA

Rating, the NMFTA eBOL and tracking, on SAIA's API gateway: production `api.saia.com`, pilot
`saiapilotapi.azure-api.net`.

**Read this first.** SAIA does not publish the response formats for its REST rating and tracking
APIs. Both are read using the field names of SAIA's older SOAP API, and tracking statuses are taken
from the words SAIA uses. A rate that cannot be read is reported as no rate, never invented.
**Capture real answers from the pilot gateway before you rely on SAIA rates at checkout.**

**Connecting.** SAIA has one subscription key per product, each from the developer portal
(**Products → subscribe → primary key**), plus your Saia Secure login.

| Field | What to put there |
|---|---|
| **Environment** | Production, or Sandbox / test, which uses SAIA's pilot gateway. The pilot has its own subscription keys. |
| **Saia Secure username** / **Saia Secure password** | Your saiasecure.com account. Sent in the body of rate requests and as Basic auth for tracking. |
| **Rate Quote key (RQ-Key)** | The Rate Quote product's key. |
| **Customer Tracking REST key (Tracking-Key)** | The tracking product's key. |
| **Bill of Lading key (Ocp-Apim-Subscription-Key)** | The NMFTA eBOL product's key. |

Your password travels in the body of every rate request, so every configured secret is removed
from the request log by value.

**Services.**

| Code | Service |
|---|---|
| `standard` | SAIA LTL Standard |

**Accessorials: none.** SAIA does not publish its REST rating accessorial codes, so nothing could
be priced reliably, and a liftgate on the BOL that the quote never priced is a surprise invoice. A
hazmat handling unit still gets the hazmat code on the BOL.

**Things to know.**

- **No pickups.** SAIA has no public REST pickup API, and the requested pickup date on a BOL does
  not book one.
- **Volume quote numbers start with `E`.** They are cited on the BOL like any other.
- **No void.** Cancel BOLs with SAIA.

## Not yet verified

These connectors were written from each carrier's published documentation without live accounts.
Everything that could not be confirmed from a primary source is marked **UNVERIFIED** in its
carrier class. Validate each carrier in its test environment before going live. The NMFTA eBOL
itself has a test flag (`bol.isTest: true`) that validates a BOL without creating anything, which
is useful when checking the party objects by hand.

**All six**

- The public tracking page URLs.
- The NMFTA eBOL party objects (`origin`, `destination`, `billTo`) for Old Dominion, Estes, R+L and
  SAIA. No primary source shows their exact nesting; the builder follows Estes's swagger.

**TForce Freight**

- The BOL party fields, and the subset of BOL request options sent. TForce rejects any property it
  does not recognise, so CIE will say so loudly.
- The liftgate and limited-access delivery codes. TForce's manuals contradict each other; the
  add-on uses `LIFD` for liftgate and `LADL` for limited access.
- Reference numbers: their shape is not documented, so the order reference is not sent.
- Pickup field names beyond those listed, and the date format.
- The error body, and how negotiated rates are linked to your account: rates are whatever the API
  client's enrolment gives.

**Old Dominion**

- Whether rating accepts a decimal freight class such as 77.5. If QA refuses it, raise it with
  API@odfl.com.
- The key the session token comes back under (several are accepted).
- The HTTP method of the BOL void (DELETE is sent).
- The fields of each shipment on a pickup request.
- Rate limits, which are not documented.

**Estes Express**

- Tracking: the endpoint is in Estes's developer site but not in its published swagger.
- Whether Estes accepts the notify code (not offered for that reason).
- Pickups: the endpoint exists, but its request body is not documented, so pickups are not
  implemented.
- Rate limits.

**XPO**

- When an automatically assigned PRO becomes readable.
- Limited access in rating, which is therefore not offered.
- Whether the PDF answers are base64 or raw bytes (both are accepted).
- NMFC subs, which are not sent.
- The error object's field names.

**R+L Carriers**

- The eBOL endpoint's NMFTA version and answer shape, and whether it accepts every NMFTA
  accessorial code sent.
- The label style used for the Avery sheet.
- The pickup date format, and the tracking status codes (statuses are read from the words).
- Pickups: the endpoint exists, but its request body is not documented, so pickups are not
  implemented.
- Rate limits.

**SAIA**

- The rating and tracking answer formats (see above), and Basic auth for tracking.
- The rating accessorial codes, which is why none are offered.
- The pallet code (`PAT`), the field the quote number is cited in on the BOL, the account-number
  field for customer pricing (not sent), and the handling-unit count in rating.

See [all carriers](/plugins/craft-carrier/docs/carriers) for the rest of the family.
