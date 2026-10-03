# US parcel carriers: UPS, FedEx, USPS (REST, 2025–2026)

Researched 2026-10-03 for craft-carrier. Plain HTTP (Guzzle), no vendor SDKs. Each fact is tagged:

- **[P]**: read from the carrier's own machine-readable spec or docs (see Sources). Field paths were taken from the OpenAPI schemas, not from prose.
- **[S]**: secondary source (third-party help article or search snippet), cited inline.
- **UNVERIFIED**: no primary source found. This is the best-known shape; confirm it in sandbox before relying on it.

USPS Web Tools (XML) is out of scope. Everything below targets the USPS **v3 REST APIs** at `apis.usps.com`.

## Sources

| Ref | What | Notes |
|---|---|---|
| [UPS-OAS] | `github.com/UPS-API/api-documentation`, the official UPS OpenAPI YAMLs (`OAuthClientCredentials.yaml`, `Rating.yaml`, `Shipping.yaml`, `Tracking.yaml`, `Pickup.yaml`, `Locator.yaml`, `AddressValidation.yaml`, `TimeInTransit.yaml`, `UPSTrackAlert.yaml`), cloned 2026-10-03 | Primary. The same specs drive developer.ups.com. Current version path segment is `v2409`. |
| [UPS-TOKEN] | Pitney Bowes support article 000097963, "UPS OAuth token lifespan change" | [S]. UPS cut token lifetime from 4 h to 1 h starting 2026-04-01. |
| [FX-OAS] | FedEx OpenAPI JSON that the docs pages load: `https://developer.fedex.com/wirc/json/api_groups/{Group}/{Name}-Resource.json` (Rate/RateQuotes, Ship/Shipment, Ship/Tag, Track/TrackingNumbers, Pickup/Pickup, Location/Locations, AddressValidation/Addresses, ServiceAvailability/PackageAndServiceOptions, APIAuthorization) | Primary. Downloaded 2026-10-03. |
| [FX-REF] | `https://developer.fedex.com/wirc/json/api_groups/API_Reference_Guide.json` | Primary. Service types, package types, label stock, tracking event codes. |
| [FX-BP] | developer.fedex.com/api/en-us/guides/best-practices.html and …/guides/ratelimits.html | Primary. Base URLs and throttling. |
| [USPS-OAS] | USPS developer-portal OpenAPI YAMLs, found from each catalog page's `<redoc spec-url=…>`: `developers.usps.com/sites/default/files/apidoc_specs/` → `oauth2_update_1.yaml`, `labels_16_0.yaml`, `international-labels_6_0.yaml`, `payments_5_0.yaml`, `domestic-prices_18.yaml`, `international-prices_11.yaml`, `shipping-options_15.yaml`, `tracking_22.yaml`, `tracking-v3r2_15.yaml`, `carrier-pickup_8.yaml`, `locations_20.yaml`, `addresses-v3r2_0.yaml`, `service-standards_5_0.yaml` | Primary. Downloaded 2026-10-03. The file names are versioned and will change, so re-scrape them from the catalog pages. |
| [USPS-EX] | `github.com/USPS/api-examples` (README curl examples, Postman collection, error inventory xlsx) | Primary (official USPS GitHub). |
| [USPS-FAQ] | developers.usps.com/faq | Primary for the default quota (60 calls/hour/API, per search-indexed text) and the TEM base URL. |

---

# 1. UPS

## 1.1 Auth [P UPS-OAS]

| | Value |
|---|---|
| Token endpoint (sandbox, "CIE") | `POST https://wwwcie.ups.com/security/v1/oauth/token` |
| Token endpoint (prod) | `POST https://onlinetools.ups.com/security/v1/oauth/token` |
| Grant | `client_credentials` |
| Client credentials | HTTP **Basic** auth header: `Authorization: Basic base64(client_id:client_secret)` |
| Body | `application/x-www-form-urlencoded`: `grant_type=client_credentials` |
| Optional header | `x-merchant-id: <6-digit UPS account number>` |
| Response | `{token_type, issued_at, client_id, access_token, scope, expires_in, refresh_count, status}`. **All values are strings**, including `expires_in`. |
| Lifetime | Historically `"14399"` (4 h). **From 2026-04-01 it is 1 h** [S UPS-TOKEN]. Always read `expires_in` and refresh about 60 s early. |
| Token errors | 400 / 401 / 403 ("Blocked Merchant") / 429 ("Quota Limit Exceeded"), with body `{"response":{"errors":[{"code","message"}]}}` |

API base URLs: sandbox `https://wwwcie.ups.com/api`, prod `https://onlinetools.ups.com/api`.

Headers on every API call:
- `Authorization: Bearer <access_token>`
- `transId`: unique per request, ≤32 chars. **Required** by Track and Time-in-Transit, optional elsewhere. Send it always (for example `bin2hex(random_bytes(16))`).
- `transactionSrc`: client/app identifier, ≤512 chars. Same rule: send it always (for example `craft-carrier`).
- `Content-Type: application/json`

The version path segment is `v2409` for Rating, Shipping, Pickup and Void. Locator uses `v3`, Address Validation `v2`, and Track `v1`. Older versions (`v1`, `v1601`, … `v2403`) still work but are listed as deprecated. At `>= v2403`/`v2409`, `RatedShipment`, `RatedPackage`, `PackageResults` and `PackageLevelResults` **are always arrays**. On older versions a single item came back as an object.

## 1.2 Rating [P UPS-OAS Rating.yaml]

`POST /api/rating/{version}/{requestoption}`, for example `/api/rating/v2409/Shop`

`requestoption` takes one of these values:
- `Rate`: one service. `Shipment.Service.Code` is required.
- `Shop`: **all services**. `Service` is ignored.
- `Ratetimeintransit`: one service plus transit times.
- `Shoptimeintransit`: all services plus transit times.

The query parameter `additionalinfo=timeintransit` is an alternative way to ask for transit times.

Minimal request (multi-package, domestic). For international, change `ShipTo.Address.CountryCode` and see the notes after the block:

```json
{
  "RateRequest": {
    "Request": { "TransactionReference": { "CustomerContext": "order-123" } },
    "Shipment": {
      "Shipper": {
        "Name": "Acme", "ShipperNumber": "A1B2C3",
        "Address": { "AddressLine": ["123 Main St"], "City": "Timonium",
                     "StateProvinceCode": "MD", "PostalCode": "21093", "CountryCode": "US" }
      },
      "ShipTo": {
        "Name": "Jane Doe",
        "Address": { "AddressLine": ["1 Peachtree St"], "City": "Alpharetta",
                     "StateProvinceCode": "GA", "PostalCode": "30005", "CountryCode": "US",
                     "ResidentialAddressIndicator": "" }
      },
      "ShipFrom": { "Name": "Acme",
        "Address": { "AddressLine": ["123 Main St"], "City": "Timonium",
                     "StateProvinceCode": "MD", "PostalCode": "21093", "CountryCode": "US" } },
      "PaymentDetails": { "ShipmentCharge": [ { "Type": "01", "BillShipper": { "AccountNumber": "A1B2C3" } } ] },
      "Service": { "Code": "03" },
      "ShipmentRatingOptions": { "NegotiatedRatesIndicator": "" },
      "Package": [
        { "PackagingType": { "Code": "02" },
          "Dimensions": { "UnitOfMeasurement": { "Code": "IN" }, "Length": "10", "Width": "8", "Height": "4" },
          "PackageWeight": { "UnitOfMeasurement": { "Code": "LBS" }, "Weight": "5" } },
        { "PackagingType": { "Code": "02" },
          "Dimensions": { "UnitOfMeasurement": { "Code": "IN" }, "Length": "12", "Width": "12", "Height": "6" },
          "PackageWeight": { "UnitOfMeasurement": { "Code": "LBS" }, "Weight": "9.5" } }
      ]
    }
  }
}
```

International and transit-time additions:
- `Shipment.DeliveryTimeInformation = {"PackageBillType":"03","Pickup":{"Date":"YYYYMMDD"}}` is **required to get any transit info**. `PackageBillType` values: `02` documents, `03` non-documents, `04` WWEF pallet, `07` domestic pallet.
- `Shipment.ShipmentTotalWeight = {"UnitOfMeasurement":{"Code":"LBS"},"Weight":"14.5"}` is required for international `*timeintransit`.
- `Shipment.InvoiceLineTotal = {"CurrencyCode":"USD","MonetaryValue":"150"}` is required for international `*timeintransit`, and for US/PR outbound with UPS Pak (`04`).
- `Shipment.DocumentsOnlyIndicator` (empty tag) marks a documents-only shipment.
- `RateRequest.CustomerClassification.Code` (US origin only): `00` rates tied to the shipper number, `01` daily, `04` retail, `05` regional, `06` general list, `53` standard list.
- `RateRequest.PickupType.Code`: `01` daily pickup (default), `03` customer counter, `06` one-time, `19` letter center, `20` air service center.
- Access Point delivery: `Shipment.ShipmentIndicationType[].Code` is `01` (hold at AP) or `02` (AP delivery), plus `Shipment.AlternateDeliveryAddress`.

Response paths (`RateResponse.RatedShipment[]`):

