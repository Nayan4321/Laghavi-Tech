# Getting started + settings reference for every guide. Labels and menu paths are exactly what the
# plugins show (taken from the admin screens). Merged into each guide by elementor.py:
# START sections go before the guide's own sections, REF sections after.

T = lambda rows, head=('Setting', 'What it does'): ('table', list(head), rows)

INSTALL_PRO = lambda name, lic: ('Install and activate', [
    'Each Rinnovo Pro needs WooCommerce and the free Rinnovo Subscriptions & Licenses plugin. Your download from My Account is a package with two zips: the Pro plugin and the Rinnovo Box Builder (shared by all Rinnovo Pros).',
    ('ol', [
        'In WordPress go to Plugins › Add New, search "Rinnovo Subscriptions & Licenses", click Install now, then Activate.',
        'On your computer, unzip the package you downloaded. You get two zips.',
        f'Plugins › Add New › Upload Plugin: upload the {name} zip and activate it. Do the same with rinnovo-box-builder.zip.',
        f'Go to WooCommerce › {lic}. Paste your license key from My Account › Licenses on laghavi.site and click Activate. Status turns Active and "Updates until" shows the end of your license year.',
        'Updates now arrive in Dashboard › Updates like any other plugin.',
    ]),
    'Already using another Rinnovo Pro? Skip step 1, and upload the Box Builder zip only if it is not installed yet.',
])

START, REF = {}, {}

# ---------------------------------------------------------------- Every Rinnovo Pro (shared)
START['rinnovo-guide-shared'] = [
 ('Before you start', [
   ('ul', ['WordPress 6.0 or later, PHP 7.4 or later, WooCommerce 8.0 or later.',
           'At least one payment method set up in WooCommerce › Settings › Payments (Stripe, a card plugin, or bank transfer).',
           'Your license key: My Account › Licenses on laghavi.site.']),
 ]),
 INSTALL_PRO('Pro', '(your Pro) license, for example WooCommerce › Boxes Pro license'),
 ('Where everything is', [
   T([
     ['WooCommerce › Settings › Rinnovo', 'Store-wide subscription settings: checkout, renewals, reminders, cancelling, reviews. Pros add their own sections on this page.'],
     ['WooCommerce › Subscriptions', 'Every subscription: status, next payment, cancel, resume, send a renewal invoice now.'],
     ['WooCommerce › Invoices', 'Invoice settings shared by all Rinnovo Pros: company, numbering, credit notes, tax layout, export.'],
     ['WooCommerce › (Pro) license', 'Your license key, status and update date for each Pro.'],
     ['Products › Edit product › Product data', 'Per product: the Subscription fields in the General tab, and each Pro\'s own tab.'],
     ['My Account (your customers)', 'Subscriptions, invoices, licenses and each Pro\'s own pages.'],
   ], ('Menu', 'What you do there')),
 ]),
 ('First setup in 10 minutes', [
   ('ol', [
     'WooCommerce › Settings › Rinnovo: check "Reminder before renewal (days)" (3 is a good start) and the two "Unpaid invoice" days, then Save.',
     'WooCommerce › Invoices: fill Legal company name, Legal address and Tax registration number, pick a Template, tick "Create an invoice for every paid order" and "Attach the invoice PDF…", then Save. Click Preview to see a sample.',
     'Products › Add New: give it a price, tick Subscription in Product data › General, set Bill every 1 and Billing period Month, then Publish.',
     'Open the product on your site and buy it with a test payment. Check WooCommerce › Subscriptions and the invoice in My Account › Invoices.',
     'Now open your Pro\'s own guide for its settings.',
   ]),
 ]),
]
REF['rinnovo-guide-shared'] = [
 ('Settings: WooCommerce › Settings › Rinnovo', [
   'These apply to every subscription in the store, whichever Pro you use.',
   T([
     ['Skip the cart page', 'Shoppers go straight to checkout after "Buy now" / "Subscribe". Links to the cart open the checkout instead.'],
     ['Editable order on checkout', 'Shoppers can change quantities and remove items on the checkout page itself.'],
     ['Button text for products / for subscriptions', 'The add-to-cart button text, separately for normal and subscription products.'],
     ['Automatic renewals with other payment plugins', 'Saves the card or bank mandate at the first payment (WooCommerce Stripe Gateway, WooPayments, PayPal Payments, Square, Braintree, Mollie, Checkout.com, GoCardless, Paystack…) and charges it on every renewal. A failed charge is retried after 1 and 3 days and the customer gets a "Pay now" link. The table below it shows which of your payment plugins renew automatically.'],
     ['Reminder before renewal (days)', 'Emails customers this many days before each renewal or trial end. 0 = no reminder.'],
     ['Unpaid invoice: put on hold after (days)', 'Counted from the renewal date. After it the subscription is "Payment due" (on hold) and Pro benefits pause.'],
     ['Unpaid invoice: cancel after (days)', 'Counted from the renewal date. 0 = never cancel automatically.'],
     ['Customers can cancel', 'Shows a Cancel button in My Account. The subscription ends at the end of the paid period.'],
     ['Modern My Account design', 'An overview dashboard with subscriptions, recent orders and quick links.'],
     ['Only buyers can review', 'Only logged-in customers who bought a product can review it.'],
     ['Show when they bought it', 'Shows "Purchased in March 2026" under each reviewer\'s name.'],
   ]),
 ]),
 ('Settings: the product\'s Subscription fields', [
   'Products › Edit product › Product data › General (or each variation of a variable product).',
   T([
     ['Subscription', 'Charges the price again every billing period.'],
     ['Bill every / Billing period', 'For example 1 + Month = monthly, 3 + Month = quarterly, 1 + Year = yearly.'],
     ['Sign-up fee', 'Charged once with the first payment.'],
     ['Free trial (days)', 'Days before the first charge. Trial rules are in Rinnovo Subscriptions & Licenses Pro.'],
     ['Number of payments', 'Ends the subscription after this many payments (for example 12). 0 = until cancelled.'],
   ]),
 ]),
 ('Settings: WooCommerce › Invoices', [
   T([
     ['Invoices', 'Create an invoice for every paid order; also for free orders; add a link to the invoice in order emails; attach the PDF to order emails.'],
     ['Template', 'Classic (black and white), Bold (accent lines and "Paid" stamp) or Modern (coloured table). Pick a colour or leave it empty. Preview shows a sample PDF before you save.'],
     ['Logo', 'Empty = the site logo from the Customizer.'],
     ['Legal company name / Legal address', 'Printed on every invoice. Empty address = the store address from WooCommerce › Settings.'],
     ['Tax registration number', 'With a number the title reads "Tax Invoice". Use your country\'s label: TRN, VAT No., GSTIN…'],
     ['Email / Phone / Website / Note at the bottom', 'Contact details and a footer note (payment terms, thank-you text).'],
     ['Invoice number', 'Prefix and next number. Numbers never have gaps; the next number can be raised (to continue from an old system) but not lowered.'],
     ['Financial year', 'Restart at 1 every financial year. Put {fy} in the prefix (INV/{fy}/ → INV/26-27/00001) or {year}. India: April; UAE, EU, UK: January.'],
     ['Credit notes', 'A credit note for every refund, full or partial, linked to its invoice, with its own numbers. To correct an invoice: "Cancel invoice" on the order, then "Issue a corrected invoice".'],
     ['Layout', 'Standard (VAT / sales tax as WooCommerce calculates it) or India GST (CGST + SGST in your state, IGST to others, export; HSN/SAC codes, place of supply, amount in words).'],
     ['India GST: your state', 'Your state code for place of supply. Each product can have its own HSN / SAC code (Product data › General).'],
     ['Your customers\' details', 'Optional (customers may add a legal company name, address and TRN for a tax invoice; recommended), Required (every customer adds a legal name and address; TRN optional), or Off (invoices use the billing name and address). Customers add them at checkout and in My Account › Account details.'],
     ['Export for accounting', 'Pick From and To dates and click Download CSV: every invoice and credit note in the period with customer, tax number, net, tax and total (credit notes negative).'],
   ]),
 ]),
]

