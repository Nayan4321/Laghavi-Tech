"""Rebuild the Pro plugins part of website/rinnovo-subscriptions-licenses.html (between the PROS markers):
"Find your Pro" chooser, a sticky Pro bar, one detailed spotlight per Pro, and a comparison chart of all Pros.
Content comes from rinnovo-product-pages/src/content.py. Run: python3 website/rinnovo-landing-src/build_lp.py"""
import html, os, re, sys
here = os.path.dirname(os.path.abspath(__file__))
web = os.path.dirname(here)
sys.path.insert(0, os.path.join(web, 'rinnovo-product-pages', 'src'))
from content import PROS, PRICES, SHARED

IMG = 'https://laghavi.site/wp-content/uploads/rinnovo-lp/'
SHOP = 'https://laghavi.site/product/'
GUIDES = 'https://laghavi.site/guides/'
e = lambda t: html.escape(t, quote=False)

# Per Pro: id, colour, icon path, image prefix, "I run a..." chooser text, who it is for.
META = {
 'rinnovo-subscriptions-licenses-pro': ('billing', '#ea580c', '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>', 'subscriptions-licenses',
   'I sell software, SaaS or international subscriptions', 'Software and SaaS sellers, digital products, agencies billing worldwide, any store that needs proper tax invoices.'),
 'rinnovo-physical-subscription-boxes-pro': ('boxes', '#0f766e', '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>', 'physical-subscription-boxes',
   'I ship boxes, meal kits or refills', 'Coffee, snack and beauty boxes, meal kits and lunch boxes, flowers, pet food, refills and subscribe & save shops.'),
 'rinnovo-membership-content-pro': ('membership', '#7c3aed', '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>', 'membership-content',
   'I sell memberships, courses or downloads', 'Coaches and educators, paid communities, course creators, newsletters and resource libraries (templates, photos, assets).'),
 'rinnovo-media-archives-pro': ('media', '#db2777', '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z"/>', 'media-archives',
   'I stream video, podcasts or media', 'Video educators and fitness trainers, podcast networks, stock media sites, agencies and production houses sharing media with clients.'),
 'rinnovo-service-contracts-pro': ('contracts', '#2563eb', '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>', 'service-contracts',
   'I run an agency or sell retainers', 'Agencies, consultants and freelancers selling SEO, design, marketing, IT support or bookkeeping retainers.'),
 'rinnovo-session-membership-pro': ('sessions', '#f97316', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>', 'session-membership',
   'I run classes, a studio or coaching', 'Yoga and fitness studios, gyms, dance and music schools, tutoring centres, coaches and therapists.'),
 'rinnovo-bundles-variable-subscriptions-pro': ('bundles', '#16a34a', '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>', 'bundles-variable-subscriptions',
   'I sell bundles or several plans', 'Shops selling bundles and build-a-box products, and anyone with Basic / Premium or monthly / yearly plans to switch between.'),
}
GUIDE = {
 'rinnovo-subscriptions-licenses-pro': 'rinnovo-guide-subscriptions-licenses-pro', 'rinnovo-physical-subscription-boxes-pro': 'rinnovo-guide-boxes-pro',
 'rinnovo-membership-content-pro': 'rinnovo-guide-membership-pro', 'rinnovo-media-archives-pro': 'rinnovo-guide-media-archives-pro',
 'rinnovo-service-contracts-pro': 'rinnovo-guide-service-contracts-pro', 'rinnovo-session-membership-pro': 'rinnovo-guide-session-pro',
 'rinnovo-bundles-variable-subscriptions-pro': 'rinnovo-guide-bundles-pro'}
ORDER = list(PROS)
SHORT = {s: PROS[s]['short'].replace('Rinnovo ', '') for s in ORDER}
SHORT['rinnovo-subscriptions-licenses-pro'] = 'Subscriptions & Licenses Pro'

def icon(slug, size=''):
    m = META[slug]
    return f'<span class="hs-icon{size}" style="background:{m[1]}"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">{m[2]}</svg></span>'

def clean(t):
    return t.replace(' & ', ' &amp; ')

