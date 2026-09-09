const BASE_URL = (process.env.ZENOTI_API_URL || 'https://api.zenoti.com').replace(/\/+$/, '');

function authHeaders() {
  if (!process.env.ZENOTI_API_KEY) {
    throw new Error('ZENOTI_API_KEY is not set');
  }
  return { Authorization: `apikey ${process.env.ZENOTI_API_KEY}` };
}

function normalizePhone(raw) {
  if (!raw) return null;
  const digits = String(raw).replace(/[^\d+]/g, '');
  return digits || null;
}

function buildProfileUrl(guest) {
  const template = process.env.ZENOTI_GUEST_URL_TEMPLATE;
  if (!template) return null;
  return template
    .replace('{guest_id}', guest.id)
    .replace('{center_id}', guest.centerId || '');
}

async function searchGuestByPhone(phone) {
  const url = new URL(`${BASE_URL}/v1/guests/search`);
  url.searchParams.set('phone', phone);
  if (process.env.ZENOTI_CENTER_ID) {
    url.searchParams.set('center_id', process.env.ZENOTI_CENTER_ID);
  }

  const resp = await fetch(url, { headers: authHeaders() });
  if (!resp.ok) {
    const body = await resp.text().catch(() => '');
    throw new Error(`Zenoti guest search failed (${resp.status}): ${body}`);
  }
  const data = await resp.json();
  const raw = data.guests && data.guests[0];
  if (!raw) return null;

  const guest = {
    id: raw.id,
    firstName: raw.personal_info?.first_name || '',
    lastName: raw.personal_info?.last_name || '',
    phone: raw.personal_info?.mobile_phone?.number || phone,
    email: raw.personal_info?.email || '',
    centerId: raw.center_id,
  };
  guest.profileUrl = buildProfileUrl(guest);
  return guest;
}

async function getGuestDetails(guestId) {
  const url = new URL(`${BASE_URL}/v1/guests/${encodeURIComponent(guestId)}`);
  const resp = await fetch(url, { headers: authHeaders() });
  if (!resp.ok) {
    const body = await resp.text().catch(() => '');
    throw new Error(`Zenoti get guest failed (${resp.status}): ${body}`);
  }
  const data = await resp.json();
  const p = data.personal_info || {};
  return {
    id: data.id,
    firstName: p.first_name || '',
    lastName: p.last_name || '',
    phone: p.mobile_phone?.number || '',
    email: p.email || '',
    centerId: data.center_id,
  };
}

module.exports = { normalizePhone, searchGuestByPhone, getGuestDetails };
