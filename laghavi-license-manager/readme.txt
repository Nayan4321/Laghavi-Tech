=== Laghavi License Manager ===
Contributors: nayan4321
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Sell your plugins with WooCommerce on laghavi.site: license keys, site activations and automatic updates.

== Description ==

Install this on your shop (laghavi.site), next to WooCommerce. It is a normal WordPress plugin: no separate server is needed. Your shop's own address is what the Pro plugin talks to for activations and updates.

* **License keys on purchase.** When an order for a licensed product is paid (Processing or Completed), one key is created per item bought. Keys appear on the thank-you page, in the order emails and in My Account.
* **Sites per license.** Set how many sites one key works on (0 = unlimited). Customers move a key by deactivating it in the plugin, or by removing a site in My Account > Licenses. A license is bought one at a time (no quantity box).
* **Plans by number of sites.** Make the product Variable with an attribute such as Sites (1 site / 5 sites / Unlimited sites), give each variation its own price, and set "License: sites per key" on each variation (0 = unlimited, empty = the License tab's number). The License tab (slug, zip, version) stays on the main product.
* **Updates for a period.** Set how long a key gets updates (365 days by default, 0 = lifetime). After that the plugin keeps working but updates stop until renewed.
* **Automatic updates.** Upload the plugin zip on the product. It is stored in a private folder and only sites with an active key can download it. Licensed sites see the new version under Dashboard > Updates like any other plugin.
* **Download in My Account.** As soon as the payment succeeds, My Account > Licenses shows the key (with a Copy button), a Download button for the plugin zip, install steps, and the sites it is used on. The thank-you page and the order email link there too. Downloads only work for the logged-in owner of an active key.
* **Works with Laghavi Subscriptions.** A key bought with a subscription follows it: renewals keep the same key (no new key each month), the key stays active while the subscription is paid, and customers can cancel right on the Licenses page. After cancelling, nothing is charged again and the key keeps working until the end of the paid period; then it expires (the plugin keeps working, updates stop). "Keep my subscription" undoes a cancellation.
* **Refunds.** Refunded or cancelled orders revoke their keys; the plugin switches its Pro features off at its next daily check.
* **Admin screen.** WooCommerce > Licenses: search by key, email or order, revoke or restore, add a year, change the number of sites, remove a site, or create a key by hand.
* **Brute-force protection.** More than 30 wrong keys from one IP in an hour are refused.

== Setting up Simple Login Page Pro for sale ==

1. Install and activate this plugin on laghavi.site (WooCommerce must be active). Visit Settings > Permalinks once and click Save so the My Account > Licenses page works.
2. Create a WooCommerce product "Simple Login Page Pro" (Simple product, Virtual + Downloadable not needed).
3. In the product's **License** tab: tick "Sell a license", set Plugin slug to `simple-login-page-pro`, choose Sites per license and Updates for (days), set Latest version to `1.0.0`, and upload `simple-login-page-pro.zip`. To sell it as a subscription, also tick "Subscription" in the General tab (Laghavi Subscriptions); "Updates for (days)" is then ignored, because the subscription decides.
4. Publish the product. Buy it once yourself with a 100% coupon to test the key, the email and the activation.

To release an update: build the new zip with a higher `Version:` in the plugin header, upload it on the product, and change "Latest version" to match. Licensed sites see it within 12 hours (or right away from Dashboard > Updates > Check again).

== API ==

Namespace `laghavi-licenses/v1`, all with `license_key`, `site_url` and `slug`:

* `POST /activate`, `POST /deactivate`, `POST /check`
* `GET /update` – latest version info, with a download link for sites allowed to update
* `GET /download` – the zip, for sites allowed to update
