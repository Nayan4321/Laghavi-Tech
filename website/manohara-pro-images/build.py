"""Build the 8 Manohara Menu Design Pro product images (1200 x 1200).
Usage: python3 build.py RAW_DIR OUT_DIR   (RAW_DIR holds the screenshots from the test site)."""
import base64, os, sys
RAW, OUT = sys.argv[1], sys.argv[2]
HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
FONT = '/tmp/claude-0/tr/site/wp-content/themes/twentytwentyfive/assets/fonts/manrope/Manrope-VariableFont_wght.woff2'
def data(path, mime='image/png'):
    return f'data:{mime};base64,' + base64.b64encode(open(path, 'rb').read()).decode()
raw = lambda n: data(os.path.join(RAW, n))
LOGO = data(os.path.join(REPO, 'sarala-login-page/assets/images/laghavi-logo.png'))
font = f"@font-face{{font-family:Manrope;src:url({data(FONT, 'font/woff2')}) format('woff2');font-weight:200 800}}" if os.path.exists(FONT) else ''
CSS = font + """
*{box-sizing:border-box;margin:0;padding:0}
body{width:1200px;height:1200px;overflow:hidden;font-family:Manrope,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#fff;
 background:radial-gradient(800px 500px at 100% 0%,rgba(245,158,11,.30),transparent 60%),radial-gradient(700px 600px at 0% 100%,rgba(20,184,166,.28),transparent 60%),linear-gradient(160deg,#04201d,#073a35 55%,#0a2f2b)}
.s{position:absolute;inset:0;padding:64px 64px 0}
.pill{display:inline-block;font-size:17px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;padding:9px 18px;border-radius:999px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.22)}
h1{font-size:74px;line-height:1.04;font-weight:800;letter-spacing:-.025em;margin-top:22px}
h1 em{font-style:normal;background:linear-gradient(90deg,#fbbf24,#f97316 55%,#fb7185);-webkit-background-clip:text;background-clip:text;color:transparent}
.sub{font-size:26px;line-height:1.4;color:#c7e6df;margin-top:16px;max-width:1000px}
.frame{background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 40px 80px -30px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.08)}
.frame img{display:block;width:100%}
.dots{height:34px;background:#eef1f4;display:flex;gap:8px;align-items:center;padding:0 16px}
.dots i{width:12px;height:12px;border-radius:50%;background:#cfd5dc}
.dots i:nth-child(1){background:#f87171}.dots i:nth-child(2){background:#fbbf24}.dots i:nth-child(3){background:#34d399}
.dots span{margin-left:14px;flex:1;height:18px;border-radius:9px;background:#fff;font-size:12px;color:#8a94a3;line-height:18px;padding-left:12px}
.chips{display:flex;flex-wrap:wrap;gap:12px}
.chip{display:inline-flex;align-items:center;gap:10px;font-size:21px;font-weight:700;padding:12px 20px;border-radius:14px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16)}
.chip:before{content:"";width:10px;height:10px;border-radius:50%;flex:none;background:#f59e0b}
.chip.ok:before{width:22px;height:22px;border-radius:7px}
.chip.ok:before{background:#f59e0b url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23062b27' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12l5 5 9-10'/%3E%3C/svg%3E") center/15px no-repeat}
.foot{position:absolute;left:64px;right:64px;bottom:44px;display:flex;align-items:center;gap:16px;font-size:20px;color:#9fcfc5}
.foot img{height:40px;filter:brightness(0) invert(1)}
.label{font-size:18px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#fbbf24;margin:0 0 10px}
.card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:20px;padding:22px}
.phone{width:330px;border-radius:44px;padding:11px;background:#020c0b;box-shadow:0 40px 80px -30px rgba(0,0,0,.7),inset 0 0 0 2px rgba(255,255,255,.1)}
.phone img{display:block;width:100%;height:520px;object-fit:cover;object-position:top;border-radius:34px}
"""
FOOT = f'<div class="foot"><img src="{LOGO}" alt=""><span>Manohara Menu Design Pro · laghavi.site</span></div>'
def frame(img, url='yoursite.com', style=''):
    return f'<div class="frame" style="{style}"><div class="dots"><i></i><i></i><i></i><span>{url}</span></div><img src="{img}" alt=""></div>'
