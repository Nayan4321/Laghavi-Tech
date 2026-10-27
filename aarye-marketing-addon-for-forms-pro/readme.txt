=== Aarye Marketing Addon for Forms Pro ===
Contributors: nayan4321
Tags: lead tracking, crm, hubspot, zoho, form analytics
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: aarye-marketing-addon-for-forms
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send every form lead with its ad source to your CRM and to Laghavi Ads Hub, see where people give up on your forms, and automate what happens next.

== Description ==

Pro add-on for the free [Aarye Marketing Addon for Forms](https://laghavi.site/aarye-marketing-addon-for-forms/). Needs the free plugin, which catches entries from Contact Form 7, WPForms, Elementor Pro Forms, Fluent Forms, Gravity Forms, Forminator, Ninja Forms, Formidable Forms, JetFormBuilder and MetForm, and knows which ad, campaign or website each lead came from.

= Connections: every lead, instantly, where you work =

* **Laghavi Ads Hub**: real leads in your marketing dashboard. The hub compares each ad network's reported results with real form leads and shows the cost per real lead by campaign and ad. Lead status changes (Won, Lost, Spam) update the hub. Only the source of a lead is sent, never names, emails, phone numbers or messages. Daily form analytics can be sent too.
* **Zoho CRM**: adds a Lead or Contact (updated when the email exists), with the ad, campaign, keyword and every form field. All Zoho data centres.
* **Zoho Forms**: Zoho Forms embedded on your site get the visitor's ad source in hidden fields, and their entries come back into Aarye through a webhook.
* **HubSpot**: creates or updates the contact and adds a note with the entry and its source. Map any property with tokens like {campaign} or {field:Company}.
* **Airtable**: one record per entry, columns matched by name.
* **Notion**: one page per entry in a database, with every field.
* **Your own database**: a MySQL or MariaDB table, created automatically and updated when the status changes.
* **Webhook / custom CRM**: JSON to any address (your CRM's API, Zapier, Make, n8n…), signed with HMAC, with your own headers.

Entries are sent in the background a few seconds after the form is sent, so forms stay fast. If a service is down, Aarye tries again for 7 days; if a key is revoked, it stops, tells you, and sends everything that waited once you fix it. A delivery log shows what went where, with "Try again".

= Form analytics =

* Views, starts, submissions and abandons for every form, with start rate, conversion and completion.
* **Abandonment by field**: the last field people filled before leaving, so you know which question loses leads.
* Conversion by source: do Meta visitors fill in your form as often as Google visitors?
* Nothing typed in the form is sent for analytics.

= Pages viewed, with IP address =

On every entry: the pages the visitor viewed before sending, with time on each page and their IP address (when you choose to save IP addresses).

= Automations =

Rules like "when the source is Meta Ads and Budget is 5k+ → status Qualified, email sales@, send to HubSpot". Conditions on source, ad network, campaign, ad, keyword, form, language, device or any form field.

= Spam and bot protection =

Bots, disposable email addresses, too many links, blocked words, emails, domains or IP addresses, and the same email sent again and again: such entries are kept with status Spam but are never sent to your CRM or counted as leads.

== Installation ==

1. Install and activate the free Aarye Marketing Addon for Forms.
2. Upload this plugin and activate it.
3. Open **Aarye Leads → Pro license** and enter the key from your purchase email or from My Account → Licenses on laghavi.site.
4. Open **Aarye Leads → Connections** and set up the services you use. Each one has step-by-step instructions.

== Frequently Asked Questions ==

= What happens when my license expires? =

Everything keeps working. Only updates stop until you renew.

= Can I send only some leads to a CRM? =

Yes. Switch "Send new entries" off on that connection, and add an automation rule with "Send it to".

= Is personal data sent to Laghavi Ads Hub? =

No. The hub receives the lead's ID, date, status, form name, pages, device and its source (UTM tags, click IDs, ad IDs), never names, emails, phone numbers, IP addresses or what was typed in the form.

== External services ==

Pro connects only to the services you set up under Connections, and sends each new entry to them as described there: Laghavi Ads Hub (your hub address), Zoho (accounts.zoho.* and zohoapis.*), HubSpot (api.hubapi.com), Airtable (api.airtable.com), Notion (api.notion.com), your database server and your webhook address. License checks and updates use laghavi.site (license key, site address, plugin version).

== Changelog ==

= 1.0.1 =
* New spam protection option: mark entries that the free plugin's Spam check calls "Likely spam" as spam (needs Aarye 1.1).

= 1.0.0 =
* First release.
