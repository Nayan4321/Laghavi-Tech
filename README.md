# CallGear → Zenoti Screen-Pop

When a call comes in on CallGear, this looks up the caller's phone number in
Zenoti and pops the matching client's profile open on the agent's screen —
no manual searching.

There are two implementations of the same app in this repo. They do the
same thing; pick whichever matches your hosting:

- **[`php/`](php/README.md) — recommended for you.** Plain PHP files,
  works on any ordinary Hostinger shared hosting plan. No terminal, no
  npm, just upload files via File Manager.
- **[`node/`](node/README.md)** — Node.js. Only usable if your Hostinger
  plan includes the Node.js app manager (hPanel → Advanced → Node.js) —
  most basic shared plans don't have this.

Start with `php/README.md` — it has the full click-by-click setup,
including exactly what to send the Zenoti team and how to wire up CallGear.

## For fully automatic popups (no tab, no click)

`php/` alone requires one click per browser session to open the profile
(a hard browser security rule, not a limitation of this code). For true
zero-click automation — the profile just opens the instant a call rings,
with nothing open beforehand — see **[`extension/`](extension/README.md)**,
a small browser extension installed once on each agent's computer. It
reuses the same `php/` backend; only the delivery mechanism changes.

## WordPress plugins by Laghavi Digital

- [`sarala-login-page/`](sarala-login-page/readme.txt): free plugin for
  WordPress.org. Social login (Google, Facebook, Apple, Microsoft, LinkedIn)
  with WooCommerce support, a math question against login bots, a custom
  login URL, and a failed-login log. Zip the folder without `.wordpress-org/`
  (that folder holds the WordPress.org icon and banner).
- [`sarala-login-page-pro/`](sarala-login-page-pro/readme.txt): paid add-on
  sold on laghavi.com. Super admin, user activity log, and per-role/per-user
  hiding of admin menus, editor blocks, panels, meta boxes and custom fields,
  plus front-end block and widget visibility. Needs the free plugin.
- [`laghavi-license-manager/`](laghavi-license-manager/readme.txt): install on
  laghavi.com only. Turns WooCommerce sales into license keys, handles site
  activations, and serves Pro updates.
- [`laghavi-subscriptions/`](laghavi-subscriptions/readme.txt): WooCommerce
  subscriptions without a third-party plugin. Stripe Billing renewals (as a
  WooCommerce payment method), invoice renewals for other gateways, reminder
  emails, a new My Account dashboard, checkout without a cart page, and
  "Ask for price" products with contact/WhatsApp buttons.
