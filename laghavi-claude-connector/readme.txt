=== Laghavi Claude Connector ===
Contributors: nayan4321
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private tool by Laghavi Digital: lets Claude find and fix problems on a WordPress site through a secure, time-limited MCP connector.

== Description ==

Install it on a site, create a key in Tools > Claude Connector and add the connector address to Claude. Claude can then:

* Look: site overview, Site Health, error logs (debug.log, PHP error_log, WooCommerce fatal-error logs), pages checked for PHP errors, plugins, themes, files, read-only database queries, options, scheduled tasks and users.
* Fix (only with a "look and fix" key): update page texts, titles, SEO and status (for example translations), edit and delete files, trash posts, delete options, remove scheduled tasks, activate, deactivate and update plugins, change options, turn error logging on or off, clear caches.

Safety:

* The key is stored as an HMAC only, works until the end date you choose, can be limited to IP addresses and revoked at any time.
* "Look only" keys can't change anything: the fixing tools are listed but refuse to run until the key is switched to "look and fix".
* Every file is backed up before it changes, and PHP files are checked for syntax errors before they are saved: a broken edit is refused.
* WordPress core files, wp-config.php (except the logging settings) and the connector itself are never edited. Files outside the site can't be read.
* Passwords, salts and keys in wp-config.php and the users table are hidden.
* Every call is in the log on the settings screen, and administrators see a notice while the connector is on.

== Connecting ==

* Claude.ai or the Claude app: Settings > Connectors > Add custom connector, paste the address.
* Claude Code: `claude mcp add --transport http my-site "<address>"`

The site must use HTTPS.

== Changelog ==

= 1.2.1 =
* update_page also changes link, e-mail and phone targets and dynamic links in Elementor widgets (IDs and element types are never changed).

= 1.2.0 =
* New page tools: page_texts lists every text on a page (Elementor or editor), its SEO title and description and its translations; update_page replaces texts, title, SEO and status without touching the layout, with a backup first.
* restore_backup also restores page backups.
* Secrets, tokens and keys saved by plugins are hidden in get_option and db_query.
* clear_cache also clears WP-Optimize and LiteSpeed (Hostinger) caches.

= 1.1.0 =
* New fixing tools for cleaning a hacked site: delete files (backed up), move posts to the Trash, delete options (backed up), remove scheduled tasks.
* The fixing tools are always listed: switching a key to "look and fix" no longer needs a reconnect.
* fetch_page works on sites where a language plugin changes the home address.

= 1.0.0 =
* First version.