| Need | Path |
|---|---|
| Service code | `RatedShipment[].Service.Code` (description is often empty, so map it yourself) |
| Published total | `RatedShipment[].TotalCharges.MonetaryValue` (string) and `.CurrencyCode` |
| Negotiated total (present only if `NegotiatedRatesIndicator` was sent and the account is entitled) | `RatedShipment[].NegotiatedRateCharges.TotalCharge.MonetaryValue` / `.CurrencyCode` |
| Total with taxes (only with `TaxInformationIndicator`) | `RatedShipment[].TotalChargesWithTaxes`, `NegotiatedRateCharges.TotalChargesWithTaxes` |
| Per package | `RatedShipment[].RatedPackage[].TotalCharges` |
| Guaranteed days (no TNT request needed) | `RatedShipment[].GuaranteedDelivery.BusinessDaysInTransit`, `.DeliveryByTime`, `.ScheduledDeliveryDate` |
| Transit (`*timeintransit` only) | `RatedShipment[].TimeInTransit.ServiceSummary.EstimatedArrival.BusinessDaysInTransit`, `.Arrival.Date` (YYYYMMDD), `.Arrival.Time` (HHMMSS), `.TotalTransitDays` (international) |
| Warnings | `RateResponse.Response.Alert[]` `{Code, Description}` |
| Success | `RateResponse.Response.ResponseStatus.Code == "1"` |

Price rule: use `NegotiatedRateCharges.TotalCharge` when it is present, otherwise fall back to `TotalCharges`.

Separate Time-in-Transit API (not needed when you use `*timeintransit`): `POST /api/shipments/{version}/transittimes`. `transId` and `transactionSrc` are **required** there. Version value: UNVERIFIED (`v1` is believed current).

## 1.3 Shipment / label [P UPS-OAS Shipping.yaml]

`POST /api/shipments/v2409/ship`. Optional query `additionaladdressvalidation=city`.

`ShipmentRequest.Shipment` requires `Shipper`, `ShipTo`, `Service` and `Package`. In practice `PaymentInformation` is required too.

```json
{
  "ShipmentRequest": {
    "Request": { "RequestOption": "nonvalidate", "TransactionReference": { "CustomerContext": "order-123" } },
    "Shipment": {
      "Description": "T-shirts",
      "Shipper": { "Name": "Acme", "AttentionName": "Shipping", "ShipperNumber": "A1B2C3",
        "Phone": { "Number": "4105551212" },
        "Address": { "AddressLine": ["123 Main St"], "City": "Timonium", "StateProvinceCode": "MD",
                     "PostalCode": "21093", "CountryCode": "US" } },
      "ShipTo": { "Name": "Jane Doe", "AttentionName": "Jane Doe", "Phone": { "Number": "4045551212" },
        "Address": { "AddressLine": ["1 Peachtree St"], "City": "Alpharetta", "StateProvinceCode": "GA",
                     "PostalCode": "30005", "CountryCode": "US", "ResidentialAddressIndicator": "" } },
      "ShipFrom": { "...": "same shape as Shipper, minus ShipperNumber" },
      "PaymentInformation": { "ShipmentCharge": [ { "Type": "01", "BillShipper": { "AccountNumber": "A1B2C3" } } ] },
      "Service": { "Code": "03" },
      "ShipmentRatingOptions": { "NegotiatedRatesIndicator": "" },
      "Package": [
        { "Packaging": { "Code": "02" },
          "Dimensions": { "UnitOfMeasurement": { "Code": "IN" }, "Length": "10", "Width": "8", "Height": "4" },
          "PackageWeight": { "UnitOfMeasurement": { "Code": "LBS" }, "Weight": "5" },
          "ReferenceNumber": [ { "Value": "order-123" } ] },
        { "Packaging": { "Code": "02" }, "PackageWeight": { "UnitOfMeasurement": { "Code": "LBS" }, "Weight": "9.5" } }
      ]
    },
    "LabelSpecification": {
      "LabelImageFormat": { "Code": "ZPL" },
      "LabelStockSize": { "Height": "6", "Width": "4" }
    }
  }
}
```

Labels:
- `LabelSpecification.LabelImageFormat.Code` accepts **`GIF`, `ZPL`, `EPL`, `SPL`**. Ship does **not** accept `PDF` or `PNG`: GIF is the image option, and `HTTPUserAgent` is required when the code is GIF. The response `ImageFormat.Code` can be EPL/SPL/ZPL/GIF/PNG. UPS Premier Silver supports ZPL only.
- `LabelStockSize` applies to thermal formats only: `Width:"4"`, `Height:"6"` or `"8"`. The image never scales beyond 4×6.
- There is no 8.5×11 option. For laser printing, wrap the GIF in your own PDF.
- Label Recovery (`POST /api/labels/{version}/recovery`) **does** accept `PDF`, plus GIF/ZPL/EPL/SPL (Roadie: GIF/PNG/ZPL). Use it to fetch a PDF later.

Response (`ShipmentResponse.ShipmentResults`):

| Need | Path |
|---|---|
| Shipment ID (the 1Z of the first package; used for void) | `ShipmentResults.ShipmentIdentificationNumber` |
| Per-package tracking | `ShipmentResults.PackageResults[].TrackingNumber` (Mail Innovations: `USPSPICNumber`) |
| Base64 label | `PackageResults[].ShippingLabel.GraphicImage`. Format is in `.ImageFormat.Code`. `.HTMLImage` is a base64 HTML wrapper (GIF/PNG only). |
| Charges | `ShipmentResults.ShipmentCharges.TotalCharges.{MonetaryValue,CurrencyCode}`. Negotiated: `ShipmentResults.NegotiatedRateCharges.TotalCharge.{MonetaryValue,CurrencyCode}` |
| Billing weight | `ShipmentResults.BillingWeight.Weight` |
| Customs forms (international) | `ShipmentResults.Form.Image.GraphicImage` (base64 PDF) |

Multi-piece: put multiple objects in `Shipment.Package[]` (schema max 200). The response has one `PackageResults[]` entry, with its own 1Z and label, per package. Return shipments allow up to 20 packages, but **US/PR-origin returns allow only 1**. Mail Innovations and Simple Rate allow 1.

Return label: send a separate ship request with `Shipment.ReturnService.Code`:
- `9` Print Return Label (PRL)
- `8` Electronic Return Label (emailed)
- `3` / `5` Return Service 1-attempt / 3-attempt
- `2` Print & Mail
- `10` Exchange PRL

Swap the parties: `ShipFrom` is the customer, `ShipTo` is the merchant, and `Shipper` stays the account holder. `Package[].Description` is required for returns. Services 13, 59, 82–86 and C6–C8 are not allowed on returns.

International: `Shipment.Description` is required when the countries differ (not UPS Letter; EU-to-EU Standard is exempt). `ShipTo.Phone.Number` is required when the countries differ. Customs data goes in `Shipment.ShipmentServiceOptions.InternationalForms`:

```json
{ "FormType": ["01"], "InvoiceNumber": "INV-1", "InvoiceDate": "20261003", "ReasonForExport": "SALE",
  "CurrencyCode": "USD", "TermsOfShipment": "DDU",
  "Product": [ { "Description": ["Cotton T-shirt"], "CommodityCode": "610910", "OriginCountryCode": "US",
                 "Unit": { "Number": "2", "Value": "15.00", "UnitOfMeasurement": { "Code": "PCS" } } } ],
  "Contacts": { "SoldTo": { "...": "required for invoice" } } }
```

`FormType` values: `01` invoice, `03` CO, `04` USMCA, `05` partial invoice, `06` packing list, `07` customer-generated, … `Contacts.SoldTo` is required for invoice (UNVERIFIED wording; the schema marks it conditional). Duties payer: add `PaymentInformation.ShipmentCharge[]` entry `{"Type":"02", "BillShipper"|"BillReceiver"|…}`.

Signature: `Package[].PackageServiceOptions.DeliveryConfirmation.DCISType`, either `2` (signature) or `3` (adult). There is also a shipment-level `ShipmentServiceOptions.DeliveryConfirmation`, and its code numbering differs: `1` signature, `2` adult. Declared value: `Package[].PackageServiceOptions.DeclaredValue.{Type.Code:"01",CurrencyCode,MonetaryValue}`.

## 1.4 Void [P]

`DELETE /api/shipments/v2409/void/cancel/{shipmentidentificationnumber}`

- To void individual packages, add `?trackingnumber=1Z…` (up to 20 numbers, sent as `trackingnumber=["1Z…","1Z…"]`, per the spec description).
- The ID must be 18 characters, uppercase, and pass 1Z rules.
- Response:
  - `VoidShipmentResponse.SummaryResult.Status.Code`
  - `VoidShipmentResponse.PackageLevelResults[].Status.Code`: `1` = voided or already voided, `0` = not voided.
- Roadie shipments use version `v3` and need the `shipperNumber` header.

## 1.5 Tracking [P UPS-OAS Tracking.yaml]

`GET /api/track/v1/details/{inquiryNumber}?locale=en_US&returnSignature=false&returnMilestones=false&returnPOD=false`

- Headers `transId` and `transactionSrc` are **required**.
- Other endpoints: `/api/track/v1/reference/details/{ref}` (by reference) and `/api/track/v1/shipment/details/{inquiryNumber}?offset=&count=` (every package in a multi-piece shipment).

Response paths:

