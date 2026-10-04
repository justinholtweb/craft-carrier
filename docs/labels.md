---
title: Labels
slug: labels
order: 50
summary: Buying labels one at a time or in bulk, why a label is never bought twice, the Problems screen, voiding, printing, returns and cash on delivery.
---

# Labels

A label costs money, so Carrier treats buying one more carefully than anything else it does. This
page covers how you buy them, what happens when a carrier says no or says nothing, and how to get
them onto paper.

## From the order screen

Every order shows a **Carrier** panel on its edit screen: the checkout method, the pickup point
chosen, any shipments with their tracking, and **Manage shipments**, which opens the order's
shipment screen.

The shipment screen is where you buy, void, reprint and track. To buy a label:

1. **Connection.** Defaults to the order's own checkout method's connection. You can ship with any
   enabled connection that makes labels.
2. **Service.** Defaults to the method's service.
3. **Parcels.** What the packer produced, with weight and dimensions in the store's units. Change
   them, or **Add parcel**, to match what is actually on the bench.
4. Optionally: a different **Label format** for this one label, **Extras** (signature, Saturday
   delivery and so on — only what this carrier can do), a **Pickup point id** (blank delivers to the
   address), or **Return label**.
5. **Buy label.**

Three buttons help before you spend anything:

- **Compare rates** prices every service the connection offers for these parcels, for carriers with
  live rates.
- **Check address** asks the carrier to validate the shipping address, for carriers that can (UPS,
  FedEx, USPS).
- **Show request** shows exactly what would be sent to the carrier, as JSON. Nothing is bought.

Once the order has a label, the form is headed **Ship more**: buying again from here deliberately
ships a second consignment.

## In bulk

Select orders on Commerce's order index and choose **Create shipping labels**. One queued job per
order buys its label with the order's own checkout method — its connection, its service and its
packing.

Orders that are not completed, or are not on a Carrier checkout method, are skipped and counted in
the message. Watch the queue for progress; results land on **Carrier → Shipments**, and anything the
carrier refused lands on **Problems**.

