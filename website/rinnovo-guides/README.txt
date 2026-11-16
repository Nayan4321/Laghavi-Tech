Laghavi guides – Elementor HTML widgets

elementor/<guide-slug>.html is the code for one guide. On laghavi.site:
1. Guides > Add guide: title, slug (the file name), guide category (you choose).
2. Edit with Elementor > full-width container, no padding > HTML widget > paste the whole file.
3. Publish. The guide switcher links to laghavi.site/guides/<slug>/, so keep the slugs.

Free plugin guides (anyone with a free account can read them):
  rinnovo-subscriptions-licenses-free.html  ->  slug rinnovo-subscriptions-licenses-free
  rinnovo-license-keys.html                 ->  slug rinnovo-license-keys
Put both in one guide category (for example "Rinnovo free plugin guides"). In Guides > Guide categories,
edit that category, leave "Only for customers with a license for" empty and tick "Free guides".
Visitors who are not logged in see "Log in" and "Create a free account" (turn on account creation in
WooCommerce > Settings > Accounts & Privacy > "Allow customers to create an account on the My account page").

Rebuild after changing src/guides.py, src/free.py or src/setup.py: python3 src/elementor.py