| Need | Path |
|---|---|
| Package | `trackResponse.shipment[].package[]` (take `[0][0]` for a single 1Z) |
| Current status | `package[].currentStatus.{type,code,statusCode,description,simplifiedTextDescription}` |
| Events (newest first) | `package[].activity[]`, each `{date:"YYYYMMDD", time:"HHMMSS", gmtDate, gmtTime, gmtOffset, status:{type,code,statusCode,description}, location.address:{city,stateProvince,postalCode,countryCode}}` |
| Delivered / scheduled dates | `package[].deliveryDate[]` `{type, date}`. `type`: `SDD` scheduled, `RDD` rescheduled, `DEL` delivered |
| Delivered time / window | `package[].deliveryTime.{type,startTime,endTime}`. `type`: `EOD`, `CMT`, `EDW`, `CDW`, `IDW`, `DEL` |
| Proof of delivery | `package[].deliveryInformation.{receivedBy,location,signature.image,pod.content}` |
| Not found | `trackResponse.shipment[].warnings[]` `{code,message}`, or an HTTP 404 error body |

Status vocabulary: `status.type` is one letter (or two). The TrackAlert spec enumerates `D` Delivery, `I` In-progress, `M` Manifest, `X` Exception [P]. Secondary sources add `MV` (manifest, voided), `P` (pickup), `U` (update), `RS` (returned to shipper) [S nsoftware/UPS guide, UNVERIFIED]. `D` also covers "out for delivery" [S], so disambiguate.

| Normalized | UPS rule |
|---|---|
| pre_transit | `type` in `M`, `MV`. `statusCode` `003` = "Shipment Ready for UPS" [S] |
| in_transit | `type` in `I`, `P`, `U`, unless one of the rules below matches |
| out_for_delivery | `type == D` **and** no `deliveryDate[]` with `type=="DEL"`. Also: description or `simplifiedTextDescription` matches /out for delivery/i. `statusCode` `021` UNVERIFIED |
| delivered | `deliveryDate[].type == "DEL"` exists, or `type == D` with description /delivered/i. `statusCode` `011` UNVERIFIED |
| exception | `type == X` |
| returned | `type == RS` (UNVERIFIED), or description /return(ed|ing) to (the )?sender\|shipper/i |

## 1.6 Pickup and Access Points [P UPS-OAS Pickup.yaml, Locator.yaml]

On-call pickup: `POST /api/pickupcreation/v2409/pickup`, body `{"PickupCreationRequest":{…}}`. Required fields:
- `RatePickupIndicator` ("Y" or "N")
- `Shipper.Account.{AccountNumber,AccountCountryCode}`
- `PickupDateInfo.{PickupDate:"yyyyMMdd",ReadyTime:"HHmm",CloseTime:"HHmm"}`
- `PickupAddress.{CompanyName,ContactName,AddressLine[],City,StateProvince,PostalCode,CountryCode,ResidentialIndicator:"Y|N",Phone.Number}`
- `AlternateAddressIndicator`
- `PickupPiece[].{ServiceCode:"003" (3-digit, UNVERIFIED padding),Quantity,DestinationCountryCode,ContainerCode:"01"}`
- `TotalWeight.{Weight,UnitOfMeasurement:"LBS"}`
- `PaymentMethod`: `00` none, `01` shipper account, `03` card, `04` 1Z

The response gives `PickupCreationResponse.PRN`.

Other pickup endpoints:
- Rate: `POST /api/shipments/v2409/pickup/{oncall|smart|both}`
- Pending: `GET` on the same path, with header `AccountNumber`
- Cancel: `DELETE /api/shipments/v2409/pickup/02` with header `Prn: <PRN>` (`01` cancels by account)

Access Point / location search: `POST /api/locations/v3/search/availabilities/{reqOption}`, with `reqOption=64` for UPS Access Points (`1` = drop/will-call, `32` = retail locations).

```json
{ "LocatorRequest": {
  "Request": { "RequestAction": "Locator" },
  "OriginAddress": { "AddressKeyFormat": { "AddressLine": "1 Peachtree St", "PoliticalDivision2": "Alpharetta",
     "PoliticalDivision1": "GA", "PostcodePrimaryLow": "30005", "CountryCode": "US" } },
  "Translate": { "Locale": "en_US" },
  "UnitOfMeasurement": { "Code": "MI" },
  "LocationSearchCriteria": { "MaximumListSize": "10", "SearchRadius": "25",
     "AccessPointSearch": { "AccessPointStatus": "01" } } } }
```

Results are in `LocatorResponse.SearchResults.DropLocation[]`:
- `LocationID` (internal; do not display it)
- `AccessPointInformation.PublicAccessPointID`: use this as the AP ID on a shipment
- `AddressKeyFormat.{ConsigneeName,AddressLine,PoliticalDivision2,PoliticalDivision1,PostcodePrimaryLow,CountryCode}`
- `Geocode.{Latitude,Longitude}`
- `Distance.Value`
- `OperatingHours`

## 1.7 Address validation [P]

`POST /api/addressvalidation/v2/{requestoption}`. `requestoption`: `1` validation, `2` classification, `3` both (`3` UNVERIFIED wording). Query: `regionalrequestindicator`, `maximumcandidatelistsize` (0–50, default 15).

- Body: `{"XAVRequest":{"AddressKeyFormat":{"AddressLine":["…"],"PoliticalDivision2":"City","PoliticalDivision1":"ST","PostcodePrimaryLow":"30005","CountryCode":"US"}}}`. Coverage is effectively US/PR only (UNVERIFIED).
- Response indicators: `XAVResponse.ValidAddressIndicator`, `AmbiguousAddressIndicator`, `NoCandidatesIndicator`. Each is present or absent.
- Candidates: `XAVResponse.Candidate[].AddressKeyFormat`, plus `Candidate[].AddressClassification.Code`: `0` unknown, `1` commercial, `2` residential.

## 1.8 Service codes [P]

| Code | Service |
|---|---|
| Domestic: `01` | Next Day Air |
| `02` | 2nd Day Air |
| `03` | Ground |
| `12` | 3 Day Select |
| `13` | Next Day Air Saver |
| `14` | Next Day Air Early |
| `59` | 2nd Day Air A.M. |
| `75` | Heavy Goods |
| International: `07` | Worldwide Express |
| `08` | Worldwide Expedited |
| `11` | Standard (CA/MX ground) |
| `54` | Worldwide Express Plus |
| `65` | Worldwide Saver |
| `96` | Worldwide Express Freight |
| `71` | Worldwide Express Freight Midday |
| `72` / `17` | Worldwide Economy DDP / DDU |
| `74` | Express 12:00 |
| `70` | Access Point Economy |

Other codes: Mail Innovations `M2`–`M7`; Roadie `C6`/`C7`/`C8`; UPS Today `82`–`86` (PL).

**Ground Saver** (formerly SurePost) is not listed in the current Ship spec enum. Community sources give `93` (≥1 lb) and `92` (<1 lb) [S ShipperHQ/Zen Cart, UNVERIFIED]. It must be enabled on the account.

Packaging codes (`PackagingType.Code` in Rate, `Packaging.Code` in Ship): `01` UPS Letter, `02` customer package, `03` tube, `04` Pak, `21` Express Box, `24` 25 kg box, `25` 10 kg box, `30` pallet, `2a`/`2b`/`2c` small/medium/large Express Box.

## 1.9 Errors, limits, traps

- Error body (all APIs): `{"response":{"errors":[{"code":"…","message":"…"}]}}`. The message is at `response.errors[0].message`. HTTP status is 400/401/403/404/429/500. The Track 404 uses the same shape.
- Rate limit: 429 "Rate Limit Exceeded" is documented, but no numeric limit is published in the specs (UNVERIFIED).
- **Every number is a string**: `"Weight":"5"`, `"MonetaryValue":"12.34"`, `"Length":"10"`. Parse charges with bcmath or round to 2 decimals.
- **Presence flags**: `ResidentialAddressIndicator`, `NegotiatedRatesIndicator`, `SaturdayDeliveryIndicator`, `DocumentsOnlyIndicator` and similar are "empty tags". Send `""` to turn one on. Omit the key to turn it off. Never send `"N"` or `false`.
- `Rate` uses `Package[].PackagingType`; `Ship` uses `Package[].Packaging`.
- `Rate` uses `PaymentDetails`; `Ship` uses `PaymentInformation`.
- `ShipTo.Address.ResidentialAddressIndicator` in Rate maps to `ShipTo.Address.ResidentialAddressIndicator` in Ship. The Ship example also shows `ShipTo.Residential`; both appear.
- Weight: LBS/KGS, with up to one decimal place in practice. `OZS` is valid only for Worldwide Economy DDU. `DimWeight.Weight` has an implied decimal ("115" = 11.5) and is ignored when `Dimensions` are present.
- Dimensions have 2-decimal precision. Length + 2×(W+H) must be ≤ 165 in.
- Dates are `YYYYMMDD`; times are `HHMMSS` or `HHMM`.
- Without `ShipperNumber`, plus `ShipFrom.StateProvinceCode`, plus `NegotiatedRatesIndicator`, you get list or retail rates only.
- Shop responses may include services the account can't use. Filter them against an allow-list.

---

# 2. FedEx

## 2.1 Auth [P FX-OAS APIAuthorization, FX-BP]

