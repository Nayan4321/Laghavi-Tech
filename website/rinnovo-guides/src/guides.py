# Customer guides for the Rinnovo Pro plugins. Each guide: slug, title, intro, sections [(heading, blocks)].
# A block is a paragraph string, ('ul', [...]), ('ol', [...]) or ('table', [header], [rows]).

G = []

G.append(dict(slug='rinnovo-guide-shared', title='Every Rinnovo Pro: invoices, page builders and insights', product='All Rinnovo Pro plugins',
intro='These parts are the same in all seven Rinnovo Pro plugins. Set them up once; they keep working when you add another Pro.',
sections=[
('What you install', [
 'Every Pro download is a package with two zips. Unzip the package on your computer, then upload both zips in Plugins › Add New › Upload: the Pro plugin and the Rinnovo Box Builder. The free Rinnovo Subscriptions & Licenses plugin from WordPress.org must be active too.',
 ('ol', ['Install and activate the free Rinnovo Subscriptions & Licenses plugin.', 'Upload and activate the Pro zip, then the Rinnovo Box Builder zip.', 'Open the Pro\'s license screen under WooCommerce, paste your license key and click Activate.', 'Automatic updates now arrive in Dashboard › Updates.']),
 'The Box Builder has no license of its own and keeps working if a license expires. It only runs while at least one Rinnovo Pro is active.']),
('Invoices, set up once for all Pros', [
 'Go to WooCommerce › Invoices. Whichever Pros you use, there is one invoice engine: one invoice per order, one number sequence, one settings screen.',
 ('ol', ['Company: legal name, address, logo, tax number (TRN, GSTIN or VAT number) and the label printed before it.', 'Numbering: prefix, digits and next number. Add {fy} to restart numbering each financial year, for example INV-{fy}-0001.', 'Template: pick one of three designs and a colour, then preview.', 'Customer details: whether business customers can, must or cannot add a company name and tax number at checkout.', 'Emails: attach the PDF to order emails, or add a download link.']),
 ('table', ['Where you sell', 'What the invoice shows'], [
   ['India (GST)', 'GSTIN, SAC/HSN codes per product, CGST + SGST inside your state, IGST between states, export without GST'],
   ['UAE (VAT)', 'TRN, "Tax Invoice" title, VAT per line and total in AED'],
   ['EU / UK (VAT)', 'VAT number, reverse charge note when it applies, VAT per rate'],
   ['Anywhere else', 'A plain invoice with your company details']]),
 'Refunds create a credit note linked to the original invoice, for the full or the refunded amount. An issued invoice never changes: a correction is always a credit note or a corrected document that points to the original.']),
('Page builders: widgets and members-only sections', [
 'Every Pro adds its widgets to the "Rinnovo" category in Elementor and in the block editor (for example Class schedule, Contract quota, Media library, Bundle builder, Plan table). In Divi, Beaver Builder, Bricks, WPBakery, Oxygen or Breakdance, paste the shortcode into a shortcode or text module.',
 'With Membership Content Pro you can also hide or show any part of a page by membership:',
 ('ol', ['Elementor: select the section, container, column or widget (a tab or heading too) › Advanced › Members only (Rinnovo). Choose Show to (logged in, any member, a group, a tier or higher, non-members, expired members, course owners, a download plan) and an optional "Show instead" teaser.', 'Block editor: select any block › the Members only panel in the sidebar › the same choices.', 'Click Update. Hidden parts are removed on the server, so they never reach the visitor\'s browser, and members-only pages are not served from the page cache.']),
 'Screenshots of both screens are on the guide page in your account.']),
('Insights for your dashboard', [
 'Each Pro shares read-only numbers with the WooCommerce performance insights dashboard: MRR and ARR, churn, renewals, trials and the Pro\'s own figures (boxes, members, plays, contract quotas, class utilisation, bundles), plus a list of things to follow up. Only shop managers can see them. Developers can read the same data with rinnovo_insights( $section ) or GET /wp-json/rinnovo/v1/insights?section=sections.']),
]))

