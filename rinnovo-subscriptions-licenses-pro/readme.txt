=== Rinnovo Subscriptions & Licenses Pro ===
Contributors: nayan4321
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later

Pro add-on for Rinnovo Subscriptions & Licenses: invoices and tax invoices, subscriptions and licenses on variable products, "Ask for price" products and a currency switcher.

== Description ==

Requires the free **Rinnovo Subscriptions & Licenses** plugin and WooCommerce. Buy a license at [laghavi.site](https://laghavi.site/rinnovo-subscriptions-licenses/).

= Invoices and tax invoices =

* An invoice for every paid order, subscription renewals included, with numbers that follow on without gaps (for example INV-00001, prefix and start number of your choice).
* Three templates: Classic, Bold (with a "Paid" stamp) and Modern, in your colour, with your logo.
* Your legal company name, legal address and tax registration number (TRN, VAT No.…). With a number the title reads "Tax Invoice".
* Customers add their own legal company name, address and TRN in My Account > Account details or at checkout (classic and block checkout). Choose optional, required, or off (billing details are used).
* My Account > Invoices lists every invoice with View and Download PDF; order emails link to the invoice; an Invoice button on My Account > Orders.
* An Invoice box on each order: view, create, or refresh the details after the customer adds them. Each invoice keeps the details it was issued with.

= Subscriptions on variable products =

* Each variation can be its own plan, for example Monthly and Yearly, with its own billing period, free trial, sign-up fee and number of payments.
* One subscription per order and no quantity box, as for simple subscription products.

= Licenses for variable products =

* Sell one product in several plans, for example 1 site, 5 sites and unlimited sites, each at its own price.
* The License tab (slug, zip, version) stays on the main product. Each variation gets "License: sites per key" (0 = unlimited).
* Works with subscriptions: each plan can be its own yearly or monthly subscription.

= Ask for price =

* Tick "Ask for price" on a product: the price becomes "Price on request" (also hidden from Google's product data), it can't be bought online, and it shows "Ask for price" (your contact or booking page) and/or "Chat on WhatsApp" with a ready-written message naming the product.

= Currency switcher =

* Your store currency stays the default; add others (AED, EUR, GBP…) with an exchange rate in WooCommerce > Settings > Subscriptions > Currencies.
* "Update rates now", or daily, fetches rates from the free open.er-api.com service. Or type your own.
* Decimals and price rounding per currency, and countries that get a currency automatically on their first visit.
* A blue round switcher in the corner of every page, "Pay in USD · AED · EUR" on checkout, and the `[rinnovo_currency_switcher]` shortcode.
* Prices, sign-up fees, fixed coupons and shipping convert. Orders, Stripe payments and every renewal stay in the currency the customer chose.

== Installation ==

1. Install and activate WooCommerce and the free Rinnovo Subscriptions & Licenses plugin.
2. Upload and activate Rinnovo Subscriptions & Licenses Pro.
3. Go to WooCommerce > Rinnovo Subscriptions & Licenses Pro, paste your license key and click Activate.

== External services ==

* **License server (laghavi.site):** to activate your key, check it daily and look for updates, this plugin sends your license key, your site's address, the plugin's name and version to https://laghavi.site/. [Terms](https://laghavi.site/terms-and-conditions/)
* **Exchange rates (open.er-api.com):** only when you click "Update rates now" or turn on daily updates, the plugin asks open.er-api.com for exchange rates from your store currency. Nothing about you or your customers is sent. [Terms](https://www.exchangerate-api.com/terms)

== Changelog ==

= 1.1.1 =
* Fix: My Account > Invoices showed "Page not found" after updating until permalinks were saved.
* Fix: "Choose image" for the invoice logo did nothing.

= 1.1.0 =
* New: invoices and tax invoices for every paid order, with three templates, your legal details and TRN, the customer's legal details, a My Account Invoices tab and email links.

= 1.0.2 =
* Subscriptions and licenses on variable products are handled by Pro itself: the free plugin has no variation code of its own.

= 1.0.0 =
* First release.
