=== Rinnovo Service Contracts Pro ===
Contributors: nayan4321
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Service retainers for Rinnovo Subscriptions & Licenses: onboarding fees, minimum terms, billing on the 1st, packages with deliverables, hours and request quotas, one-click add-ons, intake briefs, CRM and project boards, pause, GST tax invoices with PO numbers and bank transfer billing.

== Description ==

For monthly SEO packages, design retainers, marketing support and freelance contracts. Requires the free **Rinnovo Subscriptions & Licenses** plugin and WooCommerce.

= Onboarding fees and minimum terms =

* A one-time onboarding, audit or setup fee with its own name, charged with the first month.
* A minimum term (for example 3 or 6 payments). A client who cancels earlier is told when the term ends, and the contract then ends by itself.

= One billing day =

* Bill every retainer on the same day (for example the 1st). The first month is charged for the days left, and Stripe card contracts move to that day too.

= Packages, deliverables and quotas =

* Simple products or variations (Starter, Growth, Enterprise), each with its monthly deliverables, shown as a comparison table on the product page.
* A quota per month (hours, design requests, blog posts). Unused units expire, roll over, or roll over up to a limit.
* Clients send requests from their account; you mark them done with the hours used.

= One-click add-ons =

* Extras such as another landing page or a rush fee go onto the client's next renewal invoice with one click, no checkout.
* Card contracts: a Stripe invoice item. Bank transfer contracts: an extra line on the invoice.

= Intake briefs =

* Right after payment, and in My Account: your own questions (website logins stored encrypted, files kept private), or a Gravity Forms, WPForms or Contact Form 7 form.
* Reminders after 1, 3 and 7 days until the brief is sent.

= Client workspace and monthly tasks =

* New clients go into FluentCRM (with a tag) and Jetpack CRM.
* The product's task list becomes Trello cards or ClickUp tasks when the contract starts and on every renewal, plus a webhook for Zapier or Make.

= Pause =

* You or the client can pause a retainer for some months. Nothing is billed, and the contract, history and card are kept.

= GST / VAT tax invoices =

* A PDF tax invoice for every paid payment, emailed to the client and downloadable from their account. Numbers have no gaps and can include the financial year (INV/26-27/00001).
* India: CGST + SGST for clients in your state, IGST between states, export of services outside India, SAC / HSN codes, place of supply with state code, and the amount in words in lakh and crore.
* GSTIN checked at checkout, including the check digit.

= B2B details and PO numbers =

* Company name, GSTIN / VAT number and PO number at checkout. Clients can change the PO for the next invoices from their account; it appears on every invoice.

= Bank transfer and invoice approval =

* Card clients can switch to bank transfer invoices: the cycle continues in WooCommerce and your bank details are printed on the invoice.
* Optional approval of each invoice by the client's accounts team, and net payment terms printed as the due date.

== Installation ==

1. Install and activate WooCommerce and the free Rinnovo Subscriptions & Licenses plugin.
2. Upload and activate Rinnovo Service Contracts Pro, and enter your key in WooCommerce > Service Contracts Pro license.
3. WooCommerce > Service Contracts > Settings: your GST details, billing day and integrations. Then tick "Service retainer" in a subscription product's "Service contract" tab.

== External services ==

* **License server (laghavi.site):** sends your license key, site address, plugin name and version to activate, check and update. [Terms](https://laghavi.site/terms-and-conditions/)
* **Trello / ClickUp (only if you enter keys):** the task names, client name and deliverables are sent to create cards or tasks. [Trello terms](https://www.atlassian.com/legal/cloud-terms-of-service) · [ClickUp terms](https://clickup.com/terms)
* **Your webhook (only if set):** contract and client details are sent to the address you enter.
* **Stripe (through Rinnovo Subscriptions & Licenses):** for card contracts, add-ons, pauses and billing day changes are sent to Stripe.

== Changelog ==

= 1.0.0 =
* First release.
