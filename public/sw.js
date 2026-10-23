/* Service worker.
   - App files (html, css, js, icons): network first, so a new release reaches users without anyone having to
     bump a version; the cached copy is used when offline or when the network takes longer than 4 seconds.
   - Fonts: cache first (the files never change).
   - Readings API: network first too. The page already shows its own saved copy instantly and then asks the
     server, so it must see the real answer (fresh text, or a 429 / 422 / 503 to react to). The cached copy is
     used only when the network fails, is too slow, or the server errors (5xx). Only successful answers are stored. */
const VERSION = 'v15'; // only changes when the cache layout changes; releases do not need a bump
const SHELL = `shell-${VERSION}`;
const API = `api-${VERSION}`;
const FONTS = `fonts-${VERSION}`;
const NETWORK_TIMEOUT_MS = 4000;

const SHELL_FILES = [
  './', 'index.html', 'manifest.json', 'css/styles.css', 'css/fonts.css',
  'js/app.js', 'js/config.js', 'js/coptic.js', 'js/demo.js', 'icons/icon.svg', 'icons/icon-192.png', 'icons/icon-512.png',
];
// What the default look needs offline. The other reading fonts are stored the first time they are used.
const CORE_FONTS = [
  'amiri-arabic-400-normal', 'amiri-arabic-700-normal', 'amiri-latin-400-normal', 'amiri-latin-700-normal',
  'noto-sans-arabic-arabic-400-normal', 'noto-sans-arabic-arabic-600-normal', 'noto-sans-arabic-arabic-700-normal',
  'inter-latin-400-normal', 'inter-latin-600-normal', 'inter-latin-700-normal',
].map((name) => `fonts/${name}.woff2`);

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    await (await caches.open(SHELL)).addAll(SHELL_FILES);
    const fonts = await caches.open(FONTS);
    await Promise.allSettled(CORE_FONTS.map((file) => fonts.add(file))); // a missing font must not block the install
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => ![SHELL, API, FONTS].includes(k)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

async function apiNetworkFirst(request) {
  const cache = await caches.open(API);
  const cached = await cache.match(request);
  const network = fetch(request).then((res) => {
    if (res.ok) cache.put(request, res.clone());
    return res;
  });
  network.catch(() => {}); // when the timeout wins, a later network failure is not an error anyone is waiting for

  try {
    // A saved copy is only a fallback, so we stop waiting after a few seconds. With nothing saved there is
    // nothing to fall back to: wait for the server (the very first fetch of a day can take a while).
    const res = cached
      ? await Promise.race([network, new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), NETWORK_TIMEOUT_MS))])
      : await network;
    return res.status >= 500 && cached ? cached : res; // 422 / 429 go to the page, which handles them itself
  } catch (e) {
    return cached || Response.error(); // offline or too slow
  }
}

async function networkFirst(request) {
  const cache = await caches.open(SHELL);
  try {
    const res = await Promise.race([
      fetch(request),
      new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), NETWORK_TIMEOUT_MS)),
    ]);
    if (res && res.ok) cache.put(request, res.clone());
    return res;
  } catch (e) {
    return (await cache.match(request, { ignoreSearch: true }))
      || (request.mode === 'navigate' ? await cache.match('index.html') : null)
      || Response.error();
  }
}

async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;
  const res = await fetch(request);
  if (res && res.ok) cache.put(request, res.clone());
  return res;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== location.origin) return; // anything else goes straight to the network

  if (url.pathname.startsWith('/api/')) {
    event.respondWith(apiNetworkFirst(request));
  } else if (url.pathname.includes('/fonts/')) {
    event.respondWith(cacheFirst(request, FONTS));
  } else {
    event.respondWith(networkFirst(request));
  }
});
