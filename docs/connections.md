---
title: Connections
slug: connections
order: 20
summary: Adding a carrier account, credentials and environment variables, sandbox, label format, ship-from, testing and webhooks.
---

# Connections

A connection is one carrier account. Everything carrier-specific — credentials, environment, label
format, where parcels leave from — lives on the connection, not on the carrier. A store can have
several connections to the same carrier: a live UPS account and a sandbox one, or GLS Croatia and
GLS Slovenia.

## Adding one

**Carrier → Connections → New connection.**

1. **Carrier.** Pick from the carriers your installed add-ons register, grouped by region. The
   form reloads with that carrier's own fields.
2. **Name.** What you call this account: "GLS Croatia", "UPS sandbox".
3. **Handle.** Used by console commands (`php craft carrier/connections/test gls-hr`) and in the
   webhook URL. Keep it once you have set it.
4. **Enabled.** Connections start switched off. Required credentials are only enforced once a
   connection is enabled, so you can save a half-finished setup and come back with the missing key.
5. **Credentials.** Whatever this carrier declares — a client ID and secret, a username and
   password, an account number, a country. Each carrier's page says where to find them, and the
   connection screen links to the carrier's own developer portal.
6. **Label format** (see below).
7. **Ship from** (see below).

Then save, and press **Test connection**.

## Credentials and environment variables

Every credential field accepts an environment variable. Type `$` and Craft suggests the ones it
knows:

```
UPS_CLIENT_ID="..."
UPS_CLIENT_SECRET="..."
```

```
Client ID      $UPS_CLIENT_ID
Client secret  $UPS_CLIENT_SECRET
```

Use them. A secret typed directly into the field is stored in the database; one in `.env` is not.

Secrets are never sent back to the browser. A saved secret shows as dots with "saved — leave blank
to keep it", so saving the form without retyping it keeps what is stored. An environment variable
reference is shown as written, because it is not the secret.

Secrets never appear in the log either. The transport removes them from error responses before
anything is logged or shown, and **Test connection** redacts what it reports.

## Sandbox

Carriers with a test environment have an **Environment** field: *Production* or *Sandbox / test*.
Sandbox connections are marked **Sandbox** in the connection list and in `carrier/connections/list`.

Most carriers issue **separate credentials** for their sandbox and production systems. The
environment must match the keys, or the test fails with the carrier's own refusal.

Sandbox labels are not valid for shipping. Make a separate connection for each environment rather
than switching one back and forth: methods point at a connection, so you can move a checkout method
from the sandbox connection to the live one when you are ready.

Not every carrier has a sandbox. DPD EasyShip documents none (its own advice is to create a parcel
and delete it while it is unprinted), and R+L Carriers has none. Each carrier's page says.

## Label format

Pick the format that matches the printer on the packing bench. Only formats the carrier can
actually produce are offered.

| Format | For |
|---|---|
| PDF, A4 sheet | An office printer. GLS and Hrvatska pošta print four to a page |
| PDF, A6 thermal | A 100 × 150 mm thermal printer, printing PDFs |
| PDF, 4×6 in thermal | A 4 × 6 in thermal printer, printing PDFs |
| PDF, US Letter sheet | An office printer in the US |
| ZPL (Zebra thermal) | A Zebra-compatible printer, sent raw. The fastest and sharpest option |
| PNG image, 4×6 in | Printed from the browser. Several PNGs open as one print sheet |

Blank uses the carrier's first format. You can choose a different format for a single label on the
order's shipment screen. See [Labels](/plugins/craft-carrier/docs/labels) for how mixed formats print in bulk.

## Ship from

Leave it blank and parcels leave from the store's location set in Commerce. Fill it in to ship this account from somewhere else — a warehouse, or a
second country. Street and country code are the minimum; most carriers also want a phone number
and email for the sender.

The ship-from address is also where collections are booked. See [Collections](/plugins/craft-carrier/docs/collections).

Values accept environment variables, the same as credentials.

## Test connection

Save first: the test uses what is stored. It checks that every required field is filled in, then
makes the cheapest authenticated request the carrier offers. A pass shows a tick, the account it
reached, and any details the carrier returned. A failure shows a cross, the reason, and a hint
where there is one — "Credentials are incomplete" when a required field is empty, or the carrier's
own refusal:

```
→ The carrier refused the credentials: invalid_client
```

When the carrier's token endpoint refuses the credentials, its own words are shown, because "Blocked
Merchant" or "invalid_client" is usually the most useful sentence there is. The same test runs from
the console with `php craft carrier/connections/test <handle>`.

## Pickup points on the connection

For a carrier with pickup points, the connection screen shows how many are stored and when they
were last synced, with **Sync now** for list carriers and **Browse and import** for all of them.
List carriers also get a **Pickup point countries** field: comma-separated country codes to sync.
Blank uses the carrier's home countries. See [Pickup points](/plugins/craft-carrier/docs/pickup-points).

## Webhooks

A carrier that pushes tracking shows a **Tracking webhook URL** on its connection:

```
https://example.com/carrier/webhook/<connection handle>
```

Paste it into the carrier's portal and tracking arrives as it happens instead of on the next poll.
Signature checking is the carrier's job, and every carrier signs differently; a push that fails it is
refused. A push can only update shipments bought on that connection, so a forged one cannot mark
another carrier's parcel delivered.

None of the carrier add-ons declares webhooks yet, so in practice tracking is polled. The Mock
carrier has one, signed with an `X-Mock-Signature` HMAC, if you want to see the flow. See
[Tracking](/plugins/craft-carrier/docs/tracking).

## Why connections are in the database, not project config

Because project config deploys. A staging site's sandbox UPS account arriving in production on the
next deploy, or a production account being exercised by a developer's local site, is the worst
thing this plugin could do. Checkout methods point at connections, so they live in the database
too.

The consequence is that you set connections and methods up on each environment. Plugin settings —
packing, boxes, field handles, statuses, tracking intervals — hold no credentials and go through
project config as normal. See [Configuration](/plugins/craft-carrier/docs/configuration).

## Deleting a connection

Deleting a connection deletes its checkout methods and its synced pickup points. Shipments already
made are kept, with their tracking and history.

If an add-on is uninstalled, its connections stay, marked **Add-on missing**. Reinstall the add-on
and everything comes back.
