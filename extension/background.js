// Runs in the background with no visible tab. Checks the same poll.php
// endpoint the web dashboard used, and opens the call's Zenoti profile
// automatically the instant one is found — extensions are allowed to open
// tabs without a click, unlike a regular webpage.

const BASE_URL = 'https://grandflora.laghavi.com/php';
const ALARM_NAME = 'pollForCalls';

// ~every 3 seconds. Chrome only allows sub-minute alarm periods for
// extensions loaded unpacked (developer mode) - if this extension is ever
// packaged/published differently, Chrome will clamp this to once per
// minute instead, which still works, just slower to notice a call.
const POLL_PERIOD_MINUTES = 0.05;

function setupAlarm() {
  chrome.alarms.create(ALARM_NAME, { periodInMinutes: POLL_PERIOD_MINUTES });
}

chrome.runtime.onInstalled.addListener(setupAlarm);
chrome.runtime.onStartup.addListener(setupAlarm);
setupAlarm();

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === ALARM_NAME) checkForCalls();
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

async function checkForCalls() {
  const stored = await chrome.storage.local.get(['lastSeen']);
  const lastSeen = stored.lastSeen || 0;

  try {
    const resp = await fetch(`${BASE_URL}/poll.php?since=${lastSeen}`);
    const data = await resp.json();
    await chrome.storage.local.set({ lastChecked: Date.now(), lastError: null });

    if (data.event) {
      await chrome.storage.local.set({ lastSeen: data.event.receivedAt, lastEvent: data.event });
      const guest = data.event.guest;
      if (guest) {
        const url = guest.profileUrl || `${BASE_URL}/guest.php?id=${encodeURIComponent(guest.id)}`;
        await chrome.storage.local.set({ lastUrl: url });
        openInNormalWindow(url);
      }
    }
  } catch (e) {
    await chrome.storage.local.set({ lastError: String(e), lastChecked: Date.now() });
  }
}
