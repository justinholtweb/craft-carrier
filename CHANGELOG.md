# Release Notes for Carrier

## 5.1.0 - 2026-11-08

### Added
- Methods and connections can have an optional checkout logo (a method's own, else its connection's). It is available in Twig as `method.logo` / `method.logoUrl`, through `craft.carrier.byHandle(handle)` (which takes a method handle or a Commerce `carrier-…` handle), and as `method.logoUrl` in the `points/search` JSON. ([#5](https://github.com/justinholtweb/craft-carrier/issues/5))
- A carrier service can declare the point types it delivers to (`'pointTypes' => [PickupPoint::TYPE_LOCKER]` in `services()`), and a method can choose its own under "Pickup point types". `points/search` lists only those types, and `points/select` refuses any other. ([#3](https://github.com/justinholtweb/craft-carrier/issues/3))
- Settings fields can say which features need them (`'requiredFor' => Feature::allExcept(Feature::POINTS)`). Such a field no longer blocks enabling a connection. The features that need it check it when they are used: a label purchase is refused (not attempted), live rates fall back, and tracking, voiding, reprints, collections and closes are skipped with a message naming the missing fields. `Connection::can()`, `missingCredentials()` and `credentialsError()` expose this. ([#4](https://github.com/justinholtweb/craft-carrier/issues/4))

### Fixed
- `points/search` used the cart's post code even when the shopper typed a town, so `city=Split` on a Bjelovar cart returned Bjelovar points. The cart's address is now used only when the request names no place at all (no `postcode`, `city` or `lat`/`lng`). This also affected the bundled picker, which sends `city` for anything that is not a number. ([#2](https://github.com/justinholtweb/craft-carrier/issues/2))
- The `actionSearch()` docblock gave a method handle with a dash, which Craft cannot save.

## 5.0.0 - 2026-10-03

- Initial release.
- Carrier connections (stored in the database), checkout methods priced flat, by weight table or
  live with markup, rounding, a fallback price and free-over thresholds.
- Packing into configured boxes, one parcel or one per item, in store units.
- Claim-first label purchase: retries never buy twice, interrupted or unanswered purchases wait
  on the Problems screen, refusals can be retried.
- Label storage, reprint and bulk printing (merged carrier batches, ZPL streams, PNG print sheets,
  ZIP).
- Tracking by polling and webhooks, with deduplicated scans, statuses that never move backwards,
  and order status write-back.
- Pickup points: daily-synced lists, live search, CSV import, a drop-in storefront picker and a
  payment guard.
- Cash on delivery from chosen gateways; collections and end-of-day closes.
- Order screen panel, shipment screen, order-index actions and tracking column.
- Console commands, built-in Mock carrier and store pickup.
