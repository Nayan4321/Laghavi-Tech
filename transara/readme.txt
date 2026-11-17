=== Transara – Speed, Uptime & Security ===
Contributors: nayan4321
Tags: cache, performance, uptime monitor, security, optimization
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Page cache and speed optimisation, uptime and SSL monitoring with alerts, a status page, and a security scan, in one plugin.

== Description ==

Transara makes your site fast, tells you when something breaks, and keeps an eye on its security. Everything is in plain words, with safe defaults.

= Speed =

* **Page cache**: pages are saved as ready HTML files (with a compressed copy) and sent without running WordPress again. Cached pages update by themselves when you edit content, comments or menus. Options for phones, logged-in users, pages, cookies and browsers that are never cached, and query strings that get their own copy. WooCommerce cart, checkout and account pages are never cached.
* **Preload**: pages from your sitemap are cached in the background, so the first visitor gets a fast page too. Links can be loaded early when a visitor is about to click them.
* **CSS and JavaScript**: minify CSS and JavaScript files, load scripts deferred, or delay scripts until the visitor interacts (chat widgets, trackers and ads), with lists of files to leave alone. Minify HTML and remove the emoji script.
* **Images and videos**: lazy load images, iframes and videos, load the first images and the main image at once, add missing image sizes, and show a YouTube preview image until the visitor clicks play.
* **Fonts**: host Google Fonts on your own site, show text while fonts load, connect early to other domains and preload font files.
* **Database**: remove old revisions, automatic drafts, trashed posts, spam and trashed comments and expired temporary data, optimise tables, and do it automatically.
* **Heartbeat**: slow down or stop WordPress's background requests in the dashboard, the editor and the website.
* **CDN, Cloudflare and Varnish**: serve files from a CDN, and clear Cloudflare and Varnish together with Transara's cache.
* **Browser caching and compression** rules for Apache and LiteSpeed, and rules to copy for Nginx.
* **Development mode** turns caching and optimisation off for a few hours while you work.
* Export and import your settings.

= Monitoring =

* **Monitors**: website status, a word on a page (or a word that must not be there), ports (server, database, mail), SSL certificates, and heartbeats for your own cron jobs.
* Checked every minute to every day, with retries before an alert.
* **Uptime** for 24 hours, 7, 30 and 90 days, response time charts and incidents.
* **Alerts** by email and to Slack, Discord, Microsoft Teams or any webhook, when a monitor goes down and when it is back. Pause alerts during maintenance.
* **Health checks** every hour: late background tasks, PHP errors, disk space, updates, the REST API, HTTPS, errors shown to visitors and the PHP version.
* **Status page**: a public page with the monitors you choose, as a block or the [transara_status] shortcode.
* **Speed reports** from Google PageSpeed Insights, on demand or on a schedule you turn on, with history.

= Security =

* **Hardening**: hide the WordPress version, turn off the file editor and XML-RPC, hide the user list from the REST API, stop username discovery, one login error message, block PHP files in uploads, block sensitive files and folder listings.
* **Security headers**, and HSTS when you turn it on.
* **Security scan**: WordPress and plugin files compared with the originals from WordPress.org, known malware patterns, PHP files in uploads, and (when you turn it on) known vulnerabilities in your plugins, themes and WordPress.
* **Activity log**: logins, failed logins, new users and role changes, plugin, theme and core changes, settings and content changes, with an alert when someone becomes an administrator.
* A security score with what to fix.
* Works alongside the Sarala Login Page plugin for login security (two-step login, login limits, a custom login address).

= Multilingual =

Transara is translation-ready. Cached pages are kept apart per address, so language folders and language subdomains get their own copy.

== Installation ==

1. Install and activate Transara from Plugins › Add New.
2. Open **Transara**. The page cache and safe speed settings are on; check your site, then turn on more options in Transara › Speed.
3. Add monitors in **Transara › Monitoring** and enter where alerts go.
4. Review **Transara › Security** and run the first scan.

== Frequently Asked Questions ==

= Does it work with other caching plugins? =

Use one page cache at a time. Transara tells you when another plugin already uses the page cache file (advanced-cache.php).

= Does it work with Nginx? =

Yes. The page cache works on every server. Browser caching and compression rules are written for Apache and LiteSpeed; for Nginx, Transara › Tools shows the rules to add.

= Something looks wrong after turning on an option =

Turn the option off, or add the file to its "not to minify / defer / delay" list. Development mode turns everything off for a few hours while you look.

= What happens when I deactivate or delete it? =

Deactivating removes the page cache file, the WP_CACHE line and the server rules Transara added, and stops its background tasks. Deleting it also removes its settings, monitoring history, activity log, cached pages and copied fonts.

== External services ==

Transara only contacts the services below, and only when the feature that needs them is used.

* **Google PageSpeed Insights** (googleapis.com): when you run a speed test, or on the schedule you turn on in Transara › Monitoring › Speed reports. Sent: the addresses of the pages to test, and your Google API key if you entered one. [Terms](https://developers.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).
* **Google Fonts** (fonts.googleapis.com, fonts.gstatic.com): only when "Host Google Fonts on your site" is on. Transara downloads the font files your theme already uses once, so visitors no longer contact Google. Sent: a normal download request for those files. [Privacy Policy](https://policies.google.com/privacy).
* **YouTube** (i.ytimg.com): only when "Show a YouTube preview image" is on. Visitors' browsers load the preview image of each embedded video from YouTube; the video itself loads only when they click play. [Terms](https://www.youtube.com/t/terms), [Privacy Policy](https://policies.google.com/privacy).
* **WordPress.org** (api.wordpress.org, downloads.wordpress.org): during security scans, to get the checksums of WordPress and plugin files. Sent: your WordPress version and locale, and the names and versions of plugins from WordPress.org. [Privacy Policy](https://wordpress.org/about/privacy/).
* **WPVulnerability** (wpvulnerability.net): only when "Check for known vulnerabilities" is on. Sent: the names and versions of WordPress, your plugins and themes. [Terms and privacy](https://www.wpvulnerability.com/).
* **Cloudflare** (api.cloudflare.com): only when you enter a Cloudflare API token and zone ID, to clear Cloudflare's cache when Transara's cache is cleared. Sent: your token, zone ID and the addresses to clear. [Terms](https://www.cloudflare.com/website-terms/), [Privacy Policy](https://www.cloudflare.com/privacypolicy/).
* **Your webhook** (Slack, Discord, Microsoft Teams or another address you enter): only when you enter one, for alerts. Sent: the alert text (which monitor, what happened, when).
* **Addresses you monitor**: monitors connect to the websites, ports and certificates you add, to check them.
* **Your own website**: preload and monitors of your own site make requests to it; Varnish clearing sends requests to your own server.

== Privacy ==

* The activity log stores the user name and IP address of each logged event in your database. It is kept for the number of days you choose and can be turned off.
* Transara adds no cookies and no tracking.

== Changelog ==

= 1.0.0 =
* First release.
