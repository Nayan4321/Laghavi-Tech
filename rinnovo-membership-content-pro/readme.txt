=== Rinnovo Membership Content Pro ===
Contributors: nayan4321
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Memberships for Rinnovo Subscriptions & Licenses: members-only content with tiers, drip, paywalls, protected files and video, forums and courses, directory, plan switching, perks, teams and sharing limits.

== Description ==

Requires the free **Rinnovo Subscriptions & Licenses** plugin and WooCommerce. Works next to the other Rinnovo add-ons. Buy a license at [laghavi.site](https://laghavi.site/rinnovo-membership-content-pro/).

= Tiers that build on each other =

* Create tiers in WooCommerce > Membership (for example Silver, Gold, All-Access) and choose the WooCommerce products that give each one: subscriptions, variations (Monthly / Yearly) or one-time purchases (lifetime access).
* Higher tiers include everything of the lower ones. "Free" (any registered user) is built in.

= Rules for any content =

* A "Members only" box on pages, posts, products and every custom post type.
* Rules on categories, tags and any other taxonomy; rules for a whole content type (all courses, all episodes).
* Child pages follow their parent.
* Parts of a page: the "Members only" block, the CSS class `members-only` (or `members-only-gold`) on any block, or `[members_only tier="gold"]…[/members_only]`. `[non_members]…[/non_members]` shows something only to visitors without a membership.

= Forums, communities and courses =

* bbPress and BuddyBoss forums: topics and replies follow their forum; non-members are sent to the paywall.
* BuddyPress / BuddyBoss: make the activity feed, member profiles, groups, forums or messages members-only.
* LearnDash and LifterLMS: lessons follow their course, and members are enrolled in a tier's courses automatically (and unenrolled when it ends).

= Protected files =

* Choose "Members only" on a file in the Media Library: it moves to a protected folder and is served only to members, so guessed or shared links don't work. Audio and video can still seek.
* `[member_file id="123"]` and `[member_library]` (the member's digital library). Works on Apache, LiteSpeed and Nginx (rule shown in the settings), with optional X-Sendfile / X-Accel-Redirect.

= Private video =

* `[member_video provider="bunny" id="…"]`, also Vimeo, Wistia, YouTube, Presto Player and protected files. The player is only put in the page for members.
* Bunny Stream links are signed and expire after 4 hours. Vimeo and Wistia work with their domain-level privacy.

= Drip =

* Unlock content N days after the member joined (for example module 2 after 7 days), or on a date. Works on posts, categories, blocks, shortcodes and videos.
* Switching plans keeps the member's start date.

= Teasers and paywalls =

* Non-members see an excerpt, the first words or a blurred preview (never more than a third of the content), then a "Subscribe to unlock" box with the price, linked to the tier's product or your pricing page, and a log-in link.
* The rest of the content never reaches the browser: not in feeds, excerpts or the REST API either.

= Member directory and badges =

* `[member_directory]`: members who choose to be listed (or all members), with search and profile pages (headline, bio, website, community profile link).
* Badges for the tier, "New member" and years of membership, next to names in comments, bbPress and BuddyPress / BuddyBoss profiles.

= Upgrades and downgrades =

* Members switch between tiers, or between monthly and yearly, from My Content. The normal WooCommerce checkout is used, with any payment method.
* The unused part of the current period is credited on the first payment of the new plan, and the old subscription ends when the new one starts.
* Buying another plan replaces the current one, so nobody pays twice.

= Member perks in the store =

* A discount per tier, on everything or on chosen categories, with "Member price" shown to members and "Members save 20%" to everyone else.
* Free shipping, and free bonus products (one of each per order).

= Team memberships =

* Give a product "Team seats". The buyer (a school, company or family) invites people by email from My Content.
* Team members have the tier while the buyer's subscription is active; the buyer can remove them, and they can leave.

= Access ends at once =

* Access is checked live: cancelled or refunded memberships lose it at once (a cancellation keeps the period already paid for). A failed payment keeps access for a grace period you choose, then removes it.
* Roles and course enrollments follow automatically.

= No account sharing =

* At most N devices logged in at once (a new log-in signs out the oldest), and at most N videos playing at the same time per account.

= My Content =

* One page in My Account with everything in one place:
  * membership and status, with the renewal date or the date access ends
  * plan changes, with the credit for unused time
  * the team
  * all unlocked content, plus what unlocks soon and when
  * courses and the files library
  * perks, billing and the directory profile

= Access by hand =

* Give a tier to anyone for a set time (press, partners, comps) and see every member with where their access comes from.

== Installation ==

1. Install and activate WooCommerce and the free Rinnovo Subscriptions & Licenses plugin.
2. Upload and activate Rinnovo Membership Content Pro.
3. Go to WooCommerce > Membership Pro license, paste your license key and click Activate.
4. Create your tiers in WooCommerce > Membership, then set "Members only" on your content.

== External services ==

* **License server (laghavi.site):** to activate your key, check it daily and look for updates, this plugin sends your license key, your site's address, the plugin's name and version to https://laghavi.site/. [Terms](https://laghavi.site/terms-and-conditions/)
* **Video hosts:** members' browsers load videos from the host you choose (Bunny Stream, Vimeo, Wistia, YouTube) when you use [member_video]. Nothing is sent by the plugin itself.

== Changelog ==

= 1.0.0 =
* First release.
