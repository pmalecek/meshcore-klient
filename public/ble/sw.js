/*
 * Service worker Bluetooth klienta (/ble) – aplikace se dá nainstalovat a načte se i bez internetu.
 * Stránka: nejdřív síť, při výpadku uložená kopie. Statické soubory: z cache, na pozadí se obnoví.
 * API serveru (api.php, heard.php) ani mapové dlaždice se necachují.
 */
const VERSION = 'mcble-v2';
const ROOT = new URL('../', self.registration.scope).href;   // kořen aplikace (o úroveň výš než /ble/)
const PAGE = self.registration.scope;                          // …/ble/
const STATIC = [
    'assets/vendor/bootstrap/bootstrap.min.css',
    'assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
    'assets/vendor/jquery/jquery.min.js',
    'assets/vendor/bootstrap/bootstrap.bundle.min.js',
    'assets/vendor/leaflet/leaflet.js',
    'assets/vendor/leaflet/leaflet.css',
    'assets/vendor/meshcore/index.js',
    'assets/vendor/meshcore/advert.js',
    'assets/vendor/meshcore/buffer_reader.js',
    'assets/vendor/meshcore/buffer_utils.js',
    'assets/vendor/meshcore/buffer_writer.js',
    'assets/vendor/meshcore/cayenne_lpp.js',
    'assets/vendor/meshcore/constants.js',
    'assets/vendor/meshcore/events.js',
    'assets/vendor/meshcore/hex_util.js',
    'assets/vendor/meshcore/meshcore_path.js',
    'assets/vendor/meshcore/packet.js',
    'assets/vendor/meshcore/random_utils.js',
    'assets/vendor/meshcore/transport_key_util.js',
    'assets/vendor/meshcore/connection/connection.js',
    'assets/vendor/meshcore/connection/nodejs_serial_connection.js',
    'assets/vendor/meshcore/connection/serial_connection.js',
    'assets/vendor/meshcore/connection/tcp_connection.js',
    'assets/vendor/meshcore/connection/web_ble_connection.js',
    'assets/vendor/meshcore/connection/web_serial_connection.js',
    'favicon.ico',
    'favicon.svg',
    'ble/manifest.json',
    'ble/icon-192.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(VERSION);
        await cache.addAll(STATIC.map((p) => ROOT + p)).catch(() => {});
        // stránka + soubory, na které odkazuje (verzované ?v=…)
        try {
            const res = await fetch(PAGE, { credentials: 'same-origin', cache: 'no-store' });
            if (res.ok) {
                const html = await res.clone().text();
                await cache.put(PAGE, res);
                const urls = [...html.matchAll(/(?:src|href)="(assets\/[^"]+)"/g)].map((m) => ROOT + m[1].replace(/&amp;/g, '&'));
                await cache.addAll(urls).catch(() => {});
            }
        } catch (e) { /* offline při instalaci */ }
        self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const k of await caches.keys()) {
            if (k !== VERSION) { await caches.delete(k); }
        }
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') { return; }
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) { return; }                 // dlaždice OSM apod.
    if (/\/(api|login|logout|bg)\.php$/.test(url.pathname) || url.pathname.endsWith('/heard.php')) { return; }

    // stránka /ble/: síť, při výpadku cache
    if (req.mode === 'navigate' && url.href.startsWith(PAGE)) {
        event.respondWith((async () => {
            try {
                const res = await fetch(req);
                if (res.ok) { (await caches.open(VERSION)).put(PAGE, res.clone()); }
                return res;
            } catch (e) {
                return (await caches.match(PAGE)) || Response.error();
            }
        })());
        return;
    }

    // statické soubory: cache, na pozadí obnovit
    if (url.pathname.includes('/assets/') || url.href.startsWith(PAGE)) {
        event.respondWith((async () => {
            const cache = await caches.open(VERSION);
            const hit = await cache.match(req);
            const net = fetch(req).then((res) => { if (res.ok) { cache.put(req, res.clone()); } return res; }).catch(() => null);
            return hit || (await net) || Response.error();
        })());
    }
});