# ---- Chooser + sticky bar ----
out = ['  <!-- Find your Pro -->\n  <section id="pro" class="hs-reveal">\n    <h2>Seven Pro plugins. Pick the one made for your business.</h2>',
       '    <p class="lead">Every Rinnovo Pro is a complete product on its own, built for one kind of business, and they all work together. Start with the one that fits you.</p>',
       '    <div class="hs-choose">']
for s in ORDER:
    m = META[s]
    out.append(f'      <a class="hs-choose__item" href="#pro-{m[0]}" style="--c:{m[1]}">{icon(s)}<span><b>{e(m[4])}</b><small>{e(PROS[s]["name"])}</small></span><i>→</i></a>')
out.append('    </div>\n  </section>\n')
out.append('  <nav class="hs-probar" aria-label="Rinnovo Pro plugins"><div class="hs-probar__in">' + ''.join(
    f'<a href="#pro-{META[s][0]}" style="--c:{META[s][1]}"><span></span>{e(SHORT[s])}</a>' for s in ORDER) + '<a href="#compare" class="is-cmp">Compare all</a></div></nav>\n')

# ---- Spotlights ----
for n, s in enumerate(ORDER):
    p, m = PROS[s], META[s]
    p1, p5, pu = PRICES[s]
    flip = ' hs-spot--flip' if n % 2 else ''
    groups = ''.join(f'<div class="hs-feat"><h4>{e(h)}</h4><ul>' + ''.join(f'<li>{clean(i)}</li>' for i in items) + '</ul></div>' for h, items in p['sections'])
    imgs = [f'{IMG}{m[3]}-0{i}.jpg' for i in (1, 2, 3)]
    thumbs = ''.join(f'<button type="button" class="hs-thumb{" is-on" if i == 0 else ""}" data-src="{u}" aria-label="Show image {i+1}"><img src="{u}" alt="" loading="lazy" width="72" height="72"></button>' for i, u in enumerate(imgs))
    out.append(f'''  <section id="pro-{m[0]}" class="hs-spot{flip} hs-reveal" style="--c:{m[1]}">
    <div class="hs-spot__grid">
      <div class="hs-spot__media">
        <div class="hs-spot__frame"><img class="hs-spot__img" src="{imgs[0]}" alt="{e(p['name'])}" loading="lazy" width="900" height="900"></div>
        <div class="hs-thumbs">{thumbs}</div>
      </div>
      <div class="hs-spot__text">
        <div class="hs-spot__head">{icon(s, ' hs-icon--lg')}<div><span class="hs-kicker">{e(p['branch'])}</span><h3>{e(p['name'])}</h3></div></div>
        <p class="hs-spot__lead">{e(p['tagline'])}</p>
        <p class="hs-spot__for"><b>Made for:</b> {e(m[5])}</p>
        <div class="hs-spot__price"><span><b>${p1}</b>1 site</span><span><b>${p5}</b>5 sites</span><span><b>${pu}</b>Unlimited</span><em>per year · updates &amp; support</em></div>
        <div class="hs-spot__ctas"><a class="hs-btn hs-btn--solid" href="{SHOP}{s}/">See {e(SHORT[s])} &amp; pricing <span aria-hidden="true">→</span></a><a class="hs-btn hs-btn--outline" href="{GUIDES}">Read the guide</a></div>
      </div>
    </div>
    <details class="hs-spot__more" open>
      <summary>Everything in {e(SHORT[s])}</summary>
      <div class="hs-feats">{groups}</div>
    </details>
  </section>
''')

# ---- Included in every Pro ----
out.append('  <section class="hs-every hs-reveal">\n    <h2>Included in every Rinnovo Pro</h2>\n    <div class="hs-every__grid">' + ''.join(f'<div class="hs-every__item"><span>✓</span><p>{clean(x)}</p></div>' for x in SHARED) + '</div>\n  </section>\n')

# ---- Comparison chart ----
Y, N = '<td class="hs-yes">✓</td>', '<td class="hs-no">—</td>'
cols = ['free'] + ORDER
def row(label, yes, notes=None):
    notes = notes or {}
    cells = ''
    for c in cols:
        if c in notes:
            cells += f'<td class="hs-part">{e(notes[c])}</td>'
        elif c in yes or 'all' in yes or ('pros' in yes and c != 'free'):
            cells += Y
        else:
            cells += N
    return f'<tr><th scope="row">{e(label)}</th>{cells}</tr>'
