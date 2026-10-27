=== Manohara Menu Design ===
Contributors: nayan4321
Tags: mobile menu, menu design, responsive menu, elementor menu, hamburger menu
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Animated menu designs for any theme and page builder. Give desktop, tablet and mobile their own menu and design, with your colours and fonts.

== Description ==

Manohara Menu Design gives your site's menu a modern look without touching your theme. Choose a ready-made template, set your colours and font, and decide what each device shows.

**A different design on every device**

* Keep your theme's desktop menu and only change the mobile menu – or the other way round.
* Each device (desktop, tablet, mobile) is set on its own: keep the theme's design, or choose a Manohara template.
* Each device can even show a different menu: a short menu on phones, the full one on desktop.
* "Copy settings from" copies one device's setup to another in one click.
* Devices are told apart by screen width in CSS, so it works with every page cache.

**7 free templates**

* **Slide-down panel** – the menu slides down under the header, with buttons on top, round arrows for submenus and a row of extra links below.
* **Full-screen overlay** – the menu fills the screen with a smooth fade; submenus slide in with a back button.
* **Dropdown list** – a centred list drops down under the header, with dividers and a highlighted last item.
* **Side drawer** – a panel slides in from the side over a dimmed page.
* **Classic** – items side by side with an animated underline and smooth dropdowns.
* **Pills** – rounded buttons with the current page highlighted.
* **Vertical list** – a stacked menu for sidebars and footers.

**Make it yours**

* 12 colours: header bar, menu background, text, hover, accent, submenu arrows, lines, extra links, dimmed page.
* Font (your theme's font, a font your site already loads, or a built-in font stack – nothing is loaded from outside), text size, weight, letter spacing, upper case, spacing and roundness.
* Animation speed and movement (smooth, springy, ease, even).
* Use different colours and fonts on one device only.
* A header bar with your logo (or another image), an optional search button, stays at the top when scrolling.
* Live preview of desktop, tablet and mobile while you design.

**Works everywhere**

* **Theme menu location** – the design replaces your theme's menu where you choose, on the devices you choose.
* **Elementor** – the "Manohara menu" widget.
* **Block editor and site editor** – the "Manohara menu" block.
* **Divi, Beaver Builder, Bricks, Oxygen, WPBakery and others** – the `[manohara_menu id="1"]` shortcode.
* **PHP** – `<?php manohara_menu( 1 ); ?>` in a theme template.

**Light and fast**

* No jQuery and no libraries: one small script (about 7 KB) and only the styles of the templates you use.
* Nothing is loaded on pages where no design shows.
* Smooth animations use CSS transforms; visitors who ask their device for less motion get instant menus.
* Keyboard friendly: Esc closes, focus stays inside full-screen menus, ARIA labels on every button.
* Works in right-to-left languages.

**Coming in Manohara Menu Design Pro**

More templates, icons on menu items (WordPress icons or your own SVG, PNG or AVIF images, also icon-only items) and mega menus.

== Installation ==

1. Install and activate the plugin.
2. Go to **Appearance > Manohara Menu** and click **Add a menu design**.
3. In **General**, choose the theme menu location the design applies to (or leave it empty to place it with the widget, block or shortcode).
4. Open **Desktop**, **Tablet** and **Mobile**: keep the theme design or choose a template for each.
5. Set colours, typography and animation, and click **Save changes**.

== Frequently Asked Questions ==

= Can I change only the mobile menu? =

Yes. Leave Desktop and Tablet on "Theme design" and choose a template for Mobile. Your theme's menu stays as it is on the larger screens.

= Can each device show a different menu? =

Yes. Each device has its own "Menu on this device" setting. Make the menus in Appearance > Menus.

= My theme's header still shows next to the new header bar. =

When a device uses its own header bar, the plugin hides the theme's header on that device. The common themes are covered; for others, add your theme's header selector (for example `.site-header`) in Appearance > Manohara Menu > Your theme's header. Or choose "In the theme's header, where its menu is" for the menu button.

= Does it work with page caching? =

Yes. All devices are in the page and CSS shows the right one by screen width, so cached pages are the same for everyone.

= Where do I set the screen sizes of tablet and mobile? =

In Appearance > Manohara Menu > Screen sizes. By default mobile is up to 767 px and tablet up to 1024 px.

= Does it load fonts or anything from other websites? =

No. The plugin does not connect to any external service and does not load fonts from outside your site.

== Screenshots ==

1. Slide-down panel on mobile with buttons, round arrows and extra links.
2. Full-screen overlay menu.
3. Dropdown list with a highlighted last item.
4. The editor: sections on the left, templates and options in the middle, live preview on the right.
5. The list of designs, each with its design per device.

== Changelog ==

= 1.0.0 =
* First release: 7 templates, a design per device, a menu per device, colours, typography, animation, live preview, theme location, Elementor widget, block and shortcode.
