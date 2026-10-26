=== Vacanam Language Switcher ===
Contributors: nayan4321
Tags: multilingual, language switcher, translation, rtl, arabic
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your site multilingual: Arabic and right-to-left support, one-click copies of Elementor and block pages, language switchers and multilingual SEO.

== Description ==

Vacanam (वचनम्, "speech") turns your WordPress site into a multilingual site. Your current pages stay where they are. Each new language gets its own copy of every page with the same design, connected as its translation. You only change the text.

Built for Arabic sites first: right to left everywhere, Arabic flags (Arab League, UAE, Saudi Arabia, Egypt, Qatar, Kuwait…) and Gulf variants of Arabic. It works the same way for 130+ languages.

= Setup in five steps =

1. **Languages**: Vacanam reads your home page, finds the language it is written in (English, Arabic, French…) and suggests it as the main language. Then pick the languages to add.
2. **Content**: everything you already have is marked as the main language.
3. **Copy your site**: every page, post, category and menu is copied into the new languages with a progress bar, **with its design**: blocks, Elementor (all widgets and settings), other page builders, custom fields, SEO texts and featured images. The copies are connected to their originals, the home page copy becomes the home page of its language, and links between pages lead to the pages of the same language.
4. **Language switcher**: added to your menu, or as a floating button.
5. **Ready**: open a copy, change the text, publish.

= Right to left (RTL) =

* Arabic, Hebrew, Persian, Urdu, Pashto, Kurdish, Sindhi, Uyghur, Dhivehi… are shown right to left: `<html dir="rtl">`, WordPress's RTL styles, and your theme's and Elementor's RTL layouts, even if WordPress's own translation of that language is not installed.
* English pages stay left to right on the same site.
* In the editor, Arabic pages are written right to left even when your admin is in English.
* Switchers use logical CSS: they mirror themselves in RTL.

= Translations connected the way you want =

* A **Languages** box in the editor (block editor and classic): the language of the page, its translations, a **+** to copy it into a language, and a search field to connect any existing page instead. Change the connected page any time.
* Pages lists show a flag column: ✓ the language of each page, ✎ edit a translation, + copy into a language. Bulk actions: copy many pages into a language, or set their language.
* Pages built with Elementor open straight in Elementor. In Elementor, the page settings show a **Languages** panel with the same links.
* Categories, tags and custom taxonomies have a language and translations too.
* Admin bar filter: show the content of one language or all.

= Language switchers that match your theme =

* **Menu item**: Appearance > Menus > "Language switcher", as a dropdown or side by side. It takes your theme's own menu style (and works in Elementor's Nav Menu widget).
* **Elementor widget**: dropdown, side by side, list, pills, flags only or select box; names, codes (EN, AR) or flags only; flag shape and size; colours for text, background, hover, current language and border; typography, padding, spacing, alignment, dropdown shadow; opens on click or hover.
* **Block** for the block editor and the site editor, **classic widget**, **shortcode** `[vacanam_switcher]` and **PHP** `vacanam_switcher()`.
* **Floating switcher** in a corner of every page (start or end side, so it mirrors in Arabic).
* One default look in Languages > Switcher, with a live preview; each switcher can set its own.
* The switcher leads to the translation of the page being read, or to the home page of the language (or hides it, as you prefer).

= SEO that does not break =

* **hreflang** links between translations and **x-default**, on pages, posts, categories, the home page and archives.
* **One address per page**: a page opened with the wrong language code gets a 301 redirect to its right address; the main language can keep its current addresses (no /en/), so existing rankings stay.
* **Canonical** addresses, **sitemaps** (all languages, each page at its own address), breadcrumbs and Open Graph follow the language with WordPress, **Yoast SEO**, **Rank Math** and **SEOPress**; `og:locale` and `og:locale:alternate` are set.
* `<html lang="ar" dir="rtl">` on every page.
* Copies never keep a canonical address pointing to the original page, so translations are never hidden from search engines.
* Each translation has its own SEO title, description and focus keyword (copied from the original, ready to translate).
* Search engines are never redirected by the browser-language option.

= All the multilingual features =

