=== Aarye Marketing Addon for Forms ===
Contributors: nayan4321
Tags: utm, lead tracking, contact form 7, form entries, google sheets
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which ad, campaign or website sent every form entry. One dashboard for 10 form plugins, with attachments, an ads guide and Google Sheets.

== Description ==

You pay for ads on Google, Meta, TikTok or LinkedIn, and leads arrive through your website forms. But which ad sent them? Aarye Marketing Addon for Forms puts the answer next to every entry: **Meta Ads · Summer Sale › UAE 25-45 › Video Ad 1**, **Google Ads · Brand Search · "plumber dubai"**, **ChatGPT**, **Google (organic)** or **Direct**.

It works with the form plugins you already use, and keeps all entries from all of them in one dashboard.

= Works with =

* Contact Form 7
* WPForms (Lite and Pro)
* Elementor Pro Forms
* Fluent Forms
* Gravity Forms
* Forminator
* Ninja Forms
* Formidable Forms
* JetFormBuilder
* MetForm

Nothing to change in your forms: install, and every new entry is saved with its source.

**Theme and custom forms too.** Forms made by your theme (for example Houzez property enquiries), by a page builder or by hand are saved as well, when they have an email or phone field. Logins, searches, carts, checkouts, comments and payment forms are never saved.

**Can you trust the lead?** Each entry shows a **Source proof**: confirmed by an ad click ID, confirmed by the browser (the website it really came from), or only claimed by the tags in the link (utm_source), which anyone can add, for example a spammer pretending to come from a directory you're listed on. Next to it, a **Spam check** with a score and the reasons in plain words: the page script never ran (posted directly by a bot), no mouse, touch or keys on the page, sent within seconds, browser controlled by automation software, form not sent from your website, links and sales-pitch words in the message, repeated entries. It explains, it never deletes: filter by it, mark spam yourself, or let Aarye Pro do it.

**Past entries.** Import the entries your form plugins saved before Aarye was installed: Gravity Forms, Fluent Forms, WPForms Pro, Forminator, Ninja Forms, Formidable Forms, JetFormBuilder, MetForm, Elementor Pro Forms, Contact Form 7 (through Flamingo) and the Houzez CRM. They keep their original dates; their source is read from the saved page address when it carries ad tags.

= Knows where each lead came from =

* **Ads**, recognised by their click IDs and ad tags: Google Ads (gclid, gbraid, wbraid, Performance Max), Meta Ads (Facebook, Instagram, Messenger, WhatsApp), Microsoft Advertising (Bing), TikTok Ads, LinkedIn Ads, X (Twitter) Ads, Snapchat Ads, Pinterest Ads, Reddit Ads, Quora Ads, Yandex Direct, Google Display & Video 360, Amazon Ads, Apple Search Ads, Spotify Ads, Taboola, Outbrain, Criteo, AdRoll, StackAdapt, The Trade Desk, Baidu, Naver and any other network that uses UTM tags.
* **Campaign, ad set / ad group, ad and keyword** for every ad lead.
* **Search engines** (Google, Bing, Yahoo, DuckDuckGo, Yandex, Baidu, Ecosia…), **social networks**, **AI assistants** (ChatGPT, Perplexity, Gemini, Copilot, Claude…), **email**, **other websites** and **direct** visits.
* **First and last touch**: the first visit that brought the person, and the latest one before they sent the form, with the number of visits and days in between.
* **Landing page, referrer, the page the form was sent from, device, browser and language.**

= One dashboard for all your forms =

* **Overview**: leads by source, ad network, campaign, ad, website, form, language and device, with a date range and filters.
* **All entries**: every entry from every form plugin, with the source badge in front of it. Search, filter by source, ad network, campaign, form or language, and export to CSV (opens correctly in Excel, in any language).
* **Entry details**: every field of the form, custom fields included, attachments to download, and all tracking parameters.
* **Lead status**: New, Contacted, Qualified, Won, Lost or Spam.
* **Attachments are kept**: some form plugins delete uploaded files after emailing them. Aarye keeps a private copy that only administrators can download.

= Connect your ads: built-in guide =