G.append(dict(slug='rinnovo-guide-subscriptions-licenses-pro', title='Rinnovo Subscriptions & Licenses Pro guide', product='Rinnovo Subscriptions & Licenses Pro',
intro='Trials, first-payment offers, VAT/GST, currencies, license keys and "Ask for price". Settings are in WooCommerce › Settings › Rinnovo; each product has its own fields in the Subscription box.',
sections=[
('Free trials that convert', [
 ('ol', ['On the product: set Free trial (days) and, if you like, a First payment discount (percent or fixed).', 'Settings › Trials and first-payment offers: choose whether a trial needs a card (Card for free trials), Trials per customer (one per customer, one per product) and what happens when the First payment after a trial fails (cancel, or give some Days to pay after a trial).', 'Customers see the trial end date and the first renewal amount on the product page, at checkout, in emails and in My Account. A reminder email goes out before the trial ends.']),
 'One trial per customer is checked by account, email address and, when the payment method shares it, the card\'s fingerprint.']),
('VAT and GST', [
 'Turn on Business tax numbers to add a VAT / GST number field at checkout. EU numbers can be checked with VIES (choose what happens When VIES is down).',
 'Reverse charge is a separate decision from a valid number: the rules look at the customer\'s country, whether they are a business, where you are and what you sell. Every order stores the decision and the evidence used (billing address, IP address and more) under Location evidence, and each customer has a tax-exemption history.']),
('Currencies', [
 ('table', ['Mode', 'How prices are made', 'Good for'], [
   ['Converted', 'Base price × rate, with rounding; rates can update daily', 'Many currencies with little work'],
   ['Fixed', 'You type a price per currency on the product', 'Round local prices, price points per market']]),
 'A subscription keeps its currency and the amount paid at sign-up: later rate changes never change an existing renewal. The settings screen warns you when a payment method can\'t charge a currency for renewals. Show the switcher with the floating switcher, on checkout, or the [rinnovo_currency_switcher] shortcode / Currency switcher widget.']),
('Licenses that follow the plan', [
 'Set License: sites per key on each variation (1, 5, 0 = unlimited). When a customer upgrades, downgrades or renews, the key\'s sites and expiry are updated and the change is written to the license history on the subscription.']),
('"Ask for price" and quote enquiries', [
 ('ol', ['On the product tick Ask for price and write the Text shown instead of the price.', 'Pick the buttons to show: your Contact page or URL, and WhatsApp (number and message).', 'Every click is saved as an Enquiry with the product, variation, quantity and customer. Move it through new › contacted › quoted › won or lost, and turn a won enquiry into an order with one click.'])]),
('Invoices and credit notes', [
 'See the shared guide "Every Rinnovo Pro" for setting up invoices. This Pro adds the CSV export of invoices and tax for your accountant.']),
]))

