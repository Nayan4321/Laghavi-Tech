# Laghavi Ads Hub — Lead intake API (v1)

Spec provided by the Laghavi Ads Hub project (2026-10-22). The hub endpoint is built there; this plugin is the sender.

- Hub address: entered by the user (Laghavi's own hub: https://hub.laghavi.site; self-hosted hubs use their own domain). Never hard-coded.
- `POST {hub}/lead-sync`, `Content-Type: application/json`, HTTPS only.
- Header `X-Laghavi-Key: <lead key>` (one key per client profile, created in the hub: Client → Leads → "Create lead key"). Stored with autoload off, never printed in HTML/JS.
- Body: `{"action":"leads","site":home_url(),"plugin_version":"x","leads":[...]}` with 1–100 leads.
- Lead: `id` (required, unique per site, re-sending updates), `created_at` (ISO 8601 with timezone), `status` (new|qualified|won|lost|spam), `value`, `currency`, `form`, `page_url`, `landing_url`, `referrer`, `utm` {source, medium, campaign, content (ad id or name), term} — first-touch, `click_ids` {gclid, gbraid, wbraid, fbclid, ttclid, msclkid, li_fat_id}, `device` (mobile|desktop|tablet).
- Privacy: never send names, emails, phone numbers, IP addresses or message text.
- Responses: 200 `{"ok":true,"added":n,"updated":n,"skipped":n}`; 401 invalid key; 403 plan without Profit & tracking; 422 `{"error":..., "index":n}`; 429 and 5xx retry later.
- Sending: right after the entry is saved, non-blocking; failed leads retried hourly for 7 days, max 100 per request; timeout 15 s.
- Connection test: `{"action":"hello","site":...,"plugin_version":...}` → `{"ok":true,"client":"<client name>"}`.
