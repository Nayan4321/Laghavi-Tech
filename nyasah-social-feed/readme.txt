=== Nyasah Social Feed ===
Contributors: nayan4321
Tags: social feed, instagram feed, youtube gallery, tiktok feed, facebook feed
Requires at least: 6.3
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

YouTube, Instagram, Facebook, Threads, TikTok, X, Pinterest, LinkedIn, Snapchat and Reddit feeds in one plugin. Every language, RTL too.

== Description ==

**Tired of installing a different feed plugin for every social network?** Nyasah Social Feed shows all of them, with one look, one settings screen and one shortcode style.

= Every network in one plugin =

* **YouTube** – a channel, a playlist or chosen videos. Works without any key (latest 15 videos); add a free API key for up to 50 videos with views and likes.
* **Instagram** – your Business or Creator account: photos, videos, carousels, likes and comments.
* **Facebook** – your Page’s posts, or chosen public posts from your profile or page.
* **Threads** – your profile’s posts.
* **TikTok** – your account’s videos, or any public videos you choose (no account needed).
* **X (Twitter)** – an account’s posts (X API), or any public posts you choose (no account needed).
* **Pinterest** – a profile’s or a board’s latest Pins (no account needed).
* **LinkedIn** – your company page’s posts, or chosen public posts from your profile or page.
* **Snapchat** – your public Spotlights, Stories or profile.
* **Reddit** – the posts a user shared, or a community’s newest posts (no account needed).

= Layouts and design =

* **Grid, Masonry, List and Carousel** layouts.
* Separate column numbers for desktop, tablet and phone. The feed adapts to the space it is in, so it also looks right in a sidebar.
* Picture shape (square, portrait, landscape or original), spacing, rounded corners and colours.
* Text below the picture, or on the picture on hover.
* Profile header with picture, name, followers and bio.
* “Follow” and “Load more” buttons.
* **Popup viewer**: photos, galleries and videos open on your page (YouTube, TikTok, Facebook and Instagram videos play right there), with arrows to go through the feed.
* Likes, comments, views and dates.

= Made for multilingual sites =

* Fully translation-ready; buttons use your site’s language automatically.
* **Right-to-left languages** (Arabic, Hebrew, Persian, Urdu) are built in: spacing, arrows, carousels and popups follow the reading direction, so nothing loses its padding.
* Posts in a different language than the page read correctly (each text gets its own direction).
* Works with Vacanam, WPML, Polylang and TranslatePress: put a feed on each language’s page.

= Easy to place =

* Shortcode: `[nyasah-feed id="12"]`
* Block for the block editor, Elementor widget and classic widget.
* Live preview while you design the feed, with desktop, tablet, phone and right-to-left views.

= Fast and reliable =

* Posts are cached on your site (you choose how often to check for new ones).
* If a network cannot be reached, the last good copy keeps showing for up to 30 days.
* Styles and scripts load only on pages that show a feed. No jQuery.
* Instagram and Threads tokens are renewed automatically; TikTok tokens with your refresh token.

= What each network allows =

Some networks only let apps read your own account, and some do not offer feeds at all. Nyasah always offers what the network allows:

* Facebook and LinkedIn do not let any app read a **personal profile’s** timeline: choose the public posts to show instead.
* X’s API for reading posts is part of X’s paid plans: without one, choose the public posts to show.
* Snapchat has no feed for apps: choose the public Spotlights, Stories or profile to show.

== Installation ==

1. Install and activate the plugin.
2. Open **Social Feeds › Add feed**, choose a network and what to show, and design it with the live preview.
3. For Instagram, Facebook Pages, Threads, TikTok accounts, X accounts and LinkedIn company pages, first connect the account in **Social Feeds › Accounts** (step-by-step instructions are on that screen).
4. Copy the shortcode, or add the “Social Feed” block, Elementor widget or widget to any page.

== Frequently Asked Questions ==

= Can I show several social networks on one site? =

Yes, that is what Nyasah is for. Create one feed per network (or per account) and place each one where you want.

= Do I need an account or API key? =

Not for Pinterest, Reddit, Snapchat, YouTube (latest 15 videos) and chosen posts from Facebook, TikTok, X and LinkedIn. Instagram, Facebook Pages, Threads, TikTok accounts, X accounts and LinkedIn company pages need an access token from that network; the Accounts screen explains how to get each one.

