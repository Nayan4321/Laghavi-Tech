Laghavi guides – Elementor HTML widgets

elementor/<guide-slug>.html is the code for one guide. On laghavi.site:
1. Guides > Add guide: title, slug (the file name), guide category (you choose).
2. Edit with Elementor > full-width container, no padding > HTML widget > paste the whole file.
3. Publish. The guide switcher links to laghavi.site/guides/<slug>/, so keep the slugs.

Rebuild after changing src/guides.py: python3 src/elementor.py
