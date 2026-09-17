// The actual "check for calls every few seconds" loop lives here (see
// background.js for why). Offscreen documents don't reliably support
// chrome.storage directly - reading it here crashed silently - so this
// only uses chrome.runtime messaging, which offscreen documents do fully
// support, and lets background.js (a real extension background context)
// handle all the actual storage reads/writes.

const POLL_INTERVAL_MS = 3000;
const BASE_URL = 'https://grandflora.laghavi.com/php';

let agentName = null;
let lastSeen = 0;

function requestConfig() {
  chrome.runtime.sendMessage({ type: 'getConfig' }, (response) => {
    if (response) {
      agentName = response.agentName || null;
      lastSeen = response.lastSeen || 0;
    }
  });
}

// Picks up a new agent name the moment it's saved in the popup, and the
// current lastSeen after a call is handled, without needing a reload.
chrome.runtime.onMessage.addListener((message) => {
  if (message?.type === 'config') {
    agentName = message.agentName || null;
    if (typeof message.lastSeen === 'number') lastSeen = message.lastSeen;
  }
});

async function checkForCalls() {
  if (!agentName) return;

  try {
    const resp = await fetch(`${BASE_URL}/poll.php?since=${lastSeen}&agent=${encodeURIComponent(agentName)}`);
    const data = await resp.json();
    chrome.runtime.sendMessage({ type: 'checkedOk' });

    if (data.event) {
      lastSeen = data.event.receivedAt;
      chrome.runtime.sendMessage({ type: 'callEvent', event: data.event });
    }
  } catch (e) {
    chrome.runtime.sendMessage({ type: 'checkedError', error: String(e) });
  }
}

requestConfig();
setInterval(checkForCalls, POLL_INTERVAL_MS);
checkForCalls();
