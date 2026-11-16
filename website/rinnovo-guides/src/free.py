# Guides for the free plugin Rinnovo Subscriptions & Licenses (wordpress.org). Readable by anyone with a free
# account on laghavi.site. Labels and menu paths are exactly what the plugin shows.
# Extra block types (see build.block): ('h3', text), ('note', text), ('code', label, text), ('tabs', [(label, code)]).

T = lambda rows, head=('Setting', 'What it does'): ('table', list(head), rows)
API = 'https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/'

FREE = []

# ====================================================================== 1. Setup and settings
FREE.append(dict(slug='rinnovo-free-setup', family='Rinnovo (free plugin)',
title='Rinnovo Subscriptions & Licenses: setup and settings',
product='Rinnovo Subscriptions & Licenses (free)',
intro='Everything in the free plugin, from installing it to your first renewal: Stripe, your own payment plugin, subscription products, monthly and yearly plans, every setting, what customers see, and how to fix common problems. Nothing here needs a Pro plugin.',
sections=[
('Before you start', [
 ('ul', ['WordPress 6.2 or later and PHP 7.4 or later.',
         'WooCommerce installed and set up (store address, currency, at least one shipping zone if you ship).',
         'A payment method: Stripe (built in), or one of the card plugins listed in "Renewals with your own payment plugin", or bank transfer / cash (renewals are then paid by invoice).',
         'Pretty permalinks: Settings › Permalinks must not be "Plain".']),
 ('note', 'Rinnovo Subscriptions & Licenses is complete on its own. It needs no license key and has no limits. The Pro plugins only add extra features.'),
]),
('Install and activate', [
 ('ol', ['In WordPress go to Plugins › Add New and search for "Rinnovo Subscriptions & Licenses".',
         'Click Install now, then Activate.',
         'Go to Settings › Permalinks and click Save Changes once (nothing to change, saving refreshes the addresses used by My Account and the license API).',
         'A notice "Set up Stripe" appears if you have not set up Stripe yet. You can skip it if you use another payment plugin.']),
]),
('Where everything is', [
 T([
  ['WooCommerce › Settings › Subscriptions', 'Store-wide settings: checkout, renewals and reminders, cancelling, My Account design, reviews.'],
  ['WooCommerce › Settings › Payments › Stripe', 'Turn Stripe on, paste your secret key, test mode, webhook status.'],
  ['WooCommerce › Subscriptions', 'Every subscription: status, amount, next payment, how it renews, payment history, and the buttons Resume, Cancel at end of period, Cancel now, Charge renewal now / Send renewal invoice now, Mark as active.'],
  ['WooCommerce › Licenses', 'License keys you sold: search, Revoke, Restore, +1 year, Reissue key, Set limit, remove activations, create a key by hand.'],
  ['WooCommerce › License guide', 'How your software checks its key, with code examples. The second free guide explains it in more detail.'],
  ['Products › Edit product › Product data › General', 'The Subscription box: Subscription, Bill every, Billing period, Sign-up fee, Free trial (days), Number of payments.'],
  ['Products › Edit product › Product data › License', 'Sell a license key with the product (software, digital assets).'],
  ['Products › Edit product › Product data › Variations', 'The same Subscription box on each variation, for monthly / yearly plans.'],
  ['My Account (your customers)', 'Dashboard overview, Subscriptions (Pay now, Update card, Cancel, Resume) and Licenses (keys, downloads, activations).'],
 ], ('Menu', 'What you do there')),
]),
('First setup in 10 minutes', [
 ('ol', ['Set up Stripe (next section) or check that your own payment plugin saves cards.',
         'Open WooCommerce › Settings › Subscriptions. The defaults are fine for most shops; read the table in "Every setting" before going live.',
         'Create a subscription product (see "Create a subscription product").',
         'Turn on Stripe Test mode and buy your own product with the card 4242 4242 4242 4242, any future date, any CVC.',
         'Check WooCommerce › Subscriptions: your test subscription is Active with a next payment date.',
         'Open My Account › Subscriptions as the customer: you see the plan, next payment, Update card and Cancel.',
         'Turn Test mode off, paste the live key, and you are ready.']),
]),
('Stripe step by step', [
 ('ol', ['In Stripe go to Developers › API keys. Copy the Secret key (starts with sk_live_). For testing, also copy the test secret key (sk_test_).',
         'In WordPress go to WooCommerce › Settings › Payments and open Stripe.',
         'Tick Enable Stripe. Change Title and Description if you like (customers see them at checkout).',
         'Paste the key in Live secret key. For testing tick Test mode ("Use test keys (no real money moves)") and paste the key in Test secret key.',
         'Click Save changes. Rinnovo creates the Stripe webhook for you, and the Webhook line turns green: Connected.']),
 T([
  ['Enable', 'Shows Stripe at checkout.'],
  ['Title / Description', 'The name and text customers see for this payment method.'],
  ['Live secret key', 'From Stripe Dashboard › Developers › API keys. Starts with sk_live_ (or rk_live_ for a restricted key).'],
  ['Test mode', 'Use test keys, no real money moves. Test card: 4242 4242 4242 4242.'],
  ['Test secret key', 'Your sk_test_ key, used only while Test mode is on.'],
  ['Webhook', 'Shows whether Stripe can tell your shop about payments. Created automatically when you save the key.'],
  ['Webhook signing secret (optional)', 'Only if the automatic webhook setup failed and you added the webhook in Stripe yourself. Starts with whsec_.'],
 ]),
 'With Stripe, customers pay on Stripe\'s secure page (cards, Apple Pay, Google Pay, 3D Secure). Subscriptions become Stripe Billing subscriptions: Stripe charges each renewal and retries failed cards. Refunds work from the WooCommerce order screen.',
 ('note', 'Webhook not Connected? Your site must be reachable from the internet over https (a local or password-protected staging site cannot receive webhooks). See "Troubleshooting".'),
]),
('Renewals with your own payment plugin', [
 'Already use a payment plugin? Rinnovo saves the card or bank mandate at the first payment and charges it on each renewal date through that plugin. A failed charge is tried again after 1 and 3 days, and the customer gets a "Pay now" link. Turn this on with "Automatic renewals with other payment plugins" in WooCommerce › Settings › Subscriptions (on by default).',
 T([
  ['WooCommerce Stripe Gateway', 'Worldwide, including UAE and India', 'Turn on "Saved cards" in the Stripe settings.'],
  ['WooPayments', 'US, UK, EU, Australia and more', 'Turn on saved cards in the WooPayments settings.'],
  ['PayPal Payments', 'Worldwide', 'Turn on vaulting (saving PayPal and cards) in the PayPal Payments settings.'],
  ['Square', 'US, UK, Canada, Australia, Japan, EU', 'Allow customers to save cards in the Square settings.'],
  ['Braintree, Authorize.Net', 'Worldwide', 'Allow saved cards in the plugin\'s settings.'],
  ['Mollie', 'Europe', 'Cards, SEPA Direct Debit, iDEAL, Bancontact and other methods Mollie allows for recurring payments.'],
  ['Checkout.com', 'Worldwide, including UAE', 'Turn on "Save cards" in the Checkout.com settings.'],
  ['GoCardless', 'UK and EU (bank direct debit)', 'Works with the bank mandate from the first payment.'],
  ['Paystack', 'Nigeria, Ghana, South Africa, Kenya', 'Turn on "Saved cards" in the Paystack settings.'],
 ], ('Payment plugin', 'Region', 'What to turn on')),
 'Every other payment method (bank transfer, cash on delivery, Razorpay and others that don\'t save cards) still works: on each renewal date the customer gets an invoice email with a "Pay now" link. The table "This shop" in WooCommerce › Settings › Subscriptions shows how each of your enabled payment methods renews: Automatic (Stripe Billing), Automatic (saved card or mandate) or Invoice email with a "Pay now" link.',
]),
('Create a subscription product', [
 ('ol', ['Products › Add New. Give it a name and a Regular price (the amount charged every period).',
         'In Product data › General tick Subscription.',
         'Fill in Bill every and Billing period, for example 1 + month, or 3 + month for quarterly.',
         'Optional: Sign-up fee, Free trial (days), Number of payments.',
         'Publish. The price shows as "$99.00 / month · 7-day free trial · $20.00 sign-up fee" and the button says "Subscribe".']),
 T([
  ['Subscription', 'Charge the price again every billing period.'],
  ['Bill every', 'How many periods between payments: 1 = every period, 3 = every third.'],
  ['Billing period', 'Day, week, month or year.'],
  ['Sign-up fee', 'Charged once with the first payment, on top of the price.'],
  ['Free trial (days)', 'Days before the first charge. A reminder goes out before the trial ends.'],
  ['Number of payments', 'How many times to charge. 0 = until cancelled. 12 with monthly = a one-year plan.'],
 ], ('Field', 'What it does')),
 ('note', 'One subscription per order. Customers can buy other products together with it. Guests are asked to create an account so they can manage their subscription later.'),
]),
('Monthly and yearly plans on one product', [
 ('ol', ['Create a Variable product and add an attribute, for example "Plan" with the values Monthly | Yearly (tick "Used for variations").',
         'Variations › Generate variations.',
         'Open each variation, set its price and tick Subscription inside the variation.',
         'Set Billing period per variation: month for Monthly, year for Yearly. Each variation can have its own trial, sign-up fee and number of payments.',
         'Save. Customers pick their plan on the product page.']),
 'Selling software? Each variation can also have its own number of activations (License: activations per key), for example Single site, 5 sites, Unlimited. See the license keys guide.',
]),
('What customers see', [
 T([
  ['Product page', 'Price with period, trial and sign-up fee; a Subscribe button.'],
  ['Checkout', 'Goes straight to checkout (no cart page, if on). The order summary shows the sign-up fee and "Recurring total … Next payment on …". Quantities can be changed right in the summary.'],
  ['Emails', 'Order emails as usual, plus: upcoming renewal / trial ending reminder, payment failed (with an "Update card" button), renewal invoice with "Pay now", overdue notice, cancellation confirmation.'],
  ['My Account › Dashboard', 'Overview with active subscriptions, next payment, recent orders and quick links (Modern My Account design).'],
  ['My Account › Subscriptions', 'Status, amount, next payment, payment history, and Pay now, Update card, Cancel (at the end of the paid period) and Resume.'],
  ['My Account › Licenses', 'License keys with Copy and Download buttons, downloads, and the websites or devices each key is used on (with Remove).'],
 ], ('Where', 'What they see')),
]),
('Managing subscriptions', [
 'WooCommerce › Subscriptions lists every subscription with the monthly recurring revenue at the top. Open one to see its orders ("First order", "Renewal"), how it renews ("Charged automatically on each renewal date" or "Invoice emailed on each renewal date") and these buttons:',
 T([
  ['Cancel at end of period', 'Stops renewals. The customer keeps access until the paid period ends.'],
  ['Cancel now', 'Ends it right away.'],
  ['Resume', 'Restarts a cancelled (not yet ended) or on-hold subscription.'],
  ['Charge renewal now', 'Charges the next renewal to the saved card now (automatic renewals).'],
  ['Send renewal invoice now', 'Emails the "Pay now" invoice now (invoice renewals).'],
  ['Mark as active', 'For a subscription you know is paid (for example paid by bank transfer outside the shop).'],
 ], ('Button', 'What it does')),
 'A cancellation in My Account or here also cancels the subscription in Stripe. With other payment plugins nothing is charged after a cancellation, because Rinnovo creates every renewal charge itself.',
]),
('How a renewal works, day by day', [
 T([
  ['3 days before (Reminder before renewal)', 'Reminder email: "Your subscription renews on …" (or "Your trial ends on …").'],
  ['Renewal date', 'A renewal order is created. Stripe or the saved card is charged; otherwise the invoice email with "Pay now" is sent.'],
  ['Charge failed', 'Tried again after 1 and 3 days. The customer gets "Payment failed" with an "Update card" button.'],
  ['7 days after (put on hold after)', 'Still unpaid: the subscription goes on hold and the overdue notice is sent.'],
  ['30 days after (cancel after)', 'Still unpaid: the subscription is cancelled. 0 = never cancel automatically.'],
 ], ('When', 'What happens')),
 'Renewals run every hour in the background (WP-Cron). On low-traffic sites set up a real cron job in your hosting panel so they run on time (see Troubleshooting).',
]),
('Every setting', [
 'WooCommerce › Settings › Subscriptions:',
 ('h3', 'Checkout'),
 T([
  ['Skip the cart page', 'Send shoppers straight to checkout after "Buy now" / "Subscribe". Links to the cart open the checkout instead. Default: on.'],
  ['Editable order on checkout', 'Shoppers change quantities and remove items right on the checkout page. Needs the classic checkout ([woocommerce_checkout] shortcode). Default: on.'],
  ['Button text for products', 'The button on normal products. Default: Buy now.'],
  ['Button text for subscriptions', 'The button on subscription products. Default: Subscribe.'],
 ]),
 ('h3', 'Renewals and reminders'),
 T([
  ['Automatic renewals with other payment plugins', 'Save the card or bank mandate at the first payment and charge it on each renewal date. Default: on.'],
  ['This shop', 'Read-only table: each enabled payment method and how it renews.'],
  ['Reminder before renewal (days)', 'Email customers this many days before each renewal or trial end. 0 = no reminder. Default: 3.'],
  ['Unpaid invoice: put on hold after (days)', 'Counted from the renewal date, for invoices and for automatic charges that keep failing. Default: 7.'],
  ['Unpaid invoice: cancel after (days)', 'Counted from the renewal date. 0 = never cancel automatically. Default: 30.'],
  ['Customers can cancel', 'Show a "Cancel" button in My Account; the subscription ends at the end of the paid period. Default: on.'],
  ['Modern My Account design', 'Overview dashboard with subscriptions, recent orders and quick links. Default: on.'],
 ]),
 ('h3', 'Reviews'),
 T([
  ['Only buyers can review', 'Only logged-in customers who bought the product can review it. Everyone else sees a short note. Default: on.'],
  ['Show when they bought it', 'Shows "Purchased in March 2026" (month and year) under each reviewer\'s name. Default: on.'],
 ]),
 'Product reviews must be on in WooCommerce › Settings › Products. To show "1,234 downloads" under a product\'s price, tick Show downloads on that product (or use the [rinnovo_downloads] shortcode anywhere).',
]),
('Selling software and digital files', [
 'Tick Sell a license in a product\'s License tab and every paid order gets a license key, shown on the thank-you page, in the order email and in My Account › Licenses. You can upload the file customers download, set how many sites or devices a key works on, and give WordPress plugins and themes automatic updates.',
 'The guide "Rinnovo license keys: connect your software" explains every field, each kind of product, where to put the license screen in your product, code examples in eight languages, every error message and how to release updates.',
]),
('Troubleshooting', [
 T([
  ['Webhook is not Connected', 'The site must be public and on https. Save the Stripe settings again. If it still fails, add the webhook in Stripe › Developers › Webhooks yourself (the address is shown on the Stripe settings screen) and paste its whsec_ secret in Webhook signing secret.'],
  ['Renewals are late or not created', 'WP-Cron only runs when someone visits the site. In your hosting panel add a cron job every 5–15 minutes: wget -q -O - https://your-store.com/wp-cron.php?doing_wp_cron, and add define( \'DISABLE_WP_CRON\', true ); to wp-config.php.'],
  ['Card plugin renewals become invoices', 'Turn on saved cards in the payment plugin (see the table above) and check "This shop" says Automatic. Orders paid before saved cards were on renew by invoice until the customer pays once with a saved card.'],
  ['My Account › Subscriptions or Licenses shows "Page not found"', 'Settings › Permalinks › Save Changes.'],
  ['Customers can\'t change quantities on checkout', 'Your checkout page uses the Checkout block. Use the classic [woocommerce_checkout] shortcode, or accept that quantities are changed on the product page.'],
  ['The Subscribe button still says "Add to cart"', 'Your theme or page builder prints its own button. Check the product has Subscription ticked; for builders use the WooCommerce "Add to cart" widget, which uses the shop\'s button text.'],
  ['Stripe test payment fails', 'Test mode must be on with a sk_test_ key, and you must use a test card (4242 4242 4242 4242).'],
  ['A customer was charged after cancelling', 'A Stripe subscription created outside Rinnovo is not cancelled by it. Cancel it in the Stripe Dashboard and refund from the WooCommerce order.'],
 ], ('Problem', 'Fix')),
]),
('Questions people ask', [
 T([
  ['Do I need another subscription plugin?', 'No. Rinnovo handles subscription products, renewals, emails and the customer pages itself.'],
  ['Does it need a license key?', 'No. The license keys are for the software you sell in your own shop.'],
  ['What happens when I deactivate or delete it?', 'Subscription and license records stay, because they are financial history. Stripe subscriptions keep charging until you cancel them in Stripe.'],
  ['Can I move subscribers from another plugin?', 'Ask us through the contact page: we help with imports.'],
  ['What do the Pro plugins add?', 'Invoices and tax invoices, trials and first-payment offers, VAT / GST, currencies, "Ask for price", and plugins for subscription boxes, memberships and courses, streaming, service contracts, classes and bundles.'],
 ], ('Question', 'Answer')),
]),
]))