# ---------------------------------------------------------------- Subscriptions & Licenses Pro
START['rinnovo-guide-subscriptions-licenses-pro'] = [
 INSTALL_PRO('rinnovo-subscriptions-licenses-pro', 'Rinnovo Subscriptions & Licenses Pro (the license screen)'),
 ('Where everything is', [
   T([
     ['WooCommerce › Settings › Rinnovo', 'Sections added by this Pro: Trials and first-payment offers, VAT / GST for digital goods, "Ask for price" products, Currencies.'],
     ['WooCommerce › Invoices', 'Invoices and credit notes (see the "Every Rinnovo Pro" guide).'],
     ['WooCommerce › Enquiries', 'Every "Ask for price" request, with its status.'],
     ['WooCommerce › Licenses / License guide', 'All license keys (revoke, extend, reissue) and code examples for checking keys in your software.'],
     ['Product data › General', 'Per product: trial, first-payment discount, Ask for price, HSN / SAC code, prices per currency, download counts.'],
     ['Product data › License', 'Sell a license key with the product.'],
   ], ('Menu', 'What you do there')),
 ]),
 ('First setup', [
   ('ol', [
     'WooCommerce › Settings › Rinnovo › Trials and first-payment offers: tick Offers, choose "Card for free trials" and "Trials per customer" (one per customer is safest). Save.',
     'If you sell to businesses: VAT / GST section: tick Business tax numbers, choose Reverse charge, tick Check EU numbers with VIES and Location evidence. Save.',
     'Edit a subscription product: set Free trial (days) to 14 in General, and optionally a First payment discount. Update.',
     'Selling software: Product data › License: tick Sell a license, choose What you sell, set Product ID and Activations per license, upload the file. Update.',
     'Sell in more currencies: Currencies section: add your Extra currencies with a rate (or tick Update rates daily). Save.',
   ]),
 ]),
]
REF['rinnovo-guide-subscriptions-licenses-pro'] = [
 ('Settings: Trials and first-payment offers', [
   'WooCommerce › Settings › Rinnovo.',
   T([
     ['Offers', 'Turns trial and first-payment offers on.'],
     ['Card for free trials', 'Ask for a card when a trial starts (charged when it ends). Without a card, the customer gets a "Pay now" invoice when the trial ends. Each product can override this.'],
     ['Offer under the price', 'Shows "First payment: $5.00" and "No card needed" under the product price.'],
     ['Trials per customer', 'One free trial per customer for any product, one per product, or no limit. Checked by account and email address, so a new account with the same email gets no second trial.'],
     ['First payment after a trial fails', 'Treat it like any renewal (retries, grace, on hold, cancelled), or cancel after the days below because the trial was never paid.'],
     ['Days to pay after a trial', 'With "Cancel": days the customer has to pay before the subscription is cancelled.'],
   ]),
 ]),
 ('Settings: VAT / GST for digital goods', [
   T([
     ['Business tax numbers', 'Adds a VAT / GST number field for business customers at checkout and applies the rules below.'],
     ['Reverse charge', 'EU businesses in another EU country (valid number), any business with a valid number in another country, or Off (always charge tax, only print the number). Reverse-charged orders, renewals and invoices say "Reverse charge".'],
     ['Check EU numbers with VIES', 'Checks EU numbers with the European Commission. UK, GSTIN, ABN, TRN and others are checked for the right format.'],
     ['When VIES is down', 'Accept numbers in the right format (the order is flagged for you) or charge VAT.'],
     ['Location evidence', 'Keeps the billing country and IP country on each order and flags orders where they differ (EU digital goods rules).'],
     ['Invoice note', 'Text printed on reverse-charge invoices.'],
   ]),
 ]),
 ('Settings: "Ask for price" products', [
   T([
     ['Buttons to show', 'Contact page and WhatsApp, contact page only, or WhatsApp only.'],
     ['Contact page / Or contact URL', 'Where the contact button goes: one of your pages, or any address (Calendly, Google Calendar…).'],
     ['WhatsApp number', 'With country code, digits only, for example 971501234567.'],
     ['WhatsApp message', 'Pre-filled message. {product} and {url} are replaced with the product\'s name and link.'],
     ['Contact button text / WhatsApp button text', 'The two button labels.'],
     ['Text shown instead of the price', 'For example "Price on request".'],
   ]),
   'Turn it on per product: Product data › General › Ask for price. Every click is saved in WooCommerce › Enquiries.',
 ]),
 ('Settings: Currencies', [
   T([
     ['Extra currencies', 'One row per currency: the currency, its rate against your store currency, and the payment methods allowed for it. Leave the last row empty to add nothing.'],
     ['Floating switcher', 'A small currency picker in the bottom corner of every page.'],
     ['Switcher on checkout', 'Shows "Pay in USD / AED / …" above the payment methods.'],
     ['Pick currency by country', 'On a first visit, picks the currency listed for the visitor\'s country (needs Cloudflare, or MaxMind in WooCommerce › Settings › Integration). Visitors can still switch.'],
     ['Update rates daily', 'Fetches exchange rates once a day from open.er-api.com. Off = keep your own rates.'],
   ]),
   'Fixed prices: in Product data › General each extra currency has its own Regular and Sale price. Empty = converted at the current rate.',
 ]),
 ('Settings: on each product', [
   T([
     ['Trial needs a card', 'Store setting, Yes (card at sign-up, charged when the trial ends) or No ("Pay now" invoice when the trial ends). Used only when Free trial (days) is set.'],
     ['First payment discount / Discount type', 'Taken off the first payment only, for example 50 % for "first month half price". With a trial, it is taken off the first payment after the trial.'],
     ['Ask for price', 'Hides the price and buy button and shows the Ask for price buttons.'],
     ['HSN / SAC code', 'Printed on GST invoices. Empty = the default in WooCommerce › Invoices.'],
     ['Regular / Sale price (EUR…)', 'Your own price per extra currency.'],
     ['Show downloads / Add to downloads', 'Shows the download count on the product page; add downloads from elsewhere (WordPress.org).'],
   ]),
 ]),
 ('Settings: Product data › License', [
   T([
     ['Sell a license', 'Creates a license key for each one bought.'],
     ['What you sell', 'WordPress plugin or theme, desktop or mobile app, browser extension, SaaS, developer product, game, digital asset or other.'],
     ['Product ID', 'A short ID your product sends with the key, so a key only works for this product. For a WordPress plugin or theme, its folder name.'],
     ['Activations per license', 'How many sites, computers or devices can use one key. 0 = unlimited. Variations can set their own (1 / 5 / unlimited).'],
     ['License valid for (days)', '0 = lifetime. With a subscription the license follows the subscription.'],
     ['Latest version / Requires WordPress / Tested up to / Requires PHP / Changelog', 'Shown in update notices.'],
     ['File to download / Or a download link', 'Stored privately; only customers with an active key can download it.'],
   ]),
 ]),
]

