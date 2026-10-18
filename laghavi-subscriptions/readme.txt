=== Laghavi Subscriptions for WooCommerce ===
Contributors: laghavidigital
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

Subscription products with automatic Stripe renewals, reminder emails, a better My Account, checkout without a cart page, and "Ask for price" products. No other subscription plugin needed.

== Description ==

**Subscription products**

* Tick "Subscription" on any simple product or on each variation (for example Monthly / Yearly).
* Bill every N days, weeks, months or years, with an optional sign-up fee, free trial and number of payments (or until cancelled).
* Prices show as "AED 99.00 / month · 7-day free trial · AED 20.00 sign-up fee". The button says "Subscribe".
* One subscription per order (adding another replaces it); other products can be bought together with it.
* Guests are asked to create an account so they can manage their subscription.

**Payments: WooCommerce > Settings > Payments**

* **Stripe** is added as a payment method there. Customers pay on Stripe's secure checkout page (cards, Apple Pay, Google Pay, 3D Secure). Subscriptions become Stripe Billing subscriptions, so Stripe charges each renewal automatically and retries failed cards. Refunds work from the WooCommerce order screen.
* The Stripe webhook is created automatically when you save your secret key.
* **Any other payment method** (bank transfer, cheque, a future gateway): on each renewal date the customer gets an invoice email with a "Pay now" link and can pay with any method you have enabled. Unpaid invoices put the subscription on hold after 7 days and cancel it after 30 (both adjustable).

**Reminders and emails** (in your WooCommerce email design)

* Upcoming renewal / trial ending reminder (3 days before, adjustable).
* Payment failed, with an "Update card" button (customer) and a note to you.
* Renewal invoice with "Pay now" (non-Stripe methods) and an overdue notice.
* Cancellation confirmation (customer and you).
* Every renewal is a normal WooCommerce order, so customers also get the usual order emails/receipts.

**My Account**

* New overview dashboard: active subscriptions, next payment, order count, recent orders and quick links.
* Subscriptions tab: status, amount, next payment, trial end, payments made, payment history, and buttons to Pay now, Update card, Cancel (at the end of the paid period) and Resume.

**Checkout without a cart page**

* "Buy now" / "Subscribe" goes straight to checkout; any link to the cart opens the checkout.
* Shoppers change quantities (− / +) or remove items right in the checkout's order summary; coupons work there as usual.
* The checkout shows the sign-up fee and a "Recurring total … Next payment on …" line.

**Ask for price**

* Tick "Ask for price" on a product: the price becomes "Price on request" (also hidden from Google's product data), it cannot be bought online, and it shows "Ask for price" (your contact or booking page) and/or "Chat on WhatsApp" with a ready-written message naming the product.

**Admin**

* WooCommerce > Subscriptions: counts, monthly recurring revenue, search and filter, and each subscription's details, orders and actions (cancel now / at period end, resume, send renewal invoice now, open in Stripe).
* WooCommerce > Settings > Subscriptions: all options above.

== Setup ==

1. Upload and activate the plugin (WooCommerce must be active). Go to Settings > Permalinks and click Save once.
2. **Stripe:** in Stripe go to Developers > API keys and copy the Secret key. In WooCommerce > Settings > Payments open "Stripe", tick Enable, paste the key and Save. The webhook line should turn green ("Connected"). Use Test mode with a test key first (card 4242 4242 4242 4242).
3. **Products:** edit a product, tick "Subscription" in the General tab (or on each variation) and set the billing period. For dashboards you sell after a meeting, tick "Ask for price".
4. **Settings:** WooCommerce > Settings > Subscriptions: WhatsApp number (e.g. 971501234567), contact or booking page, reminder days, cart options.
5. **Checkout page:** the editable order summary works on the classic checkout. If your Checkout page uses the Checkout block, Stripe and subscriptions still work, but to edit quantities on checkout replace the block with the shortcode `[woocommerce_checkout]`.
6. Do a test purchase in Stripe test mode, then switch Test mode off.

== Notes ==

* Prices are charged in your store currency (AED by default). Stripe accepts cards from the UAE, UK, US, Germany and most other countries. Cards issued in Russia generally cannot be charged through Stripe; offer those customers bank transfer, which uses invoice renewals.
* Changing a product's price later does not change existing Stripe subscriptions (they keep the price they started with). New subscribers get the new price.
* Coupons apply to the first payment only.
* Deleting the plugin keeps subscription records (financial history). Existing Stripe subscriptions keep charging until cancelled in Stripe.

== Changelog ==

= 1.0.0 =
* First release.
