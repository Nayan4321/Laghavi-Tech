"""Make a WordPress import file (Tools > Import > WordPress) with every guide as a Guide post (post type
"guide") in a guide category connected to its product. Run: python3 website/rinnovo-guides/src/wxr.py"""
import html, os, re, sys
from datetime import datetime, timezone
here = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, here)
from guides import G
from build import block, WORKS, slugify

PRO = ['rinnovo-subscriptions-licenses-pro', 'rinnovo-physical-subscription-boxes-pro', 'rinnovo-membership-content-pro',
       'rinnovo-media-archives-pro', 'rinnovo-service-contracts-pro', 'rinnovo-session-membership-pro',
       'rinnovo-bundles-variable-subscriptions-pro']
# guide slug -> (post slug, category name, category slug, product slugs, order)
MAP = {
 'rinnovo-guide-shared': ('every-rinnovo-pro', 'Rinnovo Pro guides', 'rinnovo-pro-guides', PRO, 0),
 'rinnovo-guide-subscriptions-licenses-pro': ('rinnovo-subscriptions-licenses-pro', 'Rinnovo Subscriptions & Licenses Pro guide', 'rinnovo-subscriptions-licenses-pro-guide', [PRO[0]], 1),
 'rinnovo-guide-boxes-pro': ('rinnovo-boxes-pro', 'Rinnovo Boxes Pro guide', 'rinnovo-boxes-pro-guide', [PRO[1]], 2),
 'rinnovo-guide-membership-pro': ('rinnovo-membership-content-pro', 'Rinnovo Membership Content Pro guide', 'rinnovo-membership-content-pro-guide', [PRO[2]], 3),
 'rinnovo-guide-media-archives-pro': ('rinnovo-media-archives-pro', 'Rinnovo Media Archives Pro guide', 'rinnovo-media-archives-pro-guide', [PRO[3]], 4),
 'rinnovo-guide-service-contracts-pro': ('rinnovo-service-contracts-pro', 'Rinnovo Service Contracts Pro guide', 'rinnovo-service-contracts-pro-guide', [PRO[4]], 5),
 'rinnovo-guide-session-pro': ('rinnovo-session-membership-pro', 'Rinnovo Session Membership Pro guide', 'rinnovo-session-membership-pro-guide', [PRO[5]], 6),
 'rinnovo-guide-bundles-pro': ('rinnovo-bundles-variable-subscriptions-pro', 'Rinnovo Bundles & Variable Subscriptions Pro guide', 'rinnovo-bundles-variable-subscriptions-pro-guide', [PRO[6]], 7),
 'sarala-guide': ('sarala-login-page', 'Sarala Login Page guide', 'sarala-login-page-guide', ['sarala-login-page-pro'], 8),
}
cdata = lambda t: '<![CDATA[' + t.replace(']]>', ']]]]><![CDATA[>') + ']]>'
x = html.escape

def body(g):
    fam = g.get('family', 'Rinnovo')
    out = f'<!-- wp:paragraph --><p><strong>{x(g["product"])}</strong>. {x(g["intro"])}</p><!-- /wp:paragraph -->\n'
    for h, blocks in g['sections']:
        out += f'<!-- wp:heading --><h2 class="wp-block-heading" id="{slugify(h)}">{x(h)}</h2><!-- /wp:heading -->\n'
        out += '<!-- wp:html -->' + ''.join(block(b) for b in blocks) + '<!-- /wp:html -->\n'
    works = '<div class="llm-works"><strong>Works great with</strong><ul>' + ''.join(f'<li><a href="{u}">{x(n)}</a>: {x(t)}</li>' for n, u, t in WORKS[fam]) + '</ul></div>'
    return out + '<!-- wp:html -->' + works + '<!-- /wp:html -->\n'

if __name__ == '__main__':
    now = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    terms, items = '', ''
    for i, g in enumerate(G, 1):
        slug, cat, cslug, prods, order = MAP[g['slug']]
        terms += f'''	<wp:term><wp:term_id>{900 + i}</wp:term_id><wp:term_taxonomy>guide_category</wp:term_taxonomy><wp:term_slug>{cdata(cslug)}</wp:term_slug><wp:term_parent></wp:term_parent><wp:term_name>{cdata(cat)}</wp:term_name><wp:termmeta><wp:meta_key>{cdata('_llm_guide_product_slugs')}</wp:meta_key><wp:meta_value>{cdata(','.join(prods))}</wp:meta_value></wp:termmeta></wp:term>
    '''
        items += f'''	<item>
    		<title>{cdata(g['title'])}</title>
    		<dc:creator>{cdata('admin')}</dc:creator>
    		<content:encoded>{cdata(body(g))}</content:encoded>
    		<excerpt:encoded>{cdata(g['intro'][:250])}</excerpt:encoded>
    		<wp:post_id>{9000 + i}</wp:post_id>
    		<wp:post_date>{cdata(now)}</wp:post_date>
    		<wp:post_date_gmt>{cdata(now)}</wp:post_date_gmt>
    		<wp:comment_status>{cdata('closed')}</wp:comment_status>
    		<wp:ping_status>{cdata('closed')}</wp:ping_status>
    		<wp:post_name>{cdata(slug)}</wp:post_name>
    		<wp:status>{cdata('publish')}</wp:status>
    		<wp:post_parent>0</wp:post_parent>
    		<wp:menu_order>{order}</wp:menu_order>
    		<wp:post_type>{cdata('guide')}</wp:post_type>
    		<wp:post_password>{cdata('')}</wp:post_password>
    		<wp:is_sticky>0</wp:is_sticky>
    		<category domain="guide_category" nicename="{cslug}">{cdata(cat)}</category>
    	</item>
    '''
    xml = f'''<?xml version="1.0" encoding="UTF-8" ?>
    <rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
    	<title>Laghavi guides</title>
    	<link>https://laghavi.site</link>
    	<description>Rinnovo and Sarala customer guides</description>
    	<language>en-US</language>
    	<wp:wxr_version>1.2</wp:wxr_version>
    	<wp:base_site_url>https://laghavi.site</wp:base_site_url>
    	<wp:base_blog_url>https://laghavi.site</wp:base_blog_url>
    {terms}{items}</channel>
    </rss>
    '''
    out = os.path.join(os.path.dirname(here), 'import', 'laghavi-guides.xml')
    open(out, 'w').write(xml)
    print('wrote', out, len(G), 'guides')
