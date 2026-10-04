---
title: Writing a carrier
slug: writing-a-carrier
order: 90
summary: The add-on contract — one class per carrier, what it declares, and the rules the conformance suite enforces.
---

# Writing a carrier

A carrier add-on translates one carrier's API into Carrier's canonical documents, and does
nothing else. No tables, settings screens, templates, queue jobs, packing, retries, logging or
label storage. The gateway does all of that, once, for every carrier.

## Package layout

```
craft-carrier-<slug>/
  composer.json            requires justinholtweb/craft-carrier ^5.0.0
  src/Plugin.php           registers carriers on Carriers::EVENT_REGISTER_CARRIERS
  src/carriers/XCarrier.php
  src/icon.svg             family tile (rx 22.44), filled #FEFEFE glyph, no strokes
  tests/fixtures.php       recorded happy-path responses for the conformance suite
  README.md  CHANGELOG.md  LICENSE.md
```

Namespace `justinholtweb\carrier<slug>`, handle `carrier-<slug>`. A package may register several
carriers: `freight` has six and `posts` has four.

## The class

Extend `justinholtweb\carrier\base\Carrier`. Implement these:

- `handle()` is stable kebab-case and never changes, because connections store it. Use `ups`,
  `fedex`, `usps`, `gls`, `dpd-easyship`, `boxnow`, `freight-odfl`, `posta-hr`, and so on.
- `displayName()`, `region()` ("United States", "Europe", "Freight (US)"), `description()` and
  `setupUrl()`.
- `capabilities()` declares **exactly** what you implement. The conformance suite fails a feature
  that is declared but not implemented, and one that is implemented but not declared.
- `settingsFields()` uses `base\Field`. Mark every credential `Field::secret()`. Use
  `Field::environment()` for a production/sandbox switch; `Carrier::isSandbox()` reads it.
- `services()` returns `['code' => ['name' => …, 'international' => bool, 'points' => bool]]`.
  Set `points: true` for a service that delivers to a pickup point.
- `trackingUrl($number)` returns the carrier's public tracking page as https, or null.
- `probe()` is the cheapest authenticated request available. Return
  `HealthResult::pass('Connected as …', [...details])` or `fail()` with a hint the merchant can act on.

Feature methods, and the matching `Feature` constant to declare for each:

| Feature | Method | Returns |
|---|---|---|
| `RATES` | `rate(ShipmentRequest)` | `RateResult::ok([Rate…])` / `failed(msg)` |
| `LABELS` | `createShipment(ShipmentRequest)` | `ShipmentResult::ok(id, numbers, labels, data)` |
| `CANCEL` | `cancelShipment(ShipmentRef)` | `ActionResult` |
| `REPRINT` | `printLabels(ShipmentRef[], format)` | `LabelResult` (one merged doc when the API can) |
| `TRACKING` | `track(string[] numbers)` | `TrackingInfo[]` (set `trackingBatch(n)`) |
| `POINTS` | `pickupPoints(PointQuery)` | `PointPage`. Use `points(list|search|manual, types…)`; in `manual` mode, return an empty page and let the merchant import a CSV. |
| `PICKUPS` | `requestPickup(PickupRequest)` | `ActionResult` with the confirmation as `reference` |
| `CLOSE_DAY` | `closeDay(ShipmentRef[])` | `ActionResult`, manifest in `data['documents']` |
| `ADDRESS_VALIDATION` | `validateAddress(Address)` | `AddressResult` |
| `WEBHOOKS` | `handleWebhook(Request)` | `TrackingInfo[]`; throw `ForbiddenHttpException` on a bad signature |

These are flags with no method: `COD`, `RETURNS` (handle `$request->isReturn` in
`createShipment()`), `INTERNATIONAL`, `MULTI_PIECE` and `FREIGHT`.

## Rules the conformance suite enforces

1. **Every request goes through `$this->transport()`.** Call `setBaseUri()` and the default
   headers in `buildTransport()`. Authenticate through `buildAuth()`, using
   `auth\OAuth2ClientCredentials`, `ApiKeyAuth` or `BasicAuth`, or your own class extending
   `auth\BaseAuth`. Never call Guzzle directly. The transport logs, redacts, throttles, retries
   and can be replaced by a recorded double.
