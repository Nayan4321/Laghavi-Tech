"""Build the seven Rinnovo Pro product pages (same structure for each) and the index. Run: python3 src/build.py"""
import html, json, os, sys
here = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, here)
from content import PROS, PRICES, VERSIONS, SHARED, WORKS_WITH

out_dir = os.path.dirname(here)
head = open(os.path.join(here, 'head.html')).read()
script = open(os.path.join(here, 'script.html')).read()
images = json.load(open(os.path.join(here, 'images.json')))
e = lambda t: html.escape(t, quote=False)

def box(title, small, body, copy=None):
    btn = f'<button data-copy="{copy}">Copy</button>' if copy else ''
    cid = f' id="{copy}"' if copy else ''
    return f'''	<div class="box">
		<div class="box__head"><div><b>{title}</b><small>{small}</small></div>{btn}</div>
		<div class="content"{cid}>
{body}
		</div>
	</div>
'''

def ul(items):
    items = [i.replace(' & ', ' &amp; ') for i in items]
    return '\t\t\t<ul>\n' + ''.join(f'\t\t\t\t<li>{i}</li>\n' for i in items) + '\t\t\t</ul>\n'

others = lambda slug: [(s, p) for s, p in PROS.items() if s != slug]

for slug, p in PROS.items():
    name = p['name']
    p1, p5, pu = PRICES[slug]
    long = f'\t\t\t<h2>{e(p["h2"])}</h2>\n\t\t\t<p>{e(name)} is the <strong>{e(p["branch"].lower())}</strong> branch of the Rinnovo family. {e(p["tagline"])}</p>\n'
    for h, items in p['sections']:
        long += f'\n\t\t\t<h3>{e(h)}</h3>\n' + ul(items)
    long += '\n\t\t\t<h3>Included in every Rinnovo Pro</h3>\n' + ul(SHARED)
    long += '\n\t\t\t<h3>Works great with</h3>\n' + ul([f'<strong>{e(n)}</strong>: {e(t)}' for n, t in WORKS_WITH])
    long += '\n\t\t\t<h3>The Rinnovo family</h3>\n\t\t\t<p>Seven Pro plugins, each a full product on its own and each working with the others: ' + ', '.join(
        f'<a href="https://laghavi.site/product/{s}/">{e(o["short"])}</a> ({e(o["branch"].lower())})' for s, o in others(slug)) + '.</p>\n'
    long += ('\n\t\t\t<h3>Your license</h3>\n' + ul([
        'Choose 1 site, 5 sites or unlimited sites, billed yearly.',
        'Automatic updates from your WordPress dashboard and email support.',
        'Your license key and download in My Account right after paying. The download holds two plugins: this Pro and the Rinnovo Box Builder, shared by all Rinnovo Pros.',
        'The customer guide in your account while your license is active.',
        'Cancel any time: your license keeps working until the end of the year you paid for.']))
    long += '\n\t\t\t<h3>Requirements</h3>\n\t\t\t<p>WordPress 6.0 or later, WooCommerce 8.0 or later, PHP 7.4 or later, and the free Rinnovo Subscriptions &amp; Licenses plugin. Works on its own or next to the other Rinnovo Pro plugins.</p>'
    short = ''.join(f'\t\t\t<p>{e(t)}</p>\n' for t in p['short_desc']).rstrip('\n')
    img = images.get(slug + '.html', {}).get('imgs', [])
    img_rows = ''.join(f'\t\t\t\t<tr><td>{n}</td><td><code>{f}</code><br />Alt: {a}</td></tr>\n' for n, f, a in img)
    seo_title, focus, meta = p['seo']
    page = head.replace('{title}', e(p['short']) + ' product page') + '<body>\n<div class="wrap">\n'
    page += f'\t<h1>{e(name)} – product page</h1>\n\t<p class="lead">For <code>laghavi.site/product/{slug}/</code>. Click <b>Copy</b>, then paste into the WooCommerce editor\'s <b>Visual</b> tab (not the Text/Code tab). Headings and lists keep their formatting.</p>\n\n'
    page += box('Product name', 'The title at the top of the product', f'\t\t\t<p>{e(p.get("full_name", name))}</p>')
    page += box('Product short description', 'The box under the main editor, called "Product short description"', short, 'short')
    page += box('Product description', 'The main editor at the top of the product', long, 'long')
    page += box('Prices', 'Product data: Variable product, attribute "Sites" (used for variations), one variation per row. In each variation tick Subscription, every 1 year, Number of payments 0, and License with the sites per key.',
        f'''			<table>
				<tr><td>1 site</td><td><b>${p1}</b> / year · License: 1 site per key</td></tr>
				<tr><td>5 sites</td><td><b>${p5}</b> / year · License: 5 sites per key</td></tr>
				<tr><td>Unlimited sites</td><td><b>${pu}</b> / year · License: 0 (unlimited)</td></tr>
				<tr><td>License tab</td><td>Slug <code>{slug}</code>, version {VERSIONS[slug]}. <b>Plugin zip</b> (used for automatic updates): <code>{slug}.zip</code>. <b>Download package</b> (what customers download): <code>{slug}-package.zip</code> (the Pro zip + <code>rinnovo-box-builder.zip</code>).</td></tr>
				<tr><td>Guide</td><td>Guides › Guide categories: create "{e(p["guide"])}" and pick this product under "Only for customers with a license for". Add the guide as a Guide in that category.</td></tr>
			</table>''')
    page += box('Images', 'Product image = 01. Product gallery = the rest, in this order. Alt text: Media Library › image › Alternative Text.', f'\t\t\t<table>\n{img_rows}\t\t\t</table>')
    page += box('SEO (Yoast)', 'The Yoast box at the bottom of the product', f'''			<table>
				<tr><td>SEO title</td><td>{e(seo_title)}</td></tr>
				<tr><td>Slug</td><td>{slug}</td></tr>
				<tr><td>Meta description</td><td>{e(meta)}</td></tr>
				<tr><td>Focus keyphrase</td><td>{e(focus)}</td></tr>
			</table>''')
    page += '\n</div>\n' + script
    open(os.path.join(out_dir, slug + '.html'), 'w').write(page)

