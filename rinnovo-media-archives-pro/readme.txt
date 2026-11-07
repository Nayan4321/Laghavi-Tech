=== Rinnovo Media Archives Pro ===
Contributors: nayan4321
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Streaming archives and web app access for Rinnovo Subscriptions & Licenses: signed expiring media links, watermarked players, private podcast feeds, view-only galleries, status API, webhooks, single sign-on, feature flags, device limits and a self-service portal.

== Description ==

For video courses, podcasts, music and art archives, and SaaS or hosted tools, sold as monthly or yearly access (not downloads). Requires the free **Rinnovo Subscriptions & Licenses** plugin and WooCommerce.

= Plans, features and quotas =

* Plans (Basic, Pro…) made of WooCommerce subscription products or variations (monthly / yearly).
* Each plan has feature flags and quotas for your app: `ai_credits=500`, `exports=yes`, `projects=10`. Quotas reset every billing period.

= Tokenized, expiring streaming links =

* Bunny Stream (token authentication) and Bunny CDN files (token authentication).
* Cloudflare Stream signed tokens, made on your site with your signing key.
* Amazon CloudFront signed URLs and Amazon S3 presigned URLs.
* Vimeo private links.
* Links are made for subscribers only and expire. Audio and file players get a short-lived link through your site that forwards to the host's signed address, so the page source never shows a reusable address.

= Protected, watermarked players =

* `[media_video]` and `[media_audio]`, and Presto Player videos.
* No right-click, dragging or download buttons, and media only plays from your own pages.
* A moving watermark shows the subscriber's email and IP over the video.

= Private podcast feeds =

* Shows and Episodes under Podcasts. Each subscriber gets their own RSS address for Apple Podcasts, Overcast and other apps.
* Every play is checked, so the feed stops working the moment the subscription ends: it then shows one "subscription ended" item and no audio.
* Feeds used from too many IP addresses in a day are flagged as shared. Subscribers can get a new address at any time.

= View-only galleries =

* `[media_gallery]` shows art at screen size only (never the full-resolution file), with the subscriber's email drawn into the image.
* Image links expire and can't be hotlinked; right-click and dragging are off. Originals stay in a protected folder.

= Subscription status API and webhooks =

* REST endpoints for your hosted app: `/access`, `/me`, `/usage`, `/sessions`, `/portal-link`.
* Signed, retried webhooks: subscription.active, subscription.trial, subscription.payment_failed, subscription.cancelled, subscription.expired, subscription.paused, access.revoked and security.alert.

= Single sign-on =

* WordPress / WooCommerce as the identity provider: OAuth 2 authorization code flow with PKCE, an OpenID-style discovery document, userinfo, rotating refresh tokens, and JWT log-in with WooCommerce credentials.
* Adds plan and feature data to WP OAuth Server and JWT Authentication for WP REST API tokens too.

= Account sharing and security =

* Limits per plan: devices signed in at once (site and app) and simultaneous streams.
* Use from two different countries within the time you set is flagged (emails and webhook), or the account is signed out everywhere.

= Instant cut-off =

* When a subscription is cancelled, refunded, paused or out of its grace period after a failed payment, all app tokens stop working at once. Feeds, players and app devices stop too, and optionally site log-ins.

= Trials and upgrades =

* 7 or 14 day free trials, with or without a card.
* Upgrades (for example monthly to yearly) credit the unused part of the current period, through the normal checkout.

= Self-service portal =

* My Account > Your access, or `[media_account]` on any page, or framed in your app, or opened from your app with a one-time portal link.
* Plan, usage, private feed addresses, devices and "sign out everywhere", pause and resume, plan changes, card and invoices.

== Installation ==

1. Install and activate WooCommerce and the free Rinnovo Subscriptions & Licenses plugin.
2. Upload and activate Rinnovo Media Archives Pro, and enter your key in WooCommerce > Media Archives Pro license.
3. WooCommerce > Media Archives: create plans, connect your media hosts, and create API keys, webhooks and SSO apps for your web app.

== External services ==

* **License server (laghavi.site):** sends your license key, site address, plugin name and version to activate, check and update. [Terms](https://laghavi.site/terms-and-conditions/)
* **Media hosts you set up (Bunny.net, Cloudflare Stream, Amazon CloudFront / S3, Vimeo, Presto Player):** links to them are signed on your site; subscribers' browsers and podcast apps load the media from them.
* **Your web app:** webhooks are sent to the addresses you enter.

== Changelog ==

= 1.0.0 =
* First release.
