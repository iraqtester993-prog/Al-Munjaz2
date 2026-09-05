// Release: 2026-09-04 mobile-direct-input-r4
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
  event.waitUntil((async () => {
    await Promise.all(
      (await caches.keys())
        .filter((key) => key !== CACHE_NAME)
        .map((key) => caches.delete(key)),
    );

    // On Android/Chromium this starts the document request while the worker
    // is waking up. It avoids adding worker-startup latency when an installed
    // PWA opens a server-rendered Inertia page; unsupported browsers simply
    // continue with the normal fetch path below.
    if ('navigationPreload' in self.registration) {
      try {
        await self.registration.navigationPreload.enable();
      } catch (_) {
        // Keep activation successful if an older browser exposes the API but
        // does not allow it for this worker context.
      }
    }

    await self.clients.claim();
  })());
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
  const notificationId = data.notificationId || data.notification_id || payload.notificationId || payload.notification_id;
  const inboxUrl = notificationId
    ? `/app/notifications?open=${encodeURIComponent(notificationId)}`
    : '/app/notifications';
  // A notification may intentionally target a specific order or chat. When
  // no target was provided, open its own inbox item so the recipient sees
  // the complete title, body and actions.
  const url = data.url || payload.url || inboxUrl;

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
    event.respondWith((async () => {
      try {
        return (await event.preloadResponse) || await fetch(event.request);
      } catch (_) {
        return (await caches.match(OFFLINE_PAGE)) || Response.error();
      }
    })());
    return;
  }

  // JavaScript and style bundles intentionally stay network-only. A legacy
  // worker once used cache-first for every request, leaving installed Android
  // copies on an old registration screen. Content-hashed Vite files are fast
  // to fetch and the offline page above remains available when disconnected.
});