A step-by-step guide for Google Ads, Meta Ads, Microsoft Advertising, TikTok, LinkedIn, X, Snapchat, Pinterest, Reddit and any other network, with the tracking template to copy for each one (using each network's own placeholders, so campaign and ad names fill in by themselves). Paste the address of an ad in **Test a link** to see how its leads will be shown.

= Google Sheets =

Every new entry can be added as a row to your own Google Sheet, with its source, campaign and ad, and every form field in its own column. It uses a small script in your spreadsheet: no Google login, password or API key is stored in WordPress. Rows are sent in the background, so your forms stay fast, and are tried again if Google is unavailable.

= Multilingual =

* The language of each entry is saved, from WPML, Polylang, TranslatePress, Weglot or the page itself, and you can filter by it.
* Name, email and phone are recognised in forms in any language.
* The plugin is translation-ready.

= Privacy =

* Tracking uses two first-party cookies on your own domain; nothing is sent to us or to third parties.
* Optional: wait for cookie consent (works with the WP Consent API).
* Visitor IP addresses are not stored unless you turn it on.
* Suggested text for your privacy policy, and support for WordPress's personal data export and erase tools.

= Aarye Pro =

[Aarye Marketing Addon for Forms Pro](https://laghavi.site/aarye-marketing-addon-for-forms/) is a separate add-on that sends each entry instantly to Zoho CRM, Zoho Forms, HubSpot, Airtable, Notion, your own database or any CRM by API or webhook; adds form analytics (views, starts, completions, abandonment by field, conversion rates); shows the pages each visitor viewed; and connects to the Laghavi Ads Hub marketing dashboard.

== Installation ==

1. Install and activate the plugin from Plugins > Add New, or upload the folder to `/wp-content/plugins/`.
2. Open **Aarye Leads** in the admin menu. New form entries appear there with their source.
3. Open **Aarye Leads > Connect your ads** and add the tracking template to each ad account, so campaign and ad names are shown too.
4. Optional: **Aarye Leads > Google Sheets** to send entries to a spreadsheet.

== Frequently Asked Questions ==

= Do I need to change my forms? =

No. Aarye listens to each form plugin's own "form sent" event. Your forms, emails and the form plugin's own entries stay exactly as they are.

= Will it show entries sent before I installed it? =

Yes: open Aarye Leads → Import past entries. Form plugins that save entries are imported with their original dates, fields and files. Their source is known only when the page address saved with them carries ad tags (gclid, fbclid, utm_…); the others show as "Not tracked (imported)". Imported entries are not sent to Google Sheets, and entries already in Aarye are skipped.

= My theme has its own forms (Houzez, real estate or booking themes). Are they saved? =

Yes, when they have an email or phone field: Aarye saves them under "Other forms" with their source when the visitor clicks send. If the form rejects the message and the visitor corrects it, the same entry is updated. To ignore a form, add its CSS selector in Aarye Leads → Settings.

= Do I need UTM tags? =

Not for the ad network itself: Google, Meta, Microsoft, TikTok, LinkedIn and others add a click ID to every ad click, and Aarye recognises it. To also see the campaign, ad set and ad name, add the tracking template from **Connect your ads** once per ad account.

= Why does a lead from an Instagram ad show "Meta Ads", not Instagram? =

Instagram, Facebook, Messenger and WhatsApp ads are all bought in Meta Ads Manager. With the Meta tracking template, the placement (Instagram, Facebook…) is saved too and shown in the entry details.

= Does it slow down my site? =

No. The tracking script is a few kilobytes, loads deferred and only reads the address and referrer of a visit. Nothing is sent to other servers when someone browses.

= Does it work with page caching? =

Yes. The source is stored in a cookie by the browser, so cached pages work.

= Can I see entries from a form plugin that stores no entries (Contact Form 7, WPForms Lite)? =

Yes. Aarye stores its own copy of every entry, attachments included.

= Who can see the entries? =

Administrators (users with the "manage_options" capability). Developers can change this with the `aarye_capability` filter.

= What happens to my data if I delete the plugin? =

Entries and files are kept unless you tick "Also delete all saved entries and files" in Aarye Leads > Settings before deleting.

== External services ==

This plugin connects to an external service only if you set up the Google Sheets connection.

**Google Apps Script (Google Sheets)**: used to add each new form entry as a row in a Google Sheet that you own. You create a small script in your spreadsheet and paste its web app address into Aarye Leads > Google Sheets. After that, each time a form is sent, the plugin sends to that address: the entry's fields as filled in by the visitor (for example name, email, phone, message and any other field), the form name, date, source, channel, campaign, ad set, ad, keyword, click ID, landing page, referrer, form page, language, device, number of visits, source proof and spam check, links to attachments, and the secret you set. When you click "Test connection", only the secret is sent. Nothing is sent while the connection is not set up.
The service is provided by Google: [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy), [Apps Script terms](https://developers.google.com/apps-script/terms).

The plugin also makes a background request to your own website (wp-admin/admin-post.php) to send Google Sheets rows without making the visitor wait. This request stays on your server.

== Screenshots ==

1. Overview: leads by source, ad network, campaign and ad.
2. All entries from all form plugins, with the source of each.
3. Entry details: every field, attachments and where the lead came from.
4. Connect your ads: step-by-step guide with tracking templates.
5. Google Sheets: each entry as a row with its source.

== Changelog ==

= 1.1.0 =
* New: Source proof for every entry. Shows whether the source is confirmed by the browser (referrer), only claimed by the tags in the link (utm_source), or contradicted by the browser, with an explanation.
* New: Spam check with a score and plain-language reasons: page script never ran, no mouse/touch/keys, sent within seconds, browser controlled by automation software, submission not sent from your website, links and sales-pitch words in the message, repeated entries, faked referral tags.
* New: "Source proof" and "Spam check" columns in All entries, a Spam check filter, and the same columns in CSV export and Google Sheets.
* Older entries get the spam check from the information they already have.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.1.0 =
Adds Source proof and a Spam check with plain-language reasons to every entry.

= 1.0.0 =
First release.