# ====================================================================== 2. License keys
WP_FILE = r'''<?php
/**
 * Plugin Name: Photo Editor Pro
 * Version:     1.4.0
 */

// 1. The file you downloaded from the License tab, saved in your plugin folder.
require_once __DIR__ . '/photo-editor-pro-license.php';

// 2. Start it with your main file and your current version (keep it equal to "Version:" above).
Photo_Editor_Pro_License::init( __FILE__, '1.4.0' );

// 3. Turn paid features on only while the key is active.
add_action( 'plugins_loaded', function () {
    if ( Photo_Editor_Pro_License::is_active() ) {
        require_once __DIR__ . '/includes/pro-features.php';
    }
} );'''

WP_THEME = r'''<?php
// functions.php of a theme whose folder is named photo-studio
require_once __DIR__ . '/photo-studio-license.php';
Photo_Studio_License::init( __FILE__, '2.1.0' ); // keep equal to "Version:" in style.css

// Show a notice in the dashboard until the key is active.
add_action( 'admin_notices', function () {
    if ( ! Photo_Studio_License::is_active() ) {
        echo '<div class="notice notice-warning"><p>Enter your Photo Studio license key in Appearance › Photo Studio license to get updates.</p></div>';
    }
} );'''

CURL = r'''# Activate (once, when the customer enters the key)
curl -X POST https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/license/activate \
  -d license_key=ABCDE-FGHJK-LMNPQ-RSTUV \
  -d slug=photo-editor-pro \
  -d instance=LAPTOP-01

# Check (once a day)
curl -X POST https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/license/check \
  -d license_key=ABCDE-FGHJK-LMNPQ-RSTUV -d slug=photo-editor-pro -d instance=LAPTOP-01

# Deactivate (moving to another computer)
curl -X POST https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/license/deactivate \
  -d license_key=ABCDE-FGHJK-LMNPQ-RSTUV -d slug=photo-editor-pro -d instance=LAPTOP-01

# Latest version (GET, same three values in the address)
curl "https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/license/update?license_key=ABCDE-FGHJK-LMNPQ-RSTUV&slug=photo-editor-pro&instance=LAPTOP-01"'''

