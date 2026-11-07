=== Rinnovo Bundles & Variable Subscriptions Pro ===
Contributors: nayan4321
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Subscription bundles and variable subscriptions for Rinnovo Subscriptions & Licenses: bundle stock, packing lines, out-of-stock safeguards, flat or dynamic prices, a box builder, plan switching with proration, self-service swaps, one invoice and one renewal day.

== Description ==

For subscription boxes, "build your own" bundles and plans sold as variations (Basic / Premium, Monthly / Annual, sizes and flavours). Requires the free **Rinnovo Subscriptions & Licenses** plugin and WooCommerce.

= Bundles that keep stock right =

* Make a subscription product out of other products (Bundle tab): "2 × Coffee + 1 × Mug".
* Every order and every renewal adds the bundle's items as packing lines (no price), so their stock goes down and your team sees exactly what to pack.
* Mark items as critical: the bundle cannot be bought while one is out of stock.
* A few days before each renewal the plugin checks the stock of every box. You (and, if you like, the customer) get an email listing what's missing, so it can be restocked or swapped in time.

= Mix and match, with minimum and maximum items =

* Let customers pick their own items from a list, with a minimum and a maximum (for example 3 to 5). Each box size (variation) can have its own number of items.
* The picker appears on the product page, with + / − counters and a running count.

= Flat or dynamic pricing =

* Flat: one price for the box, whatever is inside.
* Dynamic: the price is the sum of the chosen items, with an optional bundle discount (for example 10% off). It follows the items on every renewal.

= Step-by-step box builder =

* `[bundle_builder product="123"]` on any page: 1. choose the box size, 2. pick the items, 3. choose how often (every month, every 2 weeks…). The total updates as customers go.

= Variable subscriptions and switching =

* Every variation can have its own billing period, sign-up fee, free trial and number of payments, all set in one "Subscription plans of the variations" table on the product.
* Shoppers see a "Compare plans" table.
* Customers upgrade or downgrade between variations from My Account > My plans, and see what it costs before confirming:
  * Card subscriptions (Stripe) pay the prorated difference at once with the saved card.
  * Invoice subscriptions pay the difference with a "Pay now" order, or get a credit on their next renewal when they move down.
* Same-price swaps (size M → L, vanilla → chocolate) change from the next renewal with no charge.
* Customers can change the items of their box until a cut-off before each renewal (for example 2 days).

= One invoice for everything =

* Subscriptions bought together, with the same billing period, become one subscription: one renewal, one invoice, one card charge.

= One renewal day =

* Renew every monthly bundle (or every monthly subscription) on the same day of the month, for example the 1st. The first payment covers only the days until then.

== Installation ==

1. Install and activate WooCommerce and the free Rinnovo Subscriptions & Licenses plugin.
2. Upload and activate Rinnovo Bundles & Variable Subscriptions Pro, and enter your key in WooCommerce > Bundles Pro license.
3. Open a subscription product, go to its Bundle tab and tick "Bundle". Settings are in WooCommerce > Bundles & Plans.

== External services ==

* **License server (laghavi.site):** sends your license key, site address, plugin name and version to activate, check and update. [Terms](https://laghavi.site/terms-and-conditions/)
* **Stripe (through Rinnovo Subscriptions & Licenses, only for card subscriptions):** the new price of a subscription is sent when a customer switches plan or changes the items of a dynamic-price box. [Terms](https://stripe.com/legal)

== Changelog ==

= 1.0.0 =
* First release.
