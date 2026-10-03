# US LTL freight carrier APIs: implementer's reference

Researched 2026-10-03. Scope: TForce Freight, Old Dominion (ODFL), Estes, XPO, R+L, SAIA. Target: plain-HTTP PHP connectors (no SDKs) in a Craft plugin.

**How to read this:** facts are taken from the carrier's own published manuals, OpenAPI/Swagger specs or developer-portal metadata, and each section names its source. **UNVERIFIED** marks anything inferred, taken from a secondary source, or contradicted inside the carrier's own docs. Field paths use dot notation; `[]` means array.

---

## 0. Cross-carrier summary

| | TForce | ODFL | Estes | XPO | R+L | SAIA |
|---|---|---|---|---|---|---|
| Transport | REST/JSON | REST/JSON | REST/JSON | REST/JSON (XML also accepted) | REST/JSON (XML also accepted) | REST/JSON; legacy SOAP still live |
| Auth | OAuth2 client_credentials (Azure AD) → Bearer | GET token with Basic(ODFL.com user:pass) → Bearer | `apikey` header + Basic(MyEstes user:pass) → Bearer; send both | Basic(base64 consumerKey:secret) + `grant_type=password` (XPO web user) → Bearer | static `apiKey` header | per-product subscription key header; Saia Secure user/pass in **body** (rating) or Basic (tracking) |
| Sandbox | same host, `?api-version=cie-v1` | `apiq.odfl.com` | `uat-cloudapi.estes-express.com` | prod host; `testMode=Y` on BOL/pickup only | none documented | `saiapilotapi.azure-api.net` (separate keys) |
| Quote no. | `summary.quoteNumber` (request `quoteNumber:true`) | `rateEstimateNumber` (only if a single service+rateType and `referenceNumberRequested:true`; otherwise use `/reservation`) | `data[].quoteId` | `data.rateQuote.confirmationNbr` | `RateQuote.ServiceLevels[].QuoteNumber` | UNVERIFIED (legacy SOAP: `QuoteNumber`) |
| Total | `detail[].shipmentCharges.total.value` (string) | `requestedRates[].totalCharge` (number) | `data[].quoteRate.totalCharges` (string) | `data.rateQuote.totCharge[0].amt` (**array**) | `RateQuote.ServiceLevels[].NetCharge` (string) | UNVERIFIED (legacy: `TotalInvoice`) |
| Transit days | `detail[].timeInTransit.value` | `serviceDays[].days` | `data[].transitDetails.transitDays` | `data.transitTime.transitDays` | `ServiceLevels[].ServiceDays` | UNVERIFIED (legacy: `StandardServiceDays`) |
| eBOL returns PRO | yes `detail.pro` | yes `referenceNumbers.pro` | yes `referenceNumbers.pro` | **no**, returns `bolInstId` (PRO only if you supplied one) | yes `ProNumber` | yes `referenceNumbers.pro` (+`checkDigit`) |
| BOL PDF in response | base64 `detail.documents.image[].data` | base64 `images.bol` | base64 `images.bol` | separate GET `/pdf` | separate GET `PrintBOL` → base64 `BolDocument` | base64 `images.bol` |
| Labels | yes (PDF/ZPL 4x6) | yes base64 `images.shippingLabels` | yes base64 `images.shippingLabels` | yes, separate POST endpoints | yes, `PrintShippingLabels` base64 | yes base64 `images.shippingLabels` |
| Pickup API | yes `/pickup/request`, `/pickup/bol` | yes `/pickup/v3.0/create` | yes `/v1/pickup-requests` | yes `/cust-pickup-requests` | yes `/PickupRequest`, `/PickupRequest/FromBOL` | no public REST pickup found (UNVERIFIED) |

### The big shortcut: the NMFTA Digital LTL Council eBOL standard

ODFL (v2.1.0), Estes (v2.0.1), SAIA (v2.0.1) and R+L (`POST /BillOfLading/DigitalCouncilBOL`, version not checked) all accept the **NMFTA Digital LTL Council eBOL** JSON shape: `bol{function,requestedPickupDate,isTest,requestorRole}`, `commodities.handlingUnits[].lineItems[]`, `origin`, `destination`, `billTo`, `payment.terms`, `accessorials.codes[]`, `images{includeBol,includeShippingLabels,shippingLabels{format,quantity,position}}`. Each returns `referenceNumbers.pro`, `images.bol` and `images.shippingLabels` (base64 PDF), plus `messageStatus{status: PASS|FAIL|WARNING, code, message, resolution, information[]}`.
**Build one eBOL serializer and add a small per-carrier delta.** The NMFTA accessorial codes are shared:

| Concept | NMFTA eBOL code |
|---|---|
| Liftgate delivery / pickup | `LFTD` / `LFTP` |
| Residential delivery / pickup | `RES` / `REP` |
| Inside delivery / pickup | `IDL` / `IPU` |
| Limited access delivery / pickup | `LTDAD` / `LTDAP` |
| Appointment delivery / pickup | `APTD` / `APTP` (Estes and SAIA do **not** accept APTP) |
| Notify consignee | `MNC` (ODFL lists it; Estes and SAIA lists do not, UNVERIFIED for them) |
| Hazmat | `HAZ` |
| Sort/segregate, marking, over-dimension, full-value coverage | `SRT`, `MARK`, `OVR`, `FVC` |

**Rating does not share codes.** Every carrier uses its own accessorial vocabulary for rate quotes (section 7), and ODFL even uses different codes for rating (`HYD`) and eBOL (`LFTD`).

### Normalized tracking statuses

`pre_transit | in_transit | out_for_delivery | delivered | exception`. Per-carrier maps are in each section. Rule of thumb: map explicit "delivered" codes first, then "out for delivery", then exception flags or keywords (refused, damage, undeliverable, short, delay, attempted, "will reattempt"), then pickup-pending → `pre_transit`, and default everything else to `in_transit`.

---

## 1. TForce Freight

Sources: developer.tforcefreight.com integration guides; PDF manuals at `https://www.tforcefreight.com/downloads/{Rating,Shipping,Pickup,Tracking,documents}-API-User-Manual-V1.pdf`. The Rating manual is dated July 06, 2026.

### Auth
- Microsoft identity platform (Azure AD B2B). Token URL: `POST https://login.microsoftonline.com/ca4f5969-c10f-40d4-8127-e74b691f95de/oauth2/v2.0/token`, `application/x-www-form-urlencoded`.
- **Direct (end-user) integration:** `grant_type=client_credentials`, `client_id`, `client_secret`, `scope=https://tffproduction.onmicrosoft.com/f06cb173-a8e6-44ad-89a1-06c1070a1f62/.default`.
- **Third-party (multi-tenant) integration:** customers onboard through TForce's CIAM consent flow. Your webhook receives a `UserOnboardedEvent` JWT, which you exchange with `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`, `assertion=<jwt>`, `requested_token_use=on_behalf_of`, `scope=.../Developer.All offline_access`. Refresh with `grant_type=refresh_token`. A Craft plugin used by the shipper itself should use client_credentials.
- Call APIs with `Authorization: Bearer <token>` and `Content-Type: application/json`.
- Get credentials at developer.tforcefreight.com, under Profile → Configure My Client (Client ID; the client secret is shown only once). Rating requires an "API End User" enrollment, and negotiated rates also need an approved TForce account number. The ToS forbid brokers and resellers from using Rating without an agreement.

### Base URLs and environments
- Prod: `https://api.tforcefreight.com/{rating|shipping|pickup|track|documents}/...?api-version=v1`
- Sandbox (Customer Integration Environment, CIE): **same host**, `?api-version=cie-v1` (or `cie-vNext`). CIE negotiated rates are not your real contract rates.
- URLs are **case-sensitive**. Every API exposes `GET /openapi` for OpenAPI metadata.