| | Value |
|---|---|
| Base URL sandbox | `https://apis-sandbox.fedex.com` |
| Base URL prod | `https://apis.fedex.com` |
| Token | `POST {base}/oauth/token` |
| Body | `application/x-www-form-urlencoded`: `grant_type=client_credentials&client_id=<API key>&client_secret=<secret key>`. Client ID and secret go **in the body**, not in Basic auth. |
| Other grants | `csp_credentials` (Compatible/Integrator, which adds `child_Key` and `child_secret`). `client_pc_credentials` (proprietary parent/child, which also adds the child fields). |
| Response | `{access_token, token_type:"bearer", expires_in:3600 (integer, seconds), scope:"CXS"}` |
| Lifetime | 1 hour |

Headers on every API call:
- `Authorization: Bearer …`
- `Content-Type: application/json`
- `X-locale: en_US` (optional)
- `x-customer-transaction-id: <your id>` (optional; echoed back as `customerTransactionId`)

The account number goes in each request body as `accountNumber.value`.

CORS is not supported (server-side calls only).

## 2.2 Rating [P FX-OAS RateQuotes]

`POST /rate/v1/rates/quotes`. Shop all services by **omitting `requestedShipment.serviceType`**. Optionally filter by `carrierCodes: ["FDXE","FDXG"]`.

```json
{
  "accountNumber": { "value": "123456789" },
  "rateRequestControlParameters": { "returnTransitTimes": true, "servicesNeededOnRateFailure": true,
                                    "rateSortOrder": "COMMITASCENDING" },
  "requestedShipment": {
    "shipper":   { "address": { "postalCode": "38017", "countryCode": "US", "stateOrProvinceCode": "TN", "city": "Collierville" } },
    "recipient": { "address": { "postalCode": "30005", "countryCode": "US", "stateOrProvinceCode": "GA", "city": "Alpharetta", "residential": true } },
    "shipDateStamp": "2026-10-05",
    "pickupType": "DROPOFF_AT_FEDEX_LOCATION",
    "packagingType": "YOUR_PACKAGING",
    "rateRequestType": ["ACCOUNT", "LIST"],
    "preferredCurrency": "USD",
    "totalPackageCount": 2,
    "requestedPackageLineItems": [
      { "weight": { "units": "LB", "value": 5 },   "dimensions": { "length": 10, "width": 8,  "height": 4, "units": "IN" } },
      { "weight": { "units": "LB", "value": 9.5 }, "dimensions": { "length": 12, "width": 12, "height": 6, "units": "IN" } }
    ]
  }
}
```

International additions:
- `requestedShipment.customsClearanceDetail = {"dutiesPayment":{"paymentType":"SENDER"},"commodities":[{"description":"Cotton T-shirt","quantity":2,"quantityUnits":"PCS","countryOfManufacture":"US","harmonizedCode":"610910","weight":{"units":"LB","value":1},"customsValue":{"amount":30,"currency":"USD"}}]}`. **Commodities are required for international rating.**
- `documentShipment: true` marks a documents-only shipment.
- `totalWeight` (one decimal) applies to international MPS.

Response paths (`output.rateReplyDetails[]`, one per service):

| Need | Path |
|---|---|
| Service code / name | `rateReplyDetails[].serviceType`, `.serviceName` |
| Charge | `rateReplyDetails[].ratedShipmentDetails[]` has one entry per `rateType` (`ACCOUNT`, `LIST`, `PREFERRED`, …). Pick `rateType=="ACCOUNT"` (falling back to `LIST`), then read `.totalNetCharge` (**number**). Also available: `.totalNetFedExCharge`, `.totalBaseCharge`, `.totalNetChargeWithDutiesAndTaxes`. |
| Currency | `ratedShipmentDetails[].shipmentRateDetail.currency` (UNVERIFIED: a sibling `ratedShipmentDetails[].currency` may also appear in live responses) |
| Transit | `rateReplyDetails[].commit.dateDetail.dayFormat` ("2026-10-07T10:30:00"). `.commit.daysInTransit` is an enum word (`ONE_DAY`…`TWENTY_DAYS`, `UNKNOWN`). `.commit.transitDays.description` holds Ground text such as "2-7 Business Days". `.operationalDetail.transitTime` (e.g. `THREE_DAYS`) and `.operationalDetail.deliveryDate`. Requires `returnTransitTimes:true`. |
| Alerts | `output.alerts[]` `{code, alertType: NOTE\|WARNING, message}` |
| Customer messages | `rateReplyDetails[].customerMessages[]` `{code,message}` |

Max 100 packages per rate request. `shipDateStamp` must not be in the past or more than 10 days out.

Transit-only API: `POST /availability/v1/transittimes` (Service Availability).

## 2.3 Ship / label [P FX-OAS Shipment-Resource, FX-REF]

`POST /ship/v1/shipments`

```json
{
  "accountNumber": { "value": "123456789" },
  "labelResponseOptions": "LABEL",
  "requestedShipment": {
    "shipper": { "contact": { "personName": "Acme Shipping", "phoneNumber": "9015551212", "companyName": "Acme" },
                 "address": { "streetLines": ["10 FedEx Pkwy"], "city": "Collierville", "stateOrProvinceCode": "TN",
                              "postalCode": "38017", "countryCode": "US" } },
    "recipients": [ { "contact": { "personName": "Jane Doe", "phoneNumber": "4045551212" },
                      "address": { "streetLines": ["1 Peachtree St"], "city": "Alpharetta", "stateOrProvinceCode": "GA",
                                   "postalCode": "30005", "countryCode": "US", "residential": true } } ],
    "shipDatestamp": "2026-10-05",
    "serviceType": "FEDEX_GROUND",
    "packagingType": "YOUR_PACKAGING",
    "pickupType": "USE_SCHEDULED_PICKUP",
    "shippingChargesPayment": { "paymentType": "SENDER" },
    "labelSpecification": { "imageType": "ZPLII", "labelStockType": "STOCK_4X6" },
    "requestedPackageLineItems": [
      { "weight": { "units": "LB", "value": 5 },   "dimensions": { "length": 10, "width": 8, "height": 4, "units": "IN" },
        "customerReferences": [ { "customerReferenceType": "CUSTOMER_REFERENCE", "value": "order-123" } ] },
      { "weight": { "units": "LB", "value": 9.5 } }
    ]
  }
}
```

Notes:
- **Trap**: Rate uses `shipDateStamp` (capital S); Ship uses `shipDatestamp` (lowercase s).
- Rate uses `recipient` (object); Ship uses `recipients` (array).
- `requestedShipment.totalWeight` is marked REQUIRED in the Ship schema (number, one decimal); in practice it is used for international MPS. Send the sum.

Label formats [P FX-REF Label Stock Types]:
- `imageType`: `PDF`, `PNG`, `ZPLII`, `EPL2`.
- Thermal (`ZPLII`/`EPL2`) stock: `STOCK_4X6`, `STOCK_4X675_LEADING_DOC_TAB`, `STOCK_4X675_TRAILING_DOC_TAB`, `STOCK_4X8`, `STOCK_4X9`, `STOCK_4X9_LEADING_DOC_TAB`, `STOCK_4X9_TRAILING_DOC_TAB`, `STOCK_4X85_TRAILING_DOC_TAB`, `STOCK_4X105_TRAILING_DOC_TAB`.
- Laser (`PDF`/`PNG`) stock: `PAPER_4X6`, `PAPER_4X675`, `PAPER_4X8`, `PAPER_4X9`, `PAPER_7X475`, `PAPER_85X11_TOP_HALF_LABEL`, `PAPER_85X11_BOTTOM_HALF_LABEL`, `PAPER_LETTER`.
- `labelSpecification.resolution`: `203` (default) or `300`. 300 is ZPL only.
- FedEx warns that printing PDF or PNG on a thermal printer scales the barcode badly.
- `labelResponseOptions`: `LABEL` returns base64 inline. `URL_ONLY` returns a download URL (`mergeLabelDocOption` controls the merged PDF).

Response (`output.transactionShipments[]`):

| Need | Path |
|---|---|
| Master tracking | `transactionShipments[].masterTrackingNumber` (also `completedShipmentDetail.masterTrackingId.trackingNumber`) |
| Per package | `transactionShipments[].pieceResponses[].trackingNumber` |
| Base64 label | `pieceResponses[].packageDocuments[]` where `contentType=="LABEL"`, field `.encodedLabel`; `.docType` (PDF/PNG/ZPLII/EPL2); `.url` when `URL_ONLY` |
| Shipment documents (commercial invoice, etc.) | `transactionShipments[].shipmentDocuments[]` (same shape) |
| Charges | `transactionShipments[].completedShipmentDetail.shipmentRating.shipmentRateDetails[]`: pick by `rateType` (e.g. `PAYOR_ACCOUNT_SHIPMENT`), then `.totalNetCharge`, `.currency`. Per piece: `pieceResponses[].netChargeAmount` |
| Alerts | `transactionShipments[].alerts[]`, `output.alerts[]` |
| Async job | `output.jobId`. Poll with `POST /ship/v1/shipments/results` |

Multi-piece (MPS): put all packages in `requestedPackageLineItems[]` ("single-shot MPS"). Up to **40 packages** are processed synchronously. Above that, set `processingOptionType:"ALLOW_ASYNCHRONOUS"`; `labelResponseOptions` must then be `LABEL`, and you fetch results via `jobId`. The alternative is `oneLabelAtATime:true` with `totalPackageCount` and `masterTrackingId` on child requests.

