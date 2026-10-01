// Minimal service worker: enables "Add to Home Screen" (installable PWA) and
// an offline shell. Message data is always fetched live from the API.
const CACHE = 'wa-team-v1';
const SHELL = ['/icons/icon-192.png', '/icons/icon-512.png', '/manifest.webmanifest'];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) =>
        Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    // Never cache API or auth — always go to network.
    if (url.pathname.startsWith('/api') || url.pathname.startsWith('/login') || url.pathname.startsWith('/logout')) return;
    if (e.request.method !== 'GET') return;
    e.respondWith(
        fetch(e.request).catch(() => caches.match(e.request))
    );
});

// Web push: show a notification when the server pushes one (optional upgrade).
self.addEventListener('push', (e) => {
    let data = {};
    try { data = e.data.json(); } catch (_) {}
    const title = data.title || 'New message';
    e.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        data: {url: data.url || '/inbox'},
    }));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    e.waitUntil(clients.openWindow(e.notification.data.url || '/inbox'));
});