def chips(items, cls='chip ok'):
    return '<div class="chips">' + ''.join(f'<span class="{cls}">{i}</span>' for i in items) + '</div>'
def slide(body):
    return f'<!DOCTYPE html><html><head><meta charset="utf-8"><style>{CSS}</style></head><body><div class="s">{body}</div>{FOOT}</body></html>'

S = {}
S['01-manohara-menu-design-pro'] = slide(f'''
<span class="pill">Manohara Menu Design Pro</span>
<h1>Mega menus for <em>any theme</em></h1>
<p class="sub">19 ready designs, a drag-and-drop builder and WooCommerce menus, built from the menu you already have.</p>
<div style="margin-top:38px">{frame(raw('shop.png'))}</div>
<div style="margin-top:34px">{chips(['19 mega menu designs','Drag-and-drop builder','Icons &amp; badges','WooCommerce','Sticky header','Any language'])}</div>''')

thumbs = [('tpl-icon-cards.png','Classic with icons'),('tpl-side-tabs.png','Side tabs'),('tpl-dark-tiles.png','Dark tiles'),('tpl-category-tabs.png','Category tabs'),('tpl-tabs-carousel.png','Tabs &amp; carousel'),('tpl-dark-gradient.png','Gradient promo')]
grid = ''.join(f'<div><div class="frame" style="border-radius:14px"><img src="{raw(f)}" alt="" style="height:170px;object-fit:cover;object-position:top"></div><p style="font-size:20px;font-weight:700;margin-top:10px">{t}</p></div>' for f, t in thumbs)
S['02-mega-menu-designs'] = slide(f'''
<span class="pill">19 ready designs</span>
<h1>Pick a design, <em>done</em></h1>
<p class="sub">Your second-level items become columns, cards or tabs; third-level items their links. Add an intro, a promo card and a footer strip.</p>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:26px 22px;margin-top:40px">{grid}</div>
<p style="font-size:24px;font-weight:800;margin-top:26px;color:#fbbf24">+ 13 more designs · content, full or fixed width</p>''')

S['03-tabs-and-carousel'] = slide(f'''
<span class="pill">Tabs &amp; carousels</span>
<h1>Big menus, <em>easy to scan</em></h1>
<p class="sub">Side tabs or top tabs switch on hover; products and categories show with images, in a grid or a carousel.</p>
<div style="margin-top:36px">{frame(raw('tpl-category-tabs.png'))}</div>
<div style="margin-top:-90px;width:640px;margin-left:auto;position:relative">{frame(raw('tpl-tabs-carousel.png'))}</div>''')

S['04-grid-builder'] = slide(f'''
<span class="pill">Drag-and-drop grid builder</span>
<h1>Build any panel <em>in Menus</em></h1>
<div style="display:grid;grid-template-columns:420px 1fr;gap:44px;margin-top:34px;align-items:start">
  <div class="frame" style="height:780px"><img src="{raw('admin-builder.png')}" alt=""></div>
  <div>
    <p class="sub" style="margin:0 0 26px">Rows of 12 column units. Drag rows and blocks to move them, right in Appearance › Menus.</p>
    <p class="label">Blocks</p>
    {chips(['Headings','Links with icons','A menu','Text','Images','Buttons','Promo banners','Videos','Social links','Latest posts','Shortcodes','Products','Categories','Cart'], 'chip')}
    <div class="card" style="margin-top:26px;font-size:21px;line-height:1.45;color:#d6efe9">Or use a <b style="color:#fff">block editor pattern</b> or an <b style="color:#fff">Elementor template</b> as the whole panel.</div>
  </div>
</div>''')

