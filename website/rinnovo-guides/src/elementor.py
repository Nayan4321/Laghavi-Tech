"""Elementor HTML widget code for every guide: paste one file into an Elementor "HTML" widget on the matching
Guide post. Self-contained (scoped styles + script), like the landing pages. Run: python3 src/elementor.py"""
import html, os, sys
here = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, here)
from guides import G
from build import block, WORKS, slugify
from wxr import MAP
from setup import START, REF

out_dir = os.path.join(os.path.dirname(here), 'elementor')
os.makedirs(out_dir, exist_ok=True)
e = lambda t: html.escape(t, quote=False)
URL = 'https://laghavi.site/guides/'

CSS = '''<style>
#lg-guide{--ink:#0f172a;--muted:#5b6476;--line:#e6e8f0;--soft:#f6f7fb;--brand:#ea580c;--brand2:#db2777;--navy:#140f3a;color:var(--ink);font-family:inherit;line-height:1.65;max-width:1180px;margin:0 auto;padding:24px 16px 56px;box-sizing:border-box}
#lg-guide *,#lg-guide *::before,#lg-guide *::after{box-sizing:border-box}
#lg-guide h1,#lg-guide h2,#lg-guide h3,#lg-guide p{margin:0}
#lg-guide a{text-decoration:none;box-shadow:none;border-bottom:0}
#lg-guide .lg-hero{border-radius:22px;padding:clamp(24px,4vw,40px);color:#fff;background:radial-gradient(700px 380px at 95% 0%,rgba(219,39,119,.45),transparent 60%),radial-gradient(600px 400px at 0% 120%,rgba(234,88,12,.4),transparent 60%),linear-gradient(135deg,#140f3a,#24185a)}
#lg-guide .lg-badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fed7aa}
#lg-guide .lg-hero h1{font-size:clamp(28px,4.4vw,42px);font-weight:800;letter-spacing:-.02em;line-height:1.12;margin-top:14px;color:#fff}
#lg-guide .lg-hero p{color:#d6d9f5;margin-top:12px;font-size:16.5px;max-width:760px}
#lg-guide .lg-switch{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:22px;padding-top:18px;border-top:1px solid rgba(255,255,255,.15)}
#lg-guide .lg-switch label{font-size:14px;font-weight:600;color:#fff}
#lg-guide .lg-switch select{flex:1 1 260px;min-width:0;max-width:460px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.6);font:600 15px/1.3 inherit;color:#fff!important;background-color:transparent;cursor:pointer}
#lg-guide #lg-guide-select,#lg-guide-select.lg-guide-select{display:block!important;color:#fff!important;-webkit-text-fill-color:#fff!important;position:static!important;width:auto!important;height:auto!important;clip:auto!important;clip-path:none!important;opacity:1!important;visibility:visible!important}
#lg-guide .lg-switch .select2-container{flex:1 1 260px;max-width:460px}
#lg-guide .lg-switch .select2-selection,#lg-guide .lg-switch .select2-selection--single{background:transparent!important;border:1px solid rgba(255,255,255,.6)!important;border-radius:10px!important;height:auto!important;min-height:44px}
#lg-guide .lg-switch .select2-selection__rendered,#lg-guide .lg-switch .select2-selection__placeholder{color:#fff!important;-webkit-text-fill-color:#fff!important;line-height:42px!important;padding-left:12px!important;font-weight:600}
#lg-guide .lg-switch .select2-selection__arrow b{border-color:#fff transparent transparent transparent!important}
#lg-guide #lg-guide-select option,#lg-guide #lg-guide-select optgroup{color:#0f172a!important;-webkit-text-fill-color:#0f172a!important;background:#fff!important}
#lg-guide .lg-switch a{color:#fdba74;font-weight:700;font-size:14px}
#lg-guide .lg-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:28px;align-items:start;margin-top:26px}
#lg-guide .lg-toc{position:sticky;top:100px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 10px 12px;max-height:calc(100vh - 120px);overflow:auto}
#lg-guide .lg-toc p{margin:0 10px 8px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
#lg-guide .lg-toc ol{list-style:none;margin:0;padding:0;counter-reset:lg}
#lg-guide .lg-toc li{margin:0;padding:0}
#lg-guide .lg-toc li::marker{content:none}
#lg-guide .lg-toc a{display:flex;gap:10px;padding:8px 10px;border-radius:10px;color:#374151;font-size:14.5px;line-height:1.35;transition:background .15s,color .15s}
#lg-guide .lg-toc a::before{counter-increment:lg;content:counter(lg);flex:none;width:22px;height:22px;border-radius:50%;background:#f3f4f6;color:var(--muted);font-size:12px;font-weight:700;line-height:22px;text-align:center}
#lg-guide .lg-toc a:hover,#lg-guide .lg-toc a.is-on{background:#fff7ed;color:#c2410c}
#lg-guide .lg-toc a.is-on{font-weight:700}
#lg-guide .lg-toc a.is-on::before{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff}
#lg-guide .lg-body{background:#fff;border:1px solid var(--line);border-radius:16px;padding:clamp(18px,3vw,34px);min-width:0}
#lg-guide .lg-sec{scroll-margin-top:100px}
#lg-guide .lg-sec+.lg-sec{margin-top:26px;padding-top:24px;border-top:1px solid var(--line)}
#lg-guide .lg-sec h2{display:flex;gap:12px;align-items:center;font-size:clamp(20px,2.4vw,24px);font-weight:800;letter-spacing:-.01em;color:var(--ink);font-family:inherit;text-transform:none;line-height:1.25;padding:0;border:0;background:none}
#lg-guide .lg-sec h2 span{flex:none;width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-size:15px;display:grid;place-items:center}
#lg-guide .lg-sec p{margin:12px 0 0;color:#374151;font-size:16px}
#lg-guide .lg-sec ul,#lg-guide .lg-sec ol{margin:12px 0 0;padding-left:22px;color:#374151;font-size:16px}
#lg-guide .lg-sec li{margin:6px 0}
#lg-guide .lg-sec ol.steps{list-style:none;padding:0;counter-reset:st}
#lg-guide .lg-sec ol.steps li{position:relative;padding:10px 12px 10px 48px;background:var(--soft);border-radius:12px;margin:8px 0}
#lg-guide .lg-sec ol.steps li::before{counter-increment:st;content:counter(st);position:absolute;left:12px;top:10px;width:24px;height:24px;border-radius:8px;background:var(--brand);color:#fff;font-size:13px;font-weight:700;display:grid;place-items:center}
#lg-guide .lg-table{margin-top:14px;overflow-x:auto;border:1px solid var(--line);border-radius:12px}
#lg-guide table{width:100%;border-collapse:collapse;font-size:15px;margin:0;border:0;background:#fff}
#lg-guide th,#lg-guide td{padding:10px 12px;text-align:left;vertical-align:top;border:0;border-top:1px solid var(--line);color:#374151;background:transparent}
#lg-guide tr:first-child th{border-top:0;background:var(--soft);font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
#lg-guide td:first-child{font-weight:600;color:var(--ink)}
#lg-guide .lg-sec h3{font-size:18px;font-weight:800;color:var(--ink);margin:22px 0 0;font-family:inherit;text-transform:none;letter-spacing:normal;line-height:1.3}
#lg-guide .lg-sec p.note{background:#eff6ff;border:1px solid #bfdbfe;border-left:4px solid #3b82f6;border-radius:10px;padding:12px 14px;color:#1e3a8a;font-size:15px}
#lg-guide .code{margin-top:14px;border-radius:12px;overflow:hidden;background:#0f172a;border:1px solid #1e293b}
#lg-guide .code__bar{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 12px;background:#1e293b;color:#cbd5e1;font-size:12.5px;font-weight:700;letter-spacing:.02em}
#lg-guide .code__copy{all:unset;cursor:pointer;padding:4px 10px;border-radius:7px;background:#334155;color:#fff;font-size:12px;font-weight:700}
#lg-guide .code__copy:hover{background:var(--brand)}
#lg-guide .code pre{margin:0;padding:14px 16px;overflow:auto;max-height:560px;background:transparent;border:0;color:#e2e8f0;font:13px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre;tab-size:4}
#lg-guide .code code{background:none;color:inherit;padding:0;font:inherit;white-space:inherit}
#lg-guide .tabs{margin-top:14px}
#lg-guide .tabs__bar{display:flex;flex-wrap:wrap;gap:6px}
#lg-guide .tabs__btn{all:unset;cursor:pointer;padding:7px 12px;border-radius:9px;background:var(--soft);border:1px solid var(--line);color:#374151;font-size:13.5px;font-weight:700}
#lg-guide .tabs__btn:hover{border-color:var(--brand);color:#c2410c}
#lg-guide .tabs__btn.is-on{background:linear-gradient(135deg,var(--brand),var(--brand2));border-color:transparent;color:#fff}
#lg-guide .tabs__pane[hidden]{display:none!important}
#lg-guide .tabs__pane .code{margin-top:10px}
#lg-guide .lg-top{display:inline-block;margin-top:14px;font-size:13px;color:var(--muted)}
#lg-guide .lg-works{margin-top:30px;padding:18px 20px;background:#fff7ed;border:1px solid #fed7aa;border-radius:14px}
#lg-guide .lg-works b{font-size:15px;color:var(--ink)}
#lg-guide .lg-works ul{margin:8px 0 0;padding-left:20px;color:#374151}
#lg-guide .lg-works a{color:#c2410c;font-weight:600}
#lg-guide.lg-anim .lg-sec{opacity:0;transform:translateY(16px);transition:opacity .5s ease,transform .5s ease}
#lg-guide.lg-anim .lg-sec.is-in{opacity:1;transform:none}
@media (prefers-reduced-motion:reduce){#lg-guide *{transition:none!important}#lg-guide.lg-anim .lg-sec{opacity:1;transform:none}}
@media (max-width:900px){#lg-guide .lg-layout{grid-template-columns:1fr}#lg-guide .lg-toc{position:static;max-height:none}}
</style>'''

