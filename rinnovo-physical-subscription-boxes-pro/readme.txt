=== Rinnovo Physical Subscription Boxes & Goods Pro ===
Contributors: nayan4321
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Subscription boxes and physical goods for Rinnovo Subscriptions & Licenses: skip, swap, frequencies, one shipping day with an order cut-off, Subscribe & Save, prepaid and gift plans, shipping on renewals, bundles, packing list and box emails.

== Description ==

Requires the free **Rinnovo Subscriptions & Licenses** plugin and WooCommerce. Works next to the other Rinnovo add-ons. Buy a license at [laghavi.site](https://laghavi.site/rinnovo-physical-subscription-boxes-pro/).

= Billing frequencies =

* Bill daily, weekly, monthly or yearly, or any custom frequency (every 2, 4 or 6 weeks, every 3 months…).
* Offer several frequencies on one product: customers pick one on the product page and can switch later.

= First box offers =

* A discount on the first box (percentage or fixed), a free trial, and a setup or packaging fee with your own name for it ("Packaging fee").

= Skip, switch and change, without contacting you =

In My Account > Subscriptions, under "Your boxes":

* **Skip the next box** (holiday, too much left over). Nothing is charged for it; you choose how many boxes can be skipped in a row. Undo any time before the date.
* **Switch frequency**, for example from every 4 weeks to every 6 weeks.
* **Change quantities** of each item.
* **Swap products** in the next box (another coffee roast, another shirt size): choose the products, or let subscribers switch between the variations of a variable product.
* **One shipping address for all their boxes**, and **one new card for all their subscriptions** (Stripe).
* Changes to the contents close at the order cut-off, so a box being packed doesn't change.

= One shipping day for everyone =

* Bill every subscriber on the same day of the month (and weekly boxes on the same weekday), so boxes are packed and shipped in one batch.
* Set how many days after billing boxes ship.
* **Order cut-off:** sign-ups after the cut-off get their first box in the next cycle; the checkout tells them when their first box ships.

= Shipping on renewals =

* Renewal shipping is recalculated with your WooCommerce shipping zones and methods: flat rates, table rates, and live carrier rates (USPS, UPS, DHL, FedEx… from carrier plugins). Or keep the first order's shipping, or ship renewals free.
* Card subscriptions include shipping in their recurring amount; it is updated in Stripe when the box or address changes.

= Prepaid and gift plans =

* Pay once for 3, 6 or 12 boxes (your choice, with an optional discount). Each later box becomes its own WooCommerce order on its delivery date, ready to pack and ship like any other order.
* Shipping for every box is charged up front (or only once, if you prefer).
* A gift message, printed on the packing list; the recipient is the shipping address.
* An email with the last box invites the customer to order again.

= Subscribe & Save =

* On any normal product: "One-time purchase" or "Subscribe & save 10%", with a frequency picker. The shop list shows "or subscribe & save 10%".

= Build-a-box and bundles =

* Works with WooCommerce Product Bundles, YITH Product Bundles, WPC Product Bundles, Mix and Match and Composite Products: the items the customer chose become the contents of their box, are re-sent on every renewal and can be swapped.

= Emails =

WooCommerce emails (WooCommerce > Settings > Emails), each with its own subject, heading and text:

* **Your next box:** before each renewal, with the contents, date, amount and a button to skip or swap. Replaces the renewal reminder for box subscriptions.
* **Your box has shipped:** with the tracking number and link, when you complete an order with tracking (or click "Email the tracking" on the order). Reads WooCommerce Shipment Tracking and Advanced Shipment Tracking too.
* **Your card expires soon:** for card subscriptions, with a button to update the card.
* **Your prepaid plan has ended.**

= For you =

* **WooCommerce > Boxes:** orders to pack by ship date with the total of each item (40 × Dark roast · 25 × Light roast), a CSV per ship date for your shipping run or label printer, coming renewals for the next 45 days (for buying stock), and all box subscriptions.
* A "Ships" column in the orders list, and ship date and tracking on each order.

== Installation ==

1. Install and activate WooCommerce and the free Rinnovo Subscriptions & Licenses plugin.
2. Upload and activate Rinnovo Physical Subscription Boxes & Goods Pro.
3. Go to WooCommerce > Boxes Pro license, paste your license key and click Activate.
4. Settings are in WooCommerce > Settings > Subscriptions; each product has a "Subscription box" tab.

== External services ==

* **License server (laghavi.site):** to activate your key, check it daily and look for updates, this plugin sends your license key, your site's address, the plugin's name and version to https://laghavi.site/. [Terms](https://laghavi.site/terms-and-conditions/)
* **Stripe:** for card subscriptions, changes to a box (amount, frequency, skipped box, billing day, new card) are sent to Stripe through Rinnovo Subscriptions & Licenses' Stripe connection.

== Changelog ==

= 1.0.0 =
* First release.