JS_EXT = r'''// license.js – shared by the options page and the background service worker
const API = 'https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/';
const PRODUCT_ID = 'photo-editor-pro';
const OFFLINE_DAYS = 7; // keep working this long when the store can't be reached

async function instanceId() {
  let { instance } = await chrome.storage.local.get('instance');
  if (!instance) {
    instance = crypto.randomUUID(); // one ID per install
    await chrome.storage.local.set({ instance });
  }
  return instance;
}

async function call(route, key) {
  const body = new URLSearchParams({ license_key: key, slug: PRODUCT_ID, instance: await instanceId() });
  const res = await fetch(API + 'license/' + route, { method: 'POST', body });
  try {
    return await res.json();          // errors are JSON too, with a "message"
  } catch (e) {
    throw new Error('The license server did not answer. Please try again later.');
  }
}

export async function activate(key) {
  key = key.trim().toUpperCase();
  const data = await call('activate', key);
  if (!data.success) throw new Error(data.message || 'The key could not be activated.');
  await chrome.storage.local.set({ license: { key, status: 'active', expires: data.expires || '', checked: Date.now() } });
  return data;
}

export async function deactivate() {
  const { license } = await chrome.storage.local.get('license');
  if (license) await call('deactivate', license.key).catch(() => {});
  await chrome.storage.local.remove('license');
}

export async function dailyCheck() {
  const { license } = await chrome.storage.local.get('license');
  if (!license) return;
  try {
    const data = await call('check', license.key);
    if (data.status) {
      Object.assign(license, { status: data.status, expires: data.expires || '', checked: Date.now() });
      await chrome.storage.local.set({ license });
    }
  } catch (e) { /* offline: keep the last status */ }
}

export async function isActive() {
  const { license } = await chrome.storage.local.get('license');
  return !!license && license.status === 'active' && Date.now() - license.checked < OFFLINE_DAYS * 864e5;
}'''

