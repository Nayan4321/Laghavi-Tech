=== Transara Pro ===
Contributors: laghavidigital
Tags: uptime, monitoring, firewall, webp, status page
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pro add-on for Transara: checks from outside your server, SMS, WhatsApp and Telegram alerts, hosted status pages, a firewall, WebP images and WooCommerce checks.

== Description ==

Transara Pro needs the free Transara plugin. Enter your license key under Transara › Pro license, then open Transara › Pro and click "Connect to the Transara service". No other account is needed.

= Checks from outside your server =

* Your Transara monitors are also checked by the Transara service, from servers in other regions, so you are alerted even when your whole server is down.
* Extra checks only the service runs: other websites and APIs, a value in an API answer (JSON), slow pages, DNS records and domain name expiry.
* Results per region, uptime for 24 hours, 7 and 30 days, response times and incidents in Transara › Pro.

= Alerts =

* Email, SMS, WhatsApp, Telegram, Slack, Discord, Microsoft Teams and any webhook.
* "Also tell me when it works again".
* Escalation: when nobody acknowledges an incident within the time you set, other people are alerted.
* Acknowledge an incident ("I'm on it") from WordPress or from the link in the alert.
* Quiet hours for SMS and WhatsApp (emails are still sent).
* A test alert button.

= Hosted status page =

* A status page on transara.laghavi.site that stays online when your site is down, or on your own address (status.yourdomain.com).
* Your title, logo, colour and a notice, notes on incidents, and an optional password.
* Visitors can subscribe to updates by email.

= Monthly reports =

* Uptime, downtime, incidents and average response per monitor, emailed on the 1st of each month to you or your client, and shown in Transara › Pro.

= Firewall (Transara › Firewall) =

* Blocks SQL injection, script injection, PHP code and path traversal in addresses.
* Blocks known attack tools (sqlmap, Nikto, WPScan, Acunetix…) and PHP files in the uploads folder.
* Limits requests a minute per visitor, and blocks visitors who scan for pages (many "not found" pages).
* Always-block and never-block lists of IP addresses and ranges, a log of blocked requests, and one-click unblocking.
* Your own address is never blocked by the lists. Logged-in users are never rate-limited.

= WebP images (Transara › Speed) =

* A WebP copy of every JPEG and PNG upload and each of its sizes, kept only when it is smaller.
* Pages offer WebP with <picture>, so every browser gets an image it can show and cached pages work for everyone.
* The main image preload asks for the WebP too, so it is never downloaded twice.
* Convert existing images in the background (Transara › Tools). Originals are never changed.

= WooCommerce checks =

Added to Transara's hourly health checks and alerted like them:

* Shop, cart, checkout and account pages published.
* At least one payment method on.
* Many failed payments.
* Orders stopped: no orders in 24 hours on a shop that normally gets several a day.
* WooCommerce background tasks running late.

== External services ==

* **Laghavi Digital license server (laghavi.site)**: checks the license key when you activate it and once a day, and offers updates. Sent: the license key, this site's address and the plugin name.
* **Transara service (transara.laghavi.site)**: only after you click "Connect". Sent: the license key and this site's address (to connect), then your monitors (names, addresses and check settings) and your alert and status page settings, whenever they change and at most hourly. The service checks the addresses you monitor and sends alerts to the email addresses, phone numbers, Telegram chat and webhooks you entered. Disconnecting deletes this site's checks, history and status page on the service.
* SMS and WhatsApp alerts are sent by the service through Twilio, Telegram alerts through Telegram, and webhooks to the addresses you enter.

== Changelog ==

= 1.0.0 =
* First release.