### Rate quote: `POST https://api.tforcefreight.com/rating/getRate?api-version=v1`
Volume quotes use `POST .../rating/volumeRating`, which takes `commodity` (an object) with `linearfeet` 8–28 and only allows service code 308.
```json
{
  "requestOptions": {"serviceCode":"308","pickupDate":"2026-10-05","type":"L",
                     "densityEligible":false,"timeInTransit":true,"quoteNumber":true,"customerContext":"order-123"},
  "shipFrom": {"address":{"city":"Richmond","stateProvinceCode":"VA","postalCode":"23224","country":"US"},"isResidential":false},
  "shipTo":   {"address":{"city":"Easley","stateProvinceCode":"SC","postalCode":"29640","country":"US"},"isResidential":true},
  "payment":  {"payer":{"address":{"city":"Richmond","stateProvinceCode":"VA","postalCode":"23224","country":"US"}},"billingCode":"10"},
  "serviceOptions": {"pickup":["LIFO"],"delivery":["LIFD","INDE","NTFN"]},
  "commodities": [{"class":"77.5","pieces":2,"weight":{"weight":800,"weightUnit":"LBS"},"packagingType":"PLT",
                   "dangerousGoods":false,"dimensions":{"length":48,"width":40,"height":48,"unit":"IN"}}],
  "handlingUnits": [{"pieces":2,"weight":{"weight":800,"weightUnit":"LBS"},"packagingType":"PLT",
                     "dimensions":{"length":48,"width":40,"height":48,"unit":"IN"}}]
}
```
- Required fields: `requestOptions.serviceCode`, `pickupDate` (YYYY-MM-DD), `type` (`L`); `shipFrom`/`shipTo`/`payment.payer` addresses with `postalCode` and `country`; `payment.billingCode`; `commodities[]` (1–100 items), each with `pieces`, `weight{weight,weightUnit:"LBS"}` and `packagingType`. `class` is optional (a string). `nmfc{prime:6 digits, sub:2 digits}` is optional.
- Limits: length ≤324 in, width ≤96 in, height ≤102 in. Maximum LTL weight is 19,999 lb.
- Residential delivery and pickup are set with the `shipTo.isResidential` and `shipFrom.isResidential` booleans, **not** with codes in the request. The response itemizes them as `RESD` and `RESP`.
- Service codes: `308` TForce Freight LTL, `309` LTL Guaranteed, `311` Accelerated Guaranteed, `349` Standard LTL US/MX.
- billingCode values: `10` Prepaid, `30` Third Party, `40` Collect.
- Packaging types: PLT, SKD, BOX, CTN, CRT, DRM, BAG, LOO, OTH and others (full list in the manual appendix).
- Density-based rating: set `requestOptions.densityEligible:true` and send `handlingUnits[]` with dimensions.

Response fields:
- Status: `summary.responseStatus.code` is `OK`, `PRT` (partial) or `NFO` (no rate found).
- Quote number: `summary.quoteNumber`.
- Per-service results in `detail[]`:
  - `detail[].service.code`
  - **Total:** `detail[].shipmentCharges.total.value` (a **string**, e.g. `"1551.53"`) with `.currency`.
  - Line items: `detail[].rate[]` entries of `{code,description,value,unit}`. Codes include `FUEL_SUR`/`FUS_FEE`, `DSCNT`, `LND_GROSS`, `AFTR_DSCNT`, `LIFD` and others.
  - **Transit:** `detail[].timeInTransit.value` with `.unit:"DAY"`; excludes weekends and holidays.
  - Billable weight: `detail[].shipmentWeights.billable.value`.
  - Warnings: `detail[].alerts[]`, e.g. `{"code":"853","message":"FBR853: DESTINATION IS A RURAL POINT"}`.

### BOL: `POST https://api.tforcefreight.com/shipping/bol/create?api-version=v1`
- The body uses the same shape as the rate request, plus:
  - `requestOptions.previewRate` and `bolPrintFormat` (`TFF` or `VICS`)
  - name, email and phone on each party
  - `handlingUnitOne{quantity,typeCode}` and `handlingUnitTwo`
  - `references[]`, `instructions{pickup,handling,delivery}`
  - an optional embedded `pickupRequest{pickup{date,time,openTime,closeTime},requester{...}}`
  - `documents.image[]` entries of `{type:"20"` (BOL) or `"30"` (label), `format:"01"` (PDF) or `"02"` (ZPL), `label{type,startPosition,numberOfStickers}}`
- Label type `07` is a 4x6 thermal label and is the only type that allows ZPL. Type `01` is Avery address labels with PROs.
- Hazmat: set `commodities[].dangerousGoods:true` and provide `serviceOptions.shipment.dangerousGoods{name,phone{number,extension}}`.
- Response:
  - `detail.bolId` (number)
  - **`detail.pro`** (a 9-digit string)
  - `detail.rateDetail[]`
  - `detail.pickup.transactionReference.confirmationNumber` (e.g. `WBU5353360`)
  - **`detail.documents.image[].data`** (base64 PDF) with `.type` and `.format`

### Pickup: base `https://api.tforcefreight.com/pickup`
- `POST /request` creates a standalone pickup: `pickup{date,time:"1000",openTime:"0800",closeTime:"1600"}`, `requester{...,thirdParty}`, `origin{...address{address1..3,city,stateProvinceCode,postalCode,country}}`, `services:["INPU","LIFO",...]` and `lineItems[]` of `{description,weight,weightUnit,pieces,packagingType,hazardous}`.
- `POST /bol` creates a pickup for an existing BOL: `pickup.existingShipment{bolId,pro}`.
- `DELETE /request/{confirmationNumber}` cancels.
- Response: HTTP 201 with `transactionReference.confirmationNumber`, plus `originIsRural` and `destinationIsRural`.

### Tracking: `GET https://api.tforcefreight.com/track/pro/{proNumber}?api-version=v1`
Other endpoints: `POST /pro/multiple` (body is an array of PROs) and `POST /pro/search` (by reference: `code:"BL"`, a number, optional dates and location). A PRO is 9 digits.

Response:
- `detail[].currentStatus{code,description,details}` holds the status.
- `details` is a numeric milestone: `004` voided, `005` in transit, `006` out for delivery, `011` delivered, `013` exception. `code` is a short code such as `DL`.
- `detail[].delivery.estimated.date`, `.actual.date` and `.signedBy`.
- `detail[].events[]` entries of `{date (ISO8601 with offset), description, serviceCenter, trailer{number,scac}}`. Events have no machine-readable code in the sample, only text.

| Normalized | TForce |
|---|---|
| pre_transit | no `005` events yet; `004` voided (treat as cancelled) |
| in_transit | `details=005` |
| out_for_delivery | `006` |
| delivered | `011` |
| exception | `013` |

### Documents: `POST https://api.tforcefreight.com/documents/pro`
Body: `{pro, documentCategories[]}`. Returns `imagesData[].data` as base64. `POST /documents/id` takes `docId` and `lastUpdatedDate` (both returned by `/pro`).

### Errors and limits
- 400, 401, 403 (quota, resets after 300 s, response carries `Retry-After`), 404, 429 (rate limit, resets after 60 s, `Retry-After`). CIE and production throttle counters are separate.
- The body echoes `summary.responseStatus{code,message}`. The exact error body shape is **UNVERIFIED** (not shown in the manuals).
- Unknown or misspelled properties cause the **whole request to be rejected**: "Elements that are not defined ... will result in request being rejected." Never send nulls or extra keys.

### TForce traps
- **The manuals swap two delivery codes.** The Delivery Codes table lists `LADL` as Liftgate Delivery and `LIFD` as Limited Access Delivery. The Rate Codes table (`LIFD` = `LIFT_GATE_DL`), the examples (`['INDE','LIFD']`) and the parallel pickup code `LIFO` = Liftgate Pickup all show the opposite. Use **`LIFD` = liftgate delivery and `LADL` = limited access delivery**, and verify in CIE (UNVERIFIED).
- `class` is a string (`"77.5"`). Weights are numbers in LBS.
- Time formats differ between endpoints: pickup `/request` uses `"1000"` (HHMM), while the embedded `pickupRequest` in BOL create uses `"10:00:00"`.
- The BOL create response sample has `timeInTransit.timeInTransit` where the rating response has `timeInTransit.value`. Read both.
- Charge values are strings. Parse with bcmath/decimal, not floats.
- Fuel surcharge is reported both as `FUEL_SUR` (sample) and `FUS_FEE` (table). Match either.

---

## 2. Old Dominion (ODFL)