# ---------------------------------------------------------------- Boxes Pro
START['rinnovo-guide-boxes-pro'] = [
 INSTALL_PRO('rinnovo-physical-subscription-boxes-pro', 'Boxes Pro license'),
 ('Where everything is', [
   T([
     ['WooCommerce › Settings › Rinnovo', 'The three "Subscription boxes" sections: shipping day, what customers can change, shipping, prepaid plans and emails.'],
     ['WooCommerce › Boxes', 'To pack and ship (by ship date), Coming renewals (next 45 days, for buying stock), Box subscriptions.'],
     ['WooCommerce › Boxes › Calendar', 'A month view of billing days, cut-offs, selection deadlines, shipping days and prepaid deliveries.'],
     ['WooCommerce › Boxes › Prepaid & gifts', 'Prepaid and gift plans: boxes sent, left and the next date.'],
     ['Product data › Subscription box', 'Per product: frequencies, swaps, out-of-stock rule, subscribe & save, prepaid and gifts.'],
     ['Product data › Box builder', 'What goes in the box and how customers choose (from the Rinnovo Box Builder).'],
   ], ('Menu', 'What you do there')),
 ]),
 ('First setup: your first box', [
   ('ol', [
     'Products › Add New. Set the price, tick Subscription in General (Billing period Month).',
     'Product data › Subscription box: tick Box subscription and tick the frequencies you offer (every 2, 4, 6 weeks…).',
     'Choose Price after a swap and Item out of stock at renewal (see the reference below).',
     'Product data › Box builder: pick the kind of box (Fixed, Scheduled, Build your own, Preference-based or Hybrid) and add its products. Publish.',
     'WooCommerce › Settings › Rinnovo › Subscription boxes: set One billing day, Order cut-off and Shipping on renewals. Save.',
     'Buy the box once as a test, then open WooCommerce › Boxes to see it under "To pack and ship".',
   ]),
 ]),
]
REF['rinnovo-guide-boxes-pro'] = [
 ('Settings: Subscription boxes (store-wide)', [
   'WooCommerce › Settings › Rinnovo.',
   T([
     ['One billing day for everyone', 'Off (each box renews on its own date), monthly and yearly boxes on the same day of the month, or weekly boxes on the same weekday. One day = one packing batch.'],
     ['Day of the month / Weekday', 'The billing day used above.'],
     ['Boxes ship', 'Days after the billing day. Shown on orders and the packing list.'],
     ['Order cut-off', 'Days before the billing day. Sign-ups after it get their first box next cycle; box changes after it apply to the box after.'],
     ['Skip a box', 'Customers can skip their next box.'],
     ['Boxes skipped in a row', 'Most skips in a row (0 = no limit).'],
     ['Change frequency', 'Customers can switch between the frequencies a product offers.'],
     ['Change quantities', 'Customers can change how many of each item are in their box.'],
     ['Swap products', 'Customers can swap items for the choices a product allows.'],
     ['Shipping on renewals', 'Recalculate with your shipping zones (flat, table or live carrier rates), same as the first order, or free.'],
     ['Shipping for prepaid plans', 'Charge shipping for every box up front, or once.'],
     ['"Your next box" email', 'Days before each renewal (0 = off). Lists the box with links to skip or swap.'],
     ['Card expiry warning', 'Days before a subscriber\'s card expires (Stripe; 0 = off).'],
     ['"Subscribe & save" label / "One-time purchase" label', 'The two option labels on subscribe & save products.'],
   ]),
 ]),
 ('Settings: Product data › Subscription box', [
   T([
     ['Frequencies / Add a custom frequency', 'Tick the frequencies customers can choose. Add your own (for example every 3 weeks); it appears when you save.'],
     ['Box subscription', 'Subscribers can skip, swap, change quantity and frequency, and use one address for all boxes.'],
     ['First box discount / Discount type', 'Taken off the first box only (not with a free trial).'],
     ['Name of the sign-up fee', 'For example "Packaging fee". The amount is the Sign-up fee in General.'],
     ['Customers can swap for', 'Products or variations a subscriber can put in the next box instead of this one.'],
     ['Swap variations', 'On variable products, subscribers can switch flavour or size.'],
     ['Price after a swap', 'New item\'s price, pay more for pricier items only, or same price as before. Applies from the next box.'],
     ['Item out of stock at renewal', 'Replace it with the first swap option in stock, leave it out and charge less, hold the renewal until you decide, or do nothing (backorder). Held renewals wait in WooCommerce › Orders.'],
     ['Subscribe & save / Subscription discount (%) / Selected first', 'Offers a subscription on a normal product with a discount, and which option is pre-selected.'],
     ['Prepaid boxes / Prepaid discount (%)', 'Numbers of boxes to offer, for example 3, 6, 12, with an optional discount.'],
     ['Gift plans', 'Buyers can send prepaid boxes as a gift: recipient, start date and message.'],
   ]),
 ]),
 ('Settings: Product data › Box builder', [
   T([
     ['What kind of box is this?', 'Fixed (same items every time), Scheduled (your menu per day or week, rotating weeks), Build your own (customers pick), Preference-based (customers answer questions, you pack) or Hybrid (all of these).'],
     ['Box price', 'The product\'s price, the price plus premium extra charges, or the sum of the item prices.'],
     ['Always in the box', 'Items in every box.'],
     ['Your menu (schedule)', 'Products per delivery day. Add weeks (A, B, C, D…) to rotate; set the delivery days and the rotation start date. Optionally email subscribers when the menu changes.'],
     ['What customers choose from', 'Groups (Main dish, Side, Drink) of products and whole categories, each Required, with Min / Max or Exactly. Optionally choose per delivery day.'],
     ['Box rules', 'Items per box min / max, most of one product, same product more than once, out-of-stock items shown or hidden, replace items that run out.'],
     ['Default box (auto-fill)', 'Packed when a customer chooses nothing.'],
     ['Prices and limits per product', 'A box-only price, an extra charge for premium picks, and the most a customer can take.'],
     ['Combinations that are not allowed', 'Products that never go together.'],
     ['Preference questions', 'Questions with choices (with images); "Show only when" shows a group or question after an answer.'],
     ['Selection deadline', 'When the menu is published and the deadline (hours before delivery); customers change their next box until then. Optional reminder email.'],
     ['Layout', 'Grid of cards, list or step-by-step wizard; drag sections to reorder. Switching layout never loses your contents.'],
   ]),
 ]),
]

