# Content of the seven Rinnovo Pro product pages. Every Pro is an equal branch of the Rinnovo family:
# same page structure, same shared parts, each sold and usable on its own.

PRICES = {
    'rinnovo-subscriptions-licenses-pro': (99, 199, 399),
    'rinnovo-physical-subscription-boxes-pro': (129, 259, 499),
    'rinnovo-membership-content-pro': (149, 299, 599),
    'rinnovo-media-archives-pro': (149, 299, 599),
    'rinnovo-service-contracts-pro': (119, 239, 479),
    'rinnovo-session-membership-pro': (129, 259, 499),
    'rinnovo-bundles-variable-subscriptions-pro': (99, 199, 399),
}

VERSIONS = {
    'rinnovo-subscriptions-licenses-pro': '1.3.0',
    'rinnovo-physical-subscription-boxes-pro': '1.1.0',
    'rinnovo-membership-content-pro': '1.1.0',
    'rinnovo-media-archives-pro': '1.1.0',
    'rinnovo-service-contracts-pro': '1.1.0',
    'rinnovo-session-membership-pro': '1.1.0',
    'rinnovo-bundles-variable-subscriptions-pro': '1.1.0',
}

# Short name, branch name, for whom.
PROS = {}

PROS['rinnovo-subscriptions-licenses-pro'] = dict(
    name='Rinnovo Subscriptions & Licenses Pro',
    short='Rinnovo Pro',
    branch='Billing, licenses and tax',
    tagline='Sell any subscription or software license with WooCommerce: trials that convert, invoices and credit notes, VAT/GST, multi-currency and license keys.',
    short_desc=[
        'Everything around the money of a subscription business: free trials and first-payment offers, tax invoices and credit notes, VAT/GST numbers with reverse charge, fixed or converted prices per currency and license keys that follow every upgrade and renewal.',
        'Works for any subscription product, and alone or next to the other Rinnovo Pro plugins.',
    ],
    h2='Subscriptions and licenses that bill correctly everywhere',
    sections=[
        ('Trials that convert', [
            '<strong>Free or paid trials</strong> with or without a card, and first-payment discounts.',
            '<strong>One trial per customer</strong> (by account, email and card where the gateway gives it), so trials can\'t be repeated.',
            'Trial-ending reminder emails, and a clear end date and first renewal amount on the product page, checkout, emails and My Account.',
            'You choose what happens when the first payment after a trial fails.',
        ]),
        ('Invoices and credit notes', [
            '<strong>Tax invoices</strong> on every order and renewal: 3 templates, your logo and colours, company and tax details.',
            '<strong>Credit notes for refunds</strong>, full or partial, linked to the original invoice.',
            'Numbers that never repeat, even with payments at the same moment, with financial-year numbering ({fy}).',
            'Issued invoices never change: corrections are credit notes, and each invoice keeps a snapshot of the details at issue time.',
            'CSV export of invoices and tax for your accountant.',
        ]),
        ('VAT and GST', [
            'VAT/GST number at checkout, checked with VIES for EU numbers.',
            '<strong>Reverse charge by rules:</strong> per country, customer type and where you are. A valid number alone doesn\'t switch tax off.',
            'Location evidence (billing address, IP and more) and a record of every tax decision, plus each customer\'s tax-exemption history.',
        ]),
        ('Currencies', [
            '<strong>Converted prices</strong> (rate × base price, daily rates, rounding) or <strong>fixed prices per currency</strong>.',
            'A subscription keeps its currency and amount: rate updates never change an existing renewal.',
            'Warnings when a payment method can\'t charge a currency for renewals; currency switcher widget and shortcode.',
        ]),
        ('Licenses and plans', [
            'License keys with sites per variation (1 site, 5 sites, unlimited).',
            '<strong>Licenses follow plan changes:</strong> upgrades, downgrades and renewals update sites and expiry, with a full history.',
            'Subscriptions per variation (monthly, yearly, lifetime) on one product.',
        ]),
        ('"Ask for price" and quotes', [
            'Replace the price with an "Ask for price" button that opens your contact page or WhatsApp.',
            'Every enquiry is saved: product, variation, quantity, customer and status (new, contacted, quoted, won, lost), with a one-click order.',
        ]),
    ],
    seo=('Rinnovo Pro – WooCommerce Subscription Trials, Invoices, VAT & Licenses', 'woocommerce subscription invoices',
         'Trials that convert, invoices and credit notes, VAT/GST with reverse charge rules, fixed or converted currency prices and license keys for WooCommerce subscriptions.'),
    guide='Rinnovo Subscriptions & Licenses Pro guide',
)

