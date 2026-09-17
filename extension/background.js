// Runs in the background with no visible tab. The actual "check for calls"
// loop lives in offscreen.js (a persistent offscreen document), not here -
// see that file for why. This script's job is just to keep that offscreen
// document alive and open the caller's Zenoti profile whenever it reports a
// call - extensions are allowed to open tabs without a click, unlike a
// regular webpage.

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

chrome.runtime.onMessage.addListener((message) => {
  if (message?.type !== 'callEvent') return;
  const guest = message.event?.guest;
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
});