2. **Classify failures with `ShipmentResult::fromResponse($response)`**:
   - A 4xx other than 408 or 429 is a refusal: not retryable.
   - A 429 is throttling: retryable.
   - A 5xx, 408 or no answer leaves it **uncertain** whether the label exists. Carrier never
     buys an uncertain label again automatically.

   A carrier that answers HTTP 200 with an error list (MyGLS, Odoo-style) must check the list and
   return `ShipmentResult::rejected($message)`.
3. **Never report success without a tracking number and label bytes.** Labels are raw bytes, so
   `base64_decode()` what you receive. The format you claim must match the bytes: `%PDF`,
   `\x89PNG`, or `^XA` for ZPL. Map the carrier's paper options onto `helpers\LabelFormat`, and
   declare only the formats you can produce. GIF labels must be converted (GD), or the format
   must not be offered.
4. **Return tracking numbers in parcel order**: one per parcel for `MULTI_PIECE`, one in total
   otherwise. Put anything needed later (to void or reprint) in `data`. The engine stores it and
   hands it back as `ShipmentRef::$data`.
5. **Map every status onto `base\TrackingStatus`** with `self::mapStatus($code, MAP, default)`.
   Keep the carrier's raw code in `TrackingEvent::$code`. Parse dates with
   `self::parseDate($value, 'Europe/Zagreb')` using the zone the carrier means.
6. **Credentials never reach a message.** The transport redacts non-2xx bodies already, so build
   error text from the response, not from your settings.
7. **Survive empty answers.** An empty 200 must give no rates, no points, no tracking and
   certainly no successful shipment, and must not throw.

## Returns, points and requests without auth

- **Returns**: the request still describes the outbound journey. `shipFrom` is the store and
  `shipTo` is the customer, so swap the parties the way your API wants when `isReturn` is true.
- **Points**: `$request->pointId` is the id, and `$request->point` is the `PickupPoint` the
  customer chose, when Carrier knows it. A search-mode carrier should use it rather than look it
  up again.
- **Public endpoints**: pass `'auth' => false` in a request's options to send it without
  credentials, for example a public point feed on another host.
- **Token failures**: `auth\OAuth2ClientCredentials::lastError()` holds the token endpoint's own
  words, and the connection test shows them.

## Units and data

Everything arrives in grams and millimetres. Convert on the way out with `helpers\Units`:
`gramsToKg()`, `gramsToLb()`, `gramsToWholeLb()` (LTL), `mmToCm()` and `mmToIn()`, which all round up.
Phone numbers arrive normalised (`+385…`); strip the plus if the carrier wants digits only.
`Address::$houseNumber` is split out for the European carriers. Use `streetLine()` for an API
that wants a single line. COD is `$request->codAmount` / `codCurrency` / `codReference`. A pickup
point is `$request->pointId`.

## Honesty

These add-ons are written against published documentation without live accounts. Put every
vendor-specific trap in the carrier's class docblock, where someone debugging that carrier will
find it. Mark anything you could not verify from a primary source with **UNVERIFIED** in that
docblock, and say so in the README. Don't invent fields to make a test pass. If the documentation
is silent, write what the best available source shows and flag it.

## Fixtures

`tests/fixtures.php` returns `['handle' => callable]`. The callable receives
`(string $method, string $url, array $options)` and returns a `base\Response`. Answer each
endpoint with the vendor's own documented sample response, trimmed but structurally faithful, so
that the happy path is exercised: rates priced, a shipment created with real label bytes
(`helpers\Pdf::text([...])` stands in for a PDF and a short `^XA…^XZ` for ZPL), tracking mapped,
points listed and a void accepted. Route on the URL and method. Answer token endpoints with a token.

## Running the suite

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-carrier/tests/integration/carriers.php ups fedex
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-carrier-ups/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

It must finish with `0 failed`.