# ---------------------------------------------------------------- Membership Content Pro
START['rinnovo-guide-membership-pro'] = [
 INSTALL_PRO('rinnovo-membership-content-pro', 'Membership Pro license'),
 ('Where everything is', [
   T([
     ['WooCommerce › Membership › Tiers and perks', 'Your tiers: group, the products that give each tier, perks.'],
     ['… › Content rules', 'Restrict whole content types (all posts, all products…) to a tier.'],
     ['… › Teasers and paywall', 'What non-members see instead of members-only content.'],
     ['… › Files and video', 'How protected files are sent, download limits, private video keys.'],
     ['… › Access and sharing', 'Failed payments, lifetime purchases, plan changes, devices, member directory.'],
     ['… › Members', 'Give access by hand, see and take back access.'],
     ['… › Lifecycle and drip', 'What happens on each event; drip overview.'],
     ['… › Courses', 'Course & product mapping and the courses-and-tiers matrix.'],
     ['… › Download plans', 'Credits per tier for the resource library.'],
     ['… › Dashboard and API', 'Member announcement, webhooks.'],
     ['Every post, page, product, category', 'The "Members only" box: who can see it and when it unlocks.'],
     ['Resources', 'Downloadable files for the resource library (credit cost per file).'],
     ['Posts › Categories › edit a category', 'Connect the category to products: buying one opens every post in it.'],
   ], ('Menu', 'What you do there')),
 ]),
 ('First setup: your first membership', [
   ('ol', [
     'Create the products: for example "Gold Monthly" and "Gold Yearly" as subscriptions (Product data › General › Subscription).',
     'WooCommerce › Membership › Tiers and perks: add a tier (for example Gold), set its Group, choose the products under "Products that give this tier". Save.',
     'Open a post or page, find the Members only box: Who can see this = Gold members and higher. Optionally "Unlock after (days of membership)". Update.',
     'WooCommerce › Membership › Teasers and paywall: choose the Teaser and the button text. Save.',
     'Log out and open the post: you see the teaser and the paywall. Buy the product as a test customer: the post opens.',
   ]),
 ]),
]
REF['rinnovo-guide-membership-pro'] = [
 ('Settings: Tiers and perks', [
   'One block per tier.',
   T([
     ['Group (programme)', 'Independent programmes, for example "Business training" with Silver, Gold, Platinum. A tier includes only the lower tiers of its own group; members can hold tiers of several groups.'],
     ['Invite only', 'Its products can only be bought with an invite link: product address + ?invite=CODE.'],
     ['Products that give this tier', 'Subscription products or variations (Monthly, Yearly). A one-time purchase gives lifetime access when that is on in Access and sharing.'],
     ['Store discount', 'Percentage off the shop for members of this tier.'],
     ['Free shipping', 'Members ship for free.'],
     ['Free bonus products', 'One of each free per order, for example bonus downloads.'],
     ['User role', 'Gives members a "Member: (tier)" role for other plugins; removed when access ends.'],
     ['"Subscribe to unlock" link', 'Where the paywall button for this tier leads.'],
   ]),
 ]),
 ('Settings: Content rules', [
   T([
     ['Posts / Pages / Products / Guides / Resources / Classes…', 'Restrict every item of a content type to a tier. Single items can still change it in their Members only box.'],
     ['Child pages', 'Child pages follow their parent page\'s rule. bbPress topics follow their forum; LearnDash and LifterLMS lessons follow their course.'],
   ]),
 ]),
 ('Settings: Teasers and paywall', [
   T([
     ['Teaser', 'What non-members see: the first words, the excerpt, the first words then a blurred preview, or only the paywall. A "More" block sets the teaser exactly. The rest never reaches the browser.'],
     ['Title / Text / Button', 'The paywall box. {tier} is replaced with the tier needed.'],
     ['Join page', 'Where the button leads, for example a pricing page comparing your tiers. Default: the tier\'s product page.'],
     ['Log-in link', 'Shows "Already a member? Log in".'],
     ['My Account page name', 'Name of the members\' page in My Account (default My Content).'],
   ]),
 ]),
 ('Settings: Files and video', [
   T([
     ['Sending files', 'By WordPress (works everywhere), X-Sendfile (Apache, LiteSpeed) or X-Accel-Redirect (Nginx). The server options are faster for big files.'],
     ['Download links', 'Expire after this many minutes, so copied links stop working.'],
     ['Download limit', 'Downloads of the same file per member per day (0 = no limit).'],
     ['Bunny Stream library ID / token authentication key', 'For private Bunny videos: turn on "Embed view token authentication" in Bunny; every embed link is signed and expires.'],
   ]),
 ]),
 ('Settings: Access and sharing', [
   T([
     ['Failed payments', 'Days members keep access after a renewal payment fails (grace period).'],
     ['One-time purchases', 'Buying a tier product without a subscription gives lifetime access (removed if refunded).'],
     ['Plan changes', 'Credit unused time when upgrading or switching monthly / yearly; buying another plan replaces the current one.'],
     ['Logged-in devices', 'At most this many devices logged in at once per member (0 = no limit).'],
     ['Videos at the same time', 'At most this many videos playing at once per account (0 = no limit).'],
     ['Member directory', 'Who is listed by [member_directory]: members who choose to be, everyone unless hidden, or off.'],
   ]),
 ]),
 ('Settings: Members, Courses, Download plans, Dashboard', [
   T([
     ['Members › Give access by hand', 'Pick a user, a tier and an optional end date (press, partners, comps). "Take back" removes it.'],
     ['Courses › Course & product mapping', 'Per row: Product, Course(s), Access (days, empty = lifetime) and when access starts. Save to get another row.'],
     ['Download plans › Credits work as', 'One shared wallet, or quotas per category.'],
     ['Download plans › Downloading again', 'Days a member can download the same file again without paying credits.'],
     ['Download plans › Failed downloads', 'Give the credits back when a download fails.'],
     ['Download plans › per tier: Credits / Unused credits / Limits', 'Credits each period (0 = unlimited, fair use 50 a day), carry-over up to a cap, and daily limits.'],
     ['Dashboard and API › Announcement', 'Shown at the top of every member\'s My Membership dashboard.'],
     ['Dashboard and API › Webhook URL / secret', 'Sends member.access_changed and member.entitlement_added as signed JSON.'],
   ]),
 ]),
 ('Settings: on posts, products and resources', [
   T([
     ['Members only › Who can see this', 'Not restricted (or from category / parent), Everyone, Free (any registered user), or a tier and higher.'],
     ['Members only › Unlock after (days of membership) / Or unlock on', 'Drip: opens after a number of days of membership, or on a date.'],
     ['Product › Team seats', 'People the buyer can invite to share the membership (0 = personal).'],
     ['Product › Download credits', 'A credit pack: buying it adds these credits (never reset).'],
     ['Resource › Download file and credits', 'The file (moved to the protected folder) and its credit cost. The featured image is the public preview.'],
   ]),
   'Parts of a page: the "Members only" block, [members_only tier="gold"]…[/members_only], or in Elementor: Advanced › Members only (Rinnovo).',
 ]),
]