JS = '''<script>
(function(){var root=document.getElementById('lg-guide');if(!root)return;
var sel=document.getElementById('lg-guide-select');
if(sel)sel.addEventListener('change',function(){if(this.value&&this.value!==location.href)location.href=this.value;});
/* Themes that turn every select into Select2 (Nioland): give this one back its own dropdown. */
function unSelect2(){var $=window.jQuery;if(!sel||!$)return;var $s=$(sel);
 if($s.data('select2')||$s.hasClass('select2-hidden-accessible')){try{$s.select2('destroy');}catch(e){}}
 $s.removeClass('select2-hidden-accessible').removeAttr('aria-hidden').removeAttr('tabindex').removeAttr('data-select2-id');
 [].forEach.call(root.querySelectorAll('.lg-switch .select2-container'),function(c){c.parentNode.removeChild(c);});
 $s.off('change.lg').on('change.lg',function(){if(this.value&&this.value!==location.href)location.href=this.value;});}
if(document.readyState!=='complete'){window.addEventListener('load',function(){unSelect2();setTimeout(unSelect2,300);setTimeout(unSelect2,1200);});}else{unSelect2();}
if(window.jQuery)window.jQuery(function(){setTimeout(unSelect2,0);});
root.querySelectorAll('.tabs').forEach(function(t){var bs=t.querySelectorAll('.tabs__btn'),ps=t.querySelectorAll('.tabs__pane');bs.forEach(function(b){b.addEventListener('click',function(){bs.forEach(function(x){x.classList.toggle('is-on',x===b);});ps.forEach(function(p){p.hidden=p.getAttribute('data-pane')!==b.getAttribute('data-tab');});});});});
root.querySelectorAll('.code__copy').forEach(function(b){b.addEventListener('click',function(){var c=b.closest('.code').querySelector('code').innerText;var done=function(){b.textContent='Copied!';setTimeout(function(){b.textContent='Copy';},1500);};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(c).then(done);}else{var a=document.createElement('textarea');a.value=c;document.body.appendChild(a);a.select();try{document.execCommand('copy');done();}catch(e){}document.body.removeChild(a);}});});
var links=[].slice.call(root.querySelectorAll('.lg-toc a'));var secs=links.map(function(a){return document.getElementById(a.hash.slice(1));});
var reduce=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
function mark(){var y=window.scrollY+140,cur=0;secs.forEach(function(s,i){if(s&&s.getBoundingClientRect().top+window.scrollY<=y)cur=i;});links.forEach(function(a,i){a.classList.toggle('is-on',i===cur);});}
links.forEach(function(a,i){a.addEventListener('click',function(ev){if(!secs[i])return;ev.preventDefault();secs[i].scrollIntoView({behavior:reduce?'auto':'smooth',block:'start'});history.replaceState(null,'',a.hash);});});
root.querySelectorAll('.lg-top').forEach(function(a){a.addEventListener('click',function(ev){ev.preventDefault();root.scrollIntoView({behavior:reduce?'auto':'smooth'});});});
window.addEventListener('scroll',mark,{passive:true});mark();
if('IntersectionObserver' in window&&!reduce){root.classList.add('lg-anim');var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add('is-in');io.unobserve(x.target);}});},{threshold:.08});secs.forEach(function(s){if(s)io.observe(s);});}
})();
</script>'''