With `autoLabelOnComplete` on, the same job is queued for every order on a Carrier method the
moment it is completed. See [Configuration](/plugins/craft-carrier/docs/configuration#after-the-order).

From the console:

```sh
php craft carrier/labels/create 1000123                         # by reference or id
php craft carrier/labels/create 1000123 --connection=ups-live --service=03
php craft carrier/labels/create 1000123 --dry-run               # print the request, buy nothing
```

## Never bought twice

Every purchase is **claimed before the carrier is called**. A shipment row is written as *Buying
label* under a unique key, and only then does the request leave. The database's unique index is the
guarantee, not a check that two workers could both pass.

- **Bulk and automatic labels** are claimed per order and per connection. Running **Create shipping
  labels** twice on the same orders, a queue job retried after a worker died, and two workers picking
  up the same job all find the claim and stop. One label.
- **The order screen** claims per form. A double-click or a resubmitted form finds its own claim. A
  fresh form — **Ship more** — gets a fresh claim, because shipping a second consignment is a
  decision you are allowed to make.
- **The console** claims per order and connection, like bulk.

While a purchase is in flight, the only automatic retry is after an HTTP 429 (the carrier saying
"slow down", which proves it did nothing). A throttled bulk job puts itself back on the queue for
five minutes later.

## Shipment statuses

| Status | Meaning | What to do |
|---|---|---|
| **Buying label** | Claimed; the carrier is being asked | Wait. If it is still here after ten minutes, the process died mid-call and it moves to Problems |
| **Labelled** | The carrier answered with a tracking number and label | Print it |
| **Refused** | The carrier said no — a bad postcode, an overweight parcel, a missing phone number. **No label exists** | Fix the cause, then **Try again** |
| **Check with carrier** | No usable answer came back — a timeout, a 5xx, a dropped connection. **A label may exist** | Look in the carrier's portal, then settle it on Problems |
| **Voided** | Cancelled with the carrier, or marked voided by hand | Nothing |

The difference between **Refused** and **Check with carrier** is the whole point. A refusal is a
carrier saying in so many words that it created nothing, so buying again is safe. Silence is not.
A 5xx can arrive after the carrier created and charged for the label; buying again would buy two.

Many refusals are caught before a request is sent: no shipping address, no ship-from address, a
carrier that does not ship from your country or abroad, a parcel over the carrier's weight limit,
several parcels for a carrier that takes one per label, a pickup-point method with no point, or an
extra the carrier does not support. Each message says what to fix.

## Problems

**Carrier → Problems** lists every refused purchase, every uncertain one, and every purchase still
marked *Buying label* after ten minutes. Each row says what happened in the carrier's own words.

**Refused** rows have **Try again**. It reuses the same claim, so two people pressing it at once
still buy one label. Fix the cause first — the order's address, the parcel weight, the phone field —
or the carrier will refuse it identically.

**Check with carrier** and stuck rows are never retried automatically. Someone has to look at the
carrier's portal, search for the order reference, and then say what they found:

- **It was created** — type the tracking number you found. Carrier records the shipment as
  labelled, starts tracking it, and fetches the label from the carrier if the carrier can reprint.
- **It was not created** — the row becomes **Refused**, and **Try again** is available.

Some carriers can leave part of a shipment behind — USPS creating parcel one of three, or BOX NOW
creating the order but not its labels. Carrier keeps whatever ids the carrier did return and shows
them on the row as **Already created**, so you know what to void in the portal.

Settling problems needs the *Void labels and resolve problems* permission.

## Voiding

**Void label** on the shipment screen cancels the label with the carrier, then marks it voided and
stops tracking it. Carriers differ about when they allow it — GLS only before the parcel is scanned,
Magyar Posta only before the day is closed — and a refusal is shown as the carrier worded it.

For a carrier that cannot void through its API (ELTA, most LTL carriers), void it in the carrier's
portal and press **Mark voided**. That button is also for a label you voided by phone.

## Reprinting

Carrier stores every label it buys, so the stored copy is what you print, and printing works when
the carrier is down. **Fetch labels again** asks the carrier for a fresh copy and replaces the stored
one, for carriers that support it. DPD EasyShip only reprints for about two minutes after the first
print; after that, Carrier keeps the original.

## Printing

Each label on the shipment screen downloads on its own, or **Print all** for a shipment's labels.

For many orders at once, select them on the order index and choose **Print shipping labels**, or use
**Print selected labels** on **Carrier → Shipments**. You get one print job:

| Labels selected | What you get |
|---|---|
| One label | The document as it is |
| Several ZPL labels | One ZPL stream. Send it to a Zebra and it prints them in order |
| Several PNG labels | A browser print sheet: four to an A4 page, or one per 4×6 label, switchable at the top |
| Several PDFs from one connection whose carrier batch-prints | One merged document from the carrier — GLS and DPD give A4 sheets of four |
| Anything else | A ZIP of the documents, named by tracking number |

Carrier does not merge arbitrary PDFs itself; that needs a PDF library it does not ship. If you print
a lot of mixed PDFs, choose ZPL or one format across your connections.

Printing marks the shipments printed. Printing from the order index leaves return labels out, so
they are not stuck on outgoing parcels by mistake.

Freight carriers return a bill of lading with the labels. It is stored with the shipment and
downloads from the shipment screen; bulk printing includes only the labels.

## Returns

For carriers that support return labels (UPS, FedEx and USPS), switch on **Return label** on the
shipment screen. The parcels and addresses are the outbound ones; the carrier swaps sender and
recipient. Returns appear on the order's shipment screen marked as returns. They never move the order
to its delivered status, and `craft.carrier.shipments(order)` leaves them out unless you ask.

## Cash on delivery

List the payment gateways that mean "pay the courier" in **Settings → Checkout → Cash on delivery
gateways**. An order paid through one of them ships with cash on delivery for **its outstanding
balance**, in the order's currency, with the order reference as the COD reference.

Nothing else is needed per order. The amount shows on the shipment, and on the collection slip for
store pickups. COD is only sent to carriers that support it: DPD, GLS, BOX NOW, Hrvatska pošta,
Magyar Posta, ELTA and Pick up in store. Each carrier's page lists its own limits (currency, maximum
amount, how a multi-parcel amount is split).

The pickup-point picker leaves out points that do not take cash when the cart is on a COD gateway.

## A shipment made somewhere else

**Shipped some other way? Record its tracking number** at the bottom of the shipment screen is for a
label made in the carrier's portal, at a post office counter, or with a carrier whose API cannot
create labels (Pošta Slovenije). Choose the connection, type the tracking number exactly as printed,
and press **Record**. Carrier tracks it and writes it back to the order like any other shipment.
Recording the same number twice does nothing.

## Where labels are kept

Label files are stored in the database, not in an asset volume. They carry a customer's name,
address and phone number, which must never sit in a public volume, and Craft Cloud and
load-balanced hosts have no shared disk.

They are deleted after `labelRetentionDays` (default 120) by Craft's garbage collection. The
shipment record, its tracking numbers and its tracking history are kept. A label is also checked
when it arrives: if the bytes do not look like the format the carrier claimed — an HTML error page
sent as a "PDF" — the shipment says so, so you check it before printing.