Sources: ODFL development guides, PDFs at `https://www.odfl.com/content/dam/odfl/us/en/documents/web-services/{Rate Estimate|Bill of Lading|Pickup|Tracking|Document} API Development Guide.pdf`. The Rate guide is v1.0 updated 08/21/26. The eBOL guide is v3.1 dated 03/20/26 and is NMFTA v2.1.0 compliant. Also the SOAP sunset page `https://www.odfl.com/us/en/resources/shipping-api-integrations/soap-api-sunset-guide.html`.

**Status:** ODFL is retiring all SOAP endpoints, and the sunset page now says **March 1, 2027**. Earlier copies said December 31, 2025, with the SOAP Rate Estimate service following later in 2026. Only build against REST. Old REST/SOAP URLs such as `api.odfl.com/rates/ltl/v1.0/estimate.create` and `/wsRate_v6/RateService` are on the retire list.

### Auth
- `GET https://api.odfl.com/auth/v1.0/token` (QA: `https://apiq.odfl.com/auth/v1.0/token`) with **Basic auth using the ODFL.com username and password**. The response holds a session token that expires after **1 hour**. The exact JSON key name is not shown in the guide; expect something like `sessionToken` and parse defensively (UNVERIFIED).
- Call APIs with `Authorization: Bearer <token>`.
- Errors: 400 "Invalid Credentials" (also returned when the account is disabled), 503.
- QA access is set up by "Email an Expert" (API@odfl.com) on the Shipping API Integrations page.

### Rate quote: `POST https://api.odfl.com/rating-service/v1.0/rates` (QA `apiq.odfl.com`)
```json
{
  "requestedRates": [{"serviceType":"LTL","rateType":"Default"}],
  "referenceNumberRequested": true,
  "billToAccount": 123456789,
  "movementDirection": "Outbound",
  "freightTerms": "Prepaid",
  "pickupDate": "2026-10-05T00:00:00.000Z",
  "shipper":   {"zipCode":"27360","country":"USA"},
  "consignee": {"zipCode":"29640","country":"USA"},
  "totalWeight": 800, "weightUnitType": "LB",
  "accessorialCodes": ["HYD","RDC","ARN"],
  "shipmentItems": [{"freightClass":77.5,"handlingUnitCount":2,"handlingUnitType":"SKID","weight":800,
                     "length":48,"width":40,"height":48,"dimensionUnitType":"IN","hazardous":false,"stackable":false}]
}
```
- Required fields: `requestedRates[]{serviceType,rateType}`, `shipper.zipCode`/`country`, `consignee.zipCode`/`country`, `totalWeight` (an **integer**, max 50,000 LB) and `weightUnitType` (`LB` or `KG`).
- Country codes are **ISO3**: `USA`, `CAN`, `MEX`. ZIPs can be "12345", "12345-1234" or "A1A1A1".
- Service types: `LTL`, `Guaranteed by 5pm`, `Guaranteed by Noon`. Rate types: `Default`, `Security Divider`, `Volume`, `Pallet`. Several types are marked "Delivering 2027 – Phase 2".
- Account linkage: `billToAccount` (a long), or `shipper.account`/`consignee.account` depending on `movementDirection` (`Inbound`, `Outbound` or `Third Party`; the default is Third Party).
- Dimensions are integers, and setting any dimension requires `dimensionUnitType` (`IN` or `CM`). Setting `handlingUnitCount` requires `handlingUnitType` (SKID, BSKD, WSKD, BOX, CRT, CTN, DRUM, TOTE, …).
- `shipmentItems.hazardous` (bool) and `hazardousId` (UN/NA). `nmfc` and `nmfcSub` are integers.

Response:
- `success` (bool)
- `rateRequestId` (UUID), needed for `/reservation`
- **`rateEstimateNumber`**: the quote number. It is returned only for a single serviceType+rateType with `referenceNumberRequested:true`; the sample shows `0` otherwise.
- **`serviceDays[].days`** (transit days excluding the pickup day, weekends and holidays) and `serviceDays[].city`
- `directDelivery`
- `requestedRates[]`:
  - `serviceType`, `rateType` (returned **uppercased and without spaces**: `DEFAULT`, `SECURITYDIVIDER`), `detailId`
  - **`totalCharge`** (a number; equals `netFreightCharges` in the sample), `fuelSurcharge`, `discountAmount`, `discountPercentage`, `grossFreightCharges`
  - `accessorialCharges[]` entries of `{code,amount,description,variableCharge}`
  - `rateExpirationDate`, `errorMessages[]`, `informationMessages[]`
- `alternateRates[]` (same shape) may also appear.
- When one rate type cannot be priced, its entry has `totalCharge:null` and `errorMessages:["Please contact 699Quotes@ODFL.com ..."]`, while the request as a whole still succeeds.

Reserve: `POST https://api.odfl.com/rating-service/v1.0/reservation` with `{serviceType, rateType, rateRequestId}` returns `rateEstimateNumber`. Use it when you rated several types and need a quote number to reference on the BOL (as `referenceNumbers.quoteId`).

