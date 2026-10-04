---
title: FAQ
slug: faq
order: 98
summary: Licensing, the add-on model, which carriers, sandbox advice, and the questions that come up before a purchase.
---

# FAQ

## Do I have to pay for the carrier add-ons too?

No. Carrier is $149 with a $129 annual renewal, single edition. Every carrier add-on is free, for
every carrier on the list.

## Why is the gateway paid and the add-ons free?

Because the gateway is where the work is. An add-on translates one carrier's API. Everything that has
to be true of every carrier — packing, checkout pricing that never fails a sale, the claim that stops
a label being bought twice, label storage and bulk printing, tracking written back to the order, the
pickup-point picker, the log — is Carrier's, written once. Pricing add-ons separately would mean a
merchant shipping with four carriers paying four times for the same engine. Erpy works the same way.

## Which carriers are supported?

UPS, FedEx and USPS; six US LTL freight carriers (TForce Freight, Old Dominion, Estes, XPO, R+L, SAIA);
DPD EasyShip (Croatia, Slovenia); GLS through MyGLS (seven central and south-east European
countries); BOX NOW lockers; and four national posts — Hrvatska pošta, Pošta Slovenije, Magyar Posta
and ELTA Courier. Plus two built in: a Mock carrier for testing and Pick up in store. See
[Carriers](/plugins/craft-carrier/docs/carriers) for what each one can do.

## Some carriers are marked UNVERIFIED. Should I worry?

Read it as "test this first". The add-ons were written against each carrier's published API
documentation, without live accounts, and every detail that could not be confirmed from a primary
source is marked **UNVERIFIED** in the add-on's README and its carrier class rather than guessed
silently.

So: make a sandbox connection, run real orders through it — rates, labels, voids, tracking, pickup
points — and only then switch a checkout method to the production connection. Where a carrier has no
sandbox (DPD EasyShip, R+L), create one real parcel and void it straight away. If something does not
match, send me the entry from **Carrier → Log**.

## Can I try it without a carrier account?

Yes. The **Mock Carrier** ships with Carrier. It quotes, makes labels, lists pickup points, books
collections, closes the day and moves parcels through the tracking statuses on a timer. It can also
pretend to refuse, throttle, time out or be down, so you can see what your team will see on the
Problems screen. See [Installation](/plugins/craft-carrier/docs/installation).

## Can a label be bought twice?

Not by Carrier. Every purchase is claimed in the database, under a unique key, before the carrier is
called. A double-click, a retried queue job, two workers on the same bulk action — each finds the claim
and stops. A purchase that got no clear answer is never retried automatically; it waits on **Problems**
until someone checks the carrier's portal. The only way to buy a second label for an order is to ask
for one deliberately, with **Ship more**. See [Labels](/plugins/craft-carrier/docs/labels).

## Does it work on Craft Cloud?

Yes. Label files are stored in the database rather than on disk, because Cloud (and any load-balanced
host) has no shared filesystem — and because labels carry customers' addresses and must not sit in a
public volume. Tracking and point syncs run from the queue, triggered by ordinary page traffic, so
nothing depends on a cron you cannot set up.

## Does it work with multiple Commerce stores?

Yes. A checkout method belongs to one store or to all of them, and connections are shared, so two
stores can use the same GLS account with different prices, or different accounts entirely.

## Does it replace ShipStation, or Shipper?

It is a different approach. ShipStation (and [Shipper](https://justinholt.com/plugins/craft-shipper),
which connects Commerce to ShipStation) put a hosted service between you and the carriers: orders go
to ShipStation and labels are bought there. Carrier talks to the carriers directly from Commerce —
rates at checkout, labels from the order screen, tracking written back to the order — with no
monthly service in between.

Carrier exists because ShipStation and Sendcloud do not serve every market. If your carriers are
DPD EasyShip, MyGLS, BOX NOW or a national post, Carrier is the way to reach them from Commerce. If
you already run your warehouse in ShipStation, keep Shipper.

## Does it do cash on delivery?

Yes, for the carriers that support it: DPD, GLS, BOX NOW, Hrvatska pošta, Magyar Posta, ELTA and
Pick up in store. List the payment gateways that mean "pay the courier" in the settings; an order
paid through one ships with COD for its outstanding balance, automatically. The pickup-point picker
hides points that do not take cash. See [Labels](/plugins/craft-carrier/docs/labels#cash-on-delivery).

## Can customers collect from my shop?

Yes. **Pick up in store** is built in. It turns your Commerce inventory locations into pickup points
in the same picker as a GLS locker, and prints a collection slip — with the amount to take at the
counter for a cash-on-delivery order — instead of a label. See [Pickup points](/plugins/craft-carrier/docs/pickup-points).

## Why are connections in the database instead of project config?

Because project config deploys. A sandbox account arriving in production on the next deploy, or a
developer's local site buying labels on the production account, is the worst thing a shipping plugin
could do. You set connections and checkout methods up once per environment. Plugin settings hold no
credentials and deploy normally.

## My carrier is not on the list.

A carrier is one class, registered on `Carriers::EVENT_REGISTER_CARRIERS`. It declares what it can do,
implements exactly that, and passes the conformance suite that ships with Carrier. See
[Writing a carrier](/plugins/craft-carrier/docs/writing-a-carrier). If you would rather not write it, email me — the list grows
in the order people ask.

## Will it slow down checkout?

Flat and table prices are arithmetic. Live rates are one API call per cart, reused for
`rateCacheMinutes`, and a carrier that is slow or down costs one method its live price — replaced by
a fallback, or hidden — never the checkout. Pickup points for list carriers are searched in your own
database. Labels, tracking and syncs run in the queue.

## What are the requirements?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+ with the `zip` extension.

## How do I get support?

justin@justinholt.com.
