/**
 * Shanfix BMS service worker.
 *
 * VERSION, BASE, OFFLINE_URL and SHELL are prepended by PwaController
 * before this file is served, so the worker knows the deployment's base
 * path without hard-coding it.
 *
 * Caching only. The offline write queue lives in the page, not here: a
 * queued POST has to carry a fresh CSRF token, and the token comes from a
 * rendered page. Doing it in the worker would mean fetching and scraping a
 * page for a token, which is more moving parts than the problem deserves.
 */

const SHELL_CACHE = 'shanfix-shell-' + VERSION;
const PAGE_CACHE  = 'shanfix-pages-' + VERSION;

/** A slow network should not out-wait a usable cached copy. */
const NETWORK_TIMEOUT_MS = 3500;

/**
 * Never cached, and never served from cache.
 *
 * Auth endpoints because a stale one is confusing; file downloads because
 * they are large and already have their own caching; the queue endpoints
 * because a cached answer would be a lie.
 */
const NEVER_CACHE = [
  '/login', '/logout', '/sw.js', '/offline/precache',
  '/files/', '/proof/', '/webhooks/',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE)
      // Individually, so one 404 cannot fail the whole install.
      .then((cache) => Promise.all(
        SHELL.map((url) => cache.add(url).catch(() => null))
      ))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((k) => k.startsWith('shanfix-') && k !== SHELL_CACHE && k !== PAGE_CACHE)
          .map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

/**
 * Signing out must leave nothing behind. These are shared workshop
 * devices: without this the next person to open the app sees the previous
 * user's cached pages, including clients and figures they may not be
 * allowed to see.
 */
self.addEventListener('message', (event) => {
  const data = event.data || {};

  if (data.type === 'clear') {
    event.waitUntil(
      caches.keys().then((keys) => Promise.all(
        keys.filter((k) => k.startsWith('shanfix-')).map((k) => caches.delete(k))
      ))
    );
    return;
  }

  if (data.type === 'precache' && Array.isArray(data.urls)) {
    event.waitUntil(
      caches.open(PAGE_CACHE).then((cache) => Promise.all(
        data.urls.map((url) =>
          fetch(url, { credentials: 'same-origin' })
            .then((res) => (isCacheable(res) ? cache.put(url, res.clone()) : null))
            .catch(() => null)
        )
      ))
    );
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  // Writes always go to the network. If they fail, the page queues them.
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) return;
  if (NEVER_CACHE.some((path) => url.pathname.includes(path))) return;

  if (request.mode === 'navigate') {
    event.respondWith(handleNavigation(request));
    return;
  }

  if (/\.(css|js|png|jpg|jpeg|svg|webp|woff2?)$/i.test(url.pathname)
      || url.pathname.includes('/assets/')) {
    event.respondWith(staleWhileRevalidate(request));
  }
});

/**
 * Pages: try the network briefly, fall back to the last good copy, and
 * only then to the offline notice. Fresh beats fast for a system where a
 * stale invoice balance would mislead someone.
 */
async function handleNavigation(request) {
  const cache = await caches.open(PAGE_CACHE);

  try {
    const response = await withTimeout(
      fetch(request, { credentials: 'same-origin' }),
      NETWORK_TIMEOUT_MS
    );

    if (isCacheable(response)) {
      cache.put(request, response.clone());
    }

    return response;
  } catch (err) {
    const cached = await cache.match(request, { ignoreSearch: false })
                || await cache.match(request, { ignoreSearch: true });

    if (cached) {
      return cached;
    }

    const offline = await caches.match(OFFLINE_URL);
    return offline || new Response(
      'You are offline and this page has not been saved for offline use.',
      { status: 503, headers: { 'Content-Type': 'text/plain' } }
    );
  }
}

/** Assets: serve instantly, refresh in the background. */
async function staleWhileRevalidate(request) {
  const cache  = await caches.open(SHELL_CACHE);
  const cached = await cache.match(request);

  const network = fetch(request, { credentials: 'same-origin' })
    .then((response) => {
      if (isCacheable(response)) {
        cache.put(request, response.clone());
      }
      return response;
    })
    .catch(() => null);

  return cached || network || fetch(request);
}

/**
 * A response is only worth keeping if it is a real 200 from us and not the
 * login page wearing another URL. Caching a redirect to /login would pin
 * every visitor to a sign-in screen they cannot leave.
 */
function isCacheable(response) {
  if (!response || !response.ok || response.status !== 200) return false;
  if (response.type === 'opaque' || response.type === 'opaqueredirect') return false;

  if (response.redirected) {
    try {
      if (new URL(response.url).pathname.includes('/login')) return false;
    } catch (e) { /* unparseable URL: fall through to the header checks */ }
  }

  const control = response.headers.get('Cache-Control') || '';
  if (control.includes('no-store') || control.includes('private')) return false;

  return true;
}

function withTimeout(promise, ms) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('timeout')), ms);
    promise.then(
      (value) => { clearTimeout(timer); resolve(value); },
      (err)   => { clearTimeout(timer); reject(err); }
    );
  });
}
