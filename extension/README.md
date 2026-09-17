# Call Screen-Pop — Browser Extension

Does the same thing as the `php/` dashboard, but runs invisibly in the
background — no tab to keep open, no click needed, ever. The moment a call
comes in, the caller's Zenoti profile opens automatically.

## Why this exists

A regular webpage can't open a new tab with zero user interaction — that's
a browser security rule, not something any code can work around. A browser
**extension** the user has installed is allowed to, which is what makes
true zero-click automation possible.

## How it works

Reuses the same backend as the dashboard — `webhook.php` still receives
CallGear's call event and looks up the caller in Zenoti; nothing there
changes. This extension replaces the dashboard tab: instead of a person
watching a webpage, a background script checks `poll.php` every few
seconds and opens the profile automatically the instant it finds one.

## Install (one-time, per agent's computer)

This isn't published to the Chrome Web Store — it's installed directly,
the same way developers test their own extensions:

1. Download this `extension` folder onto the agent's computer (e.g. from
   this repo's ZIP download, then extract just the `extension` folder).
2. Open Chrome, go to `chrome://extensions`.
3. Turn on **Developer mode** (top-right toggle).
4. Click **Load unpacked**, and select the `extension` folder.
5. Done — no further setup. Chrome may show a small puzzle-piece icon in
   the toolbar; clicking it shows whether it's connected and the last call
   it saw, useful for confirming it's working.

Chrome will label it a "Developer mode extension" — this is expected and
safe for an internal tool like this; it's not published because Zenoti/
CallGear integrations like this are specific to one business, not a
general public tool.

## Troubleshooting

- Click the extension's icon (toolbar puzzle-piece → pin it for easy
  access) — it shows "Active — last checked Ns ago" if working, or
  "Connection trouble" if it can't reach the server. It also shows the
  exact URL it last tried to open (`Opened: ...`) — useful for confirming
  whether it computed the right link.
- If nothing happens on a real call: confirm CallGear's Interactive Call
  Handling step and scenario setup are still correct (see `../php/README.md`
  Step 4) — this extension only reacts to events that `webhook.php` already
  received and stored; it doesn't talk to CallGear directly.
- **If the wrong page opens (e.g. Zenoti's general dashboard instead of the
  specific guest)**: this is almost always a login-session issue, not a bug
  in the link itself. Chrome extensions are scoped to one specific browser
  **profile** — if it was installed ("Load unpacked") in a different Chrome
  profile than the one the agent normally uses to stay logged into Zenoti,
  the new window it opens won't have that login, and Zenoti will bounce an
  unauthenticated deep link to its dashboard/login instead. Fix: remove the
  extension and reload it from the same Chrome profile/window where the
  agent is actually logged into Zenoti day to day.
- The extension always opens the profile in its own dedicated browser
  window (not inside whatever window currently has focus) — this matters
  if agents use a separate small popup-style window (e.g. a CallGear
  softphone/workspace panel) to handle calls, since that kind of window
  isn't built to host arbitrary pages.
- Uninstall any time from `chrome://extensions` — this doesn't affect the
  server side at all.