International: `requestedShipment.customsClearanceDetail` must include:
- `dutiesPayment.paymentType`: SENDER / RECIPIENT / THIRD_PARTY
- `isDocumentOnly`
- `commercialInvoice.{shipmentPurpose: SOLD|GIFT|SAMPLE|REPAIR_AND_RETURN|PERSONAL_EFFECTS|NOT_SOLD, termsOfSale}`
- `commodities[]`, each with `{description (≤450, REQUIRED), countryOfManufacture, quantity, quantityUnits, unitPrice{amount,currency}, customsValue{amount,currency}, weight{units,value}, harmonizedCode}`
- `totalCustomsValue`

Add `shippingDocumentSpecification` to get a FedEx-generated commercial invoice.

Return label: send a separate Ship call with `requestedShipment.shipmentSpecialServices = {"specialServiceTypes":["RETURN_SHIPMENT"], "returnShipmentDetail":{"returnType":"PRINT_RETURN_LABEL", "rma":{"reason":"…"}}}`. Swap shipper and recipient. For an emailed label, use `returnType:"PENDING"` with `pendingShipmentDetail` (`EMAIL`) and `shipAction`. Ground call tags use `POST /ship/v1/shipments/tag`.

## 2.4 Cancel [P]

`PUT /ship/v1/shipments/cancel`

- Body: `{"accountNumber":{"value":"123456789"},"trackingNumber":"794953555571","senderCountryCode":"US","deletionControl":"DELETE_ALL_PACKAGES"}`
- Response: `output.cancelledShipment` (bool), `output.cancelledHistory`, `output.message`, `output.alerts[]`.

## 2.5 Tracking [P FX-OAS TrackingNumbers, FX-REF]

`POST /track/v1/trackingnumbers`

- Body: `{"includeDetailedScans":true,"trackingInfo":[{"trackingNumberInfo":{"trackingNumber":"123456789012"}}]}`
- Max **30** numbers per call. Optional `shipDateBegin` / `shipDateEnd` and `trackingNumberInfo.carrierCode` to disambiguate.

Response (`output.completeTrackResults[].trackResults[]`):

| Need | Path |
|---|---|
| Status | `trackResults[].latestStatusDetail.{code, derivedCode, description, statusByLocale}`. Exception text: `.ancillaryDetails[]`, `.delayDetail` |
| Events | `trackResults[].scanEvents[]`: `{date (ISO with offset), eventType, eventDescription, derivedStatusCode, derivedStatus, exceptionCode, exceptionDescription, scanLocation.{city,stateOrProvinceCode,postalCode,countryCode}}` |
| Dates | `trackResults[].dateAndTimes[]` `{type, dateTime}`. `type` includes `ACTUAL_DELIVERY`, `ACTUAL_PICKUP`, `ESTIMATED_DELIVERY`, `COMMITMENT`, `ATTEMPTED_DELIVERY`, … |
| ETA window | `trackResults[].estimatedDeliveryTimeWindow.window.{begins,ends}`, `standardTransitTimeWindow.window.ends` |
| Per-number error | `trackResults[].error.{code,message}` (e.g. `TRACKING.TRACKINGNUMBER.NOTFOUND`). The call can return HTTP 200 with errors inside. |

Normalization, using `latestStatusDetail.derivedCode` (fall back to `.code`). Codes come from [P FX-REF] tracking event tables:

| Normalized | FedEx codes |
|---|---|
| pre_transit | `OC` label created, `MD` manifest data, `PD` pickup delayed (or treat as exception), `DS` vehicle dispatched |
| in_transit | `PU` picked up, `DO` dropped off, `IP` in FedEx possession, `IT`, `AR`, `AF`, `DP`, `TR`, `CC`, `CP`, `EA`, `AC`, `OX` (handed to USPS for Ground Economy), `PM`, `CH`, `US`, `AE`, `AO`, `DY`, `IN` |
| out_for_delivery | `OD`. Optionally `HP` (ready for pickup at Hold at Location) |
| delivered | `DL` |
| exception | `DE`, `DD`, `SE`, `CD`, `CA` (cancelled by sender), `RR`/`RM`/`RA` (delivery change requests: informational, keep the prior state) |
| returned | `RS` (returning to shipper), `RT` (return to shipper requested), `RP`/`RG`/`RD`/`LC` (return-label link events; ignore for outbound) |

## 2.6 Pickup and locations [P FX-OAS Pickup, Locations]

Pickup: `POST /pickup/v1/pickups`. Required fields:
- `associatedAccountNumber.value`
- `originDetail.pickupLocation.{contact, address.{streetLines,city,stateOrProvinceCode,postalCode,countryCode}}`
- `originDetail.readyDateTimestamp` (e.g. `"2026-10-05T11:00:00Z"`)
- `originDetail.customerCloseTime` (`"18:00:00"`)
- `carrierCode`: `FDXE` | `FDXG`. **Express and Ground need separate pickups.**
- Also `pickupType` (e.g. `ON_CALL`), `totalWeight`, `packageCount`. UNVERIFIED which of these are strictly required.

The response gives `output.pickupConfirmationCode` and `output.location`.

- Availability: `POST /pickup/v1/pickups/availabilities`.
- Cancel: `PUT /pickup/v1/pickups/cancel` with `{associatedAccountNumber, pickupConfirmationCode, scheduledDate:"YYYY-MM-DD", location}`.

Locations: `POST /location/v1/locations`

- Body: `{"locationSearchCriterion":"ADDRESS","location":{"address":{"postalCode":"30005","countryCode":"US"}},"locationTypes":["FEDEX_OFFICE","FEDEX_AUTHORIZED_SHIP_CENTER"],"sort":{"criteria":"DISTANCE","order":"ASCENDING"},"resultsRequested":10}`. `resultsRequested` is UNVERIFIED.
- `locationSearchCriterion` also accepts `GEOGRAPHIC_COORDINATES` and `PHONE_NUMBER`.
- `locationTypes`: `FEDEX_AUTHORIZED_SHIP_CENTER`, `FEDEX_OFFICE`, `FEDEX_SELF_SERVICE_LOCATION`, `FEDEX_ONSITE` (retail partners such as Walgreens/Dollar General), `FEDEX_EXPRESS_STATION`, `FEDEX_HOME_DELIVERY_STATION`, `FEDEX_FREIGHT_SERVICE_CENTER`.
- Result fields: `output.locationDetailList[].{locationId, distance.{value,units}, contactAndAddress.address, geoPositionalCoordinates.{latitude,longitude}, storeHours…}`, and `output.totalResults`.
- Hold-at-location delivery uses `shipmentSpecialServices.specialServiceTypes:["HOLD_AT_LOCATION"]` + `holdAtLocationDetail.locationId`. The `holdAtLocationDetail` path is UNVERIFIED.

## 2.7 Address validation [P]

`POST /address/v1/addresses/resolve`

- Body: `{"addressesToValidate":[{"address":{"streetLines":["7372 Parkridge Blvd","Apt 286"],"city":"Irving","stateOrProvinceCode":"TX","postalCode":"75063","countryCode":"US"}}]}`
- Result fields: `output.resolvedAddresses[].{streetLinesToken[], city, stateOrProvinceCode, postalCode, countryCode, classification: BUSINESS|RESIDENTIAL|MIXED|UNKNOWN, attributes{…}, customerMessages}`. `attributes` holds DPV, Resolved, etc. as string `"true"`/`"false"` (UNVERIFIED specifics).

## 2.8 Service types [P FX-REF, US list]

Domestic:
- `FIRST_OVERNIGHT`, `PRIORITY_OVERNIGHT`, `STANDARD_OVERNIGHT`
- `FEDEX_2_DAY_AM`, `FEDEX_2_DAY`, `FEDEX_EXPRESS_SAVER`
- `FEDEX_GROUND` (business; also international ground to CA)
- `GROUND_HOME_DELIVERY` (residential)
- `SMART_POST` (= **FedEx Ground Economy**; needs `smartPostInfoDetail.{hubId, indicia}`)
- `SAME_DAY`, `SAME_DAY_CITY`
- Freight: `FEDEX_1_DAY_FREIGHT`, `FEDEX_2_DAY_FREIGHT`, `FEDEX_3_DAY_FREIGHT`, `FEDEX_FIRST_FREIGHT`

International:
- `FEDEX_INTERNATIONAL_PRIORITY_EXPRESS`, `INTERNATIONAL_FIRST`, `FEDEX_INTERNATIONAL_PRIORITY`, `INTERNATIONAL_ECONOMY`, `FEDEX_INTERNATIONAL_CONNECT_PLUS`
- `INTERNATIONAL_PRIORITY_FREIGHT`, `INTERNATIONAL_ECONOMY_FREIGHT`, `FEDEX_INTERNATIONAL_DEFERRED_FREIGHT`
- `INTERNATIONAL_PRIORITY_DISTRIBUTION`, `INTL_GROUND_DISTRIBUTION`

`FEDEX_INTERNATIONAL_PRIORITY` and `INTERNATIONAL_ECONOMY` replaced the older `INTERNATIONAL_PRIORITY`. Rate may still echo legacy names, so map both.

Package types: `YOUR_PACKAGING`, `FEDEX_ENVELOPE`, `FEDEX_PAK`, `FEDEX_BOX`, `FEDEX_SMALL_BOX`, `FEDEX_MEDIUM_BOX`, `FEDEX_LARGE_BOX`, `FEDEX_EXTRA_LARGE_BOX`, `FEDEX_10KG_BOX`, `FEDEX_25KG_BOX`, `FEDEX_TUBE`.