G.append(dict(slug='rinnovo-guide-boxes-pro', title='Rinnovo Boxes Pro guide', product='Rinnovo Physical Subscription Boxes & Goods Pro',
intro='Subscription boxes, subscribe & save and build-your-own boxes. Day-to-day work is in WooCommerce › Boxes (calendar, prepaid plans, packing).',
sections=[
('Pick your path', [
 'New product › Subscription box opens a Step-by-step wizard with three paths:',
 ('table', ['Path', 'Choose it when', 'Example'], [
   ['Recurring subscription box', 'You sell one box that ships on a schedule', 'Monthly coffee box, snack box'],
   ['Subscribe & save', 'You already sell the products and want a subscription option with a discount', 'Pet food, shampoo, filters'],
   ['Build-your-own box', 'Customers choose what goes in the box', 'Meal kits, lunch boxes, flower or fruit boxes']])]),
('Frequencies', [
 'Tick the frequencies customers may choose (every week, 2, 4 or 6 weeks, every month, 3 months…). They appear as chips on the product. The frequency chosen replaces the product\'s default billing interval for that subscription; customers can switch later in My Account.']),
('Box builder', [
 ('table', ['Mode', 'How it works', 'Example'], [
   ['Fixed box', 'The same items every time, customers can swap within rules', 'A curated monthly snack box'],
   ['Pick your items', 'Customers choose items with Items per box: min / max', 'Choose 6 of 20 coffees'],
   ['Box sizes', 'Sizes of 6, 12 or 24 items as variations', 'Fruit box S / M / L'],
   ['Rotating menu', 'A new menu per delivery with a selection deadline; customers who don\'t choose get your default picks', 'Weekly meal kit, school lunches']]),
 'Prices: the box price, or the sum of the item prices plus extra charges. Each subscription keeps a snapshot of the items, variations, quantities and prices it was sold with.']),
('Swaps and out-of-stock items', [
 ('table', ['Setting', 'Options'], [
   ['Price after a swap', 'Charge the difference, refund it, credit it to the next box, or keep the price'],
   ['Item out of stock at renewal', 'Use a substitute, skip the item (and adjust the price) or hold the box until it\'s back']])]),
('Prepaid and gift plans', [
 'Prepaid boxes: the customer pays once for 3, 6 or 12 boxes (with a Prepaid discount). Each box becomes its own order on its delivery date; WooCommerce › Boxes › Prepaid shows boxes sent and left, and the next date.',
 'Gift plans: at checkout the buyer adds the recipient\'s name and email, a start date and a gift message. The recipient gets an email and their own page with the deliveries; the plan ends after the last prepaid box.']),
('Shipping', [
 'Shipping on renewals uses your shipping zones. When a customer changes their address, the next renewal\'s shipping is recalculated for the new zone, and card subscriptions are updated with the new amount. With One billing day for everyone every box is billed on the same Day of the month (or Weekday) so you pack in one batch.']),
('Calendar and packing', [
 'WooCommerce › Boxes › Calendar shows every delivery per day; Prepaid shows prepaid and gift plans; the Packing list gives item totals (40 × Dark roast) and a CSV for your label printer.']),
]))

G.append(dict(slug='rinnovo-guide-membership-pro', title='Rinnovo Membership Content Pro guide', product='Rinnovo Membership Content Pro',
intro='Memberships, courses and resource libraries on one engine. Settings are in WooCommerce › Membership; members see My Membership in their account.',
sections=[
('Groups and tiers', [
 'A group is an independent programme (Business training, Photography, VIP community). Inside a group, tiers inherit from lower tiers (Gold includes Silver). A member can belong to several groups at once; access is worked out from all their active memberships, course purchases and manual grants together.']),
('What happens when', [
 ('table', ['Event', 'Access'], [
   ['Payment succeeds', 'Membership starts or is renewed'],
   ['Payment fails', 'Grace period (days you set), then suspended'],
   ['Cancelled', 'Kept until the end of the paid period'],
   ['Refunded', 'Removed or shortened, by your refund setting'],
   ['Upgrade', 'Higher tier at once'],
   ['Downgrade', 'Lower tier from the next renewal'],
   ['Expired', 'Benefits removed'],
   ['Manual grant', 'Your own start and end dates']])]),
('Drip timeline', [
 'In the Members only box of a page or post, open it after a number of days of membership (Day 1 welcome, Day 7 module 2, Day 30 premium resources) or on a date. The drip timeline in My Membership shows members what opens next.']),
('Protected files and video', [
 'Files are never reachable by a public URL: downloads are checked on every request and served through the server (X-Sendfile or X-Accel when available), with no-store headers and limits per member or file.',
 ('table', ['Provider', 'How it is protected'], [
   ['Bunny Stream', 'Private library with token playback'],
   ['Vimeo', 'Privacy set to "hide from Vimeo" plus domain restriction to your site'],
   ['Wistia', 'Wistia privacy controls'],
   ['YouTube unlisted', 'Not protected: anyone with the link can watch. Use it only for previews.']])]),
('Selling courses', [
 ('ol', ['WooCommerce › Membership › Course & product mapping.', 'Pick a product, then one or more courses (LearnDash, LifterLMS, or a category of course pages).', 'Choose the access length (lifetime or months) and when it starts. Save.']),
 'Several courses on one product make a bundle; one course can be unlocked by several products (a standalone purchase or a Gold membership). The Courses and tiers matrix shows each course\'s price and which tiers include it. A course bought on its own stays when a membership ends.']),
('Download plans and credits', [
 ('ol', ['WooCommerce › Membership › Download plans: credits per period, reset, carry-over, maximum per day and at the same time.', 'Choose per-category quotas (photos 30, PSD 10, videos 5) or one shared wallet.', 'Add resources (Resources menu) with a file, preview and credit cost.', 'Sell top-ups as products with Download credits. Purchased credits are kept apart and never vanish with the monthly reset.']),
 'Members see their balance (32 of 50), reset date and download history in My Membership.']),
('Hiding parts of a page', [
 'See "Page builders" in the shared guide: Elementor Advanced › Members only (Rinnovo), or the Members only panel on any block.']),
]))

