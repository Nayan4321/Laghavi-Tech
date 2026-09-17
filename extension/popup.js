const statusEl = document.getElementById('status');
const lastCallEl = document.getElementById('last-call');
const lastUrlEl = document.getElementById('last-url');

chrome.storage.local.get(['lastChecked', 'lastError', 'lastEvent', 'lastUrl'], (data) => {
  if (data.lastError) {
    statusEl.textContent = 'Connection trouble';
    statusEl.className = 'err';
  } else if (data.lastChecked) {
    const secondsAgo = Math.round((Date.now() - data.lastChecked) / 1000);
    statusEl.textContent = `Active — last checked ${secondsAgo}s ago`;
    statusEl.className = 'ok';
  } else {
    statusEl.textContent = 'Starting up...';
  }

  if (data.lastEvent) {
    const guest = data.lastEvent.guest;
    const name = guest ? [guest.firstName, guest.lastName].filter(Boolean).join(' ') : null;
    lastCallEl.textContent = `Last call: ${data.lastEvent.phone}` + (name ? ` (${name})` : ' (no match)');
  }

  if (data.lastUrl) {
    lastUrlEl.textContent = `Opened: ${data.lastUrl}`;
  }
});