B, BX, M, MD, SC, SE, BU = ORDER
G = [
 ('The basics', [
   row('Subscriptions on any product, any WooCommerce payment method', ['all']),
   row('Automatic card renewals and "Pay now" invoices', ['all']),
   row('License keys, downloads and updates', ['all']),
   row('My Account: pay, update card, cancel, resume', ['all']),
 ]),
 ('Included in every Pro', [
   row('Tax invoices and credit notes (GST, VAT, reverse charge)', ['pros']),
   row('Elementor widgets and blocks', ['pros']),
   row('Insights data for your dashboard', ['pros']),
   row('Customer guide and email support', ['pros']),
   row('Works alone, works with the other Pros', ['pros']),
 ]),
 ('Billing, tax and licenses', [
   row('Trials limited to one per customer, trial reminders', [B]),
   row('VAT / GST rules with evidence, tax exemption history', [B]),
   row('Fixed prices per currency, currency switcher', [B]),
   row('License history on upgrades and downgrades', [B]),
   row('"Ask for price" and quote enquiries', [B]),
 ]),
 ('Physical products', [
   row('Box builder: pick items, sizes, rotating menus', [BX, BU]),
   row('Skip, swap and change the next box', [BX, BU], {BU: 'Next box only'}),
   row('Gift subscriptions and prepaid plans', [BX]),
   row('Delivery calendar and packing lists', [BX], {BU: 'Packing CSV'}),
   row('Exact component stock, renewals held when out of stock', [BU], {BX: 'Out-of-stock rules'}),
   row('Plan table and switching with proration', [BU], {M: 'Tier upgrades'}),
 ]),
 ('Content and media', [
   row('Membership groups, tiers and lifecycle rules', [M]),
   row('Drip timeline, courses sold as products', [M]),
   row('Download credits and resource library', [M], {MD: 'Download quotas'}),
   row('Protected files and private video', [M, MD]),
   row('Streaming library, signed links, stream limits', [MD], {M: 'Basic'}),
   row('Private podcast feed per subscriber', [MD]),
   row('Members-only sections in page builders', [M]),
 ]),
 ('Services and classes', [
   row('Several allowances with rollover per contract', [SC]),
   row('Client requests, approvals, work log, monthly report', [SC]),
   row('Minimum terms, notice periods, contract PDF', [SC]),
   row('Class credits ledger, packs, family and hybrid plans', [SE]),
   row('Class schedule, recurring bookings, QR check-in', [SE]),
   row('WooCommerce Bookings, Amelia and Bookly', [SE]),
 ]),
]
head = '<tr><th scope="col" class="hs-cmp__corner">Feature</th><th scope="col"><span class="hs-cmp__name">Free plugin</span><small>$0</small></th>' + ''.join(
    f'<th scope="col" style="--c:{META[s][1]}"><span class="hs-cmp__dot"></span><span class="hs-cmp__name">{e(SHORT[s])}</span><small>from ${PRICES[s][0]}/yr</small></th>' for s in ORDER) + '</tr>'