G.append(dict(slug='rinnovo-guide-media-archives-pro', title='Rinnovo Media Archives Pro guide', product='Rinnovo Media Archives Pro',
intro='Subscription-based media hosting, streaming and digital distribution. Settings: WooCommerce › Media Archives (Plans & Quotas, Media Providers, Protection, App & Integrations, Security & Activity). Content: the Media library menu and Overview & analytics.',
sections=[
('Pick your business model', [
 ('table', ['Model', 'For', 'You get'], [
   ['A. Video streaming library', 'Fitness trainers, educators, film libraries', 'Browse, search, filter, resume, playlists, favourites, history'],
   ['B. Premium podcast network', 'Podcasters with paid shows', 'Free and premium episodes, seasons, a private feed per subscriber'],
   ['C. Stock media library', 'Photos, footage, sound effects, music, templates', 'Watermarked previews, collections, download quotas'],
   ['D. Private media portals', 'Agencies, production houses, internal training', 'Client collections, permissions, your branding']]),
 'The setup wizard asks which model you run and only shows the settings it needs. You can run several.']),
('Media providers', [
 ('ol', ['Bunny Stream: library ID, API key and token key; turn on token authentication in Bunny.', 'Cloudflare Stream: account ID, API token and signing key; require signed URLs on each video.', 'S3 + CloudFront: bucket private, a CloudFront key pair; the plugin signs short-lived links.']),
 'Keys stay on your server and are never sent to the browser.']),
('Private podcast feeds', [
 'Every subscriber gets their own feed address in their media account, which works in any podcast app. If a feed is shared, it is flagged (many devices or places); rotate it with one click and the old address stops working. Storage addresses are never in the feed.']),
('How each play is protected', [
 ('ol', ['Who is asking: logged in and subscription valid.', 'What their plan allows: the item, the quota, device and stream limits.', 'A short-lived signed link or provider token is created.', 'The play or download is recorded; unusual activity raises an alert (geo alerts are warnings, they never block travellers automatically).']),
 'Signed links stop sharing of links and downloads of your storage, but they are not DRM: a determined viewer can record the screen. DRM streaming is a future integration.']),
('The member\'s media account', [
 'Sections follow the media you enabled: Continue watching, Video, Podcasts, Media library, plan and benefits, devices, active streams, downloads, private feeds, billing and security alerts. Choose grid, list or carousel, columns, thumbnail ratio, descriptions, download buttons and player colours.']),
('Media Archives or Membership Content?', [
 ('table', ['You need', 'Use'], [
   ['Tiers, courses, members-only articles, member files', 'Membership Content Pro'],
   ['Video/audio hosting and streaming, stream limits, private podcast feeds, watermarks, media quotas, API and external portals', 'Media Archives Pro'],
   ['Both', 'Both: they share the customer and subscription data and neither needs the other']])]),
]))

