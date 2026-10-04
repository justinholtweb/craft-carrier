---
title: Console
slug: console
order: 80
summary: Every console command, its options, and what to run from cron.
---

# Console

Every command exits non-zero on failure, so they are safe to chain and to run from cron or CI.

## Connections

### `carrier/connections/list`

Lists every connection — handle, carrier, enabled or disabled, and whether it is a sandbox — then the
carriers your installed add-ons register.

```sh
php craft carrier/connections/list
```

```
gls-hr                   gls                  enabled
ups-test                 ups                  enabled (sandbox)
dpd-hr                   dpd-easyship         disabled

Installed carriers: mock, store-pickup, gls, ups, dpd-easyship
```

### `carrier/connections/test <handle>`

Tests a connection's saved credentials, exactly as **Test connection** does in the control panel:
the details the carrier returned on success, the reason and any hints on failure.

```sh
php craft carrier/connections/test gls-hr
```

Exits `0` when the test passes and non-zero when it fails or the connection's add-on is missing —
useful as a deploy check.

## Labels

### `carrier/labels/create <order>`

Buys the label for one order, by reference or by id.

```sh
php craft carrier/labels/create 1000123
php craft carrier/labels/create 1000123 --connection=ups-live --service=03
php craft carrier/labels/create 1000123 --dry-run
```

| Option | |
|---|---|
| `--connection` | A connection handle. Defaults to the connection of the order's own checkout method. Required if the order is not on a Carrier method |
| `--service` | A service code. Defaults to the method's service |
| `--dry-run` | Print the shipment request that would be sent, as JSON, and buy nothing |

The purchase is claimed per order and connection, the same as **Create shipping labels**: running the
command twice buys one label. A refused or uncertain purchase prints the reason and exits non-zero;
it is then on **Carrier → Problems** like any other. See [Labels](/plugins/craft-carrier/docs/labels).

`--dry-run` is the quickest way to see what Carrier is sending — weights in grams, dimensions in
millimetres, the addresses after phone and house-number handling, the pickup point, COD and customs
lines — including anything your own `EVENT_AFTER_BUILD_REQUEST` listener changed.

## Tracking

### `carrier/tracking/refresh`

Polls every shipment whose next tracking check is due, batched per connection.

```sh
php craft carrier/tracking/refresh
php craft carrier/tracking/refresh --limit=2000
```

| Option | |
|---|---|
| `--limit` | How many due shipments to poll in one run. Default 500 |

Prints how many shipments were due and how many changed. See [Tracking](/plugins/craft-carrier/docs/tracking) for the
schedule.

### `carrier/tracking/show <tracking number>`

Polls one shipment now and prints its status and full history.

```sh
php craft carrier/tracking/show 1Z999AA10123456784
```

```
1Z999AA10123456784: In transit — Departed from facility
  2026-10-02 18:40:00  inTransit        Departed from facility Charlotte, NC
  2026-10-02 09:12:00  inTransit        Origin scan Charlotte, NC
  2026-10-01 16:03:00  preTransit       Shipper created a label
```

## Pickup points

### `carrier/points/sync`

Syncs pickup points from carriers that publish a list (GLS, BOX NOW, Hrvatska pošta, Magyar Posta,
Pick up in store).

```sh
php craft carrier/points/sync                       # every list connection whose points are due
php craft carrier/points/sync --connection=gls-hr   # one connection, now
php craft carrier/points/sync --force               # every point connection, due or not
```

| Option | |
|---|---|
| `--connection` | One connection's handle. Syncs it whether or not it is due |
| `--force` | Sync every connection with pickup points, even if its list is fresh |

Without options, a connection is due once its list is older than `pointsSyncHours` (default 24).
Prints the number of points per connection. If a sync fails, the previous points are kept.

### `carrier/points/import <connection> <file>`

Imports pickup points from a CSV file, for carriers with no point API (DPD EasyShip).

```sh
php craft carrier/points/import dpd-hr storage/dpd-points.csv
```

Columns, by header name: `id`, `name`, `type`, `street`, `city`, `postcode`, `country`, `latitude`,
`longitude`, `hours`, `cod`. Only `id`, `name` and `country` are required. Prints how many were
imported and skipped, with the reason for each skipped line. A point with an id that is already
stored is updated rather than duplicated. See [Pickup points](/plugins/craft-carrier/docs/pickup-points#importing-a-csv).

## From cron

Carrier runs tracking polls and point syncs from ordinary page traffic by default. For a quiet site,
or to control exactly when they happen:

```cron
*/30 * * * *  cd /path/to/site && php craft carrier/tracking/refresh
15 3 * * *    cd /path/to/site && php craft carrier/points/sync
```

Both are safe to run alongside the page-traffic route. Craft's own `php craft gc` prunes old labels
and log entries.
