/*
 * Umar Techy store app: service worker (Phase B55; Module 25 §10–26, §65–66, §83).
 * Generated for one store by StorefrontPwaController; CONFIG is filled in on the server.
 *
 * - Its scope is the store's own address, and every cache name carries the
 *   store and the version, so one store's pages never reach another (§26).
 * - Live data is never cached: API calls, Inertia page data, and every page
 *   that is about a person (account, cart, checkout, support, returns) or
 *   that the server marks private (X-Ut-Private). Offline, those show the
 *   offline page. Prices, stock and orders always come from the server (§4, §17).
 * - Public pages: network first, the last copy when offline (§15–16).
 * - Built assets (hashed file names) and the store's images: cache first.
 * - A new version waits until the page asks it to take over (§23–24).
 * - When the app is switched off or the shop is not open, this worker
 *   clears its caches and unregisters itself (§83).
 */
const CONFIG = __CONFIG__;
const PREFIX = 'ut-' + CONFIG.store + '-';
const PAGES = PREFIX + 'pages-' + CONFIG.version;
const ASSETS = PREFIX + 'assets-' + CONFIG.version;
const IMAGES = PREFIX + 'images-' + CONFIG.version;
const CURRENT = [PAGES, ASSETS, IMAGES];

self.addEventListener('install', (event) => {
  if (CONFIG.retired) {
    self.skipWaiting();
    return;
  }
  event.waitUntil(caches.open(PAGES).then((cache) => cache.add(new Request(CONFIG.offline, { cache: 'reload' }))));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const names = (await caches.keys()).filter((name) => name.startsWith(PREFIX));
      if (CONFIG.retired) {
        await Promise.all(names.map((name) => caches.delete(name)));
        await self.registration.unregister();
        return;
      }
      await Promise.all(names.filter((name) => !CURRENT.includes(name)).map((name) => caches.delete(name)));
      await self.clients.claim();
    })(),
  );
});

self.addEventListener('message', (event) => {
  if (event.data === 'skip-waiting') self.skipWaiting();
  // Signing out: no page of the session stays on the device (§66).
  if (event.data === 'forget-pages') {
    event.waitUntil(caches.delete(PAGES).then(() => caches.open(PAGES)).then((cache) => cache.add(new Request(CONFIG.offline, { cache: 'reload' }))));
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (CONFIG.retired || request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/') || request.headers.has('X-Inertia')) return;
  if (url.pathname.startsWith('/build/assets/')) {
    event.respondWith(cacheFirst(request, ASSETS, 300));
    return;
  }
  if (url.pathname.startsWith(CONFIG.media)) {
    event.respondWith(cacheFirst(request, IMAGES, CONFIG.imageLimit));
    return;
  }
  if (request.mode === 'navigate' && (url.pathname === CONFIG.base || url.pathname.startsWith(CONFIG.base + '/'))) {
    event.respondWith(page(request, url));
  }
});

function isPrivate(url) {
  const path = url.pathname.slice(CONFIG.base.length) || '/';
  if (CONFIG.private.some((prefix) => path === prefix || path.startsWith(prefix + '/'))) return true;

  return ['token', 'preview', 'theme_preview', 'signature'].some((name) => url.searchParams.has(name));
}

function cacheKey(url) {
  const key = new URL(url.href);
  key.hash = '';
  key.searchParams.delete('source');

  return key.href;
}

async function page(request, url) {
  const isPrivatePage = isPrivate(url);
  try {
    const response = await fetch(request);
    if (!isPrivatePage && response.ok && response.type === 'basic' && !response.headers.has('X-Ut-Private')) {
      const copy = response.clone();
      caches.open(PAGES).then((cache) => cache.put(cacheKey(url), copy).then(() => trim(cache, CONFIG.pageLimit, CONFIG.offline)));
    }

    return response;
  } catch (error) {
    const cache = await caches.open(PAGES);
    if (!isPrivatePage) {
      const saved = await cache.match(cacheKey(url));
      if (saved) return saved;
    }
    const offline = await cache.match(CONFIG.offline);

    return offline || new Response('You are offline.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
  }
}

async function cacheFirst(request, name, limit) {
  const cache = await caches.open(name);
  const saved = await cache.match(request);
  if (saved) return saved;
  const response = await fetch(request);
  if (response.ok && response.type === 'basic') {
    cache.put(request, response.clone()).then(() => trim(cache, limit, null));
  }

  return response;
}

async function trim(cache, limit, keep) {
  const keys = (await cache.keys()).filter((request) => !keep || !request.url.endsWith(keep));
  for (let i = 0; i < keys.length - limit; i++) {
    await cache.delete(keys[i]);
  }
}