G.append(dict(slug='rinnovo-guide-service-contracts-pro', title='Rinnovo Service Contracts Pro guide', product='Rinnovo Service Contracts Pro',
intro='Retainers with allowances, requests, work logs and reports. Product setup: the Service retainer tab; contracts: WooCommerce › Service Contracts; clients: My Service Contracts in My Account.',
sections=[
('Set up a retainer', [
 ('ol', ['Create a subscription product and open the Service retainer tab.', 'Onboarding / setup fee (and when it is charged), Minimum term (payments) and Notice period (days).', 'Monthly allowances, one per line: name | amount | unit | rollover | max | expiry.', 'Monthly deliverables, Monthly tasks (Trello, ClickUp or webhook), SAC / HSN code and Invoice approval.', 'Publish.']),
 'Example: "Consulting | 10 | hours | cap 5 | max 15 | expiry 2" keeps up to 5 unused hours, never more than 15 in total, and rolled-over hours last 2 months. "Articles | 8 | posts | expire" resets to 8 each month.']),
('From request to completed', [
 ('table', ['Status', 'Allowance'], [
   ['Submitted', 'Nothing used: unapproved work never uses the allowance'],
   ['Approved (with an estimate)', 'The estimate is reserved, so two requests can\'t spend the same hours'],
   ['In progress / review / revision', 'Still reserved; revisions are counted'],
   ['Completed (with the actual amount)', 'Reservation released, actual amount used'],
   ['Rejected', 'Nothing used']]),
 'Work you log yourself (date, description, quantity, request) also uses the allowance. Assign staff and keep internal notes and attachments on each contract.']),
('Ending a contract', [
 ('table', ['State', 'Meaning'], [
   ['Cancellation requested', 'The client asked; you review it (or it is scheduled automatically)'],
   ['Cancellation scheduled', 'The end date respects the notice period and the minimum term'],
   ['Terminated', 'The contract has ended']])]),
('Onboarding fee and first month', [
 'The onboarding fee is charged at once, on the first invoice or invoiced separately, and is never prorated. With a billing day (say the 1st), a client who starts on the 21st of a 30-day month pays 10/30 of the monthly fee for the first month, then the full fee on the 1st. Time zone, month length and tax are taken into account.']),
('Invoice approval', [
 ('table', ['Workflow', 'How it works'], [
   ['Approve before issue', 'The renewal waits until the client approves; then the invoice is issued and charged'],
   ['Approve for payment', 'The invoice is issued; the client approves it for payment']]),
 'Each approval is recorded; an approved invoice never changes. Tax invoices per country are covered in the shared guide.']),
('Monthly service report', [
 'At the end of each period the client receives a report (also in their account): for example 8/10 hours used, 6/8 articles delivered, 12 requests completed, 2 pending, and the list of work done.']),
('Connections', [
 'Add Trello or ClickUp tokens, CRM and webhook details in Settings. They are stored encrypted. Webhooks are signed and retried, and a retried renewal never creates the monthly tasks twice.']),
]))

G.append(dict(slug='rinnovo-guide-session-pro', title='Rinnovo Session Membership Pro guide', product='Rinnovo Session Membership Pro',
intro='Class memberships, packs and passes. Classes: the Classes menu; members, reports and settings: WooCommerce › Sessions; members book with [session_schedule] (or the Class schedule widget).',
sections=[
('Create each pricing model', [
 ('table', ['Model', 'Product settings'], [
   ['Monthly membership (12 classes)', 'Session membership, Sessions per billing period 12, rollover rule'],
   ['Unlimited pass', 'Session membership, Unlimited'],
   ['10-class pack valid 90 days', 'Simple product, Class pack credits 10, Pack valid for (days) 90'],
   ['Family pass (30 shared)', 'Session membership 30, Family members, shared pool'],
   ['Private sessions (5 PT)', 'Class pack credits 5, Pack is for class type "private"'],
   ['Trial (3 intro classes)', 'Class pack credits 3, Trial (once per customer)'],
   ['Hybrid (8 group + 2 private)', 'Credits per class type: group 8, private 2'],
   ['Top-up (5 extra)', 'Extra sessions (top-up) 5']]),
 'Packs have their own expiry, independent of subscription billing.']),
('Families', [
 'Choose One shared pool for the family, A separate allowance per person, or both. Each person has their own bookings, attendance and waiver; you choose whether the account holder books for others and whether each person signs their own waiver.']),
('Schedules', [
 'Each class has weekly times, extra dates, a time zone, and blackout dates (holidays). Every date becomes its own session you can cancel or give a substitute instructor (booked members get their credits back and are told). Changing the times after people booked cancels the dates that disappear, returns the credits and tells them.']),
('Credits', [
 ('table', ['What happens', 'Credit'], [
   ['Book', 'Reserved'],
   ['Attend', 'Used'],
   ['Cancel before the cut-off', 'Given back'],
   ['Late cancel / no-show', 'Used (if you choose so)'],
   ['Studio cancels the date', 'Given back']]),
 'Every movement is a line in the ledger with a reason; two bookings can never take the same last credit.']),
('Recurring bookings, check-in and reports', [
 'Members book a series (every Monday and Wednesday 6 PM for 4 weeks); it stops when credits run out, the membership ends or they stop it. Each booking has a QR code; staff scan it or type the short code on the session screen. Reports show utilisation, attendance, no-shows and late cancels per class, and the instructor roster with days instructors marked as away.']),
('Booking tools', [
 'WooCommerce Bookings works on its own hooks. For Amelia or Bookly, set the Adapter secret in settings and point their webhook to /wp-json/rinnovose/v1/booking with the header X-Rinnovose-Secret. Bookings take a credit, cancellations give it back, and duplicate deliveries are ignored.']),
('Session Pro or Membership Pro?', [
 'Membership Content decides "is this person a Gold member"; Session Pro decides "can they book this class and do they have credits". A Gold member may have no class credits, and someone can buy a 10-class pack without a membership.']),
]))