Weight limits: 150 lb for Express/Ground in your own packaging; 70 lb for Ground Economy.

## 2.9 Errors, limits, traps

- Error body: `{"transactionId":"…","customerTransactionId":"…","errors":[{"code":"SERVICE.UNAVAILABLE.ERROR","message":"…","parameterList":[{"key","value"}]}]}`. The message is at `errors[0].message`. Codes are dotted uppercase strings.
- Rate limits [P FX-BP ratelimits]:
  - **1,400 transactions per 10 s per project**. Exceeding it returns 429 until the window ends.
  - Organization daily quota of **500,000 requests**. Exceeding it returns 429 "Daily transaction quota exceeded" until 00:00 GMT.
  - There are also per-project quotas.
  - FedEx asks you not to load-test against sandbox or prod.
- Numbers are real JSON numbers. Dimensions are **integers** (no decimals, max 999), so round up. Weight takes a decimal; `totalWeight` takes 1 decimal.
- Residential flag: `address.residential: true` on the recipient. Ground to residential must use `GROUND_HOME_DELIVERY`, not `FEDEX_GROUND`. Rate often returns both, so filter by the address type.
- `phoneNumber` is required on shipper and recipient contacts. `personName` or `companyName` is required.
- `streetLines`: at least 1, at most 3 (35 chars each, UNVERIFIED).
- `stateOrProvinceCode` must be 2 characters for US/CA/PR.
- Sandbox only works with FedEx's mock tracking numbers (see FX-REF "Mock Tracking Numbers"). Sandbox rates are not real.
- Production label printing requires **label certification** (FedEx validates sample labels before enabling prod Ship) [S, common knowledge, UNVERIFIED for 2026 process].

---

# 3. USPS (v3 REST)

## 3.1 Auth and payment [P USPS-OAS Oauth, payments; USPS-EX]

| | Value |
|---|---|
| Base prod | `https://apis.usps.com` |
| Base test (TEM) | `https://apis-tem.usps.com`. TEM labels are watermarked and not billed [P USPS-FAQ]. |
| Token | `POST {base}/oauth2/v3/token` |
| Body | **JSON** `{"grant_type":"client_credentials","client_id":"<Consumer Key>","client_secret":"<Consumer Secret>"}`. `application/x-www-form-urlencoded` is also accepted. |
| Response | `{access_token, token_type:"Bearer", issued_at (ms), expires_in (int seconds, example 28799 ≈ 8 h), status, scope:"addresses … labels prices tracking …", issuer, client_id, application_name, api_products, public_key}` |
| Other grants | `refresh_token`, `authorization_code` (only for resource-owner flows) |
| Revoke | `POST /oauth2/v3/revoke` |
| Token errors | RFC 6749 shape `{"error":"invalid_client","error_description":"…","error_uri":"…"}`. 429 = `SpikeArrestViolation`, with a `Retry-After` header. |

`scope` lists the APIs this app may call. Labels, international labels, pickup and so on only appear once USPS has granted access to the app.

**Label payment authorization** (required for every `labels`/`international-labels` call):

`POST /payments/v3/payment-authorization` (Bearer)

```json
{ "roles": [
  { "roleName": "PAYER",       "CRID": "12345678", "MID": "123456", "manifestMID": "123456", "accountType": "EPS", "accountNumber": "1000012345" },
  { "roleName": "LABEL_OWNER", "CRID": "12345678", "MID": "123456", "manifestMID": "123456", "accountType": "EPS", "accountNumber": "1000012345" } ] }
```

- Response: `paymentAuthorizationToken`.
- Send it as the header **`X-Payment-Authorization-Token`**.
- Valid for **8 hours**. USPS asks you to cache it rather than calling per label [P].
- Other roles: `RETURN_LABEL_PAYER`, `RATE_HOLDER`, `RETURN_LABEL_RATE_HOLDER`, `MAIL_OWNER`.
- `accountType`: `EPS` (Enterprise Payment System account) or `PERMIT` (which also needs `permitZIP`).
- CRID and MID come from the USPS Business Customer Gateway.

Account prerequisites: a USPS business account with CRID, MID and an EPS account funded and linked. Default developer apps get OAuth, Addresses, Domestic and International Prices, Locations, Service Standards, Shipping Options and Tracking. **Labels, international labels and carrier pickup need a separate access request** [P USPS-FAQ] (pickup is UNVERIFIED as gated).

## 3.2 Rating [P USPS-OAS domestic-prices, international-prices, shipping-options]

There are three ways to rate. USPS has no multi-package request; **rate each package separately** and sum.

**(a) Shop all classes + delivery standard in one call: Shipping Options (recommended)**

`POST /shipments/v3/options/search`

```json
{ "originZIPCode": "21093", "destinationZIPCode": "30005",
  "pricingOptions": [ { "priceType": "COMMERCIAL",
                        "paymentAccount": { "accountType": "EPS", "accountNumber": "1000012345" } } ],
  "packageDescription": { "weight": 5, "length": 10, "width": 8, "height": 4,
                          "mailingDate": "2026-10-05", "packageValue": 50 } }
```

- Omit `packageDescription.mailClass` to get every eligible class. `shippingFilter: "PRICE" | "SERVICE_STANDARDS"` collapses the result to the single cheapest or fastest option.
- International: replace `destinationZIPCode` with `destinationCountryCode` (and optionally `foreignPostalCode`).
- Response:
  - `pricingOptions[].shippingOptions[].mailClass`
  - `…rateOptions[].totalBasePrice` (number), which is postage + fees
  - `…rateOptions[].totalPrice`, which includes extra services and is returned only if `extraServices` were sent
  - `…rateOptions[].rates[].{description, productName, SKU, price, zone, mailClass}`
  - `…rateOptions[].commitment.{name ("2 Days"), scheduleDeliveryDate, expectedDeliveryDate, guaranteedDelivery, cutOffTime}`
- There are no commitments for international or return classes.
- Currency is always USD (implicit; there is no currency field).

**(b) Single class: Domestic Prices**

`POST /prices/v3/base-rates/search`. Required fields: `originZIPCode`, `destinationZIPCode`, `weight` (lb), `length`, `width`, `height` (in), `mailClass`, `processingCategory`, `rateIndicator` (`SP` = single piece), `destinationEntryFacilityType` (`NONE`), `priceType` (`RETAIL`|`COMMERCIAL`|`CONTRACT`). Optional: `mailingDate`, `accountType`, `accountNumber`.

- Response: `totalBasePrice`, `rates[].{price, description, SKU, zone, weight, dimWeight, fees[]}`.
- `POST /prices/v3/total-rates/search` takes the same body plus `extraServices[]` and `itemValue`. Response: `rateOptions[].{totalBasePrice,totalPrice,rates[],extraServices[]}`.
- `POST /prices/v3/base-rates-list/search` lists every rate option for the given ingredients.

**(c) International Prices**

`POST /international-prices/v3/base-rates/search` (also `total-rates`, `base-rates-list`). Takes `destinationCountryCode` plus `foreignPostalCode`, `mailClass` (`FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE`, `PRIORITY_MAIL_INTERNATIONAL`, `PRIORITY_MAIL_EXPRESS_INTERNATIONAL`), and `processingCategory`, `rateIndicator`, `priceType`, `weight`/dims, `mailingDate`.

Delivery days without a price: `GET /service-standards/v3/estimates?originZIPCode=&destinationZIPCode=&acceptanceDate=&mailClass=` returns days and the expected date. International: `GET /international-service-standard/v3/international-service-standard`.

## 3.3 Labels [P USPS-OAS labels_16_0, international-labels_6_0; USPS-EX]

Domestic: `POST /labels/v3/label`. Headers:
- `Authorization: Bearer …`
- `X-Payment-Authorization-Token: …`
- `Content-Type: application/json`
- `Accept: application/vnd.usps.labels+json` (see the trap below)
- `X-Idempotency-Key: <uuid>` (optional; reuse it to reprint or cancel)

```json
{
  "imageInfo": { "imageType": "ZPL203DPI", "labelType": "4X6LABEL", "receiptOption": "NONE",
                 "suppressPostage": false, "suppressMailDate": false, "returnLabel": false,
                 "packageNumber": 1, "totalPackages": 2 },
  "toAddress":   { "firstName": "Jane", "lastName": "Doe", "streetAddress": "1 Peachtree St", "secondaryAddress": "Apt 2",
                   "city": "Alpharetta", "state": "GA", "ZIPCode": "30005", "phone": "4045551212" },
  "fromAddress": { "firm": "Acme", "streetAddress": "123 Main St", "city": "Timonium", "state": "MD", "ZIPCode": "21093" },
  "packageDescription": {
    "mailClass": "USPS_GROUND_ADVANTAGE", "rateIndicator": "SP", "processingCategory": "MACHINABLE",
    "destinationEntryFacilityType": "NONE", "mailingDate": "2026-10-05",
    "weightUOM": "lb", "weight": 5, "dimensionsUOM": "in", "length": 10, "width": 8, "height": 4,
    "extraServices": [920], "packageOptions": { "packageValue": 50 },
    "customerReference": [ { "referenceNumber": "order-123", "printReferenceNumber": true } ]
  }
}
```

