=== Rinnovo Subscriptions & Licenses ===
Contributors: nayan4321
Tags: subscriptions, recurring payments, stripe, license manager, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Subscriptions for WooCommerce with any payment method, automatic Stripe renewals, reminders, and license keys for the plugins you sell.

== Description ==

Rinnovo Subscriptions & Licenses turns WooCommerce into a subscription shop, without a separate subscription plugin. Sell monthly or yearly plans, charge renewals automatically with Stripe, and if you sell plugins or software, hand out license keys, downloads and automatic updates from the same shop.

= Subscription products =

* Tick "Subscription" on a simple product, or on each variation of a variable product.
* Variable products: every variation is its own plan, for example Monthly and Yearly, each with its own price, period, trial, sign-up fee and number of payments. Customers pick their plan on one product page.
* Bill every N days, weeks, months or years, with an optional sign-up fee, free trial and number of payments (or until cancelled).
* Prices show as "$99.00 / month · 7-day free trial · $20.00 sign-up fee". The button says "Subscribe".
* One subscription per order; other products can be bought together with it.
* Guests are asked to create an account so they can manage their subscription.

= Payments =

* **Stripe** is added under WooCommerce > Settings > Payments. Customers pay on Stripe's secure checkout page (cards, Apple Pay, Google Pay, 3D Secure). Subscriptions become Stripe Billing subscriptions, so Stripe charges each renewal automatically and retries failed cards. Refunds work from the WooCommerce order screen.
* The Stripe webhook is created automatically when you save your secret key.
* **Your own payment plugin, charged automatically:** with WooCommerce Stripe Gateway, WooPayments, PayPal Payments, Square, Braintree, Authorize.Net, Mollie, Checkout.com, GoCardless or Paystack, the card (or bank mandate) is saved at the first payment and charged automatically on each renewal date, through that payment plugin. No other subscription plugin is needed. A failed charge is tried again after 1 and 3 days, and the customer gets a "Pay now" link.
* **Any other payment method** (bank transfer, cheque, a gateway that doesn't save cards): on each renewal date the customer gets an invoice email with a "Pay now" link. Unpaid invoices put the subscription on hold after 7 days and cancel it after 30 (both adjustable).

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
* Sell one plugin as several plans on a variable product (1 site, 5 sites, unlimited), each variation with its own price and number of sites. Combine with subscription variations for yearly or monthly plans.
* Keys sold with a subscription follow it: renewals keep the same key, cancelling stops renewals, and the key keeps working until the paid period ends.
* WooCommerce > Licenses: search, revoke or restore keys, add a year, change sites, or create a key by hand.
* A small REST API for your plugin to activate, check and update (see "License API" below).


= Reviews and download counts =

* Only buyers can review: only logged-in customers who bought a product can review it.
* Each review shows when the reviewer bought the product, as month and year: "Purchased in March 2026".
* Tick "Show downloads" on a product to show "1,234 downloads" under its price, counted from paid orders. `[rinnovo_downloads]` shows it anywhere.

== Installation ==

1. Install and activate WooCommerce, then Rinnovo Subscriptions & Licenses. Go to Settings > Permalinks and click Save once.
2. **Stripe:** in Stripe go to Developers > API keys and copy the secret key. In WooCommerce > Settings > Payments open "Stripe", tick Enable, paste the key and save. The webhook line turns green ("Connected"). Try test mode first with the card 4242 4242 4242 4242.
3. **Products:** edit a simple product and tick "Subscription" in the General tab, then set the billing period. On a variable product, open each variation and tick "Subscription" there.
4. **Settings:** WooCommerce > Settings > Subscriptions for reminders, grace periods, checkout and reviews.
5. **Selling software:** in the product's License tab, tick "Sell a license", enter your plugin's folder name as Plugin slug, upload the zip and set the version.

== Frequently Asked Questions ==

= Does Rinnovo Subscriptions & Licenses need a license key itself? =

No. Every feature of this plugin works without any key, time limit or usage limit. The license keys are for the plugins or software you sell in your own shop: your shop creates them for your customers, and your own products check them against your site.

= Do I need another subscription plugin? =

No. Rinnovo Subscriptions & Licenses handles the subscription products, renewals, emails and the customer's account pages itself.

= Which payment methods work? =

All of them. Renewals are charged automatically with Rinnovo's own Stripe, and with these payment plugins when they save cards: WooCommerce Stripe Gateway, WooPayments, PayPal Payments, Square, Braintree, Authorize.Net (official WooCommerce extension), Mollie, Checkout.com, GoCardless and Paystack. WooCommerce > Settings > Subscriptions lists each payment method on your shop and how it renews. Every other payment method works with invoice renewals: the customer gets a "Pay now" email on each renewal date.

= Where do customers find their license key after a renewal? =

In My Account > Licenses, with Copy and Download buttons. Renewals keep the same key and extend it, so nothing changes on their sites. You can revoke, restore, extend or reissue a key (a new key, emailed to the customer) in WooCommerce > Licenses.

= Does cancelling stop the payments? =

Yes. A cancellation in My Account or in WooCommerce > Subscriptions also cancels the subscription in Stripe, at the end of the paid period or right away. With other payment plugins nothing is charged after a cancellation, because Rinnovo creates every renewal charge itself.

= Can customers cancel? =

Yes, from My Account (you can turn this off). They keep access until the end of the period they paid for, and nothing is charged again.

= Does it work with the block checkout? =

Stripe and subscriptions work with the Checkout block. Editing quantities on the checkout page needs the classic checkout (the `[woocommerce_checkout]` shortcode).

= How does my plugin talk to the license API? =

Send `license_key`, `site_url` and `slug` (your plugin's folder name) to these routes under `/wp-json/rinnovo-subscriptions-licenses/v1/`:

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

**Mollie** (mollie.com) and **Paystack** (paystack.com), only when their own WooCommerce payment plugins are installed and a subscription was bought with them: on each renewal date the plugin asks Mollie or Paystack to charge the customer's saved payment method, with the order number, amount, currency and the customer's Mollie customer ID or Paystack card reference and email address, using the API key saved in that payment plugin. Mollie then calls your shop's address (/wp-json/rinnovo/v1/mollie) with the payment ID, and the payment status is read back from Mollie.
Mollie's terms: https://www.mollie.com/legal and privacy policy: https://www.mollie.com/privacy
Paystack's terms: https://paystack.com/terms and privacy policy: https://paystack.com/privacy

Renewals with the other supported payment plugins are charged by those plugins themselves, under their own terms.

No other data leaves your site. The license API runs on your own site and is only called by the plugins you sell.

== Rinnovo Pro ==

Separate Pro plugins add invoices and tax invoices, free trials and first-payment offers, VAT / GST for digital goods, "Ask for price" products and a currency switcher, plus plugins for subscription boxes, memberships, media, service contracts, sessions and bundles. Everything in this plugin works without them. See https://laghavi.site/rinnovo-subscriptions-licenses/

== Screenshots ==

1. A subscription product with its price, trial and sign-up fee.
2. The checkout with the recurring total.
3. My Account: subscriptions with payment history, Cancel and Update card.
4. My Account: license keys, downloads and sites.
5. WooCommerce > Subscriptions with monthly recurring revenue.
6. WooCommerce > Licenses.

== Changelog ==

= 1.1.0 =
* Automatic renewals with WooCommerce Stripe Gateway, WooPayments, PayPal Payments, Square, Braintree, Authorize.Net, Mollie, Checkout.com, GoCardless and Paystack: the card or mandate is saved at the first payment and charged on each renewal date, with retries after 1 and 3 days. Every other payment method keeps invoice renewals.
* Settings list each payment method on the shop and how it renews.
* Licenses: "Reissue key" gives a license a new key and emails it to the customer.
* The hourly renewal run can no longer run twice at the same time.
* A "Rinnovo Pro" screen under WooCommerce.

= 1.0.0 =
* First release.
