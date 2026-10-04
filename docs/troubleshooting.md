---
title: Troubleshooting
slug: troubleshooting
order: 95
summary: Where to look first, and the failures that turn out to have ordinary causes.
---

# Troubleshooting

## Start here

1. **Is it Carrier or the add-on?** Make a Mock Carrier connection and run the same flow. The Mock
   never leaves the server. If it works there, the problem is the add-on, the credentials or the
   carrier account — not the engine.
2. **Test connection.** It makes a real authenticated request and shows the carrier's own refusal
   when there is one. `php craft carrier/connections/test <handle>` does the same.
3. **Carrier → Log.** Every request and response, with secrets removed. Filter to errors.
4. **Carrier → Problems.** Every refused or uncertain label, with the carrier's reason.
5. **Show request** on the order's shipment screen, or `php craft carrier/labels/create <order>
   --dry-run`, shows exactly what Carrier would send.

## A checkout method does not appear at checkout

A method that cannot be offered is left out rather than shown with an error. In order:

- Is the method **enabled**, and its connection **enabled**? Is the connection's add-on installed?
- On a multi-store site, is the method set to this store or to all stores?
- Does the cart pass the method's conditions — country, weight, subtotal?
- Is there anything to ship? Non-shippable lines and lines with free shipping do not count, so a
  cart of downloads has nothing for Carrier to price.
- **Weight table:** is the cart heavier than the last row? Then the method is not offered.
- **Live rate:** is there a shipping address yet? Did the carrier answer? With no **Fallback price**,
  a carrier that fails hides the method. Craft's log (`storage/logs`, category `carrier`) and
  **Carrier → Log** say why.

## Live rates are wrong, or zero

- **Weights.** Products with no weight send no weight. Set weights, or **Weight for items with
  none** in the settings. Check Commerce's weight and dimension units match what you typed into
  products.
- **Boxes.** Box dimensions and weights are in the store's units. A "max weight" of 20 in a store set
  to grams is 20 g.
- **Markup.** The percentage is applied first, then the fixed amount, then **Round up to**.
- **Cache.** Live rates are reused for `rateCacheMinutes` for an unchanged cart. A rate you changed
  in the carrier's portal shows after that.
- **Account rates.** UPS and FedEx return your negotiated rates where the account has them. A sandbox
  account usually has list rates only.

## "Refused" on every label

Read the message — it is the carrier's, and it usually names the field. The common ones:

- **No phone number.** Commerce addresses have none. Add a phone field to the address layout, collect
  it at checkout, and set **Phone field handle** in the settings. GLS refuses locker and parcel-shop
  parcels without a phone and an email.
- **No house number.** European carriers want it separately. Carrier splits it off the street line;
  DPD refuses an address with no number at all. If your form has a house-number field, set **House
  number field handle**.
- **No ship-from address.** Set the store's location in Commerce, or a ship-from address on the
  connection.
- **Service not available** for this destination or weight. **Compare rates** on the shipment screen
  shows what the carrier will offer.
- **Wrong environment.** Sandbox credentials on a production connection, or the other way round.

Fix the cause and press **Try again** on Problems.

## "Check with carrier" after a label purchase

The carrier did not give a usable answer — a timeout, a 5xx, a dropped connection — so a label may or
may not exist. Carrier will not buy it again on its own. Log in to the carrier's portal, search for
the order reference, then on **Carrier → Problems**:

- found it: type its tracking number and press **It was created**;
- not there: press **It was not created**, then **Try again**.

If this happens often with one carrier, check **Carrier → Log** for slow responses. A label purchase
that takes longer than the PHP or queue timeout is cut off mid-call — raise the timeout for queue
workers. GLS labels in particular arrive as very large JSON; raise `memory_limit` for queue workers if
GLS purchases fail with memory errors.

## A label is stuck on "Buying label"

The process died mid-purchase — a deploy, an out-of-memory error, a restarted container. After ten
minutes the shipment moves to Problems, to be settled like an uncertain one. It is never retried
automatically, because the carrier may have received the request.

## "Create shipping labels" did nothing

- The action queues jobs. Is the queue running? This is the most common cause by a wide margin.
- Were the orders completed, and on a Carrier checkout method? Others are skipped, and the message
  says how many.
- Did the orders already have a label from this connection? Bulk labelling is claimed per order and
  connection, so a second run buys nothing. To ship a second consignment, use **Ship more** on the
  order's shipment screen.

## Printing

- **A ZIP when you expected one PDF.** Carrier only merges PDFs when the carrier batch-prints (GLS,
  DPD) and every selected label is from one connection. Mixed carriers or formats come as a ZIP.
  Choose ZPL, or one PDF format across your connections.
- **A UPS label prints sideways.** UPS draws its PNG labels landscape. Rotate in the print dialog,
  or use ZPL.
- **"Nothing to print."** Only labelled shipments print. Label files older than `labelRetentionDays`
  are deleted; **Fetch labels again** gets a fresh copy from carriers that reprint.
- **"The carrier returned a label that does not look like one."** The bytes did not match the format
  the carrier claimed — usually an error page. Open it before printing, and check the log.

## The pickup-point picker

- **Nothing renders.** The picker only renders when the cart is on a method with **Delivers to a
  pickup point** on. Make sure it is on the page after the shipping method has been saved to the
  cart.
- **"No pickup points found nearby."** For a list carrier, check **Carrier → Pickup points** for the
  connection — has it synced? Is the queue running? Do the **Pickup point countries** include the
  customer's? For DPD EasyShip, import a CSV. The picker also hides points too small for the cart's
  weight and, on a cash-on-delivery order, points that do not take cash.
- **A search carrier (UPS, FedEx, USPS) only shows imported points.** Once a connection has any
  stored points that are offered, Carrier searches those instead of asking the carrier. **Hide** them
  on **Carrier → Pickup points** to go back to live search.
- **"That pickup point is not available."** The id was not one Carrier knows: a point from another
  connection, a hidden one, or — for search carriers — one not shown by a search in this session.
- **The customer cannot pay.** That is the payment guard: they are on a pickup-point method with no
  point chosen. Show the picker, or switch off `requirePointOnPayment`.
- **The search returns a 400.** The endpoint requires `Accept: application/json`.

## Tracking is not moving

- **Is the queue running?** Page views queue tracking jobs; nothing runs without a worker. Or run
  `php craft carrier/tracking/refresh` from cron.
- **Is `trackingEnabled` on?**
- **Is the shipment older than `trackingMaxDays`?** Polling stops then.
- `php craft carrier/tracking/show <number>` polls now and prints what the carrier said.
- For store pickups there is nothing to track.

## The order did not move to "delivered"

It moves only when **every** labelled, non-return shipment on the order is delivered. One parcel of
three still in transit holds it back. Check `orderStatusOnDelivered` is set, and that the status
handle exists in this order's store.

## An error message contains something that looks like my API key

It should not. The transport removes secrets from error responses, and the connection test redacts
what it shows. Success responses are left alone, because add-ons have to parse them, so a credential
echoed inside a **200** response can still reach the log if `logBodies` is on. Turn it off and tell
me which carrier so the add-on can strip it.

## The add-on behaves differently from the carrier's documentation

Possible. The add-ons were written against published API documentation without live accounts.
Anything that could not be verified is marked **UNVERIFIED** in the add-on's README and in its carrier
class's docblock — look there first. Then send me the request and response from **Carrier → Log**
(secrets are already removed).

## Getting help

justin@justinholt.com. Useful things to include: the carrier and add-on version, whether the
connection is sandbox or production, the relevant entry from **Carrier → Log**, and whether the same
flow works against the Mock carrier.
