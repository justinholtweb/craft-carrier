# SEE carriers: DPD EasyShip (HR/SI), GLS MyGLS, BOX NOW

Researched 2026-10-03 for craft-carrier. Plain HTTP, no SDKs. Anything not confirmed by an official
document or a live probe is marked **UNVERIFIED**.

## Sources

| Ref | What | Notes |
|---|---|---|
| [DPD-PDF] | *DPD EasyShip Webservice User Manual for DPD Slovenia and DPD Croatia*, v2.2, 2025-08-05. https://www.dpd.com/wp-content/uploads/sites/262/2026/04/DPD_EasyShip_Web_Services.pdf | Official, 38 pp. Primary DPD source. |
| [DPD-SDK] | DataLinx/DPD-PHP-SDK (`datalinx/dpd-php-sdk`), used by `craftsnippets/craft-dpd-easyship` (Craft plugin) | Older auth style (username/password query params). |
| [DPD-PROBE] | Live unauthenticated probes of easyship.hr / easyship.si, 2026-10-03 | Shows backend version 2.0.45, 401/405 behaviour. |
| [GLS-PDF] | *MyGLS API for system integration*, ver. 25.12.11, change log up to 2026-03-30. https://api.mygls.hr/docs/MyGLS_API.pdf (also listed at https://api.mygls.hr/index_en.html) | Official, 46 pp. Primary GLS source. |
| [GLS-SAMPLE] | Official sample code zip https://api.mygls.hr/docs/mygls_api_20260331.zip → `PHP/php_rest_client.php` | Shows the exact JSON wire format. |
| [GLS-SDK] | webapix/mygls-sdk (`webapix/mygls-sdk`, MIT) | Unofficial PHP client, confirms field names and byte-array handling. |
| [GLS-MAP] | Public delivery-point JSON `https://map.gls-croatia.com/data/deliveryPoints/hr.json` (+ widget `gls-dpm.js`) | Probed live: 1,276 HR points. |
| [BN-PDF] | *BOX NOW API Integration, Partner API* v1.65 (BG edition). https://www.boxnow.bg/media/PDF/API%20integration%20manual%20BOX%20NOW%20-%20EN%20v1.69.pdf (file is named v1.69, cover says 1.65) | Official. Written for BG, but it's the same Partner API across all BOX NOW countries. |
| [BN-WP] | Official WordPress plugin *BOX NOW Delivery Croatia + Slovenia* v3.3.7 (`box-now-delivery-croatia`) | Confirms the HR hostnames, payloads, cancellation and phone normalisation. |
| [BN-PROBE] | Live probes of api-*.boxnow.{hr,si,gr,bg,cy} and locationapi-*.boxnow.* | 2026-10-03. |

---

## 0. Which "EasyShip"?

- **DPD EasyShip** (easyship.hr / easyship.si) is DPD Croatia's and DPD Slovenia's web portal plus web API. The
  manual covers **only SI and HR**. Other DPD units in the region use different systems: DPD Hungary has
  Weblabel; DPD Romania and DPD Bulgaria/Speedy have their own APIs (**UNVERIFIED**, out of scope here). If the
  merchant says "DPD EasyShip" and ships from Croatia, this is almost certainly what they mean. Their EasyShip
  portal login is the API credential.
- **Easyship.com** is an unrelated multi-carrier aggregator (Bearer token API, quotes rates). A Croatian merchant
  could plausibly say "Easyship" for it, but it doesn't really carry DPD HR domestic COD. **Ask the merchant
  for the login URL**: `easyship.hr` means DPD; `app.easyship.com` means the aggregator.

---

## 1. DPD EasyShip (Croatia + Slovenia)

### 1.1 Base URLs, transport, auth
| Country | Base |
|---|---|
| HR | `https://easyship.hr/api/` |
| SI | `https://easyship.si/api/` |

