/*
 * Service worker of the TPV. The Vite manifest drives the precache, so every deploy
 * produces a new static cache. The /tpv shell is network-first with a cached fallback
 * so tablets can boot without network; data comes from IndexedDB, not from here.
 */
const STATIC_PREFIX = 'tpv-static-';
const PAGES = 'tpv-pages';
const MEDIA = 'tpv-media';
const SHELL_URL = '/tpv';
const EXTRA = ['/favicon.ico', '/images/logo-centre-civic.png', '/images/logo-centre-civic-icon.png', '/manifest.webmanifest'];

function hash(text) {
    let h = 0;

    for (let i = 0; i < text.length; i++) {
        h = (Math.imul(31, h) + text.charCodeAt(i)) | 0;
    }

    return (h >>> 0).toString(36);
}

async function staticCacheName() {
    const response = await fetch('/build/manifest.json', { cache: 'no-store' });
    const text = await response.text();

    return { name: STATIC_PREFIX + hash(text), manifest: JSON.parse(text) };
}

function manifestFiles(manifest) {
    const files = new Set();

    for (const entry of Object.values(manifest)) {
        files.add(`/build/${entry.file}`);

        for (const css of entry.css ?? []) {
            files.add(`/build/${css}`);
        }

        for (const asset of entry.assets ?? []) {
            files.add(`/build/${asset}`);
        }
    }

    return [...files];
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const { name, manifest } = await staticCacheName();
            const cache = await caches.open(name);
            const files = [...manifestFiles(manifest), ...EXTRA];

            // Individual failures (e.g. a removed image) must not abort the whole install.
            await Promise.all(files.map((url) => cache.add(url).catch(() => undefined)));

            try {
                const shell = await fetch(SHELL_URL, { credentials: 'same-origin' });

                if (shell.ok && !shell.redirected) {
                    await (await caches.open(PAGES)).put(SHELL_URL, shell);
                }
            } catch {
                // Offline install: the shell is cached on the next online visit.
            }

            await self.skipWaiting();
        })(),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const { name } = await staticCacheName().catch(() => ({ name: null }));

            if (name) {
                for (const key of await caches.keys()) {
                    if (key.startsWith(STATIC_PREFIX) && key !== name) {
                        await caches.delete(key);
                    }
                }
            }

            await self.clients.claim();
        })(),
    );
});

async function cacheFirst(request) {
    const cached = await caches.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        const keys = await caches.keys();
        const name = keys.find((key) => key.startsWith(STATIC_PREFIX)) ?? `${STATIC_PREFIX}runtime`;
        await (await caches.open(name)).put(request, response.clone());
    }

    return response;
}

async function shellNetworkFirst(request) {
    const cache = await caches.open(PAGES);

    try {
        const response = await fetch(request);

        if (response.ok && !response.redirected && response.headers.get('content-type')?.includes('text/html')) {
            await cache.put(SHELL_URL, response.clone());
        }

        return response;
    } catch {
        const cached = await cache.match(SHELL_URL);

        if (cached) {
            return cached;
        }

        throw new Error('offline');
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(MEDIA);
    const cached = await cache.match(request);
    const network = fetch(request)
        .then((response) => {
            if (response.ok) {
                void cache.put(request, response.clone());
            }

            return response;
        })
        .catch(() => cached);

    return cached ?? network;
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));

        return;
    }

    if (request.mode === 'navigate' && (url.pathname === SHELL_URL || url.pathname === `${SHELL_URL}/`)) {
        event.respondWith(shellNetworkFirst(request));

        return;
    }

    if (url.pathname.startsWith('/storage/') || url.pathname.startsWith('/images/') || url.pathname === '/favicon.ico') {
        event.respondWith(staleWhileRevalidate(request));
    }
});

self.addEventListener('message', (event) => {
    if (event.data === 'skipWaiting') {
        void self.skipWaiting();
    }
});
