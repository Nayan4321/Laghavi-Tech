=== Sarala Login Page Pro ===
Contributors: nayan4321
Tags: super admin, activity log, admin menu editor, hide blocks, user roles
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pro add-on for Sarala Login Page: login redirects, a login widget, profile pictures, a protected super admin, an activity log and per-role control.

== Description ==

Requires the free **Sarala Login Page** plugin. Buy a license at [laghavi.site](https://laghavi.site/).

**Login redirects**

* Choose the page each role goes to after logging in and after logging out (for example admins to the dashboard, customers to My Account), with a fallback for everyone else.
* Full addresses or paths, with {user_login} and {user_id} placeholders.
* Optionally keep a page the visitor was on their way to.
* Works with password logins, Sarala's social logins, WooCommerce and the login widget.

**Login widget**

* A login box for any sidebar (Appearance > Widgets) or anywhere with `[sarala_login_widget]`.
* AJAX login: no page reload, errors shown in the box. Respects login limits, password expiry and the security question.
* Social login buttons, "Remember me", lost password and register links, each optional.
* Your own HTML for logged-in users with placeholders such as {avatar}, {display_name} and {logout_url}, and your own CSS.

**Profile pictures**

* Users upload their own picture on Users > Profile and WooCommerce My Account > Account details. It replaces Gravatar everywhere WordPress shows an avatar.

**Disconnecting social accounts**

* Choose who may disconnect a connected social account: users and admins, admins only, or the site owner only.
* Stops users from disconnecting their only social login when they never set a password.
* Bulk action on the Users screen to disconnect social accounts, with each disconnection in the activity log.

**Super admin**

* The admin who activates Pro becomes the super admin (you can hand it over later).
* Only the super admin can add or promote administrators. Add one from the Super admin screen by email.
* Other administrators cannot edit, delete or demote the super admin or each other, and cannot deactivate Sarala Login Page.
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

1. Install and activate the free Sarala Login Page plugin.
2. Upload `sarala-login-page-pro.zip` in Plugins > Add New > Upload Plugin and activate it.
3. Go to Sarala Login > Pro license and enter your key.

== Frequently Asked Questions ==

= What happens when my license expires? =

Everything keeps working. You stop getting updates until you renew. If a license is refunded or revoked, the Pro features switch off.

= Can I move my license to another site? =

Yes. Click "Deactivate on this site" on the Pro license screen, then activate it on the new site. You can also remove sites from My Account > Licenses on laghavi.site.

= Do cached pages respect block visibility? =

Page caching plugins usually serve the same page to every logged-out visitor and skip the cache for logged-in users, so "logged-out visitors" rules and role rules both work. If your cache also serves logged-in users, exclude pages that use role-based blocks.

== Changelog ==

= 1.1.0 =
* New: login redirects per role, after login and after logout.
* New: Sarala Login widget and [sarala_login_widget] shortcode with AJAX login, custom HTML and CSS.
* New: custom profile pictures (avatars) on the profile and WooCommerce account pages.
* New: rules for disconnecting social accounts, a Users bulk action, and activity log entries.
* Needs Sarala Login Page 1.1.0 or later.

= 1.0.2 =
* The activity log and other Pro features no longer stop when the daily license check cannot match this site: Pro activates the site again by itself and shows a notice if that fails.
* The license uses the site address from Settings > General, so language plugins that change the address (for example /ar/) no longer confuse it.

= 1.0.1 =
* Works with Sarala Login Page 1.0.1 and later (its new code prefix). Update both plugins together.

= 1.0.0 =
* First release: super admin, activity log, hide & restrict, front-end visibility, login page designer.
