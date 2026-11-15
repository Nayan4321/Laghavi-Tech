"""Build the Rinnovo customer guides: one HTML file per guide (paste the article part into a Guide post)
and print-ready pages for the PDFs. Run: python3 src/build.py"""
import html, os, sys
here = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, here)
from guides import G
out = os.path.dirname(here)
e = lambda t: html.escape(t, quote=False).replace('&lt;br&gt;', '<br>')

WORKS = ('<div class="works"><b>Works great with</b><ul>'
 '<li><a href="https://laghavi.site/product/sarala-login-page-pro/">Sarala Login Page</a>: designed log-in, sign-up and account pages with social log-ins.</li>'
 '<li><a href="https://laghavi.site/product/vacanam-language-switcher-pro/">Vacanam Language Switcher</a>: your store, emails and invoices in the customer\'s language.</li>'
 '<li><a href="https://laghavi.site/shop/">WooCommerce performance insights</a>: one dashboard for the store and every Rinnovo plugin.</li>'
 '</ul></div>')

CSS = '''body{margin:0;background:#fff;color:#1d1f23;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
.wrap{max-width:820px;margin:0 auto;padding:32px 22px 48px}
.kicker{color:#ea580c;font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.06em;margin:0}
h1{font-size:28px;margin:4px 0 8px}
.intro{color:#4b5260;font-size:16px;margin:0 0 20px}
h2{font-size:20px;margin:26px 0 8px;padding-top:6px;border-top:1px solid #e5e7eb}
p{margin:0 0 10px} ul,ol{margin:0 0 12px;padding-left:22px} li{margin:0 0 5px}
table{width:100%;border-collapse:collapse;margin:6px 0 14px;font-size:14px}
th,td{border:1px solid #e1e4ea;padding:7px 10px;text-align:left;vertical-align:top}
th{background:#f6f7f9}
.works{margin-top:30px;padding:14px 16px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px}
.works ul{margin:6px 0 0} a{color:#c2410c}
.toc{background:#f6f7f9;border-radius:10px;padding:10px 16px;margin:0 0 10px}
@media print{.wrap{padding:0 4px} h2{break-after:avoid} table,tr{break-inside:avoid}}'''

def block(b):
    if isinstance(b, str):
        return f'<p>{e(b)}</p>'
    if b[0] in ('ul', 'ol'):
        return f'<{b[0]}>' + ''.join(f'<li>{e(i)}</li>' for i in b[1]) + f'</{b[0]}>'
    if b[0] == 'table':
        return '<table><tr>' + ''.join(f'<th>{e(h)}</th>' for h in b[1]) + '</tr>' + ''.join('<tr>' + ''.join(f'<td>{e(c)}</td>' for c in r) + '</tr>' for r in b[2]) + '</table>'

def article(g):
    body = f'<p class="kicker">{e(g["product"])}</p><h1>{e(g["title"])}</h1><p class="intro">{e(g["intro"])}</p>'
    body += '<div class="toc"><b>In this guide</b><ol>' + ''.join(f'<li>{e(h)}</li>' for h, _ in g['sections']) + '</ol></div>'
    for h, blocks in g['sections']:
        body += f'<h2>{e(h)}</h2>' + ''.join(block(b) for b in blocks)
    return body + WORKS

def page(title, inner):
    return f'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>{e(title)}</title><style>{CSS}</style></head><body><div class="wrap">{inner}</div></body></html>\n'

allp = []
for g in G:
    a = article(g)
    open(os.path.join(out, g['slug'] + '.html'), 'w').write(page(g['title'], a))
    allp.append(a)
cover = '<p class="kicker">Laghavi Digital</p><h1>Rinnovo Pro customer guides</h1><p class="intro">One guide per Rinnovo Pro plugin, plus the parts every Pro shares. On laghavi.site each guide is in the Guides area and opens for customers with an active license.</p><ol>' + ''.join(f'<li>{e(g["title"])}</li>' for g in G) + '</ol>'
combined = cover + ''.join(f'<div style="break-before:page">{a}</div>' for a in allp)
open(os.path.join(out, 'all-guides.html'), 'w').write(page('Rinnovo Pro customer guides', combined))
print('built', len(G))
