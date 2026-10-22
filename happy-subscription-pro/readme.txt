=== Happy Subscription Pro ===
Contributors: nayan4321
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Pro add-on for Happy Subscription: licenses for variable products, "Ask for price" products and a currency switcher.

== Description ==

Requires the free **Happy Subscription** plugin and WooCommerce. Buy a license at [laghavi.site](https://laghavi.site/happy-subscription/).

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
* A blue round switcher in the corner of every page, "Pay in USD · AED · EUR" on checkout, and the `[happysub_currency_switcher]` shortcode.
* Prices, sign-up fees, fixed coupons and shipping convert. Orders, Stripe payments and every renewal stay in the currency the customer chose.

== Installation ==

1. Install and activate WooCommerce and the free Happy Subscription plugin.
2. Upload and activate Happy Subscription Pro.
3. Go to WooCommerce > Happy Subscription Pro, paste your license key and click Activate.

== External services ==

* **License server (laghavi.site):** to activate your key, check it daily and look for updates, this plugin sends your license key, your site's address, the plugin's name and version to https://laghavi.site/. [Terms](https://laghavi.site/terms-and-conditions/)
* **Exchange rates (open.er-api.com):** only when you click "Update rates now" or turn on daily updates, the plugin asks open.er-api.com for exchange rates from your store currency. Nothing about you or your customers is sent. [Terms](https://www.exchangerate-api.com/terms)

== Changelog ==

= 1.0.0 =
* First release.
