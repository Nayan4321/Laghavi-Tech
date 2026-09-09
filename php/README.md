# CallGear → Zenoti Screen-Pop (PHP version)

This does exactly the same thing as the Node version one level up, but as
plain PHP files — no Node.js hosting, no npm, no terminal. This works on
any Hostinger shared hosting plan with PHP (which is all of them).

## How it works

```
Incoming call on CallGear
        │
        ▼
CallGear calls webhook.php on this app
        │
        ▼
webhook.php asks Zenoti: "who has this phone number?" (Search Guest API)
        │
        ▼
webhook.php saves the answer to a small file, one per agent
        │
        ▼
Each agent's open dashboard.html tab checks poll.php every ~2.5 seconds
        │
        ▼
New answer found → banner + desktop notification → agent clicks →
client profile opens in a new tab
```

This checks-in-every-few-seconds approach (instead of a permanently open
connection) is deliberate — it's what reliably works on ordinary shared
hosting, where long-held connections often get cut off by the server.

## Step 1 — Upload the files

1. In hPanel, open **File Manager** and go into `public_html` (or a
   subfolder if you want this at `yourdomain.com/screenpop/` instead of the
   root — either is fine, just adjust URLs below accordingly).
2. Create a folder there, e.g. `screenpop`.
3. Upload every file from this `php/` folder into it — `webhook.php`,
   `poll.php`, `guest.php`, `zenoti.php`, `store.php`,
   `config.example.php`, `dashboard.html`, and the `data/` folder (with its
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

Visit `https://yourdomain.com/screenpop/dashboard.html` (adjust the path to
match where you uploaded it). You should see the "Incoming call screen-pop"
page.

### The URLs you now have, ready to send out

- **Send to the Zenoti team** (for the "URI" field when they create the app):
  `https://yourdomain.com/screenpop`
- **Keep for yourself**, to use once you configure CallGear:
  `https://yourdomain.com/screenpop/webhook.php?token=YOUR_WEBHOOK_TOKEN`
- **Send to each agent**, to open once on their own computer:
  `https://yourdomain.com/screenpop/dashboard.html`

## Step 3 — After the Zenoti app-creation call

Once you have the API key, edit `config.php` again in File Manager and set:
- `zenoti_api_key` → the generated API key.
- `zenoti_api_url` → your Zenoti region's API host (US: `https://api.zenoti.com`).
- `zenoti_center_id` → leave blank unless told otherwise.

No restart needed — PHP picks up the change on the next request.

## Step 4 — Point CallGear at your webhook

Same as the Node version's Step 4: find CallGear's notification/scenario
feature for triggering an HTTP request on an incoming call, and point it at
the webhook URL above. See the top-level README for details on the field
names this app understands.

## Step 5 — Agents open their dashboard

Each agent opens `https://yourdomain.com/screenpop/dashboard.html` once,
types in their CallGear agent/extension ID, clicks Save, and allows the
notification permission prompt.

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
- `store.php` — tiny per-agent file storage (no database needed).
- `dashboard.html` — the page each agent keeps open.
- `config.example.php` — copy to `config.php` and fill in your values.
- `data/` — where the latest call per agent is stored; protected by
  `.htaccess` so it can't be browsed directly.
