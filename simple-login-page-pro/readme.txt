=== Simple Login Page Pro ===
Contributors: nayan4321
Tags: super admin, activity log, admin menu editor, hide blocks, user roles
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pro add-on for Simple Login Page: a protected super admin, a full user activity log, and per-role or per-user control over the admin, the editor and the front end.

== Description ==

Requires the free **Simple Login Page** plugin. Buy a license at [laghavi.site](https://laghavi.site/).

**Super admin**

* The admin who activates Pro becomes the super admin (you can hand it over later).
* Only the super admin can add or promote administrators. Add one from the Super admin screen by email.
* Other administrators cannot edit, delete or demote the super admin or each other, and cannot deactivate Simple Login Page.
* Optionally, only the super admin can install/update/delete plugins and themes, switch themes, edit files and update WordPress.
* Optionally hide the super admin from other administrators' Users list.
* Recovery: `define( 'SLPP_OWNER', 1 );` in wp-config.php makes user 1 the super admin.

**User activity**

* "Last activity" for every user on the Users screen, for example "5 mins ago · Edited “About us”".
* A full log: logins and logouts, sign-ups, profile and role changes, posts and pages created, edited, published, trashed and deleted, media uploads, comments, menus, plugins and themes activated, deactivated, installed, updated or deleted, settings changes, and WooCommerce orders, order status changes, account details and addresses.
* Optionally every page each logged-in user opens, on the site and in the admin.
* Filter by user, type and date, see IP address and device, and export to CSV.
* Only the super admin sees it, unless they allow other administrators.

**Hide & restrict, per role or per user**

* Admin menus and submenus (hidden screens cannot be opened by typing the address either).
* Admin bar tools.
* Dashboard widgets.
* Meta boxes and custom fields on the editing screens, including plugin boxes, and Advanced Custom Fields fields.
* Editor blocks (removed from the inserter).
* Editor sidebar panels: Summary, Permalink, Template, Categories, Tags, Featured image, Excerpt, Discussion, Page attributes.
* Editor features: code editor, block locking, block patterns, block directory, Openverse, template editing.

**Login page design**

* Five templates: Glass (frosted card over a background), Split screen (brand panel + form), Wave card (curved brand panel with centred logo), Image card (form + your picture) and Gradient (colourful panel, rounded inputs with icons).
* Your logo (with width), a page background image, a panel image, and a "darken images" slider so text stays readable.
* Colours (button, second colour, page, panel, panel text), font (system or Poppins, Inter, Montserrat, DM Sans from Google Fonts) and corners (square, rounded, pill).
* Your own text: panel headline, panel text, footer ({year} and {site} placeholders), and a title and subtitle for each screen: login, register, forgot password and reset password.
* Hide what you don't want: logo, headline, panel text, footer, titles, subtitles, "Remember me", "Lost your password?", "Register", "Go to site", privacy link, language switcher, the "or" divider, or the whole brand panel on phones.
* Forgot-password, reset-password and registration screens use the same template automatically, also on a custom login URL.
* Fully responsive: on phones the brand panel becomes a header (or hides), and nothing scrolls sideways.
* Desktop and phone previews on the settings screen, including a real reset-password preview.

**Front-end visibility per user tier**

* Every block gets a "Who can see this block" setting: everyone, only chosen roles (and/or logged-out visitors), or everyone except them.
* Classic sidebar widgets get the same setting.

== Installation ==

1. Install and activate the free Simple Login Page plugin.
2. Upload `simple-login-page-pro.zip` in Plugins > Add New > Upload Plugin and activate it.
3. Go to Simple Login > Pro license and enter your key.

== Frequently Asked Questions ==

= What happens when my license expires? =

Everything keeps working. You stop getting updates until you renew. If a license is refunded or revoked, the Pro features switch off.

= Can I move my license to another site? =

Yes. Click "Deactivate on this site" on the Pro license screen, then activate it on the new site. You can also remove sites from My Account > Licenses on laghavi.site.

= Do cached pages respect block visibility? =

Page caching plugins usually serve the same page to every logged-out visitor and skip the cache for logged-in users, so "logged-out visitors" rules and role rules both work. If your cache also serves logged-in users, exclude pages that use role-based blocks.

== Changelog ==

= 1.0.0 =
* First release: super admin, activity log, hide & restrict, front-end visibility, login page designer.
