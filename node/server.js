require('dotenv').config();
const express = require('express');
const path = require('path');
const zenoti = require('./lib/zenoti');

const app = express();
app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(express.static(path.join(__dirname, 'public')));

// agentId -> array of open SSE response objects
const agentStreams = new Map();

function broadcastToAgent(agentId, payload) {
  const streams = agentStreams.get(agentId) || [];
  const line = `data: ${JSON.stringify(payload)}\n\n`;
  streams.forEach((res) => res.write(line));
}

app.get('/', (req, res) => res.redirect('/dashboard.html'));

// Agent's browser tab connects here and keeps this connection open.
app.get('/events/:agentId', (req, res) => {
  const { agentId } = req.params;
  res.set({
    'Content-Type': 'text/event-stream',
    'Cache-Control': 'no-cache',
    Connection: 'keep-alive',
  });
  res.flushHeaders();
  res.write(': connected\n\n');

  const list = agentStreams.get(agentId) || [];
  list.push(res);
  agentStreams.set(agentId, list);

  const keepAlive = setInterval(() => res.write(': ping\n\n'), 25000);

  req.on('close', () => {
    clearInterval(keepAlive);
    const remaining = (agentStreams.get(agentId) || []).filter((r) => r !== res);
    agentStreams.set(agentId, remaining);
  });
});

// CallGear calls this on every incoming call (configure as GET or POST).
app.all('/webhooks/callgear', async (req, res) => {
  if (process.env.WEBHOOK_TOKEN) {
    const token = req.query.token || req.body.token;
    if (token !== process.env.WEBHOOK_TOKEN) {
      return res.status(401).json({ error: 'invalid or missing token' });
    }
  }

  const data = { ...req.query, ...req.body };
  const rawPhone =
    data.phone || data.caller_phone_number || data.contact_phone_number ||
    data.communication_number || data.from || data.caller_number;
  const phone = zenoti.normalizePhone(rawPhone);
  const agentId = String(data.agent_id || data.employee_id || data.agent || data.employee_ext || 'default');

  if (!phone) {
    return res.status(400).json({ error: 'no phone number found in webhook payload', received: data });
  }

  try {
    const guest = await zenoti.searchGuestByPhone(phone);
    broadcastToAgent(agentId, { phone, guest, receivedAt: Date.now() });
    res.json({ ok: true, matched: !!guest });
  } catch (err) {
    console.error('callgear webhook error:', err.message);
    res.status(502).json({ error: 'zenoti lookup failed', detail: err.message });
  }
});

// Fallback profile page, used when ZENOTI_GUEST_URL_TEMPLATE isn't set yet.
app.get('/guest/:id', async (req, res) => {
  try {
    const g = await zenoti.getGuestDetails(req.params.id);
    res.send(`<!doctype html>
<html><head><meta charset="utf-8"><title>${g.firstName} ${g.lastName}</title>
<style>body{font-family:system-ui,sans-serif;max-width:480px;margin:48px auto;padding:0 16px}
h1{margin-bottom:4px}dl{display:grid;grid-template-columns:auto 1fr;gap:6px 12px}dt{color:#666}</style>
</head><body>
<h1>${g.firstName} ${g.lastName}</h1>
<dl>
<dt>Phone</dt><dd>${g.phone}</dd>
<dt>Email</dt><dd>${g.email || '—'}</dd>
<dt>Guest ID</dt><dd>${g.id}</dd>
<dt>Center ID</dt><dd>${g.centerId}</dd>
</dl>
</body></html>`);
  } catch (err) {
    res.status(502).send(`Could not load guest details: ${err.message}`);
  }
});

const port = process.env.PORT || 3000;
app.listen(port, () => console.log(`listening on port ${port}`));