* **Addresses**: language folder (example.com/ar/page/) or parameter (?lang=ar); the main language with or without its code.
* **Browser language**: the home page in the visitor's language on the first visit; the choice is remembered.
* **Media**: translate titles, alt texts and captions of images (one file for all languages).
* **Custom post types and taxonomies**: WooCommerce products, portfolios, Elementor templates (headers, footers, popups per language)…
* **Synchronization**: categories, featured image, parent page, template, order, comments, sticky, post format, date and custom fields are kept the same across translations (texts and designs never are).
* **Menus per language**: one location per language in Appearance > Menus and the Customizer, menu copies, and Elementor menus swapped automatically. A menu used in every language even leads to the translated pages by itself.
* **Texts**: translate the site title, tagline, date formats, widget titles and texts registered by themes and plugins.
* **Widgets per language**: show a widget only in one language.
* **Hidden languages**: prepare a language while only editors can see it.
* **Theme compatibility**: themes and plugins written for Polylang (`pll__()`, `pll_the_languages()`, `pll_get_post()`…) or WPML (`wpml_object_id`…) work as they are.

= Vacanam Pro =

[Vacanam Language Switcher Pro](https://laghavi.site/vacanam-language-switcher/) is a separate add-on that translates pages, Elementor designs, SEO texts, categories, menus and texts with AI (Claude, ChatGPT or Gemini, with your own key) into as many languages as you need, and adds shared and translated slugs, and one domain or sub-domain per language.

== Installation ==

1. Install and activate the plugin from Plugins > Add New, or upload the folder to `/wp-content/plugins/`.
2. The setup opens: confirm your main language, add your other languages, and copy your site into them.
3. Translate the copies: open Pages, click the pencil of a language, change the text, publish.

== Frequently Asked Questions ==

= Will my current pages change address? =

No. With the default settings the main language has no language code: example.com/about/ stays example.com/about/. Translations get one: example.com/ar/about-ar/.

= Does it copy Elementor pages with their design? =

Yes. All Elementor data (sections, containers, widgets and their settings) is copied exactly. Links to your other pages inside the design lead to the copies in the same language, and Elementor template widgets use the template of the same language. Open the copy in Elementor and change the text.

= What about my header and footer made with Elementor? =

Elementor templates can be translated: copy the header into Arabic and visitors of the Arabic pages see the Arabic header. Menus inside them are swapped for their Arabic copy automatically. Or keep one header and add the Language switcher widget to it.

= Does it work with Yoast SEO and Rank Math? =

Yes. Each translation has its own SEO title and description; canonical addresses, sitemaps and Open Graph follow the language; hreflang links are added by Vacanam.

= My Arabic page is not right to left. =

Check that the language has "Written right to left" ticked in Languages > Edit. Vacanam sets the direction even when WordPress's Arabic translation is not installed. If your theme ignores `dir="rtl"`, use the body classes `vacanam-rtl` and `vacanam-ar` to adjust it.

= Can I connect a page I already translated by hand? =

Yes: in the Languages box of the editor, type its title in the field of its language and save. Clear the field to disconnect it.

= Can I use it with WooCommerce? =

Products and product categories can be translated: tick them in Languages > Settings, then copy them like pages.

= I used Polylang before. =

Vacanam provides Polylang's theme functions, so templates keep working. Deactivate Polylang before using Vacanam: only one translation plugin should be active.

= What happens when I delete the plugin? =

Pages and posts are never deleted. Settings and links between translations are removed only if you tick "When the plugin is deleted, also delete its settings" in Languages > Settings.

== External services ==

This plugin connects to no external service of its own. When you add a language, WordPress's own language-pack installer may download WordPress's translation of that language from WordPress.org (translate.wordpress.org), as when you change the site language in Settings > General. Only the language code is sent. [WordPress.org privacy policy](https://wordpress.org/about/privacy/).

== Credits ==

Flags: [flag-icons](https://github.com/lipis/flag-icons) by Panayiotis Lipiridis, MIT license (see assets/flags/LICENSE.txt).

== Screenshots ==

1. Setup: the main language is detected from your home page.
2. Copy your whole site into new languages, with the same designs.
3. Pages with a flag per language: edit or copy a translation in one click.
4. The Languages box in the editor.
5. Language switcher styles, colours and live preview.
6. Arabic page, right to left, with the menu language switcher.
7. Settings: addresses, SEO, synchronization.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
