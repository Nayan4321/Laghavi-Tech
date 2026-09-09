# CallGear → Zenoti Screen-Pop

When a call comes in on CallGear, this app looks up the caller's phone number in
Zenoti and pops the matching client's profile open on the agent's screen — no
manual searching.

You do not need to know how to code to deploy or run this. Follow the steps
below in order.

## How it works

```
Incoming call on CallGear
        │
        ▼
CallGear sends a webhook to this app (POST /webhooks/callgear)
        │
        ▼
This app asks Zenoti: "who has this phone number?" (Search Guest API)
        │
        ▼
This app pushes the answer to the agent's open dashboard tab (live, via SSE)
        │
        ▼
Agent sees a banner + gets a desktop notification → clicks → client profile
opens in a new tab
```

Each agent keeps one browser tab open at `/dashboard.html` on their own
computer, and types in their CallGear agent/extension ID once (saved in that
browser). Calls for other agents don't show up on their screen.

## Why deploy this *before* your Zenoti app-creation call

Creating the Zenoti API app (the steps you already have — Configuration →
Apps → Add) requires a **URI** field. That should be the web address of this
app once it's live. Since the Zenoti admin won't hand you ongoing access, get
this deployed first so you walk into that Zoom call with the exact value
ready, instead of holding things up mid-call.

## Step 1 — Deploy to Hostinger

Hostinger's Node.js app hosting works with this project as-is.

1. In **hPanel**, go to **Advanced → Node.js**.
2. Click **Create Application**.
   - **Node.js version**: 18 or newer.
   - **Application root**: any folder, e.g. `callgear-zenoti`.
   - **Application URL**: pick the domain/subdomain you want this to live on,
     e.g. `screenpop.yourbusiness.com`.
   - **Application startup file**: `server.js`.
3. Get the code onto the server — two options:
   - **Git (recommended)**: if this repository is pushed to GitHub, use
     Hostinger's Git deployment option under the Node.js app settings and
     point it at this repo/branch.
   - **Manual upload**: zip this project folder (excluding `node_modules`)
     and upload it via **File Manager**, then extract it into the
     application root.
4. In the Node.js app screen, open **NPM install** (or run `npm install` from
   the built-in terminal) to install dependencies.
5. Add environment variables (there's a dedicated section for this in the
   Node.js app screen) — copy every key from `.env.example` and fill in real
   values (Zenoti values come later, in Step 3):
   - `WEBHOOK_TOKEN` — make up a long random string now, e.g. by mashing the
     keyboard. Save it somewhere; you'll need it again in Step 4.
   - `PORT` — leave as Hostinger sets it, or `3000`.
   - Leave `ZENOTI_API_KEY` blank for now.
6. Start (or restart) the application. Hostinger will show you the live
   **Application URL** — this is your app's address, e.g.
   `https://screenpop.yourbusiness.com`.

Once this loads `https://screenpop.yourbusiness.com/dashboard.html` in a
browser and shows the "Incoming call screen-pop" page, the deployment is
done.

## Step 2 — What to bring to the Zenoti app-creation call

Give these exact values to whoever creates the app in Zenoti
(Configuration → Apps → Add), following the steps you already have:

| Field | What to enter |
|---|---|
| Name | e.g. `Call Screen-Pop` |
| URI | Your Hostinger app URL from Step 1, e.g. `https://screenpop.yourbusiness.com` |
| Description | "Looks up incoming callers and shows their client profile to agents" |
| Login User Type | **Employee** (this is an internal tool, not guest-facing) |
| Source App | **Client App** (closest fit — this app doesn't book appointments, so this field won't functionally matter) |
| Scopes (step 6 in your Zenoti steps) | Under the **Guest** API group, enable read/search permissions for guests (e.g. "Search guests", "Retrieve guest details"). Nothing else is needed. |
| Auth method | **API keys (APIKEY GROUPS)** — not JWT/access tokens. Simpler for this use case. |

At the end of that process, Zenoti generates an **Application ID**, a
**Secret**, and then an **API Key**. You only need the **API Key** — copy it
somewhere safe.

## Step 3 — Add the Zenoti API key to your app

Back in Hostinger's Node.js environment variables:

- `ZENOTI_API_KEY` → paste the API key from Step 2.
- `ZENOTI_API_URL` → the API host for your Zenoti region:
  - US: `https://api.zenoti.com`
  - UAE: `https://api.zenoti.ae`
  - Other region: ask your Zenoti rep.
- `ZENOTI_CENTER_ID` → leave blank unless you're told guest search needs to be
  limited to one location.

Restart the app after saving.

## Step 4 — Point CallGear at your webhook

In your CallGear account, look for the feature to trigger an action on a
call event — this is usually under something like **Integrations →
Notifications** or a **scenario/automation** builder where you set a
condition ("new incoming call") and an action ("send HTTP request to a URL").
Configure it to call:

```
https://screenpop.yourbusiness.com/webhooks/callgear?token=YOUR_WEBHOOK_TOKEN
```

(using the `WEBHOOK_TOKEN` value you set in Step 1). This app accepts either
GET (with the caller's number as a URL parameter, if CallGear supports
placeholders like `{phone}`) or POST (JSON or form body).

If CallGear's exact field names differ from what this app expects, that's
fine — check the payload/placeholder fields your CallGear scenario editor
offers, and tell me their names; the webhook handler in `server.js`
(`app.all('/webhooks/callgear', ...)`) already checks several common field
names (`phone`, `caller_phone_number`, `contact_phone_number`,
`communication_number`, `from`, `caller_number`) but can easily be extended
if CallGear uses something else. The agent/extension ID should be sent too,
under one of `agent_id`, `employee_id`, `agent`, or `employee_ext` — this is
what routes the popup to the right agent's screen.

## Step 5 — Each agent opens their dashboard

Every agent opens, once, on their own computer:

```
https://screenpop.yourbusiness.com/dashboard.html
```

They type their CallGear agent/extension ID into the box and click **Save**.
The browser remembers it after that. Allow the notification permission
prompt so they get a desktop pop-up as well as the on-page banner.

## Step 6 — Test it

Have someone call your CallGear number from a phone number that exists as a
client in Zenoti. Within a second or two, the agent's dashboard tab should
show a banner with the client's name and an **Open client profile** button
(and a desktop notification, if permitted).

## About the profile link

Right now, clicking the button opens this app's own simple profile page
(name, phone, email, guest ID) — it's generated directly from the Zenoti API,
so it works immediately with no extra setup.

If you'd rather it open the *actual* Zenoti web app page for that client:
open any client's profile in Zenoti yourself, copy the URL from the address
bar, and send it to me. It'll look something like
`https://yourbusiness.zenoti.com/.../guest/<some id>`. Once we know the exact
pattern, set `ZENOTI_GUEST_URL_TEMPLATE` in the environment variables (see
the comment in `.env.example`) and the button will deep-link straight into
Zenoti instead.

## Files in this project

- `server.js` — the web server: receives the CallGear webhook, pushes live
  updates to agents, serves the fallback guest page.
- `lib/zenoti.js` — talks to the Zenoti API (guest search, guest details).
- `public/dashboard.html` — the page each agent keeps open.
- `.env.example` — template for all configuration values; copy to `.env`
  locally, or enter the same keys into Hostinger's environment variables
  panel in production.
