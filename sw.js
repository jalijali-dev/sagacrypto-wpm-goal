/**
 * Sagagoal — service worker (PWA, added 20 Agu 2026).
 *
 * MUST stay at the site root (not in a subfolder) — a service worker's
 * default scope is the directory it's served from, and this needs to
 * control the whole origin (every page, not just one section).
 *
 * Strategy, deliberately different per content type:
 *   - Static assets (assets/css|js|img/*) — stale-while-revalidate: serve
 *     instantly from cache, then re-fetch in the background to refresh the
 *     cache for next time. These change rarely and a one-request-stale
 *     copy is harmless.
 *   - HTML navigations (page loads — index.php, /artikel/<slug>,
 *     football.php, etc.) — network-first: this is a news site, scores
 *     and headlines must be current whenever the network is actually
 *     available. Cache is ONLY the offline fallback, never preferred over
 *     a live network response. A page that was never visited before going
 *     offline falls back to OFFLINE_URL (a tiny cached placeholder) rather
 *     than a browser's default blank/error page.
 *   - Everything else (cms-admin/, uploads/, API calls, ads) — not
 *     intercepted at all; goes straight to the network exactly as if this
 *     service worker didn't exist. Caching admin/dynamic/third-party
 *     content is out of scope and risks serving stale ads or, worse,
 *     stale admin state.
 */

/**
 * ---- FCM (Firebase Cloud Messaging) companion, added 27 Agu 2026 ----
 *
 * Combined into this SAME file rather than a separate
 * firebase-messaging-sw.js, deliberately: a service worker's default
 * scope is the directory it's served from, and this file already claims
 * the whole origin (see docblock above) — Firebase's own guidance for
 * "I already have a service worker at root scope" is to import the
 * Messaging scripts into that existing worker instead of registering a
 * second one, precisely to avoid two workers fighting over the same
 * scope. The frontend (includes/site-footer.php) passes this file's own
 * registration into getToken({ serviceWorkerRegistration }) instead of
 * letting Firebase register its own file, so there is still only ever
 * one active service worker for the origin.
 *
 * FCM_WEB_CONFIG is Firebase's small PUBLIC web-app config (apiKey,
 * projectId, messagingSenderId, appId, ...) — not a secret, it's meant
 * to ship inside client JS/SW. Regenerated in place between the two
 * marker comments below by cms_push_regenerate_sw_js_config()
 * (cms-admin/includes/PushNotificationHelper.php) every time the admin
 * saves Push Notification settings — don't hand-edit the line between
 * the markers, it gets overwritten on the next save. `null` just means
 * push notifications haven't been configured yet.
 */
/* FCM_WEB_CONFIG_START */
const FCM_WEB_CONFIG = null;
/* FCM_WEB_CONFIG_END */

if (FCM_WEB_CONFIG) {
  try {
    importScripts('https://www.gstatic.com/firebasejs/10.13.0/firebase-app-compat.js');
    importScripts('https://www.gstatic.com/firebasejs/10.13.0/firebase-messaging-compat.js');
    firebase.initializeApp(FCM_WEB_CONFIG);
    const messaging = firebase.messaging();

    // Data-only messages (see cms_push_send_single() server-side) —
    // onBackgroundMessage fires for every push while no tab has focus,
    // and we build the notification ourselves so title/body/image/link
    // are exactly what the publishing article says, not FCM's default.
    messaging.onBackgroundMessage((payload) => {
      const data = (payload && payload.data) || {};
      const title = data.title || 'Sagagoal';
      self.registration.showNotification(title, {
        body: data.body || '',
        icon: 'assets/img/icon-192.png',
        image: data.image || undefined,
        data: { url: data.url || './' },
      });
    });
  } catch (e) {
    // Firebase CDN unreachable, or FCM_WEB_CONFIG malformed — push just
    // silently doesn't work this time. Must never throw past this point:
    // everything below (the existing cache/offline logic) has to keep
    // working regardless of Firebase's availability.
  }
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || './';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      for (const client of windowClients) {
        if (client.url === url && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(url);
      }
    })
  );
});

const CACHE_NAME = 'sagagoal-v1';
const OFFLINE_URL = 'offline.html';

const PRECACHE_URLS = [
  OFFLINE_URL,
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

/** True for assets/css/*, assets/js/*, assets/img/* (any path segment matching, works whether this SW's own scope is root or a local subfolder). */
function isStaticAsset(url) {
  return /\/assets\/(css|js|img)\//.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const request = event.request;

  // Only ever handle same-origin GET — never intercept POST (forms,
  // admin actions) or cross-origin requests (ads, third-party embeds,
  // livescore APIs called from the page itself).
  if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) {
    return;
  }

  const url = new URL(request.url);

  if (isStaticAsset(url)) {
    event.respondWith(staleWhileRevalidate(request));
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkFirstNavigation(request));
    return;
  }

  // Anything else (cms-admin/, uploads/, API/XHR calls, etc.) — not
  // intercepted, falls through to the browser's normal network handling.
});

async function staleWhileRevalidate(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);

  const networkFetch = fetch(request)
    .then((response) => {
      if (response && response.ok) {
        cache.put(request, response.clone());
      }
      return response;
    })
    .catch(() => null);

  return cached || (await networkFetch) || Response.error();
}

async function networkFirstNavigation(request) {
  try {
    const response = await fetch(request);
    return response;
  } catch (e) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    if (cached) {
      return cached;
    }
    const offline = await cache.match(OFFLINE_URL);
    return offline || Response.error();
  }
}
