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
        chrome.tabs.create({ url });
      }
    }
  } catch (e) {
    await chrome.storage.local.set({ lastError: String(e), lastChecked: Date.now() });
  }
}
