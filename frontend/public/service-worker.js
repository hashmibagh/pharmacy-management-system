/* Hand-rolled service worker for the Pharmacy Management PWA.
 * No Firebase / no Workbox dependency.
 *
 * Strategies:
 *  - App shell (HTML / JS / CSS / images): cache-first, fall back to
 *    offline.html for navigations when the network is unavailable.
 *  - /api GET requests: network-first with cache fallback so lists still
 *    render offline when previously fetched.
 *  - /api POST / PUT / DELETE while offline: the app queues these in
 *    IndexedDB (offline outbox) instead of reaching the SW; the queue is
 *    flushed on reconnect by the foreground app and via Background Sync.
 *  - Push: Web Push handler stub (applicationServerKey must be injected by
 *    the app when subscribing via PushManager).
 */

const CACHE_NAME = 'pharmacy-shell-v1';
const API_CACHE_NAME = 'pharmacy-api-v1';
const OFFLINE_URL = '/offline.html';

// Precache the app shell on install.
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => cache.addAll(['/', '/index.html', OFFLINE_URL]))
      .then(() => self.skipWaiting())
      .catch(() => {}),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const names = await caches.keys();
      await Promise.all(
        names
          .filter(
            (n) =>
              n !== CACHE_NAME && n !== API_CACHE_NAME && n.startsWith('pharmacy-'),
          )
          .map((n) => caches.delete(n)),
      );
      await self.clients.claim();
    })(),
  );
});

function isApiRequest(request) {
  return request.url.includes('/api/');
}

function isNavigation(request) {
  return request.mode === 'navigate';
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return; // writes go through the app's outbox

  if (isApiRequest(request)) {
    // Network-first for API reads, cache fallback for offline resilience.
    event.respondWith(
      (async () => {
        try {
          const response = await fetch(request);
          const copy = response.clone();
          const cache = await caches.open(API_CACHE_NAME);
          cache.put(request, copy).catch(() => {});
          return response;
        } catch (err) {
          const cached = await caches.match(request);
          if (cached) return cached;
          return new Response(
            JSON.stringify({ success: false, message: 'Offline — no cached response' }),
            { status: 503, headers: { 'Content-Type': 'application/json' } },
          );
        }
      })(),
    );
    return;
  }

  if (isNavigation(request)) {
    event.respondWith(
      (async () => {
        try {
          const response = await fetch(request);
          const copy = response.clone();
          const cache = await caches.open(CACHE_NAME);
          cache.put(request, copy).catch(() => {});
          return response;
        } catch (err) {
          const cached = await caches.match(request).catch(() => null);
          return cached || (await caches.match(OFFLINE_URL));
        }
      })(),
    );
    return;
  }

  // Static assets: cache-first, populate on the way through.
  event.respondWith(
    (async () => {
      const cached = await caches.match(request);
      if (cached) return cached;
      try {
        const response = await fetch(request);
        if (response && response.ok) {
          const copy = response.clone();
          const cache = await caches.open(CACHE_NAME);
          cache.put(request, copy).catch(() => {});
        }
        return response;
      } catch (err) {
        return new Response('Offline', { status: 503 });
      }
    })(),
  );
});

// Background Sync: tell the foreground app to flush the IndexedDB outbox.
self.addEventListener('sync', (event) => {
  if (event.tag === 'pharmacy-outbox-sync') {
    event.waitUntil(
      (async () => {
        const clients = await self.clients.matchAll({ includeUncontrolled: true });
        clients.forEach((client) => client.postMessage({ type: 'FLUSH_OUTBOX' }));
      })(),
    );
  }
});

// Push notification handler stub (Web Push — no Firebase Cloud Messaging).
// The app subscribes via PushManager and sends the subscription to the backend.
self.addEventListener('push', (event) => {
  let payload = { title: 'Pharmacy', body: 'You have a new notification.', data: {} };
  try {
    if (event.data) payload = { ...payload, ...event.data.json() };
  } catch (err) {
    // text payload fallback
    payload.body = event.data ? event.data.text() : payload.body;
  }

  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: payload.body,
      icon: '/icons/icon-192.png',
      badge: '/icons/icon-192.png',
      data: payload.data || {},
      tag: payload.tag || 'pharmacy-general',
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(
    (async () => {
      const clients = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
      });
      for (const client of clients) {
        if ('focus' in client) {
          await client.focus();
          if ('navigate' in client && client.url !== target) {
            await client.navigate(target);
          }
          return;
        }
      }
      if (self.clients.openWindow) await self.clients.openWindow(target);
    })(),
  );
});