JS_EXT_BG = r'''// manifest.json (Manifest V3)
{
  "manifest_version": 3,
  "name": "Photo Editor Pro",
  "version": "1.4.0",
  "permissions": ["storage", "alarms"],
  "host_permissions": ["https://your-store.com/*"],
  "background": { "service_worker": "background.js", "type": "module" },
  "options_page": "options.html"
}

// background.js – check once a day
import { dailyCheck } from './license.js';
chrome.runtime.onInstalled.addListener(() => chrome.alarms.create('license', { periodInMinutes: 1440 }));
chrome.alarms.onAlarm.addListener((alarm) => { if (alarm.name === 'license') dailyCheck(); });

// options.js – the license screen (options.html has <input id="key">, <button id="go">, <p id="msg">)
import { activate } from './license.js';
document.getElementById('go').addEventListener('click', async () => {
  const msg = document.getElementById('msg');
  try {
    const data = await activate(document.getElementById('key').value);
    msg.textContent = 'Active' + (data.expires ? ', updates until ' + data.expires.slice(0, 10) : '');
  } catch (e) {
    msg.textContent = e.message; // the store's message, written for the customer
  }
});'''

NODE = r'''// Electron or Node.js 18+ (built-in fetch). Save the license next to your app's settings.
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const os = require('os');

const API = 'https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/';
const PRODUCT_ID = 'photo-editor-pro';
const FILE = path.join(os.homedir(), '.photo-editor-pro', 'license.json'); // Electron: app.getPath('userData')

function load() { try { return JSON.parse(fs.readFileSync(FILE, 'utf8')); } catch { return {}; } }
function save(data) { fs.mkdirSync(path.dirname(FILE), { recursive: true }); fs.writeFileSync(FILE, JSON.stringify(data)); }

function instanceId() {
  const data = load();
  if (!data.instance) { data.instance = crypto.randomUUID(); save(data); } // or a hashed machine ID
  return data.instance;
}

async function call(route, key) {
  const body = new URLSearchParams({ license_key: key, slug: PRODUCT_ID, instance: instanceId() });
  const res = await fetch(API + 'license/' + route, { method: 'POST', body, signal: AbortSignal.timeout(15000) });
  return res.json();
}

async function activate(key) {
  const reply = await call('activate', key.trim());
  if (!reply.success) throw new Error(reply.message);
  save({ ...load(), key: key.trim(), status: 'active', expires: reply.expires, checked: Date.now() });
}

async function check() {
  const data = load();
  if (!data.key) return false;
  try {
    const reply = await call('check', data.key);
    if (reply.status) save({ ...data, status: reply.status, expires: reply.expires, checked: Date.now() });
  } catch { /* offline: keep the last status */ }
  const now = load();
  return now.status === 'active' && Date.now() - now.checked < 7 * 864e5;
}

module.exports = { activate, check };'''

PY = r'''# Desktop apps, CLI tools, Blender add-ons. Standard library only.
import hashlib, json, os, subprocess, sys, time, uuid
import urllib.error, urllib.parse, urllib.request

API = 'https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/'
PRODUCT_ID = 'photo-editor-pro'
STORE = os.path.join(os.path.expanduser('~'), '.photo-editor-pro.json')
OFFLINE_DAYS = 7

def machine_id():
    """A stable ID for this computer, hashed so the raw ID never leaves it."""
    try:
        if sys.platform.startswith('win'):
            import winreg
            key = winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r'SOFTWARE\Microsoft\Cryptography', 0,
                                 winreg.KEY_READ | winreg.KEY_WOW64_64KEY)
            raw = winreg.QueryValueEx(key, 'MachineGuid')[0]
        elif sys.platform == 'darwin':
            out = subprocess.check_output(['ioreg', '-rd1', '-c', 'IOPlatformExpertDevice']).decode()
            raw = out.split('"IOPlatformUUID" = "')[1].split('"')[0]
        else:
            raw = open('/etc/machine-id').read().strip()
    except Exception:
        raw = str(uuid.getnode())
    return hashlib.sha256((PRODUCT_ID + ':' + raw).encode()).hexdigest()[:32]

def call(route, key):
    body = urllib.parse.urlencode({'license_key': key, 'slug': PRODUCT_ID, 'instance': machine_id()}).encode()
    try:
        with urllib.request.urlopen(API + 'license/' + route, data=body, timeout=15) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:      # 403, 404, 429: still JSON with a message
        return json.load(e)

def load():
    try:
        return json.load(open(STORE))
    except (OSError, ValueError):
        return {}

def activate(key):
    reply = call('activate', key.strip())
    if not reply.get('success'):
        raise RuntimeError(reply.get('message', 'The key could not be activated.'))
    json.dump({'key': key.strip(), 'status': 'active', 'expires': reply.get('expires', ''), 'checked': time.time()}, open(STORE, 'w'))

def is_active():
    data = load()
    if not data.get('key'):
        return False
    if time.time() - data.get('checked', 0) > 86400:          # once a day
        try:
            reply = call('check', data['key'])
            if reply.get('status'):
                data.update(status=reply['status'], expires=reply.get('expires', ''), checked=time.time())
                json.dump(data, open(STORE, 'w'))
        except (urllib.error.URLError, OSError, ValueError):
            pass                                              # offline: keep the last status
    return data.get('status') == 'active' and time.time() - data.get('checked', 0) < OFFLINE_DAYS * 86400'''

CS = r'''// .NET 6+ (WPF, WinForms, MAUI, console). System.Text.Json is built in.
using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Text.Json;
using System.Threading.Tasks;

public record LicenseReply(bool success, string? status, string? code, string? message, string? expires);

public static class License
{
    const string Api = "https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/";
    const string ProductId = "photo-editor-pro";
    static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(15) };

    // Windows: the MachineGuid, hashed. Elsewhere use a random ID you save once.
    public static string MachineId()
    {
        var raw = Microsoft.Win32.Registry.GetValue(@"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Cryptography", "MachineGuid", null)?.ToString()
                  ?? Environment.MachineName;
        var hash = System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(ProductId + ":" + raw));
        return Convert.ToHexString(hash)[..32];
    }

    public static async Task<LicenseReply> Call(string route, string key)
    {
        var form = new FormUrlEncodedContent(new Dictionary<string, string>
        {
            ["license_key"] = key.Trim(), ["slug"] = ProductId, ["instance"] = MachineId()
        });
        try
        {
            using var res = await Http.PostAsync(Api + "license/" + route, form);
            var json = await res.Content.ReadAsStringAsync();   // 4xx answers are JSON too
            return JsonSerializer.Deserialize<LicenseReply>(json)!;
        }
        catch (Exception e) when (e is HttpRequestException or TaskCanceledException or JsonException)
        {
            return new LicenseReply(false, null, "offline", "The license server could not be reached. Please try again later.", null);
        }
    }
}

// var reply = await License.Call("activate", keyTextBox.Text);
// if (reply.success) SaveSettings(key, reply.expires); else MessageBox.Show(reply.message);'''