# ---------------------------------------------------------------- Media Archives Pro
START['rinnovo-guide-media-archives-pro'] = [
 INSTALL_PRO('rinnovo-media-archives-pro', 'Media Archives Pro license'),
 ('Where everything is', [
   T([
     ['Media Archives › Overview & analytics', 'The setup wizard ("What are you building?"), plays and downloads, subscriber activity.'],
     ['Media Archives › Media Archives / Add media item', 'Your videos, audio, images and files.'],
     ['Media Archives › Collections & seasons / Libraries & clients', 'Group media; one library per brand or client.'],
     ['Podcasts', 'Episodes and shows for private podcast feeds.'],
     ['WooCommerce › Media Archives', 'Settings in five tabs: Plans & Quotas, Media Providers, Protection, App & Integrations, Security & Activity.'],
   ], ('Menu', 'What you do there')),
 ]),
 ('First setup', [
   ('ol', [
     'Media Archives › Overview & analytics: pick what you are building (A video library, B podcast network, C stock media, D private portals). Only the settings you need are shown.',
     'Create the subscription products (for example Basic Monthly, Pro Yearly).',
     'WooCommerce › Media Archives › 1 · Plans & Quotas: for each plan choose its Products and write its Features and quotas. Save.',
     '2 · Media Providers: enter the keys of the service your media is on (Bunny, Cloudflare Stream or S3 / CloudFront). Save.',
     'Media Archives › Add media item: choose Type, Hosted on, Who can play it. Publish.',
     'Create a page with [media_library] (the wizard offers to). Buy a plan as a test customer and play an item.',
   ]),
 ]),
]
REF['rinnovo-guide-media-archives-pro'] = [
 ('Settings: 1 · Plans & Quotas', [
   T([
     ['Products', 'Subscription products or variations that give this plan.'],
     ['Features and quotas', 'One per line. Numbers are quotas per billing period (for example downloads=50); yes/no are feature flags. Sent to your app in the API, tokens and webhooks.'],
     ['Limits', 'Devices signed in at once and streams at the same time for this plan.'],
     ['Failed payments', 'Days of access after a renewal payment fails.'],
     ['Plan changes', 'Credit unused time when upgrading (monthly to yearly).'],
     ['Pausing', 'Months a subscriber can pause at most (0 = no pausing).'],
   ]),
 ]),
 ('Settings: 2 · Media Providers', [
   T([
     ['Links expire after', 'Minutes a signed link works (players refresh it on page load).'],
     ['Bunny.net: Stream library ID / token key, CDN hostname / token key', 'Turn on "Embed view token authentication" in your Bunny library and token authentication on the pull zone; add your domain.'],
     ['Cloudflare Stream: customer code, signing key ID, signing key (pem)', 'Create the key with the Stream keys API and turn on "Require signed URLs" on your videos.'],
     ['Amazon CloudFront / S3: domain, key pair ID, private key, bucket, region, access keys', 'Keep the bucket private; use an IAM user that can only read it.'],
   ]),
 ]),
 ('Settings: 3 · Protection', [
   T([
     ['Watermark / Watermark text', 'A moving watermark over players, drawn into gallery images. {email}, {name}, {ip}, {date}, {id} are replaced per subscriber.'],
     ['Copy protection', 'No right-click, dragging, text selection or download buttons on players and galleries.'],
     ['Only on this site', 'Media links only work on your own pages (no hotlinking).'],
     ['Gallery images', 'Widest size shown, in pixels. The original is never sent.'],
     ['Shared podcast feeds', 'Flag a private feed used from more than this many IP addresses a day (0 = off).'],
     ['Player colour / Custom CSS', 'Colour of players and the library; extra CSS for the portal.'],
   ]),
 ]),
 ('Settings: 4 · App & Integrations and 5 · Security & Activity', [
   T([
     ['App address / "Open the app" link', 'Your app, if subscribers use one.'],
     ['Show the portal inside your app', 'Origins allowed to frame My Account, or use a one-time POST /portal-link.'],
     ['API keys, Webhooks, Single sign-on', 'Server-to-server keys, signed webhooks, OAuth 2 / OpenID for "Sign in with" your store.'],
     ['Same account in two countries / Within', 'Flag it (email you and the customer), flag and sign out everywhere, or off; within this many minutes. Needs Cloudflare or MaxMind.'],
     ['Site log-ins at once', 'When a plan sets no device limit (0 = none). A new log-in signs out the oldest.'],
     ['When access ends', 'Also sign the customer out of the site.'],
     ['API rate limit', 'Requests per minute per IP address.'],
   ]),
 ]),
 ('Settings: on each media item and product', [
   T([
     ['Type', 'Video, Audio / podcast, Image / footage / design, or File.'],
     ['Hosted on', 'Bunny Stream, Cloudflare Stream, Vimeo, Bunny Storage / CDN, CloudFront, S3, or a protected file.'],
     ['Who can play it', 'Any plan, or a plan and higher.'],
     ['Free preview', 'ID of a short trailer on the same host, shown to visitors who have not subscribed.'],
     ['Download', 'Subscribers can download it (counts against a downloads quota).'],
     ['Product › Media Archives extra', 'Usage add-on: buying it adds to the quota for the current period, for example downloads=50.'],
   ]),
 ]),
]