PROS['rinnovo-physical-subscription-boxes-pro'] = dict(
    name='Rinnovo Physical Subscription Boxes & Goods Pro',
    short='Boxes Pro',
    branch='Subscription boxes and recurring goods',
    tagline='Run a subscription box or a subscribe & save shop: customers build, skip and swap their boxes; you bill on one day, pack from one list and ship.',
    short_desc=[
        'For coffee, snacks, meal kits, flowers, pet food, cosmetics and anything you ship again and again. A guided setup picks the right model: a recurring box, subscribe & save on products you already sell, or a build-your-own box.',
        'Customers choose a delivery frequency with one tap, build and change their box, skip, swap and send boxes as gifts. You get a delivery calendar, prepaid tracking and packing lists.',
    ],
    h2='Subscription boxes that build, pack and ship on schedule',
    sections=[
        ('Guided setup', [
            'Pick one of three paths: <strong>recurring subscription box</strong>, <strong>subscribe & save</strong> on an existing product, or a <strong>build-your-own box</strong>. Only the settings you need are shown.',
        ]),
        ('Box builder (included)', [
            'Fixed boxes, pick-your-items boxes, sized boxes (6, 12 or 24 items) and <strong>rotating menus</strong> with selection deadlines (meal kits, lunch boxes, flower or fruit boxes).',
            'Step-by-step builder with a live price, min/max per item, box-specific prices or extra charges and stock checks.',
            'Every box is saved as a snapshot of items, variations, quantities and prices.',
        ]),
        ('Frequencies', [
            'Every week, 2, 4 or 6 weeks, month, 3 months… shown as tap-to-choose chips; customers switch later.',
            'First-box discount, free trial and a packaging or setup fee with your own name.',
        ]),
        ('Customers manage their boxes', [
            '<strong>Skip, swap and change the next box</strong> before a cut-off, so a box being packed never changes.',
            '<strong>Swap price rules:</strong> charge, refund or credit the difference, or keep the price.',
            '<strong>Out-of-stock rules:</strong> substitute, skip the item or hold the box.',
        ]),
        ('Gifts and prepaid plans', [
            'Send a box as a gift: recipient name and email, start date, message; the recipient gets their own page.',
            'Pay once for 3, 6 or 12 boxes: each box becomes its own order on its date, with the boxes left always visible.',
        ]),
        ('Shipping and packing', [
            'One shipping day for everyone; renewal shipping from your zones (flat, table or live carrier rates), recalculated when the address changes.',
            '<strong>Delivery calendar</strong> and prepaid tracking screens, orders to pack with item totals, CSV for your label printer, and expected stock for the next weeks.',
        ]),
    ],
    seo=('Rinnovo Boxes Pro – WooCommerce Subscription Box & Box Builder Plugin', 'woocommerce subscription box',
         'Run a WooCommerce subscription box: build-your-own boxes, rotating menus, skip and swap, gifts, prepaid plans, one shipping day, delivery calendar and packing lists.'),
    guide='Rinnovo Boxes Pro guide',
)