`customerReference` item fields are UNVERIFIED.

`packageDescription` comes in two shapes (`oneOf`):
1. With `rateIndicator`: `SP` single piece; `FE`/`FP`/`FA` flat-rate envelopes; `FS` small FR box; `FB` medium FR box; `PL` large FR box; `CP` cubic; `P5`–`P9` cubic soft pack; and others.
2. **Without** `rateIndicator`, using `shape` (`RECTANGULAR` | `NON_RECTANGULAR`) and `packagingType` (`VARIABLE`, `FLAT_RATE_ENVELOPE`, `SM_FLAT_RATE_BOX`, `MD_FLAT_RATE_BOX`, `LG_FLAT_RATE_BOX`, `CUBIC_PARCELS`, `CUBIC_SOFT_PACK`, …).

Required on the base: `mailClass`, `processingCategory`, `destinationEntryFacilityType`, `mailingDate`, `weight`, `length`, `width`, `height`.

Rate-indicator change on 2026-07-12: `Q6`–`Q0` were removed, and `P5`–`P9` dimensions changed [P].

Label formats:
- `imageInfo.imageType`: `PDF` (default), `TIFF`, `JPG`, `PNG`, `GIF`, `SVG`, `ZPL203DPI`, `ZPL300DPI`, `EPL300DPI`, `LABEL_BROKER` (QR code for printing at the Post Office), `NONE`.
- `labelType`: `4X6LABEL` (default), `4X4LABEL`, `4X5LABEL`, `6X4LABEL`, `2X7LABEL`.
- There is **no 8.5×11 sheet type**. A 4×6 PDF is the paper option; use `receiptOption: SAME_PAGE` to put the receipt on the page.
- Restrictions:
  - EPL only supports 4X6.
  - 2X7 doesn't support ZPL.
  - Customs forms support 4X6 only.
  - ZPL/EPL can't use `SAME_PAGE` receipts.
- `suppressPostage` and `suppressMailDate` **default to true**, so set them to false if you want postage and date printed.

Response:
- The default `200` content type is **`multipart/form-data`**, with part `labelMetadata` (JSON) plus part `labelImage` (binary) plus optional `receiptImage` and `returnLabelImage`.
- Send `Accept: application/vnd.usps.labels+json` to get a single JSON object instead: `LabelMetadata` fields plus `labelImage` (base64), `receiptImage` (base64), `labelBrokerQR`, `returnLabel…`.
- Metadata paths:
  - `trackingNumber` (22-digit IMpb)
  - `postage` (number, USD)
  - `extraServices[].{name,SKU,price}`
  - `zone`
  - `commitment.{name,scheduleDeliveryDate}`
  - `routingInformation` ("420"+ZIP)
  - `labelAddress` (the standardized to-address)
  - `SKU`
  - `fees[]`
  - `serviceTypeCode`
  - `links[].href` (tracking URL)

Multi-piece: there is **no shipment grouping**. Send one `POST /label` per package. `imageInfo.packageNumber` and `totalPackages` only print "1 of N" on the label (not on 2x7/4x4).

Returns:
- **Pay-on-use return label as a separate request**: `POST /labels/v3/return-label` (same headers; body `ReturnLabelRequest`). `fromAddress` is the customer and `toAddress` is the merchant. Return classes include `USPS_GROUND_ADVANTAGE_RETURN_SERVICE`, `PRIORITY_MAIL_RETURN_SERVICE`, `PRIORITY_MAIL_EXPRESS_RETURN_SERVICE`, `GROUND_RETURN_SERVICE` (taken from the price/options enums; the label enum names are UNVERIFIED). This needs the `RETURN_LABEL_PAYER` role.
- **Outbound with return attached**: set `imageInfo.returnLabel: true` on `POST /label`. The return label comes back alongside the outbound. Its class follows the outbound class: PME → PME Return, PM → PM Return, anything else → Ground Advantage Return.

International: `POST /international-labels/v3/international-label` returns **201**, not 200.
- Same headers.
- `mailClass`: `FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE` | `PRIORITY_MAIL_INTERNATIONAL` | `PRIORITY_MAIL_EXPRESS_INTERNATIONAL`.
- `imageType`: `PDF`, `TIFF`, `ZPL203DPI`, `ZPL300DPI`, `LABEL_BROKER`, `NONE`. `labelType` is `4X6LABEL` only.
- `toAddress` uses `{firstName,lastName|firm, streetAddress, city, province, postalCode, country, countryISOAlpha2Code, phone}`. Canada postal codes must start with a letter.
- `customsForm` is required: `{customsContentType: MERCHANDISE|GIFT|DOCUMENT|COMMERCIAL_SAMPLE|RETURNED_GOODS|OTHER|…, AESITN: "NO EEI 30.37(a)" (or an ITN), contents[]:{itemDescription, itemQuantity (int), itemTotalValue (USD), itemTotalWeight (lb), weightUOM:"lb", HSTariffNumber, countryofOrigin}}`. Note the lowercase "o" in `countryofOrigin`. `itemValue` and `itemWeight` are deprecated in favour of the `*Total*` fields.
- `mailingDate` may be today through today + 7.
- Response: `labelMetadata.internationalTrackingNumber` (e.g. `EZ000012162US`), `labelMetadata.postage`, `labelImage`.

## 3.4 Cancel / refund [P]

- `DELETE /labels/v3/label/{trackingNumber}`, with header `X-Payment-Authorization-Token`. Alternatively `DELETE /labels/v3/label` with header `X-Idempotency-Key` set to the key used at creation.
- International: `DELETE /international-labels/v3/international-label/{trackingNumber}`.
- Response: `{trackingNumber, status: "CANCELED" | "DISPUTED", disputeId}`.
  - `CANCELED`: no Shipping Services File (SSF) was created yet, so there's no charge.
  - `DISPUTED`: an SSF already existed, so a refund request was opened and `disputeId` tracks it.
- Errors include "refund same label same day" and "not found" examples.

Edit after creation: `PATCH /labels/v3/label/{trackingNumber}` (JSON-Patch array; weight, dims, rate indicator, processing category, containers). Only allowed when the label was created with `suppressPostage = true`.

Reprint: `POST /labels/v3/label-reprint/{trackingNumber}` or `POST /labels/v3/label-reprint` (with the idempotency key).

## 3.5 Tracking [P USPS-OAS tracking_22, tracking-v3r2_15; USPS-EX]

Two versions are live:
- **v3**: `GET /tracking/v3/tracking/{trackingNumber}?expand=DETAIL` (or `SUMMARY`). Optional `destinationZIPCode` and `mailingDate`. Returns one object.
- **v3r2 (Tracking 3.2, the modernized backend)**: `POST /tracking/v3r2/tracking` with body `[{"trackingNumber":"9400…","mailingDate":"2026-10-05","destinationZIPCode":"30005"}]`. It's a batch: an array in, an array out, and HTTP **207** on mixed results. Optional `includeVeriPoint`.
  - **Note**: v3r2 dropped `originCity/State/ZIP` and `destinationCity/State/ZIP` in June 2025 [P].

Response (v3 object or each v3r2 array item):

