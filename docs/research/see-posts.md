# SEE / central-Europe national posts — implementer notes

Drivers in the `posts` package: **Hrvatska pošta (HR)**, **Pošta Slovenije (SI)**, **Magyar Posta / MPL (HU)**, **ELTA Courier (GR)**.

Researched 2026-10-03. Plain HTTP, no SDKs. Labels used below:

- **[P]**: verified from a primary source (the carrier's own docs/PDF/docx, or a live public endpoint).
- **[S]**: verified from a public third-party module (cited).
- **UNVERIFIED**: no public primary or credible source. This is the best-documented shape found; confirm it against the contract docs before you ship.

Source copies (PDF/docx → txt) are in the session scratchpad. Re-download them from the URLs given in each section.

## Summary matrix

| | HP (HR) | Pošta Slovenije (SI) | MPL (HU) | ELTA Courier (GR) |
|---|---|---|---|---|
| Transport | REST/JSON | REST-ish JSON envelope (create), REST/JSON (tracking) | REST/JSON | SOAP 1.1 (document/literal, Micro Focus NetExpress) |
| Auth | user/pass → JWT bearer (4h) | Basic + IDs in body (create); Basic → JWT bearer (tracking) | OAuth2 client_credentials (Basic) → bearer (~30–60 min) | user code + password + customer code in every SOAP body |
| Public sandbox | yes, `dxwebapit.posta.hr` (credentials on contract) | yes for create (`tstePortal`), none for tracking | yes, `sandbox.api.posta.hu` (mock) | test credentials `9999999` (S) |
| Create | yes | data submission only, you supply barcode | yes | yes (`CREATEAWB`) |
| Label from API | PDF (base64), A4 4-up or single | **no** (you print it yourself; only the CN23 PDF comes back) | PDF or ZPL, many sizes | PDF base64, A4 or A6 |
| Cancel | yes, by client ref | **no API** (UNVERIFIED) | `DELETE` until closed | **no API**, phone the branch |
| Tracking | yes, batch | yes, ≤10 per call | yes: single sync, 500 async, push | yes, one per call |
| Pickup points | yes, API (POs + Paketomat) | no API; undocumented website JSON | yes, API + XML feed | ZIP → station only |
| Rates | no | no | indicative only, returned by close | no |
| End-of-day close | no | "end submission" step | **mandatory** `/shipments/close` | no |

---

## 1. Hrvatska pošta (Croatia) — "HP Shipping Service API" / DXWebAPI

**Primary sources [P]**

- EN: https://www.posta.hr/UserDocsImages/posta/Paket24/API/HP_Shipping_Service_Api__Documentation.docx
- HR: https://www.posta.hr/UserDocsImages/posta/Paket24/API/HP_Shipping_Service_Api_Dokumentacija_lekt-final.docx

Both are v1 (28.11.2022) and their content is identical. You must be a contract customer; Hrvatska pošta issues the credentials. Product name: "Paket 24".

### 1.1 Auth [P]

```
POST https://dxwebapit.posta.hr:9000/api/authentication/client_auth   (TEST)
POST https://dxwebapi.posta.hr:9000/api/authentication/client_auth    (PROD)
Content-Type: application/json
{"username":"…","password":"…"}
→ 200 {"accessToken":"<JWT>","expiresIn":"14400"}
```

- `expiresIn` is a **string**, in seconds (4 h). Cache the token and refresh it about 5 min early.
- The business API runs on a **different port (9020)**. Base URL: `https://dxwebapi[t].posta.hr:9020/api/{format}/{methodName}`. Send `Authorization: Bearer <accessToken>` and `Content-Type: application/json`.
- Port trap: non-443 ports (9000/9020) are often blocked by shared-hosting egress firewalls. Surface a clear error for this.

### 1.2 Create — `POST :9020/api/shipment/create_shipment_orders` [P]

Request `ShipmentOrdersData`: `{ "parcels": [ShipmentOrderData…], "return_address_label": bool }`. Set `return_address_label: true` and the response includes the labels.

`ShipmentOrderData`:

| field | type | req | notes |
|---|---|---|---|
| `client_reference_number` | string ≤100 | Y | **Unique per order.** This is the cancel key, and also a label key |
| `service` | int | Y | `24` pallet, `26` Paket24 D+1, `29` D+2, `32` D+3, `38` D+4. The examples send it as the string `"26"` |
| `payed_by` | int | | 1 sender (default), 2 recipient |
| `delivery_type` | int | Y | 1 address, 2 post office, 3 parcel locker |
| `pickup_type` | int | | 1 address, 2 post office, 3 parcel locker |
| `value` | decimal(2) | | insured value |
| `payment_value` | decimal(2) | Y if additional service 9 | **COD amount** (EUR) |
| `parcel_size` | `X`/`S`/`M`/`L` | Y if delivery_type=3 | Locker compartment size (XS/S/M/L); see posta.hr/dimenzije-pretinaca/9279 |
| `reference_field_B/C/D` | string ≤100 | | free refs, not unique |
| `sender` | obj | Y | see below |
| `recipient` | obj | Y | see below |
| `additional_services` | `[{additional_service_id:int}]` | | `1` personal delivery, `3` Saturday, `4` AR (advice of receipt), **`9` COD**, `30` recipient notification, `32` recipient e-mail, `47` consolidated |
| `packages` | list | Y | one entry per piece (multi-piece supported) |

`sender` fields: `sender_name`(50), `sender_phone`(25), `sender_email`(25), `sender_street`(50), `sender_hnum`(10), `sender_hnum_suffix`(10), `sender_zip`(5), `sender_city`(25), `sender_pickup_center`(5, required if pickup_type is 2 or 3).

`recipient` fields: the same set with the `recipient_` prefix. `recipient_delivery_center`(5) is **required if delivery_type is 2 or 3**: it is the post-office code or the Paketomat `code`.

`packages[]` fields: `barcode_type` (Y): `1` = HP assigns the barcode (the normal case), `0` = you pre-hold an HP barcode. Then `barcode` (≤20, req if type 0), `barcode_client` (≤500, req if type 1: your own piece ref), `weight` (kg, 3 dp, Y).

Minimal COD-to-locker example:

```json
{"parcels":[{"client_reference_number":"ORD-1001","service":26,"payed_by":1,
 "delivery_type":3,"pickup_type":1,"payment_value":49.90,"parcel_size":"S",
 "sender":{"sender_name":"Shop d.o.o.","sender_phone":"+38511234567","sender_street":"Ilica",
   "sender_hnum":"1","sender_hnum_suffix":"","sender_zip":"10000","sender_city":"Zagreb"},
 "recipient":{"recipient_name":"Ana Horvat","recipient_phone":"+385981234567",
   "recipient_email":"ana@example.hr","recipient_street":"Vukovarska","recipient_hnum":"5",
   "recipient_hnum_suffix":"","recipient_zip":"21000","recipient_city":"Split",
   "recipient_delivery_center":"<paketomat code>"},
 "additional_services":[{"additional_service_id":9}],
 "packages":[{"barcode":"","barcode_type":1,"barcode_client":"ORD-1001-1","weight":1.2}]}],
 "return_address_label":true}
```

Response `CreateShipmentOrdersMainResponse`:

```json
{"ShipmentOrdersList":[{"ClientReferenceNumber":"ORD-1001","ResponseStatus":0,
   "Packages":[{"barcode":"LE900002111HR","barcode_type":1,"barcode_client":"ORD-1001-1","weight":3.0}],
   "ErrorCode":null,"ErrorMessage":null,"MessageID":""}],
 "ShipmentsLabel":"<base64 PDF>"}
```

- **Tracking number** = `ShipmentOrdersList[i].Packages[j].barcode`. The format is S10, e.g. `LE…HR`.
- There is no shipment-level tracking number. Use the first piece's barcode as the primary.
- `ResponseStatus`: 0 OK, 1 ERROR, **per order**. Keys switch to PascalCase in responses; requests are snake_case.

### 1.3 Labels — `GET :9020/api/shipment/get_shipping_labels` [P]

- **GET with a JSON body.** The doc's own PHP sample does `CURLOPT_CUSTOMREQUEST=>'GET'` + `CURLOPT_POSTFIELDS`. Many HTTP clients (Guzzle with default options, some proxies) silently drop GET bodies, so use raw curl or force the body.
- Body: `{"client_reference_number":"ORD-1001","barcodes":[{"barcode":"LE…HR"}],"A4":false}`. Send `client_reference_number` **or** `barcodes`.
- `A4:true` gives 4 labels per A4 page. `false`/null gives the default single-label page. Its size is not stated in the doc; it is presumably 10×15/A6 (UNVERIFIED).
- Response: `{"PackageLabel":"<base64 PDF>","ResponseStatus":0,"ErrorCode":null,"ErrorMessage":null}`.
- **PDF only. No ZPL** is documented.

### 1.4 Cancel — `POST :9020/api/shipment/cancel_shipment_orders` [P]

- Body: `{"parcels":[{"client_reference_number":"ORD-1001"}]}`. Cancellation is **by client reference, not by barcode**.
- Response is a bare array: `[{"ClientReferenceNumber":"ORD-1001","ResponseStatus":0,"ErrorCode":null,"ErrorMessage":null}]`.
- The doc does not say until when cancellation is allowed. Assume it is only possible before collection (`PRI`/`ZPR`) (UNVERIFIED).

### 1.5 Tracking — `GET :9020/api/shipment/get_shipment_status` (GET + JSON body again) [P]

- Body: `{"barcodes":[{"barcode":"LE347087966HR"},…]}`. Batch is supported; no max is documented.
- Response is an array: `[{"Barcode":"…","PackageScansList":[{"Scan":"NOV","ScanDescription":"Definirani podaci o pošiljci","ScanTime":"2022-11-16T11:15:33.257","Center":"ROOT","Comment":null,"CommentNPA":"5000002"}],"ResponseStatus":0,"ErrorCode":null,"ErrorMessage":null}]`.
- `ScanTime` has no timezone; treat it as Europe/Zagreb local time.
- The `ScanDescription` text is Croatian.

Scan code → normalized status (scan meanings [P], mapping is ours):

| Scan | meaning | normalized |
|---|---|---|
| NOV | new parcel / data defined | pre_transit |
| ZAP | courier assigned for pickup | pre_transit |
| NPR | pickup not done (reason in CommentNPA 105000x) | pre_transit (flag a warning) |
| PRI | collected from sender | in_transit |
| ZPR | received into system / hub | in_transit |
| OTP | dispatched between depots | in_transit |
| ZOT | received from another depot | in_transit |
| UKO | loaded into container | in_transit |
| PRE | rerouted (destination change) | in_transit |
| IZM | parcel data changed | (ignore for status) |
| ZAD | assigned to delivery courier | out_for_delivery |
| **IOD** | **delivered (final)** | delivered |
| IND | delivery failed | exception. Exceptions to that: if `CommentNPA` = `7010004` (for collection at post office) or `7010017` (other PO) → ready_for_pickup; if `7010003` → returned |
| ODG | stored / delivery delayed (warehousing) | ready_for_pickup when delivery_type is 2 or 3, else exception |
| POV | sent for return to sender | returned |
| IPP | delivered back to sender (final) | returned |

Useful `CommentNPA` codes: `5006001`/`5006002` (parcel announced to the locker), `2303001`–`2303004` (locker handover failed: full, out of order, does not fit), `2210001` (return parcel dropped in locker), `3900004` (refused), `3900007` (no money for COD), `3900019` (damaged).

There is **no explicit "ready in Paketomat" scan**. Infer it from `ZOT`/`ODG` at a locker centre plus delivery_type 3 (UNVERIFIED heuristic).

### 1.6 Pickup points — `GET :9020/api/delivery_point/get_parcel_delivery_point` (GET + body) [P]

- Body: `{"facilityType":"ALL"|"PU"|"PAK","nextWeek":0|1,"searchText":""}`. **All three fields are required.**
- `PU` = post offices (the doc mislabels these as "post lockers"); `PAK` = Paketomat.
- An empty `searchText` returns everything; otherwise it matches name, place, ZIP, locker code or street.
- Response (the doc's example lacks the outer `{`):
  - `postaInfoList[]`: `type, name, zip, city, address, tel, fax, geoLatitude, getLongitude, workingHours[{weekDay 1=Mon..7=Sun, date, openingTime{hour,minute,second}, closingTime{…}, closed "0"|"1"}]`
  - `paketomatInfoList[]`: `type, name, zip, city, address, geoLat, getLng, code`
- **Traps:**
  - Coordinate keys differ per list and are typo'd: `getLongitude` and `getLng`.
  - Post offices have **no `code` field**. The doc implies the post-office code used in `recipient_delivery_center` is its 5-digit `zip`, but this is not stated explicitly (UNVERIFIED).
  - Cache the list daily.
- Normalized fields: id = `code` (lockers) or `zip` (POs); name; address/zip/city; lat/lng; type = `locker` or `post_office`.

### 1.7 Rates

There is no rate endpoint. Use price tables from the contract.

### 1.8 Errors and traps

- **Errors are per item.** `ResponseStatus:1` + `ErrorCode` (int) + `ErrorMessage` (+ `MessageID` on create).
- HTTP status follows MDN semantics (401 means the token expired).
- No error-code catalogue is published (UNVERIFIED codes).
- Request is snake_case, response is PascalCase (`Packages[].barcode` stays lowercase).
- The create sample shows `sender_pick_up_center` in the example while the table says `sender_pickup_center`. **Use `sender_pickup_center`**, the table name, which is also what the cURL sample uses.
- Field limits are tight: email ≤25 (!), city ≤25, zip ≤5, name ≤50. Truncate or validate before sending. A 25-char e-mail limit will reject many real addresses, so drop the e-mail rather than fail.
- `sender_hnum_suffix` / `recipient_hnum_suffix` are marked required. Send `""`.
- House number is a separate field, so the driver needs a street/number splitter.
- The docs state no currency. Croatia uses EUR since 2023; COD is in EUR.

---

## 2. Pošta Slovenije (Slovenia)

There are **three separate, contract-only services**, each with its own credentials, all issued by the "skrbnik pogodbe" (contract manager). This is the least-public of the four posts.

### 2.1 Shipment data submission — eSpremnica.Provider.WebApi ("eOddaja") [P]

Source: https://espremnica.posta.si/Dokumenti/eOddajaServiceJSON.pdf (Nov 2019). The legacy WCF/XML specs are in the same folder: `eOddajaServiceXML.pdf` (2009) and `eOddajaServiceCSV.pdf` (2016).

- Base: **TEST** `https://tstePortal.posta.si/Services/eSpremnica.Provider/WebApi/api/`, **PROD** `https://ePortal.posta.si/Services/eSpremnica.Provider/WebApi/api/`.
- **Auth:** HTTP Basic (user/pass from PS), **plus** an `Authentication` object in every body: `{"KomitentId":int,"PogodbaId":int,"PodruznicaId":int,"PostaId":"string"}` (customer / contract / branch / acceptance-post IDs).
- **Response is gzip'd, base64'd, with a 4-byte length prefix, and UTF-16LE.** The doc's PHP is `gzdecode(substr(base64_decode($body), 4))`; then convert from `UTF-16LE` to UTF-8. That conversion is implied by the C# `Encoding.Unicode` and the hex example, but the PHP sample omits it. Check `Content-Encoding: gzip`.
- Envelope `ProviderRequest`: `{ "Authentication":{…}, "Guid":"…", "Data":{…}, "Route":"…", "DataType":4 }`. DataType: 2 CSV, 3 XML, **4 JSON**.
- Envelope `ProviderResponse`: `{ "Code":200, "Value":…, "Route":"…" }`. Codes: 200, 400, 401, 403, 408, 412 InvalidArguments, 500, 501, 550 ValidationError, 560 NoData, 600 TryAgain. On a non-200 `Code`, `Value` is an error **string**.

Flow (asynchronous):

1. `POST eOddaja/R_GetGuid` → `Value` = submission GUID.
2. `POST eOddaja/C_PostData`, with `Guid` set and `Data` set to the shipment records.
3. Wait 15–30 s, then poll `POST eOddaja/R_GetStatus` while `ResultCode` is 1 (Transmitted). The poll returns `Value` = `{ResultCode, Errors[{RowNumber, Description, DescriptionEN}], ProcessNumber}`.
   - ResultCode values: 0 InTransmission, 1 Transmited, 2 InProgress, 3 ProcessingWithoutErrors, 4 ProcessingWithErrors, 5 InvalidInputData, 6 NoData.

`eOddajaData` record [P]:

| field | type | notes |
|---|---|---|
| `RecordNumber` | int | row number |
| `ParcelType` | int | shipment type, a code **PS gives you** (UNVERIFIED values) |
| `Parcels[]` | list | see below |
| `CommonServices[]` | list of `eOddajaService` | shipment-level services |
| `Addressee` | obj | see below |
| `Upn` | obj | UPN payment slip (COD-by-slip) |
| `Comment1`, `Comment2` | string | comments |

- `Parcels[]` fields: `ParcelNumber` (**the barcode you supply**), `Weight`, `Volume`, `AdditionalServices[]`, `Cn23[]`, `International`, `ParcelContent` (0 DOCUMENTS / 1 GOODS).
- `eOddajaService` = `{Code:string, Amount:decimal}`. **COD is a service code + Amount.** The PS service-code list is not public. The 2009 XML schema shows numeric `SID` service IDs and a separate `ODK` (odkupnina = COD) decimal (UNVERIFIED for JSON).
- `Addressee` fields: `AddresseeName1`, `AddresseeName2`, `AddresseeAddress`, `AddresseePostId` (postcode), `AddresseePost` (post name), `AddresseeCountryId`, `AddresseePhone`, `AddresseeEmail`.
- `Upn` fields: `UPNType` (2 OCR, 3 no OCR, 4 HUB, 5 QR, 6 no QR), `UPNPurpose1/2`, `UPNModel`, `UPNReference`, `UPNIBAN`.

**Implications:**

- **No tracking number is returned.** You allocate barcodes from a **range PS assigns** to you ("unikaten nabor sprejemnih številk", per https://www.posta.si/poslovno/spletne-trgovine/opremljanje-posiljk). Barcodes are S10 with a check digit, e.g. `CH611134035SI`. The prefix per product comes from PS, and the driver must run a barcode allocator (a counter + S10 mod-11 check digit).
- **No label endpoint.** You render the label yourself (barcode + PS layout spec from PS, UNVERIFIED). The only server PDF is the **CN23** customs form: `POST CN23/C_Document` with `CN23Document` (`docNumber`, `consignee{name,surname,organizationName,street,houseNumber,post,city,countryCode,phone,email}`, `shipment{items[{goodsDescription,quantity,weight,amount{amountValue,currencyCode},originCountryCode,tariffNumber}],category{categoryType,explanation},…,amountCOD{amountValue,currencyCode},nonDeliveredStatusCode,articles[]}`), then `POST CN23/R_Report` with `{docNumber}` → `Value` = base64 PDF.
- **No cancel endpoint** is documented (UNVERIFIED). Practically, do not hand the parcel over, or call PS.
- **Locker (PS Paketomat) delivery:** no documented field. It is presumably a service code + the Paketomat ID in the address/service (UNVERIFIED). Ask PS for the "alternativne možnosti dostave" spec.
- The legacy XML spec (2009) shows an explicit **"end submission" call (`KonecOddaje`)** after posting. The JSON API folds this into the GUID lifecycle (UNVERIFIED).

### 2.2 Tracking — new Azure API, live since 2025-10-01; the old WCF is switched off [P]

Sources:

- FAQ: https://www.posta.si/api-za-sledenje-faq
- VS-Code REST-client script: https://www.posta.si/poslovno-site/Documents/API/eSledenje-pogodbeniki-core-api.pdf

Endpoints:

- OAuth: `POST https://oauth.appservice.azuremb.posta.si/api/oauth.token`
  - Headers: `Content-Type: application/json`, `Authorization: Basic base64(user:pass)`. The REST-Client syntax `Basic user:pass` auto-encodes. The user/pass are GUIDs from PS; the PDF prints example GUIDs, which should not be reused.
  - Body: the **raw JSON string token** PS gives you (the "user token"), e.g. `"…"`.
  - Response body = **the JWT itself** (the script reads `response.body.*`). Expiry is in the JWT `exp` claim.
- Base: `https://tracking.appservice.azuremb.posta.si/api/`. All calls use `Authorization: Bearer <JWT>`.
  - `GET easistecho`: health check.
  - `POST parcels.select.barcode`: body is a JSON string `"CH611134035SI"`. Covers the last 60 days.
  - `POST parcels.select.barcode.queue`: body `["CH…SI", …]`, **max 10**. Covers 60 days.
  - `POST parcels.select.year.barcode`: body is a JSON string. Covers up to 1 year.
  - `POST parcels.select.client`: body `{"ClientId":"…","Days":1..5}`. Returns everything handed over in the last N days. This is the best fit for a cron sync.
- **Response shape is not published.** The FAQ says field names changed versus the old API (UNVERIFIED). The legacy WCF (`https://sledenje.posta.si/TrackWebService.svc`, retired) returned `DogodkiPosiljke[]` with `PostaId`, `PostaNaziv`, `Status`, `OpisStatusaStranka`, `PrevodStatusa`, `PredalId`, `Opomba`, `DatumDogodka`, `VrnjenoPostaId` (returned), `PreposlanoPostaId` (forwarded), `CrtnaKoda`, `EMS*` (international). Expect a similar event list.
- **The status code list is not public** (UNVERIFIED). Map on keywords in the description as a fallback. Slovenian terms:

| Slovenian term | normalized |
|---|---|
| "Sprejem" | in_transit |
| "Prispelo", "V obdelavi" | in_transit |
| "V dostavi" | out_for_delivery |
| "Vročeno" | delivered |
| "Obvestilo", "čaka na prevzem", "Paketomat" | ready_for_pickup |
| "Vrnjeno" / VrnjenoPostaId set | returned |
| "Nevročljivo" | exception |

  Customer-facing tracking link: `https://sledenje.posta.si/Default.aspx?tn=<barcode>&guid=<per-contract>&lang=si` [P].

### 2.3 Pickup points

- The "Alternativne možnosti dostave" web service exists but is contract-only and undocumented in public (UNVERIFIED).
- **Undocumented public website JSON [P, live-probed 2026-10-03, not an API contract, may change]:**
  - `GET https://www.posta.si/_vti_bin/PostaSI/PostOffices/PostOfficesService.svc/PostalOffice/PostalClasses/0` → `[{"Description":"Paketomat","Id":32},{"Description":"Bencinski servisi","Id":128},…]`
  - `GET …/PostOfficesService.svc/PostalOffice/{postcode-or-text}` → array of points. Fields:

| field | meaning |
|---|---|
| `PostalId` | id: `"1101"` for a post office, `"SIP1701"` for a PS Paketomat, `"2SIB…"` for a fuel station |
| `PostalClassNumAndTitle` | e.g. `"PS Paketomat 1701 Ljubljana"` |
| `PostalAddress` | address |
| `VrstaEnote` | 0 post office, 2 Paketomat, 1 partner/fuel station |
| `PostalClassGroup` | 1 PO, 32 Paketomat, 128 fuel station |
| `Pin.GPSx` | **latitude** (despite the x) |
| `Pin.GPSy` | **longitude** |
| `FormatWorkingDays[]` | opening hours |

  - Also available: `…/GetPostOffices` (postcode list) and `…/GetServices`.
  - Use this only behind a feature flag with caching, and prefer the contract service.
  - The locker ID that eOddaja expects is unknown (UNVERIFIED). Possibly `SIPxxxx`.

### 2.4 Rates

No rate API. Use contract price lists.

### 2.5 Errors and traps

- The gzip+base64+4-byte-prefix+UTF-16 response wrapper on eOddaja.
- Envelope `Code` is not the HTTP status. HTTP can be 200 while `Code` is 550.
- Errors come back asynchronously, only via `R_GetStatus.Errors[]` when ResultCode is 4.
- Credentials are separate per service: eOddaja Basic + 4 IDs; tracking Basic + user token → JWT.
- The tracking `select.barcode` body is a bare JSON string, not an object.

---

## 3. Magyar Posta — MPL API v2 (Hungary)

**Primary sources [P]** (index at https://devportal.posta.hu/en/documentation):

- Technical description: https://www.posta.hu/static/internet/download/MPLAPI_technical_description.pdf
- Domestic integration recommendations (2026-08): https://www.posta.hu/static/internet/download/AM1017_MPL_API_integracios_javaslat_belfold_HU_EN_2026.pdf
- Samples: https://posta.hu/static/internet/download/mplapi_samples.pdf
- Error codes: https://www.posta.hu/static/internet/download/MPLAPI_errorcodes.pdf
- Label types: https://www.posta.hu/static/internet/download/MPLAPI_Cimirat_tipusok_EN.pdf
- Tracking (single item): https://www.posta.hu/static/internet/download/Tracking_technical_description.pdf
- Tracking (500 items, async): https://www.posta.hu/static/internet/download/MPLAPI_pull_500_tracking_en.pdf
- Tracking (push): https://www.posta.hu/static/internet/download/MPLAPI_push_tracking_en.pdf
- Tracking events: https://www.posta.hu/static/internet/download/Tracking_events.pdf
- International: https://devportal.posta.hu/sites/default/files/2025-12/MPLAPI_integration_recommendations_international.pdf
- Mutually exclusive services: https://www.posta.hu/static/internet/download/Kizaro_szolgaltatasok_en.pdf
- ZPL printer objects: https://www.posta.hu/static/internet/download/MPL_ZPL_PrinterObjects.zip

The full OpenAPI YAML/Swagger is behind the devportal login (https://devportal.posta.hu/api/7).

Secondary source [S]: Sylius plugin `gtgt/sylius-mpl-shipping`, https://github.com/gtgt/sylius-mpl-shipping, `src/Api/MplApiClient.php`.

### 3.1 Auth [P]

Base URLs:

- PROD: `https://core.api.posta.hu/v2/mplapi`
- SANDBOX: `https://sandbox.api.posta.hu/v2/mplapi` (a **mock** service)

Separate key/secret per environment (devportal → Applications).

Token request:

```
POST https://core.api.posta.hu/oauth2/token        (sandbox: https://sandbox.api.posta.hu/oauth2/token [S])
Authorization: Basic base64(client_id:client_secret)
Content-Type: application/x-www-form-urlencoded
grant_type=client_credentials
→ {"access_token":"…","issued_at":1592910455065,"expires_in":1799,"token_type":"Bearer"}
```

- The doc text says 3600 s, but the example shows 1799. Honour `expires_in`.

Headers on **every** business call:

| header | required | notes |
|---|---|---|
| `Authorization: Bearer <token>` | yes | Basic auth is also accepted |
| `X-Accounting-Code: <customer code>` | yes | |
| `X-Request-ID: <GUID>` | yes | **validated as a GUID; the request is rejected if malformed** |
| `X-Correlation-ID: <GUID>` | no | optional |

- There is a 1:1 mapping between the customer code and the API key. One customer can have several **agreement codes** (`sender.agreement`).
- Do not exceed 5 parallel threads when submitting.

### 3.2 Create — `POST /shipments` (body is a JSON **array** of shipments) [P]

```json
[{
  "developer":"YourVendor","webshopId":"1","orderId":"ORD-1001",
  "labelType":"A6","labelFormat":"PDF",
  "tag":"warehouse-1",
  "sender":{"agreement":"10000319","accountNo":"11112222-33334444-00000000",
    "contact":{"name":"Shop Kft","email":"info@shop.hu","phone":"+3611234567"},
    "address":{"postCode":"1138","city":"Budapest","address":"Dunavirág utca 2."},
    "parcelTerminal":"true"},
  "recipient":{"contact":{"name":"Kiss Anna","email":"anna@example.hu","phone":"+36201234567"},
    "address":{"postCode":"9023","city":"Győr","address":"Sport utca 1",
      "parcelPickupSite":"107206"},
    "disabled":false},
  "item":[{"customData1":"ORD-1001","weight":{"value":1500,"unit":"G"},"size":"S",
    "services":{"basic":"A_175_UZL","extra":["K_UVT","K_ENY","K_ORZ"],
      "cod":4200,"value":4200,"deliveryMode":"CS"}}],
  "paymentMode":"UV_AT","packageRetention":5,"groupTogether":false
}]
```

Field notes:

- `services.basic`: `A_175_UZL` (MPL Business Parcel) or `A_177_MPC` (MPL Postal Parcel). International examples use `A_121_CSG` and `A_125_HAR`.
- `deliveryMode`:

| code | meaning | Postal Parcel? | weight limit |
|---|---|---|---|
| `HA` | home (default) | yes, ≤10 kg | ≤40 kg |
| `RA` | pallet | no | ≤1000 kg |
| `PM` | post office / poste restante | yes | ≤30 kg |
| `PP` | PostaPont | no | ≤20 kg |
| `CS` | parcel terminal (locker) | no | ≤20 kg, one parcel only |

- **COD:** add `K_UVT` to `extra` and set `services.cod` (integer HUF; ≤2,000,000, or ≤500,000 for CS).
  - `paymentMode` `UV_AT` = COD paid out by bank transfer (needs `sender.accountNo`). `UV_KP` = paid in cash.
  - Samples say to always send `accountNo`.
  - For foreign COD, set `codCurrency`.
- Insurance: `K_ENY` + `services.value`. It is mandatory above 40 kg or with `K_TOR` (fragile).
- Retention: `K_ORZ` + `packageRetention`.
  - Business Parcel: HA 0/5/10, PM/PP/CS 5/10.
  - Postal Parcel: 5.
  - Default is 5.
  - The API auto-adds K_ORZ/K_UVT/K_ENY with WARNING codes 5/6/7 if omitted.
- Pickup point: `recipient.address.parcelPickupSite` = the delivery-place **name or numeric id** (samples: `"345 sz. automata - Szolnok Auchan"` ≡ `"107206"`).
- `recipient.disabled:true` (easy-access low compartment) is allowed for CS only.
- `size` `S`/`M`/`L` applies to lockers: S 31×25×7, M 50×31×16, L 50×31×35 cm.
- `sender.parcelTerminal:"true"` = the sender drops off at a locker (<20 kg).
- Multi-parcel: several `item[]` entries, max 16 (Business Parcel). Postal Parcel allows 1. `groupTogether:true` = deliver together.
  - With `groupTogether:false` you can add pieces later via `POST /shipments/{trackingNumber}/item` (before close).
- Weight units seen: `"g"`/`"G"`. Value type is string or int.

Response (array, aligned to the request):

```json
[{"webshopId":"1","trackingNumber":"PKAAA50058206",
  "packageTrackingNumbers":["PKAAA500582060011138001500"],
  "label":"JVBERi0x…","errors":[{"code":"33","parameter":"recipient.address","text":"…","text_eng":"…"}]}]
```

- `trackingNumber` = shipment id (13 chars domestic, e.g. `PN…`/`PK…`/`PB…`; S10 or UPU for international).
- `packageTrackingNumbers[]` = per-piece barcodes. Use `trackingNumber` for tracking.
- `label` (base64) is present when `labelType` was sent.
- Exchange parcels (`K_CSE`) also return `replacementTrackingNumber` and `replacementLabels[]`.

**Mandatory close [P].** `POST /shipments/close` builds the posting list (manifest). **Parcels are not dispatched without it.**

Body forms:

- `{"checkList":true,"checkListWithPrice":true}` (closes all pending)
- `{"trackingNumbers":[…],"checkList":true}`
- `{"tag":"…"}`
- `{"fromDate":"2024-01-02","toDate":"2024-01-03"}`
- `{"requestId":"<earlier X-Request-ID>"}` to replay a timed-out close

`singleFile` is also used [S].

Response: `[{"manifest":"<base64 PDF>","trackingNrPrices":[{"trackingNumber":"…","price":1250}],"errors":[…]}]`.

- Close **once per day per dispatch site**, not per parcel; offer it as a button. Inverse parcels have no manifest.
- **After close, the parcels vanish from MPL API** (no GET/label/DELETE). Only the tracking APIs work from then on.
- Close does not order a pickup truck.

### 3.3 Labels — `GET /shipments/label?trackingNumbers=…&labelType=A6&labelFormat=PDF&singleFile=true` [P for endpoint, S for params]

- Params are taken from the Sylius client. `trackingNumbers` is repeated or array-encoded; check against the Swagger (UNVERIFIED encoding).
- Response: `[{"trackingNumber":"…","label":"<base64>","errors":[…]}]`.

`labelType` [P]:

| value | description |
|---|---|
| `A5` | top of an A4 sheet |
| `A5inA4` | 2 per A4 |
| `A5E` | 154×105 |
| `A5E_EXTRA` | with tabs |
| `A5E_STAND` | 110×150 portrait |
| `A6` | 148.5×105 |
| `A6inA4` | 4 per A4 |
| `A4` | **only** for Call-out (`K_LEH`) and Inverse (`K_INV`) parcels |

- `labelFormat` is `PDF` or `ZPL` [P error 132 / S]. **ZPL is only allowed with A6, A5E, A5E_EXTRA** (error 132).
- ZPL printer objects (logos/fonts) ship as a zip; see the sources list above.
- Some types are refused for "integrator clients" (error 231).

### 3.4 Cancel — `DELETE /shipments/{trackingNumber}` [P]

- Only works before close. Error 201 = already cancelled or closed; 202 = not found.
- To grow a `groupTogether:true` shipment you must delete it and resubmit.

### 3.5 Tracking [P]

1. **Single, sync:** `POST https://core.api.posta.hu/v2/nyomkovetes/registered` (or `/guest`; sandbox `https://sandbox.api.posta.hu/v2/nyomkovetes/…`).
   - Same headers as above. Body `{"language":"en","ids":"PB2SW00021917","state":"last"|"all"}`. **One id per call.**
   - Recommended poll interval 4 h, at most hourly. Stop polling delivered items.
   - Response: `{"trackAndTrace":[{c0..c63}]}`; empty array = unknown id.

| key | meaning |
|---|---|
| `c1` | id |
| `c2` | base service |
| `c4` | delivery mode |
| `c6` | COD |
| `c8` | retention |
| `c9` | event text |
| `c10` | event category text |
| `c11` | date `YYYYMMDD` |
| `c12` | time `HH:MM:SS` |
| `c13` | posting office |
| `c43` | **event category code** |
| `c55` | failed-delivery reason |
| `c56` | receipt ground |
| `c61` | max category reached (0–5) |

2. **Bulk, async (≤500):**
   - `POST https://core.api.posta.hu/v2/mplapi-tracking/tracking` with `{"trackingNumbers":[…],"language":"EN"}` → `{"trackingGUID":"…"}`.
   - Then `GET …/tracking/{GUID}` at most once per minute. `status` goes `NEW` → `INPROGRESS` → `READY`, which can take 10–15 min.
   - `report` holds `item_identifier`, `last_event_type`, `last_post_name`, `last_event_timestamp`. **Trap:** in the doc, `report` is a JSON-ish **string** (and not valid JSON). Parse defensively.
3. **Push:**
   - `POST …/mplapi-tracking/tracking/subscribe` with `[{"sender":"API","dmlFlag":true,"language":"EN",…}]` → `[{"identifier":"<token GUID>","errors":[…]}]`.
   - Then hold a chunked HTTPS stream to `https://net.posta.hu/tracking/public/tracking/TrackingData/{token}` (`?lastRead=<InsertedAt>` to resume).
   - Records: `TrackingRecordId, PackageId, PackageStatus, PackageStatusText, EventDateTime, EventPlace, InsertedAt`.
   - The connection is capped at 1 h; the token lives 24 h. Not practical for PHP-FPM; use it only from a long-running worker.
4. **Reports:** `POST /reports` `{"dateFrom","dateTo","type":"FUGGO"|"VEGALLAPOT"|"UTALASI","format":"JSON"}`. Gives pending, final-state, and COD-settled reports (from previous-day data).

Mapping (category code `c43` / `PackageStatus` [P]; text-level overrides are ours):

| c43 | category | normalized | text overrides |
|---|---|---|---|
| 1 | Accepting | pre_transit | "Notification received…" = data only. "Acceptance completed" → in_transit |
| 2 | Processing | in_transit | |
| 3 | Transportation | in_transit | "Cannot be delivered (…)" + "being returned" → exception, then returned. "directed to post office for storage" → ready_for_pickup |
| 4 | Delivery | out_for_delivery | "Item handed over to delivery man". "Mail item available for collection at post office", "Ready for collection in parcel terminal", "Available for collection at PostaPont", "Ready for collection in PostPoint after 14:00" → ready_for_pickup. "Unsuccessful delivery" → exception |
| 5 | Delivered | delivered | "Returned to sender" → returned. "Cash on delivery paid to sender" → keep delivered and fire a COD-settled event |

### 3.6 Pickup points [P/S]

- **API:** `POST /deliveryplace` (the samples spell it `/deliveryPlace`).
  - Body `{"deliveryplacesQuery":{"city":"Szolnok"},"servicePointType":["CS"]}`. `city:""` returns all. `postCode` is also accepted [S].
  - `servicePointType` takes `PM`/`PP`/`CS`.
  - Response [S]: `[{"deliveryplacesQueryResult":{"id":"43078","deliveryplace":"MOL Töltőállomás","postCode":"1138","city":"Budapest","address":"Váci út 1","geocodeLat":47.5,"geocodeLong":19.0,"postCodeOnLabel":"…"},"servicePointType":["PP"]}]`.
  - The geocode/opening-hours fields beyond this are UNVERIFIED; check the Swagger.
- Normalize: id = `id`, name = `deliveryplace`, type from `servicePointType`: CS → locker, PP → partner point (MOL/Coop), PM → post_office.
- **Alternative feed [P]:** PostInfo2 XML from https://posta.hu/partnerextra. Use the "All parcel collection points (1+2+3)" file; `postInfo->post->name` is the value for `parcelPickupSite`.
- Address helpers: `POST /addresses/cityToZipCode {"city":…}` and `POST /addresses/zipCodeToCity`. The samples use `zipToCityCode` with a `city` key holding the zip; treat this as a doc inconsistency.

### 3.7 Rates

- No quote endpoint.
- `close` with `checkListWithPrice:true` returns **indicative** per-parcel prices (`trackingNrPrices[].price`, HUF) after the fact.
- For checkout rates, use the contract price table + the limits table in the integration recommendations.

### 3.8 Errors and traps

Error shape: per-item `errors:[{"code":"…","parameter":"…","text":"<HU>","text_eng":"<EN>"}]` inside a 200 response.

- Each code is typed **ERROR or WARNING**. Warnings: 5, 6, 7, 11–14, 33, 44–48, 106–111, 122, 220, 221. A WARNING still returns a tracking number.
- Error 33 = "address cannot be identified". The data is accepted anyway; MPL recommends deleting and resubmitting it.
- HTTP 401 = token expired.

Notable error codes:

| code | meaning |
|---|---|
| 20 | bad postcode |
| 34 | weight over the delivery-mode limit |
| 38 | bad delivery location |
| 57 | PP/CS/RA not allowed for Postal Parcel |
| 60 | pickup site's mode ≠ `deliveryMode` |
| 64 | insurance mandatory >40 kg |
| 66 | CS COD ≤500k |
| 100 | one parcel per terminal |
| 101–105 | mandatory, type, length, format, forbidden-char field errors (`{0}` = field) |
| 132 | ZPL size |
| 135 | too many ids |
| 201 / 202 | cancel: already closed / not found |
| 206 | no close yesterday/today |
| 208 | mixed delivery modes in one consignment |
| 601 / 602 | barcode or critical failure (retry) |

Traps:

- The create body **and** the response are arrays.
- MPL ignores unknown JSON tags (silent typos!).
- `X-Request-ID` must be a fresh GUID on each call. Reuse it deliberately only to replay a close.
- The sandbox is a mock, so its validation is weaker than production.
- Additional-service compatibility is a big matrix (Kizaro PDF). Validate on your side: e.g. no `K_TER` (bulky) to PP/CS, `K_UVT` + `K_INV` are exclusive, CS max 20 kg.
- Phone format is auto-corrected (warnings 108/109).

---

## 4. ELTA Courier (Greece) — SOAP web services

ELTA Courier's operations have been folded into ELTA (Hellenic Post), per [naftemporiki](https://naftemporiki.gr/english/1534821/elta-the-reorganization-of-the-network-has-been-completed). Whether a new API replaces the SOAP services is UNVERIFIED; every integration found still uses the SOAP endpoint below.

**There is no public primary documentation.** Credentials and the WSDL/PDF bundle come on contract: ask a local branch, call +30 210 607 3000, or e-mail info@elta-courier.gr.

Sources:

- [S] `chinchillabrains/eltaws`, https://github.com/chinchillabrains/eltaws (MIT PHP client; `src/Client.php` + `src/ws/PELTT01.wsdl`)
- [S] WooCommerce "Elta Courier Voucher": docs at https://woocommerce.com/document/elta-courier-voucher/
- [S] CodeCanyon "WooCommerce ELTA Courier Voucher & Label": https://codecanyon.net/item/woocommerce-elta-courier-voucher/26182266
- [S] PrestaShop module: https://01generator.com/en/courier-integration/19-my-elta-courier-web-service-prestashop.html

### 4.1 Transport and auth [S]

- SOAP 1.1, document/literal. The WSDLs are Micro Focus NetExpress-generated, **one WSDL per operation**, and each exposes a single operation **`READ`**.
- Endpoint: **`http://212.205.47.226:9003`**.
  - Plain HTTP, raw IP, port 9003. Hosts must allow outbound TCP 9003.
  - Live-probed 2026-10-03: the server answers, but `?wsdl` is **not served** (`CASKC0023E`). **Bundle the WSDL files locally**, as eltaws does, or hand-roll the SOAP envelopes.
  - Target namespace is `/<SERVICE>`, e.g. `/PELTT01`.
- No token. Credentials go in every request body. Field names vary per service:
  - CREATEAWB: `pel_user_code`, `pel_user_pass`, `pel_apost_code` (customer/sender code), `pel_apost_sub_code`
  - Others: `wpel_code`, `wpel_user`, `wpel_pass`
- Test credentials per eltaws: `9999999` / `9999999` / customer `999999999`. COD (`pel_ant_poso`) is "not available in testing mode".
- Since `?wsdl` is not served, hand-build the envelope (namespace `/CREATEAWB`), or ship the WSDLs inside the package.

### 4.2 Create — service `CREATEAWB`, op `READ` [S request; response UNVERIFIED]

Request fields (Greek transliterations):

| field | meaning |
|---|---|
| `pel_user_code`, `pel_user_pass`, `pel_apost_code`, `pel_apost_sub_code`, `pel_user_lang` | credentials / language |
| `pel_paral_name`, `pel_paral_address`, `pel_paral_area` (city), `pel_paral_tk` (postcode), `pel_paral_thl_1`, `pel_paral_thl_2` (phones), `pel_paral_sxolia` (comments) | **recipient** (paraliptis). The sender is your account |
| `pel_service` | e.g. `"1"` (standard); other codes from the contract |
| `pel_temaxia` | pieces, zero-padded 3 chars, `"001"` |
| `pel_baros` | weight in kg |
| `pel_ant_poso` | **COD amount** (antikatavoli, EUR) |
| `pel_ant_poso1..4`, `pel_ant_date1..4` | post-dated cheque instalments |
| `pel_sur_1..3` | extra-service flags (e.g. Saturday delivery) |
| `pel_asf_poso` | insured value |
| `pel_ref_no` | your order ref |

Response (**UNVERIFIED**, inferred from the field-naming convention of the published PELTT01 WSDL and the label call's input): `st_flag` (int, 0 = OK) + `st_title` (message), with the voucher number in `vg_code`. Multi-piece child vouchers likely come back in a repeated element. Confirm against the contract WSDL.

### 4.3 Labels — service `PELB64VG`, op `READ` [S]

- Request: `pel_code`, `wpel_user`, `wpel_pass`, `wpel_vg` (voucher number), `paper_size` (`0` = A4, `1` = A6, the default).
- Response: base64 PDF plus `st_flag`/`st_title`. The PDF element name is UNVERIFIED (expected `b64_string`).
- The PDF is server-generated. The A4 layout does not fit perforated label sheets [S CodeCanyon].
- **PDF only.** No ZPL.

### 4.4 Cancel — **not available via web services** [S WooCommerce doc]

"Elta Courier Web Services does not offer cancellation service." You must inform the local branch. Model it as `supportsCancel=false`: mark the voucher void locally and alert the merchant.

### 4.5 Tracking — service `PELTT01`, op `READ` [S, WSDL in eltaws]

- Request: `wpel_code`, `wpel_user` (**xs:integer**), `wpel_pass`, `wpel_vg` (voucher), `wpel_ref` (your ref), `wpel_flag` (`1` = search by voucher, `2` = by reference).
- Response:

| element | notes |
|---|---|
| `st_flag` | int |
| `st_title` | string |
| `pod_date`, `pod_time`, `pod_name` | **proof of delivery**. A non-empty `pod_date` means delivered |
| `web_status[0..100]` | each with `web_date`, `web_time`, `web_station`, `web_status_title` (Greek text) |
| `web_status_counter` | int |

- **No status codes, only Greek titles.** Map by keyword (UNVERIFIED wording; the WooCommerce plugins only distinguish "Delivery Completed" and "Delivery Denied"):

| Greek keyword | normalized |
|---|---|
| "ΠΑΡΑΛΑΒΗ" (pickup) | in_transit |
| "ΑΠΟΣΤΟΛΗ", "ΔΙΑΚΙΝΗΣΗ", "ΑΦΙΞΗ" | in_transit |
| "ΣΕ ΔΙΑΝΟΜΗ" / "ΠΡΟΣ ΔΙΑΝΟΜΗ" | out_for_delivery |
| "ΠΑΡΑΔΟΘΗΚΕ" / POD present | delivered |
| "ΠΡΟΣ ΠΑΡΑΛΑΒΗ ΑΠΟ ΚΑΤΑΣΤΗΜΑ" / "ΘΥΡΙΔΑ" | ready_for_pickup |
| "ΑΡΝΗΣΗ", "ΑΠΩΝ", "ΛΑΘΟΣ ΔΙΕΥΘΥΝΣΗ" | exception |
| "ΕΠΙΣΤΡΟΦΗ" | returned |

  Log unmapped titles so the table can grow.
- Unofficial public alternative: a POST to `https://www.elta-courier.gr/track.php`, used by the WP plugin `elta-order-tracking`. It is scraping; do not rely on it.

### 4.6 Pickup points [S]

- Service `PELSTATION`, op `READ`. Request `{web_tk: "<postcode>"}` → the ELTA store serving that ZIP. Response fields are UNVERIFIED.
- There is **no locker/PUDO list endpoint**. ELTA's parcel lockers and "pickup from store" are not exposed in any public integration found (UNVERIFIED).

### 4.7 Rates

None.

### 4.8 Errors and traps

- **Errors:** check `st_flag` ≠ 0 and show `st_title` (Greek). SOAP faults surface as `SoapFault`; network blocks surface as connect timeouts on port 9003. Detect and explain that case.
- `wpel_user` is typed as an integer in the WSDL; send digits only.
- Plain HTTP means credentials travel unencrypted. Document this to merchants, and never log the request bodies.
- Encoding: send UTF-8 Greek. Recipient fields probably have short max lengths; truncate them (UNVERIFIED limits).

---

## Cross-driver design notes

- **Capability flags per driver:**

| capability | HP | PS | MPL | ELTA |
|---|---|---|---|---|
| `createsTrackingNumber` | yes | no (barcode allocator) | yes | yes |
| `labelsFromApi` | yes | no | yes | yes |
| `zpl` | no | no | yes | no |
| `cancel` | yes | no | yes (pre-close) | no |
| `requiresClose` | no | — | yes | no |
| `pickupPointsApi` | yes | no | yes | no |
| `rates` | no | no | no | no |
| `batchTracking` | yes | yes (10) | async (500) | no |

- **COD:** EUR in HR/SI/GR, HUF integers in HU. MPL needs `sender.accountNo` for bank payout.
- **Ports and egress:** HP uses 9000/9020; ELTA uses 9003 on plain HTTP. Add a connectivity self-test per driver (`client_auth` / `easistecho` / a `PELTT01` call with a dummy voucher).
- **GET with body** (HP) needs a raw curl path in the HTTP abstraction.
- **Timezones:** all four return local naive timestamps (HR/SI CET/CEST, HU CET/CEST, GR EET/EEST). Convert with each carrier's zone.