UNITY = r'''// Unity (any platform). Attach to a GameObject on your start or settings scene.
using System;
using System.Collections;
using UnityEngine;
using UnityEngine.Networking;

public class LicenseCheck : MonoBehaviour
{
    const string Api = "https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/";
    const string ProductId = "my-game";

    [Serializable] public class Reply { public bool success; public string status; public string code; public string message; public string expires; }

    public IEnumerator Call(string route, string key, Action<Reply> done)
    {
        var form = new WWWForm();
        form.AddField("license_key", key.Trim());
        form.AddField("slug", ProductId);
        form.AddField("instance", SystemInfo.deviceUniqueIdentifier);
        using var req = UnityWebRequest.Post(Api + "license/" + route, form);
        req.timeout = 15;
        yield return req.SendWebRequest();
        if (req.result == UnityWebRequest.Result.ConnectionError)
        {
            done(null); // offline: use the status saved in PlayerPrefs
            yield break;
        }
        done(JsonUtility.FromJson<Reply>(req.downloadHandler.text)); // 4xx answers are JSON too
    }
}

// StartCoroutine(Call("activate", input.text, r => {
//     if (r != null && r.success) { PlayerPrefs.SetString("license", input.text); PlayerPrefs.SetString("license_status", "active"); }
//     else if (r != null) errorLabel.text = r.message;
// }));'''

JAVA = r'''// Java 8+ and Android (org.json is built into Android; on desktop add the org.json library).
// Android: call from a background thread, and add <uses-permission android:name="android.permission.INTERNET" />.
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.Scanner;
import org.json.JSONObject;

public final class License {
    static final String API = "https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/";
    static final String PRODUCT_ID = "photo-editor-pro";

    public static JSONObject call(String route, String key, String instance) throws Exception {
        String body = "license_key=" + URLEncoder.encode(key.trim(), "UTF-8")
                + "&slug=" + URLEncoder.encode(PRODUCT_ID, "UTF-8")
                + "&instance=" + URLEncoder.encode(instance, "UTF-8");
        HttpURLConnection c = (HttpURLConnection) new URL(API + "license/" + route).openConnection();
        c.setRequestMethod("POST");
        c.setDoOutput(true);
        c.setConnectTimeout(15000);
        c.setReadTimeout(15000);
        c.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");
        try (OutputStream out = c.getOutputStream()) {
            out.write(body.getBytes(StandardCharsets.UTF_8));
        }
        InputStream in = c.getResponseCode() < 400 ? c.getInputStream() : c.getErrorStream(); // errors are JSON too
        try (Scanner s = new Scanner(in, "UTF-8").useDelimiter("\\A")) {
            return new JSONObject(s.hasNext() ? s.next() : "{}");
        }
    }
}

// Android instance: a UUID saved once in SharedPreferences.
// JSONObject r = License.call("activate", key, instanceId);
// if (r.optBoolean("success")) save(key, r.optString("expires")); else showError(r.optString("message"));'''

SWIFT = r'''// Swift 5.5+ (iOS, iPadOS, macOS). async/await with URLSession.
import Foundation

struct LicenseReply: Decodable {
    let success: Bool?
    let status: String?
    let code: String?
    let message: String?
    let expires: String?
}

enum License {
    static let api = "https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/"
    static let productId = "photo-editor-pro"

    /// One ID per install, kept in UserDefaults (iOS: or UIDevice.current.identifierForVendor).
    static var instanceId: String {
        if let id = UserDefaults.standard.string(forKey: "license_instance") { return id }
        let id = UUID().uuidString
        UserDefaults.standard.set(id, forKey: "license_instance")
        return id
    }

    static func call(_ route: String, key: String) async throws -> LicenseReply {
        var request = URLRequest(url: URL(string: api + "license/" + route)!, timeoutInterval: 15)
        request.httpMethod = "POST"
        request.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
        var form = URLComponents()
        form.queryItems = [
            URLQueryItem(name: "license_key", value: key.trimmingCharacters(in: .whitespaces)),
            URLQueryItem(name: "slug", value: productId),
            URLQueryItem(name: "instance", value: instanceId),
        ]
        request.httpBody = form.percentEncodedQuery?.data(using: .utf8)
        let (data, _) = try await URLSession.shared.data(for: request) // 4xx answers are JSON too
        return try JSONDecoder().decode(LicenseReply.self, from: data)
    }
}

// let reply = try await License.call("activate", key: keyField.text ?? "")
// if reply.success == true { /* save key + expires */ } else { showAlert(reply.message ?? "") }'''

GO = r'''// Go CLI tools and services. Standard library only.
package license

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"time"
)

const (
	api       = "https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/"
	productID = "my-cli"
)

type Reply struct {
	Success bool   `json:"success"`
	Status  string `json:"status"`
	Code    string `json:"code"`
	Message string `json:"message"`
	Expires string `json:"expires"`
}

var client = &http.Client{Timeout: 15 * time.Second}

// Call sends activate, check or deactivate. instance = a hashed machine ID saved in your config file.
func Call(route, key, instance string) (*Reply, error) {
	form := url.Values{"license_key": {key}, "slug": {productID}, "instance": {instance}}
	res, err := client.PostForm(api+"license/"+route, form)
	if err != nil {
		return nil, err // offline: keep the last status
	}
	defer res.Body.Close()
	var r Reply
	if err := json.NewDecoder(res.Body).Decode(&r); err != nil { // 4xx answers are JSON too
		return nil, fmt.Errorf("license server: %w", err)
	}
	return &r, nil
}

// mycli activate ABCDE-FGHJK-LMNPQ-RSTUV
// r, err := license.Call("activate", os.Args[2], machineID())
// if err == nil && !r.Success { fmt.Println(r.Message) }'''

PHP = r'''<?php
// Any PHP app on YOUR server (Laravel, Symfony, plain PHP): SaaS, hosted APIs, member areas.
// Never call the store from the customer's browser: check keys on your server.
const LICENSE_API = 'https://your-store.com/wp-json/rinnovo-subscriptions-licenses/v1/';
const PRODUCT_ID  = 'photo-editor-pro';

function license_call( string $route, string $key, string $instance ): array {
    $ch = curl_init( LICENSE_API . 'license/' . $route );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query( [
            'license_key' => trim( $key ),
            'slug'        => PRODUCT_ID,
            'instance'    => $instance,           // e.g. 'account-' . $account->id
        ] ),
    ] );
    $body = curl_exec( $ch );
    curl_close( $ch );
    $data = is_string( $body ) ? json_decode( $body, true ) : null;
    return is_array( $data ) ? $data : [ 'success' => false, 'code' => 'offline', 'message' => 'The license server could not be reached.' ];
}

// When the customer saves their key in your app's Billing page:
$reply = license_call( 'activate', $_POST['license_key'] ?? '', 'account-' . $account->id );
if ( ! empty( $reply['success'] ) ) {
    $account->update( [ 'license_key' => trim( $_POST['license_key'] ), 'license_status' => 'active', 'license_expires' => $reply['expires'] ] );
} else {
    $error = $reply['message'];               // show it as it is
}

// Daily job (cron / scheduler) for every account with a key:
$reply = license_call( 'check', $account->license_key, 'account-' . $account->id );
if ( isset( $reply['status'] ) && 'offline' !== ( $reply['code'] ?? '' ) ) {
    $account->update( [ 'license_status' => $reply['status'] ] );
}'''

