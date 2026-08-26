// Laravel replaces __PWA_VERSION__ at the dynamic /pwa/worker route. Keeping
// the source as a placeholder guarantees its cache name matches app.blade.php.
const CACHE_NAME = 'almunjaz-shell-__PWA_VERSION__';
const OFFLINE_PAGE = '/pwa/offline';
const APP_SHELL = [OFFLINE_PAGE, '/pwa/manifest', '/assets/icon-192.png', '/assets/icon-512.png', '/assets/icon-maskable-512.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))));
  self.clients.claim();
});

// A Web Push event is delivered by the browser even when the PWA has no open
// window.  Android/iOS decide the audible alert from the user's device and
// notification settings; `silent: false` explicitly asks the platform not to
// suppress that normal system alert.
self.addEventListener('push', (event) => {
  let payload = {};

  try {
    payload = event.data ? event.data.json() : {};
  } catch (_) {
    payload = { body: event.data ? event.data.text() : '' };
  }

  const data = payload.data || {};
  const url = data.url || payload.url || '/app/notifications';

  event.waitUntil(self.registration.showNotification(payload.title || 'المنجز السريع', {
    body: payload.body || '',
    icon: payload.icon || '/assets/icon-192.png',
    badge: payload.badge || '/assets/icon-192.png',
    tag: payload.tag || `almunjaz-${Date.now()}`,
    data: { ...data, url },
    renotify: true,
    silent: false,
    vibrate: [180, 80, 180],
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const url = event.notification.data?.url || '/app/notifications';

  event.waitUntil((async () => {
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    const existing = windows.find((client) => new URL(client.url).origin === self.location.origin);

    if (existing) {
      await existing.focus();
      if ('navigate' in existing) await existing.navigate(url);
      return;
    }

    await clients.openWindow(url);
  })());
});

// Navigation and API responses are never cached. This prevents an installed
// app from reopening a stale login or authorization response after deployment.
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  const requestUrl = new URL(event.request.url);
  if (requestUrl.origin !== self.location.origin) return;

  // Never pretend that authenticated data is current while disconnected.
  // We instead present an explicit, app-branded offline state for a
  // navigation request.  The user can retry as soon as connectivity returns.
  if (event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE_PAGE)));
    return;
  }

  if (requestUrl.pathname.startsWith('/build/') || requestUrl.pathname.startsWith('/assets/')) {
    event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request).then((response) => {
      const copy = response.clone();
      if (response.ok) caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
      return response;
    })));
  }
});
