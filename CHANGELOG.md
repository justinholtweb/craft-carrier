# Release Notes for Carrier

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