body = ''.join(f'<tr class="hs-cmp__group"><th scope="rowgroup" colspan="{len(cols)+1}"><span>{e(g)}</span></th></tr>' + ''.join(rows) for g, rows in G)
price_rows = ''.join(f'<tr class="hs-cmp__price"><th scope="row">{lbl}</th><td>{"$0" if i == 0 else "—"}</td>' + ''.join(f'<td><b>${PRICES[s][i]}</b>/yr</td>' for s in ORDER) + '</tr>' for i, lbl in enumerate(['1 site', '5 sites', 'Unlimited sites']))
price_rows = price_rows.replace('<td>—</td>', '<td class="hs-no">—</td>')
buy = '<tr class="hs-cmp__buy"><th scope="row"></th><td><a class="hs-btn hs-btn--outline hs-btn--sm" href="https://wordpress.org/plugins/rinnovo-subscriptions-licenses/">Download</a></td>' + ''.join(f'<td><a class="hs-btn hs-btn--solid hs-btn--sm" style="--c:{META[s][1]}" href="{SHOP}{s}/">See plugin</a></td>' for s in ORDER) + '</tr>'
out.append(f'''  <section id="compare" class="hs-reveal">
    <h2>Compare every Rinnovo plugin</h2>
    <p class="lead">The free plugin and all seven Pros side by side. Scroll sideways to see every plugin; the feature names stay in place.</p>
    <div class="hs-cmp__hint" aria-hidden="true">Scroll to compare →</div>
    <div class="hs-cmp" tabindex="0" role="region" aria-label="Comparison of Rinnovo plugins">
      <table class="hs-cmp__table">
        <thead>{head}</thead>
        <tbody>{body}<tr class="hs-cmp__group"><th scope="rowgroup" colspan="{len(cols)+1}"><span>Price per year</span></th></tr>{price_rows}{buy}</tbody>
      </table>
    </div>
  </section>
''')
SECTIONS = '\n'.join(out)