PROS['rinnovo-membership-content-pro'] = dict(
    name='Rinnovo Membership Content Pro',
    short='Membership Pro',
    branch='Memberships, courses and resource libraries',
    tagline='Three businesses on one engine: paid memberships, courses sold as products and download libraries with credits.',
    short_desc=[
        'Sell memberships with tiers and groups, drip content on a timeline, sell every course as a WooCommerce product, and run a resource library where members spend download credits.',
        'Access is worked out from everything a member owns (memberships, course purchases, manual grants), so cancelling a membership never removes a course they bought separately.',
    ],
    h2='Memberships, courses and downloads that keep access right',
    sections=[
        ('Membership groups and tiers', [
            'Independent programmes (Business training: Silver, Gold, Platinum; Photography: Basic, Premium; VIP community), each with its own tiers.',
            'Several memberships at once: access comes from all of them together.',
            'Trials, invite-only memberships, gift memberships and membership bundles.',
        ]),
        ('Lifecycle rules', [
            'What happens on payment, failed payment, grace period, cancel, refund, upgrade, downgrade and expiry — set once, applied everywhere.',
            'Upgrades work at once; downgrades at the end of the paid period.',
        ]),
        ('Drip timeline', [
            'Day 1 welcome, day 7 module 2, day 30 premium resources: content opens by days of membership or on a date, shown as a timeline.',
        ]),
        ('Course commerce', [
            'Map any product to LearnDash, LifterLMS or a category of course pages: access length, start, lifetime or limited.',
            'Course bundles (one product, several courses) and one course unlocked by several products; a course-vs-tier matrix.',
            'Products connected to categories: buying the product opens every page in the category.',
        ]),
        ('Download plans and credits', [
            'A stock-library style resource library: previews, file type, size and credit cost per item.',
            'Per-category quotas or one shared credit wallet, reset periods, carry-over, daily limits, and top-up packs that never expire with the monthly reset.',
            'Credits are taken in one safe step with a full history; files never have a public URL.',
        ]),
        ('Protected files and video', [
            'Expiring, checked downloads (X-Sendfile / X-Accel), no-store headers and download limits.',
            'Bunny, Vimeo and Wistia privacy per video, resume playback, progress and stream limits (with an honest warning about YouTube unlisted).',
        ]),
        ('My Membership and page builders', [
            'A My Membership dashboard: plan, renewal, credits left, courses, downloads, quick links.',
            '<strong>Hide or show any section, column, widget, tab or block</strong> by membership in Elementor, the block editor and Bricks, removed on the server; dynamic tags for name, tier, renewal date and credits.',
            'REST API, webhooks, member notes, announcements and access logs.',
        ]),
    ],
    seo=('Rinnovo Membership Pro – WooCommerce Memberships, Courses & Download Credits', 'woocommerce membership plugin',
         'WooCommerce memberships with groups and tiers, drip timelines, courses sold as products, download credits and a resource library, and members-only sections in Elementor and the block editor.'),
    guide='Rinnovo Membership Content Pro guide',
)

PROS['rinnovo-media-archives-pro'] = dict(
    name='Rinnovo Media Archives Pro',
    full_name='Rinnovo Media Archives Pro – Subscription-Based Media Hosting, Streaming & Digital Distribution',
    short='Media Archives Pro',
    branch='Media hosting, streaming and distribution',
    tagline='Subscription-based media hosting, streaming and digital distribution for podcast networks, video educators, stock media sites, agencies and production houses.',
    short_desc=[
        'Sell access to a video library, a premium podcast, a stock media library or private client portals. A setup wizard picks the model and shows only what it needs.',
        'Every play and download goes through one security pipeline: who is asking, what their plan allows, short-lived signed links, then tracking.',
    ],
    h2='Your own subscription streaming and media platform',
    sections=[
        ('Four business models', [
            '<strong>Video streaming library:</strong> browse, search, filter, watch, resume, playlists, favourites and history.',
            '<strong>Premium podcast network:</strong> free and premium episodes, seasons, a private RSS feed per subscriber that you can rotate when it\'s shared.',
            '<strong>Stock media library:</strong> watermarked previews, collections, download quotas per plan and per asset.',
            '<strong>Private media portals:</strong> client-specific collections and permissions with your own branding.',
        ]),
        ('Security pipeline', [
            'Identity, plan, device and stream limits are checked, then a short-lived signed link or provider token is issued (Bunny, Cloudflare Stream, S3/CloudFront).',
            'Keys stay on the server; signed webhooks with replay protection, rate limits, device identification and configurable geo alerts.',
            'Honest by design: signed links are not DRM.',
        ]),
        ('Media account portal', [
            'Continue watching, video, podcasts and media library, plan and benefits, devices, active streams, downloads, feeds, billing and security alerts.',
            'Grid, list or carousel, columns, thumbnail ratio, player colours and separate templates for video, audio and galleries.',
        ]),
        ('Run the library', [
            'Collections, playlists and seasons, scheduled publishing, free previews, expiring share links and several libraries (brands or clients).',
            'Analytics of plays, downloads and watch time; usage add-ons that sell extra streams or downloads.',
            'Tabs for Plans & Quotas, Media Providers, Protection, Apps & Integrations, and Security & Activity.',
        ]),
    ],
    seo=('Rinnovo Media Archives Pro – Subscription Media Hosting, Streaming & Distribution', 'woocommerce video streaming subscription',
         'Sell subscription access to video libraries, premium podcasts with private feeds, stock media and client portals with WooCommerce, with signed links, stream limits and analytics.'),
    guide='Rinnovo Media Archives Pro guide',
)

