=== Laghavi License Manager ===
Contributors: laghavidigital
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Sell your plugins with WooCommerce on laghavi.com: license keys, site activations and automatic updates.

== Description ==

Install this on laghavi.com (the shop), not on client sites.

* **License keys on purchase.** When an order for a licensed product is paid (Processing or Completed), one key is created per item bought. Keys appear on the thank-you page, in the order emails and in My Account.
* **Sites per license.** Set how many sites one key works on (0 = unlimited). Customers move a key by deactivating it in the plugin, or by removing a site in My Account > Licenses.
* **Updates for a period.** Set how long a key gets updates (365 days by default, 0 = lifetime). After that the plugin keeps working but updates stop until renewed.
* **Automatic updates.** Upload the plugin zip on the product. It is stored in a private folder and only sites with an active key can download it. Licensed sites see the new version under Dashboard > Updates like any other plugin.
* **Refunds.** Refunded or cancelled orders revoke their keys; the plugin switches its Pro features off at its next daily check.
* **Admin screen.** WooCommerce > Licenses: search by key, email or order, revoke or restore, add a year, change the number of sites, remove a site, or create a key by hand.
* **Brute-force protection.** More than 30 wrong keys from one IP in an hour are refused.

== Setting up Simple Login Page Pro for sale ==

1. Install and activate this plugin on laghavi.com (WooCommerce must be active). Visit Settings > Permalinks once and click Save so the My Account > Licenses page works.
2. Create a WooCommerce product "Simple Login Page Pro" (Simple product, Virtual + Downloadable not needed).
3. In the product's **License** tab: tick "Sell a license", set Plugin slug to `simple-login-page-pro`, choose Sites per license and Updates for (days), set Latest version to `1.0.0`, and upload `simple-login-page-pro.zip`.
4. Publish the product. Buy it once yourself with a 100% coupon to test the key, the email and the activation.

To release an update: build the new zip with a higher `Version:` in the plugin header, upload it on the product, and change "Latest version" to match. Licensed sites see it within 12 hours (or right away from Dashboard > Updates > Check again).

== API ==

Namespace `laghavi-licenses/v1`, all with `license_key`, `site_url` and `slug`:

* `POST /activate`, `POST /deactivate`, `POST /check`
* `GET /update` – latest version info, with a download link for sites allowed to update
* `GET /download` – the zip, for sites allowed to update