= Can I show my personal Facebook or LinkedIn profile? =

Neither network lets apps read personal profiles. Paste the addresses of the public posts you want to show and Nyasah displays them with the network’s own embedded post.

= Does it work in Arabic and other right-to-left languages? =

Yes. The whole design uses direction-aware spacing, so padding, arrows and carousels are correct in right-to-left languages. You can check it in the editor’s preview with the “Right-to-left” option.

= Will the feed slow down my site? =

No. Posts are fetched in the background of the first visit after the cache expires and kept on your site; pictures load lazily and the script is small.

= Something is wrong with my feed. What do I check? =

Logged-in administrators see the network’s error message in place of the feed (visitors never do). Check the account or the name you typed, then use “Fetch all feeds again” on the feeds list.

== External services ==

Nyasah Social Feed connects to a social network only to show a feed you created for that network. Requests are made from your server when a feed is shown and its cache has expired (and in the editor preview). Pictures and embedded posts are then loaded by visitors’ browsers from the network.

**YouTube (Google)** – for YouTube feeds: the channel, playlist or video IDs you entered, and your API key when you added one, are sent to the YouTube Data API (googleapis.com) or YouTube’s public feed and oEmbed (youtube.com). Videos play from youtube-nocookie.com. [Terms](https://www.youtube.com/t/terms), [Privacy](https://policies.google.com/privacy).

**Instagram and Facebook (Meta)** – for Instagram and Facebook feeds: your access token and account or Page ID are sent to graph.instagram.com or graph.facebook.com; chosen Facebook posts are shown with Facebook’s embedded post (facebook.com/plugins). [Terms](https://www.facebook.com/terms.php), [Privacy](https://www.facebook.com/privacy/policy/), [Platform terms](https://developers.facebook.com/terms/).

**Threads (Meta)** – for Threads feeds: your access token is sent to graph.threads.net. [Terms](https://help.instagram.com/769983657850450), [Privacy](https://help.instagram.com/515230437301944).

**TikTok** – for TikTok feeds: your access token (and, to renew it, your client key, client secret and refresh token) is sent to open.tiktokapis.com; chosen video addresses are sent to TikTok’s oEmbed (tiktok.com). Videos play from tiktok.com. [Terms](https://www.tiktok.com/legal/terms-of-service), [Privacy](https://www.tiktok.com/legal/privacy-policy).

**X (Twitter)** – for X feeds: the username and your bearer token are sent to api.x.com; chosen post addresses are sent to X’s oEmbed (publish.twitter.com). [Terms](https://x.com/tos), [Privacy](https://x.com/privacy).

**Pinterest** – for Pinterest feeds: the username or board is sent to Pinterest’s public RSS feed (pinterest.com). [Terms](https://policy.pinterest.com/terms-of-service), [Privacy](https://policy.pinterest.com/privacy-policy).

**LinkedIn** – for LinkedIn feeds: your access token and organization ID are sent to api.linkedin.com; chosen posts are shown with LinkedIn’s embedded post (linkedin.com/embed). [Terms](https://www.linkedin.com/legal/user-agreement), [Privacy](https://www.linkedin.com/legal/privacy-policy).

**Snapchat** – for Snapchat feeds: the public addresses you entered are opened on snapchat.com to read their picture and title; Spotlights play from snapchat.com. [Terms](https://snap.com/terms), [Privacy](https://values.snap.com/privacy/privacy-policy).

**Reddit** – for Reddit feeds: the username or community name is sent to Reddit’s public listing or RSS feed (reddit.com). [Terms](https://redditinc.com/policies/user-agreement), [Privacy](https://www.reddit.com/policies/privacy-policy).

Nothing is sent to Laghavi Digital.

== Screenshots ==

1. A feed in the grid layout with the profile header.
2. The feed editor with live preview.
3. Masonry, list and carousel layouts.
4. The popup viewer.
5. The same feed in Arabic (right-to-left).
6. Accounts screen with step-by-step guides.

== Changelog ==

= 1.0.0 =
* First release: YouTube, Instagram, Facebook, Threads, TikTok, X, Pinterest, LinkedIn, Snapchat and Reddit feeds; grid, masonry, list and carousel layouts; popup viewer; block, Elementor widget, widget and shortcode; right-to-left support.
