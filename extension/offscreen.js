// The actual "check for calls every few seconds" loop lives here instead of
// in background.js, because Manifest V3 background service workers are
// frozen after ~30 seconds of inactivity, and Chrome also clamps
// chrome.alarms to a minimum of once per minute - both make a plain
// "poll every 3 seconds" timer unreliable in a service worker. An offscreen
// document is a normal page Chrome keeps alive in the background, so a
// simple setInterval here behaves exactly like an open browser tab would.

const BASE_URL = 'https://grandflora.laghavi.com/php';
const POLL_INTERVAL_MS = 3000;

async function checkForCalls() {
  const stored = await chrome.storage.local.get(['lastSeen', 'agentName']);
  const lastSeen = stored.lastSeen || 0;

  if (!stored.agentName) return;

  try {
    const resp = await fetch(`${BASE_URL}/poll.php?since=${lastSeen}&agent=${encodeURIComponent(stored.agentName)}`);
    const data = await resp.json();
    await chrome.storage.local.set({ lastChecked: Date.now(), lastError: null });

    if (data.event) {
      await chrome.storage.local.set({ lastSeen: data.event.receivedAt, lastEvent: data.event });
      chrome.runtime.sendMessage({ type: 'callEvent', event: data.event });
    }
  } catch (e) {
    await chrome.storage.local.set({ lastError: String(e), lastChecked: Date.now() });
  }
}

setInterval(checkForCalls, POLL_INTERVAL_MS);
checkForCalls();
