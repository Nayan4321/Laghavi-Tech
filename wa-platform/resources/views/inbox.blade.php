<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Inbox · WhatsApp Team Platform</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html, body { height: 100%; overscroll-behavior: none; }
        .chat-bg { background-color:#efeae2; background-image:radial-gradient(#d9d2c7 0.5px, transparent 0.5px); background-size:12px 12px; }
        .bubble-in { background:#fff; }
        .bubble-out { background:#d9fdd3; }
    </style>
</head>
<body class="h-full bg-slate-100 text-slate-800">
<div id="app" class="h-full flex flex-col">
    <!-- Top bar -->
    <header class="bg-slate-900 text-white px-3 h-14 flex items-center justify-between shrink-0">
        <div class="flex items-center gap-2">
            <button id="backBtn" class="md:hidden hidden text-xl px-1">←</button>
            <span class="font-semibold">💬 Inbox</span>
            <span id="badge" class="ml-1 text-xs bg-emerald-500 rounded-full px-2 py-0.5 hidden">0</span>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <span id="meName" class="text-white/70"></span>
            <a id="supLink" href="/supervisor" class="hidden px-2 py-1 rounded bg-white/10">Dashboard</a>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="px-2 py-1 rounded hover:bg-white/10">Logout</button></form>
        </div>
    </header>

    <div class="flex-1 min-h-0 flex">
        <!-- Conversation list -->
        <aside id="listPane" class="w-full md:w-96 border-r border-slate-200 bg-white flex flex-col min-h-0">
            <div class="p-2 flex gap-2 border-b">
                <select id="numberFilter" class="text-sm border rounded-lg px-2 py-1.5 flex-1">
                    <option value="">All numbers</option>
                </select>
                <select id="statusFilter" class="text-sm border rounded-lg px-2 py-1.5">
                    <option value="">All</option>
                    <option value="open" selected>Open</option>
                    <option value="resolved">Resolved</option>
                </select>
            </div>
            <div class="p-2 border-b"><input id="search" placeholder="Search name or number"
                class="w-full text-sm border rounded-lg px-3 py-1.5"></div>
            <ul id="convList" class="flex-1 overflow-y-auto divide-y"></ul>
        </aside>

        <!-- Chat pane -->
        <section id="chatPane" class="hidden md:flex flex-1 flex-col min-h-0">
            <div id="emptyState" class="flex-1 flex items-center justify-center text-slate-400 text-sm">
                Select a conversation
            </div>

            <div id="chatView" class="hidden flex-1 flex-col min-h-0">
                <div class="bg-slate-50 border-b px-4 h-14 flex items-center justify-between shrink-0">
                    <div>
                        <div id="chatName" class="font-semibold text-sm"></div>
                        <div id="chatMeta" class="text-xs text-slate-500"></div>
                    </div>
                    <div class="flex gap-2">
                        <button id="resolveBtn" class="text-xs px-2 py-1 rounded border">Resolve</button>
                    </div>
                </div>

                <div id="messages" class="chat-bg flex-1 overflow-y-auto p-4 space-y-2"></div>

                <div id="windowClosed" class="hidden bg-amber-50 text-amber-800 text-xs px-4 py-2 border-t">
                    The 24-hour reply window is closed. You can only re-open this chat with an approved template message.
                </div>

                <div class="border-t bg-slate-50 p-2 shrink-0">
                    <div id="cannedBar" class="flex gap-1 overflow-x-auto pb-1 mb-1"></div>
                    <form id="sendForm" class="flex gap-2 items-end">
                        <textarea id="msgInput" rows="1" placeholder="Type a message"
                            class="flex-1 resize-none border rounded-2xl px-4 py-2 text-sm max-h-32"></textarea>
                        <button id="sendBtn" class="w-10 h-10 rounded-full bg-emerald-500 hover:bg-emerald-600 text-white shrink-0">➤</button>
                    </form>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;
let me = null, current = null, pollTimer = null;

const api = async (url, opts = {}) => {
    const res = await fetch(url, {
        headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest',
                  ...(opts.body ? {'Content-Type': 'application/json'} : {})},
        ...opts,
    });
    if (res.status === 401 || res.status === 419) { location.href = '/login'; return null; }
    return res;
};

const esc = s => (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const timeFmt = iso => iso ? new Date(iso).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '';
const dayFmt = iso => iso ? new Date(iso).toLocaleDateString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'}) : '';
const tick = s => ({queued:'🕗', sent:'✓', delivered:'✓✓', read:'✓✓', failed:'⚠️'}[s] || '');

async function loadMe() {
    const r = await api('/api/me'); if (!r) return;
    me = await r.json();
    document.getElementById('meName').textContent = me.name;
    if (me.is_supervisor) document.getElementById('supLink').classList.remove('hidden');
    const b = document.getElementById('badge');
    if (me.unread_total > 0) { b.textContent = me.unread_total; b.classList.remove('hidden'); }
    else b.classList.add('hidden');
}

async function loadNumbers() {
    const r = await api('/api/numbers'); if (!r) return;
    const nums = await r.json();
    const sel = document.getElementById('numberFilter');
    nums.forEach(n => { const o = document.createElement('option'); o.value = n.id; o.textContent = n.label; sel.appendChild(o); });
}

async function loadCanned() {
    const r = await api('/api/canned-replies'); if (!r) return;
    const items = await r.json();
    const bar = document.getElementById('cannedBar');
    bar.innerHTML = '';
    items.forEach(c => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'text-xs whitespace-nowrap px-2 py-1 rounded-full border bg-white hover:bg-slate-100';
        btn.textContent = c.shortcut || c.title;
        btn.onclick = () => { const i = document.getElementById('msgInput'); i.value = c.body; i.focus(); autoGrow(i); };
        bar.appendChild(btn);
    });
}

async function loadList() {
    const num = document.getElementById('numberFilter').value;
    const status = document.getElementById('statusFilter').value;
    const q = document.getElementById('search').value.trim();
    const params = new URLSearchParams();
    if (num) params.set('number_id', num);
    if (status) params.set('status', status);
    if (q) params.set('q', q);
    const r = await api('/api/conversations?' + params); if (!r) return;
    const data = await r.json();
    const ul = document.getElementById('convList');
    ul.innerHTML = '';
    if (!data.data.length) { ul.innerHTML = '<li class="p-6 text-center text-sm text-slate-400">No conversations</li>'; return; }
    data.data.forEach(c => ul.appendChild(convRow(c)));
}

function convRow(c) {
    const li = document.createElement('li');
    li.className = 'p-3 hover:bg-slate-50 cursor-pointer flex gap-3 items-center' + (current === c.id ? ' bg-emerald-50' : '');
    li.onclick = () => openConversation(c.id);
    const initials = (c.contact_name || '?').slice(0,2).toUpperCase();
    li.innerHTML = `
        <div class="w-10 h-10 rounded-full bg-slate-200 flex items-center justify-center text-sm font-medium shrink-0">${esc(initials)}</div>
        <div class="flex-1 min-w-0">
            <div class="flex justify-between gap-2">
                <span class="font-medium text-sm truncate">${esc(c.contact_name)}</span>
                <span class="text-[11px] text-slate-400 shrink-0">${timeFmt(c.last_message_at)}</span>
            </div>
            <div class="flex justify-between gap-2">
                <span class="text-xs text-slate-500 truncate">${esc(c.number)} ${c.assigned_to ? '· '+esc(c.assigned_to) : ''}</span>
                ${c.unread ? `<span class="text-[11px] bg-emerald-500 text-white rounded-full px-1.5">${c.unread}</span>` : ''}
            </div>
        </div>`;
    return li;
}

async function openConversation(id) {
    current = id;
    document.getElementById('emptyState').classList.add('hidden');
    document.getElementById('chatView').classList.remove('hidden');
    document.getElementById('chatView').classList.add('flex');
    if (window.innerWidth < 768) {
        document.getElementById('listPane').classList.add('hidden');
        document.getElementById('chatPane').classList.remove('hidden');
        document.getElementById('backBtn').classList.remove('hidden');
    }
    await refreshChat();
    loadList();
}

async function refreshChat() {
    if (!current) return;
    const r = await api('/api/conversations/' + current); if (!r) return;
    const d = await r.json();
    const c = d.conversation;
    document.getElementById('chatName').textContent = c.contact_name;
    document.getElementById('chatMeta').textContent = `${c.wa_id} · ${c.number}` + (c.assigned_to ? ` · ${c.assigned_to}` : '');
    document.getElementById('resolveBtn').textContent = c.status === 'resolved' ? 'Re-open' : 'Resolve';
    document.getElementById('resolveBtn').dataset.status = c.status;
    document.getElementById('windowClosed').classList.toggle('hidden', d.window_open);
    document.getElementById('msgInput').disabled = !d.window_open;

    const box = document.getElementById('messages');
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
    box.innerHTML = '';
    d.messages.forEach(m => box.appendChild(bubble(m)));
    if (atBottom) box.scrollTop = box.scrollHeight;
}

function bubble(m) {
    const wrap = document.createElement('div');
    const out = m.direction === 'out';
    wrap.className = 'flex ' + (out ? 'justify-end' : 'justify-start');
    let media = '';
    if (m.media_url) {
        if ((m.type === 'image')) media = `<a href="${m.media_url}" target="_blank"><img src="${m.media_url}" class="rounded-lg max-w-[200px] mb-1"></a>`;
        else media = `<a href="${m.media_url}" target="_blank" class="text-blue-600 underline text-sm block mb-1">📎 ${esc(m.type)}</a>`;
    }
    wrap.innerHTML = `
        <div class="max-w-[75%] rounded-lg px-3 py-2 shadow-sm ${out ? 'bubble-out' : 'bubble-in'}">
            ${out && m.sender ? `<div class="text-[11px] font-medium text-emerald-700 mb-0.5">${esc(m.sender)}</div>` : ''}
            ${media}
            ${m.body ? `<div class="text-sm whitespace-pre-wrap break-words">${esc(m.body)}</div>` : ''}
            <div class="text-[10px] text-slate-400 text-right mt-0.5">${timeFmt(m.sent_at)} ${out ? tick(m.status) : ''}</div>
        </div>`;
    return wrap;
}

async function sendMessage(e) {
    e.preventDefault();
    const input = document.getElementById('msgInput');
    const body = input.value.trim();
    if (!body || !current) return;
    document.getElementById('sendBtn').disabled = true;
    const r = await api('/api/conversations/' + current + '/messages', {method: 'POST', body: JSON.stringify({body})});
    document.getElementById('sendBtn').disabled = false;
    if (!r) return;
    if (r.ok) { input.value = ''; autoGrow(input); await refreshChat(); }
    else { const err = await r.json().catch(()=>({})); alert(err.message || 'Failed to send'); }
}

document.getElementById('sendForm').addEventListener('submit', sendMessage);
document.getElementById('msgInput').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(e); }
});
function autoGrow(el){ el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,128)+'px'; }
document.getElementById('msgInput').addEventListener('input', e => autoGrow(e.target));

document.getElementById('resolveBtn').addEventListener('click', async () => {
    const reopen = document.getElementById('resolveBtn').dataset.status === 'resolved';
    await api('/api/conversations/' + current + '/resolve', {method:'POST', body: JSON.stringify({reopen})});
    await refreshChat(); loadList();
});

document.getElementById('backBtn').addEventListener('click', () => {
    document.getElementById('listPane').classList.remove('hidden');
    document.getElementById('chatPane').classList.add('hidden');
    document.getElementById('backBtn').classList.add('hidden');
    current = null;
});

['numberFilter','statusFilter'].forEach(id => document.getElementById(id).addEventListener('change', loadList));
let searchTimer; document.getElementById('search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(loadList, 300); });

function startPolling() {
    clearInterval(pollTimer);
    pollTimer = setInterval(async () => { await loadMe(); await loadList(); if (current) await refreshChat(); }, 5000);
}

if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(()=>{});

(async function init() {
    await loadMe(); await loadNumbers(); await loadCanned(); await loadList(); startPolling();
})();
</script>
</body>
</html>
