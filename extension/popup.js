const statusEl = document.getElementById('status');
const lastCallEl = document.getElementById('last-call');
const lastUrlEl = document.getElementById('last-url');
const agentInput = document.getElementById('agent-input');
const agentSave = document.getElementById('agent-save');
const busyToggle = document.getElementById('busy-toggle');
const busyHint = document.getElementById('busy-hint');

function renderBusyToggle(isBusy) {
  if (isBusy) {
    busyToggle.textContent = 'On a call — click when done';
    busyToggle.className = 'busy';
    busyHint.textContent = 'Popups are paused right now.';
  } else {
    busyToggle.textContent = 'Mark: On a call (pause popups)';
    busyToggle.className = 'available';
    busyHint.textContent = 'Click this before making an outgoing call.';
  }
}

busyToggle.onclick = () => {
  chrome.storage.local.get(['busy'], (data) => {
    const next = !data.busy;
    chrome.storage.local.set({ busy: next }, () => renderBusyToggle(next));
  });
};

chrome.storage.local.get(['lastChecked', 'lastError', 'lastEvent', 'lastUrl', 'agentName', 'busy'], (data) => {
  renderBusyToggle(!!data.busy);
  if (!data.agentName) {
    statusEl.textContent = 'Not set up yet — enter your agent name below';
    statusEl.className = 'warn';
  } else if (data.lastError) {
    statusEl.textContent = 'Connection trouble';
    statusEl.className = 'err';
  } else if (data.lastChecked) {
    const secondsAgo = Math.round((Date.now() - data.lastChecked) / 1000);
    statusEl.textContent = `Active as "${data.agentName}" — last checked ${secondsAgo}s ago`;
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

  if (data.agentName) {
    agentInput.value = data.agentName;
  }
});

agentSave.onclick = () => {
  const name = agentInput.value.trim().toLowerCase();
  if (!name) return;
  chrome.storage.local.set({ agentName: name }, () => {
    statusEl.textContent = `Saved. Active as "${name}".`;
    statusEl.className = 'ok';
  });
};
