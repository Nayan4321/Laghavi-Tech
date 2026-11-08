=== Aarye Marketing Addon ===
Contributors: nayan4321
Tags: utm, lead tracking, marketing attribution, google ads, google sheets
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A helper for your marketing team: see which ad, campaign, keyword or website brought every lead, and whether the lead is real.

== Description ==

You pay for ads on Google, Meta, TikTok or LinkedIn, and leads arrive on your website. But which ad sent them? Aarye Marketing Addon puts the answer next to every lead: **Meta Ads · Summer Sale › UAE 25-45 › Video Ad 1**, **Google Ads · Brand Search · "plumber dubai"**, **ChatGPT**, **Google (organic)** or **Direct**.

Install it and every new enquiry sent from your website is saved with its source, in one dashboard. Nothing else to install or set up.

= Knows where each lead came from =

* **Ads**, recognised by their click IDs and ad tags: Google Ads (gclid, gbraid, wbraid, Performance Max), Meta Ads (Facebook, Instagram, Messenger, WhatsApp), Microsoft Advertising (Bing), TikTok Ads, LinkedIn Ads, X (Twitter) Ads, Snapchat Ads, Pinterest Ads, Reddit Ads, Quora Ads, Yandex Direct, Google Display & Video 360, Amazon Ads, Apple Search Ads, Spotify Ads, Taboola, Outbrain, Criteo, AdRoll, StackAdapt, The Trade Desk, Baidu, Naver and any other network that uses UTM tags.
* **Campaign, ad set / ad group, ad and keyword** for every ad lead.
* **Search engines** (Google, Bing, Yahoo, DuckDuckGo, Yandex, Baidu, Ecosia…), **social networks**, **AI assistants** (ChatGPT, Perplexity, Gemini, Copilot, Claude…), **email**, **other websites** and **direct** visits.
* **First and last touch**: the first visit that brought the person, and the latest one before they got in touch, with the number of visits and days in between.
* **Landing page, referrer, the page the lead was sent from, device, browser and language.**

= Is the lead real? =

* **Source proof**: confirmed by an ad click ID, confirmed by the browser (the website it really came from), or only claimed by the tags in the link (utm_source), which anyone can add.
* **Spam check** with a score and the reasons in plain words: the page script never ran (posted directly by a bot), no mouse, touch or keys on the page, sent within seconds, browser controlled by automation software, not sent from your website, links and sales-pitch words in the message, repeated leads. It explains, it never deletes: filter by it or mark spam yourself.

= One dashboard for every lead =

* **Overview**: leads by source, ad network, campaign, ad, website, language and device, with a date range and filters.
* **All leads**: search, filter by source, ad network, campaign or language, and export to CSV (opens correctly in Excel, in any language).
* **Lead details**: every detail the visitor sent, attachments to download, and all tracking parameters.
* **Lead status**: New, Contacted, Qualified, Won, Lost or Spam.
* **Attachments are kept** as a private copy that only administrators can download.
* **Past leads**: bring in enquiries your site saved before Aarye was installed, with their original dates.

= Connect your ads: built-in guide =

A step-by-step guide for Google Ads, Meta Ads, Microsoft Advertising, TikTok, LinkedIn, X, Snapchat, Pinterest, Reddit and any other network, with the tracking template to copy for each one (using each network's own placeholders, so campaign and ad names fill in by themselves). Paste the address of an ad in **Test a link** to see how its leads will be shown.

= Google Sheets =

Every new lead can be added as a row to your own Google Sheet, with its source, campaign and ad, and every detail in its own column. It uses a small script in your spreadsheet: no Google login, password or API key is stored in WordPress. Rows are sent in the background, so your site stays fast, and are tried again if Google is unavailable.

= Multilingual =

* The language of each lead is saved, from WPML, Polylang, TranslatePress, Weglot or the page itself, and you can filter by it.
* Name, email and phone are recognised in any language.
* The plugin is translation-ready.

= Privacy =

* Tracking uses two first-party cookies on your own domain; nothing is sent to us or to third parties.
* Optional: wait for cookie consent (works with the WP Consent API).
* Visitor IP addresses are not stored unless you turn it on.
* Suggested text for your privacy policy, and support for WordPress's personal data export and erase tools.

== Installation ==

1. Install and activate the plugin from Plugins > Add New, or upload the folder to `/wp-content/plugins/`.
2. Open **Aarye Leads** in the admin menu. New leads appear there with their source.
3. Open **Aarye Leads > Connect your ads** and add the tracking template to each ad account, so campaign and ad names are shown too.
4. Optional: **Aarye Leads > Google Sheets** to send leads to a spreadsheet.

== Frequently Asked Questions ==

= Do I need to install anything else? =

No. Aarye saves the enquiries visitors send from your website as they are, with no changes to your pages and nothing else to install.

= Will it show leads that arrived before I installed it? =

Yes, when your site saved them: open Aarye Leads > Import past entries. They keep their original dates; their source is known only when the saved page address carries ad tags (gclid, fbclid, utm_…).

= Can I ignore some enquiries, such as a newsletter signup? =

Yes. Add its CSS selector in Aarye Leads > Settings. Logins, searches, carts, checkouts, comments and payment pages are never saved.

= Do I need UTM tags? =

Not for the ad network itself: Google, Meta, Microsoft, TikTok, LinkedIn and others add a click ID to every ad click, and Aarye recognises it. To also see the campaign, ad set and ad name, add the tracking template from **Connect your ads** once per ad account.

= Why does a lead from an Instagram ad show "Meta Ads", not Instagram? =

Instagram, Facebook, Messenger and WhatsApp ads are all bought in Meta Ads Manager. With the Meta tracking template, the placement (Instagram, Facebook…) is saved too and shown in the lead details.

= Does it slow down my site? =

No. The tracking script is a few kilobytes, loads deferred and only reads the address and referrer of a visit. Nothing is sent to other servers when someone browses.

= Does it work with page caching? =

Yes. The source is stored in a cookie by the browser, so cached pages work.

= Who can see the leads? =

Administrators (users with the "manage_options" capability). Developers can change this with the `aarye_capability` filter.

= What happens to my data if I delete the plugin? =

Leads and files are kept unless you tick "Also delete all saved entries and files" in Aarye Leads > Settings before deleting.

== External services ==

This plugin connects to an external service only if you set up the Google Sheets connection.

**Google Apps Script (Google Sheets)**: used to add each new lead as a row in a Google Sheet that you own. You create a small script in your spreadsheet and paste its web app address into Aarye Leads > Google Sheets. After that, each time a lead arrives, the plugin sends to that address: the entry's fields as filled in by the visitor (for example name, email, phone, message and any other field), the name of the place it came from, date, source, channel, campaign, ad set, ad, keyword, click ID, landing page, referrer, page it was sent from, language, device, number of visits, source proof and spam check, links to attachments, and the secret you set. When you click "Test connection", only the secret is sent. Nothing is sent while the connection is not set up.
The service is provided by Google: [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy), [Apps Script terms](https://developers.google.com/apps-script/terms).

The plugin also makes a background request to your own website (wp-admin/admin-post.php) to send Google Sheets rows without making the visitor wait. This request stays on your server.

== Screenshots ==

1. Overview: leads by source, ad network, campaign and ad.
2. All leads, with the source of each.
3. Lead details: everything the visitor sent, attachments and where the lead came from.
4. Connect your ads: step-by-step guide with tracking templates.
5. Google Sheets: each lead as a row with its source.

== Changelog ==

= 1.1.0 =
* Source proof and a Spam check with plain-language reasons for every lead.

= 1.0.0 =
* First release.
