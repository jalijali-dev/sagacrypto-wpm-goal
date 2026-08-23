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
