const CACHE_NAME = 'almunjaz-shell-v10';
const APP_SHELL = ['/pwa/manifest.json', '/assets/icon-192.png', '/assets/icon-512.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))));
  self.clients.claim();
});

// Navigation and API responses are never cached. This prevents an installed
// app from reopening a stale login or authorization response after deployment.
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  const requestUrl = new URL(event.request.url);
  if (requestUrl.origin !== self.location.origin) return;

  if (requestUrl.pathname.startsWith('/build/') || requestUrl.pathname.startsWith('/assets/')) {
    event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request).then((response) => {
      const copy = response.clone();
      if (response.ok) caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
      return response;
    })));
  }
});