# ---------------------------------------------------------------- Service Contracts Pro
START['rinnovo-guide-service-contracts-pro'] = [
 INSTALL_PRO('rinnovo-service-contracts-pro', 'Service Contracts Pro license'),
 ('Where everything is', [
   T([
     ['WooCommerce › Service Contracts', 'Every contract: client, package, status, next invoice, quota left, flags. Open one for requests, work log, notes and reports.'],
     ['WooCommerce › Service Contracts › Settings', 'Billing day, tax invoices, client workspace and tasks, cancellations.'],
     ['Product data › Service contract', 'Per retainer: fees, term, allowances, intake form, tasks, invoice approval.'],
     ['My Account › My Service Contracts (clients)', 'Requests, allowances left, reports, invoices.'],
   ], ('Menu', 'What you do there')),
 ]),
 ('First setup: your first retainer', [
   ('ol', [
     'Products › Add New: monthly price, tick Subscription in General.',
     'Product data › Service contract: tick Service retainer, set Onboarding / setup fee, Minimum term and Notice period.',
     'Monthly allowances, one per line, for example: Consulting | 10 | hours | cap 5',
     'Optional: Intake form questions and Monthly tasks. Publish.',
     'WooCommerce › Service Contracts › Settings: set "Bill every retainer on day" and "When a client asks to cancel". Save.',
   ]),
 ]),
]
REF['rinnovo-guide-service-contracts-pro'] = [
 ('Settings: WooCommerce › Service Contracts › Settings', [
   T([
     ['Bill every retainer on day', '1–28 of every month (0 = each contract on its own date). Monthly retainers only.'],
     ['First month', 'Charge the first month only for the days left until the billing day.'],
     ['Pause / Longest pause (months)', 'Clients can pause their retainer themselves, up to this long.'],
     ['Bank transfer', 'Clients paying by card can switch to bank transfer invoices (turn on Direct bank transfer in WooCommerce payments).'],
     ['Default SAC code', 'For contracts whose product has no SAC code.'],
     ['Bank details', 'Prints your bank account details on invoices paid by transfer.'],
     ['FluentCRM tag / Jetpack CRM', 'Adds new clients to your CRM.'],
     ['Trello API key, token, list ID / ClickUp token, list ID', 'Monthly tasks become cards or tasks in this list.'],
     ['Webhook / Webhook secret', 'Sends contract.started and contract.renewed (Zapier, Make). Signed, retried 3 times, never duplicated. Stored encrypted.'],
     ['When a client asks to cancel', 'Schedule the end automatically (after the minimum term and notice period), or wait for you to review it.'],
   ]),
 ]),
 ('Settings: Product data › Service contract', [
   T([
     ['Service retainer', 'Sells this subscription as a service contract.'],
     ['Onboarding / setup fee / Fee name / Onboarding fee is', 'A one-time fee, paid with the first payment or invoiced separately. Never prorated.'],
     ['Minimum term (payments)', 'The client can only end the contract after this many payments.'],
     ['Notice period (days)', 'A cancellation takes effect at the end of the billing period after the notice period and the minimum term.'],
     ['SAC / HSN code', 'Printed on GST invoices, for example 998361 (advertising).'],
     ['Monthly deliverables', 'One per line. Shown on the product, in the client\'s account and on invoices.'],
     ['Quota per month / Unit / Unused at month end / Rollover limit', 'A single allowance (hours, posts…) and what happens to unused units.'],
     ['Monthly allowances', 'Several allowances, one per line: name | amount | unit | rollover (expire, all, or cap N) | max N | expiry N. Replaces the single quota.'],
     ['Paid add-ons', 'One-time extras the client adds to the next invoice with one click.'],
     ['Intake form / Or a form shortcode / Brief needed', 'Questions (label|type|required; secret answers are encrypted) shown after payment, or your Gravity Forms / WPForms / CF7 form; remind the client until it is sent.'],
     ['Monthly tasks', 'Created in Trello, ClickUp or your webhook at the start and on every renewal.'],
     ['Invoice approval', 'No approval, client approves before the invoice is issued, or client approves the issued invoice for payment.'],
   ]),
 ]),
]