rows = ''.join(f'<tr><td><a href="{s}.html">{e(p["name"])}</a></td><td>{e(p["branch"])}</td><td>${PRICES[s][0]}</td><td>${PRICES[s][1]}</td><td>${PRICES[s][2]}</td></tr>\n' for s, p in PROS.items())
index = f'''<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>Rinnovo product pages</title>
<style>body{{margin:0;background:#eef0f4;color:#1d1f23;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}}.wrap{{max-width:980px;margin:0 auto;padding:28px 16px 60px}}table{{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden}}th,td{{padding:10px 14px;border-top:1px solid #dfe2e8;text-align:left}}th{{background:#f6f7f9;font-size:13px;text-transform:uppercase;letter-spacing:.05em}}a{{color:#ea580c}}</style></head>
<body><div class="wrap"><h1>Rinnovo product pages</h1><p>Seven Pro plugins, each an equal branch of the Rinnovo family and each sold on its own. One WooCommerce product per plugin: open a page, copy each part into the product, and add the images from the matching folder of <code>rinnovo-product-images.zip</code>. Each product is a variable product with the attribute "Sites": 1 site, 5 sites, Unlimited sites, billed yearly.</p>
<table><tr><th>Plugin</th><th>Branch</th><th>1 site</th><th>5 sites</th><th>Unlimited</th></tr>
{rows}</table><p>Every Pro download is a package with two zips: the Pro plugin and the Rinnovo Box Builder. Upload the plugin zip as "Plugin zip" (automatic updates) and the package as "Download package" on the product's License tab.</p><p>The landing page (<code>website/rinnovo-subscriptions-licenses.html</code>) links to each product at <code>laghavi.site/product/&lt;slug&gt;/</code>.</p></div></body></html>
'''
open(os.path.join(out_dir, 'index.html'), 'w').write(index)
print('built', len(PROS), 'pages')
