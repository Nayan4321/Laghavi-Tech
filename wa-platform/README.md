# WhatsApp Team Platform

A **team inbox + agent-monitoring platform** for a company's WhatsApp **sales
numbers**, built on the official **WhatsApp Business Cloud API** (Meta) with
**PHP + Laravel**.

Agents reply to customers from a mobile-friendly app (installable PWA), and the
owner/supervisor sees **every conversation, response time, and flagged message**
— so you can check agents are chatting fairly.

> **Scope, honestly:** this monitors conversations on **company-owned numbers
> that customers message**, because the business owns those WhatsApp Business
> accounts. It does **not** and cannot read agents' *personal* WhatsApp chats —
> that would need spyware and isn't supported. Tell agents their work
> conversations on company numbers are logged; that keeps this clean and legal.

---

## What it does

- **Agent inbox (PWA):** mobile-first, installable ("Add to Home Screen"),
  push-notification ready. Chat list, conversations, media, quick replies,
  resolve/claim — feels like the WhatsApp Business app, but it's *your* system.
- **Multi-number:** one WABA holds up to 20 numbers; agents are mapped to the
  numbers they're allowed to work on. Supports both "a number per agent" and
  "a few shared numbers."
- **Supervisor dashboard:** read any conversation, per-agent metrics (messages
  sent, avg. first-response time, open chats, last active), live counts.
- **Keyword flagging:** auto-flag agent messages that share personal numbers,
  offer unauthorised discounts, use rude language, or try to move customers
  off-platform. Supervisors review flags.
- **Audit log:** who viewed/sent/exported what, with IP.
- **CSV export** of messages for records.
- **Campaigns-ready:** the Cloud API send layer supports template (broadcast)
  messages for engagement — wire a campaigns UI on top once the ad test (below)
  passes.

---

## ⚠️ Read first: the Click-to-WhatsApp ad test

If you run **Click-to-WhatsApp / engagement ads**, do this **before migrating
all your numbers**:

A WhatsApp number can be in **one** mode at a time — the WhatsApp Business
**app**, or the **Cloud API** (this platform) — never both. Click-to-WhatsApp
ads officially support Cloud API numbers, **but Meta's behaviour varies by
account**, so verify it on **one** number first:

1. Migrate **one** spare / low-traffic number to the Cloud API and add it here.
2. Point this platform's webhook at it (see setup) so replies land in the inbox.
3. In Ads Manager, run a small Click-to-WhatsApp ad to that number.
4. **If Meta runs the ad and replies arrive in the inbox → roll out the rest.**
   **If Meta rejects it → do not migrate your ad numbers; keep them on the
   Business app and reconsider which numbers to monitor.**

Migrating a number removes it from the phone app, and after that the **only**
place ad replies appear is this platform — so the platform must be live first.

---

## Requirements

- PHP 8.2+ (8.3 recommended), with `pdo`, `mbstring`, `openssl`, `gd`, `curl`,
  `zip` — all standard on Hostinger.
- MySQL (recommended on shared hosting) or SQLite.
- A public **HTTPS** URL (for the Meta webhook). Any normal hosting with SSL.
- A Meta **Business account**, a **WhatsApp Business Account (WABA)**, and for
  each number: its **phone_number_id** and a **permanent access token**.

---

## Meta setup (once)

1. In **Meta Business Suite → Settings → WhatsApp Accounts**, make sure your
   WABA exists and you're an admin. Complete **Business Verification** for
   higher messaging limits.
2. In **developers.facebook.com → your App → WhatsApp → API Setup**, find each
   number's **`phone_number_id`** and the **WABA id**.
3. Create a **System User** (Business Settings → Users → System Users) with a
   **permanent access token** that has `whatsapp_business_messaging` and
   `whatsapp_business_management` permissions. (Temporary tokens expire in 24h
   — use a permanent one.)
4. Get your App's **App Secret** (App → Settings → Basic) for webhook signature
   validation → put it in `WHATSAPP_APP_SECRET`.