CSS = r'''
/* Seven Pros: chooser, sticky bar, spotlights, comparison chart, animations */
#hs-lp .hs-btn--solid{background:var(--c,var(--brand))!important;color:#fff!important;border:0;box-shadow:0 10px 24px -8px var(--c,var(--brand));font-weight:700}
#hs-lp .hs-btn--solid:hover{filter:brightness(1.08);transform:translateY(-2px)}
#hs-lp .hs-btn--outline{background:#fff!important;color:var(--c,var(--ink))!important;border:2px solid var(--c,var(--ink));font-weight:700}
#hs-lp .hs-btn--outline:hover{background:var(--c,var(--ink))!important;color:#fff!important}
#hs-lp .hs-btn--sm{padding:8px 12px;font-size:13px;white-space:nowrap}
#hs-lp .hs-choose{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-top:22px}
#hs-lp .hs-choose__item{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;color:var(--ink)!important;transition:transform .2s,border-color .2s,box-shadow .2s}
#hs-lp .hs-choose__item:hover{transform:translateY(-3px);border-color:var(--c);box-shadow:0 14px 30px -14px var(--c)}
#hs-lp .hs-choose__item b{display:block;font-size:15px}
#hs-lp .hs-choose__item small{color:var(--muted);font-size:12.5px}
#hs-lp .hs-choose__item i{margin-left:auto;font-style:normal;font-weight:800;color:var(--c);font-size:20px;transition:transform .2s}
#hs-lp .hs-choose__item:hover i{transform:translateX(4px)}
#hs-lp .hs-probar{position:sticky;top:0;z-index:20;margin:28px -16px 0;padding:10px 16px;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
#hs-lp .hs-probar__in{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none}
#hs-lp .hs-probar__in::-webkit-scrollbar{display:none}
#hs-lp .hs-probar a{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;padding:8px 13px;border-radius:999px;border:1px solid var(--line);font-size:13.5px;font-weight:600;color:var(--ink)!important;background:#fff;transition:background .2s,color .2s,border-color .2s}
#hs-lp .hs-probar a span{width:9px;height:9px;border-radius:50%;background:var(--c)}
#hs-lp .hs-probar a:hover,#hs-lp .hs-probar a.is-on{background:var(--c);border-color:var(--c);color:#fff!important}
#hs-lp .hs-probar a.is-on span,#hs-lp .hs-probar a:hover span{background:#fff}
#hs-lp .hs-probar a.is-cmp{--c:#0f172a}
#hs-lp .hs-spot{scroll-margin-top:84px;background:#fff;border:1px solid var(--line);border-radius:22px;padding:clamp(18px,3.5vw,32px);position:relative;overflow:hidden}
#hs-lp .hs-spot::before{content:"";position:absolute;inset:0 0 auto 0;height:5px;background:linear-gradient(90deg,var(--c),transparent)}
#hs-lp .hs-spot__grid{display:grid;grid-template-columns:.95fr 1.05fr;gap:clamp(18px,3.5vw,36px);align-items:center}
#hs-lp .hs-spot--flip .hs-spot__media{order:2}
#hs-lp .hs-spot__frame{border-radius:18px;overflow:hidden;box-shadow:0 24px 50px -24px rgba(15,23,42,.55);aspect-ratio:1/1;background:#120d33}
#hs-lp .hs-spot__img{display:block;width:100%;height:100%;object-fit:cover;transition:transform .6s ease,opacity .25s}
#hs-lp .hs-spot:hover .hs-spot__img{transform:scale(1.03)}
#hs-lp .hs-thumbs{display:flex;gap:8px;margin-top:10px}
#hs-lp .hs-thumb{padding:0;border:2px solid transparent;border-radius:10px;overflow:hidden;cursor:pointer;background:none;opacity:.6;transition:opacity .2s,border-color .2s}
#hs-lp .hs-thumb img{display:block;width:64px;height:64px;object-fit:cover}
#hs-lp .hs-thumb.is-on,#hs-lp .hs-thumb:hover{opacity:1;border-color:var(--c)}
#hs-lp .hs-spot__head{display:flex;gap:14px;align-items:center}
#hs-lp .hs-icon--lg{width:52px;height:52px;border-radius:14px}
#hs-lp .hs-icon--lg svg{width:26px;height:26px}
#hs-lp .hs-kicker{display:inline-block;font-size:11.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--c)}
#hs-lp .hs-spot h3{font-size:clamp(20px,2.4vw,26px);font-weight:800;letter-spacing:-.01em;margin-top:2px}
#hs-lp .hs-spot__lead{font-size:16.5px;color:var(--ink);margin-top:14px}
#hs-lp .hs-spot__for{font-size:14px;color:var(--muted);margin-top:10px;padding:10px 12px;background:var(--soft);border-radius:10px}
#hs-lp .hs-spot__for b{color:var(--ink)}
#hs-lp .hs-spot__price{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-top:16px}
#hs-lp .hs-spot__price span{display:flex;flex-direction:column;border:1px solid var(--line);border-radius:12px;padding:8px 14px;font-size:12px;color:var(--muted);min-width:92px}
#hs-lp .hs-spot__price span b{font-size:22px;color:var(--ink);letter-spacing:-.02em}
#hs-lp .hs-spot__price span:first-child{border:2px solid var(--c)}
#hs-lp .hs-spot__price em{font-style:normal;font-size:12.5px;color:var(--muted);padding-bottom:8px}
#hs-lp .hs-spot__ctas{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
#hs-lp .hs-spot__ctas .hs-btn{padding:14px 22px;font-size:15.5px}
#hs-lp .hs-spot__more{margin-top:22px;border-top:1px solid var(--line);padding-top:6px}
#hs-lp .hs-spot__more summary{cursor:pointer;list-style:none;font-weight:700;padding:12px 0;display:flex;justify-content:space-between;color:var(--ink)}
#hs-lp .hs-spot__more summary::-webkit-details-marker{display:none}
#hs-lp .hs-spot__more summary::after{content:"–";color:var(--c);font-size:22px;line-height:1}
#hs-lp .hs-spot__more:not([open]) summary::after{content:"+"}
#hs-lp .hs-feats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
#hs-lp .hs-feat{background:var(--soft);border-radius:14px;padding:16px;border-top:3px solid var(--c);transition:transform .2s,box-shadow .2s}
#hs-lp .hs-feat:hover{transform:translateY(-3px);box-shadow:0 12px 26px -16px rgba(15,23,42,.4)}
#hs-lp .hs-feat h4{font-size:14.5px;font-weight:800;margin:0;color:var(--ink);font-family:inherit;text-transform:none}
#hs-lp .hs-feat ul{list-style:none;padding:0;margin:8px 0 0;font-size:13.5px;color:var(--muted)}
#hs-lp .hs-feat li{position:relative;padding-left:18px;margin:6px 0}
#hs-lp .hs-feat li::before{content:"";position:absolute;left:2px;top:.55em;width:7px;height:7px;border-radius:50%;background:var(--c)}
#hs-lp .hs-feat strong{color:var(--ink)}
#hs-lp .hs-every{background:linear-gradient(135deg,#140f3a,#24185a);border-radius:22px;padding:clamp(22px,4vw,36px);color:#fff}
#hs-lp .hs-every h2{color:#fff}
#hs-lp .hs-every__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-top:18px}
#hs-lp .hs-every__item{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:14px;padding:14px;display:flex;gap:10px;font-size:13.5px;color:#d6d9f5}
#hs-lp .hs-every__item span{flex:none;width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,var(--brand),var(--brand2));display:grid;place-items:center;font-size:12px;font-weight:800;color:#fff}
#hs-lp .hs-every__item strong{color:#fff}
#hs-lp #compare,#hs-lp #pro{scroll-margin-top:84px}
#hs-lp .hs-cmp__hint{display:none;font-size:12.5px;font-weight:700;color:var(--brand);margin-top:12px;animation:hs-nudge 1.6s ease-in-out infinite}
#hs-lp .hs-cmp{margin-top:16px;border:1px solid var(--line);border-radius:16px;overflow-x:auto;overflow-y:hidden;background:#fff;-webkit-overflow-scrolling:touch;scroll-snap-type:x proximity}
#hs-lp .hs-cmp:focus{outline:2px solid var(--brand);outline-offset:2px}
#hs-lp .hs-cmp__table{border-collapse:separate;border-spacing:0;min-width:1120px;width:100%;font-size:13.5px}
#hs-lp .hs-cmp__table th,#hs-lp .hs-cmp__table td{padding:11px 12px;border-top:1px solid var(--line)!important;text-align:center;vertical-align:middle}
#hs-lp .hs-cmp__table thead th{position:sticky;top:0;background:var(--soft);font-size:12px;border-top:0!important;scroll-snap-align:start;min-width:112px}
#hs-lp .hs-cmp__table thead th small{display:block;color:var(--muted);font-weight:600;font-size:11.5px;margin-top:2px}
#hs-lp .hs-cmp__name{display:block;font-weight:800;color:var(--ink);font-size:13px}
#hs-lp .hs-cmp__dot{display:block;width:10px;height:10px;border-radius:50%;background:var(--c);margin:0 auto 6px}
#hs-lp .hs-cmp__table tbody th[scope=row],#hs-lp .hs-cmp__corner{position:sticky;left:0;z-index:2;background:#fff;text-align:left;font-weight:600;color:var(--ink);min-width:230px;max-width:260px;box-shadow:6px 0 10px -8px rgba(15,23,42,.25)}
#hs-lp .hs-cmp__corner{background:var(--soft)!important;z-index:3;text-align:left!important}
#hs-lp .hs-cmp__group th span{position:sticky;left:12px}
#hs-lp .hs-cmp__group th{background:#fff7ed!important;color:#c2410c;font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;text-align:left!important}
#hs-lp .hs-cmp__table td.hs-yes{color:#16a34a;font-weight:800;font-size:17px}
#hs-lp .hs-cmp__table td.hs-no{color:#c0c4d0}
#hs-lp .hs-cmp__table td.hs-part{color:#b45309;font-size:12px;font-weight:700}
#hs-lp .hs-cmp__table tbody tr:not(.hs-cmp__group):hover td,#hs-lp .hs-cmp__table tbody tr:not(.hs-cmp__group):hover th[scope=row]{background:#fffaf5}
#hs-lp .hs-cmp__price td{color:var(--muted)}
#hs-lp .hs-cmp__price td b{color:var(--ink);font-size:15px}
#hs-lp .hs-cmp__buy td{border-top:1px solid var(--line)!important}
/* Scroll-in animation */
#hs-lp.hs-anim .hs-reveal{opacity:0;transform:translateY(28px);transition:opacity .7s ease,transform .7s cubic-bezier(.2,.7,.2,1)}
#hs-lp.hs-anim .hs-reveal.is-in{opacity:1;transform:none}
#hs-lp.hs-anim .hs-reveal .hs-feat,#hs-lp.hs-anim .hs-reveal .hs-choose__item{opacity:0;transform:translateY(14px);transition:opacity .5s ease,transform .5s ease}
#hs-lp.hs-anim .hs-reveal.is-in .hs-feat,#hs-lp.hs-anim .hs-reveal.is-in .hs-choose__item{opacity:1;transform:none}
#hs-lp .hs-hero h1{animation:hs-rise .8s cubic-bezier(.2,.7,.2,1) both}
#hs-lp .hs-mock{animation:hs-float 6s ease-in-out infinite}
@keyframes hs-rise{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
@keyframes hs-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes hs-nudge{0%,100%{transform:translateX(0)}50%{transform:translateX(6px)}}
@media (prefers-reduced-motion:reduce){#hs-lp *{animation:none!important;transition:none!important}#hs-lp.hs-anim .hs-reveal,#hs-lp.hs-anim .hs-feat,#hs-lp.hs-anim .hs-choose__item{opacity:1!important;transform:none!important}}
@media (max-width:900px){#hs-lp .hs-spot__grid{grid-template-columns:1fr}#hs-lp .hs-spot--flip .hs-spot__media{order:0}#hs-lp .hs-feats{grid-template-columns:repeat(2,1fr)}#hs-lp .hs-cmp__hint{display:block}}
@media (max-width:640px){#hs-lp .hs-choose,#hs-lp .hs-feats{grid-template-columns:1fr}#hs-lp .hs-spot__ctas .hs-btn{flex:1 1 100%;justify-content:center}#hs-lp .hs-cmp__table tbody th[scope=row],#hs-lp .hs-cmp__corner{min-width:160px}}
'''

