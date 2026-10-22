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

## Agreed additions (hub reply, Laghavi Ads Hub 2026.10.22.1)

- `status`: `new, contacted, qualified, won, lost, spam` (`contacted` sent as is). `spam` is stored, never counted.
- `ids`: `{campaign_id, adset_id, ad_id}`; `adgroup_id` accepted as alias of `adset_id`. Max 40 chars each. `ids.ad_id` → exact ad match; else `utm.content` (ad name).
- `channel`: `paid, organic_search, organic_social, ai, email, referral, direct, other`.
- `network`: `google_ads, meta_ads, tiktok_ads, microsoft_ads, linkedin_ads, snapchat_ads, pinterest_ads, reddit_ads, x_ads, amazon_ads, quora_ads, yandex_ads, taboola, outbrain, criteo, apple_search_ads, spotify_ads, youtube_ads, other_ads`. Unknown → stored empty, lead still accepted.
- `last_touch`: `{utm, click_ids, ids, landing_url, referrer, channel, network}`. Top-level fields = first touch.
- Click IDs accepted: `gclid, gbraid, wbraid, dclid, fbclid, msclkid, ttclid, li_fat_id, twclid, sccid, epik, rdt_cid, qclid, yclid, tblci` (case-insensitive).
- `currency` 3 letters, `value` number ≥ 0, `device` mobile|desktop|tablet.
- Hub keeps the date part of `created_at` (site local date), strips the query string from `page_url`, keeps only utm_*/click IDs/ad params in `landing_url`.
- `form_stats`: `{"action":"form_stats","site","plugin_version","date":"YYYY-MM-DD","forms":[{"form","views","starts","submits","abandons","top_abandon_field"}]}`, max 200 forms, same site+date+form replaces → send full-day totals. Response `{"ok":true,"forms":N}`.
- hello → `{"ok":true,"client":"…","accepts":["leads","form_stats"],"api":1}`.
- Errors carry `code`: 401 `invalid_key` and 403 `no_access` → stop retrying, show the message, ask for a new key / plan; 422 `bad_request` with `index` → whole batch rejected: drop that lead and resend the rest; 429 (60 req/min per key) and 5xx `server_error` → retry later.
- Keys: `lgpl_` + 40 chars, one per website, created on Profit & tracking → Real leads (that page also shows the hub address). Plugin settings: Hub address + Lead key.

## Mapping in Aarye Pro

- `id` = `{form_plugin}-{form_id}-{entry_id}`; Aarye network keys map 1:1 except `yandex_direct`→`yandex_ads`, `apple_ads`→`apple_search_ads`, any other paid network → `other_ads`.
- Status changes in WordPress re-send the lead (same `id` → update).
