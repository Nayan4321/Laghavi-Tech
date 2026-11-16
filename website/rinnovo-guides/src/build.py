"""Build the customer guide pages (HTML). Each page: a dropdown to switch to another product's guide,
a sticky list of the topics on the left (click = jump to that section), and the guide itself.
Run: python3 src/build.py"""
import html, os, re, sys
here = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, here)
from guides import G
out = os.path.dirname(here)
e = lambda t: html.escape(t, quote=False)
slugify = lambda t: re.sub(r'[^a-z0-9]+', '-', t.lower()).strip('-')

WORKS = {
 'Rinnovo': [('Sarala Login Page', 'https://laghavi.site/product/sarala-login-page-pro/', 'designed log-in, sign-up and account pages with social log-ins.'),
             ('Vacanam Language Switcher', 'https://laghavi.site/product/vacanam-language-switcher-pro/', 'your store, emails and invoices in the customer\'s language.'),
             ('Performance Insight for WooCommerce', 'https://wordpress.org/plugins/laghavi-performance-insight-for-woocommerce/', 'cookieless analytics and SEO, with every Rinnovo plugin\'s numbers.')],
 'Rinnovo (free plugin)': [('Rinnovo Pro plugins', 'https://laghavi.site/rinnovo-subscriptions-licenses/', 'trials, VAT/GST, invoices, boxes, memberships, media, contracts, classes and bundles.'),
             ('Sarala Login Page', 'https://wordpress.org/plugins/sarala-login-page/', 'designed log-in and sign-up pages with social log-ins, for your customers\' accounts.'),
             ('Performance Insight for WooCommerce', 'https://wordpress.org/plugins/laghavi-performance-insight-for-woocommerce/', 'cookieless analytics and SEO, with your MRR and renewals on the dashboard.')],
 'Sarala': [('Rinnovo Subscriptions & Licenses', 'https://wordpress.org/plugins/rinnovo-subscriptions-licenses/', 'sell subscriptions, memberships and license keys with WooCommerce.'),
            ('Performance Insight for WooCommerce', 'https://wordpress.org/plugins/laghavi-performance-insight-for-woocommerce/', 'cookieless analytics and SEO for your store.'),
            ('Vacanam Language Switcher', 'https://laghavi.site/product/vacanam-language-switcher-pro/', 'login and account pages in the visitor\'s language.')],
}

CSS = '''*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:84px}
body{margin:0;background:#f6f7f9;color:#1d1f23;font:16px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
a{color:#c2410c}
.bar{position:sticky;top:0;z-index:10;background:#120d33;color:#fff;box-shadow:0 2px 12px #0003}
.bar__in{max-width:1200px;margin:0 auto;padding:12px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.bar b{font-size:15px;letter-spacing:.02em}.bar b span{color:#fdba74}
.bar label{margin-left:auto;font-size:14px;color:#d6d3e8;display:flex;align-items:center;gap:8px}
.bar select{font:600 15px/1 inherit;padding:9px 12px;border-radius:9px;border:0;min-width:300px;max-width:100%;cursor:pointer}
.layout{max-width:1200px;margin:0 auto;padding:24px 20px 60px;display:grid;grid-template-columns:270px 1fr;gap:28px;align-items:start}
.toc{position:sticky;top:80px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 10px 12px;max-height:calc(100vh - 100px);overflow:auto}
.toc p{margin:0 10px 8px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7280}
.toc ol{list-style:none;margin:0;padding:0;counter-reset:t}
.toc li a{display:flex;gap:10px;padding:8px 10px;border-radius:9px;color:#374151;text-decoration:none;font-size:15px;line-height:1.35}
.toc li a::before{counter-increment:t;content:counter(t);flex:none;width:22px;height:22px;border-radius:50%;background:#f3f4f6;color:#6b7280;font:700 12px/22px inherit;text-align:center}
.toc li a:hover{background:#fff7ed;color:#c2410c}
.toc li a.on{background:#fff7ed;color:#c2410c;font-weight:600}.toc li a.on::before{background:#ea580c;color:#fff}
main{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:30px 36px;min-width:0}
.kicker{color:#ea580c;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.07em;margin:0}
h1{font-size:30px;line-height:1.2;margin:6px 0 10px}.intro{color:#4b5260;font-size:17px;margin:0 0 8px}
section{padding-top:8px}h2{font-size:21px;margin:26px 0 10px;padding-top:18px;border-top:1px solid #eef0f3}
h2 small{color:#9ca3af;font-weight:600;margin-right:8px}
p{margin:0 0 12px}ul,ol.steps{margin:0 0 14px;padding-left:22px}li{margin:0 0 6px}
table{width:100%;border-collapse:collapse;margin:6px 0 16px;font-size:15px}th,td{border:1px solid #e1e4ea;padding:8px 11px;text-align:left;vertical-align:top}th{background:#f6f7f9}
.top{display:inline-block;margin-top:6px;font-size:13px;color:#6b7280;text-decoration:none}
.works{margin-top:32px;padding:16px 18px;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px}.works ul{margin:6px 0 0}
@media (max-width:860px){.layout{grid-template-columns:1fr}.toc{position:static;max-height:none}main{padding:22px 18px}.bar select{min-width:0;width:100%}.bar label{margin-left:0;width:100%}}
@media print{.bar,.toc{display:none}.layout{display:block}main{border:0}}'''