### BOL (NMFTA eBOL v2.1.0): `POST https://api.odfl.com/BOL/v3.1/eBOL/bol-external-per-standards`
- Modify: `POST .../modify-bol-external-per-standards`. Delete: `.../deleteBolPro?proNumber=` (no body).
- QA host: `apiq.odfl.com`. You can also send `bol.isTest:true`.
- Request (abridged from ODFL's sample): `version:"2.1.0"`, `bol{function:"Create",requestedPickupDate:"2025-08-27T10:30:00.000",isTest,requestorRole}`, `commodities{lineItemLayout:"nested",handlingUnits[{count,type:"SKD",weight,weightUnit:"Pounds",length,width,height,dimensionsUnit:"inches",stackable,lineItems[{weight,classification:"85",description,hazardous,pieces,packagingType,nmfc,nmfcSub,hazardousDetails{...}}]}]}`, `payment.terms`, `origin`, `destination`, `billTo` (`country:"USA"`), `images{includeBol,includeShippingLabels,shippingLabels{format:"Avery",quantity,position}}`, `referenceNumbers{pro?,quoteId,bol[],po[]}`, `accessorials{codes[],hazardousDetails{emergencyContact{name,phone}},appointmentDetails{...}}`.
- Omit `referenceNumbers.pro` to have ODFL assign one.
- Response:
  - **`referenceNumbers.pro`**: a string in the field table but a **number** (`77777777777`) in the sample, so cast to string.
  - **`images.bol`** and **`images.shippingLabels`** (base64 PDF; null unless requested)
  - `messageStatus{status:PASS|FAIL|WARNING,resolution,message,code,information[]}`
  - `resultStatusCodes`: 1200 BOL PDF not created, 1300 label not created, 1400 duplicate PRO, 1700 duplicate PO, and others
- eBOL accessorials use the NMFTA codes: LFTD/LFTP, RES/REP, IDL/IPU, LTDAD/LTDAP, APTD/APTP, MNC, HAZ, SRT, FVC, GTD_NOON/GTD_PM, plus a location-type list (`School-69`, `Secure-45` …). COD and GTD_AM are not supported.

### Pickup: `POST https://api.odfl.com/pickup/v3.0/{create|cancel|update|info}` (QA `apiq`)
- The guide lists `/update`; the sunset page says `/modify` (**UNVERIFIED** which is live).
- Create needs `pickupDate` (YYYY-MM-DD), `shipper{firstName≤15,telephoneNumber,streetAddressOne≤33,city≤24,state,zipCode,companyName≤33}`, `requester{...}` and `shipments[]`.
- The response has `response.pickupNumber` (a number). If you send several consignees you still get one pickup number.

### Tracking: `POST https://api.odfl.com/tracking/v2.0/shipment.track`
- Body: `{"referenceType":"PRO","referenceNumber":"07800000000"}`. Reference types: PRO, BOL, PO, LOAD, PKU, PPID. A reference number is 9–11 characters.
- Response: `ok`, `traceInfo[].trackTraceDetail[]` entries of `{status, desc, dateTime (ISO8601), city, state, zipCode}`, plus `standardEta`, `updatedEta`, `deliverySign`, `proNumber`, `pieces`, `weight`.
- Authorized fields (returned when the token's user can see the account): `appointmentDate`, `appointmentStatus`, `guaranteed`.
- Business errors can come back **with HTTP 200**: messages such as 404 "No results found…" and 400 "Invalid Reference Type…" appear in the body. Always check `ok`.

| Normalized | ODFL `status` |
|---|---|
| pre_transit | Pickup Requested, Pickup Rescheduled |
| in_transit | Pickup Completed, In Transit, Received At Dock, Agent Handoff, Interline Handoff, Appointment Set/Confirmed |
| out_for_delivery | Out for Delivery |
| delivered | Delivered, Delivery Confirmed |
| exception | Delayed, Appointment Attempted, Returned To Dock, Pickup Cancelled (treat as cancelled), Appointment Cancelled/Rescheduled (likely just in_transit; your call) |

### Document retrieval: `https://api.odfl.com/document-retrieval-api/v1.0/getDocument`
Request details are in the Document guide and were not extracted (UNVERIFIED).

### ODFL traps
- **`freightClass` is typed `integer` in the guide and the sample sends `77`**, yet the valid-values list includes `77.5` and `92.5`. Send a JSON number such as `77.5` and test in QA whether decimals are accepted. If they are not, this is a known ODFL quirk to raise with API@odfl.com (UNVERIFIED).
- In the eBOL, `classification` is a **string** (`"92.5"`). Rating and eBOL use different types and different accessorial vocabularies (`HYD` vs `LFTD`).
- The field table says `errorsMessages` but the samples say `errorMessages`. Read both.
- `rateType` is echoed back uppercased with spaces removed, so match case-insensitively with spaces stripped.
- All weights are integers. `declaredValue` and `cargoLiability` are whole dollars.
- Rate limits are not documented (UNVERIFIED).

---

## 3. Estes Express

Sources: `https://developer.estes-express.com/` (single-page docs) and the full OpenAPI 3.0.3 spec at **`https://cloudapi.estes-express.com/specs/swagger.yaml`** ("Estes Express API Suite" v1.26.37). This is the best-documented carrier; generate DTOs from that spec.

### Auth (three layers)
1. Email WebSupport@estes-express.com to get a client ID and secret, separate for test and production.
2. Create an API key once per environment: `POST https://cloudapi.estes-express.com/v1/api-key` with Basic `clientId:clientSecret`. The response is `data.apiKey`, `data.apiClientId` and `data.apiClientSecret`, and that **new secret replaces the emailed one**. Use `GET` to retrieve the key and `DELETE` to remove it. A 409 means a key already exists.
3. Request a bearer token: `POST https://cloudapi.estes-express.com/authenticate` with header `apikey: <key>` and **Basic MyEstes username:password**. The response is `{"access_token","token_type":"Bearer","expires_in":35999}`, roughly 10 hours. The schema in the spec says `token`, the documented sample says `access_token`, so read both.
4. Every call needs **both** `Authorization: Bearer <token>` **and** `apikey: <key>`.

A TMS stores each customer's MyEstes credentials, and a token must never be shared across customer credentials.

### Base URLs
- Prod: `https://cloudapi.estes-express.com`. Test: `https://uat-cloudapi.estes-express.com`.
- Legacy SOAP services (e.g. "Rate Quote Web Service v4.0") remain on estes-express.com and do not use API keys. Do not use them.

### Rate quote: `POST /v1/rate-quotes`
```json
{
  "quoteRequest": {"shipDate":"2026-10-05","shipTime":"16:00","serviceLevels":["LTL"]},
  "payment": {"account":"1234567","payor":"Shipper","terms":"Prepaid"},
  "requestor": {"name":"Jane","phone":"8045551234","email":"j@example.com"},
  "origin": {"address":{"city":"Richmond","stateProvince":"VA","postalCode":"23234","country":"US"}},
  "destination": {"address":{"city":"Easley","stateProvince":"SC","postalCode":"29640","country":"US"}},
  "commodity": {"handlingUnits":[{"count":2,"type":"PT","weight":800,"weightUnit":"Pounds",
      "length":48,"width":40,"height":48,"dimensionsUnit":"Inches","isStackable":false,"isTurnable":true,
      "lineItems":[{"description":"Widgets","weight":800,"pieces":2,"packagingType":"PT","classification":"77.5","isHazardous":false}]}]},
  "accessorials": {"codes":["LGATE","HD","APT"]}
}
```
- `serviceLevels`: `LTL`, `LTLTC` (time-critical guaranteed), `VTL` (volume LTL and truckload), `VTLTC`, `ERG` (Estes Retail Guarantee), `EU` (exclusive use), or `ALL`.
- `requestor`, handling-unit `count`, `tareWeight`, dimensions and the hazmat flag become **required** for LTLTC, VTL, VTLTC and EU.
- `classification` (a string) is required for LTL and LTLTC. `nmfc` and `nmfcSub` are strings.
- Weights are **integers** in pounds (`weightUnit:"Pounds"`). Countries are 2-letter `US`, `CA`, `MX`. Payor values are `Shipper`, `Consignee` or `Third Party`; terms are `Prepaid` or `Collect`.
- Handling-unit types (2 letters): **`PT` = PALLET, `PL` = PAIL**, SK skid, BX box, CR crate, CT carton, DR drum, TO tote, BD, BG, BK, BL, BR, CN, CS, CY, JC, KT, PC, PK, RE, RL, TL.

Response (`200`): `data[]`, one entry per service level.
- `serviceLevelId`, `serviceLevelText`
- **`quoteId`** (e.g. `"LFBX7KH"`), `rateFound` (bool)
- `dates.quoteExpiration`, `dates.transitDeliveryDate`
- **`transitDetails.transitDays`** (number), `transitDetails.laneType` (DIRECT or INDIRECT)
- **`quoteRate.totalCharges`** (a **string**), `quoteRate.ratedAccessorials[]` entries of `{code,description,charge}` (`charge` can be `"Included"`)
- `lineItemCharges[]`, `chargeItems[]`
- `alerts[]` and `reasons[]` entries of `{messageId,message}`. `reasons[]` explains why no rate was produced.

The envelope always also carries `error{code,message,details}` (code 0 on success).

### BOL (NMFTA eBOL v2.0.1): `POST /v1/bol`
- The body follows the NMFTA shape (section 0). Set `bol.isTest:true` in UAT or production to validate only. `referenceNumbers.pro` is optional; Estes assigns one if you omit it.
- Response 201:
  - `referenceNumbers.pro`, `referenceNumbers.shipmentConfirmationNumber`
  - **`images.bol`** and **`images.shippingLabels`** (base64 PDF). Label formats are given by `images.shippingLabels.format`, e.g. `"Avery"`.
  - `messageStatus{status,code,message,resolution,information[]{code,type:E|W,message}}`
- eBOL accessorials are the NMFTA codes plus Estes-specific ones: APTD (APTP **not** accepted), HAZ, IDL, IPU, LFTD, LFTP, LTDAD, LTDAP, MARK, HDSIG, CONST, EXHIB, FVC, GTD_*, MINIDL and others. Full list in swagger.yaml under `/v1/bol` → `accessorials.codes`.

### Pickup: `POST /v1/pickup-requests`
- Response 201 is `data.requestNumber`.
- `PUT /v1/pickup-requests` updates. `GET /v1/pickup-requests/{requestNumber}` returns status. `DELETE /v1/pickup-requests/{requestNumber}` cancels.
- Estes also offers PRO pre-allocation (Reserve PRO, through WebSupport), route guides (`POST /v1/route-guides` for transit times), images (`GET /v1/search/images/{document-type}`, base64) and webhooks (`/v1/events/{pickup-requests|shipments}/subscriptions`).

### Tracking: `GET /v1/shipments/history?pro=<PRO>`
- Other lookups: `po`, `bol`, `pur` (pickup request), `ldn` (load), `exl`, `interline-pro`, with `party-to=true` to filter.
- Response `data[]`:
  - `status{conciseStatus, expandedStatus, referenceDate, referenceTime, reasonCode, reason, isException}`
  - `deliveryDate`, `deliveryTime`, `receivedBy`
  - `estimatedDelivery{startDate,startTime,endDate,endTime}`
  - `transitDays`, `appointment{...}`, `driverInfo{geoCoordinates,stopsAway}`
  - `statusHistory[]` (same fields as `status`)

| Normalized | Estes |
|---|---|
| pre_transit | no `conciseStatus` yet / pickup request only |
| in_transit | `conciseStatus` = "Departed Pickup Location" or "In Transit" |
| out_for_delivery | "Out for Delivery" |
| delivered | "Delivered" |
| exception | `status.isException == true` (check this **before** conciseStatus); `reasonCode`/`reason` come from the Driver Delivery No Service and Dispatcher Bring Back tables |

### Errors and limits
- Every error uses the same envelope: `{"error":{"code":20200,"message":"Invalid or missing body parameter","details":"Input data failed validation"}}`.
- Known codes: 30020 failed authentication (401), 40010 not authorized (403), 20040 not acceptable (406), 90020 (415).
- Pickup errors also carry `error.validationFailures[]{field,description}`.
- 429 appears in the spec for the subscription endpoints; numeric limits are not published (UNVERIFIED).

### Estes traps
- `PL` means pail and `PT` means pallet. The NMFTA eBOL uses different handling-unit codes (`PLT`/`SKD`, per the NMFTA list).
- Rate-quote accessorial codes such as `LGATE` differ from eBOL codes such as `LFTD`. See section 7.
- `quoteRate.totalCharges` is a string, and `shipTime` is "HH:MM" military time.
- Alaska has its own accessorial codes (`AKLGAT`, `AKRES` …).

---

## 4. XPO (LTL)

Sources: XPO API Help Center `https://www.xpo.com/help-center/api/` (formerly ltl-solutions.xpo.com). Implementation guides are at `https://www.xpo.com/cdn/files/s1/XPO_API_{Rating,BOL,Pickup_Request,Shipment_Tracking,Imaging,LTL_Shipping_Tools}_Guide.pdf`. The Rating and Pickup guides are dated 2021-07-18. The BOL guide was updated 2026-08-18 with a new accessorial list and an NMFC sub field. The Tracking guide is from 2018.

### Auth
- Register a web user, then email LTLWebAPISupport@xpo.com to subscribe. You get a consumer key and secret; XPO calls base64(key:secret) the "access token".
- Token request: `POST https://api.ltl.xpo.com/token` with header `Authorization: Basic <base64(consumerKey:consumerSecret)>` and form body `grant_type=password&username=<XPO LTL web user>&password=<pw>`.
- Response: `{"access_token","refresh_token","scope":"default","token_type":"Bearer","expires_in":43200}`. The access token lasts **12 h**, the refresh token 24 h. Refresh with `grant_type=refresh_token&refresh_token=...` (standard grant; parameter names UNVERIFIED).
- Call APIs with `Authorization: Bearer <access_token>`.

### Environment and envelope
- Single production host `https://api.ltl.xpo.com`. **There is no separate sandbox.** BOL and pickup take a mandatory `?testMode=Y|N` URL parameter, and rating and tracking hit production.
- Health check: `GET /{api}/1.0/health-check`. Options: `GET /{api}/1.0/options`.
- Every response is wrapped as `{code, transactionTimestamp (epoch ms), data, error, link}`. Note `"code"` is a **string**, e.g. `"201"`.
- **Rate limits:** per access token, over a sliding 1-minute window, in tiers from **Bronze (5 requests per minute)** to **Diamond (100 requests per minute)**. This comes from an XPO help-center snippet seen in search results; the current help page no longer shows the tiers (UNVERIFIED). Assume Bronze and cache quotes.

### Rate quote: `POST https://api.ltl.xpo.com/rating/1.0/ratequotes`
Other endpoints: `GET /ratequotes/{confirmationNbr}`, `POST /spotquotes` (volume) and `GET /rating/1.0/accessorials/ratequote?origCountry=US&destCountry=US` (lists the accessorial codes valid for a lane; call it and cache the result).
```json
{"shipmentInfo": {
  "paymentTermCd": "P",
  "shipmentDate": "2026-10-05T12:00:00.000-0400",
  "shipper":   {"acctInstId":"1234567","address":{"postalCd":"72143"}},
  "consignee": {"address":{"postalCd":"29640"}},
  "bill2Party": {"address":{"usZip4":""}},
  "commodity": [{"grossWeight":{"weight":800,"weightUom":"LBS"},"nmfcClass":77.5,"hazmatInd":false,"pieceCnt":2,
                 "dimensions":{"length":48,"width":40,"height":48,"dimensionsUom":"INCH"}}],
  "accessorials": [{"accessorialCd":"DLG"},{"accessorialCd":"RSD"}],
  "palletCnt": 2, "linealFt": 0
}}
```
- Business rules:
  - Gross weight over **25,000 lb** is rejected.
  - If any dimension is given for a commodity, all three must be given, in inches.
  - Mexico uses city names instead of postal codes.
  - When an account (`acctInstId`) is a pickup or delivery account, its postal code overrides the one you enter.
  - Use `discountLevel` only when no account is given.
- Excessive length or lineal-foot charges: call `LTLShippingTools` `calculateLinealFeet` first and pass `totalLinealFt`, `excessiveLength` and `cubicCapacity`. Excessive length is accessorial `ELS` with `quantity` and `quantityUom:"Inch"`.
- Guaranteed service is accessorial `GUR` (G!).

Response `data`:
- **`rateQuote.confirmationNbr`** is the quote number.
- **`rateQuote.totCharge[0].amt`**: `totCharge` is an **array** of `{amt}`.
- `rateQuote.totAccessorialAmt.amt`, `rateQuote.totDiscountAmt.amt`
- `rateQuote.shipmentInfo.accessorials[]` entries of `{accessorialCd,accessorialDesc,chargeAmt{amt}}`; fuel is `FSC`.
- `rateQuote.shipmentInfo.commodity[].nmfcClass` comes back as a **string** `"100.0"`.
- `rateQuote.vspApplied` (volume shipment pricing)
- **`transitTime.transitDays`**, `transitTime.estdDlvrDate` (epoch ms), `transitTime.garntInd`
- `msgs[]{message}`; a spot-quote suggestion arrives as a message here.

### BOL: base `https://api.ltl.xpo.com/billoflading/1.0`
| Operation | Method and path |
|---|---|
| create | `POST /billsoflading?testMode=N` |
| update | `PUT /billsoflading/{bolInstId}` |
| cancel | `PUT /billsoflading/{bolInstId}/cancel` (this also cancels the pickup) |
| get | `GET /billsoflading/{bolInstId}/requester` |
| BOL PDF | `GET /billsoflading/{bolInstId}/pdf` → `data.bolpdf{fileName,contentType}`, where contentType holds the PDF content (base64 assumed, UNVERIFIED) |
| PAPS label | `POST .../papslabels/pdf` |
| shipping labels | `POST .../shippinglabels/pdf` with `nbrOfLabels`, `labelPosition` |
| piece-level labels | `POST .../plt/shippinglabels/pdf?printOneLabelPerPage=false` |
| email | `POST .../email` (also for PAPS and shipping labels) |

- Body: `{"bol":{requester{role:"S"},consignee{...},shipper{...},billToCust{...},commodityLine[...],additionalService[{accsrlCode,prepaidOrCollect}],proNbr?,...},"autoAssignPro":?,...}`. Field names are abridged; see the guide for the full element table.
- Create response: `data.bolInfo{bolInstId,pkupInstId,pkupTrmnlSic,pkupCallDate,pkupCallSeq}`. **No PRO is returned unless you supplied `bol.proNbr`.** Getting a PRO back may need a later `GET .../requester` (`proNbr`) or a pre-assigned PRO (UNVERIFIED how auto-assignment works).
- Accessorial codes (`bol.additionalService.accsrlCode`, updated 2026-08-18): `APT` delivery appointment, `DID` inside delivery, `OIP` inside pickup, `DLA` limited access delivery, `OLA` limited access pickup, `DLG` liftgate delivery, `OLG` origin liftgate, `DNC` destination notification, `RSD` residential delivery, `RSO` residential pickup, `CSD`/`OCS` construction sites, `DMS`/`OMS` mine, government or airport, `DSH` Saturday or holiday, `GUR`/`G12` guaranteed, `MBD`/`MBS` must arrive by date, `XFZ` freezable (replaced `PFF`). COD is disabled as of 2025-05-29.

### Pickup: base `https://api.ltl.xpo.com/pickuprequest/1.0`
- `POST /cust-pickup-requests?testMode=N` returns `data.confirmationNbr` (e.g. `"UPO-8-35"`).
- `GET` or `PUT /cust-pickup-requests/{confirmationNbr}`; cancel with `PUT /pickuprequests/{confirmationNbr}/cancel`. The cancel path segment differs from the create path.

### Tracking: `GET https://api.ltl.xpo.com/tracking/1.0/shipments/shipment-status-details?referenceNumbers=<PRO>`
- Other endpoints: `GET /shipments/{proNbr}/tracking-events` and `/shipments/{proNbr}/reference-numbers`.
- Response: `data.shipmentStatusDtls[]{proNbr, pkupDate (epoch ms), shipmentStatus{statusCd (numeric string), reason, dateTime (epoch ms), description}, currSic, origSic, destSic, estdDlvrDate?}`. All timestamps are UTC epoch milliseconds.

| Normalized | XPO `statusCd` |
|---|---|
| pre_transit | 32 recorded in system, 31 BOL image available |
| in_transit | 1 picked up, 2, 3, 5, 6, 25, 29, 43, 44, 38, 39, 40, 41, 42, 10, 13, 14, 11, 18, 24 |
| out_for_delivery | 8 |
| delivered | 23 delivered, 28 final delivery pending review (treat as delivered), 30 delivery receipt available |
| exception | 4, 7 delayed; 12 undeliverable; 15 all short; 17 returned to dock; 19 attempted; 20 delivered part short; 21 refused; 22 refused for damage; 26 cancelled; 27 overage; 33, 35, 36, 37 late or possible delay |

### Errors
- `error` inside the envelope carries the HTTP code and a fault message. Documented codes are 400, 404, 304, 409 and 415. Rate-quote warnings come in `data.msgs[]`, and `errorCd` is "not used" there.
- The exact `error` object field names are UNVERIFIED (the help page names the codes only).

### XPO traps
- `totCharge` is an array. `nmfcClass` is a number in the request and a string `"100.0"` in the response.
- **Canada's country code is `CN`, not `CA`, in the BOL** (changed 2021-10-12).
- Dimension unit is `"INCH"` and weight unit is `"LBS"`. Dates in responses are epoch ms; `shipmentDate` in the request is an ISO string with offset.
- The rate-quote accessorial list from the rating guide's accessorials endpoint uses `DLG/OLG/DID/OIP/RSD/RSO/DNC/TDC/ZHM/...`. Limited access in rating is **UNVERIFIED**: the BOL accepts `DLA`/`OLA`, but the 2021 rating list does not show them; call `/accessorials/ratequote` to confirm. Hazmat in rating is `commodity.hazmatInd:true`; `ZHM` appears as a charge code.
- `TDC` is "time date critical / appointment" in rating, whereas the BOL uses `APT` for appointments.

---

## 5. R+L Carriers

Sources: R+L developer docs `https://technology.rlcarriers.com/api-documentation/{getting-started,rate-quote,bill-of-lading,pickup-request,shipment-tracking,accessorial-documentation}/`. Swagger 2.0 spec **`https://api.rlc.com/swagger/docs/v1`** ("RLC.API"); Swagger UI at `https://api.rlc.com/swagger/ui/index`. The legacy SOAP Rate Quote is deprecated in favor of REST v1.

### Auth
- Static API key in the header **`apiKey: <key>`**. A missing key returns HTTP 400 "No API Key was passed in the Header"; a wrong key returns HTTP 400 "Invalid API Key."
- Get a key from rlcarriers.com: log in, then Resources → API Overview → "Access API Key". The account must be "enabled to accept external rate requests".
- Base URL: `https://api.rlc.com` (the swagger `host`). **No sandbox is documented** (UNVERIFIED).

### Rate quote: `POST https://api.rlc.com/RateQuote`
```json
{"RateQuote": {
  "PickupDate": "10/05/2026",
  "Origin":      {"City":"Ocala","StateOrProvince":"FL","ZipOrPostalCode":"34471","CountryCode":"USA"},
  "Destination": {"City":"Wilmington","StateOrProvince":"OH","ZipOrPostalCode":"45177","CountryCode":"USA"},
  "Items": [{"Class":"77.5","Weight":800,"Length":48,"Width":40,"Height":48}],
  "AdditionalServices": ["DestinationLiftgate","ResidentialDelivery","DeliveryAppointment"],
  "DeclaredValue": 0
}}
```
- `Items[].Weight` is a required integer. `Class` is a string from `50.0, 55.0, 60.0, 65.0, 70.0, 77.5, 85.0, 92.5, 100.0, 110.0, 125.0, 150.0, 175.0, 200.0, 250.0, 300.0, 400.0, 500.0`; the docs list them as `"65.0"` while the sample uses `"65"`, so both likely work (UNVERIFIED).
- Countries are **ISO3** (`USA`, `CAN`). Send `ZipOrPostalCode` as an empty string where there is no ZIP.
- `Pallets[]{Code (4-digit pallet code), Weight, Quantity}` covers pallet pricing (`GET /RateQuote/GetPalletTypes`). `OverDimensions[]{Pieces,Inches}` covers over-length freight.
- `PickupDate` format is not shown in the extracted docs (UNVERIFIED; try MM/dd/yyyy).

Response `200`:
- `RateQuote.ServiceLevels[]{Name/Title, Code ("STD", "GSHW" (guaranteed hourly window) and other guaranteed codes), QuoteNumber, ServiceDays, Charge, NetCharge, HourlyWindow{Start,End}}`
  - **Total = `NetCharge`** (a string).
  - For `STD`, `Charge` is the gross charge. For every other level, `Charge` is the *difference* from STD's net charge.
- `RateQuote.Charges[]{Type,Title,Weight,Rate,Amount}` (strings), `CustomerDiscounts`, `IsDirect`, origin and destination service centers, `CategorizedMessages{GeneralMessages,TransitMessages,RatesMessages}`.
- Envelope: `Code` (int), `Errors[]{Property,ErrorMessage,ExceptionMessage}`, `Messages[]`.

Money strings may include `$` and thousands separators. The format was not seen in the docs, so strip everything except `[0-9.-]` before parsing (UNVERIFIED).

### BOL: `POST https://api.rlc.com/BillOfLading`
- An NMFTA version is also available: `POST /BillOfLading/DigitalCouncilBOL`.
- Body: `{"BillOfLading":{BOLDate*, Shipper*{CompanyName,AddressLine1?,City,StateOrProvince,ZipOrPostalCode,CountryCode,PhoneNumber?}, Consignee*, BillTo?, Items[]{IsHazmat,Pieces,PackageType,NMFCItemNumber,NMFCSubNumber,Description*,Unna{UnnaNumber,...},Class,Weight*} | HandlingUnits[]{UnitType:"PLT",Dimensions[],Items[]*}, AdditionalServices[], HazmatInformation{ContractNumber,ContractHolder}, ReferenceNumbers{ShipperNumber,RateQuoteNumber,PONumber}, FreightChargePaymentMethod:"Prepaid", ServiceLevel:"Standard"}, "PickupRequest":{PickupInformation{PickupDate*,ReadyTime*,CloseTime*,...},Contact{...}}}`. Shipper and consignee address field names are abridged here (filtered from the dump); check the swagger.
- Response: **`ProNumber`**, `PickupRequestNumber`, `Code`, `Errors[]`, `Messages[]`.
- BOL PDF: `GET /BillOfLading/PrintBOL?ProNumber=` returns **`BolDocument`** (base64 PDF).
- Labels: `GET /BillOfLading/PrintShippingLabels?ProNumber=&Style=1..13&StartPosition=&NumberOfLabels=` returns **`ShippingLabelsFile`** (base64 PDF).
- Lookups: `GET /BillOfLading/GetHazmatDescription?UNNANumber=` and `GetNMFCDescription`.
- UnitType and PackageType values: SKD, PLT, BOX, BAG, CRT, CTN, DRM, TOTE, PAIL, BNDL, ROLL, REEL, LSE, UNIT …

### Pickup
- `POST /PickupRequest` returns `PickupRequestId` (int).
- `POST /PickupRequest/FromBOL`; `GET`, `PUT` or `DELETE /PickupRequest`; `GET /PickupRequestHistory`.
- The pickup API uses a generic `Liftgate` accessorial instead of `OriginLiftgate`.

### Tracking: `GET https://api.rlc.com/ShipmentTracing?TraceNumbers=<PRO>&TraceType=PRO`
- TraceType values: PRO (default), BOL, PO, PickupID, Reference, Job, Manifest, Sales, Account, CustomerOrder, CustomerPO, Lot, Order.
- Response: `Shipments[]{ShipmentNumber, ShortStatus, LongStatus, EstimatedDelivery, PickupDate{Date,Time}, AppointmentDate{Date,StartTime,EndTime}, DeliveryDate{Date,Time}, StatusHistory[]{Date,Time,Description,StatusCode,City,State,ZipCode,Latitude,Longitude}, ServiceDelayInfos[]}`.
- R+L does not publish an enumeration of `ShortStatus` or `StatusCode` values (UNVERIFIED). Map by text:

| Normalized | R+L (text match on ShortStatus/LongStatus, UNVERIFIED) |
|---|---|
| pre_transit | no PickupDate yet / "pickup" requested wording |
| in_transit | default |
| out_for_delivery | contains "out for delivery" |
| delivered | `DeliveryDate.Date` present, or text contains "delivered" |
| exception | `ServiceDelayInfos[]` non-empty, or text contains refused, damage, exception, short, delay, "unable to deliver" |

### Rate-quote and BOL accessorial names
R+L uses CamelCase names, not short codes: `OriginLiftgate`, `DestinationLiftgate`, `ResidentialPickup`, `ResidentialDelivery`, `InsidePickup`, `InsideDelivery`, `LimitedAccessPickup`, `LimitedAccessDelivery`, `DeliveryAppointment`, `Hazmat` (rate quote only), `Freezable`, `SortAndSegregate` (rate quote only), `OverDimension` (rate quote only). There is **no notify-before-delivery code in the published list** (UNVERIFIED). Valid services vary by lane.

### R+L traps
- Errors can come back as HTTP 400 with `Errors[]`, and auth errors are also 400, not 401.
- PROs can carry a letter prefix (e.g. `I875682972`), so store them as strings.
- Rate limits are not documented (UNVERIFIED).

---

## 6. SAIA

Sources: SAIA's Azure API Management developer portals. Prod is `https://saiaprodapi.developer.azure-api.net/`; test (pilot) is `https://saiapilotapi.developer.azure-api.net/`. Metadata was read from the public portal data API: `https://saiaprodapi.developer.azure-api.net/developer/apis?api-version=2022-04-01-preview`, plus the `/operations` and `/schemas` subresources. The Shipwell setup article (intercom.help/shipwell) is a secondary source for the key header names, and the portal metadata confirms them. **Response schemas for REST rating and tracking are not published** in the portal metadata, so those fields are UNVERIFIED.

### Auth
- Sign up on the developer portal, then subscribe to each product (Products → Rate Quote, Customer Tracking REST API, Bill of Lading, Images) to get a primary and secondary key. **Each product has its own key and its own header name:**

| API (gateway path) | Header | Query alternative |
|---|---|---|
| REST Rate Quote (`/rate-quote`) | `RQ-Key` | `rq-key` |
| Tracking REST (`/customer-api`) | `Tracking-Key` | `tracking-key` |
| NMFTA eBOL (`/nmfta`) | `Ocp-Apim-Subscription-Key` | `subscription-key` |
| Imaging (`/imaging`) | `CA-Key` | `subscription-key` |
| Legacy SOAP RateQuote (`/RateQuote`), SpotQuote, Shipment | `Ocp-Apim-Subscription-Key` | |

- **A Saia Secure (saiasecure.com) username and password are also required.** For rating they go **in the JSON body** (`userID`, `password`); for tracking, Basic auth is used (per Shipwell; exact header UNVERIFIED). An account number is needed for customer pricing.

### Base URLs
- Prod gateway: `https://api.saia.com/{rate-quote|customer-api|nmfta|imaging}`. Test gateway: `https://saiapilotapi.azure-api.net/...` with separate pilot subscription keys.
- Legacy SOAP WSDL: `https://api.saia.com/wsdl/RateQuote/v1` (also `https://www.saiasecure.com/webservice/ratequote/soap.asmx`).

### Rate quote (REST): `POST https://api.saia.com/rate-quote/webservice/ratequote/customer-api` with `RQ-Key`
Portal example plus the pilot schema:
```json
{
  "userID":"user","password":"pass","payer":"Shipper",
  "origin":{"city":"DEL RIO","state":"TX","zipcode":"78842"},
  "destination":{"city":"SAVANNAH","state":"GA","zipcode":"31406"},
  "weightUnits":"LBS","measurementUnit":"IN",
  "details":[{"length":48,"width":48,"height":30,"weight":876,"class":77.5,"units":2}],
  "accessorials":{"codes":[]}
}
```
- `class` is a **JSON number** here (`77.5`).
- **The REST accessorial codes are not published (UNVERIFIED).** One pilot-portal example for a different internal endpoint uses `"RDEL"` for residential delivery. The legacy SOAP request takes `<Accessorials><AccessorialItem><Code>…`, and Saia's accessorial names include LiftgateService, Residential, Inside, Limited Access, ArrivalNotice/Appointment and Hazardous (from secondary sources; exact codes UNVERIFIED). Get the list from Saia API support before shipping this connector.
- **The response shape is UNVERIFIED.** The legacy SOAP `CreateResult` (via the APIM example) has `QuoteNumber`, `TotalInvoice`, `StandardServiceDays`, `EstimatedDeliveryDate`, `FuelSurchargeAmount`, `DiscountAmount`, `RateDetails/RateDetailItem{Weight,Class,Rate,Amount}`, `RateAccessorials/RateAccessorialItem{Code,Description,Amount}`, `GuaranteeAmount`, `GuaranteeAmount12pm`, `GuaranteeAmount2pm`, `Code`, `Element`, `Fault` and `Message`. The REST response is presumably a JSON analogue with camelCase names; confirm against the pilot environment.
- Saia volume quote numbers start with `E` (QuoteFactory, secondary source). The quote number must be referenced on the BOL for the price to be honored.
- Other rate-quote gateway operations: `GET /api/v1/accounts/zipcodes/{zip}` and `/api/v1/points/zipcodes/{zip}` (service points) and `/health`.

### BOL (NMFTA eBOL v2.0.1): `POST https://api.saia.com/nmfta/bol/v2.0.1/app/` with `Ocp-Apim-Subscription-Key`
- The body follows the NMFTA shape. Required `bol` fields: `function:"Create"`, `requestedPickupDate` (ISO 8601; "**does not serve as a Pickup Request**"), `isTest` and `requestorRole`.
- With `isTest:true` Saia only validates and creates no eBOL.
- Response:
  - **`referenceNumbers.pro`** (e.g. `"77049738070"`) and `referenceNumbers.checkDigit`
  - **`images.bol`** and **`images.shippingLabels`** (base64 PDF). Label formats are `Avery` (3-1/3"x4", 6 per sheet), `Letter` and `Zebra` (4x6).
  - `messageStatus{status:PASS|FAIL|WARNING, code:"200"|"400"|<custom>|"USE", message, resolution, information[]}`
- Accessorials:
  - NMFTA codes: APTD, OVR, HAZ, INBD, IDL, IPU, LFTD, LFTP, LTDAD, LTDAP, MARK, RES, REP, SS, SRT, FVC, PSC, PSH.
  - Saia-specific codes: BORDER, RESD1 (room of choice), WGLV (white glove), EXHIB/EXHIBP (trade show), GROC, MARKO, SORTO, FOOD, GSS5 and GSS12 (guaranteed).
- Handling units: NMFTA codes (PAT pallet, SKD, BOX, CRT, CTN, DRM, BAG, LSE, PCS, …) plus Saia's EA, JT, NS and SW (shrink-wrapped pallet).
- Classes: 50 through 500, including 77.5 and 92.5.

### Pickup
**No public REST pickup API** exists in either APIM portal (UNVERIFIED). The eBOL explicitly is not a pickup request. Options: Saia's legacy SOAP pickup service on saiasecure.com (not researched, UNVERIFIED), the website, or EDI. Treat pickup as unsupported for SAIA in v1 of the connector.

### Tracking: `GET https://api.saia.com/customer-api/webservice/track/pro-numbers/{pro}` with `Tracking-Key`
- Other lookups: `/bol-numbers/{n}/zipcodes/{zip}`, `/purchase-order-numbers/{n}/zipcodes/{zip}`, `/pickup-numbers/{n}/zipcodes/{zip}`, `/shipper-numbers/{n}/zipcodes/{zip}`, `/partner-pro-numbers/{n}`. Non-PRO lookups need a ZIP.
- **The response shape is UNVERIFIED** (not published in the portal). Map statuses by text in the same way as R+L until a sample response is captured from the pilot environment.

### Imaging
`GET https://api.saia.com/imaging/api/v1/getImages?pro={pro}&formatType={formatType}&documentFilter={documentFilter}` with `CA-Key` returns base64. Docs are at `https://api.saia.com/Imaging-DOCS/api-docs/`.

### SAIA traps
- There are four products, four keys and four different header names. Store them per product.
- Saia Secure credentials travel **in the request body** for rating, so never log request bodies.
- Error shape is UNVERIFIED. APIM-level failures look like `{"statusCode":401,"message":"Access denied due to missing subscription key..."}`, which is the standard Azure APIM shape (UNVERIFIED for SAIA specifically).

---

## 7. Accessorial vocabulary matrix (rate quote and BOL)

Rate-quote codes go in the rate request. BOL codes go in the eBOL or BOL request. A dash means no code was found.

| Concept | TForce (rate/BOL/pickup) | ODFL rate | ODFL / Estes / SAIA eBOL (NMFTA) | Estes rate | XPO rate (BOL if different) | R+L rate and BOL | SAIA rate (REST) |
|---|---|---|---|---|---|---|---|
| Liftgate pickup | `LIFO` | `HYO` | `LFTP` | `LGATEP` | `OLG` | `OriginLiftgate` (pickup API: `Liftgate`) | UNVERIFIED |
| Liftgate delivery | `LIFD` (see TForce traps) | `HYD` | `LFTD` | `LGATE` | `DLG` | `DestinationLiftgate` | UNVERIFIED |
| Residential pickup | `shipFrom.isResidential` (charge code `RESP`) | `RPC` | `REP` | `HPU` | `RSO` | `ResidentialPickup` | UNVERIFIED |
| Residential delivery | `shipTo.isResidential` (charge code `RESD`) | `RDC` | `RES` | `HD` (signature/appointment version: `HDSIG`) | `RSD` | `ResidentialDelivery` | UNVERIFIED (`RDEL` seen in a pilot example) |
| Inside pickup | `INPU` | `IPC` | `IPU` | `INP` | `OIP` | `InsidePickup` | UNVERIFIED |
| Inside delivery | `INDE` | `IDC` | `IDL` | `INS` | `DID` (truck-level and non-truck-level variants: `WHI`, `WHN`) | `InsideDelivery` | UNVERIFIED |
| Limited access pickup | `LAPU` | `LPC` | `LTDAP` | `LADPU` / `PRISPU` (secured) / site-specific codes | UNVERIFIED (BOL: `OLA`) | `LimitedAccessPickup` | UNVERIFIED |
| Limited access delivery | `LADL` | `LDC` | `LTDAD` | no generic code; `PRISDL` (secured), `CONST`, `SCHLDL`, `MINE`, `WORDL` … | UNVERIFIED; `DMS` is mine/government/airport (BOL: `DLA`) | `LimitedAccessDelivery` | UNVERIFIED |
| Notify before delivery | `NTFN` | `ARN` | `MNC` | `NCM` | `DNC` | none published | UNVERIFIED |
| Appointment | (UNVERIFIED; no rate code) | `CA` delivery, `CP` pickup (`CA` cannot combine with `ARN`) | `APTD`, `APTP` | `APT` | `TDC` (BOL: `APT`) | `DeliveryAppointment` | UNVERIFIED |
| Hazmat | `dangerousGoods:true` + contact | `shipmentItems.hazardous:true` + `hazardousId` | `HAZ` + `hazardousDetails` | `HAZ` code + `lineItems[].isHazardous` | `commodity.hazmatInd:true` (charge code `ZHM`) | `Hazmat` (rate quote only) + BOL `Items[].IsHazmat` / `Unna` | UNVERIFIED |
| Freeze protection | `serviceOptions.shipment.freezableProtection` | `PFF` | `PSC` | `FRZ` | `XFZ` (older `PFF`) | `Freezable` | UNVERIFIED |

---

## 8. Cross-cutting traps checklist

1. **Freight class type varies per carrier.**
   - Send a string (`"77.5"`) to TForce, Estes (rate and eBOL), R+L and all NMFTA eBOLs.
   - Send a number (`77.5`) to XPO rating and SAIA REST rating.
   - ODFL rating declares an integer (see ODFL traps).
   - Keep class as a canonical decimal string internally (`"77.5"`, `"92.5"`) and cast at the edge. Never use floats for money or class comparisons (`92.5` vs `"92.50"`).
2. **NMFC** is split into item and sub everywhere:
   - TForce: `nmfc.prime` (6 digits) + `nmfc.sub` (2 digits)
   - ODFL rating: `nmfc`/`nmfcSub` as integers
   - Estes, ODFL eBOL and SAIA eBOL: `nmfc`/`nmfcSub` as strings
   - R+L: `NMFCItemNumber`/`NMFCSubNumber`
   - XPO BOL: `commodityLine.sub` (added 2026-08)

   Integer typing drops leading zeros on subs such as `"03"`, so preserve them in your model.
3. **Weights are integer pounds** at ODFL, Estes and R+L. Round up, never down, to avoid under-declaring and reweigh/inspection charges. Dimensions are integers at ODFL and Estes.
4. **Density-based and class-less pricing.** TForce uses `densityEligible` with `handlingUnits[]` dims. Estes VTL and XPO volume pricing use linear feet. Customers on density tariffs may get errors or wrong rates if you send only a class, so always send dims when you have them.
5. **Country codes:** ISO2 at TForce, Estes and XPO rating; **ISO3 at ODFL and R+L** (`USA`, `CAN`, `MEX`); **XPO BOL uses `CN` for Canada**.
6. **Money comes as strings** at TForce, Estes, R+L and SAIA (probably). Parse with bcmath. R+L may include `$` and commas (UNVERIFIED).
7. **Quote numbers are only valid when referenced on the BOL:**
   - ODFL: `referenceNumbers.quoteId`
   - R+L: `ReferenceNumbers.RateQuoteNumber`
   - SAIA: quote number on the BOL; field name UNVERIFIED (likely `referenceNumbers.quoteId` per NMFTA)
   - Estes: `quoteId`; BOL field name not checked, likely the NMFTA `referenceNumbers.quoteId` (UNVERIFIED)

   Persist the quote number with the order.
8. **Test modes differ:** TForce uses a query `api-version=cie-v1`; ODFL and Estes have separate hosts; XPO uses `testMode=Y` (BOL and pickup only; **rating and tracking always hit production**); NMFTA eBOLs take `bol.isTest`; R+L has none; SAIA has a pilot gateway with separate keys.
9. **Token lifetimes:** TForce uses standard Azure AD lifetimes (~60–90 min; UNVERIFIED); ODFL 1 h; Estes ~10 h (`expires_in` 35999); XPO 12 h with a 24 h refresh. Cache per credential set in Craft's cache and refresh on 401.
10. **HTTP 200 with business errors:** ODFL tracking (`ok`, messages), ODFL rating (per-rate `errorMessages`, `totalCharge:null`), NMFTA eBOL (`messageStatus.status=FAIL` with HTTP 400 or 200), XPO (`code` inside the envelope) and Estes rate (`rateFound:false` + `reasons[]`). Never trust the HTTP status alone.
11. **Strict schemas:** TForce rejects unknown properties. ODFL eBOL and Estes validate enums strictly. Strip nulls and empty strings before sending.
12. **Residential and limited access** are booleans at TForce (residential) but codes elsewhere. Several carriers also auto-apply residential and limited-access charges from address classification, so quotes can still differ from invoices. Surface the carriers' rate-disclaimer URLs, e.g. Estes `disclaimersURL`.
