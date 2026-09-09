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