G.append(dict(slug='rinnovo-guide-bundles-pro', title='Rinnovo Bundles & Variable Subscriptions Pro guide', product='Rinnovo Bundles & Variable Subscriptions Pro',
intro='Subscription bundles and variable plans. Products: the Bundle tab; operations: WooCommerce › Bundles & Plans.',
sections=[
('Kinds of bundles', [
 ('table', ['Kind', 'Example'], [
   ['Fixed', 'Starter kit: 2 × shampoo, 1 × conditioner'],
   ['Mix and match', 'Choose 6 of 15 snacks (Minimum items / Maximum items)'],
   ['Mixed', 'A fixed mug plus 3 coffees of your choice, plus an optional grinder']]),
 'Flat price: one price for the bundle, split across components for tax and refunds as you choose (for example Equally between components). Dynamic price: the components\' prices × quantities minus the Bundle discount (%).']),
('Component rules', [
 'Per component: minimum and maximum, Critical or Optional, an Extra price (premium picks), and exclusions for combinations that don\'t go together.']),
('Out of stock at renewal', [
 'When a critical component is out of stock, the renewal is held and not charged. Open Held bundle renewal and choose: replace the item, skip it, partial ship, delay (it retries), or refund the difference, then release the renewal. Optional components out of stock are skipped and the price adjusted.']),
('What customers can do', [
 'In My Account: change the next box only (Next shipment only), copy the previous box, save a favourite box, skip or replace one component for the next shipment.']),
('Variable plans and switching', [
 'The plan table on the product sets each variation\'s price, period, sign-up fee and trial; shoppers see a Compare plans table (also the Plan table widget / [bundle_plans]).',
 'Example: Monthly $20 → Yearly $200 halfway through the month: the unused $10 is credited, the customer pays $190 now and the next renewal is in a year. Card subscriptions pay the difference at once; others get a credit on the next renewal.']),
('One renewal statement', [
 'Under Settings you can send one statement for several independent subscriptions renewing the same day. Each subscription keeps its own record, payment and cancellation.']),
('Which plugin does what', [
 ('table', ['Plugin', 'Does'], [
   ['Rinnovo Box Builder', 'The builder customers use and the saved selection (no billing, stock or shipping)'],
   ['Bundles Pro', 'Bundle prices, component stock, renewals, plans and switching'],
   ['Boxes Pro', 'Delivery schedules, shipping, prepaid, gifts, swaps and packing']]),
 'Bundles Pro never needs Boxes Pro (digital bundles, simple combinations), and Boxes Pro handles its own fixed boxes without Bundles Pro.']),
]))