# ---------------------------------------------------------------- Session Membership Pro
START['rinnovo-guide-session-pro'] = [
 INSTALL_PRO('rinnovo-session-membership-pro', 'Session Membership Pro license'),
 ('Where everything is', [
   T([
     ['Classes › Add class', 'Each class: weekly times, places, credits, instructor, online link, time zone, holidays.'],
     ['Classes › Class categories / Locations', 'Filters for the schedule (yoga, HIIT; Downtown, Marina).'],
     ['WooCommerce › Sessions', 'Upcoming sessions with bookings and waitlist; open one to check in, cancel a date or set a substitute.'],
     ['WooCommerce › Sessions › Members / Reports', 'Members with sessions left and waivers; utilisation, attendance and instructor roster.'],
     ['WooCommerce › Sessions › Settings', 'Booking rules, families, privacy, reminders, Zoom.'],
     ['Product data › General', 'Session membership, packs, trials, family and hybrid credits.'],
     ['Pages', 'Put [session_schedule] on a booking page and [session_roster] on a private page for instructors.'],
   ], ('Menu', 'What you do there')),
 ]),
 ('First setup: your first class', [
   ('ol', [
     'Products › Add New: "Monthly 12 classes", tick Subscription, then in General tick Session membership and set Sessions per billing period to 12. Publish.',
     'Classes › Add class: Weekly times (one per line, for example "Mon 18:00"), Length, Places, Credits per booking 1. Publish.',
     'Create a page "Book a class" with [session_schedule]. Publish.',
     'WooCommerce › Sessions › Settings: set Booking opens and the Cancel / move cut-off. Save.',
     'Buy the membership as a test customer and book a class from the page.',
   ]),
 ]),
]
REF['rinnovo-guide-session-pro'] = [
 ('Settings: WooCommerce › Sessions › Settings', [
   T([
     ['Sessions made ahead', 'Days of sessions created from the weekly times.'],
     ['Booking opens', 'Days before a session.'],
     ['Cancel / move cut-off', 'Hours before a session. Later cancellations are "late".'],
     ['Late cancellations / No-shows', 'Use up the session credit.'],
     ['Waitlist', 'Book the first person waiting automatically when a place opens (otherwise just tell them).'],
     ['Weekly slots booked ahead', 'Days ahead that recurring bookings are made.'],
     ['Longest freeze', 'Months a member can freeze (0 = no freezing).'],
     ['Extra sessions product', 'ID of the one-time top-up product; shows a "Buy extra sessions" button.'],
     ['Waiver text', 'The liability waiver members accept.'],
     ['Studio closed on', 'Holiday dates for every class (YYYY-MM-DD, comma separated). Booked members are told and get their credits back.'],
     ['Family credits', 'One shared pool, a separate allowance per person, or both.'],
     ['Booking for others / Waivers', 'The account holder can book for family members; each family member signs their own waiver.'],
     ['Keep progress notes', 'Months to keep notes (0 = keep). Only shop managers and the member\'s instructors see them.'],
     ['Booking platform secret', 'For Amelia, Bookly or another platform posting to /wp-json/rinnovose/v1/booking. WooCommerce Bookings needs no setup.'],
     ['24 hours before / 1 hour before', 'Email and SMS reminders.'],
     ['Twilio account SID, auth token, number', 'For SMS reminders. Members can turn SMS off.'],
     ['Zoom account ID, client ID, client secret', 'A Server-to-Server OAuth app (scope meeting:write). A meeting is created for each online session.'],
   ]),
 ]),
 ('Settings: on each class', [
   T([
     ['Weekly times', 'One per line: weekday and start time, for example "Mon 18:00".'],
     ['Extra dates', 'One-off dates and times.'],
     ['From / until', 'When the class runs.'],
     ['Length / Places / Credits per booking', 'Minutes, places per session (0 = unlimited), credits one booking uses.'],
     ['Instructor / Where', 'Who teaches and the room.'],
     ['Online', 'In person, Zoom (meeting per session), Google Meet, Teams or another link. Only booked members see it.'],
     ['Plans that can book', 'Empty = every session membership.'],
     ['Class type', 'For hybrid plans and packs: which credits this class uses (group, private).'],
     ['Time zone', 'Of the venue. Daylight saving is handled.'],
     ['No class on', 'Dates without this class. Booked members are told and get their credits back.'],
     ['Booking rules', 'Members can bring a guest (uses their credit).'],
   ]),
 ]),
 ('Settings: on each product', [
   T([
     ['Session membership / Sessions per billing period / Unlimited', 'Makes the subscription a class membership with this many credits per period, or unlimited.'],
     ['Unused sessions / Rollover limit', 'Expire, roll over, or roll over up to a limit.'],
     ['Family members', 'How many family members the buyer can add and book for.'],
     ['Waiver / Intake questions', 'Members accept the waiver; answer your questions (question|text|required) before their first booking.'],
     ['Credits per class type', 'Hybrid plans, for example group=8, private=2.'],
     ['Credits per family member', 'Each person\'s own allowance (when family credits are separate or both).'],
     ['Class pack credits / Pack valid for (days) / Pack is for class type', 'A one-time pack (10 classes valid 90 days), works without a membership.'],
     ['Trial', 'Once per customer (account and email).'],
     ['Extra sessions (top-up)', 'A one-time product that adds sessions to the current period.'],
     ['Book with a credit', 'For WooCommerce Bookings, Amelia or Bookly products: members with a credit pay nothing.'],
   ]),
 ]),
]

# ---------------------------------------------------------------- Bundles Pro
START['rinnovo-guide-bundles-pro'] = [
 INSTALL_PRO('rinnovo-bundles-variable-subscriptions-pro', 'Bundles Pro license'),
 ('Where everything is', [
   T([
     ['WooCommerce › Bundles & Plans', 'Settings: one invoice and renewal day, stock, pricing, switching, packing list, replace a product.'],
     ['WooCommerce › Bundles & Plans › Bundle subscriptions', 'Every bundle subscription with its items and next renewal.'],
     ['WooCommerce › Orders', 'Held bundle renewals (out of stock) to resolve.'],
     ['Product data › Bundle', 'Per product: contents, components, price, frequencies.'],
     ['Product data › Box builder', 'The builder customers use, when Contents is "Box builder".'],
     ['Variable product › plan table', 'Price, period, sign-up fee and trial per variation.'],
   ], ('Menu', 'What you do there')),
 ]),
 ('First setup: your first bundle', [
   ('ol', [
     'Products › Add New: tick Subscription in General (monthly).',
     'Product data › Bundle: tick Bundle, choose Contents (fixed components, mix and match, or box builder).',
     'Add the components (or the items to choose from with Minimum / Maximum items), choose Price (flat or dynamic) and Bundle discount. Publish.',
     'WooCommerce › Bundles & Plans: set Check stock and turn on Hold renewals. Save.',
     'Buy it as a test customer; the bundle and its components show on the order.',
   ]),
 ]),
]
REF['rinnovo-guide-bundles-pro'] = [
 ('Settings: WooCommerce › Bundles & Plans', [
   T([
     ['One recurring invoice', 'Subscriptions bought together (same period, no trial) become one subscription with one renewal invoice.'],
     ['Separate subscriptions', 'Invoice each on its own, or one renewal statement per customer and day (records are never merged).'],
     ['Renew on day / Renewal day applies to / First payment', 'One renewal day (1–28) for bundles or all monthly subscriptions; first payment covers only the days until then.'],
     ['Check stock / Tell', 'Days before a renewal to check every item is in stock; who is told.'],
     ['Hold renewals', 'When a critical component is out of stock, hold the renewal (not charged) until you replace, skip, ship partly or delay it on the order.'],
     ['Shipping weight', 'A bundle without its own weight weighs as much as its components.'],
     ['Share a flat price', 'How a flat price is split across components for tax, refunds and reports: in proportion, equally, or not at all.'],
     ['Coupons on bundles', 'Allowed or not allowed on bundle products.'],
     ['Change plan / Swap / Change items / Close changes', 'Customers can upgrade or downgrade (prorated), swap to a same-price variation, change pick-your-own items until this many days before renewal.'],
     ['Next shipment only', 'Customers can skip or replace a component for the next shipment only.'],
     ['Packing list / Replace a discontinued product', 'Export what to pack; swap a product for another in every bundle subscription at once.'],
   ]),
 ]),
 ('Settings: Product data › Bundle', [
   T([
     ['Bundle', 'The product is made of other products; their stock follows every order and renewal.'],
     ['Contents', 'Fixed components, the customer picks items (mix and match), or box builder (fixed components + choices in the Box builder tab).'],
     ['Component 1, 2, 3…', 'Product, quantity, Critical / Optional, Extra price. Save to get more rows.'],
     ['Items to choose from / Minimum items / Maximum items', 'For mix and match. Box sizes (variations) can set their own.'],
     ['Chosen items are critical', 'Out-of-stock items cannot be chosen and are reported before renewals.'],
     ['Price / Bundle discount (%)', 'Flat (the product\'s own price) or dynamic (total of the items chosen, less the discount).'],
     ['Delivery frequencies', 'Optional step in the builder: the customer picks how often.'],
   ]),
 ]),
]