PROS['rinnovo-service-contracts-pro'] = dict(
    name='Rinnovo Service Contracts Pro',
    short='Service Contracts Pro',
    branch='Retainers and service agreements',
    tagline='Sell retainers with several allowances, take client requests, log work and send a monthly report — with tax invoices for India, the UAE, the EU and the UK.',
    short_desc=[
        'For agencies, consultants and freelancers: a retainer of 10 consulting hours, 8 articles and 4 technical requests a month, each with its own rollover, maximum and expiry.',
        'Clients submit requests in their own My Service Contracts page; approved work reserves the allowance, completed work uses it, and a monthly report shows everything delivered.',
    ],
    h2='Retainers that track every hour and deliverable',
    sections=[
        ('Allowances', [
            'Several allowances per contract, each with a unit, rollover rule, maximum and expiry.',
            'A ledger of every use (date, quantity, request, contract): reserved, approved and completed work can\'t double-spend the balance.',
        ]),
        ('Requests and deliverables', [
            'Clients submit requests; you approve or reject (unapproved work never uses the allowance).',
            'Pending, in progress, review, completed, with revisions counted; work log with staff assignment, notes and attachments.',
        ]),
        ('Monthly service report', [
            'At the end of each period: 8/10 hours used, 6/8 articles delivered, requests completed and pending — emailed and in the client\'s account.',
        ]),
        ('Terms and cancellation', [
            'Minimum term and notice period; cancellation requested, scheduled and terminated.',
            'Onboarding fee charged at once, on the first invoice or separately, never prorated; first-month proration by your billing day and time zone.',
            'A PDF of the agreed terms for each contract.',
        ]),
        ('Invoices and approval', [
            'Tax invoices with SAC/HSN, GSTIN and CGST/SGST/IGST, UAE VAT and EU/UK VAT; financial-year numbering.',
            'Two approval workflows: the client approves before the invoice is issued, or approves an issued invoice for payment, with history.',
        ]),
        ('Your tools', [
            'Monthly tasks in Trello or ClickUp, CRM sync and webhooks — credentials encrypted, webhooks signed and retried, tasks never duplicated.',
        ]),
    ],
    seo=('Rinnovo Service Contracts Pro – WooCommerce Retainers, Allowances & Reports', 'woocommerce retainer plugin',
         'Sell retainers with WooCommerce: several allowances with rollover, client requests and approvals, work logs, monthly reports, cancellation terms and GST/VAT invoices.'),
    guide='Rinnovo Service Contracts Pro guide',
)

PROS['rinnovo-session-membership-pro'] = dict(
    name='Rinnovo Session Membership Pro',
    short='Session Pro',
    branch='Classes, sessions and studio memberships',
    tagline='Class memberships, packs and passes for studios, gyms, schools and coaches, with a credit ledger, family profiles and QR check-in.',
    short_desc=[
        'Sell 12 classes a month, unlimited passes, 10-class packs valid 90 days, family passes, private session packages, trials and hybrid plans (8 group + 2 private).',
        'Members book from a schedule of class cards, cancel and reschedule themselves and check in with a QR code; you see utilisation, attendance and instructor rosters.',
    ],
    h2='Class bookings that keep every credit right',
    sections=[
        ('Every pricing model', [
            'Monthly credits, unlimited, packs with their own expiry, family pools, private sessions, trials, top-ups and credits per class type.',
            '<strong>Credit ledger:</strong> reserve, confirm, deduct or restore, each with a reason; two bookings can never take the same last credit.',
        ]),
        ('Schedule and booking', [
            '[session_schedule] class cards: time, room or online, instructor, places left, credit cost, Book or Join waitlist, with category and location filters.',
            'Recurring reservations (every Monday and Wednesday 6 PM for 4 weeks) that stop when the membership ends.',
            'Guest and trial bookings, class-specific restrictions and self-service cancel and reschedule.',
        ]),
        ('Families', [
            'Each family member has their own profile, bookings, attendance and waiver; shared pool, separate allowances or both.',
        ]),
        ('Classes and instructors', [
            'Time zone per class, holidays and blackout dates, one-date cancellations and substitute instructors — booked members are told and credits come back.',
            'Instructors see only their own classes; progress notes with retention rules.',
        ]),
        ('Check-in and reports', [
            'QR code or short code check-in; missed-class notifications.',
            'Utilisation, attendance, no-shows and late cancels per class; instructor roster with days away.',
        ]),
        ('Connect your booking tool', [
            'Adapters for WooCommerce Bookings, Amelia and Bookly: bookings, cancellations and reschedules take and return credits, with duplicate protection.',
            'Zoom meeting per session, calendar feed and SMS reminders.',
        ]),
    ],
    seo=('Rinnovo Session Pro – WooCommerce Class Memberships, Packs & Bookings', 'woocommerce class booking membership',
         'Class memberships, packs, family passes and hybrid plans for WooCommerce with a credit ledger, class schedule, recurring bookings, QR check-in and booking tool adapters.'),
    guide='Rinnovo Session Membership Pro guide',
)