JS = '''<script>
document.getElementById('guide-switch').addEventListener('change',function(){location.href=this.value;});
(function(){var links=[].slice.call(document.querySelectorAll('.toc a'));var secs=links.map(function(a){return document.getElementById(a.hash.slice(1));});
function mark(){var y=window.scrollY+120,cur=0;secs.forEach(function(s,i){if(s&&s.offsetTop<=y)cur=i;});links.forEach(function(a,i){a.classList.toggle('on',i===cur);});}
links.forEach(function(a,i){a.addEventListener('click',function(ev){ev.preventDefault();secs[i].scrollIntoView({behavior:'smooth',block:'start'});history.replaceState(null,'',a.hash);links.forEach(function(l){l.classList.remove('on');});a.classList.add('on');});});
window.addEventListener('scroll',mark,{passive:true});mark();})();
</script>'''

def code_box(label, text):
    return (f'<div class="code"><div class="code__bar"><span>{e(label)}</span><button type="button" class="code__copy">Copy</button></div>'
            f'<pre><code>{html.escape(text, quote=False)}</code></pre></div>')

def block(b):
    if isinstance(b, str):
        return f'<p>{e(b)}</p>'
    if b[0] == 'h3':
        return f'<h3>{e(b[1])}</h3>'
    if b[0] == 'note':
        return f'<p class="note">{e(b[1])}</p>'
    if b[0] == 'code':
        return code_box(b[1], b[2])
    if b[0] == 'tabs':
        tabs = ''.join(f'<button type="button" class="tabs__btn{" is-on" if i == 0 else ""}" data-tab="{i}">{e(lbl)}</button>' for i, (lbl, _) in enumerate(b[1]))
        panes = ''.join(f'<div class="tabs__pane"{"" if i == 0 else " hidden"} data-pane="{i}">{code_box(lbl, txt)}</div>' for i, (lbl, txt) in enumerate(b[1]))
        return f'<div class="tabs"><div class="tabs__bar" role="tablist">{tabs}</div>{panes}</div>'
    if b[0] == 'ul':
        return '<ul>' + ''.join(f'<li>{e(i)}</li>' for i in b[1]) + '</ul>'
    if b[0] == 'ol':
        return '<ol class="steps">' + ''.join(f'<li>{e(i)}</li>' for i in b[1]) + '</ol>'
    return '<table><tr>' + ''.join(f'<th>{e(h)}</th>' for h in b[1]) + '</tr>' + ''.join('<tr>' + ''.join(f'<td>{e(c)}</td>' for c in r) + '</tr>' for r in b[2]) + '</table>'

def switcher(cur):
    groups = {}
    for g in G:
        groups.setdefault(g.get('family', 'Rinnovo'), []).append(g)
    s = '<select id="guide-switch" aria-label="Open another guide">'
    for fam, items in groups.items():
        s += f'<optgroup label="{e(fam)}">' + ''.join(f'<option value="{g["slug"]}.html"{" selected" if g["slug"] == cur else ""}>{e(g["title"])}</option>' for g in items) + '</optgroup>'
    return s + '</select>'

if __name__ == '__main__':
    for g in G:
        fam = g.get('family', 'Rinnovo')
        ids = [slugify(h) for h, _ in g['sections']]
        toc = '<nav class="toc" aria-label="In this guide"><p>In this guide</p><ol>' + ''.join(f'<li><a href="#{i}">{e(h)}</a></li>' for i, (h, _) in zip(ids, g['sections'])) + '</ol></nav>'
        body = f'<p class="kicker">{e(g["product"])}</p><h1>{e(g["title"])}</h1><p class="intro">{e(g["intro"])}</p>'
        for n, (i, (h, blocks)) in enumerate(zip(ids, g['sections']), 1):
            body += f'<section id="{i}"><h2><small>{n}</small>{e(h)}</h2>' + ''.join(block(b) for b in blocks) + '<a class="top" href="#top">↑ Back to top</a></section>'
        body += '<div class="works"><b>Works great with</b><ul>' + ''.join(f'<li><a href="{u}">{e(n)}</a>: {e(t)}</li>' for n, u, t in WORKS[fam]) + '</ul></div>'
        page = f'''<!DOCTYPE html>
    <html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>{e(g["title"])}</title><style>{CSS}</style></head>
    <body id="top"><header class="bar"><div class="bar__in"><b>Laghavi <span>guides</span></b><label for="guide-switch">Guide for {switcher(g["slug"])}</label></div></header>
    <div class="layout">{toc}<main>{body}</main></div>
    {JS}
    </body></html>
    '''
        open(os.path.join(out, g['slug'] + '.html'), 'w').write(page)
    # Start page = first guide.
    idx = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" /><meta http-equiv="refresh" content="0; url=rinnovo-guide-shared.html" /><title>Guides</title></head><body><a href="rinnovo-guide-shared.html">Open the guides</a></body></html>\n'
    open(os.path.join(out, 'index.html'), 'w').write(idx)
    if os.path.exists(os.path.join(out, 'all-guides.html')):
        os.remove(os.path.join(out, 'all-guides.html'))
    print('built', len(G))