- **No sandbox** is documented. Test against production with real credentials, then **delete** the parcel
  (allowed while it's unprinted). **UNVERIFIED** whether DPD will provide a test account on request.
- Auth: `Authorization: Basic base64(username:password)`, the same credentials as the easyship web portal [DPD-PDF].
  - Old style (`?username=..&password=..` as query params, as used by [DPD-SDK] and still shown in the
    manual's workday example): a probe with bad creds via query params returned **401 with an empty body**,
    while Basic returned **401 `Invalid username or password`**. Use Basic. Whether query-param auth still works
    with valid credentials is **UNVERIFIED**.
- Every parameter goes in the **query string**, including on POST (all manual examples, and [DPD-SDK], POST with
  an empty body and the params URL-encoded). Whether a form-encoded body also works is **UNVERIFIED**.
- Use the method the manual specifies. Probing showed `/parcel/status` answers **405 to POST** (`Allow: HEAD, DELETE, GET, OPTIONS`)
  and `/parcel/import` answers **403 to GET**.
- Response header `easyship-backend-version: 2.0.45` (useful in logs).

### 1.2 Create parcel: `POST /api/parcel/import`
Receiver fields (max length; * = required):

| Param | Len | Notes |
|---|---|---|
| `name1`* | 35 | receiver company / person |
| `name2` | 35 | extra name |
| `contact` | 35 | contact person |
| `street`* | 35 | street **without** house number |
| `rPropNum`* | 8 | house number, **separate field**, case-sensitive ("4A") |
| `city`* | 35 | |
| `country`* | 2 | ISO2 |
| `pcode`* | 9 | must be valid for the country (validated) |
| `email` | 50 | phone **or** email required for B2C/export. A literal `+` must arrive as `%2B` |
| `phone` | 30 | examples use `38591223441` (no `+`); return examples use `+385…` |
| `sender_remark` | 50 | courier note |
| `weight` | 4 | kg, `.` decimal. Required for Shop/PUDO types. **Per-parcel weights repeat the key**: `&weight=4.5&weight=6.5` |
| `num_of_parcel`* | 2 | number of labels / parcels |
| `order_number` / `order_number2` | 20 | references |
| `parcel_type`* | 20 | see below |
| `parcel_cod_type` | 10 | `avg` \| `all` \| `firstonly` (how COD is split across multi-parcel shipments) |
| `cod_amount` | 10 | required for COD, in **destination-country currency** (EUR for HR and SI), `.` decimal |
| `cod_purpose` | 14 | required for COD: COD reference (keep it 14 chars or less) |
| `predict` | 1 | `1`: Predict notification, **mandatory for B2C** |
| `pudo_id` | 7 | PUDO / locker ID for direct-to-shop, e.g. `HR00006` |
| `dimension` | 9 | `LLLWWWHHH` cm, zero-padded, no separators (`010020030`). Required for DPD Shop + Pallet |
| `sender_*` | | `sender_name`(30) `sender_city` `sender_pcode` `sender_country` `sender_street` `sender_phone` `sender_email`(100). Overrides what's printed on the label; the sender otherwise comes from the EasyShip profile |
| `return_*` | | alternative return address (if one is set, name/street/city/pcode/country become required) |
| `return_of_document`, `is_id_check`, `id_check_receiver`, `id_check_num` | | extras |
| `barcode=true` | | also return the 27-digit barcode string |

`parcel_type` codes:

| Use | Code |
|---|---|
| B2B | `D` |
| B2B + COD | `D-COD` |
| Home (B2C) | `D-B2C` |
| Home + COD | `D-COD-B2C` |
| Home + COD + return label | `D-COD-B2C-RP` |
| **Parcel shop / locker** | `D-B2C-PSD` |
| **Parcel shop + COD** | `D-B2C-COD-PSD` |
| Ship-from-shop (to home / business) | `SFSB2C` / `SFSB2B` |
| Other | Tyre `D-TYRE`, `D-TYRE-B2C`; Exchange `D-SWAP`; Pallet `PAL`; food/fresh/frozen `D-FOOD*`/`D-FRESH*`/`D-FROZEN*`; limited quantity `D-LQ*` |

COD is selected by the **parcel type**. A COD amount on a non-COD type is presumably ignored or rejected
(**UNVERIFIED**). Map: method (home vs PSD) × COD flag → one code.

Minimal HR B2C COD to a parcel shop:
```
POST https://easyship.hr/api/parcel/import?name1=Ana%20Horvat&street=Ilica&rPropNum=10&city=Zagreb
  &country=HR&pcode=10000&phone=385911234567&email=ana%40x.hr&num_of_parcel=1&weight=1.2
  &parcel_type=D-B2C-COD-PSD&pudo_id=HR00006&cod_amount=49.90&cod_purpose=ORD1001
  &parcel_cod_type=all&predict=1&order_number=ORD1001&dimension=030020010
Authorization: Basic …
```
Response (JSON):
```json
{"status":"ok","errlog":null,"pl_number":["16972000336597"]}
```
- `pl_number[]` holds one 14-digit parcel number per parcel and is the tracking number. Error:
  `{"status":"err","errlog":"Sender street is mandatory (ES-356)","pl_number":[]}`. Parse `(ES-\d+)` from
  `errlog` for the code (table §15 of the manual: 301–448, e.g. 336 "Service code restricted for receiver postal
  code", 383 "Cod amount limit exceeded", 410–413 parcel-shop problems, 414/415 dimension).
- The HTTP status on a validation error is **UNVERIFIED** (likely 200). Always branch on `status`.

### 1.3 Labels: `POST /api/parcel/print`
- Params: `parcels`: comma-separated parcel numbers (max length 500, roughly 33 numbers), `format`: `PDF_A4` | `PDF_A6` | `ZPL`.
  If `format` is omitted, the portal's *Settings → Printer configuration* decides.
- Response: raw bytes, `Content-Type: application/octet-stream` (PDF or a ZPL string). On error you get a JSON
  body `{"status":"err","errlog":"…"}`. **Detect by sniffing**: `%PDF` prefix, ZPL `^XA`, otherwise try JSON decode.
- Bulk: one call with many parcel numbers gives one document. A4 layout (how many labels per page) is fixed by
  DPD/portal settings. There's no 1/2/4-per-page parameter (**UNVERIFIED** beyond the 3 format values).
- **TRAP:** print is only allowed while the status is `created`/`printed`. After printing, DPD transmits the
  data automatically **within about 2 minutes**, the status becomes `sent`, and **any later print returns an
  empty PDF**. **Persist the label bytes the first time** and serve reprints from storage.

### 1.4 Delete / cancel
- `POST /api/parcel/delete?parcels=n1,n2` only works for **not yet printed** parcels.
  Success `{"status":"1 deleted","errlog":null}`, failure `{"status":"No matched parcels","errlog":"1 not deleted"}`.
  `status` is free text, so parse the leading integer.
- `POST /api/parcel/cancel?parcels=…` works **after printing / after the data was sent**. Returns `{"status":"1 cancelled","errlog":null}`.
  Cancelled parcels drop off the manifest.
- Void strategy: try `delete`, and if it reports `0`/"not deleted", fall back to `cancel`. Alternatively choose by
  the last known status (CREATED/NOT_YET_PRINTED → delete, otherwise cancel).

### 1.5 Tracking: `GET /api/parcel/status?parcel_number=N`
- One parcel per call. Returns **only the latest status**, no history and no timestamp: `{"parcel_status":"DELIVERED"}`.
  Rich history is only available on DPD's public tracking page
  (`https://www.dpdgroup.com/hr/mydpd/my-parcels/track?parcelNumber=N`, which sits behind Cloudflare, so don't scrape it).
- Values are mixed-case, some contain spaces. **Compare case-insensitively.**

| EasyShip status | Normalised |
|---|---|
| CREATED, NOT_YET_PRINTED, PRINTED, PREPARED_TO_SEND, SENT | pre_transit |
| DRIVERS_PICK_UP, PICK_UP, HUB_SCAN, Inbound, LOADING, WAREHOUSE, CONSOLIDATION, CUSTOMS_CLEARANCE_PROCESS, EXPORT_IMPORT_CLEARED | in_transit |
| OUT_FOR_DELIVERY | out_for_delivery |
| DELIVERED | delivered |
| DELIVERED_BY_DRIVER_TO_DPD_PARCELSHOP | ready_for_pickup |
| DELIVERY_ATTEMPT_NOT_SUCCESSFUL, Inbound exception, DRIVERS_RETURN | exception |
| Return to sender, SYSTEM_RETURN | returned |
| CANCELLED, CANCELLED_SENT, PREPARED_TO_CANCEL, DELETED | voided (no normalised bucket; treat as terminal) |
| UNKNOWN, ADDITIONAL_INFORMATION | no change (keep the previous status) |

Note that DPD doesn't distinguish "collected from the PUDO" from home delivery. Collection from the shop
presumably shows up as `DELIVERED` (**UNVERIFIED**).

### 1.6 Parcel shops / lockers
- **EasyShip has no PUDO list endpoint.** The manual says "Please contact local DPD for the list of PUDO IDs and
  locations". Probing `/api/pudo`, `/api/pudo/list`, `/api/parcelshops` and similar all returned 404.
- The public locator (`dpdgroup.com/hr/mydpd/parcel-shops/search`) is a SPA behind a Cloudflare challenge, so it
  can't be fetched server-side.
- Options: (a) a merchant-uploaded CSV/JSON of PUDO IDs (from DPD) loaded into our points table; (b) let the
  customer type or select an ID; (c) the Geopost/DPD Pickup locator API (needs a DPD-issued key, **UNVERIFIED**
  for HR); (d) ask DPD HR for a feed. Plan for (a) plus manual entry. ID format is `HR` + 5 digits (`HR00006`),
  max length 7.

### 1.7 Pickup / manifest / extras
- Courier pickup ("carorder"): `POST /api/pickupdiff/import` with `name`* `street`* (house number goes in the same field
  here) `postal`* `city`* `contact` `phone` `parcels` (count) `remark` `date_from`* `date_to`*, both `YYYYMMDDHHMM`.
  `date_from` must be **at least 30 minutes in the future**, otherwise `Invalid from date (ES-130)`. Response
  `{"code":0,"message":"OK","dispref":"ES…"}`.
  Cancel: `POST /api/pickupdiff/cancel?dispref=…`. Most contract customers have a daily scheduled pickup, so this is optional.
- End-of-day manifest: `POST /api/parcel/manifest?type=manifest|manifest_cod|summary_list&date=YYYY-MM-DD`
  returns a PDF (octet-stream). It only includes parcels in status `sent`.
- Return label on demand (B2C parcels at most 30 days old): `POST /api/parcel/label/return?returnParcelNum=N&weight=1&dimension=…`
  returns `pl_number[]`. Standalone return label: `POST /api/parcel/import/return` (same fields as import, the
  customer is the sender).
- Digital (QR) return label: `GET /api/parcel/print/digital?parcel_number=N` returns a PNG.
- Collection request (pickup from a third party): `POST /api/collection_request/import` (fields `c*` = pickup party,
  `r*` = receiver, `pickup_date=YYYYMMDD`). Returns `{"status":"OK","reference":"[ES…]","code":0}`. Note the reference
  is wrapped in brackets. Get the parcel number on pickup day: `POST /api/collection_request/search/parcel_number?reference=…`.
- Helpers: `GET /api/date/workingday?date=YYYYMMDD&country=HR` returns `{"content":{"workingDay":true},"status":200}`.
  `POST /api/cities/search/post_code?buCode=028&postCode=10000` validates a postcode (HR BU = `028`, SI = `026`) and
  returns `content[]` (empty if invalid).

### 1.8 Rates
None. No price or quote endpoint exists. Use table rates.

### 1.9 Traps
- **Street/house-number split** is mandatory (`street` + `rPropNum`). You need a robust splitter for Croatian
  addresses ("Ulica grada Vukovara 269F", "Ilica bb"; "bb" means bez broja, i.e. no number). If parsing
  fails, ask at checkout or let the merchant edit.
- Repeated `weight=` keys: PHP's `http_build_query` would emit `weight[0]=…`, so build the query by hand.
- Encode with RFC 3986 (`rawurlencode`), so spaces become `%20` and `+` becomes `%2B`. The manual warns that a raw `+` in an email breaks.
- Field length limits are short (35 chars, 30 for the sender). Truncate with `mb_substr`. The encoding is UTF-8 and
  diacritics are fine.
- `predict=1` plus phone/email is required for B2C, otherwise you get ES-395/396.
- The PSD types need `weight`, `phone`, and mobile or email (ES-411..413).
- `dimension` must be exactly 9 digits (ES-414).
- Labels vanish after about 2 minutes (see 1.3).
- A 401 body is the plain string `Invalid username or password` even though it's labelled `application/json`.

---

## 2. GLS: MyGLS API (HR, SI, HU, CZ, SK, RO, RS)

### 2.1 Base URLs, transport, auth
`https://api.mygls.{cc}/{Service}.svc/json/{Method}` where `cc` ∈ `hr, si, hu, cz, sk, ro, rs`
and `Service` ∈ `ParcelService` | `MasterDataService`.
Test: `https://api.test.mygls.{cc}/…` (separate test credentials from local GLS; the HU sample account in
[GLS-SAMPLE] is explicitly not for customer testing).
Pin a version by suffix, e.g. `PrintLabels_20190201` or `PrintLabels_20251022`. With no suffix you get the latest.

- **Every method is `POST`** with a JSON body and `Content-Type: application/json`. Auth goes in the body of
  every request (`APIRequestBase`):
  ```json
  {"Username":"user@example.hr","Password":[12,250,...64 ints...],"ClientNumberList":[],"WebshopEngine":"Craft Commerce", ...}
  ```
  - `Password` = **SHA-512 of the plaintext password as raw bytes, serialised as a JSON array of 64 integers
    (0–255)**. PHP: `array_values(unpack('C*', hash('sha512', $pwd, true)))`. (The XML/SOAP variant uses base64 instead.)
  - `ClientNumberList`: the doc says "do not use". Omit it or send `[]`. The client number goes on each `Parcel.ClientNumber`.
  - `WebshopEngine`: documented as **REQUIRED** on `PrintLabels`/`PrepareLabels` since 2025-02. Send a constant such as
    `"CraftCommerce"`.
- Credentials: the MyGLS portal login (email) and password, plus the **ClientNumber** (integer) from the GLS contract.
  Collect all three.

### 2.2 Data shapes (field names are UpperCamelCase)
`Parcel`:
| Field | Notes |
|---|---|
| `ClientNumber`* | int |
| `ClientReference` | string, "strongly recommended" (may be required by account rights, error 32). Use the order reference |
| `Count` | 1–99 parcels in the shipment (default 1) |
| `CODAmount` | decimal, 0 = none |
| `CODReference` | string, recommended when there's COD (bank pairing) |
| `CODCurrency` | ISO currency, optional (error 48 if invalid). EUR for HR/SI |
| `Content` | text printed on the label. May be required by account rights (error 33). **Mandatory in RS** |
| `PickupDate` | WCF date `"\/Date(ms)\/"`, defaults to today |
| `PickupAddress`* / `DeliveryAddress`* | `Address` |
| `ServiceList` | `[{ "Code": "...", "<Code>Parameter": {...} }]` |
| `ParcelPropertyList` | optional `[{Content, PackageType(1 Colli…7 Sack), Height, Length, Width (int, cm UNVERIFIED), Weight (decimal kg)}]`. **Weight mandatory for RS** |
| `FinalDeliveryAddress` | fallback home address for PSD if the shop becomes unavailable (new 2026-03) |
| `SenderIdentityCardNumber` | RS only, required there |

`Address`: `Name`* `Street`* `HouseNumber` (**digits only**, 0 is rejected with error 23) `HouseNumberInfo` (building/suffix,
e.g. "A", "/b") `City`* `ZipCode`* `CountryIsoCode`* `ContactName` `ContactPhone` `ContactEmail`.

COD in GLS is expressed by `CODAmount > 0`. A `COD` service code also exists in Appendix B. Sending just
`CODAmount` is what [GLS-SAMPLE] and [GLS-SDK] do, and whether `{"Code":"COD"}` must also be added is
**UNVERIFIED** (believed not needed).

Useful service codes (Appendix B; availability depends on country and contract):
- `PSD` parcel shop / locker delivery: `{"Code":"PSD","PSDParameter":{"StringValue":"<delivery point id>"}}`.
  **Requires `DeliveryAddress.ContactName`, `ContactPhone` and `ContactEmail`.**
- `FDS` FlexDelivery email: `{"Code":"FDS","FDSParameter":{"Value":"a@b.hr"}}`. `FSS` FlexDelivery SMS:
  `FSSParameter.Value` "+385…", **requires FDS too** (error 30).
- `SM2` SMS pre-advice `SM2Parameter.Value` (intl phone). `SM1` custom SMS `"+385…|text #ParcelNr#"`.
- `CS1` contact service (phone), `AOS` addressee only, `INS` insurance (Count must be 1, error 28), `SAT` Saturday,
  `T09/T10/T12` express, `24H`, `DDS` day-definite (`/Date()/`), `SDS` time window (UTC `/Date()/` time-of-day), `XS` exchange,
  `PSS`/`PRS` pick&ship / pick&return, `SRS` shop return (HU, SI only), `DPV` declared value (HR 20xxx zips only).

### 2.3 Create shipment and get a label in one step: `POST …/ParcelService.svc/json/PrintLabels`
```json
{
  "Username":"user@shop.hr","Password":[...64 ints...],"WebshopEngine":"CraftCommerce",
  "ParcelList":[{
    "ClientNumber":1234567,"ClientReference":"ORD1001","Count":1,
    "CODAmount":49.90,"CODReference":"ORD1001","CODCurrency":"EUR","Content":"Order ORD1001",
    "PickupDate":"\/Date(1791100800000)\/",
    "PickupAddress":{"Name":"Shop d.o.o.","Street":"Slavonska avenija","HouseNumber":"6","HouseNumberInfo":"A",
      "City":"Zagreb","ZipCode":"10000","CountryIsoCode":"HR","ContactName":"Warehouse","ContactPhone":"+38512345678","ContactEmail":"ops@shop.hr"},
    "DeliveryAddress":{"Name":"Ana Horvat","Street":"Ilica","HouseNumber":"10","City":"Zagreb","ZipCode":"10000",
      "CountryIsoCode":"HR","ContactName":"Ana Horvat","ContactPhone":"+385911234567","ContactEmail":"ana@x.hr"},
    "ServiceList":[{"Code":"PSD","PSDParameter":{"StringValue":"HR10000-PARCELLOC101"}},
                   {"Code":"FDS","FDSParameter":{"Value":"ana@x.hr"}}],
    "ParcelPropertyList":[{"Weight":1.2,"PackageType":2}]
  }],
  "TypeOfPrinter":"A4_2x2","PrintPosition":1,"ShowPrintDialog":false
}
```
Response:
```json
{"Labels":[37,80,68,70,...],"PrintLabelsErrorList":[],
 "PrintLabelsInfoList":[{"ClientReference":"ORD1001","ParcelId":123456789,"ParcelNumber":5012345678}]}
```
- **Two IDs per parcel**: `ParcelId` (internal DB id, needed for reprint, delete and GetPrintData) and `ParcelNumber`
  (tracking number, `long`). Store both. When `Count > 1` you get one info row per sub-parcel.
  **UNVERIFIED** whether the sub-parcels share a ParcelId (DeleteLabels returns a `SubParcelIdList`, which suggests a parent id with children).
- `Labels` is the **PDF (or ZPL) as a JSON array of byte ints**. Decode with `implode(array_map('chr', $labels))`, or
  faster with `pack('C*', ...$labels)`. Expect large payloads (one A4 label is about 75 KB, so roughly 300K ints in the JSON),
  so raise memory and timeout. The official sample sets 1 GB / 600 s.
- The `PrintLabels_20251022` versioned method adds `PIN` (locker PIN for labelless P&S) to the info rows.
- Two-step alternative: `PrepareLabels` (validates and stores; returns `ParcelInfoList[{ClientReference,ParcelId}]` +
  `PrepareLabelsError[]`; `PrepareLabelsV2` is the same with faster internals), then `GetPrintedLabels`
  (`ParcelIdList` max 99, plus the print params) returns `Labels`, `PrintDataInfoList[{ParcelId, ParcelNumber,
  ParcelNumberWithCheckdigit, Depot, Sort, Driver, B2CChar, …}]`, `GetPrintedLabelsErrorList`. Parcel numbers are assigned at
  GetPrintedLabels time.
- `GetPrintData` (`ParcelIdList` or `ParcelList`) returns the data needed to render your own label (`Pdfdocument`, `PrintDataInfoList`).

### 2.4 Label formats
`TypeOfPrinter`: `A4_2x2` (4 per A4 page), `A4_4x1` (4 stacked strips per A4), `Connect`, `Thermo` (PDF for a
thermal printer, approx. A6/100×150, **UNVERIFIED** exact size), `ThermoZPL` (203 dpi), `ThermoZPL_300DPI`,
`ShipItThermoPdf`, `ShipItThermoZpl`. Omitting it uses the MyGLS portal setting. An invalid value gives error 34.
`PrintPosition` 1–4 sets the starting quarter on A4 (for reusing partly used sheets). `HidePhoneNumberOnLabels` (bool) is optional.
Bulk printing: `GetPrintedLabels` with up to 99 `ParcelId`s produces one document. Whether `Labels` contains ZPL text bytes
for the ZPL types is **UNVERIFIED** (presumably the same byte array).
Reprinting an already printed parcel via `GetPrintedLabels` is allowed (the sample flow uses it after PrepareLabels). Whether
reprints work after the parcel has been scanned is **UNVERIFIED**, so persist the bytes anyway.

### 2.5 Delete: `POST …/DeleteLabels`
`{"ParcelIdList":[123456789]}` (**ParcelId, not ParcelNumber**; max 50) returns `SuccessfullyDeletedList[{ParcelId,SubParcelIdList[]}]`
and `DeleteLabelsErrorList`. Expected to fail once GLS has scanned the parcel (presumably error 6 "different status than
PRINTED", **UNVERIFIED**). After physical handover you need to call GLS.
`ModifyCOD`: `{ParcelId | ParcelNumber, CODAmount}` returns `{Successful, ModifyCODError[]}`.

### 2.6 Tracking
- `POST …/GetParcelStatuses` `{"ParcelNumber":5012345678,"ReturnPOD":false,"LanguageIsoCode":"HR"}` returns
  `{ClientReference, DeliveryCountryCode, DeliveryZipCode, ParcelNumber, Weight, POD (byte[] PDF if ReturnPOD),
  ParcelStatusList:[{StatusCode:"5",StatusDate:"\/Date(…+0200)\/",StatusDescription,StatusInfo,DepotCity,DepotNumber}],
  GetParcelStatusErrors[]}`. Full history is returned. `LanguageIsoCode`: EN (default), HR, CS, HU, RO, SK, SL.
- **Batch** (new 2026-03): `POST …/GetParcelListStatuses` `{"ParcelNumberList":[...≤100],"LanguageIsoCode":"HR"}` returns
  `ParcelList[{ParcelNumber,Weight,ClientNumber,ClientReference,ParcelStatusList[]}]` + `GetParcelListStatusesErrors`.
  Use this one for the tracking cron.
- `GetParcelList` `{PickupDateFrom/To | PrintDateFrom/To}` (WCF dates) returns `PrintDataInfoList`, useful for reconciliation or end-of-day.
- `StatusCode` is a **string**. Order the list by `StatusDate`, not by array position (**UNVERIFIED** ordering).

Status mapping (Appendix G codes):
| Codes | Normalised |
|---|---|
| 51 (data entered, not handed over), 83, 84 | pre_transit |
| 1, 2, 3, 6, 7, 9, 10, 22, 24, 26, 27, 41, 46, 47, 53, 80, 85, 86; customs 60–67, 70–76 | in_transit |
| 4, 32 | out_for_delivery |
| 5, 58, 59 (ParcelShop pickup, i.e. collected), 92, 93 | delivered |
| 54 (delivered to parcel box), 55, 56, 97, 8 (stored, consignee collects) | ready_for_pickup |
| 11–21, 25, 28, 29, 30, 31, 33–39, 43, 44, 57 (max shop storage reached), 68, 69, 88, 89, 401–404, 420 | exception |
| 23, 40 | returned |
| 52 (COD data), 99 (email notice) | informational, no change |
| 87, 90, 91 (pickup cancelled), 42 (disposed) | exception / terminal |
(Classification of 8, 9, 24, 54, 59 is a judgement call, **UNVERIFIED** semantically. Look at sample histories once live.)

### 2.7 Parcel shops / lockers
**Option A, the official API** (new 2026-03, **UNVERIFIED live**; the prod probe timed out and test returned 502):
`POST https://api.mygls.hr/MasterDataService.svc/json/GetDeliveryPoints` `{auth…, "CountryIsoCode":"HR", "LastUpdateTime":"\/Date(…)\/"}`
returns `{ErrorCode (0 ok), ErrorDescription, IsChanged, LastUpdateTime, Data: byte[] GZIP JSON}`. Decode with `gzdecode(pack('C*', ...$Data))`
(byte array like Labels, **UNVERIFIED** that it isn't base64), which gives a list of `DeliveryPoint{Id(int), Address{Name,Street,HouseNumber,City,
ZipCode,CountryIsoCode,…}, Latitude, Longitude, Matchcode, LegacyId, DeliveryPointType (1 ParcelShop, 2 ParcelLocker, 3 Depot),
Dropoffpoint, CardPaymentAllowed, CodHandler, PickupTime, VacationFrom/Till, LabelsPrintableTill, IsActive,
DeliveryPointOpeningList[{DayOfWeek 1=Mon..7, Open "HH:mm-HH:mm" ("" closed, "00:00-24:00" 24/7), Break}]}`.
Cache it, and send `LastUpdateTime` to get `IsChanged=false` and an empty payload when nothing changed.
Which field is the PSD `StringValue` (Matchcode vs LegacyId vs Id) is **UNVERIFIED**. Most likely Matchcode, by analogy with the map feed id.

**Option B, the public map feed (verified live, no auth)**: `GET https://map.gls-croatia.com/data/deliveryPoints/{cc}.json`
(`hr`, `si`, `hu`, `cz`, `sk`, `ro`, `rs` all return 200 from this host; country-branded hosts such as `map.gls-slovenia.com`,
`map.gls-hungary.com` also exist). Shape:
```json
{"items":[{"id":"HR10000-PARCELLOC101","goldId":15814,"name":"GLS Paketomat 1060",
  "contact":{"countryCode":"HR","postalCode":"10000","city":"Zagreb","address":"Samoborska cesta 28","name":"…","phone":"…","email":"…"},
  "location":[45.8117,15.8933],"hours":[[1,"00:00","24:00"],…],
  "features":["acceptsCard","pickup","delivery"],"type":"parcel-locker","externalId":"1060",
  "parcelLockerExternalProvider":4,"pickupTime":"14:00","hasWheelchairAccess":false}]}
```
- HR on 2026-10-03: 1,276 points, of which 1,084 `parcel-locker` and 192 `parcel-shop`. Features: `acceptsCash` (shops),
  `acceptsCard` (lockers + some shops), `pickup`, `delivery`.
- `id` is the value used as `PSDParameter.StringValue` (the official example `"2351-CSOMAGPONT"` matches this pattern).
- `location` is `[lat, lng]`. `hours` rows are `[dayOfWeek(1=Mon), open, close]`, sometimes with 2 extra values
  (`[1,"08:00","16:00","10:00","14:00"]`, presumably a break window, **UNVERIFIED**). `address` holds street and number in one string.
- COD to a locker: lockers list `acceptsCard` only, so COD there is paid by card. Respect `features` when offering COD
  to a point (**UNVERIFIED** whether GLS rejects COD+PSD to a point without `acceptsCash`/`acceptsCard`).
- Checkout widget: `<script type="module" src="https://map.gls-croatia.com/widget/gls-dpm.js"></script>` + `<gls-dpm>` element,
  which fires a `change` CustomEvent with `e.detail` (the point object). Attributes such as country/language are **UNVERIFIED**.
- Unofficial and undocumented, but it's what GLS's own widget uses. Prefer Option A once it's confirmed working, and keep B as a fallback.

### 2.8 Pickup / manifest
No pickup-request or manifest method exists in the MyGLS API. Pickup is the contract's daily collection, and `PickupDate` on the
parcel tells GLS when it should be collected. Use `GetParcelList` by print date for an end-of-day list rendered by us.

### 2.9 Rates
None. Table rates.

### 2.10 Errors and traps
- **HTTP 200 even for auth failure.** Verified live: bad credentials return 200 with
  `{"GetParcelStatusErrors":[{"ErrorCode":-1,"ErrorDescription":"Unauthorized.","ClientReferenceList":[],"ParcelIdList":[]}],…}`.
- The error list key **differs per method**: `PrintLabelsErrorList`, `PrepareLabelsError`, `GetPrintedLabelsErrorList`,
  `GetPrintDataErrorList`, `DeleteLabelsErrorList`, `ModifyCODError`, `GetParcelListErrors`, `GetParcelStatusErrors`,
  `GetParcelListStatusesErrors`; MasterData uses top-level `ErrorCode`/`ErrorDescription`.
  `ErrorInfo{ErrorCode int, ErrorDescription, ClientReferenceList[], ParcelIdList[]}` lets you map an error back to the
  failing parcels in a batch. **Partial success is possible**: some parcels are created and others fail in the same call.
- Codes (Appendix A): 13 parcel validation (the description carries the detail), 14 user doesn't exist, 23 house number 0,
  29 count not in 1..99, 30 FSS without FDS, **31 "same request sent 5 times within last 5 minutes"** (a rate-limit or
  idempotency guard, so don't retry blindly, and vary nothing), 32/33 ClientReference/Content required, 34 bad TypeOfPrinter,
  48 bad COD currency, 1000/1001 internal.
- **WCF dates**: send `"\/Date(<unix ms>)\/"`. PHP's default `json_encode` escapes `/` as `\/`, which is exactly what WCF
  needs. **Don't use `JSON_UNESCAPED_SLASHES`** for these payloads. Responses come back as
  `"/Date(1598911199000+0200)/"`, so parse with `/Date\((-?\d+)([+-]\d{4})?\)/` (ms UTC, the offset is informational).
- `SDS` times are UTC (the sample sets `date_default_timezone_set('UTC')`).
- `HouseNumber` is digits only, and the rest goes to `HouseNumberInfo`. Same address-splitting problem as DPD.
- Phones should be in international format (`+385…`), required for SMS services.
- Big integer arrays in both directions, so memory and time limits matter.
- `ParcelNumber` is a `long`. Store it as a string.

---

## 3. BOX NOW (GR, BG, CY, HR, SI)

### 3.1 Base URLs and auth
| Country | Production | Stage (sandbox) |
|---|---|---|
| HR (also serves SI; the official HR+SI WP plugin offers only `.hr` hosts) | `https://api-production.boxnow.hr` | `https://api-stage.boxnow.hr` |
| SI | `https://api-production.boxnow.si` (host exists, 401 unauth) | `https://api-stage.boxnow.si` |
| GR | `https://api-production.boxnow.gr` | `https://api-stage.boxnow.gr` |
| BG | `https://api-production.boxnow.bg` | `https://api-stage.boxnow.bg` |
| CY | `https://api-production.boxnow.cy` | `https://api-stage.boxnow.cy` |
All the hosts above answered 401 on `/api/v1/auth-sessions` with dummy credentials, so they exist. BOX NOW **issues the API_URL with the
credentials**, so make the host a setting, defaulting by country. Whether `.si` credentials work on the `.hr` host and vice versa is **UNVERIFIED**.

Token: `POST {base}/api/v1/auth-sessions`, `Content-Type: application/json`
```json
{"grant_type":"client_credentials","client_id":"…","client_secret":"…"}
```
returns `{"access_token":"eyJ…","token_type":"Bearer","expires_in":3600}`. Cache it for about 55 minutes and send
`Authorization: Bearer …` on every call.
Bad credentials (live): HTTP 401 `{"message":"Requires authentication","status":401,"code":"?","errorClass":"NotAuthenticated","stack":"…"}`.
Optional `X-PartnerID: <id>` header when one login manages several partners (errors P440/P441). List them via `GET /api/v1/entrusted-partners`
(it also returns a `permission{codPayment, addressAsDestination, codAddressAsDestination, anyApmAsOrigin, …}` block, which is
handy for validating settings).

### 3.2 Origins (your warehouses): `GET /api/v1/origins`
Query: `locationType=warehouse|any-apm|depot`, `latlng`, `radius`, `name`, `regionLanguage`. Returns `data[{id, type, name, title, addressLine1,
addressLine2, postalCode, country, lat, lng, note, region}]`. The `id` is `origin.locationId`. Warehouses are registered by BOX NOW at onboarding.
`any-apm` is a pseudo-origin for "merchant drops the parcel into any locker" (then `compartmentSize` is required; P406).

### 3.3 Create: `POST /api/v1/delivery-requests`
```json
{
  "orderNumber":"ORD1001_48213",
  "invoiceValue":"49.90",
  "paymentMode":"cod",
  "amountToBeCollected":"49.90",
  "allowReturn":true,
  "origin":{"locationId":"<warehouse id>","contactNumber":"+38591…","contactEmail":"ops@shop.hr","contactName":"Shop d.o.o."},
  "destination":{"locationId":"1689","contactNumber":"+385911234567","contactEmail":"ana@x.hr","contactName":"Ana Horvat"},
  "items":[{"id":"ORD1001-1","name":"Order ORD1001","value":"49.90","weight":1.2,"compartmentSize":2}],
  "notifyOnAccepted":"",
  "additionalInformation":"Craft Commerce / craft-carrier x.y"
}
```
Response 200: `{"id":"<delivery request id>","parcels":[{"id":"2270192741"}]}` (the §4.4 example shows `referenceNumber`
instead of `id`, and the appendix and the HR plugin use `id`, so read both). **One item = one parcel = one locker compartment.** Parcel IDs are
10-digit strings, and they're also the tracking number shown to customers (tracking page `https://t.boxnow.hr/?track=<parcelId>`, from [BN-WP]).
- `paymentMode`: `prepaid` | `cod`. `amountToBeCollected` is a **string** decimal, in range (0, 5000) (P408). COD needs the
  account permission (P411). Currency is the partner's assigned currency (EUR in HR/SI/GR/CY), and there's no currency field.
- `invoiceValue`: string. The HR plugin sends the order total when COD and `"0"` otherwise.
- `orderNumber` **must be unique forever** (P410 on reuse, even after a cancel). The HR plugin appends `_<random 5 digits>`.
  Do the same, or append an attempt counter, and store the exact value sent.
- `compartmentSize`: 1 small (H 8 × W 45 × L 60 cm), 2 medium (H 17), 3 large (H 36). Max 20 kg. Anything bigger can't ship.
  Hide the method at checkout for oversized carts. Optional when origin is a warehouse, required from any-apm.
  The plugin defaults to 2 when dimensions are unknown. `weight` is in kg, number. "If unknown pass 1". Range 0–1,000,000 (P421).
- Home-address delivery (only with the `addressAsDestination` permission, P415; COD to address needs `codAddressAsDestination`, P416):
  omit `destination.locationId` and send `addressLine1`, `addressLine2`, `postalCode`, `country`, `name`/`title`. Field-level rules are **UNVERIFIED**.
- Other optional fields: `typeOfService` (`same-day`…), `description`, `notifySMSOnAccepted`, `showRecipientInformation`,
  `overwriteSenderShippingLabelInfo{row1..row4}`, `returnLocation{…}`, `items[].originDeliveryParcelId/destinationDeliveryParcelId`.
- Modify: `PUT /api/v1/delivery-requests/{orderNumber}` with `{"allowReturn":true}` only (the doc is inconsistent: `delivery-requests:orderNumber` vs `/{id}`, **UNVERIFIED**).

### 3.4 Labels
- One parcel: `GET /api/v1/parcels/{parcelId}/label.pdf` (`Accept: application/pdf`) returns raw PDF bytes in the body.
  ZPL: `/label.zpl?dpi=200|300[&width=<mm>][&printerModel=…]`. The appendix also shows a JSON label-data shape for this path,
  so use the extension and the Accept header to get the file.
- All parcels of one order: `GET /api/v1/delivery-requests/{orderNumber}/label.pdf` (or `.zpl`).
- Bulk across orders / paper size: `POST /api/v1/labels:search` `{"parcelIds":[…],"orderNumbers":[…],"paperSize":"A6","perPage":1}`.
  The response format and the allowed `paperSize`/`perPage` values (A4 with 2/4 per page?) are **UNVERIFIED**.
- The PDF label is A6-ish by default (**UNVERIFIED** exact size). BOX NOW can also email the label (`notifyOnAccepted`).
- Labels for `customerReturns` come back as URLs (`labels[].url`) and the CSV import returns `parcels[].labelUrl`.

### 3.5 Cancel
`POST /api/v1/parcels/{parcelId}:cancel` (empty JSON body `{}`). Returns 200 with an empty body (the plugin treats empty-2xx or
`{"success":true}` as OK). Idempotent ("cancelling a cancelled parcel has no effect"). Allowed only for new or in-transit,
not-delivered parcels (P420). Cancel is per parcel, so loop over every parcel of the delivery request.
`POST /api/v1/parcels/{id}:declareDeliveryPartnerReturn` covers the case where the partner is returning the parcel.

### 3.6 Tracking
- Poll: `GET /api/v1/parcels?parcelId=<id>` (or `?orderNumber=…`; `limit` max 100, P442; paginate with `pageToken` from
  `pagination.next`). Returns `data[{id, state, events[{type, createTime, locationDisplayName, locationId, postalCode}],
  deliveryRequest{…}, payment{mode, price{amount,currency}, state: pending|paid-by-customer|transferred-to-partner},
  parcelLabelUrl, orderLabelUrl, cancelationRequested, createTime, updateTime}]`.
- Push: a webhook (CloudEvents-style) that BOX NOW configures for you (send them the URL and port). The body has
  `data{parcelId, parcelState, event, time, orderNumber, parcelReferenceNumber, parcelPin, eventLocation{displayName,postalCode}, customer{…}}`.
  Use `data.time` to order and dedupe events. No signature or auth scheme is documented (**UNVERIFIED**), so
  verify by re-fetching the parcel from the API rather than trusting the payload.

| BOX NOW `state` / event | Normalised |
|---|---|
| new | pre_transit |
| in-depot, in-transit, wait-for-load (when not returning) | in_transit |
| final-destination (in the locker, waiting for the customer) | ready_for_pickup |
| delivered (customer collected) | delivered |
| returned, expired-return (not collected in time, going back) | returned |
| canceled / cancelled, cancelled-return | voided (terminal) |
| lost, missing | exception |
There's no out_for_delivery state. Treat a locker load as ready_for_pickup. Spelling varies (`canceled` in the filter enum,
`cancelled` in the definitions), so match both. The full webhook `event` vocabulary is **UNVERIFIED**.

### 3.7 Lockers
- API: `GET /api/v1/destinations?latlng=45.81,15.98&radius=5000&requiredSize=2&locationType=apm&limit=…&name=…`. The doc says the default
  is "all", but the appendix says **`limit` defaults to 50**, so don't rely on it for a full sync.
- **Full list, no auth (verified live)**: `https://locationapi-production.boxnow.{cc}/v1/apms_{lang}-{CC}.json`
  - HR: `…boxnow.hr/v1/apms_hr-HR.json` (909 lockers on 2026-10-03; `en-GB` returns an empty list)
  - SI: `…boxnow.si/v1/apms_sl-SI.json`, GR: `…boxnow.gr/v1/apms_el-GR.json`, BG: `…boxnow.bg/v1/apms_bg-BG.json`
  - Stage: `https://locationapi-stage.boxnow.hr/v1/apms_hr-HR.json` (200)
  - Shape: `{"data":[{"id":"1689","type":"apm","lat":"45.817207","lng":"15.88953","addressLine1":"Bolnička cesta 66",
    "addressLine2":"Zagreb","postalCode":"10090","title":"…","name":"BOX NOW Vrapče - Kebab Agram","country":"HR",
    "image":"https://storage.googleapis.com/…","note":"…","region":"hr-HR","state":"boxnow-ready",
    "expectedDeliveryTime":"2026-10-04T20:00:00.000Z"}]}`
  - `lat` and `lng` are **strings**. `addressLine2` is the city. `state`: `boxnow-ready` (907) or `only-delivery` (2), and the
    exact meaning of `only-delivery` is **UNVERIFIED** (probably no drop-off). Every point is `type:"apm"` (a locker). **No opening
    hours field** (lockers are presumed 24/7; some sit inside shops, so check `note`).
- `id` goes into `destination.locationId`.
- Checkout widget: iframe/popup `https://widget-v5.boxnow.hr/popup.html` (and `.si`, `.gr`, `.bg`; `.cy` still TODO in the plugin), or
  the JS embed `https://widgetcdn.boxnow.{cc}/map-widget/client/v5.js` with `_bn_map_widget_config={partnerId, parentElement, type:'iframe'|'popup'|'navigate', afterSelect(sel){sel.boxnowLockerId, sel.boxnowLockerAddressLine1, sel.boxnowLockerPostalCode}}`.
  **The widget only talks to production.**
- Address to nearest locker: `POST /api/v2/delivery-requests:checkAddressDelivery` `{"city","street","postalCode","radius","compartmentSize"}`
  returns one locker plus `distance` (P422/P423/P465).

### 3.8 Pickup / manifest
No pickup endpoint. BOX NOW couriers collect from the registered origin warehouses on the agreed schedule. There's no
manifest either. `GET /parcels` can be used for an end-of-day list.

### 3.9 Rates
None to quote. `parcels[].payment.price{amount,currency}` exists after creation (presumably the billed price, **UNVERIFIED**).
Use table rates. The 20 kg / 60×45×36 cm limits gate availability.

### 3.10 Errors and traps
- 400 body: `{"code":"P4xx","message":"…"}`. Key codes: P400 bad data, P401/P402 bad origin/destination id, **P405/C404 phone
  must be full international format** (e.g. `+385 91 123 4567`; spaces allowed), P406 compartment size, P407 country ISO2,
  P408 COD amount range, **P410 duplicate orderNumber**, P411 COD not allowed, P415/P416 home delivery not allowed,
  P420 cancel not allowed, P440/P441 X-PartnerID, P442 limit > 100, P461 street > 100, P464 zip > 20, P466 overdue account.
  403 X403 account disabled. 503 P600 (locker bridge) / P610 (geocoding), so retry later.
- Phone normalisation (from [BN-WP]): strip non-digits; `00385…` becomes `+385…`; national `09x…` becomes `+3859x…` (drop the leading 0) for HR;
  `0[3-7]…`/`4x` patterns become `+386…` for SI. Default by store country.
- Money fields are **strings** ("49.90"); `weight` is a number.
- Must send recipient name, phone and email (all are needed for the pickup PIN SMS/email).

---

## 4. Cross-carrier implementation notes
- **Rates**: none of the three quote prices, so all use table rates (plus size/weight gates: BOX NOW 20 kg / 3 compartment sizes).
- **COD**: DPD = `parcel_type` variant + `cod_amount`/`cod_purpose` + `parcel_cod_type`. GLS = `CODAmount`/`CODReference`/`CODCurrency`.
  BOX NOW = `paymentMode:"cod"` + `amountToBeCollected` (string). Currency is EUR across HR/SI.
- **Point selection** → DPD `pudo_id` + `D-B2C(-COD)-PSD`; GLS `PSD` service + contact name, phone and email; BOX NOW `destination.locationId`.
- **Identifiers to persist**: DPD `pl_number[]`; GLS `ParcelId` + `ParcelNumber` per parcel; BOX NOW delivery request `id`, the exact
  `orderNumber` sent, and `parcels[].id`.
- **Persist label bytes** at creation for all three (DPD reprint goes blank after about 2 minutes).
- **Address splitting** (street vs house number) is needed for DPD and GLS. BOX NOW lockers need none.
- **Tracking cron**: GLS batch (≤100), BOX NOW webhook + `/parcels` poll, DPD one GET per parcel with latest status only.
- **HTTP-200 errors**: GLS always (per-method error list key), DPD `status:"err"`, BOX NOW uses real 4xx.