S['05-icons-badges'] = slide(f'''
<span class="pill">Icons, images &amp; badges</span>
<h1>Menu items that <em>stand out</em></h1>
<p class="sub">57 built-in icons, Dashicons, Font Awesome or your own images, badges in any colour, and a description under the label.</p>
<div style="margin-top:36px">{frame(raw('products.png'))}</div>
<div style="display:grid;grid-template-columns:520px 1fr;gap:34px;margin-top:30px;align-items:center">
  <div class="frame" style="border-radius:16px"><img src="{raw('admin-icons.png')}" alt=""></div>
  <div>{chips(['Hide items on desktop, tablet or mobile','New, Popular, -20% badges','Works in every language'])}</div>
</div>''')

S['06-woocommerce'] = slide(f'''
<span class="pill">WooCommerce</span>
<h1>Menus that <em>sell</em></h1>
<p class="sub">Product and category grids inside mega menus, and a cart button that counts items as they are added.</p>
<div style="margin-top:36px">{frame(raw('shop.png'), 'yourshop.com')}</div>
<div style="display:grid;grid-template-columns:1fr 380px;gap:30px;margin-top:30px;align-items:center">
  {chips(['Popular, latest, best rated, on sale','Category tiles with images','My account button','Count on cart menu items'])}
  <div class="frame" style="border-radius:16px;padding:10px 18px"><img src="{raw('phone-bar.png')}" alt=""></div>
</div>''')

S['07-mobile-menu'] = slide(f'''
<span class="pill">Made for phones</span>
<h1>Mega menus <em>in your pocket</em></h1>
<div style="display:grid;grid-template-columns:330px 330px 1fr;gap:34px;margin-top:40px;align-items:start">
  <div class="phone"><img src="{raw('phone-products.png')}" alt=""></div>
  <div class="phone" style="margin-top:50px"><img src="{raw('phone-shop.png')}" alt=""></div>
  <div style="padding-top:10px">
    <p class="sub" style="margin:0 0 24px;font-size:24px">On phones and tablets every mega menu opens as an accordion.</p>
    <div class="chips" style="flex-direction:column;align-items:flex-start">
      <span class="chip ok">Swipe to close</span><span class="chip ok">Tap to open</span><span class="chip ok">Sticky or hiding header</span><span class="chip ok">8 menu buttons</span><span class="chip ok">Logo for phones</span><span class="chip ok">Hide theme parts</span>
    </div>
  </div>
</div>''')

plans = [('Single site','1 website','49',False),('5 sites','A few websites','99',True),('Unlimited','Agencies','199',False)]
cards = ''.join(f'''<div class="card" style="padding:34px 30px;position:relative;{'background:linear-gradient(160deg,rgba(20,184,166,.25),rgba(245,158,11,.18));border:2px solid #f59e0b;transform:translateY(-14px)' if pop else ''}">
{'<span style="position:absolute;top:-17px;left:30px;background:#f59e0b;color:#062b27;font-size:15px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;padding:6px 14px;border-radius:999px">Most popular</span>' if pop else ''}
<p style="font-size:30px;font-weight:800">{n}</p><p style="font-size:20px;color:#9fcfc5;margin-top:4px">{d}</p>
<p style="font-size:78px;font-weight:800;letter-spacing:-.03em;margin-top:26px">${p}<span style="font-size:22px;font-weight:600;color:#9fcfc5"> / year</span></p>
<div style="margin-top:24px;display:grid;gap:12px;font-size:20px;color:#d6efe9"><span>✓ Every Pro feature</span><span>✓ Updates for a year</span><span>✓ {'Priority' if n=='Unlimited' else 'Email'} support</span></div></div>''' for n, d, p, pop in plans)
S['08-pricing'] = slide(f'''
<span class="pill">Plans</span>
<h1>One price, <em>every feature</em></h1>
<p class="sub">Billed yearly. Pro adds to the free Manohara Menu Design; if you don't renew, it keeps working.</p>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:26px;margin-top:70px">{cards}</div>
<p style="text-align:center;font-size:24px;font-weight:700;margin-top:56px;color:#fbbf24">laghavi.site/product/manohara-menu-design-pro</p>''')

os.makedirs(OUT, exist_ok=True)
for name, html in S.items():
    open(os.path.join(OUT, name + '.html'), 'w').write(html)
print('\n'.join(S))