def wrap_tables(h):
    return h.replace('<table>', '<div class="lg-table"><table>').replace('</table>', '</table></div>')

def switcher(cur):
    groups = {}
    for g in G:
        groups.setdefault(g.get('family', 'Rinnovo'), []).append(g)
    s = '<select id="lg-guide-select" class="lg-guide-select" aria-label="Open another guide" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;background-color:transparent !important;opacity:1 !important">'
    for fam, items in groups.items():
        s += f'<optgroup label="{e(fam)}">' + ''.join(f'<option style="color:#0f172a;-webkit-text-fill-color:#0f172a;background-color:#ffffff" value="{URL}{MAP[g["slug"]][0]}/"{" selected" if g["slug"] == cur else ""}>{e(g["title"])}</option>' for g in items) + '</optgroup>'
    return s + '</select>'

for g in G:
    g = dict(g, sections=START.get(g['slug'], []) + g['sections'] + REF.get(g['slug'], []))
    fam = g.get('family', 'Rinnovo')
    post_slug, cat = MAP[g['slug']][0], MAP[g['slug']][1]
    ids = [slugify(h) for h, _ in g['sections']]
    toc = '<nav class="lg-toc" aria-label="In this guide"><p>In this guide</p><ol>' + ''.join(f'<li><a href="#{i}">{e(h)}</a></li>' for i, (h, _) in zip(ids, g['sections'])) + '</ol></nav>'
    secs = ''.join(f'<section class="lg-sec" id="{i}"><h2><span>{n}</span>{e(h)}</h2>' + wrap_tables(''.join(block(b) for b in blocks)) + '<a class="lg-top" href="#lg-guide">↑ Back to top</a></section>' for n, (i, (h, blocks)) in enumerate(zip(ids, g['sections']), 1))
    works = '<div class="lg-works"><b>Works great with</b><ul>' + ''.join(f'<li><a href="{u}">{e(n)}</a>: {e(t)}</li>' for n, u, t in WORKS[fam]) + '</ul></div>'
    code = f'''<!--
  {g["title"]} – Elementor HTML widget for laghavi.site/guides/{post_slug}/
  Guides › Add guide (title "{g["title"]}", slug "{post_slug}", guide category "{cat}") › Edit with Elementor.
  Add a full-width container with no padding, drop in an "HTML" widget and paste everything in this file.
-->
{CSS}
<div id="lg-guide">
  <header class="lg-hero">
    <span class="lg-badge">{e(g["product"])}</span>
    <h1>{e(g["title"])}</h1>
    <p>{e(g["intro"])}</p>
    <div class="lg-switch"><label>Guide for</label>{switcher(g["slug"])}<a href="{URL}">All guides →</a></div>
  </header>
  <div class="lg-layout">
    {toc}
    <main class="lg-body">{secs}{works}</main>
  </div>
</div>
{JS}
'''
    open(os.path.join(out_dir, post_slug + '.html'), 'w').write(code)
print('wrote', len(G), 'widgets to', out_dir)