JS = r'''<script>
(function(){var root=document.getElementById('hs-lp');if(!root)return;
var reduce=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if('IntersectionObserver' in window&&!reduce){root.classList.add('hs-anim');
 var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add('is-in');
  [].forEach.call(x.target.querySelectorAll('.hs-feat,.hs-choose__item'),function(el,i){el.style.transitionDelay=(i*60)+'ms';});io.unobserve(x.target);}});},{threshold:.12});
 [].forEach.call(root.querySelectorAll('.hs-reveal'),function(el){io.observe(el);});}
[].forEach.call(root.querySelectorAll('.hs-spot'),function(sec){var big=sec.querySelector('.hs-spot__img');
 [].forEach.call(sec.querySelectorAll('.hs-thumb'),function(b){b.addEventListener('click',function(){big.style.opacity=0;setTimeout(function(){big.src=b.dataset.src;big.style.opacity=1;},180);
  [].forEach.call(sec.querySelectorAll('.hs-thumb'),function(t){t.classList.toggle('is-on',t===b);});});});});
var links=[].slice.call(root.querySelectorAll('.hs-probar a'));
if('IntersectionObserver' in window){var spy=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){links.forEach(function(a){a.classList.toggle('is-on',a.hash==='#'+x.target.id);});}});},{rootMargin:'-45% 0px -50% 0px'});
 links.forEach(function(a){var t=document.getElementById(a.hash.slice(1));if(t)spy.observe(t);});}
root.addEventListener('click',function(ev){var a=ev.target.closest('a[href^="#"]');if(!a)return;var t=document.getElementById(a.hash.slice(1));if(!t)return;ev.preventDefault();t.scrollIntoView({behavior:reduce?'auto':'smooth',block:'start'});history.replaceState(null,'',a.hash);});
})();
</script>'''

p = os.path.join(web, 'rinnovo-subscriptions-licenses.html')
s = open(p).read()
s = re.sub(r'(  <!-- PROS:START -->\n).*?(  <!-- PROS:END -->)', lambda m: m.group(1) + SECTIONS + m.group(2), s, flags=re.S)
s = re.sub(r'(/\* PROS-CSS:START \*/\n).*?(/\* PROS-CSS:END \*/)', lambda m: m.group(1) + CSS.strip() + '\n' + m.group(2), s, flags=re.S)
s = re.sub(r'(<!-- PROS-JS:START -->\n).*?(<!-- PROS-JS:END -->)', lambda m: m.group(1) + JS + '\n' + m.group(2), s, flags=re.S)
open(p, 'w').write(s)
print('built', len(SECTIONS) // 1000, 'kB of sections')
