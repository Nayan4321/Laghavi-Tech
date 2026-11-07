=== Rinnovo Subscriptions & Licenses Pro ===
Contributors: nayan4321
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Pro add-on for Rinnovo Subscriptions & Licenses: invoices and tax invoices, trial and first-payment offers, VAT / GST with reverse charge, subscriptions and licenses on variable products, "Ask for price" products and a currency switcher.

== Description ==

Requires the free **Rinnovo Subscriptions & Licenses** plugin and WooCommerce. Buy a license at [laghavi.site](https://laghavi.site/rinnovo-subscriptions-licenses/).

= Invoices and tax invoices =

* An invoice for every paid order, subscription renewals included, with numbers that follow on without gaps (for example INV-00001, prefix and start number of your choice).
* Three templates: Classic, Bold (with a "Paid" stamp) and Modern, in your colour, with your logo.
* Your legal company name, legal address and tax registration number (TRN, VAT No.…). With a number the title reads "Tax Invoice".
* Payment details from the order: payment method, date, transaction ID, and for subscriptions the billing period, automatic renewal and next payment date.
* Preview each template as a PDF before saving.
* Customers add their own legal company name, address and TRN in My Account > Account details or at checkout (classic and block checkout). Choose optional, required, or off (billing details are used).
* My Account > Invoices lists every invoice with View and Download PDF; order emails link to the invoice; an Invoice button on My Account > Orders.
* An Invoice box on each order: view, create, or refresh the details after the customer adds them. Each invoice keeps the details it was issued with.

= Trials and first-payment offers =

Turn on in WooCommerce > Settings > Subscriptions > Trials and first-payment offers.

* Free trials of any length (for example 7 or 14 days) with or without a card. With a card, the card is saved at sign-up and charged when the trial ends. Without a card, checkout asks for no payment at all and the customer gets a "Pay now" invoice on the day the trial ends.
* A discount on the first payment, as a percentage or a fixed amount ("first month half price"). Without a trial it comes off today's payment; with a trial it comes off the first payment after the trial (a one-time Stripe coupon for card subscriptions, a discount line on invoice renewals). Every later payment is the full price.
* "First payment: $5.00" and "No card needed for the trial" under the price, in the cart and at checkout.
* Store-wide setting for the card, which each product or variation can change.

= VAT / GST for digital goods =

Turn on in WooCommerce > Settings > Subscriptions > VAT / GST for digital goods.

* A "VAT / GST number" field for business customers on the classic and block checkout (shared with the invoice details).
* EU VAT numbers are checked with the European Commission's VIES service; UK, Swiss, Norwegian, Indian (GSTIN), Australian (ABN, with checksum), UAE and Saudi (TRN), Canadian, New Zealand and Singapore numbers are checked for the right format.
* Reverse charge: EU businesses in another EU country (or, if you choose, any business abroad with a valid number) pay no VAT. Totals update as soon as the number is typed, the checkout shows "Reverse charge (0%)", and the invoice carries your reverse charge note.
* Evidence of location for EU digital goods: the billing country and the IP address country are kept on each order, and orders where they differ are flagged.
* Every renewal keeps the first order's tax number and reverse charge.
* Works with WooCommerce Tax, TaxJar, Quaderno and other tax plugins: tax amounts still come from WooCommerce's tax calculation, renewals are priced the same way, and the number is stored under the order fields these plugins read (`_billing_vat_number`, `_vat_number`, `vat_number`).

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
* **EU VIES (ec.europa.eu):** only when VAT / GST numbers are turned on and a customer types an EU VAT number at checkout, the number and its country are sent to the European Commission's VIES service to check it. [Privacy](https://ec.europa.eu/taxation_customs/vies/#/privacy-statement)
* **Exchange rates (open.er-api.com):** only when you click "Update rates now" or turn on daily updates, the plugin asks open.er-api.com for exchange rates from your store currency. Nothing about you or your customers is sent. [Terms](https://www.exchangerate-api.com/terms)

== Changelog ==

= 1.2.0 =
* New: trials with or without a card, and a discount on the first payment.
* New: VAT / GST numbers with VIES checks, reverse charge, location evidence and the same treatment on renewals; works with WooCommerce tax plugins.

= 1.1.3 =
* Subscriptions and licenses on variations now come from the free plugin; Pro no longer adds a second copy of these fields.

= 1.1.2 =
* Invoices: payment details come from the order (payment method, date, transaction ID, and for subscriptions the billing period, automatic renewal and next payment date); the manual "Payment information" field is gone.
* Invoices: a Preview button for each template on WooCommerce > Invoices shows a sample PDF with the values on the page, before saving.
* Checkout: the legal details fields say "(for a tax invoice)" instead of "(optional)".

= 1.1.1 =
* Fix: My Account > Invoices showed "Page not found" after updating until permalinks were saved.
* Fix: "Choose image" for the invoice logo did nothing.

= 1.1.0 =
* New: invoices and tax invoices for every paid order, with three templates, your legal details and TRN, the customer's legal details, a My Account Invoices tab and email links.

= 1.0.2 =
* Subscriptions and licenses on variable products are handled by Pro itself: the free plugin has no variation code of its own.

= 1.0.0 =
* First release.
