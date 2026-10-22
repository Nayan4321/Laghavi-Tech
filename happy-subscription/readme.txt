=== Happy Subscription ===
Contributors: nayan4321
Tags: subscriptions, recurring payments, stripe, license manager, woocommerce
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Subscriptions for WooCommerce with automatic Stripe renewals, reminders, a better My Account, and license keys for the plugins you sell.

== Description ==

Happy Subscription turns WooCommerce into a subscription shop, without a separate subscription plugin. Sell monthly or yearly plans, charge renewals automatically with Stripe, and if you sell plugins or software, hand out license keys, downloads and automatic updates from the same shop.

= Subscription products =

* Tick "Subscription" on any simple product or on each variation (for example Monthly / Yearly).
* Bill every N days, weeks, months or years, with an optional sign-up fee, free trial and number of payments (or until cancelled).
* Prices show as "$99.00 / month · 7-day free trial · $20.00 sign-up fee". The button says "Subscribe".
* One subscription per order; other products can be bought together with it.
* Guests are asked to create an account so they can manage their subscription.

= Payments =

* **Stripe** is added under WooCommerce > Settings > Payments. Customers pay on Stripe's secure checkout page (cards, Apple Pay, Google Pay, 3D Secure). Subscriptions become Stripe Billing subscriptions, so Stripe charges each renewal automatically and retries failed cards. Refunds work from the WooCommerce order screen.
* The Stripe webhook is created automatically when you save your secret key.
* **Any other payment method** (bank transfer, cheque, another gateway): on each renewal date the customer gets an invoice email with a "Pay now" link. Unpaid invoices put the subscription on hold after 7 days and cancel it after 30 (both adjustable).

= Reminders and emails =

* Upcoming renewal and trial ending reminders (3 days before, adjustable).
* Payment failed, with an "Update card" button.
* Renewal invoice with "Pay now", and an overdue notice.
* Cancellation confirmation, for the customer and for you.
* Every renewal is a normal WooCommerce order, so the usual order emails and receipts go out too.

= My Account =

* An overview dashboard: active subscriptions, next payment, orders and quick links.
* A Subscriptions tab with status, amount, next payment, payment history and buttons to Pay now, Update card, Cancel (at the end of the paid period) and Resume.

= Checkout without a cart page =

* "Buy now" and "Subscribe" go straight to checkout; links to the cart open the checkout.
* Shoppers change quantities or remove items right in the checkout's order summary.
* The checkout shows the sign-up fee and a "Recurring total … Next payment on …" line.

= License keys for the software you sell =

* Tick "Sell a license" in a product's License tab: every paid order gets a license key, shown on the thank-you page, in the order email and in My Account > Licenses.
* Upload your plugin's zip on the product. Customers download it from My Account, and licensed sites get automatic updates under Dashboard > Updates.
* Set how many sites a key works on and how long it gets updates. Customers move a key between sites themselves.
* Keys sold with a subscription follow it: renewals keep the same key, cancelling stops renewals, and the key keeps working until the paid period ends.
* WooCommerce > Licenses: search, revoke or restore keys, add a year, change sites, or create a key by hand.
* A small REST API for your plugin to activate, check and update (see "License API" below).

The free plugin sells licenses for simple products. To sell one product in several plans (for example 1 site, 5 sites and unlimited at different prices), use Happy Subscription Pro.

= Reviews and download counts =

* Only buyers can review: only logged-in customers who bought a product can review it.
* Each review shows when the reviewer bought the product, as month and year: "Purchased in March 2026".
* Tick "Show downloads" on a product to show "1,234 downloads" under its price, counted from paid orders. `[happysub_downloads]` shows it anywhere.

= Happy Subscription Pro =

[Happy Subscription Pro](https://laghavi.site/happy-subscription/) adds licenses for variable products (plans by number of sites), "Ask for price" products with contact page and WhatsApp buttons, and a currency switcher with exchange rates and per-country currencies.

== Installation ==

1. Install and activate WooCommerce, then Happy Subscription. Go to Settings > Permalinks and click Save once.
2. **Stripe:** in Stripe go to Developers > API keys and copy the secret key. In WooCommerce > Settings > Payments open "Stripe", tick Enable, paste the key and save. The webhook line turns green ("Connected"). Try test mode first with the card 4242 4242 4242 4242.
3. **Products:** edit a product and tick "Subscription" in the General tab (or on each variation), then set the billing period.
4. **Settings:** WooCommerce > Settings > Subscriptions for reminders, grace periods, checkout and reviews.
5. **Selling software:** in the product's License tab, tick "Sell a license", enter your plugin's folder name as Plugin slug, upload the zip and set the version.

== Frequently Asked Questions ==

= Do I need another subscription plugin? =

No. Happy Subscription handles the subscription products, renewals, emails and the customer's account pages itself.

= Which payment methods work? =

Stripe charges renewals automatically. Any other WooCommerce payment method works with invoice renewals: the customer gets a "Pay now" email on each renewal date.

= Can customers cancel? =

Yes, from My Account (you can turn this off). They keep access until the end of the period they paid for, and nothing is charged again.

= Does it work with the block checkout? =

Stripe and subscriptions work with the Checkout block. Editing quantities on the checkout page needs the classic checkout (the `[woocommerce_checkout]` shortcode).

= How does my plugin talk to the license API? =

Send `license_key`, `site_url` and `slug` (your plugin's folder name) to these routes under `/wp-json/happy-subscription/v1/`:

* `POST license/activate`, `POST license/deactivate`, `POST license/check`
* `GET license/update` returns the latest version, with a download link for sites allowed to update
* `GET license/download` returns the zip for sites allowed to update

Replies are JSON with `success`, `status` (active, expired, revoked, invalid or inactive), `expires` and a `message` for the customer.

= What happens if I delete the plugin? =

Subscription and license records are kept, because they are financial history. Existing Stripe subscriptions keep charging until they are cancelled in Stripe.

== External services ==

This plugin connects to **Stripe** (stripe.com) to take payments, but only when you enable the Stripe payment method and enter your Stripe secret key.

* When you save the key, the plugin creates a webhook endpoint in your Stripe account so Stripe can tell your shop about payments.
* When a customer pays with Stripe, the plugin sends Stripe the order number, item names, amounts and currency, the customer's email address, and your shop's address (to return to after paying). The customer then enters their card on Stripe's own checkout page.
* For subscriptions, renewals, cancellations, refunds and card updates, the plugin sends Stripe the IDs of the subscription, customer or payment involved.
* Stripe sends payment events back to your shop's webhook address.

Stripe's terms: https://stripe.com/legal/ssa and privacy policy: https://stripe.com/privacy

No other data leaves your site. The license API runs on your own site and is only called by the plugins you sell.

== Screenshots ==

1. A subscription product with its price, trial and sign-up fee.
2. The checkout with the recurring total.
3. My Account: subscriptions with payment history, Cancel and Update card.
4. My Account: license keys, downloads and sites.
5. WooCommerce > Subscriptions with monthly recurring revenue.
6. WooCommerce > Licenses.

== Changelog ==

= 1.0.0 =
* First release.