5. **Webhook:** App → WhatsApp → Configuration → Edit:
   - **Callback URL:** `https://YOUR-DOMAIN/webhook/whatsapp`
   - **Verify token:** the value you set in `WHATSAPP_VERIFY_TOKEN` (or a
     number's own token).
   - Subscribe to the **`messages`** field.

Then, in this app (as owner): **Settings → Numbers → Add number**, and paste the
label, `phone_number_id`, WABA id, and permanent token (stored **encrypted**).

---

## Deploy WITHOUT SSH (Hostinger File Manager)

Laravel needs a `vendor/` folder and a `public/` web root — it's not a single
upload like a plain PHP script, but this gets you there with no terminal:

1. **Build the upload bundle** (on any machine with PHP + Composer, or ask the
   developer to run it once):
   ```
   bash build-deploy-zip.sh
   ```
   This produces `wa-platform-deploy-YYYYMMDD-HHMM.zip` with dependencies baked
   in (no composer needed on the server).
2. In **hPanel → File Manager**, upload the zip **above** `public_html` (e.g. to
   a folder `wa-platform/`) and **extract** it there.
3. **Point your domain at `public/`.** Two options:
   - *Preferred:* in hPanel, set the domain's **Document Root** to
     `.../wa-platform/public`.
   - *If you can't change the document root:* move everything in `public/` into
     `public_html/`, and edit `public_html/index.php` so the two `require`
     paths point to `../wa-platform/bootstrap/...` and `../wa-platform/vendor/...`.
4. **Create a MySQL database** (hPanel → Databases), then copy `.env.example`
   to `.env` (File Manager → rename) and fill in:
   ```
   APP_URL=https://your-domain
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_DATABASE=...  DB_USERNAME=...  DB_PASSWORD=...
   WHATSAPP_VERIFY_TOKEN=some-long-random-string
   WHATSAPP_APP_SECRET=your-meta-app-secret
   ```
5. **Generate the app key:** easiest is to paste a key into `.env` as
   `APP_KEY=base64:...`. If you can run `php artisan key:generate` once, do;
   otherwise ask the developer for a generated key line.
6. **Run the installer:** visit `https://your-domain/install`. It runs the
   database migrations and creates your **owner** account. It locks itself
   afterwards.
7. **Media storage:** the installer tries to create the `public/storage` link.
   If your host blocks symlinks, in File Manager copy `storage/app/public` into
   `public/storage` (or create the symlink in hPanel).

Log in at `/login`, then **Settings → Numbers** to add your first number.

### Deploy WITH SSH (simpler if available)

```
composer install --no-dev --optimize-autoloader
cp .env.example .env   # then edit DB + WhatsApp values
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force      # loads default flag rules (optional)
php artisan storage:link
```
Point the web root at `public/`.

---

## Background processing & notifications

- **No worker required.** Inbound webhooks are processed inline and agent
  replies are sent synchronously, so the platform works on plain shared hosting
  with **no cron and no daemon**.
- **Live updates** use lightweight polling (every 5s) — no WebSocket server
  needed. For instant updates at larger scale, add Laravel Reverb or Pusher
  later (optional).
- **Push notifications:** the service worker (`public/sw.js`) is push-ready. To
  send real pushes you'd add a Web Push (VAPID) subscription flow — a sensible
  phase-2 upgrade. Until then, agents get in-app unread badges.

---

## Roles

| Role | Can do |
|------|--------|
| **Owner** | Everything: numbers, users, rules, quick replies, all monitoring, inbox. |
| **Supervisor** | Monitor all conversations, agent metrics, flags, audit, export, inbox. |
| **Agent** | Inbox for **assigned numbers only**. No settings, no cross-agent view. |

Create users under **Settings → Users**. Assign agents to numbers there or on
the number's own page.

---

## Security & privacy

- Access tokens and per-number verify tokens are **encrypted at rest**.
- Inbound webhooks are validated with **`X-Hub-Signature-256`** when
  `WHATSAPP_APP_SECRET` is set (strongly recommended in production).
- Every agent/supervisor action is written to the **audit log**.
- Tell agents their work conversations are logged (standard for any company
  inbox) and show customers your business privacy notice.

---

## Tech notes

- Laravel 13, session-based auth, Blade + Tailwind (CDN, **no build step**) so
  it deploys without `npm`.
- Agent inbox is a single installable PWA page talking to a JSON API.
- Tests: `php artisan test` (covers webhook verify, ingestion, idempotency,
  agent reply + auto-flagging, and access control).

---

## Project map

```
app/
  Http/Controllers/
    Api/            # JSON endpoints for the PWA
    Supervisor/     # dashboard, agents, conversations, flags, audit
    Settings/       # numbers, users, flag rules, quick replies (owner)
    Webhook/        # Meta webhook (verify + receive)
    Auth/ Install   # login, one-time web installer
  Services/
    WhatsAppCloudService.php    # send text/media/template, download media
    WebhookProcessor.php        # ingest inbound payloads -> conversations
    OutboundMessageService.php  # agent reply -> API + record + flag
    KeywordFlagService.php      # monitoring rules
    AuditLogger.php
  Models/           # User, WhatsappNumber, Contact, Conversation, Message, ...
resources/views/    # auth, inbox (PWA), supervisor/*, settings/*
routes/web.php      # all routes (API under /api, same-origin session auth)
public/             # manifest.webmanifest, sw.js, icons/
build-deploy-zip.sh # builds the no-SSH upload bundle
```