PROS['rinnovo-bundles-variable-subscriptions-pro'] = dict(
    name='Rinnovo Bundles & Variable Subscriptions Pro',
    short='Bundles Pro',
    branch='Bundles and plan switching',
    tagline='Subscription bundles with exact component stock, and variable plans customers switch between with clear proration.',
    short_desc=[
        'Fixed, mix-and-match or mixed bundles with optional extras and premium picks; every renewal takes the exact component stock, and a renewal waits when a critical item is out of stock.',
        'Variable subscriptions (Basic / Premium, monthly / yearly) with a plan table and switching with proration.',
    ],
    h2='Bundles and plans that renew exactly as sold',
    sections=[
        ('Bundles', [
            'Fixed components, selectable components with min/max, optional fixed-price extras and premium picks that cost more; exclusions for combinations that don\'t go together.',
            'Flat or dynamic price with a bundle discount; you choose how a flat price is split for tax, refunds and reports.',
            'Built on the shared Rinnovo box builder (included).',
        ]),
        ('Stock and renewals', [
            'Exact quantities per component on first orders and every renewal, never taken twice when a renewal is retried.',
            '<strong>Out-of-stock protection:</strong> the renewal is held with a resolution screen: replace, skip the item, partial ship, delay or refund the difference, then release.',
        ]),
        ('Customers', [
            'Change the next box only, copy the previous one, save favourites, skip or replace one component for the next shipment.',
        ]),
        ('Variable plans', [
            'A plan table per variation (price, period, sign-up fee, trial), shown to shoppers as a comparison.',
            'Monthly ⇄ yearly and Basic ⇄ Premium switching with proration, a new renewal date and the payment adjustment.',
        ]),
        ('Operations', [
            'Bundle coupons, shipping weight from components, order breakdown, packing list CSV and a replace tool for discontinued products.',
            'One renewal statement for several independent subscriptions, without merging their records.',
        ]),
    ],
    seo=('Rinnovo Bundles Pro – WooCommerce Subscription Bundles & Plan Switching', 'woocommerce subscription bundles',
         'Subscription bundles with exact component stock, out-of-stock renewal protection, next-box changes and variable plans with proration switching for WooCommerce.'),
    guide='Rinnovo Bundles & Variable Subscriptions Pro guide',
)

# The same in every Pro.
SHARED = [
    '<strong>Invoices</strong> on every order and renewal (India GST, UAE VAT, EU/UK VAT with reverse charge, credit notes for refunds) — set up once under WooCommerce › Invoices for all your Rinnovo plugins.',
    '<strong>Page builder widgets</strong> for Elementor and the block editor, plus shortcodes for Divi, Beaver Builder, Bricks and others.',
    '<strong>Insights data</strong> for the Performance Insight for WooCommerce dashboard: MRR, churn, renewals and this plugin\'s own numbers.',
    '<strong>Customer guide:</strong> step-by-step guides in your account while your license is active.',
    '<strong>The free Rinnovo Subscriptions & Licenses plugin</strong> underneath: subscriptions on any product, Stripe and saved-card renewals, "Pay now" emails for every other payment method, and a better My Account.',
]

WORKS_WITH = [
    ('Sarala Login Page', 'designed log-in, sign-up and account pages with Google, Apple and other social log-ins.'),
    ('Vacanam Language Switcher', 'a multilingual store, with emails and invoices in the customer\'s language.'),
    ('Performance Insight for WooCommerce', 'cookieless analytics and SEO, with every Rinnovo plugin\'s numbers and what to follow up.'),
]
