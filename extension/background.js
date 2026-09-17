// Runs in the background with no visible tab. The actual "check for calls"
// loop lives in offscreen.js (a persistent offscreen document) - see that
// file for why. This script keeps that offscreen document alive, is the
// only place that touches chrome.storage (offscreen documents don't
// reliably support it directly), and opens the caller's Zenoti profile
// whenever offscreen.js reports a call - extensions are allowed to open
// tabs without a click, unlike a regular webpage.

const BASE_URL = 'https://grandflora.laghavi.com/php';
const OFFSCREEN_URL = 'offscreen.html';
const WATCHDOG_ALARM = 'offscreenWatchdog';

async function ensureOffscreenDocument() {
  const has = await chrome.offscreen.hasDocument();
  if (has) return;
  await chrome.offscreen.createDocument({
    url: OFFSCREEN_URL,
    reasons: ['LOCAL_STORAGE'],
    justification: 'Keeps a persistent page alive to poll for incoming calls every few seconds, which a Manifest V3 service worker cannot reliably do on its own.',
  });
}

chrome.runtime.onInstalled.addListener(ensureOffscreenDocument);
chrome.runtime.onStartup.addListener(ensureOffscreenDocument);
ensureOffscreenDocument();

// Not used for call-checking itself (that's offscreen.js, every 3s) - this
// is just a once-a-minute safety net that recreates the offscreen document
// if Chrome ever closes it. Chrome allows alarms this infrequent everywhere.
chrome.alarms.create(WATCHDOG_ALARM, { periodInMinutes: 1 });
chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === WATCHDOG_ALARM) ensureOffscreenDocument();
});

async function getConfig() {
  const stored = await chrome.storage.local.get(['lastSeen', 'agentName']);
  return { agentName: stored.agentName || null, lastSeen: stored.lastSeen || 0 };
}

// The agent name is saved from popup.js straight into chrome.storage - push
// it (and the current lastSeen) to the offscreen document immediately, no
// reload needed.
chrome.storage.onChanged.addListener((changes, area) => {
  if (area !== 'local' || !changes.agentName) return;
  getConfig().then((config) => {
    chrome.runtime.sendMessage({ type: 'config', ...config }).catch(() => {});
  });
});

// chrome.tabs.create() opens inside whichever window currently has focus -
// if that's a small popup-style window (like a CallGear softphone/workspace
// panel, which isn't built to host arbitrary pages as tabs), the result can
// come out broken or redirected. Opening a dedicated normal window instead
// avoids that entirely and always pops clearly into view.
async function openInNormalWindow(url) {
  const windows = await chrome.windows.getAll({ windowTypes: ['normal'] });
  const target = windows.find((w) => w.focused) || windows[0];

  if (target) {
    const tab = await chrome.tabs.create({ url, windowId: target.id });
    chrome.windows.update(target.id, { focused: true });
    return tab;
  }
  return chrome.windows.create({ url, type: 'normal', focused: true });
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message?.type === 'getConfig') {
    getConfig().then(sendResponse);
    return true; // keep the message channel open for the async response
  }

  if (message?.type === 'checkedOk') {
    chrome.storage.local.set({ lastChecked: Date.now(), lastError: null });
    return;
  }

  if (message?.type === 'checkedError') {
    chrome.storage.local.set({ lastError: message.error, lastChecked: Date.now() });
    return;
  }

  if (message?.type === 'callEvent') {
    const event = message.event;
    chrome.storage.local.set({ lastSeen: event.receivedAt, lastEvent: event });

    const guest = event?.guest;
    if (!guest) return;

    const url = guest.profileUrl || `${BASE_URL}/guest.php?id=${encodeURIComponent(guest.id)}`;
    // Route through our own redirect.php instead of opening the Zenoti
    // URL directly - a tab created from nothing (no originating page)
    // has no "referrer", and Zenoti's own app bounces those to its
    // dashboard. Bouncing through our own page first, then navigating
    // onward via a real page redirect, gives it one - the same reason
    // clicking a link on index.html works but a raw pasted URL doesn't.
    const openUrl = `${BASE_URL}/redirect.php?url=${encodeURIComponent(url)}`;
    chrome.storage.local.set({ lastUrl: url });
    openInNormalWindow(openUrl);
  }
});
