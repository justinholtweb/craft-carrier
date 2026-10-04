---
title: Collections
slug: collections
order: 70
summary: Booking a courier to collect your parcels, and closing the day for carriers that need a manifest.
---

# Collections

**Carrier → Collections** is for the two things that happen at the end of a packing day: asking a
courier to come and collect, and closing the day with carriers that want a manifest. It only shows
connections whose carrier can do one or the other through its API.

| Carrier | Book a collection | Close the day |
|---|---|---|
| UPS | yes — on-call pickup, the PRN is the reference | — |
| FedEx | yes — Ground or Express, a connection setting chooses | — |
| USPS | yes — carrier pickup | — |
| TForce Freight, Old Dominion | yes | — |
| DPD EasyShip | yes — at least 30 minutes' notice | yes — manifest PDF |
| Magyar Posta | — | **required** — returns the posting list PDF |

Many carriers do neither, because the collection is part of your contract: GLS and BOX NOW pick up on
a schedule, and the national posts collect or take drop-offs at the counter.

The screen also shows, per connection, how many labelled parcels are waiting — labels bought but not
yet scanned by the carrier.

## Booking a collection

Choose the connection, then:

| Field | |
|---|---|
| **Date** | Defaults to tomorrow |
| **Ready from** | When the parcels are ready, default 09:00 |
| **Closes at** | When the premises close, default 17:00 |
| **Note for the driver** | Which door, which bell |

**Book collection** sends the request with the parcel count, total weight and tracking numbers of
every labelled, not-yet-scanned parcel on that connection from the last seven days. The carrier's
confirmation number is shown and kept in the list under **Recent**.

The collection address is the connection's **Ship from** address, or, when that is blank, the
address the parcels were shipped from. Set a ship-from address on any connection you book
collections for; carriers are particular about collection addresses. See
[Connections](/plugins/craft-carrier/docs/connections#ship-from).

A refused booking is shown in the carrier's words and recorded in **Recent** as failed.

## Closing the day

**Close the day** sends every open labelled shipment on the connection from the last seven days to
the carrier as the day's consignment, and marks them closed so they are not sent again tomorrow.

If the carrier issues a manifest — a document the driver signs — it downloads straight away. When it issues more than one (DPD adds a separate cash-on-delivery manifest), they download together as a ZIP.

Closing is not optional for **Magyar Posta**: MPL dispatches nothing until the day's shipments are
closed, and once closed they can no longer be voided or reprinted. Close the day after the last
label, before the collection.

Once closed, a shipment is not included in the next close, but it is still tracked as normal.

## Permissions

Both need the *Request collections and close the day* permission.
