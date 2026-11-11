/*
 * MeshCore klient – režim Bluetooth (Web Bluetooth).
 *
 * Tenhle skript poskytuje stejné „API“ jako server (api.php), jen ho obsluhuje přímo
 * v prohlížeči: s companionem mluví přes knihovnu meshcore.js (Web Bluetooth)
 * a historii ukládá do IndexedDB. Uživatelské rozhraní (app.js) je společné pro oba režimy.
 */
(function () {
    'use strict';

    var LIB = window.MC_LIB || null;
    window.addEventListener('mc-lib', function () { LIB = window.MC_LIB; });

    var DEDUP_WINDOW = 30 * 60 * 1000;   // příchozí duplicita: stejný text od stejného odesílatele do 30 min
    var POLL_TIMEOUT = 20000;
    var MAX_LEN = 140;
    var PATTERNS = ['dots', 'grid', 'diagonal', 'checks', 'circles'];
    var DM_DIRECT = 3, DM_FLOOD = 3;   // přímé zprávy: pokusy známou cestou, pak floodem
    var SYNC_KV = ['settings', 'reads', 'contacts', 'channels', 'self'];   // co se ukládá na server (přihlášený uživatel)
    var SERVER = !!$('meta[name="csrf"]').attr('content');             // přihlášený uživatel = historie i na serveru
    var CSRF = $('meta[name="csrf"]').attr('content') || '';
    var ANALYZER = $('meta[name="mc-analyzer"]').attr('content') === '1';   // kontrola přes analyzer (observeři, heard)
    var LS_DEV = 'mcble.device';         // klíč posledního companionu

    // ---------------------------------------------------------------- pomocné funkce
    function hex(bytes) {
        return Array.from(bytes || [], function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }
    function unhex(s) {
        var out = new Uint8Array(s.length / 2);
        for (var i = 0; i < out.length; i++) { out[i] = parseInt(s.substr(i * 2, 2), 16); }
        return out;
    }
    function iso(ms) { return new Date(ms).toISOString(); }
    function now() { return Date.now(); }
    function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
    function lsGet(k, def) { try { var v = localStorage.getItem(k); return v === null ? def : JSON.parse(v); } catch (e) { return def; } }
    function lsSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* plné / zakázané */ } }
    function err(msg, status) { return { status: status || 400, responseJSON: { error: msg } }; }
    function validConv(c) { return /^(c:\d{1,3}|d:[0-9a-f]{12})$/.test(String(c || '')); }

    // Odhad nabití Li-ion článku podle napětí (mV)
    function batteryPct(mv) {
        var t = [[4200, 100], [4100, 90], [4000, 80], [3900, 65], [3800, 50], [3700, 35], [3600, 20], [3500, 10], [3400, 5], [3300, 0]];
        if (mv >= t[0][0]) { return 100; }
        for (var i = 1; i < t.length; i++) {
            if (mv >= t[i][0]) {
                var a = t[i - 1], b = t[i];
                return Math.round(b[1] + (mv - b[0]) * (a[1] - b[1]) / (a[0] - b[0]));
            }
        }
        return 0;
    }

    // ---------------------------------------------------------------- IndexedDB
    var store = {
        db: null,
        open: function (name) {
            return new Promise(function (resolve, reject) {
                if (!window.indexedDB) { resolve(null); return; }
                var rq = indexedDB.open(name, 1);
                rq.onupgradeneeded = function () {
                    var db = rq.result;
                    if (!db.objectStoreNames.contains('messages')) { db.createObjectStore('messages', { keyPath: 'id' }); }
                    if (!db.objectStoreNames.contains('kv')) { db.createObjectStore('kv', { keyPath: 'k' }); }
                };
                rq.onsuccess = function () { store.db = rq.result; resolve(rq.result); };
                rq.onerror = function () { reject(rq.error); };
            });
        },
        all: function (name) {
            return new Promise(function (resolve) {
                if (!store.db) { resolve([]); return; }
                var rq = store.db.transaction(name).objectStore(name).getAll();
                rq.onsuccess = function () { resolve(rq.result || []); };
                rq.onerror = function () { resolve([]); };
            });
        },
        put: function (name, val) {
            if (!store.db) { return; }
            try { store.db.transaction(name, 'readwrite').objectStore(name).put(val); } catch (e) { console.warn('IDB put', e); }
        },
        del: function (name, key) {
            if (!store.db) { return; }
            try { store.db.transaction(name, 'readwrite').objectStore(name).delete(key); } catch (e) { console.warn('IDB delete', e); }
        }
    };

    // ---------------------------------------------------------------- stav
    var D = {
        dev: lsGet(LS_DEV, null),     // { pk, name } posledního companionu
        msgs: {},                     // id -> zpráva (interní formát)
        nextId: 1,
        seq: 1,                       // pořadové číslo změny (kurzor pro poll)
        contacts: [],                 // kontakty companionu
        channels: [],                 // kanály companionu
        self: null,                   // self info companionu
        reads: {},                    // conv -> poslední přečtené id
        settings: { convs: {}, default_bg: null, retention: 0 },
        battery: null,                // mV
        batteryAt: null,
        lastEventAt: null,
        discovered: {}                // pk -> uzel slyšený advertem (companion v manuálním režimu přidávání)
    };
    var conn = null, connected = false, connecting = false, wantConnected = false;
    var waiters = [];
    var acks = {};                    // expectedAckCrc -> { id, attempt }
    var ready = null;

    function kvSave(k) {
        store.put('kv', { k: k, v: D[k] });
        if (SYNC_KV.indexOf(k) >= 0) { sync.kv(k, D[k]); }
    }

    // ID zprávy: časově řazené a prakticky unikátní i mezi prohlížeči (kvůli ukládání na server)
    function newId() {
        var id = Math.max(D.nextId, now() * 1000);
        D.nextId = id + 1;
        return id;
    }

    function removeMsg(id) {
        if (!D.msgs[id]) { return; }
        delete D.msgs[id];
        store.del('messages', id);
        sync.del(id);
    }

    function load() {
        if (ready) { return ready; }
        ready = (D.dev ? store.open('mcble-' + D.dev.pk) : Promise.resolve(null)).then(function () {
            return Promise.all([store.all('messages'), store.all('kv')]);
        }).then(function (r) {
            r[0].forEach(function (m) { D.msgs[m.id] = m; if (m.id >= D.nextId) { D.nextId = m.id + 1; } if (m.upd >= D.seq) { D.seq = m.upd + 1; } });
            r[1].forEach(function (x) { if (x.k in D) { D[x.k] = x.v; } });
            D.settings = D.settings || { convs: {}, default_bg: null, retention: 0 };
            cleanup();
        }).catch(function (e) { console.warn('IndexedDB nedostupná', e); }).then(function () {
            if (!SERVER) { return; }
            // přihlášený uživatel: v novém prohlížeči převzít poslední companion ze serveru
            var pre = D.dev ? Promise.resolve() : sync.devices().then(function (r) {
                var d = (r.devices || [])[0];
                if (d && !D.dev) { return switchDevice(d.device, d.name); }
            }).catch(function () {});
            return pre.then(function () { return serverStart(); });
        });
        return ready;
    }

    // přepnutí na jiný companion = jiná databáze historie
    function switchDevice(pk, name) {
        if (D.dev && D.dev.pk === pk) {
            if (name && D.dev.name !== name) { D.dev.name = name; lsSet(LS_DEV, D.dev); }
            return Promise.resolve(false);
        }
        D.dev = { pk: pk, name: name };
        lsSet(LS_DEV, D.dev);
        D.msgs = {}; D.nextId = 1; D.seq = 1; D.contacts = []; D.channels = []; D.reads = {};
        D.settings = { convs: {}, default_bg: null, retention: 0 };
        if (store.db) { store.db.close(); store.db = null; }
        sync.reset();
        ready = null;
        return load().then(function () { return true; });
    }

    // přihlášený uživatel: stáhnout historii ze serveru, a když v tomto prohlížeči něco je a na serveru ne, nahrát
    function serverStart() {
        if (!SERVER || !D.dev) { return Promise.resolve(); }
        var key = 'mcble.pushed.' + D.dev.pk;
        return sync.pull().then(function () {
            if (!lsGet(key, false)) { sync.pushAll(); lsSet(key, true); }
        });
    }

    function saveMsg(m, fromServer) {
        if (!fromServer) { m.mt = now(); sync.msg(m); }
        m.upd = D.seq++;
        D.msgs[m.id] = m;
        store.put('messages', m);
        D.lastEventAt = now();
        notify();
    }

    function notify() {
        var w = waiters; waiters = [];
        w.forEach(function (f) { f(); });
    }

    // ---------------------------------------------------------------- formát zpráv (stejný jako server)
    function fmt(m) {
        return {
            id: m.id, conv: m.conv, dir: m.dir, type: m.type, sender: m.sender || null, author: m.author || null, pk: m.pk || null, body: m.body,
            hops: m.hops != null ? m.hops : null, snr: m.snr != null ? m.snr : null, rssi: m.rssi != null ? m.rssi : null,
            scope: null, status: m.status || null, repeaters: m.repeaters || 0, tries: m.tries || null, flood: m.flood != null ? !!m.flood : null,
            ack: m.ack != null ? m.ack : null, error: m.error || null,
            dup_of: m.dup_of || null, dups: m.dups || 0, heard: m.heard || null, rx: m.rx || null, ts: iso(m.ts)
        };
    }

    function withGroups(list) {
        return list.map(function (m) {
            var f = fmt(m);
            if (m.dir === 'out' && m.dups > 0) {
                var copies = Object.values(D.msgs).filter(function (x) { return x.dup_of === m.id; }).sort(function (a, b) { return a.id - b.id; });
                if (copies.length) {
                    var last = copies[copies.length - 1];
                    f.group = {
                        count: copies.length + 1,
                        repeaters: Math.max.apply(null, [m.repeaters || 0].concat(copies.map(function (x) { return x.repeaters || 0; }))),
                        heard_repeats: Math.max.apply(null, [m].concat(copies).map(function (x) { return (x.heard && x.heard.repeats) || 0; })),
                        heard_count: Math.max.apply(null, [m].concat(copies).map(function (x) { return (x.heard && x.heard.count) || 0; })),
                        delivered: m.status === 'delivered' || copies.some(function (x) { return x.status === 'delivered'; }),
                        last: fmt(last)
                    };
                }
            }
            return f;
        });
    }

    function sorted(filter) {
        return Object.values(D.msgs).filter(filter).sort(function (a, b) { return a.id - b.id; });
    }

    // ---------------------------------------------------------------- konverzace
    function contactByPk(pk12) {
        for (var i = 0; i < D.contacts.length; i++) { if (D.contacts[i].pk === pk12) { return D.contacts[i]; } }
        return null;
    }

    function conversations() {
        var stats = {}, out = {};
        Object.values(D.msgs).forEach(function (m) {
            if (m.dup_of) { return; }
            var s = stats[m.conv] || (stats[m.conv] = { last: null, unread: 0 });
            if (!s.last || m.id > s.last.id) { s.last = m; }
            if (m.dir === 'in' && m.id > (D.reads[m.conv] || 0)) { s.unread++; }
        });
        D.channels.forEach(function (c) {
            var key = 'c:' + c.idx;
            out[key] = { conv: key, kind: 'channel', name: c.name || ('Kanál ' + c.idx), idx: c.idx };
        });
        D.contacts.forEach(function (c) {
            var key = 'd:' + c.pk;
            out[key] = {
                conv: key, kind: 'contact', name: c.name || c.pk, pk: c.pk, type: c.type,
                lat: c.lat, lon: c.lon, last_advert: c.last_advert, path_len: c.path_len, path: c.path, scopes: null
            };
        });
        Object.keys(stats).forEach(function (key) {
            if (out[key]) { return; }
            var p = key.split(':');
            out[key] = p[0] === 'c'
                ? { conv: key, kind: 'channel', name: 'Kanál ' + p[1], idx: +p[1] }
                : { conv: key, kind: 'contact', name: (stats[key].last && stats[key].last.sender) || p[1], pk: p[1], type: null };
        });
        return Object.keys(out).map(function (key) {
            var c = out[key], s = stats[key];
            c.unread = s ? s.unread : 0;
            c.last_id = s && s.last ? s.last.id : 0;
            c.last = s && s.last ? fmt(s.last) : null;
            return c;
        });
    }

    function state() {
        return {
            ble: true,
            worker_alive: true, worker_connected: true,
            companion_connected: connected,
            connecting: connecting,
            device: D.dev,
            last_event_at: D.lastEventAt ? iso(D.lastEventAt) : null,
            worker_error: null, last_send_error: null, sun: null,
            firmware: connected && D.fw ? D.fw : null,
            mqtt_total: 0, mqtt_connected: 0, devices_total: 0, devices_online: 0
        };
    }

    function home() {
        if (D.self && (D.self.lat || D.self.lon)) { return { lat: D.self.lat, lon: D.self.lon, source: 'companion' }; }
        return null;
    }

    // ---------------------------------------------------------------- retence
    function cleanup() {
        var def = D.settings.retention || 0, n = 0, t = now();
        Object.values(D.msgs).forEach(function (m) {
            var cs = D.settings.convs[m.conv] || {};
            var days = cs.retention != null ? cs.retention : def;
            if (days > 0 && m.ts < t - days * 86400000) { removeMsg(m.id); n++; }
        });
        return n;
    }
    setInterval(cleanup, 3600 * 1000);

    function deleteMessage(id) {
        var m = D.msgs[id];
        if (!m) { return 0; }
        var n = 0;
        sorted(function (x) { return x.id === id || x.dup_of === id; }).forEach(function (x) { removeMsg(x.id); n++; });
        if (m.dup_of && D.msgs[m.dup_of]) {
            var o = D.msgs[m.dup_of];
            o.dups = Math.max(0, (o.dups || 0) - 1);
            saveMsg(o);
        }
        return n;
    }

    // ---------------------------------------------------------------- fronta příkazů pro companion
    // Knihovna nepáruje odpovědi s požadavky, proto se příkazy posílají striktně jeden po druhém.
    var queue = Promise.resolve();
    function cmd(fn, timeout) {
        var p = queue.then(function () {
            if (!conn || !connected) { throw new Error('Companion není připojen'); }
            return Promise.race([
                fn(conn),
                sleep(timeout || 10000).then(function () { throw new Error('Companion neodpověděl včas'); })
            ]);
        });
        queue = p.catch(function () { /* fronta pokračuje */ });
        return p;
    }

    // Odeslání příkazu a čekání na jednu z odpovědí (kódy z Constants.ResponseCodes / PushCodes)
    function request(c, send, okCodes, errCodes) {
        return new Promise(function (resolve, reject) {
            var handlers = [];
            function cleanupH() { handlers.forEach(function (h) { c.off(h[0], h[1]); }); }
            okCodes.forEach(function (code) {
                var h = function (d) { cleanupH(); resolve(d); };
                handlers.push([code, h]); c.on(code, h);
            });
            (errCodes || []).forEach(function (code) {
                var h = function (d) { cleanupH(); reject(new Error('Companion odmítl příkaz' + (d && d.errCode != null ? ' (chyba ' + d.errCode + ')' : ''))); };
                handlers.push([code, h]); c.on(code, h);
            });
            Promise.resolve(send()).catch(function (e) { cleanupH(); reject(e); });
        });
    }

    // ---------------------------------------------------------------- Bluetooth spojení
    function C() { return LIB.Constants; }

    function makeConnection(device) {
        // Zápisy do GATT charakteristiky se nesmí překrývat („GATT operation already in progress“)
        var Base = LIB.WebBleConnection;
        var c = new Base(device);
        var writeChain = Promise.resolve();
        c.write = function (bytes) {
            var self = this;
            writeChain = writeChain.then(function () {
                return self.rxCharacteristic.writeValue(bytes);
            }).catch(function (e) { console.warn('BLE zápis selhal', e); });
            return writeChain;
        };
        return c;
    }

    function connect() {
        if (!navigator.bluetooth) {
            return Promise.reject(new Error('Tento prohlížeč nepodporuje Web Bluetooth. Použij Chrome nebo Edge na počítači či Androidu.'));
        }
        if (!LIB) { return Promise.reject(new Error('Knihovna MeshCore se ještě načítá, zkus to za chvíli.')); }
        // requestDevice musí proběhnout přímo v obsluze kliknutí
        var rq = navigator.bluetooth.requestDevice({ filters: [{ services: [LIB.Constants.Ble.ServiceUuid.toLowerCase()] }] });
        connecting = true; notify();
        return rq.then(function (device) {
            wantConnected = true;
            return attach(device);
        }).catch(function (e) {
            connecting = false; notify();
            if (e && e.name === 'NotFoundError') { throw new Error('Výběr zařízení byl zrušen.'); }
            throw e;
        });
    }

    function attach(device) {
        connecting = true; notify();
        return new Promise(function (resolve, reject) {
            var c = makeConnection(device);
            var done = false;
            var t = setTimeout(function () { if (!done) { done = true; connecting = false; notify(); reject(new Error('Spojení s companionem se nepodařilo navázat (vypršel čas).')); } }, 30000);
            c.on('connected', function () {
                if (done) { return; }
                done = true; clearTimeout(t);
                conn = c; connected = true; connecting = false;
                bindEvents(c);
                notify();
                afterConnect(device).then(resolve, function (e) { console.warn(e); resolve(); });
            });
            c.on('disconnected', function () {
                if (c._dead) { return; }
                c._dead = true;
                if (conn && conn !== c) { return; }
                var was = connected;
                connected = false; connecting = false; conn = null;
                notify();
                if (!done) { done = true; clearTimeout(t); reject(new Error('Companion spojení odmítl. Zkontroluj, že je zapnutý a není připojený k jiné aplikaci (např. mobilní).')); return; }
                if (was) { ui('toast', 'Companion odpojen' + (wantConnected ? ' – zkouším se znovu připojit' : ''), 'warning'); }
                if (wantConnected) { reconnect(device, 1); }
            });
        });
    }

    // automatické znovupřipojení ke stejnému zařízení (bez nového výběru)
    function reconnect(device, n) {
        if (!wantConnected || connected || n > 10) { return; }
        setTimeout(function () {
            if (!wantConnected || connected) { return; }
            attach(device).catch(function () { reconnect(device, n + 1); });
        }, Math.min(30000, 2000 * n));
    }

    function disconnect() {
        wantConnected = false;
        if (conn) { try { conn.close(); } catch (e) { /* ignorovat */ } }
        connected = false; conn = null; notify();
    }

    function afterConnect() {
        return cmd(function (c) {
            return request(c, function () { return c.sendCommandAppStart(); }, [C().ResponseCodes.SelfInfo]);
        }).then(function (si) {
            var pk = hex(si.publicKey).slice(0, 12);
            var self = {
                pk: pk, publicKey: hex(si.publicKey), name: si.name,
                lat: si.advLat ? si.advLat / 1e6 : null, lon: si.advLon ? si.advLon / 1e6 : null,
                txPower: si.txPower, maxTxPower: si.maxTxPower, radioFreq: si.radioFreq, radioBw: si.radioBw, radioSf: si.radioSf, radioCr: si.radioCr,
                manualAdd: si.manualAddContacts
            };
            return switchDevice(pk, si.name).then(function () { D.self = self; kvSave('self'); });
        }).then(function () {
            // čas companionu podle počítače (zprávy jinak nesou špatný timestamp)
            return cmd(function (c) {
                return request(c, function () { return c.sendCommandSetDeviceTime(Math.floor(now() / 1000)); }, [C().ResponseCodes.Ok], [C().ResponseCodes.Err]);
            }).catch(function () { /* starší firmware */ });
        }).then(function () {
            // verze firmwaru: model (40 B) a verze (20 B) jsou v meshcore.js slité do manufacturerModel
            return cmd(function (c) { return c.deviceQuery(C().SupportedCompanionProtocolVersion); }).then(function (di) {
                var parts = String(di.manufacturerModel || '').split('\0').map(function (x) { return x.trim(); }).filter(Boolean);
                D.fw = { ver: parts[1] || (di.firmware_build_date ? di.firmware_build_date : 'v' + di.firmwareVer), model: parts[0] || null,
                         build: di.firmware_build_date || null };
            }).catch(function () { D.fw = null; });
        }).then(syncContacts).then(syncChannels).then(refreshBattery).then(function () {
            notify();
            ui('reload');
            ui('toast', 'Companion „' + (D.self && D.self.name) + '“ připojen', 'success');
            return drainMessages();
        });
    }

    function syncContacts() {
        return cmd(function (c) { return c.getContacts(); }, 30000).then(function (list) {
            D.contacts = list.map(function (x) {
                var pl = x.outPathLen, hops = -1, size = 1, path = '';
                if (pl >= 0 && pl !== 255) {
                    hops = pl & 0x3F; size = (pl >> 6) + 1;
                    path = hex(x.outPath.slice(0, hops * size));
                }
                return {
                    pk: hex(x.publicKey).slice(0, 12), publicKey: hex(x.publicKey), name: x.advName, type: x.type,
                    lat: x.advLat ? x.advLat / 1e6 : null, lon: x.advLon ? x.advLon / 1e6 : null,
                    last_advert: x.lastAdvert ? iso(x.lastAdvert * 1000) : null,
                    path_len: hops, path: hops > 0 ? path : null, path_size: size
                };
            });
            kvSave('contacts');
        });
    }

    function syncChannels() {
        return cmd(function (c) { return c.getChannels(); }, 60000).then(function (list) {
            D.channels = list.filter(function (ch) {
                return ch.name && ch.secret && ch.secret.some(function (b) { return b !== 0; });
            }).map(function (ch) { return { idx: ch.channelIdx, name: ch.name, secret: hex(ch.secret) }; });
            D.channelSlots = list.length;
            kvSave('channels');
        });
    }

    function refreshBattery() {
        return cmd(function (c) {
            return request(c, function () { return c.sendCommandGetBatteryVoltage(); }, [C().ResponseCodes.BatteryVoltage], [C().ResponseCodes.Err]);
        }).then(function (r) { D.battery = r.batteryMilliVolts; D.batteryAt = now(); }).catch(function () { /* nepodporováno */ });
    }
    setInterval(function () { if (connected) { refreshBattery(); } }, 5 * 60 * 1000);

    var contactsTimer = null;
    function contactsChanged() {
        clearTimeout(contactsTimer);
        contactsTimer = setTimeout(function () { syncContacts().then(function () { ui('reload'); }).catch(function () {}); }, 1500);
    }

    function bindEvents(c) {
        var K = C();
        c.on(K.PushCodes.MsgWaiting, function () { drainMessages(); });
        c.on(K.PushCodes.SendConfirmed, function (d) { onAck(d.ackCode, d.roundTrip); });
        c.on(K.PushCodes.Advert, contactsChanged);
        c.on(K.PushCodes.PathUpdated, contactsChanged);
        c.on(K.PushCodes.NewAdvert, function (x) {
            var pk = hex(x.publicKey);
            D.discovered[pk.slice(0, 12)] = {
                pk: pk, pk12: pk.slice(0, 12), name: x.advName, type: x.type,
                last_advert: x.lastAdvert ? iso(x.lastAdvert * 1000) : null,
                path_len: x.outPathLen < 0 || x.outPathLen === 255 ? -1 : (x.outPathLen & 63),
                lat: x.advLat ? x.advLat / 1e6 : null, lon: x.advLon ? x.advLon / 1e6 : null,
                raw: { flags: x.flags, outPathLen: x.outPathLen, outPath: hex(x.outPath), lastAdvert: x.lastAdvert, advLat: x.advLat, advLon: x.advLon }
            };
        });
        c.on(K.PushCodes.LogRxData, onRxLog);
        c.on('rx', onRawFrame);
    }

    // ---------------------------------------------------------------- příjem zpráv
    var draining = false, drainAgain = false;
    function drainMessages() {
        if (draining) { drainAgain = true; return Promise.resolve(); }
        draining = true;
        function next() {
            return cmd(function (c) { return c.syncNextMessage(); }).then(function (r) {
                if (!r) { return null; }
                if (r.contactMessage) { onContactMessage(r.contactMessage); }
                else if (r.channelMessage) { onChannelMessage(r.channelMessage); }
                return next();
            });
        }
        return next().catch(function (e) { console.warn('Načtení zpráv selhalo', e); }).then(function () {
            draining = false;
            if (drainAgain) { drainAgain = false; return drainMessages(); }
        });
    }

    function findDup(conv, sender, body, ts) {
        var list = sorted(function (m) {
            return m.conv === conv && m.dir === 'in' && !m.dup_of && m.body === body && (m.sender || '') === (sender || '') &&
                Math.abs(m.ts - ts) <= DEDUP_WINDOW;
        });
        return list.length ? list[list.length - 1] : null;
    }

    function addIncoming(m) {
        var orig = findDup(m.conv, m.sender, m.body, m.ts);
        if (orig && Math.abs(orig.ts - m.ts) < 2000 && orig.body === m.body && m.type === 'channel' && orig.raw_ts === m.raw_ts) {
            // stejný paket znovu (např. po znovupřipojení) – nezakládat duplicitu
            return orig;
        }
        m.id = newId();
        m.dir = 'in';
        if (orig) {
            m.dup_of = orig.id;
            orig.dups = (orig.dups || 0) + 1;
            saveMsg(orig);
        }
        saveMsg(m);
        return m;
    }

    function onContactMessage(x) {
        var pk = hex(x.pubKeyPrefix).slice(0, 12), c = contactByPk(pk);
        if (x.txtType === 1) {
            // odpověď CLI repeateru (např. na vzdálený advert)
            if (cliWaiters[pk]) { cliWaiters[pk](x.text); }
            else { ui('toast', 'Odpověď uzlu ' + (c ? c.name : pk) + ': ' + x.text, 'secondary'); }
            return;
        }
        var signed = x.txtType === 2 && lastSigned && lastSigned.pk === pk ? lastSigned : null, author = null;
        lastSigned = null;
        if (signed) {
            author = contactFull(signed.author.slice(0, 8));
            x.text = signed.text;
        }
        var m = addIncoming({
            conv: 'd:' + pk, type: 'direct', sender: signed ? (author ? author.name : signed.author) : (c ? c.name : pk),
            author: signed ? signed.author : null, pk: pk, body: x.text,
            hops: x.pathLen === 255 ? null : (x.pathLen & 0x3F), snr: x.snr,
            ts: x.senderTimestamp ? Math.min(now(), x.senderTimestamp * 1000) : now(),
            txt_type: x.txtType
        });
        if (c) { matchIncomingDirect(m, c.publicKey); }
    }

    function onChannelMessage(x) {
        var text = x.text || '', sender = null, i = text.indexOf(': ');
        if (i > 0 && i <= 32) { sender = text.slice(0, i); text = text.slice(i + 2); }
        var m = addIncoming({
            conv: 'c:' + x.channelIdx, type: 'channel', sender: sender, body: text,
            hops: x.pathLen === 255 ? null : (x.pathLen & 0x3F), snr: x.snr,
            ts: x.senderTimestamp ? Math.min(now(), x.senderTimestamp * 1000) : now(),
            raw_ts: x.senderTimestamp
        });
        if (x.senderTimestamp) { matchIncomingChannel(m, x.text || '', x.senderTimestamp); }
    }

    // ---------------------------------------------------------------- odesílání
    function onAck(code) {
        var a = acks[code];
        if (!a) { return; }
        delete acks[code];
        var m = D.msgs[a.id];
        if (!m) { return; }
        m.status = 'delivered'; m.ack = true; m.error = null;
        saveMsg(m);
    }

    // Přímá zpráva: 3 pokusy známou cestou, bez ACK reset cesty a 3 pokusy floodem
    // (kontakt bez známé cesty rovnou 3× floodem). Stejná časová značka, rostoucí číslo pokusu.
    function sendDirect(m, contact) {
        var pub = unhex(contact.publicKey), ts = Math.floor(m.ts / 1000);
        var long = new TextEncoder().encode(m.body).length > 158;   // pokus > 3 firmware ukládá +2 B na konec
        var directLeft = contact.path_len >= 0 ? DM_DIRECT : 0, floodLeft = DM_FLOOD, flood = directLeft === 0;
        function delivered() { return D.msgs[m.id] && D.msgs[m.id].status === 'delivered'; }
        function attempt(n) {
            if (delivered()) { return Promise.resolve(); }
            if (!connected) { m.ack = false; saveMsg(m); return Promise.resolve(); }
            var prep = Promise.resolve();
            if (!flood && directLeft === 0) {
                // známá cesta nefunguje -> zapomenout ji a dál floodem
                flood = true;
                prep = cmd(function (c) {
                    return request(c, function () { return c.sendCommandResetPath(pub); }, [C().ResponseCodes.Ok], [C().ResponseCodes.Err]);
                }).catch(function () { /* i tak zkusit flood */ });
            }
            return prep.then(function () {
                return cmd(function (c) {
                    return request(c, function () { return c.sendCommandSendTxtMsg(C().TxtTypes.Plain, long ? Math.min(n, 3) : n, ts, pub, m.body); },
                        [C().ResponseCodes.Sent], [C().ResponseCodes.Err]);
                });
            }).then(function (r) {
                acks[r.expectedAckCrc] = { id: m.id, attempt: n };
                if (flood) { floodLeft--; } else { directLeft--; }
                if (!delivered()) { m.status = 'sent'; m.tries = n + 1; m.flood = flood || r.result === 1; saveMsg(m); }
                var wait = Math.max(8000, (r.estTimeout || 0) * 1.25 + 2000);
                return sleep(wait).then(function () {
                    if (delivered()) { return; }
                    if (directLeft > 0 || floodLeft > 0) { return attempt(n + 1); }
                    m.ack = false; saveMsg(m);
                });
            });
        }
        return attempt(0).then(function () { if (contact.path_len >= 0 && flood) { contactsChanged(); } });
    }


    // Region (flood scope) kanálu: klíč = prvních 16 B sha256("#název"); '*' = vynutit bez regionu.
    // Nastaví se jen pro jedno odeslání a pak se vrátí výchozí companionu (jako v integraci HA).
    function scopeFrame(scope) {
        if (scope === '*') { return Promise.resolve(new Uint8Array([C().CommandCodes.SetFloodScope, 1])); }
        if (!scope) { var z = new Uint8Array(18); z[0] = C().CommandCodes.SetFloodScope; return Promise.resolve(z); } // nulový klíč = výchozí
        return crypto.subtle.digest('SHA-256', new TextEncoder().encode('#' + scope)).then(function (h) {
            var f = new Uint8Array(18);
            f[0] = C().CommandCodes.SetFloodScope; f[1] = 0; f.set(new Uint8Array(h).slice(0, 16), 2);
            return f;
        });
    }
    function setScope(c, scope) {
        return scopeFrame(scope).then(function (f) {
            return request(c, function () { return c.sendToRadioFrame(f); }, [C().ResponseCodes.Ok], [C().ResponseCodes.Err]);
        });
    }

    function sendChannel(m, idx) {
        var ts = Math.floor(m.ts / 1000);
        var scope = ((D.settings.convs['c:' + idx] || {}).scope) || null;
        expectOutgoingChannel(m, idx, ts);
        return cmd(function (c) {
            var send = function () {
                return request(c, function () { return c.sendCommandSendChannelTxtMsg(C().TxtTypes.Plain, idx, ts, m.body); },
                    [C().ResponseCodes.Ok], [C().ResponseCodes.Err]);
            };
            if (!scope) { return send(); }
            return setScope(c, scope).catch(function () {
                throw err('Companion nepodporuje region (flood scope) – aktualizuj firmware, nebo region u kanálu zruš.');
            }).then(function () {
                return send().then(function (r) { return setScope(c, null).catch(function () {}).then(function () { return r; }); },
                    function (e) { return setScope(c, null).catch(function () {}).then(function () { throw e; }); });
            });
        }).then(function () { m.status = 'sent'; saveMsg(m); scheduleHeard(m); });
    }

    function send(p) {
        var conv = String(p.conv || ''), text = String(p.text || '').trim();
        if (!validConv(conv)) { throw err('Neplatná konverzace'); }
        if (!text) { throw err('Prázdná zpráva'); }
        if (Array.from(text).length > MAX_LEN) { throw err('Zpráva je příliš dlouhá'); }
        var bytes = new TextEncoder().encode(text).length;
        var limit = conv.charAt(0) === 'c' ? 160 - new TextEncoder().encode(((D.self && D.self.name) || '') + ': ').length : 160;
        if (bytes > limit) { throw err('Zpráva má ' + bytes + ' B, limit je ' + limit + ' B.'); }
        if (!connected) { throw err('Companion není připojen – připoj ho přes Bluetooth.'); }
        var parts = conv.split(':'), contact = null;
        if (parts[0] === 'd') {
            contact = contactByPk(parts[1]);
            if (!contact) { throw err('Kontakt není v companionu'); }
        }
        var m = {
            id: newId(), conv: conv, dir: 'out', type: parts[0] === 'c' ? 'channel' : 'direct',
            sender: D.self ? D.self.name : null, pk: contact ? contact.pk : null, body: text,
            ts: now(), status: 'sent', repeaters: 0, ack: null
        };
        var orig = p.resend_of ? D.msgs[p.resend_of] : null;
        if (orig && orig.conv === conv && orig.dir === 'out') {
            var root = orig.dup_of ? D.msgs[orig.dup_of] || orig : orig;
            m.dup_of = root.id;
            root.dups = (root.dups || 0) + 1;
            saveMsg(root);
        }
        saveMsg(m);
        (parts[0] === 'c' ? sendChannel(m, +parts[1]) : sendDirect(m, contact)).catch(function (e) {
            m.status = 'failed'; m.error = e.message || 'Odeslání selhalo';
            saveMsg(m);
        });
        return { ok: true };
    }

    // ---------------------------------------------------------------- nastavení (vzhled, oblíbené, retence)
    function saveSettings() { kvSave('settings'); }
    function settingsOut() { return { convs: D.settings.convs, default_bg: D.settings.default_bg, retention: D.settings.retention || 0 }; }

    function validBg(bg) {
        if (bg == null) { return null; }
        if (bg.type === 'image') { throw err('Vlastní obrázky na pozadí nejsou v režimu Bluetooth k dispozici.'); }
        if (bg.type === 'pattern' && PATTERNS.indexOf(bg.value) < 0) { throw err('Neznámý vzor'); }
        return bg;
    }

    // ---------------------------------------------------------------- kanály
    function sha256(str) {
        return crypto.subtle.digest('SHA-256', new TextEncoder().encode(str)).then(function (b) { return new Uint8Array(b); });
    }

    // ---------------------------------------------------------------- ukládání na server (přihlášený uživatel)
    // Zprávy a nastavení companionu se zrcadlí do DB na serveru, aby historie přežila
    // vymazání prohlížeče a byla vidět i v jiném prohlížeči (i bez připojeného companionu).
    var sync = (function () {
        var qMsgs = {}, qDel = {}, qKv = {}, timer = null, cursor = '', pulling = false;
        function serverApi(action, data, method) {
            return $.ajax({
                url: 'api.php?action=' + action + (method === 'POST' ? '' : '&' + $.param(data || {})),
                method: method || 'GET', dataType: 'json',
                contentType: method === 'POST' ? 'application/json' : undefined,
                data: method === 'POST' ? JSON.stringify(data || {}) : undefined,
                headers: method === 'POST' ? { 'X-CSRF-Token': CSRF } : {}
            });
        }
        function schedule() {
            if (!SERVER || !D.dev) { return; }
            clearTimeout(timer);
            timer = setTimeout(flush, 1500);
        }
        function strip(m) {
            var o = {};
            Object.keys(m).forEach(function (k) { if (k !== 'upd') { o[k] = m[k]; } });
            return o;
        }
        function flush() {
            if (!SERVER || !D.dev) { return; }
            var msgs = Object.keys(qMsgs).slice(0, 300), dels = Object.keys(qDel), kvs = Object.keys(qKv);
            if (!msgs.length && !dels.length && !kvs.length) { return; }
            var body = { device: D.dev.pk, name: D.dev.name || null, messages: [], deleted: dels.map(Number), kv: {} };
            msgs.forEach(function (id) { if (D.msgs[id]) { body.messages.push(strip(D.msgs[id])); } delete qMsgs[id]; });
            dels.forEach(function (id) { delete qDel[id]; });
            kvs.forEach(function (k) { body.kv[k] = qKv[k]; delete qKv[k]; });
            serverApi('ble_push', body, 'POST').fail(function (xhr) {
                // nepovedlo se – vrátit do fronty a zkusit později
                body.messages.forEach(function (m) { qMsgs[m.id] = true; });
                body.deleted.forEach(function (id) { qDel[id] = true; });
                Object.keys(body.kv).forEach(function (k) { if (!(k in qKv)) { qKv[k] = body.kv[k]; } });
                if (xhr.status === 401) { SERVER = false; return; }
                setTimeout(schedule, 15000);
            }).done(function () { if (Object.keys(qMsgs).length) { schedule(); } });
        }
        // stažení změn ze serveru (jiný prohlížeč / starší historie)
        function pull() {
            if (!SERVER || !D.dev || pulling) { return Promise.resolve(); }
            pulling = true;
            var dev = D.dev.pk;
            if (cursor === '') { cursor = lsGet('mcble.cursor.' + dev, ''); }
            return Promise.resolve(serverApi('ble_pull', { device: dev, since: cursor })).then(function (r) {
                if (!D.dev || D.dev.pk !== dev) { return; }
                var changed = false;
                (r.messages || []).forEach(function (m) {
                    var cur = D.msgs[m.id];
                    if (cur && (cur.mt || 0) >= (m.mt || 0)) { return; }
                    D.msgs[m.id] = m;
                    if (m.id >= D.nextId) { D.nextId = m.id + 1; }
                    saveMsgLocal(m);
                    changed = true;
                });
                (r.deleted || []).forEach(function (id) {
                    if (D.msgs[id]) { delete D.msgs[id]; store.del('messages', id); changed = true; }
                });
                Object.keys(r.kv || {}).forEach(function (k) {
                    // ze serveru jen to, co lokálně nemáme čerstvější (kontakty/kanály bere připojený companion)
                    if ((k === 'contacts' || k === 'channels' || k === 'self') && connected) { return; }
                    if (SYNC_KV.indexOf(k) < 0) { return; }
                    D[k] = r.kv[k];
                    store.put('kv', { k: k, v: D[k] });
                    changed = true;
                });
                cursor = r.cursor || cursor;
                lsSet('mcble.cursor.' + dev, cursor);
                if (changed) { notify(); ui('reload'); }
                if (r.more) { setTimeout(function () { pull(); }, 50); }
            }).catch(function (xhr) {
                if (xhr && xhr.status === 401) { SERVER = false; }
            }).then(function () { pulling = false; });
        }
        function saveMsgLocal(m) {
            m.upd = D.seq++;
            store.put('messages', m);
        }
        setInterval(function () { if (!document.hidden) { pull(); } }, 30000);
        return {
            msg: function (m) { if (SERVER) { qMsgs[m.id] = true; delete qDel[m.id]; schedule(); } },
            del: function (id) { if (SERVER) { qDel[id] = true; delete qMsgs[id]; schedule(); } },
            kv: function (k, v) { if (SERVER) { qKv[k] = v; schedule(); } },
            pull: pull,
            reset: function () { cursor = ''; qMsgs = {}; qDel = {}; qKv = {}; },
            pushAll: function () {
                if (!SERVER) { return; }
                Object.keys(D.msgs).forEach(function (id) { qMsgs[id] = true; });
                SYNC_KV.forEach(function (k) { if (D[k] != null) { qKv[k] = D[k]; } });
                schedule();
            },
            devices: function () { return SERVER ? Promise.resolve(serverApi('ble_devices')) : Promise.resolve({ devices: [] }); },
            api: serverApi
        };
    })();

    // ---------------------------------------------------------------- RX log (cesty paketů)
    // Companion hlásí každý přijatý paket (LogRxData). Kanálové zprávy umíme přesně spárovat:
    // zašifrujeme si začátek zprávy klíčem kanálu (AES-128 ECB) a porovnáme se šifrovaným payloadem.
    var rx = { pending: {}, byKey: {}, expect: [] };
    var aesKeys = {};

    function aesKey(secretHex) {
        if (!aesKeys[secretHex]) {
            aesKeys[secretHex] = crypto.subtle.importKey('raw', unhex(secretHex).slice(0, 16), { name: 'AES-CBC' }, false, ['encrypt']);
        }
        return aesKeys[secretHex];
    }

    // první 16B blok šifrovaného textu: ECB = CBC s nulovým IV, první blok výstupu
    function cipherHead(secretHex, ts, flags, text) {
        var plain = new Uint8Array(16), t = new TextEncoder().encode(text);
        plain[0] = ts & 0xff; plain[1] = (ts >>> 8) & 0xff; plain[2] = (ts >>> 16) & 0xff; plain[3] = (ts >>> 24) & 0xff;
        plain[4] = flags;
        plain.set(t.slice(0, 11), 5);
        return aesKey(secretHex).then(function (k) {
            return crypto.subtle.encrypt({ name: 'AES-CBC', iv: new Uint8Array(16) }, k, plain);
        }).then(function (buf) { return hex(new Uint8Array(buf).slice(0, 16)); });
    }

    function channelBySecretHash(h) {
        for (var i = 0; i < D.channels.length; i++) { if (D.channels[i].hash === h) { return D.channels[i]; } }
        return null;
    }

    function rxEntry(pkt, snr, rssi) {
        var size = LIB.Packet.extractPathHashSize(pkt.pathLen), count = LIB.Packet.extractPathHashCount(pkt.pathLen);
        return {
            snr: snr, rssi: rssi, path: hex(pkt.path), path_len: count, path_hash_size: size,
            route_typename: pkt.route_type_string || null, t: now()
        };
    }

    function attachRx(msgId, entries) {
        var m = D.msgs[msgId];
        if (!m) { return; }
        m.rx = (m.rx || []).concat(entries.map(function (e) {
            return { snr: e.snr, rssi: e.rssi, path: e.path, path_len: e.path_len, path_hash_size: e.path_hash_size, route_typename: e.route_typename };
        })).slice(0, 40);
        if (m.dir === 'out' && m.type === 'channel') { m.repeaters = m.rx.length; }
        if (m.dir === 'in' && m.rssi == null && entries.length) { m.rssi = entries[0].rssi; }
        saveMsg(m);
    }

    function onRxLog(d) {
        var pkt;
        try { pkt = LIB.Packet.fromBytes(d.raw); } catch (e) { return; }
        var pt = pkt.payload_type, payload = pkt.payload;
        if (pt !== 5 && pt !== 2) { return; }        // GRP_TXT a TXT_MSG
        var key = pt + ':' + hex(payload), e = rxEntry(pkt, d.lastSnr, d.lastRssi);
        if (rx.byKey[key]) { attachRx(rx.byKey[key], [e]); return; }
        var p = rx.pending[key];
        if (!p) {
            p = rx.pending[key] = { t: now(), type: pt, entries: [], payload: payload,
                head: pt === 5 ? hex(payload.slice(3, 19)) : null, chHash: payload[0], src: payload[1], dst: payload[0] };
        }
        p.entries.push(e);
        // čekající odchozí zpráva do kanálu?
        if (pt === 5) {
            for (var i = 0; i < rx.expect.length; i++) {
                if (rx.expect[i].head === p.head) { claim(key, rx.expect[i].id); rx.expect.splice(i, 1); break; }
            }
        }
    }

    function claim(key, msgId) {
        var p = rx.pending[key];
        rx.byKey[key] = msgId;
        if (p) { delete rx.pending[key]; attachRx(msgId, p.entries); }
    }

    setInterval(function () {
        var t = now();
        Object.keys(rx.pending).forEach(function (k) { if (t - rx.pending[k].t > 180000) { delete rx.pending[k]; } });
        rx.expect = rx.expect.filter(function (x) { return t - x.t < 180000; });
        var keys = Object.keys(rx.byKey);
        if (keys.length > 500) { keys.slice(0, keys.length - 500).forEach(function (k) { delete rx.byKey[k]; }); }
    }, 60000);

    // příchozí kanálová zpráva -> najít její pakety v RX logu
    function matchIncomingChannel(m, rawText, ts) {
        var ch = null;
        D.channels.forEach(function (c) { if ('c:' + c.idx === m.conv) { ch = c; } });
        if (!ch || !ch.secret) { return; }
        var tries = [0, 1, 2, 3].map(function (a) { return cipherHead(ch.secret, ts, a, rawText); });
        Promise.all(tries).then(function (heads) {
            Object.keys(rx.pending).forEach(function (k) {
                var p = rx.pending[k];
                if (p.type === 5 && heads.indexOf(p.head) >= 0) { claim(k, m.id); }
            });
        }).catch(function () { /* WebCrypto nedostupné */ });
    }

    // odeslaná kanálová zpráva -> její opakování repeatery = zachycené echo
    function expectOutgoingChannel(m, idx, ts) {
        var ch = null;
        D.channels.forEach(function (c) { if (c.idx === idx) { ch = c; } });
        if (!ch || !ch.secret) { return; }
        var text = ((D.self && D.self.name) || '') + ': ' + m.body;
        cipherHead(ch.secret, ts, 0, text).then(function (head) {
            var found = null;
            Object.keys(rx.pending).forEach(function (k) { if (rx.pending[k].type === 5 && rx.pending[k].head === head) { found = k; } });
            if (found) { claim(found, m.id); } else { rx.expect.push({ head: head, id: m.id, t: now() }); }
        }).catch(function () {});
    }

    // příchozí DM: nejnovější TXT_MSG od odesílatele (hash 1. bajtu klíče) pro nás za posledních 15 s
    function matchIncomingDirect(m, pkHex) {
        var src = parseInt(pkHex.slice(0, 2), 16), dst = D.self ? parseInt(D.self.publicKey.slice(0, 2), 16) : null, best = null;
        Object.keys(rx.pending).forEach(function (k) {
            var p = rx.pending[k];
            if (p.type === 2 && p.src === src && (dst === null || p.dst === dst) && now() - p.t < 15000 && (!best || p.t > rx.pending[best].t)) { best = k; }
        });
        if (best) { claim(best, m.id); }
    }

    // ---------------------------------------------------------------- dotazy na uzly (login, stav, trace, cesta)
    // Odeslání příkazu přes frontu (čeká na „Sent“), samotná odpověď z mesh sítě se čeká mimo frontu.
    function sentThenWait(sendFn, waitFn, minWait) {
        var waiter = waitFn();
        return cmd(function (c) {
            return request(c, function () { return sendFn(c); }, [C().ResponseCodes.Sent], [C().ResponseCodes.Err]);
        }).then(function (r) {
            var t = Math.max(minWait || 10000, (r.estTimeout || 0) + 5000);
            return Promise.race([waiter.promise, sleep(t).then(function () { waiter.cancel(); throw new Error('timeout'); })]);
        }, function (e) { waiter.cancel(); throw e; });
    }

    // čekání na push z companionu; filter vrací data nebo undefined
    function waitPush(code, filter) {
        var h, cancelled = false;
        var promise = new Promise(function (resolve, reject) {
            h = function (d) {
                var v = filter(d);
                if (v === undefined) { return; }
                conn && conn.off(code, h);
                if (v instanceof Error) { reject(v); } else { resolve(v); }
            };
            if (conn) { conn.on(code, h); }
        });
        return { promise: promise, cancel: function () { if (!cancelled && conn) { cancelled = true; conn.off(code, h); } } };
    }

    // syrové rámce, které knihovna nezná (login fail 0x86, odpověď na zjištění cesty 0x8D)
    var rawWaiters = [];
    function waitRaw(code, filter) {
        var w = { code: code };
        w.promise = new Promise(function (resolve) { w.fn = function (frame) { var v = filter(frame); if (v !== undefined) { resolve(v); return true; } return false; }; });
        rawWaiters.push(w);
        return { promise: w.promise, cancel: function () { rawWaiters = rawWaiters.filter(function (x) { return x !== w; }); } };
    }
    // podepsaný text (txt_type 2 = příspěvek z room serveru): knihovna z něj neumí vytáhnout autora
    var lastSigned = null;
    function parseSigned(f) {
        var o = f[0] === 16 ? 4 : 1;                     // V3 má navíc SNR + 2 rezervované bajty
        if (f.length < o + 12) { return null; }
        var pk = hex(f.slice(o, o + 6)); o += 6;
        o += 1;                                          // path_len
        var txt = f[o++];
        if (txt !== 2) { return null; }
        o += 4;                                          // timestamp
        var sig = hex(f.slice(o, o + 4)); o += 4;
        return { pk: pk.slice(0, 12), author: sig, text: new TextDecoder().decode(f.slice(o)) };
    }

    function onRawFrame(frame) {
        if (!frame || !frame.length) { return; }
        if (frame[0] === 7 || frame[0] === 16) { lastSigned = parseSigned(frame); }
        rawWaiters = rawWaiters.filter(function (w) { return !(w.code === frame[0] && w.fn(frame)); });
    }

    function contactFull(pk12) {
        var c = contactByPk(String(pk12 || '').slice(0, 12));
        if (!c) {
            for (var i = 0; i < D.contacts.length; i++) { if (D.contacts[i].pk.indexOf(pk12) === 0) { c = D.contacts[i]; break; } }
        }
        return c;
    }

    // uzel, který companion nezná, nejdřív přidat z „slyšených“ (advert)
    function ensureContact(pk12) {
        var c = contactFull(pk12);
        if (c) { return Promise.resolve({ contact: c, added: false }); }
        var d = D.discovered[String(pk12).slice(0, 12)];
        if (!d) { return Promise.reject(err('Uzel není v kontaktech companionu')); }
        return addDiscovered(d).then(function () {
            var c2 = contactFull(pk12);
            if (!c2) { throw err('Uzel se nepodařilo přidat do kontaktů companionu'); }
            return { contact: c2, added: true };
        });
    }

    function addDiscovered(d) {
        var path = new Uint8Array(64);
        if (d.raw.outPath) { path.set(unhex(d.raw.outPath).slice(0, 64)); }
        return cmd(function (c) {
            return c.addOrUpdateContact(unhex(d.pk), d.type, d.raw.flags || 0, d.raw.outPathLen, path, d.name || '', d.raw.lastAdvert || 0, d.raw.advLat || 0, d.raw.advLon || 0);
        }).then(syncContacts).then(function () { delete D.discovered[d.pk12]; });
    }

    function login(contact, pw) {
        var pub = unhex(contact.publicKey), pre = hex(pub.slice(0, 6));
        var fail = waitRaw(0x86, function (f) { return hex(f.slice(2, 8)) === pre ? 'fail' : undefined; });
        return sentThenWait(function (c) { return c.sendCommandSendLogin(pub, pw); }, function () {
            var w = waitPush(C().PushCodes.LoginSuccess, function (d) { return hex(d.pubKeyPrefix) === pre ? d : undefined; });
            var both = Promise.race([w.promise, fail.promise.then(function () { throw new Error('login_failed'); })]);
            return { promise: both, cancel: function () { w.cancel(); fail.cancel(); } };
        }, 15000).then(function (d) {
            fail.cancel();
            return { admin: (d.reserved & 1) === 1 };
        }, function (e) {
            fail.cancel();
            var pwHint = pw === '' ? ' Bez hesla to projde jen u uzlu, který povoluje hosty (nebo má companion v ACL).' : '';
            if (e.message === 'login_failed') { throw err('Uzel přihlášení odmítl – špatné heslo.' + pwHint); }
            if (e.message === 'timeout') { throw err('Uzel na přihlášení neodpověděl – špatné heslo, nebo je mimo dosah.' + pwHint); }
            throw e;
        });
    }

    function parseStatus(b) {
        var v = new DataView(b.buffer, b.byteOffset, b.byteLength);
        function u16(o) { return o + 2 <= b.length ? v.getUint16(o, true) : null; }
        function i16(o) { return o + 2 <= b.length ? v.getInt16(o, true) : null; }
        function u32(o) { return o + 4 <= b.length ? v.getUint32(o, true) : null; }
        return {
            bat: u16(0), tx_queue_len: u16(2), noise_floor: i16(4), last_rssi: i16(6), nb_recv: u32(8), nb_sent: u32(12),
            airtime: u32(16), uptime: u32(20), sent_flood: u32(24), sent_direct: u32(28), recv_flood: u32(32), recv_direct: u32(36),
            full_evts: u16(40), last_snr: i16(42) != null ? i16(42) / 4 : null, direct_dups: u16(44), flood_dups: u16(46),
            rx_airtime: u32(48), recv_errors: u32(52)
        };
    }

    function remote(p, wantStatus) {
        var pw = String(p.password || '');
        if (pw.length > 64 || /[\x00-\x1f]/.test(pw)) { throw err('Neplatné heslo'); }
        var cmdName = String(p.cmd || ''), plan = wantStatus ? null : remoteCommands(cmdName, p.arg);
        if (!connected) { throw err('Companion není připojen'); }
        var info = null, admin = null;
        return ensureContact(String(p.pk || '')).then(function (r) {
            info = r;
            return login(r.contact, pw).catch(function (e) { if (e.responseJSON) { e.responseJSON.added = info.added; } throw e; });
        }).then(function (l) {
            admin = l.admin;
            var pub = unhex(info.contact.publicKey), pre = hex(pub.slice(0, 6));
            if (wantStatus) {
                return sentThenWait(function (c) { return c.sendCommandSendStatusReq(pub); }, function () {
                    return waitPush(C().PushCodes.StatusResponse, function (d) { return hex(d.pubKeyPrefix) === pre ? d : undefined; });
                }, 15000).then(function (d) {
                    return { ok: true, status: parseStatus(d.statusData), admin: admin, added: info.added, at: iso(now()) };
                }, function (e) {
                    if (e.message === 'timeout') { throw err('Uzel na žádost o stav neodpověděl (mimo dosah nebo vytížená síť).'); }
                    throw e;
                });
            }
            var replies = [];
            return plan.cli.reduce(function (chain, line) {
                return chain.then(function () {
                    var ts = Math.floor(now() / 1000), reply = waitCli(info.contact.pk, cmdName === 'reboot' ? 3000 : 15000);
                    return cmd(function (c) {
                        return request(c, function () { return c.sendCommandSendTxtMsg(C().TxtTypes.CliData, 0, ts, pub, line); },
                            [C().ResponseCodes.Sent], [C().ResponseCodes.Err]);
                    }).then(function () { return reply; }).then(function (text) { if (text) { replies.push(text); } });
                });
            }, Promise.resolve()).then(function () {
                return { ok: true, what: plan.what, admin: admin, added: info.added, reply: replies.length ? replies.join(' · ') : null };
            });
        });
    }

    // povolené vzdálené příkazy (stejné jako na serveru)
    function remoteCommands(c, arg) {
        var simple = { 'advert': 'flood advert', 'advert.zerohop': 'lokální advert', 'ver': 'verze firmwaru', 'clock sync': 'synchronizace času', 'reboot': 'restart' };
        if (simple[c]) { return { what: simple[c], cli: [c] }; }
        if (c === 'name') {
            var name = String(arg || '').trim();
            if (!name || new TextEncoder().encode(name).length > 31 || /[\x00-\x1f]/.test(name)) { throw err('Jméno musí mít 1–31 bajtů'); }
            return { what: 'přejmenování (' + name + ')', cli: ['set name ' + name] };
        }
        if (c === 'latlon') {
            var la = arg && +arg.lat, lo = arg && +arg.lon;
            if (!isFinite(la) || !isFinite(lo) || Math.abs(la) > 90 || Math.abs(lo) > 180 || arg.lat === '' || arg.lon === '') { throw err('Neplatná poloha'); }
            return { what: 'nastavení polohy', cli: ['set lat ' + la.toFixed(6), 'set lon ' + lo.toFixed(6)] };
        }
        throw err('Neplatný příkaz');
    }

    // odpovědi CLI repeaterů (txt_type 1) se do chatu neukládají
    var cliWaiters = {};
    function waitCli(pk12, timeout) {
        return new Promise(function (resolve) {
            var t = setTimeout(function () { delete cliWaiters[pk12]; resolve(null); }, timeout);
            cliWaiters[pk12] = function (text) { clearTimeout(t); delete cliWaiters[pk12]; resolve(text); };
        });
    }

    function pathDiscovery(contact) {
        var pub = unhex(contact.publicKey), pre = hex(pub.slice(0, 6));
        return sentThenWait(function (c) {
            var w = new Uint8Array(34); w[0] = 0x34; w[1] = 0; w.set(pub, 2);
            return c.sendToRadioFrame(w);
        }, function () {
            return waitRaw(0x8D, function (f) {
                if (hex(f.slice(2, 8)) !== pre) { return undefined; }
                var o = 8, opl = f[o++], osz = (opl >> 6) + 1, on = opl & 63;
                var out = hex(f.slice(o, o + on * osz)); o += on * osz;
                var ipl = f[o++] || 0, isz = (ipl >> 6) + 1, inn = ipl & 63;
                return { out_path_len: on, out_path_hash_len: osz, out_path: out, in_path_len: inn, in_path: hex(f.slice(o, o + inn * isz)) };
            });
        }, 15000).catch(function (e) {
            if (e.message === 'timeout') { throw err('Nepodařilo se najít cestu k uzlu (vypršel čas).'); }
            throw e;
        });
    }

    function trace(contact) {
        var start = now();
        var prep = contact.path_len >= 0 ? Promise.resolve({ hops: contact.path_len, path: contact.path || '', size: contact.path_size || 1 })
            : pathDiscovery(contact).then(function (r) { return { hops: r.out_path_len, path: r.out_path, size: r.out_path_hash_len }; });
        return prep.then(function (pi) {
            var out = [];
            for (var i = 0; i < pi.hops; i++) { out.push(pi.path.substr(i * pi.size * 2, 2)); }
            var full = out.join('') + contact.publicKey.slice(0, 2) + out.slice().reverse().join('');
            var tag = Math.floor(Math.random() * 0xffffffff) >>> 0;
            start = now();
            return sentThenWait(function (c) { return c.sendCommandSendTracePath(tag, 0, unhex(full)); }, function () {
                return waitPush(C().PushCodes.TraceData, function (d) { return d.tag === tag ? d : undefined; });
            }, 20000);
        }).then(function (d) {
            var path = [];
            for (var i = 0; i < d.pathLen; i++) {
                var sn = d.pathSnrs[i];
                path.push({ hash: hex([d.pathHashes[i]]), snr: (sn > 127 ? sn - 256 : sn) / 4 });
            }
            path.push({ snr: d.lastSnr });
            return { trace: { hops: d.pathLen, path: path, round_trip_ms: now() - start, final_snr: d.lastSnr } };
        }, function (e) {
            if (e && e.responseJSON) { return { trace: null, error: 'path_discovery_timeout' }; }
            if (e && e.message === 'timeout') { return { trace: null, error: 'timeout' }; }
            throw e;
        });
    }

    // ---------------------------------------------------------------- analyzer (jen přihlášený – dotaz jde přes server)
    var HEARD_SCHEDULE = [5, 30, 120, 600];
    function heardCheck(m, final) {
        var ch = null;
        D.channels.forEach(function (c) { if ('c:' + c.idx === m.conv) { ch = c; } });
        if (!ch) { return Promise.resolve(m); }
        var used = [], siblings = [];
        Object.values(D.msgs).forEach(function (x) {
            if (x.id === m.id) { return; }
            if (x.heard && x.heard.hash) { used.push(x.heard.hash); }
            // ostatní odeslání stejného textu – paket patří pokusu s nejbližším časem
            if (x.dir === 'out' && x.conv === m.conv && x.body === m.body && Math.abs(x.ts - m.ts) < 600000) { siblings.push(Math.floor(x.ts / 1000)); }
        });
        var q = {
            device: D.dev ? D.dev.pk : '', channel: ch.name, body: m.body, ts: Math.floor(m.ts / 1000), sender: (D.self && D.self.name) || '',
            hash: m.heard && m.heard.hash || null, exclude: used.slice(-200), final: !!final, siblings: siblings.slice(0, 20)
        };
        // přihlášený jde přes api.php, nepřihlášený přes veřejný (omezený) endpoint
        var rq = SERVER ? sync.api('ble_heard', q, 'POST') : $.ajax({
            url: 'ble/heard.php', method: 'POST', dataType: 'json', contentType: 'application/json',
            data: JSON.stringify(q), headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        return Promise.resolve(rq).then(function (r) {
            var cur = D.msgs[m.id];
            if (!cur) { return m; }
            cur.heard = r.heard;
            cur.heard.attempts = ((m.heard && m.heard.attempts) || 0) + 1;
            cur.heard.checked = iso(now());
            cur.heard_observers = r.observers || [];
            saveMsg(cur);
            return cur;
        });
    }
    function scheduleHeard(m) {
        if (!ANALYZER) { return; }
        HEARD_SCHEDULE.forEach(function (sec, i) {
            setTimeout(function () {
                var cur = D.msgs[m.id];
                if (!cur || (cur.heard && cur.heard.state === 'heard' && i < HEARD_SCHEDULE.length - 1) || (cur.heard && cur.heard.state === 'unsupported')) { return; }
                heardCheck(cur, i === HEARD_SCHEDULE.length - 1).catch(function () {});
            }, sec * 1000);
        });
    }

    // ---------------------------------------------------------------- API
    var handlers = {
        'GET init': function () {
            return {
                state: state(), conversations: conversations(), cursor: String(D.seq - 1), max_len: MAX_LEN,
                settings: settingsOut(), patterns: PATTERNS, analyzer: ANALYZER, home: home(), self_name: D.self ? D.self.name : null
            };
        },
        'GET state': function () { return { state: state() }; },
        'GET conversations': function () { return { conversations: conversations() }; },
        'GET settings': function () { return { settings: settingsOut() }; },
        'GET messages': function (p) {
            if (!validConv(p.conv)) { throw err('Neplatná konverzace'); }
            var hide = (p.dups || 'hide') !== 'show', before = +p.before || 0, limit = Math.max(1, Math.min(200, +p.limit || 50));
            var list = sorted(function (m) { return m.conv === p.conv && (!hide || !m.dup_of) && (!before || m.id < before); });
            return { messages: withGroups(list.slice(-limit)) };
        },
        'GET poll': function (p) {
            var cursor = +p.cursor || 0;
            if (lastPollKey === null) { lastPollKey = stateKey(); }
            return new Promise(function (resolve) {
                var timer = null;
                function finish(list) {
                    clearTimeout(timer);
                    waiters = waiters.filter(function (w) { return w !== check; });
                    lastPollKey = stateKey();
                    resolve({ messages: withGroups(list), cursor: String(D.seq - 1), state: state() });
                }
                function check() {
                    var changed = sorted(function (m) { return m.upd > cursor; });
                    if (changed.length || stateKey() !== lastPollKey) {
                        // u změněného opakovaného odeslání přidat i původní zprávu (souhrn skupiny)
                        var ids = {};
                        changed.forEach(function (m) { ids[m.id] = true; });
                        changed.slice().forEach(function (m) {
                            if (m.dir === 'out' && m.dup_of && !ids[m.dup_of] && D.msgs[m.dup_of]) { ids[m.dup_of] = true; changed.push(D.msgs[m.dup_of]); }
                        });
                        finish(changed);
                        return;
                    }
                    waiters.push(check);
                }
                timer = setTimeout(function () { finish([]); }, POLL_TIMEOUT);
                check();
            });
        },
        'GET nicks': function (p) {
            var map = {};
            sorted(function (m) { return m.conv === p.conv && m.dir === 'in' && !m.dup_of && m.sender; }).forEach(function (m) {
                var n = map[m.sender] || (map[m.sender] = { nick: m.sender, last: 0, count: 0 });
                n.last = Math.max(n.last, m.ts); n.count++;
            });
            return { nicks: Object.values(map).sort(function (a, b) { return b.last - a.last; }).slice(0, 200)
                .map(function (n) { return { nick: n.nick, last: iso(n.last), count: n.count }; }) };
        },
        'GET search': function (p) {
            var q = String(p.q || '').trim().toLowerCase();
            if (q.length < 2) { return { messages: [] }; }
            var list = sorted(function (m) {
                return !m.dup_of && ((m.body || '').toLowerCase().indexOf(q) >= 0 || (m.sender || '').toLowerCase().indexOf(q) >= 0);
            }).reverse().slice(0, 100);
            return { messages: list.map(fmt) };
        },
        'GET copies': function (p) {
            var m = D.msgs[+p.id];
            if (!m) { return { messages: [] }; }
            var root = m.dup_of || m.id;
            return { messages: sorted(function (x) { return x.id === root || x.dup_of === root; }).map(fmt) };
        },
        'POST read': function (p) {
            D.reads[p.conv] = Math.max(D.reads[p.conv] || 0, +p.last_id || 0);
            kvSave('reads');
            return { ok: true };
        },
        'POST send': send,
        'POST message_delete': function (p) { return { deleted: deleteMessage(+p.id) }; },
        'POST conv_delete': function (p) {
            if (!validConv(p.conv)) { throw err('Neplatná konverzace'); }
            var n = 0;
            sorted(function (m) { return m.conv === p.conv; }).forEach(function (m) { removeMsg(m.id); n++; });
            return { deleted: n, conversations: conversations() };
        },
        'POST conv_settings': function (p) {
            if (!validConv(p.conv)) { throw err('Neplatná konverzace'); }
            var cs = Object.assign({ color: null, bg: null, fav: false, retention: null, scope: null }, D.settings.convs[p.conv] || {});
            if ('color' in p) { cs.color = p.color || null; }
            if ('bg' in p) { cs.bg = validBg(p.bg); }
            if ('fav' in p) { cs.fav = !!p.fav; }
            if ('scope' in p) {
                if (p.conv.charAt(0) !== 'c') { throw err('Region jde nastavit jen u kanálu'); }
                var sc = String(p.scope == null ? '' : p.scope).trim().replace(/^#/, '');
                if (sc && sc !== '*' && !/^[\p{L}\p{N}_.\-]{1,30}$/u.test(sc)) { throw err('Neplatný název regionu (písmena, číslice, - _ .)'); }
                cs.scope = sc || null;
            }
            if ('retention' in p) { cs.retention = p.retention === null || p.retention === '' ? null : Math.max(0, +p.retention); }
            if (!cs.color && !cs.bg && !cs.fav && cs.retention == null && !cs.scope) { delete D.settings.convs[p.conv]; } else { D.settings.convs[p.conv] = cs; }
            saveSettings();
            if ('retention' in p) { cleanup(); }
            return { settings: settingsOut() };
        },
        'POST default_bg': function (p) { D.settings.default_bg = validBg(p.bg || null); saveSettings(); return { settings: settingsOut() }; },
        'GET bg_list': function () { return { images: [] }; },
        'POST bg_delete': function () { return { images: [], settings: settingsOut() }; },
        'POST retention': function (p) {
            D.settings.retention = Math.max(0, +p.days || 0); saveSettings();
            return { settings: settingsOut(), deleted: cleanup() };
        },
        'GET devices': function () {
            var comp = [];
            if (D.dev) {
                var sensors = {};
                if (D.battery) {
                    sensors.battery_percentage = { state: String(batteryPct(D.battery)), unit: '%', updated: iso(D.batteryAt) };
                    sensors.bat = { state: String((D.battery / 1000).toFixed(3)), unit: 'V', updated: iso(D.batteryAt) };
                }
                if (D.self && D.self.txPower != null) { sensors.tx_power = { state: String(D.self.txPower), unit: 'dBm' }; }
                if (D.self && D.self.radioFreq) { sensors.frequency = { state: String(D.self.radioFreq / 1000), unit: 'MHz' }; }
                comp.push({ pk: D.dev.pk, name: D.dev.name || D.dev.pk, status: connected ? 'online' : 'odpojeno', sensors: sensors, telemetry: {}, other: {} });
            }
            return { devices: { available: true, devices: [], companions: comp, mqtt: [], updated_at: iso(now()) } };
        },
        'GET discovered': function () {
            var known = {};
            D.contacts.forEach(function (c) { known[c.pk] = true; });
            var nodes = Object.values(D.discovered).filter(function (n) { return !known[n.pk12]; })
                .sort(function (a, b) { return String(b.last_advert).localeCompare(String(a.last_advert)); });
            return { discovered: { available: true, nodes: nodes } };
        },
        'POST advert': function (p) {
            var flood = !!p.flood;
            return cmd(function (c) {
                return request(c, function () { return c.sendCommandSendSelfAdvert(flood ? 1 : 0); }, [C().ResponseCodes.Ok], [C().ResponseCodes.Err]);
            }).then(function () { return { ok: true, flood: flood }; });
        },
        'POST path_reset': function (p) {
            var c0 = contactByPk(String(p.pk || ''));
            if (!c0) { throw err('Kontakt není v companionu'); }
            return cmd(function (c) {
                return request(c, function () { return c.sendCommandResetPath(unhex(c0.publicKey)); }, [C().ResponseCodes.Ok], [C().ResponseCodes.Err]);
            }).then(syncContacts).then(function () { return { ok: true, conversations: conversations() }; });
        },
        'GET channels_info': function () {
            return { slots: D.channelSlots || 0, channels: D.channels.map(function (c) { return { channel_idx: c.idx, name: c.name }; }) };
        },
        'POST channel_add': function (p) {
            var name = String(p.name || '').trim(), secret = String(p.secret || '').trim().toLowerCase();
            if (!name || new TextEncoder().encode(name).length > 31) { throw err('Název kanálu musí mít 1–31 bajtů'); }
            if (secret && !/^[0-9a-f]{32}$/.test(secret)) { throw err('Klíč musí mít 32 hex znaků (16 bajtů)'); }
            if (!secret && name[0] !== '#') { throw err('Kanál bez klíče musí být hashtagový (začínat #)'); }
            var used = {}, max = D.channelSlots || 8, idx = null;
            D.channels.forEach(function (c) { used[c.idx] = true; });
            for (var i = 0; i < max; i++) { if (!used[i]) { idx = i; break; } }
            if (idx === null) { throw err('Companion nemá volný slot pro kanál (obsazeno ' + max + ')'); }
            return (secret ? Promise.resolve(unhex(secret)) : sha256(name).then(function (h) { return h.slice(0, 16); })).then(function (key) {
                return cmd(function (c) { return c.setChannel(idx, name, key); });
            }).then(function () {
                sorted(function (m) { return m.conv === 'c:' + idx; }).forEach(function (m) { removeMsg(m.id); });
                return syncChannels();
            }).then(function () { return { ok: true, idx: idx, conversations: conversations() }; });
        },
        'POST channel_delete': function (p) {
            var idx = +p.idx;
            return cmd(function (c) { return c.deleteChannel(idx); }).then(syncChannels).then(function () {
                sorted(function (m) { return m.conv === 'c:' + idx; }).forEach(function (m) { removeMsg(m.id); });
                delete D.settings.convs['c:' + idx]; saveSettings();
                return { ok: true, conversations: conversations() };
            });
        },
        'GET self_info': function () {
            if (!D.self) { throw err('Companion zatím nebyl připojen.'); }
            return { self: { public_key: D.self.publicKey, name: D.self.name, type: 1, lat: D.self.lat, lon: D.self.lon } };
        },
        'GET ble_settings': function () {
            if (!D.self) { throw err('Companion zatím nebyl připojen.'); }
            return { self: D.self, connected: connected };
        },
        'POST ble_settings': function (p) {
            if (!connected) { throw err('Companion není připojen'); }
            var K = C(), steps = [], enc = new TextEncoder();
            function ok(fn) { return function () { return cmd(function (c) { return request(c, function () { return fn(c); }, [K.ResponseCodes.Ok], [K.ResponseCodes.Err]); }); }; }
            if (p.name != null) {
                var name = String(p.name).trim();
                if (!name || enc.encode(name).length > 31 || /[\x00-\x1f]/.test(name)) { throw err('Jméno musí mít 1–31 bajtů'); }
                steps.push(ok(function (c) { return c.sendCommandSetAdvertName(name); }));
            }
            if (p.lat != null || p.lon != null) {
                var la = +String(p.lat).replace(',', '.'), lo = +String(p.lon).replace(',', '.');
                if (!isFinite(la) || !isFinite(lo) || Math.abs(la) > 90 || Math.abs(lo) > 180) { throw err('Neplatná poloha'); }
                steps.push(ok(function (c) { return c.sendCommandSetAdvertLatLon(Math.round(la * 1e6), Math.round(lo * 1e6)); }));
            }
            if (p.tx != null) {
                var tx = Math.round(+p.tx), max = (D.self && D.self.maxTxPower) || 22;
                if (!isFinite(tx) || tx < -9 || tx > max) { throw err('Výkon musí být −9 až ' + max + ' dBm'); }
                steps.push(ok(function (c) { return c.sendCommandSetTxPower(tx); }));
            }
            if (p.manual != null) {
                steps.push(ok(function (c) { return c.sendCommandSetOtherParams(p.manual ? 1 : 0); }));
            }
            if (p.radio) {
                var f = Math.round(+p.radio.freq * 1000), bw = Math.round(+p.radio.bw * 1000), sf = +p.radio.sf, cr = +p.radio.cr;
                if (!(f >= 150000 && f <= 2500000 && bw >= 7000 && bw <= 500000 && sf >= 5 && sf <= 12 && cr >= 5 && cr <= 8)) {
                    throw err('Neplatné rádiové parametry');
                }
                steps.push(ok(function (c) { return c.sendCommandSetRadioParams(f, bw, sf, cr); }));
            }
            if (!steps.length) { throw err('Nic se nezměnilo'); }
            return steps.reduce(function (ch, st) { return ch.then(st); }, Promise.resolve()).then(function () {
                return cmd(function (c) { return request(c, function () { return c.sendCommandAppStart(); }, [K.ResponseCodes.SelfInfo]); });
            }).then(function (si) {
                D.self.name = si.name; D.self.txPower = si.txPower; D.self.maxTxPower = si.maxTxPower;
                D.self.lat = si.advLat ? si.advLat / 1e6 : null; D.self.lon = si.advLon ? si.advLon / 1e6 : null;
                D.self.radioFreq = si.radioFreq; D.self.radioBw = si.radioBw; D.self.radioSf = si.radioSf; D.self.radioCr = si.radioCr;
                D.self.manualAdd = si.manualAddContacts;
                if (D.dev) { D.dev.name = si.name; lsSet(LS_DEV, D.dev); }
                kvSave('self'); notify(); ui('reload');
                return { ok: true, self: D.self };
            });
        },
        'POST room_login': function (p) {
            var pw = String(p.password || '');
            if (pw.length > 64 || /[\x00-\x1f]/.test(pw)) { throw err('Neplatné heslo'); }
            if (!connected) { throw err('Companion není připojen'); }
            var info;
            return ensureContact(String(p.pk || '')).then(function (r) { info = r; return login(r.contact, pw); })
                .then(function (l) { setTimeout(drainMessages, 3000); return { ok: true, admin: l.admin, added: info.added }; });
        },
        'POST remote_status': function (p) { return remote(p, true); },
        'POST remote_cmd': function (p) { return remote(p, false); },
        'POST trace': function (p) {
            if (!connected) { return { trace: null, error: 'not_connected' }; }
            var c = contactFull(String(p.pk || ''));
            if (!c) { return { trace: null, error: 'contact_not_on_device' }; }
            return trace(c);
        },
        'POST path_discover': function (p) {
            if (!connected) { throw err('Companion není připojen'); }
            var c = contactFull(String(p.pk || ''));
            if (!c) { throw err('Kontakt není v companionu'); }
            return pathDiscovery(c).then(function (r) {
                return syncContacts().then(function () { return { ok: true, result: r, conversations: conversations() }; });
            });
        },
        'GET card_lookup': function (p) {
            var pk = String(p.pk || '').toLowerCase(), pk12 = pk.slice(0, 12), c = contactFull(pk12), d = D.discovered[pk12];
            return {
                known: c ? { conv: 'd:' + c.pk, name: c.name } : null,
                discovered: !c && d ? { name: d.name, type: d.type, last_advert: d.last_advert, path_len: d.path_len } : null,
                discovered_error: !c && !d ? 'not_found' : null
            };
        },
        'POST contact_add': function (p) {
            var d = D.discovered[String(p.pk || '').slice(0, 12)];
            if (!d) { throw err('Uzel companion zatím neslyšel (chybí jeho advert).'); }
            return addDiscovered(d).then(function () { return { ok: true, conversations: conversations() }; });
        },
        'POST contact_remove': function (p) {
            if (!connected) { throw err('Companion není připojen'); }
            var pks = (Array.isArray(p.pks) ? p.pks : []).map(function (x) { return String(x).slice(0, 12); }).filter(function (x) { return /^[0-9a-f]{12}$/.test(x); });
            if (!pks.length || pks.length > 500) { throw err('Vyber 1–500 kontaktů'); }
            var removed = 0, failed = 0;
            return pks.reduce(function (chain, pk) {
                return chain.then(function () {
                    var c = contactByPk(pk);
                    if (!c) { failed++; return; }
                    return cmd(function (cn) {
                        return request(cn, function () { return cn.sendCommandRemoveContact(unhex(c.publicKey)); }, [C().ResponseCodes.Ok], [C().ResponseCodes.Err]);
                    }).then(function () { removed++; }, function () { failed++; });
                });
            }, Promise.resolve()).then(syncContacts).then(function () {
                return { ok: true, removed: removed, failed: failed, conversations: conversations() };
            });
        },
        'POST contact_import': function (p) {
            var h = String(p.card || '').replace(/\s+/g, '').toLowerCase();
            if (!/^[0-9a-f]{100,1000}$/.test(h) || h.length % 2) { throw err('Karta kontaktu musí být hex řetězec (export z MeshCore)'); }
            return cmd(function (c) {
                return request(c, function () { return c.sendCommandImportContact(unhex(h)); }, [C().ResponseCodes.Ok], [C().ResponseCodes.Err]);
            }).then(syncContacts).then(function () { return { ok: true, conversations: conversations() }; });
        },
        'GET heard': function (p) {
            var m = D.msgs[+p.id];
            return { message: m ? withGroups([m])[0] : null, observers: (m && m.heard_observers) || [], analyzer: 'https://analyzer.meshcore.cz' };
        },
        'POST heard_check': function (p) {
            if (!ANALYZER) { throw err('Kontrola přes analyzer je vypnutá.'); }
            var m = D.msgs[+p.id];
            if (!m) { throw err('Zpráva nenalezena'); }
            // celou skupinu opakovaných odeslání spárovat znovu od začátku
            var root = m.dup_of || m.id;
            var group = sorted(function (x) { return (x.id === root || x.dup_of === root) && x.dir === 'out' && x.type === 'channel'; });
            group.forEach(function (x) { if (x.heard) { x.heard.hash = null; } });
            return group.reduce(function (ch, x) {
                return ch.then(function () { return heardCheck(D.msgs[x.id] || x, true).catch(function () {}); });
            }, Promise.resolve()).then(function () {
                var cur = D.msgs[m.id];
                return { message: withGroups([cur])[0], observers: cur.heard_observers || [] };
            });
        },
        'POST channel_secret': function (p) {
            var ch = null;
            D.channels.forEach(function (c) { if (c.idx === +p.idx) { ch = c; } });
            if (!ch) { throw err('Kanál nenalezen'); }
            return { secret: ch.secret, name: ch.name };
        }
    };

    function call(action, params, method) {
        var dfd = $.Deferred();
        var h = handlers[(method || 'GET') + ' ' + action];
        load().then(function () {
            if (!h) { throw err('V režimu Bluetooth zatím není k dispozici.', 404); }
            return h(params || {});
        }).then(function (r) { dfd.resolve(r); }, function (e) {
            if (e && e.responseJSON) { dfd.reject(e); return; }
            dfd.reject(err((e && e.message) || 'Chyba komunikace s companionem'));
        });
        return dfd.promise();
    }

    var lastPollKey = null;
    function stateKey() { return (connected ? '1' : '0') + (connecting ? '1' : '0'); }

    // ---------------------------------------------------------------- napojení na UI
    function ui(what, a, b) {
        var U = window.MCUI;
        if (!U) { return; }
        if (what === 'toast') { U.toast(a, b); }
        if (what === 'reload') { U.reload(); }
    }

    function connectClick() {
        if (connected) { return; }
        connect().catch(function (e) { ui('toast', e.message || String(e), 'danger'); }).then(function () { notify(); });
    }

    function renderBar() {
        var $bar = $('#ble-bar'), st = state();
        if (!$bar.length) { return; }
        $bar.toggleClass('ble-ok', st.companion_connected).toggleClass('ble-off', !st.companion_connected);
        var noBt = !navigator.bluetooth && !st.companion_connected;
        $('#ble-bar-text').text(noBt ? 'Prohlížeč nepodporuje Web Bluetooth – použij Chrome nebo Edge.' :
            st.companion_connected ? 'Připojeno: ' + ((D.self && D.self.name) || '') :
            st.connecting ? 'Připojuji…' : (D.dev ? 'Odpojeno – ' + (D.dev.name || D.dev.pk) : 'Companion není připojen'));
        $('#ble-bar-btn').text(st.companion_connected ? 'Odpojit' : 'Připojit').prop('disabled', st.connecting || noBt)
            .toggleClass('btn-primary', !st.companion_connected).toggleClass('btn-outline-secondary', st.companion_connected);
        $('#btn-ble-connect').toggleClass('d-none', st.companion_connected);
        $('#btn-ble-disconnect').toggleClass('d-none', !st.companion_connected);
    }
    (function barLoop() { var w = function () { renderBar(); waiters.push(w); }; waiters.push(w); })();

    $(function () {
        renderBar();
        $('#ble-bar-btn').on('click', function () { if (connected) { disconnect(); } else { connectClick(); } });
        $('#btn-ble-connect').on('click', connectClick);
        $('#btn-ble-disconnect').on('click', disconnect);
        $('#btn-ble-settings').on('click', function () {
            bootstrap.Dropdown.getOrCreateInstance($('[data-bs-toggle="dropdown"]').first()[0]).hide();
            if (window.MCUI && window.MCUI.bleSettings) { window.MCUI.bleSettings(); }
        });
    });

    function statusRows(row, yesNo) {
        var st = state(), s = D.self;
        return row('Companion připojen', yesNo(st.companion_connected)) +
            row('Zařízení', D.dev ? $('<span>').text((D.dev.name || '') + ' (' + D.dev.pk + ')').html() : '–') +
            (s ? row('Rádio', (s.radioFreq / 1000).toFixed(3) + ' MHz · BW ' + (s.radioBw / 1000) + ' kHz · SF' + s.radioSf + ' · CR' + s.radioCr + ' · ' + s.txPower + ' dBm') : '') +
            (D.battery ? row('Baterie', (D.battery / 1000).toFixed(2) + ' V (~' + batteryPct(D.battery) + ' %)') : '') +
            row('Kontaktů / kanálů', D.contacts.length + ' / ' + D.channels.length) +
            row('Uložených zpráv', String(Object.keys(D.msgs).length) + (SERVER ? ' (v prohlížeči i na serveru)' : ' (jen v tomto prohlížeči)')) +
            row('Poslední událost', st.last_event_at ? new Date(st.last_event_at).toLocaleString('cs-CZ') : '–');
    }

    // ---------------------------------------------------------------- PWA (instalace, běh bez internetu)
    var installPrompt = null;
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('ble/sw.js', { scope: 'ble/' }).catch(function (e) { console.warn('Service worker:', e); });
        });
    }
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        installPrompt = e;
        $('#btn-ble-install').removeClass('d-none');
    });
    window.addEventListener('appinstalled', function () { installPrompt = null; $('#btn-ble-install').addClass('d-none'); });
    $(function () {
        $('#btn-ble-install').on('click', function () {
            bootstrap.Dropdown.getOrCreateInstance($('[data-bs-toggle="dropdown"]').first()[0]).hide();
            if (!installPrompt) { return; }
            installPrompt.prompt();
            installPrompt.userChoice.then(function () { installPrompt = null; $('#btn-ble-install').addClass('d-none'); });
        });
    });

    window.MC_BACKEND = {
        call: call,
        connect: connectClick,
        disconnect: disconnect,
        statusRows: statusRows,
        isConnected: function () { return connected; },
        server: function () { return SERVER; },
        // jen pro testy: připojení přes jiné spojení (simulátor)
        _attachConnection: function (c) {
            wantConnected = false;
            return new Promise(function (resolve) {
                c.on('connected', function () {
                    conn = c; connected = true; connecting = false; bindEvents(c); notify();
                    afterConnect().then(resolve, function (e) { console.warn(e); resolve(); });
                });
                c.on('disconnected', function () { connected = false; conn = null; notify(); });
            });
        }
    };
})();