| Need | Path |
|---|---|
| Status | `status` (text), `statusCategory` (text category), `statusSummary` (sentence) |
| Events (newest first) | `trackingEvents[]`: `{eventType (text), eventCode, eventTimestamp (local ISO), GMTTimestamp (v3r2), eventCity, eventState, eventZIP(Code), eventCountry, firm, name, authorizedAgent}` |
| Expected delivery | v3r2: `deliveryDateExpectation.expectedDeliveryDate` (UNVERIFIED for v3) |
| Delivered date | the timestamp of the event with `eventCode == "01"` |
| Access / billing | v3r2: `accessControl` = `FREE` \| `IP_AGREEMENT` (detail data for others' packages can be gated) |

The `statusCategory` values are **not enumerated in the spec**, which defers to Pub 199 Appendix G-4 (domestic) and G-5 (international). Values seen in practice include `Pre-Shipment`, `Accepted`, `In Transit`, `Out for Delivery`, `Delivered`, `Alert`, `Available for Pickup`, `Delivery Attempt` and `Return to Sender` (UNVERIFIED). `eventCode` values are Pub 199 G-4 two-character codes (UNVERIFIED list from memory of Pub 199). **Map on `eventCode` first**, then on `statusCategory` text:

| Normalized | eventCode (Pub 199, UNVERIFIED) | statusCategory text |
|---|---|---|
| pre_transit | `MA` (label/manifest created), `GX` (label cancelled) | Pre-Shipment |
| in_transit | `03` accepted, `OA`/`80` picked up / acceptance, `10` processed, `07` arrived at unit, `81`/`82`/`PC` processed / arrived / departed, `T1` | Accepted, In Transit |
| out_for_delivery | `OF` | Out for Delivery |
| delivered | `01` (and `17` picked up at PO/agent; `16`/`14` mean available for pickup, so treat those as out_for_delivery) | Delivered |
| exception | `02`/`52`–`57` notice left / attempt, `04` refused, `05` undeliverable, `08` missent, `31`, `44` | Alert, Delivery Attempt |
| returned | `09` return to sender, `21`–`29` undeliverable reasons | Return to Sender |

## 3.6 Pickup and locations [P USPS-OAS carrier-pickup_8, locations_20]

Carrier pickup is free and runs Monday–Saturday. Same-day requests must be in before 2:00 AM CT on the pickup day.

- Eligibility: `GET /pickup/v3/carrier-pickup/eligibility?streetAddress=&city=&state=&ZIPCode=`
- Create: `POST /pickup/v3/carrier-pickup`
  - Body: `{pickupDate, pickupAddress:{firstName,lastName,firm,address:{streetAddress,city,state,ZIPCode,ZIPPlus4},contact:[{email}|{phone}]}, packages:[{packageType: PRIORITY_MAIL|PRIORITY_MAIL_EXPRESS|USPS_GROUND_ADVANTAGE|FIRST-CLASS_PACKAGE_SERVICE|RETURNS|INTERNATIONAL|OTHER, packageCount}], estimatedWeight (lb), pickupLocation:{packageLocation: FRONT_DOOR|BACK_DOOR|SIDE_DOOR|KNOCK_ON_DOOR|MAIL_ROOM|OFFICE|PORCH|RECEPTION|MAILBOX|OTHER, specialInstructions, dogPresent}, nextAvailablePickup}`
  - Response: `confirmationNumber`, `pickupDate`. The `ETag` response header is needed later.
- Get: `GET /pickup/v3/carrier-pickup/{confirmationNumber}`
- Update: `PUT` on the same path, with header `If-Match: <ETag>`
- Cancel: `DELETE` on the same path, with header **`If-Match: <ETag>`** (required for update and cancel)

Locations:
- `GET /locations/v3/post-office-locations`. Query by `ZIPCode` or address, or `LAT`/`LONG`, with `radius` (miles). Filters: `postOfficeType`, `pickupService` (e.g. `PICKUP_PARCEL_LOCKER`, which needs length/width/height), `hours`, `offset`, `limit`.
- `GET /locations/v3/parcel-locker-locations?ZIPCode=` (or `city` + `state`), with `offset` and `limit`. This is the USPS "locker" search.
- `GET /locations/v3/dropoff-locations?destinationZIPCode=&mailClass=PARCEL_SELECT` finds destination-entry facilities (DDU/SCF/NDC) for Parcel Select.

## 3.7 Address validation [P USPS-OAS addresses-v3r2]

`GET /addresses/v3/address?streetAddress=&secondaryAddress=&city=&state=&ZIPCode=&ZIPPlus4=&urbanization=&firm=`. `streetAddress` and `state` are required, plus `city` or `ZIPCode` (UNVERIFIED combination rule).

Response:
- `address.{streetAddress, secondaryAddress, city, state, ZIPCode, ZIPPlus4, urbanization}`
- `additionalInfo.{DPVConfirmation: Y|D|S|N, business: Y|N, DPVCMRA, DPVVacant (UNVERIFIED), carrierRoute, deliveryPoint, returnCode (31 single match, 32 default match / needs secondary, 22 multiple, 21 not found, …), returnCodeText}`
- `corrections[]` and `matches[]` `{code,text}`

Other lookups: `GET /addresses/v3/city-state?ZIPCode=` and `GET /addresses/v3/zipcode?streetAddress=&city=&state=`. US addresses only.

## 3.8 Mail classes [P]

Domestic label `mailClass`:
- `USPS_GROUND_ADVANTAGE`, `PRIORITY_MAIL`, `PRIORITY_MAIL_EXPRESS`
- `PARCEL_SELECT`. With `destinationEntryFacilityType: NONE` it converts to Ground Advantage.
- `PARCEL_SELECT_LIGHTWEIGHT`: deprecated, converts to Parcel Select.
- `FIRST-CLASS_PACKAGE_SERVICE`: deprecated, converts to Ground Advantage.
- `MEDIA_MAIL`, `LIBRARY_MAIL`, `BOUND_PRINTED_MATTER` (content-restricted)
- `USPS_CONNECT_LOCAL`, `USPS_CONNECT_REGIONAL`, `USPS_CONNECT_MAIL`, `MARKETING_MAIL_PARCELS`

Prices and options also accept: `USPS_RETAIL_GROUND`, `USPS_GROUND_ADVANTAGE_RETURN_SERVICE`, `PRIORITY_MAIL_RETURN_SERVICE`, `PRIORITY_MAIL_EXPRESS_RETURN_SERVICE`, `GROUND_RETURN_SERVICE`.

International: `FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE`, `PRIORITY_MAIL_INTERNATIONAL`, `PRIORITY_MAIL_EXPRESS_INTERNATIONAL`. Shipping Options also lists `GLOBAL_EXPRESS_GUARANTEED`.

Extra services (integers):

| Code | Service |
|---|---|
| `920` | USPS Tracking |
| `921` | Signature Confirmation |
| `922` | Adult Signature 21+ |
| `923` | Adult Signature Restricted Delivery |
| `924` | Signature Confirmation Restricted Delivery |
| `930` | Insurance ≤ $500 |
| `931` | Insurance > $500 |
| `925` | PME Merchandise Insurance |
| `910`–`913` | Certified Mail variants |
| `955` | Return Receipt |
| `957` | Return Receipt Electronic |
| `981` | Signature Requested (PME only) |
| `986` | PO to Addressee (PME only) |
| `991` | Sunday Delivery |
| `415` | USPS Label Delivery |
| `480`–`489` | Tracking Plus |
| `810`–`8xx` | HAZMAT classes |
| `365` | Global Direct Entry |

Insurance (`930`/`931`) requires `packageOptions.packageValue`.

## 3.9 Errors, limits, traps

- Error body: `{"apiVersion":"…","error":{"code":"400","message":"…","errors":[{"status","code","title","detail","source":{"parameter","example"}}]}}`. Show `error.message`, plus each `errors[].detail` and `errors[].source.parameter`. OAuth errors use the RFC shape instead (§3.1).
- The full error catalogue is in `USPS/api-examples/Error Inventory/API Error Consolidated List.xlsx`.
- Warnings in 200 responses: `extraServices[].warnings[]` / `rates[].warnings[]` `{warningCode, warningDescription}`. Codes `001`/`002`/`003` cover contract-rate, dim-weight and COD notes [P USPS-FAQ].
- **Quota: 60 calls per hour per API by default** [P USPS-FAQ, via search-indexed text]. Exceeding it returns 429. Raise it with the "Registration for API Access" form. This makes caching rates essential: cache by (origin ZIP, destination ZIP/country, rounded weight, dims, class) for at least the day.
- Weight is in **pounds, decimal** (`weightUOM: "lb"` is the only value). Ounces aren't accepted, so divide by 16. Maximum 70 lb.
- Dimensions are in inches, decimal (`dimensionsUOM: "in"`). If you send any dimension, send all three. Send `girth` only for non-rectangular packages. Dimensions over 12 in on any side affect pricing (dim weight / nonstandard fees).
- `mailingDate` may be today through today + 7. A label's default time is 10:00 PM local.
- Address keys are **camelCase but `ZIPCode`, `ZIPPlus4`, `SKU`, `CRID`, `MID` are uppercase**, and international uses `countryofOrigin`. Copy keys exactly.
- `firstName` + `lastName`, or `firm`, is always required on `toAddress` and `fromAddress`. `ignoreBadAddress: true` bypasses origin street validation.
- Domestic labels return HTTP **200**; international labels return **201**. Check for 2xx, not `== 200`.
- If you don't send the Accept header from §3.3, the label comes back as multipart (see the trap there).
- Labels need extra product access plus a funded EPS account. TEM labels are watermarked. Plan for an approval lead time.

---

# 4. Cross-carrier normalization cheat-sheet

| Concept | UPS | FedEx | USPS |
|---|---|---|---|
| Token TTL | 1 h (since 2026-04-01; was 4 h) | 1 h | ~8 h (`expires_in` 28799) |
| Client creds in | Basic header | form body | JSON or form body |
| Shop all | `/rating/v2409/Shop` | omit `serviceType` | `/shipments/v3/options/search` without `mailClass` |
| Multi-piece | `Package[]` (≤200), one call | `requestedPackageLineItems[]` (≤40 sync) | one label call per package |
| Numbers | strings | numbers (integer dims) | numbers |
| Weight units | LBS/KGS (OZS only for DDU) | LB/KG | lb only |
| Label base64 | `PackageResults[].ShippingLabel.GraphicImage` | `pieceResponses[].packageDocuments[].encodedLabel` | `labelImage` (vnd JSON), or the multipart `labelImage` part |
| Tracking # | `PackageResults[].TrackingNumber` | `pieceResponses[].trackingNumber` | `trackingNumber` / `internationalTrackingNumber` |
| Thermal | ZPL/EPL/SPL 4×6 or 4×8 | ZPLII/EPL2 STOCK_4X6… | ZPL203DPI/ZPL300DPI/EPL300DPI 4X6 |
| Paper | GIF (via Ship) / PDF (via Label Recovery) | PDF/PNG PAPER_4X6, PAPER_85X11_TOP_HALF_LABEL, PAPER_LETTER | PDF/PNG/… 4X6 only |
| Void | `DELETE …/void/cancel/{1Z}` | `PUT /ship/v1/shipments/cancel` | `DELETE /labels/v3/label/{tn}` (cancel or refund dispute) |
| Error message | `response.errors[0].message` | `errors[0].message` | `error.message` / `error.errors[].detail` |
| Rate limit | 429 (number unpublished) | 1,400 / 10 s; 500k / day | 60 / h / API (default) |
