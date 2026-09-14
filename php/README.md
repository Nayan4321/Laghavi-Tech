# CallGear → Zenoti Screen-Pop (PHP version)

This does exactly the same thing as the Node version one level up, but as
plain PHP files — no Node.js hosting, no npm, no terminal. This works on
any Hostinger shared hosting plan with PHP (which is all of them).

## How it works

```
Incoming call on CallGear
        │
        ▼
CallGear's Interactive Call Processing calls webhook.php, and waits for
a reply before continuing to route the call
        │
        ▼
webhook.php asks Zenoti: "who has this phone number?" (Search Guest API)
        │
        ▼
webhook.php immediately replies {"returned_code": 1} so the call keeps
routing normally, and separately saves the answer for the dashboards
        │
        ▼
Every open index.html tab checks poll.php every ~2.5 seconds
        │
        ▼
New answer found → banner + desktop notification on every agent's screen
→ whoever picks up the call clicks → client profile opens in a new tab
```

This checks-in-every-few-seconds approach (instead of a permanently open
connection) is deliberate — it's what reliably works on ordinary shared
hosting, where long-held connections often get cut off by the server.

**Important:** CallGear doesn't tell us which agent will actually take the
call — so this pops up on *every* agent's open dashboard tab, not just one.
Whoever answers sees it. There's no "enter your agent ID" step anymore.

**Also important:** this webhook is part of CallGear's live call routing —
it must always reply within a couple of seconds with valid instructions, or
the call itself could be affected. See Step 4 below; this is handled
carefully in the code (always replies successfully, Zenoti lookups time out
after 3 seconds), but the CallGear scenario side still needs to be
configured correctly by whoever sets that up.

## Step 1 — Upload the files

1. In hPanel, open **File Manager** and go into `public_html` (or a
   subfolder if you want this at `yourdomain.com/screenpop/` instead of the
   root — either is fine, just adjust URLs below accordingly).
2. Create a folder there, e.g. `screenpop`.
3. Upload every file from this `php/` folder into it — `webhook.php`,
   `poll.php`, `guest.php`, `zenoti.php`, `store.php`,
   `config.example.php`, `index.html`, and the `data/` folder (with its
   `.htaccess` inside).
   - Easiest way to get them there: on GitHub, open this repo, switch to
     branch `claude/zenoti-api-key-setup-q62p11`, click **Code → Download
     ZIP**, unzip it on your computer, then drag just the contents of the
     `php` folder into File Manager's upload dialog.
4. In File Manager, right-click `config.example.php` → **Rename** it to
   `config.php`.
5. Edit `config.php` (File Manager has an built-in editor — right-click →
   Edit) and leave it as-is for now except:
   - `webhook_token` → replace with a long random string you make up now.
     Save it — you'll reuse it later when configuring CallGear.
   - Leave `zenoti_api_key` as the placeholder for now.
6. Make sure the `data` folder is writable: right-click it → **Permissions**
   → set to `755` (or `775` if `755` gives a "failed to write" error later).

## Step 2 — Confirm it's live

Visit `https://yourdomain.com/screenpop/` (adjust the path to match where
you uploaded it). Since the dashboard is named `index.html`, that bare
folder address loads it directly — no filename needed. You should see the
"Incoming call screen-pop" page.

### The URLs you now have, ready to send out

- **Send to the Zenoti team** (for the "URI" field when they create the app):
  `https://yourdomain.com/screenpop`
- **Keep for yourself**, to use once you configure CallGear:
  `https://yourdomain.com/screenpop/webhook.php?token=YOUR_WEBHOOK_TOKEN`
- **Send to each agent**, to open once on their own computer (and ideally
  add as a browser startup page — see Step 5):
  `https://yourdomain.com/screenpop/`

## Step 3 — After the Zenoti app-creation call

Once you have the API key, edit `config.php` again in File Manager and set:
- `zenoti_api_key` → the generated API key.
- `zenoti_api_url` → your Zenoti region's API host (US: `https://api.zenoti.com`).
- `zenoti_center_id` → leave blank unless told otherwise.

No restart needed — PHP picks up the change on the next request.

## Step 4 — Point CallGear at your webhook

This uses CallGear's **Interactive Call Processing** feature (in the Virtual
PBX scenario builder), not a simple notification webhook — it actively waits
for a reply before continuing the call. Whoever manages your CallGear
scenario needs to:

1. In the scenario where incoming calls are handled, add an **Interactive
   call handling** step at the point where you want the lookup to happen.
2. Set:
   - **Method**: GET or POST (either works — this app accepts both)
   - **Authorization URL**: `https://yourdomain.com/screenpop/webhook.php?token=YOUR_WEBHOOK_TOKEN`
3. Under **Return code 1** (or whichever number is already set in
   `config.php`'s `callgear_returned_code`), set its linked operation to
   whatever the call should normally do next — e.g. the same forwarding/
   distribution step that handles it today. This makes the webhook a
   "tap" that never changes how calls are actually routed.
4. Set up CallGear's fallback for "no answer from outside system" (they
   recommend this) to also continue to that same normal step — so even if
   this app is ever slow or down, calls still go through unaffected.

**Test this on a non-critical scenario/number first**, not your main call
queue, until you've confirmed a real call still routes normally end to end.

## Step 5 — Agents just start work as usual

Add `https://yourdomain.com/screenpop/` as a **browser startup page**, so
it's already open every time an agent opens their browser — nothing to
search for, nothing to type, no daily instruction to give anyone about a URL:

- **Chrome/Edge**: Settings → On startup → "Open a specific page or set of
  pages" → Add the URL above.

From there, the flow is exactly this:
1. Agent opens their browser like any other day. The tab is just already
   there — nothing to search for.
2. First call of the session comes in → the tab shows the incoming call, and
   the **"Enable Auto Popup"** button is sitting right there. One click.
3. From then on, every call that session pops the profile open
   automatically, with no further clicks.

The only instruction anyone needs is: "if you see a call come in on that
tab, click Enable Auto Popup once" — not "go find this URL before you can
start working."

## About the profile link

Same as the Node version: it opens this app's own `guest.php` page (built
straight from Zenoti's API) unless you set `zenoti_guest_url_template` in
`config.php` to the real Zenoti web-app URL pattern.

## Files

- `webhook.php` — receives the CallGear webhook, looks up the caller, saves
  the result.
- `poll.php` — the dashboard asks this "anything new?" every ~2.5s.
- `guest.php` — fallback profile page.
- `zenoti.php` — talks to the Zenoti API.
- `store.php` — tiny file-based storage (no database needed).
- `index.html` — the page every agent keeps open.
- `config.example.php` — copy to `config.php` and fill in your values.
- `data/` — where the latest call is stored; protected by `.htaccess` so it
  can't be browsed directly.