UPDATE_JS = r'''// Ask for the latest version (any language: a GET with the same three values).
const q = new URLSearchParams({ license_key: key, slug: 'photo-editor-pro', instance });
const info = await (await fetch(API + 'license/update?' + q)).json();
// info = { version: "1.5.0", package: "https://…/license/download?…", changelog: "<p>…</p>",
//          tested: "7.1", requires: "6.2", requires_php: "7.4", homepage: "https://your-store.com/product/…" }

if (isNewer(info.version, CURRENT_VERSION)) {
  if (info.package) showUpdateButton(info.package);   // only for keys active on this instance
  else showMessage('Version ' + info.version + ' is available. Renew your license to download it.');
}

function isNewer(a, b) {            // "1.10.0" > "1.9.2"
  const x = a.split('.').map(Number), y = b.split('.').map(Number);
  for (let i = 0; i < Math.max(x.length, y.length); i++) {
    if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) > (y[i] || 0);
  }
  return false;
}'''

FREE.append(dict(slug='rinnovo-free-licenses', family='Rinnovo (free plugin)',
title='Rinnovo license keys: connect your software',
product='Rinnovo Subscriptions & Licenses (free)',
intro='Sell license keys for WordPress plugins and themes, desktop and mobile apps, browser extensions, SaaS, developer tools, games and digital assets. This guide shows how to connect each kind of product to its key, where to put the license screen inside your product, working code in eight languages, what every error means and how to fix it, and how to release updates and make changes safely.',
sections=[
('How license keys work', [
 ('ol', ['You tick Sell a license on a product and give it a Product ID, for example photo-editor-pro.',
         'Every paid order gets a key like ABCDE-FGHJK-LMNPQ-RSTUV. The customer sees it on the thank-you page, in the order email and in My Account › Licenses.',
         'The customer types the key into your product (its license screen).',
         'Your product sends three things to your store: the key, the Product ID, and an ID of where it runs (a website address, computer, device or account).',
         'The store answers in JSON: active, expired, revoked, invalid, inactive or limit. Each website, computer or device counts as one activation, up to "Activations per license".',
         'Your product saves the answer, turns paid features on or off, and checks again once a day.']),
 ('note', 'No password or secret goes into your product. The license key is the only credential, and wrong keys are limited to 30 an hour per IP address.'),
]),
('Set up a product that sells a license', [
 ('ol', ['Products › Add New (or edit a product). Set the price, and tick Subscription in the General tab if the license renews every month or year.',
         'Open Product data › License and tick Sell a license.',
         'Choose What you sell (see the next section). The tab changes to show how that kind of product connects.',
         'Fill in Product ID. For a WordPress plugin or theme use its folder name exactly (photo-editor-pro).',
         'Set Activations per license and License valid for (days).',
         'Upload the file customers download, or paste a download link.',
         'Fill in Latest version (and for WordPress: Requires WordPress, Tested up to, Requires PHP, Changelog).',
         'Publish. The section "Connect your product to its license" now shows your store\'s addresses, a cURL example and, for WordPress, the Download the license file button.']),
 T([
  ['Sell a license', 'Create a license key for each one bought.'],
  ['What you sell', 'The kind of product. Changes the help shown and, for WordPress, the license file you can download.'],
  ['Product ID', 'A short ID your product sends with the key, so a key only works for this product. Letters, numbers and dashes. Case, dashes and dots are ignored when comparing (Photo-Editor-Pro = photoeditorpro). If it is empty, keys can\'t be activated and the tab shows a red warning.'],
  ['Activations per license', 'How many websites, computers or devices can use one key. 0 = unlimited.'],
  ['License valid for (days)', '0 = lifetime. With a subscription the license follows the subscription instead. After this the key shows as expired: no new activations, downloads or updates. Your product decides what else stops.'],
  ['Latest version', 'Your product asks for it to show "update available".'],
  ['Requires WordPress / Tested up to / Requires PHP / Changelog', 'Shown in WordPress\'s "View details" popup and in Dashboard › Updates.'],
  ['File', 'Stored privately: only customers with an active key can download it. Allowed: zip, exe, msi, dmg, pkg, apk, pdf and more. Upload a new file for each release.'],
  ['Or a download link', 'For files kept elsewhere (App Store, Google Play, cloud storage, your app\'s sign-in page). Used when no file is uploaded; only customers with an active key see it.'],
 ], ('Field', 'What it does')),
 ('h3', 'Plans: 1 site, 5 sites, unlimited'),
 'Use a variable product. Each variation can have its own price, its own Subscription settings (monthly or yearly) and its own License: activations per key (0 = unlimited; empty = the number from the License tab). All variations share the same Product ID, so every plan works with the same build of your software.',
 ('h3', 'Keys sold with a subscription'),
 'The key follows the subscription: renewals keep the same key and extend it, a cancellation stops renewals and the key keeps working until the paid period ends, and an unpaid renewal makes the key expired once the subscription ends.',
 ('h3', 'Test before you sell'),
 ('ol', ['WooCommerce › Licenses › Add a license by hand: create a key for your test product (or buy it with Stripe test mode).',
         'Run the cURL example from the License tab with that key. You should get {"success":true,"status":"active",…}.',
         'Run it with a wrong Product ID to see the "different product" error, then with the right one again.',
         'Open WooCommerce › Licenses: the key shows 1 activation (LAPTOP-01 or your test site).']),
]),
('What you sell: the right settings for each kind of product', [
 T([
  ['WordPress plugin', 'site_url (the ready-made file sends it)', 'Settings › (Product) license', 'Download the license file. Product ID = plugin folder name.'],
  ['WordPress theme', 'site_url (the ready-made file sends it)', 'Appearance › (Product) license', 'Product ID = theme folder name; the folder must have exactly that name.'],
  ['Desktop application (Windows, macOS, Linux)', 'A hashed computer ID', 'First start dialog and Help › License', 'Allow a few offline days. Offer Deactivate before a computer change.'],
  ['Mobile application (Android, iOS)', 'A random ID saved on first start', 'Settings › License (or Account)', 'Read the App Store / Google Play rules on unlocking features bought outside the store.'],
  ['Browser extension', 'A random ID kept in chrome.storage.local', 'The extension\'s options page', 'Add your store to host_permissions. Check daily with an alarm.'],
  ['SaaS or web application', 'The customer\'s account ID', 'Account › Billing / License page in your app', 'Call the store from your server, never from the browser.'],
  ['Developer product (SDK, library, CLI, API)', 'CLI: computer ID. SDK: the project or domain. API: the account ID', 'mycli activate <key> or a config file', 'Check on your own server too for anything valuable.'],
  ['Game or game development product', 'Unity SystemInfo.deviceUniqueIdentifier or a saved random ID', 'Launcher or Settings › License', 'Check at start-up and cache the answer for offline play.'],
  ['Digital asset or creative product', 'Nothing (set Activations per license to 0)', 'None: the key is the proof of license', 'Only customers with an active key can download files and new versions.'],
  ['Other (course, content, service…)', 'Usually nothing', 'None', 'The key is a proof of purchase customers can quote to you.'],
 ], ('What you sell', 'What to send as instance / site_url', 'Where the license screen goes', 'Tips')),
 ('note', 'instance can be letters, numbers, dots, dashes, colons and @, up to 191 characters. Something that looks like a web address is treated as a website, so https://www.example.com/, http://example.com and example.com are the same activation. Hash hardware IDs before sending them.'),
]),
('Where to put the license screen in your product', [
 'Customers must be able to find the key field without reading docs. Put it in the place they already look for settings, and show the status next to it.',
 T([
  ['WordPress plugin', 'Automatic: Settings › (Product) license, made by the license file. Link to it from your plugin\'s row on the Plugins screen and from your own settings page.'],
  ['WordPress theme', 'Automatic: Appearance › (Product) license. Add a dashboard notice until the key is active (example below).'],
  ['Desktop app', 'A dialog on first start ("Enter your license key · Buy a license · Continue trial"), plus Help › License (Windows/Linux) or App menu › License… (macOS) with Activate / Deactivate.'],
  ['Mobile app', 'Settings › License with a key field and the status. Don\'t block the first screen; show the field when the user opens a paid feature.'],
  ['Browser extension', 'The options page (options_page in manifest.json). Show a badge or a popup link when no key is active.'],
  ['SaaS / web app', 'Account › Billing or Account › License. The key is saved on your server with the account.'],
  ['CLI tool', 'A command: mycli activate <key>, mycli deactivate, mycli status. Save the key in ~/.config/mycli/config.json.'],
  ['Game', 'Launcher, or Settings › License in the main menu. Never in the middle of play.'],
 ], ('Product', 'Where the key field goes')),
 'What the screen should show:',
 ('ul', ['A key field and an Activate button (Deactivate once active).',
         'The status in plain words: Active, updates until 31 January 2027 / Expired, renew to get updates / Not activated.',
         'The store\'s message after a failed activation, exactly as it was sent: it tells the customer what to do.',
         'Links: "Find your key in My Account › Licenses" and "Buy a license" (your product page).']),
]),
('WordPress plugins and themes: the ready-made license file', [
 ('ol', ['Set What you sell to WordPress plugin (or theme) and Product ID to your folder name.',
         'Save the product, then click Download the license file for your plugin or theme in the License tab. You get photo-editor-pro-license.php, made for this product and this store.',
         'Put the file in your plugin (or theme) folder.',
         'Add the two lines from the top of the file to your main plugin file (the one with "Plugin Name:"), or to functions.php for a theme.',
         'Ship the plugin. Customers enter their key in Settings › Photo Editor Pro license (themes: Appearance › … license). Updates then arrive in Dashboard › Updates like any other plugin while the key is active.']),
 ('code', 'Your main plugin file', WP_FILE),
 ('code', 'A theme (functions.php)', WP_THEME),
 T([
  ['init( __FILE__, $version )', 'Starts the license screen, the daily check and updates. $version must be the version of the build you ship.'],
  ['is_active()', 'true while the key is active on this site. Use it to switch paid features on or off.'],
  ['data()', 'The saved key, status, expiry date and last message.'],
  ['check()', 'Runs once a day by itself. If the store can\'t be reached, the last status is kept.'],
 ], ('Method', 'What it does')),
 ('note', 'The class name comes from your Product ID: photo-editor-pro becomes Photo_Editor_Pro_License. If you sell several plugins, each gets its own file and class, so they never clash.'),
 'What the file does for you: a license screen, activation and deactivation with the store\'s messages, a daily status check, update checks every 6 hours, the "View details" popup with your changelog, and downloads straight from your store (only for sites where the key is active).',
]),
('Your store\'s license addresses', [
 'Every request goes to your own store under /wp-json/rinnovo-subscriptions-licenses/v1/. The License tab shows the exact address for your store.',
 T([
  ['POST license/activate', 'Use the key here. Once, when the customer enters it.'],
  ['POST license/check', 'Is the key still active here? Once a day.'],
  ['POST license/deactivate', 'Free this activation (moving to another computer or site).'],
  ['GET license/update', 'Latest version, changelog, requirements, and a download link for keys active here.'],
  ['GET license/download', 'The file (or a redirect to your download link), only for keys active here.'],
 ], ('Address', 'When to call it')),
 T([
  ['license_key', 'Yes', 'The key the customer entered. Trim spaces.'],
  ['slug', 'Yes', 'Your Product ID.'],
  ['instance', 'One of the two', 'Where it runs: a computer, device, account or project ID.'],
  ['site_url', 'One of the two', 'Instead of instance, for anything that runs on a website.'],
 ], ('Send', 'Required', 'What it is')),
 'Send the values as a normal form (application/x-www-form-urlencoded) for POST, or in the address for GET. Always use https.',
 ('code', 'cURL: try it from a terminal', CURL),
]),
('Answers and what your product should do', [
 ('code', 'Answers (JSON)', '''// activate, success
{"success":true,"status":"active","expires":"2027-01-31 00:00:00","sites_allowed":3,"activations":1}

// check
{"success":true,"status":"active","expires":"2027-01-31 00:00:00"}
{"success":true,"status":"inactive","expires":"2027-01-31 00:00:00"}

// an error (activate, deactivate, update, download)
{"success":false,"code":"limit","status":"limit","message":"This key is already used on 3 sites or devices. Deactivate it on one of them first, or remove one in My Account > Licenses on the shop's website."}'''),
 T([
  ['active', 'Valid and activated here.', 'Turn everything on.'],
  ['inactive', 'Valid, but not activated here (check only).', 'Ask the customer to activate again.'],
  ['expired', 'The license or its subscription ended.', 'Show a renew link. You decide whether the product keeps working without updates.'],
  ['revoked', 'You revoked it (refund, abuse).', 'Turn paid features off.'],
  ['invalid', 'Unknown key, or a key for another product.', 'Show the message, keep the field editable.'],
  ['limit', 'Activation limit reached.', 'Show the message: it tells the customer how to free an activation.'],
  ['too_many', 'Too many wrong keys from this IP address.', 'Wait an hour. Don\'t retry in a loop.'],
 ], ('status', 'Meaning', 'What your product should do')),
 ('note', '"expires" is the end date in the store\'s time zone (YYYY-MM-DD HH:MM:SS), or empty for a lifetime key. Every error has a "message" written for the customer: show it as it is.'),
]),
('The recommended flow', [
 ('ol', ['Customer enters the key → call activate. On success save the key, "active", the expiry date and today\'s date.',
         'Once a day → call check and save the new status. If the store can\'t be reached, keep the last status. Allow some offline days (7 is a good default) before turning paid features off.',
         'Customer moves to a new computer or site → call deactivate on the old one (or they click Remove in My Account › Licenses), then activate on the new one.',
         'Updates → call update, compare "version" with yours, and offer the "package" link when it is newer.',
         'Never block your product\'s start-up waiting for the store. Check in the background with a 15-second timeout.']),
]),
('Code examples', [
 'Working examples you can copy. Replace your-store.com with your store\'s address and photo-editor-pro with your Product ID. Each one activates a key; check and deactivate work the same way with another route.',
 ('tabs', [
  ('Browser extension', JS_EXT + '\n\n' + JS_EXT_BG),
  ('Node.js / Electron', NODE),
  ('Python', PY),
  ('C# (.NET)', CS),
  ('C# (Unity)', UNITY),
  ('Java / Android', JAVA),
  ('Swift (iOS, macOS)', SWIFT),
  ('Go', GO),
  ('PHP (your server)', PHP),
  ('cURL', CURL),
 ]),
 ('h3', 'Checking for updates (any language)'),
 ('code', 'JavaScript', UPDATE_JS),
]),
('Errors: what each one means and how to fix it', [
 'activate, deactivate, update and download answer errors with an HTTP error code and JSON. check always answers 200 with the status, so a daily check never looks like a crash.',
 T([
  ['invalid · 404', 'This license key is not valid.', 'Typo, extra spaces, an old key after Reissue key, or no instance / site_url sent.', 'Trim and upper-case the key. Send instance or site_url. Look the key up in WooCommerce › Licenses.'],
  ['invalid · 404', 'This license key is for a different product.', 'The slug your product sends is not this product\'s Product ID.', 'Make the Product ID in the License tab and the slug in your code the same.'],
  ['not_configured · 409', 'This product is not set up for activation yet (no Product ID on the store). Please contact support.', 'The product has no Product ID.', 'Fill in Product ID in the License tab. WooCommerce › Licenses also warns about it.'],
  ['revoked · 403', 'This license key has been revoked.', 'You revoked it.', 'WooCommerce › Licenses › Restore, if it was a mistake.'],
  ['expired · 403', 'This license key has expired. Please renew it on (your store).', 'License valid for (days) passed, or the subscription ended.', 'The customer renews; or you click +1 year in WooCommerce › Licenses.'],
  ['limit · 403', 'This key is already used on 3 sites or devices…', 'Activations per license reached.', 'The customer removes an old site in My Account › Licenses, or you click Set limit / Remove in WooCommerce › Licenses.'],
  ['too_many · 429', 'Too many attempts. Please try again in an hour.', '30 wrong keys in an hour from one IP address.', 'Wait. A SaaS server calls for all its customers from one IP: check the key format first and never retry automatically.'],
  ['not_allowed · 403', 'This key cannot download updates here. Activate it first.', 'update/download for a site or device where the key is not active, or an expired key.', 'Activate first, and send exactly the same instance or site_url.'],
  ['missing · 404', 'The download is not available right now.', 'No file uploaded and no download link.', 'Upload the file in the License tab.'],
  ['rest_missing_callback_param · 400', 'Missing parameter(s): slug', 'WordPress\'s own error when license_key or slug is not sent. It has no "success" field.', 'Send both, as form fields (not JSON) for POST.'],
  ['rest_no_route · 404', 'No route was found matching the URL and request method.', 'Wrong address, GET instead of POST, or the plugin is not active.', 'Copy the address from the License tab. Settings › Permalinks › Save Changes.'],
  ['HTML instead of JSON, or 401 / 403', '(a login page, "Access denied", a Cloudflare challenge)', 'A security plugin, firewall or "Disable REST API" plugin blocks /wp-json/.', 'Allow /wp-json/rinnovo-subscriptions-licenses/v1/ in Wordfence, iThemes, Cloudflare WAF / Bot Fight Mode, or your host\'s firewall.'],
  ['Timeout / no internet', '(no answer)', 'The customer is offline, or your store is down.', 'Keep the last status and use the offline days. Never treat "no answer" as "invalid".'],
 ], ('code · HTTP', 'Message the customer sees', 'Why', 'Fix')),
 ('h3', 'WordPress updates don\'t show up'),
 ('ul', ['Latest version on the product must be higher than the version passed to init() in the installed build.',
         'A file must be uploaded (or a download link given) and the key must be active on that site.',
         'The license file caches the version for 6 hours: click Dashboard › Updates › Check again, or deactivate and activate the key.',
         'The plugin folder name must equal the Product ID; for themes the theme folder must equal it too.',
         'Staging copies count as their own site. Activate there or deactivate first.']),
]),
('Making changes after you start selling', [
 T([
  ['Release a new version', 'Upload the new file in the License tab, raise Latest version, add the Changelog, Update. WordPress sites see it within 6 hours; other products on their next update check.'],
  ['Change the Product ID', 'Avoid it: every copy you already shipped sends the old ID and would get "different product". If you must, ship a build with the new ID first, wait until customers have updated, then change it on the store.'],
  ['Change Activations per license', 'Applies to new keys. For existing keys use Set limit in WooCommerce › Licenses (per key).'],
  ['Change License valid for (days)', 'Applies to new keys. Extend existing keys with +1 year.'],
  ['Move your store to a new domain', 'The store address is built into your products. Keep the old domain pointing to the store (POST requests must not be redirected: many apps drop the form data on a redirect), or ship an update with the new address before you move.'],
  ['Refund', 'Revoke the key in WooCommerce › Licenses. Your product sees "revoked" on its next daily check.'],
  ['A key was shared or leaked', 'Reissue key: the customer gets a new key by email, the old one stops working, and their sites must be activated again.'],
  ['Customer changed domain or computer', 'They click Remove next to the old site in My Account › Licenses (or deactivate in the product), then activate on the new one. http/https, "www." and a trailing slash don\'t count as a change.'],
  ['Switch from lifetime to yearly', 'Set License valid for (days) to 365, or turn on Subscription for new sales. Existing lifetime keys stay lifetime.'],
 ], ('Change', 'How to do it safely')),
]),
('Managing keys in WooCommerce › Licenses', [
 T([
  ['Search', 'By key, email or order number.'],
  ['Remove (next to a site)', 'Frees one activation.'],
  ['Revoke / Restore', 'Turn a key off (refund, abuse) or back on.'],
  ['+1 year', 'Moves the expiry date one year later.'],
  ['Reissue key', 'A new key for the same license, emailed to the customer. The old key stops working.'],
  ['Set limit', 'Change how many sites or devices this key can be used on.'],
  ['Add a license by hand › Create key', 'For a test, a gift, a partner or a sale made outside the shop.'],
 ], ('Action', 'What it does')),
 'Customers see their keys in My Account › Licenses with Copy and Download buttons, the latest version, "2 of 5 used", each site or device with Remove, and for subscriptions "Renews automatically on …" with a Cancel subscription button.',
]),
('Security checklist', [
 ('ul', ['The key is the only credential: don\'t put an API secret in software you ship.',
         'Anything that runs on the customer\'s computer can be changed. For valuable features, also check the key on your own server (your API, update service or cloud features).',
         'SaaS: check keys from your server, never from the browser.',
         'Hash hardware IDs before sending them; never send serial numbers or MAC addresses as they are.',
         'Always use https. Don\'t turn off certificate checks.',
         'Show the store\'s message instead of your own guess: it already tells the customer what to do.']),
]),
]))