# ---------------------------------------------------------------- Sarala Login Page
START['sarala-guide'] = [
 ('Where everything is', [
   T([
     ['Sarala Login › Settings', 'New sign-ups, where buttons show, login page URL, bot protection, login security, failed login log, providers.'],
     ['Sarala Login › Failed logins', 'Every failed attempt and the IPs locked out now (unlock here).'],
     ['Sarala Login › Button style (Pro)', 'Button style, corners, colours, with a preview.'],
     ['Sarala Login › CAPTCHA (Pro)', 'Turnstile, reCAPTCHA v2 or hCaptcha keys.'],
     ['Sarala Login › Login redirects (Pro)', 'Where each role goes after login and logout; blocked addresses.'],
     ['Sarala Login › Profiles (Pro)', 'Profile pictures and who can disconnect social accounts.'],
     ['Sarala Login › Login page design (Pro)', 'Template, logo, images, colours, texts of the login pages.'],
     ['Sarala Login › Hide & restrict (Pro)', 'Hide admin menus and screens per role or user.'],
     ['Sarala Login › User activity (Pro)', 'What logged-in users did, with IP and device.'],
     ['Sarala Login › Super admin (Pro)', 'Your administrators and what only you can do.'],
     ['Sarala Login › Pro license', 'Your license key and status.'],
   ], ('Menu', 'What you do there')),
 ]),
]
REF['sarala-guide'] = [
 ('Settings: Sarala Login › Settings', [
   T([
     ['New sign-ups', 'Create an account when someone logs in with a social account for the first time. New accounts get the Customer role with WooCommerce, otherwise your default role. Existing users with the same verified email are logged into their account.'],
     ['Where to show the buttons', 'WordPress login and registration pages, WooCommerce login and registration forms, and the checkout for guests (they come back with their cart). Elsewhere: [sarala_login_page] with optional redirect_to="…" and heading="…".'],
     ['Button text / Layout', '"Continue with", "Sign in with" or "Log in with"; full-width buttons with text, or icons in a row.'],
     ['Login page URL', 'A new address for the login page (for example team-login). Logged-out visitors opening wp-login.php or wp-admin get your 404 page. Locked out? Rename the plugin\'s folder over FTP.'],
     ['Powered by link', 'A small "Powered by Laghavi Digital" link under the buttons (optional).'],
     ['Bot protection', 'A math question on the login and/or registration forms.'],
     ['Limit login attempts: Lock out after / Lockout lasts / Never lock out', 'Failed logins allowed and in how many minutes, how long the lockout lasts, and IPs never locked out (add your own).'],
     ['Force password reset: Passwords expire after / For these roles', 'Days before a password must be changed; tick roles, or none for everyone. Social logins are not affected.'],
     ['Failed login log: Record / Keep entries for', 'Records each failed login (username tried, IP, device, location) for this many days.'],
     ['IP address source', 'Direct connection, Cloudflare, X-Forwarded-For or X-Real-IP. Change only if every attempt shows the same IP.'],
     ['Location / IPinfo token', 'Looks up city and country with IPinfo.io when you open the Failed logins page.'],
     ['Login providers', 'Tick Enable for each provider and paste its keys. Copy the Redirect URI shown under each provider into that provider\'s app. Your site must use HTTPS.'],
   ]),
 ]),
 ('Settings: Pro screens', [
   T([
     ['Button style › Style', 'Standard, icon tiles, outline boxes or brand pills.'],
     ['Button style › Corners / Colours', 'As designed, rounded, pill, square, circle; each provider\'s colours or your brand colours.'],
     ['CAPTCHA › Service / Site key / Secret key', 'Off, Cloudflare Turnstile, reCAPTCHA v2 or hCaptcha. If it cannot load, the math question is used, so nobody is locked out.'],
     ['Login redirects', 'After login and after logout per role (and everyone else). Full address or path; {user_login} and {user_id} placeholders. Optionally send people back to the page they were going to.'],
     ['Blocked redirects', 'Addresses nobody is ever sent to after login or logout: paths with *, full addresses or whole domains.'],
     ['Profiles › Profile pictures', 'Users upload their own picture on Users › Profile (and My Account), with a size limit.'],
     ['Profiles › Disconnecting social accounts', 'Who may remove a connected account; never the last one if the user has no password.'],
     ['Login page design', 'Template, logo and width, background and panel images, darken images, colours, font, corners, panel headline and text, footer, and the title and subtitle of each login screen. Live preview.'],
     ['Hide & restrict', 'Pick a role or user and tick the menus and screens they should not see. You always see everything.'],
     ['User activity', 'Optionally record every page logged-in users open; keep activity for N days.'],
     ['Super admin', 'Add administrators by email; only you can install plugins and themes, edit files and update WordPress (each optional); hide yourself from other admins. Recovery: define( \'SLPP_OWNER\', 1 ); in wp-config.php.'],
   ]),
 ]),
]
