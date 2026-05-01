const CACHE  = 'nilla-v3';
const STATIC = [
    '/offline.html',
    '/manifest.json',
    '/app-icons/icon-192.png',
    '/app-icons/icon-512.png',
];

// ── Install: precache offline shell ───────────────────────────────────────────

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE).then(c => c.addAll(STATIC))
    );
    self.skipWaiting();
});

// ── Activate: drop old caches ─────────────────────────────────────────────────

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// ── Fetch ─────────────────────────────────────────────────────────────────────

self.addEventListener('fetch', e => {
    const req = e.request;
    const url = new URL(req.url);

    // Only handle same-origin GETs
    if (req.method !== 'GET' || url.origin !== self.location.origin) return;

    // Pass through SSE streams and JSON endpoints without caching
    if (url.pathname.includes('stream') ||
        url.pathname.startsWith('/json_endpoints/')) return;

    // Cache-first for static assets (JS, CSS, images, fonts)
    if (/\.(js|css|png|jpg|jpeg|gif|svg|webp|woff2?|ttf|eot|ico)$/i.test(url.pathname)
        || url.pathname.endsWith('theme.css.php')) {
        e.respondWith(cacheFirst(req));
        return;
    }

    // Network-first for all PHP pages; fall back to offline page
    e.respondWith(networkFirst(req));
});

// ── Strategies ────────────────────────────────────────────────────────────────

async function cacheFirst(req) {
    const cached = await caches.match(req);
    if (cached) return cached;
    try {
        const resp = await fetch(req);
        if (resp.ok) {
            const cache = await caches.open(CACHE);
            cache.put(req, resp.clone());
        }
        return resp;
    } catch {
        return new Response('Asset unavailable offline', { status: 503 });
    }
}

async function networkFirst(req) {
    try {
        const resp = await fetch(req);
        if (resp.ok) {
            const cache = await caches.open(CACHE);
            cache.put(req, resp.clone());
        }
        return resp;
    } catch {
        const cached = await caches.match(req);
        if (cached) return cached;
        return caches.match('/offline.html');
    }
}