G.append(dict(slug='sarala-guide', title='Sarala Login Page guide', product='Sarala Login Page and Sarala Login Page Pro', family='Sarala',
intro='One-click social login and a safer login page for WordPress and WooCommerce. Everything is set up in Sarala Login Page › Settings. The free plugin is on WordPress.org; Pro adds providers, button styles, CAPTCHA, redirects, the login widget and super admin protection.',
sections=[
('Install and first steps', [
 ('ol', ['Install Sarala Login Page from Plugins › Add New (search "Sarala Login Page") and activate it.', 'For Pro: upload sarala-login-page-pro.zip in Plugins › Add New › Upload, activate it and enter your license key on its license screen.', 'Open Sarala Login Page › Settings and turn on the providers you want.']),
 'New social sign-ups become Customers when WooCommerce is active, otherwise they get the default role from Settings › General.']),
('Connect a login provider', [
 'Each provider needs an app in its developer console. In Sarala Login Page › Settings, open the provider: it shows the exact Redirect URL to paste into that app.',
 ('table', ['Provider', 'Where to create the app', 'You paste into Sarala'], [
   ['Google', 'Google Cloud Console › APIs & Services › Credentials › OAuth client ID (Web)', 'Client ID, Client secret'],
   ['Facebook', 'Meta for Developers › My Apps › Facebook Login', 'App ID, App secret'],
   ['Apple', 'Apple Developer › Identifiers (Services ID) and Keys (Sign in with Apple)', 'Services ID, Team ID, Key ID, private key'],
   ['Microsoft', 'Azure portal › App registrations', 'Application (client) ID, Client secret'],
   ['LinkedIn, X, GitHub, Discord, Yahoo, Reddit', 'The provider\'s developer portal', 'Client ID, Client secret']]),
 'Reddit shares no email address, so people first connect Reddit to their account from their profile and then log in with it.']),
('Where the buttons appear', [
 ('ul', ['WordPress login and registration pages.', 'WooCommerce My Account login and registration forms.', 'Top of the WooCommerce checkout: guests sign up or log in and return to checkout with their cart.', 'Anywhere with the [sarala_login_page] shortcode (any theme or page builder).', 'Pro: the login widget (Appearance › Widgets or [sarala_login_widget]) with AJAX login.']),
 'Existing users with the same verified email are logged straight into their account, which is connected automatically. Users connect and disconnect accounts in their profile or My Account › Account details.']),
('Protect the login page', [
 ('table', ['Setting', 'What it does'], [
   ['Limit login attempts', 'After 5 failed logins in 15 minutes (your numbers) the IP is locked out for 30 minutes; add your own IPs to "never lock out".'],
   ['Custom login URL', 'Hides wp-login.php and wp-admin from bots.'],
   ['Security question', 'A math question on login and registration forms, checked on the server.'],
   ['Force password reset', 'Passwords expire after the days you choose, for all or chosen roles.'],
   ['Failed logins', 'Every failed attempt with IP, device and location; unlock addresses here.']])]),
('Pro: button styles and providers', [
 'Pick icon tiles, outline boxes or brand pills, the corner shape, and each provider\'s colours or your brand colours, with a live preview. Pro adds Slack, TikTok, Twitch, VK, Patreon, Amazon and Disqus.']),
('Pro: CAPTCHA with a backup', [
 'Choose Cloudflare Turnstile, reCAPTCHA v2 or hCaptcha. If the CAPTCHA can\'t load, the security question appears instead, so nobody is locked out. Secrets are stored encrypted.']),
('Pro: redirects and the login widget', [
 'Set where each role goes after logging in and out (admins to the dashboard, customers to My Account), with {user_login} and {user_id} placeholders, and block addresses nobody should ever be sent to. The login widget logs in without a page reload and shows your own HTML for logged-in users.']),
('Pro: super admin', [
 'The admin who activates Pro becomes the super admin. Only they can add administrators, and other administrators can\'t edit or demote them or deactivate Sarala. Optionally only the super admin installs plugins and themes. Recovery: define( \'SLPP_OWNER\', 1 ); in wp-config.php.']),
('Recommended plugins', [
 'Sarala Login Page › Settings shows a "Works great with" box: Rinnovo Subscriptions & Licenses (sell subscriptions and licenses with WooCommerce) and Performance Insight for WooCommerce (cookieless analytics and SEO). Click Install to add them from WordPress.org, or Not now to hide the suggestion.']),
]))
