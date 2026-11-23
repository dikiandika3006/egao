/*
 * Service worker Egao (PWA).
 * Semua path dihitung dari scope, jadi tetap jalan di domain sendiri (egao.test)
 * maupun subfolder (localhost/egao/public).
 * - Aset build, ikon, dan font: cache-first.
 * - Halaman: network-first, fallback ke halaman offline.
 * - Permintaan Livewire/POST tidak pernah di-cache (data klinik selalu dari server).
 * Offline draft rekam medis (NFR-AVL-02) akan ditambahkan bersama modul rekam medis.
 */
const VERSION = 'egao-v3';
const SCOPE = new URL(self.registration.scope).pathname; // mis. "/" atau "/egao/public/"
const OFFLINE_URL = SCOPE + 'offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll([OFFLINE_URL, SCOPE + 'icons/icon-192.png'])));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET') return;

    const isStatic =
        url.pathname.startsWith(SCOPE + 'build/') ||
        url.pathname.startsWith(SCOPE + 'icons/') ||
        url.hostname === 'fonts.gstatic.com' ||
        url.hostname === 'fonts.googleapis.com';

    if (isStatic) {
        event.respondWith(
            caches.match(request).then(
                (hit) =>
                    hit ||
                    fetch(request).then((res) => {
                        const copy = res.clone();
                        caches.open(VERSION).then((cache) => cache.put(request, copy));
                        return res;
                    })
            )
        );
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
    }
});
