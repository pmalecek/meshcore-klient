/* MeshCore klient – frontend (jQuery + Bootstrap 5) */
(function ($) {
    'use strict';

    var CSRF = $('meta[name="csrf"]').attr('content');
    var TYPE_ICONS = { 1: 'bi-person-fill', 2: 'bi-router-fill', 3: 'bi-people-fill', 4: 'bi-thermometer-half' };
    var TYPE_NAMES = { 1: 'Klient', 2: 'Repeater', 3: 'Room server', 4: 'Senzor' };

    var S = {
        ble: !!window.MC_BACKEND, // režim Bluetooth
        caps: {},           // funkce podle napojení (HA / meshcore-server), viz can()
        convs: {},          // conv_key -> konverzace
        current: null,      // aktuální conv_key
        messages: {},       // id -> zpráva (aktuální konverzace)
        oldestId: null,
        cursor: null,
        tab: (function () {
            var t = loadPref('tab', 'chats');
            return ['chats', 'channels', 'favorites', 'contacts', 'devices', 'map'].indexOf(t) >= 0 ? t : 'chats';
        })(),
        maxLen: 140,
        state: {},
        routeSent: {},      // id zpráv, na které už šla odpověď trasou (v této relaci)
        pending: [],        // optimistické odchozí zprávy
        hideDups: loadPref('hideDups', true),
        resent: loadPref('resent', []), // ID zpráv, které už byly odeslány znovu
        settings: { convs: {}, default_bg: null }, // nastavení ze serveru (barvy, pozadí, oblíbené)
        patterns: [],
        devices: null
    };
    if (!Array.isArray(S.resent)) { S.resent = []; }

    // ---------- nastavení v prohlížeči ----------
    function loadPref(key, def) {
        try {
            var v = localStorage.getItem('mc.' + key);
            return v === null ? def : JSON.parse(v);
        } catch (e) { return def; }
    }
    function savePref(key, val) {
        try { localStorage.setItem('mc.' + key, JSON.stringify(val)); } catch (e) { /* nedostupné */ }
    }

    // ---------- pomocné ----------
    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }
    function api(action, params, method) {
        // režim Bluetooth: „server“ běží v prohlížeči (assets/ble.js)
        if (window.MC_BACKEND) { return window.MC_BACKEND.call(action, params, method); }
        var opts = { url: 'api.php?action=' + action, dataType: 'json', method: method || 'GET' };
        if (method === 'POST') {
            opts.contentType = 'application/json';
            opts.data = JSON.stringify(params || {});
            opts.headers = { 'X-CSRF-Token': CSRF };
        } else if (params) {
            opts.url += '&' + $.param(params);
        }
        return $.ajax(opts).fail(function (xhr) {
            if (xhr.status === 401) { location.href = 'login.php'; }
        });
    }
    function errText(xhr) {
        return (xhr.responseJSON && xhr.responseJSON.error) || 'Chyba spojení se serverem';
    }
    function hashColor(s) {
        var h = 0;
        for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; }
        return 'hsl(' + (Math.abs(h) % 360) + ', 55%, 45%)';
    }
    function initials(name) {
        var clean = String(name || '?').replace(/[^\p{L}\p{N}\s]/gu, '').trim();
        var parts = clean.split(/\s+/).filter(Boolean);
        if (!parts.length) { return '?'; }
        return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
    }
    // Region zobrazit vždy jako "#název" (hvězdičku nechat)
    function fmtScope(x) {
        x = String(x || '').trim();
        return !x || x === '*' || x.charAt(0) === '#' ? x : '#' + x;
    }

    // Krátký popis regionů uzlu: výchozí region, jinak povolené (bez '*')
    function scopeLabel(sc) {
        if (!sc) { return ''; }
        if (sc.default) { return fmtScope(sc.default); }
        var list = (sc.configured || []).filter(function (x) { return x !== '*'; });
        if (!list.length) { list = sc.declared || sc.transported || []; }
        return list.slice(0, 3).map(fmtScope).join(', ') + (list.length > 3 ? '…' : '');
    }

    function scopeRows(sc) {
        if (!sc) { return ''; }
        var j = function (l) { return esc((l || []).map(fmtScope).join(', ')); };
        return (sc.default ? row('Výchozí region', '<span class="scope-badge">' + esc(fmtScope(sc.default)) + '</span>') : '') +
            (sc.configured && sc.configured.length ? row('Povolené regiony', j(sc.configured)) : '') +
            (sc.transported && sc.transported.length ? row('Přenáší regiony', j(sc.transported)) : '') +
            (sc.declared && sc.declared.length ? row('Deklarované regiony', j(sc.declared)) : '');
    }

    function convSet(conv) { return S.settings.convs[conv] || {}; }

    // Regiony známé z repeaterů (výchozí, povolené, přenášené) a z přijatých zpráv – nabídka v nastavení kanálu
    function knownScopes() {
        var cnt = {};
        var add = function (x) { x = String(x || '').replace(/^#/, ''); if (x && x !== '*') { cnt[x] = (cnt[x] || 0) + 1; } };
        Object.keys(S.convs).forEach(function (k) {
            var sc = S.convs[k].scopes;
            if (sc) { add(sc.default); [].concat(sc.configured || [], sc.transported || [], sc.declared || []).forEach(add); }
        });
        Object.keys(S.messages).forEach(function (k) { add(S.messages[k].scope); });
        return Object.keys(cnt).sort(function (a, b) { return cnt[b] - cnt[a] || a.localeCompare(b); });
    }
    function scopeSetting(conv) {
        var v = convSet(conv).scope;
        return v ? (v === '*' ? 'bez regionu' : '#' + v) : '';
    }
    function isFav(conv) { return !!convSet(conv).fav; }
    function avatarColor(conv) { return convSet(conv).color || hashColor(conv); }

    function avatar(c) {
        var $a = $('<div class="avatar">').css('background', avatarColor(c.conv));
        if (c.kind === 'channel') {
            $a.html('<i class="bi bi-hash"></i>');
        } else if (c.type && c.type !== 1) {
            $a.html('<i class="bi ' + TYPE_ICONS[c.type] + '"></i>');
        } else {
            $a.text(initials(c.name));
        }
        return $a;
    }
    function fmtTime(iso) {
        if (!iso) { return ''; }
        return new Date(iso).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
    }
    function fmtDay(iso) {
        var d = new Date(iso), today = new Date(), y = new Date();
        y.setDate(today.getDate() - 1);
        if (d.toDateString() === today.toDateString()) { return 'Dnes'; }
        if (d.toDateString() === y.toDateString()) { return 'Včera'; }
        return d.toLocaleDateString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric', year: 'numeric' });
    }
    function fmtShort(iso) {
        if (!iso) { return ''; }
        var d = new Date(iso);
        return d.toDateString() === new Date().toDateString()
            ? fmtTime(iso)
            : d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' });
    }
    function fmtAgo(iso) {
        if (!iso) { return 'nikdy'; }
        var s = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
        if (s < 60) { return 'před chvílí'; }
        if (s < 3600) { return 'před ' + Math.round(s / 60) + ' min'; }
        if (s < 86400) { return 'před ' + Math.round(s / 3600) + ' h'; }
        return 'před ' + Math.round(s / 86400) + ' d';
    }
    function utf8Len(s) { return new TextEncoder().encode(s).length; }
    // Rozdíl v sekundách: 45 s / 3 min 5 s / 3 h 55 min / 2 d 4 h
    function fmtDiff(sec) {
        if (sec < 60) { return sec + ' s'; }
        if (sec < 3600) { return Math.floor(sec / 60) + ' min ' + (sec % 60) + ' s'; }
        if (sec < 86400) { return Math.floor(sec / 3600) + ' h ' + Math.floor(sec % 3600 / 60) + ' min'; }
        return Math.floor(sec / 86400) + ' d ' + Math.floor(sec % 86400 / 3600) + ' h';
    }
    function toast(text, kind) {
        var $t = $('<div class="toast align-items-center border-0" role="alert">')
            .addClass('text-bg-' + (kind || 'secondary'))
            .html('<div class="d-flex"><div class="toast-body"></div>' +
                  '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>');
        $t.find('.toast-body').text(text);
        $('#toasts').append($t);
        var t = new bootstrap.Toast($t[0], { delay: 5000 });
        $t.on('hidden.bs.toast', function () { $t.remove(); });
        t.show();
    }

    // ---------- seznam konverzací ----------
    function setConversations(list) {
        S.convs = {};
        hopCache = {};
        list.forEach(function (c) { S.convs[c.conv] = c; });
        renderList();
        renderBadges();
        if (S.current) { renderHeader(); }
    }

    function renderBadges() {
        var chats = 0, channels = 0, favs = 0;
        $.each(S.convs, function (k, c) {
            if (c.kind === 'channel') { channels += c.unread; } else { chats += c.unread; }
            if (isFav(k)) { favs += c.unread; }
        });
        $('#badge-chats').text(chats).toggleClass('d-none', !chats);
        $('#badge-channels').text(channels).toggleClass('d-none', !channels);
        $('#badge-favorites').text(favs).toggleClass('d-none', !favs);
        document.title = (chats + channels ? '(' + (chats + channels) + ') ' : '') + 'MeshCore';
    }

    function byName(a, b) { return a.name.localeCompare(b.name, 'cs', { numeric: true, sensitivity: 'base' }); }

    function renderList() {
        if (S.tab !== 'map') { hideNetMap(); }
        if (S.tab === 'devices') { renderDevices(); return; }
        if (S.tab === 'map') { renderMapTab(); return; }
        var q = $('#search').val().trim().toLowerCase();
        var items = $.map(S.convs, function (c) { return c; });
        var cmp;

        if (S.tab === 'chats') {
            items = items.filter(function (c) { return c.kind === 'contact' && c.last; });
            cmp = function (a, b) { return b.last_id - a.last_id; };
        } else if (S.tab === 'channels') {
            items = items.filter(function (c) { return c.kind === 'channel'; });
            cmp = function (a, b) { return a.idx - b.idx; };
        } else if (S.tab === 'favorites') {
            items = items.filter(function (c) { return isFav(c.conv); });
            cmp = function (a, b) { return (b.last_id - a.last_id) || byName(a, b); };
        } else {
            items = items.filter(function (c) { return c.kind === 'contact'; });
            cmp = byName;
        }
        // oblíbené vždy nahoře
        items.sort(function (a, b) { return (isFav(b.conv) - isFav(a.conv)) || cmp(a, b); });
        if (q) {
            items = items.filter(function (c) {
                return c.name.toLowerCase().indexOf(q) >= 0 || (c.pk && c.pk.indexOf(q) === 0);
            });
        }

        var $list = $('#conv-list').empty();
        if (S.tab === 'channels') {
            $list.append('<div class="px-2 pb-2"><button type="button" class="btn btn-sm btn-outline-primary w-100" id="btn-add-channel">' +
                '<i class="bi bi-plus-lg me-1"></i>Přidat kanál</button></div>');
        }
        if (S.tab === 'contacts') {
            $list.append('<div class="px-2 pb-2 d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-primary flex-grow-1" id="btn-add-contact">' +
                '<i class="bi bi-person-plus me-1"></i>Přidat kontakt</button>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" id="btn-cleanup" title="Úklid kontaktů"><i class="bi bi-trash3 me-1"></i>Úklid</button></div>');
        }
        if (!items.length && !q) {
            $list.append('<div class="text-center text-body-secondary small p-4">' +
                (S.tab === 'chats' ? 'Zatím žádné přímé zprávy.<br>Začni konverzaci v záložce Kontakty.'
                    : S.tab === 'favorites' ? 'Zatím nemáš žádné oblíbené.<br>Označ je hvězdičkou v hlavičce chatu.'
                    : 'Nic tu není.') + '</div>');
        }
        items.forEach(function (c) { $list.append(convItem(c)); });

        if (q.length >= 2) { searchMessages(q); }
    }

    function convItem(c) {
        var $i = $('<a class="list-group-item list-group-item-action d-flex gap-2 align-items-center py-2">')
            .attr('data-conv', c.conv)
            .toggleClass('active', c.conv === S.current);
        var preview;
        if (S.tab === 'contacts' || (S.tab === 'favorites' && c.kind === 'contact' && !c.last)) {
            preview = (TYPE_NAMES[c.type] || 'Kontakt') +
                (scopeLabel(c.scopes) ? ' · ' + scopeLabel(c.scopes) : '') +
                (c.path_len != null && c.path_len >= 0 ? ' · ' + c.path_len + ' hop' : ' · flood') +
                ' · ' + fmtAgo(c.last_advert);
        } else if (c.last) {
            preview = (c.last.dir === 'out' ? 'Ty: ' : (c.kind === 'channel' && c.last.sender ? c.last.sender + ': ' : '')) +
                (c.last.status === 'failed' ? '⚠ neodesláno' : previewText(c.last.body));
        } else {
            preview = '';
        }
        var $body = $('<div class="flex-grow-1 min-w-0">')
            .append($('<div class="d-flex justify-content-between gap-2">')
                .append($('<span class="fw-semibold text-truncate">').text(c.name)
                    .append(isFav(c.conv) ? ' <i class="bi bi-star-fill fav-star"></i>' : ''))
                .append($('<span class="conv-time text-body-secondary">').text(c.last && S.tab !== 'contacts' ? fmtShort(c.last.ts) : '')))
            .append($('<div class="d-flex justify-content-between gap-2">')
                .append($('<span class="conv-preview text-body-secondary text-truncate">').text(preview))
                .append(c.unread ? $('<span class="badge rounded-pill text-bg-primary">').text(c.unread) : ''));
        $i.append(avatar(c), $body);
        if (S.tab === 'chats' && c.kind === 'contact') {
            $i.append('<button type="button" class="btn btn-sm conv-act" data-act="clear" title="Smazat historii konverzace"><i class="bi bi-trash"></i></button>');
        } else if (S.tab === 'channels' && c.kind === 'channel' && c.idx != null) {
            $i.append('<button type="button" class="btn btn-sm conv-act" data-act="remove" title="Odebrat kanál z companionu"><i class="bi bi-x-circle"></i></button>');
        }
        return $i;
    }

    // Akce v seznamu: smazat historii konverzace / odebrat kanál
    function clearConversation(conv, done) {
        var c = S.convs[conv];
        if (!c || !confirm('Smazat celou historii konverzace „' + c.name + '“? Nelze vrátit.')) { return; }
        api('conv_delete', { conv: conv }, 'POST').done(function (r) {
            toast('Smazáno zpráv: ' + r.deleted, 'secondary');
            setConversations(r.conversations);
            if (S.current === conv) { openConv(conv, true); }
            if (done) { done(); }
        }).fail(function (xhr) { toast(errText(xhr), 'danger'); });
    }

    function removeChannel(conv, $btn, done) {
        var c = S.convs[conv];
        if (!c || !confirm('Odebrat kanál „' + c.name + '“ z companionu? Smaže se i historie jeho zpráv.')) { return; }
        var html = $btn ? $btn.html() : '';
        if ($btn) { $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>'); }
        api('channel_delete', { idx: c.idx }, 'POST').done(function (r) {
            if (S.current === conv) { history.replaceState({}, '', location.pathname); closeConv(); }
            setConversations(r.conversations);
            toast('Kanál odebrán', 'secondary');
            if (done) { done(); }
        }).fail(function (xhr) {
            toast(errText(xhr), 'danger');
            if ($btn) { $btn.prop('disabled', false).html(html); }
        });
    }

    var searchTimer = null;
    function searchMessages(q) {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            api('search', { q: q }).done(function (r) {
                if ($('#search').val().trim().toLowerCase() !== q) { return; }
                $('#conv-list .search-hit, #conv-list .search-head').remove();
                if (!r.messages.length) { return; }
                $('#conv-list').append('<div class="search-head small text-body-secondary px-3 pt-3 pb-1 fw-semibold">Nalezené zprávy</div>');
                r.messages.forEach(function (m) {
                    var c = S.convs[m.conv] || { name: m.conv };
                    var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
                    $('<a class="list-group-item list-group-item-action search-hit py-2">')
                        .attr('data-conv', m.conv)
                        .html('<div class="d-flex justify-content-between small"><span class="fw-semibold">' + esc(c.name) +
                              '</span><span class="text-body-secondary">' + esc(fmtShort(m.ts)) + '</span></div>' +
                              '<div class="small text-truncate">' + esc(m.body).replace(re, '<mark>$1</mark>') + '</div>')
                        .appendTo('#conv-list');
                });
            });
        }, 300);
    }

    // ---------- chat ----------
    function openConv(conv, fromHistory) {
        if (!S.convs[conv]) { return; }
        S.current = conv;
        S.messages = {};
        S.oldestId = null;
        $('#conv-list .list-group-item').removeClass('active');
        $('#conv-list [data-conv="' + conv + '"]').not('.search-hit').addClass('active');
        $('#chat-empty').addClass('d-none');
        $('#map-view').addClass('d-none');
        $('#chat-view').removeClass('d-none');
        $('#app').addClass('show-chat');
        $('#message-list').empty();
        $('#send-error').text('');
        renderHeader();
        applyBackground();
        updateCount();
        hideSuggest();
        loadNicks(conv);
        maybeRoomAutoLogin(conv);
        $('#btn-mention').toggleClass('d-none', conv.charAt(0) !== 'c' && !(S.convs[conv] && S.convs[conv].type === 3));
        if (!fromHistory) {
            history.pushState({ conv: conv, pushed: true }, '', location.pathname + '#' + conv);
        }
        api('messages', { conv: conv, limit: 50, dups: S.hideDups ? 'hide' : 'show' }).done(function (r) {
            if (S.current !== conv) { return; }
            addMessages(r.messages, true);
            $('#load-more-wrap').toggleClass('d-none', r.messages.length < 50);
            scrollBottom(true);
            markRead();
        });
        if (window.matchMedia('(min-width: 768px)').matches) { $('#msg-input').trigger('focus'); }
    }

    function closeConv() {
        S.current = null;
        $('#app').removeClass('show-chat');
        $('#chat-view').addClass('d-none');
        $('#chat-empty').removeClass('d-none');
        $('#conv-list .list-group-item').removeClass('active');
    }

    function renderHeader() {
        var c = S.convs[S.current];
        if (!c) { return; }
        $('#chat-avatar').replaceWith(avatar(c).attr('id', 'chat-avatar').addClass('me-2'));
        $('#chat-title').text(c.name);
        var sub;
        if (c.kind === 'channel') {
            sub = 'Kanál #' + c.idx + (scopeSetting(c.conv) ? ' · 📍 odesílá se ' + scopeSetting(c.conv) : '');
        } else {
            sub = (TYPE_NAMES[c.type] || 'Kontakt') + (scopeLabel(c.scopes) ? ' · ' + scopeLabel(c.scopes) : '') + ' · ' + c.pk +
                (c.last_advert ? ' · advert ' + fmtAgo(c.last_advert) : '');
        }
        $('#chat-subtitle').text(sub);
        $('#btn-info').toggleClass('d-none', c.kind === 'channel');
        $('#btn-room').toggleClass('d-none', !(c.kind === 'contact' && c.type === 3 && can('room_login')));
        $('#btn-path').toggleClass('d-none', c.kind === 'channel')
            .attr('title', 'Cesta: ' + (c.path_len == null || c.path_len < 0 ? 'flood' : c.path_len === 0 ? 'přímo' : c.path_len + ' hop'));
        var fav = isFav(c.conv);
        $('#btn-fav').attr('title', fav ? 'Odebrat z oblíbených' : 'Přidat do oblíbených')
            .find('.bi').toggleClass('bi-star', !fav).toggleClass('bi-star-fill', fav);
    }

    function addMessages(list, prepend) {
        list.forEach(function (m) { S.messages[m.id] = m; });
        renderMessages();
        if (list.length) {
            var ids = Object.keys(S.messages).map(Number);
            S.oldestId = Math.min.apply(null, ids);
        }
    }

    function renderMessages() {
        var $list = $('#message-list').empty();
        var ids = Object.keys(S.messages).map(Number).sort(function (a, b) { return a - b; });
        var lastDay = null;
        ids.forEach(function (id) {
            var m = S.messages[id];
            var day = new Date(m.ts).toDateString();
            if (day !== lastDay) {
                $list.append('<div class="day-sep"><span>' + esc(fmtDay(m.ts)) + '</span></div>');
                lastDay = day;
            }
            $list.append(msgEl(m));
        });
        S.pending.forEach(function (p) {
            if (p.conv === S.current) { $list.append(msgEl(p)); }
        });
    }

    function msgEl(m) {
        var c = S.convs[m.conv] || {};
        var $m = $('<div class="msg">').addClass(m.dir).attr('data-id', m.id || '')
            .toggleClass('failed', m.status === 'failed')
            .toggleClass('pending', !!m.pending)
            .toggleClass('dup', !!m.dup_of);
        var $b = $('<div class="bubble">');
        if (m.dup_of) {
            $b.append('<div class="dup-label"><i class="bi bi-layers"></i> duplicita</div>');
        }
        if (m.dir === 'in' && (c.kind === 'channel' || m.author) && m.sender) {
            $b.append($('<div class="sender" title="Odpovědět">').css('color', hashColor(m.sender))
                .attr('data-nick', m.sender).text(m.sender));
        }
        if (m.status === 'failed') {
            $b.append($('<div>').html('<i class="bi bi-exclamation-triangle me-1"></i>').append(
                document.createTextNode('Zprávu se nepodařilo odeslat' + (m.error ? ' (' + m.error + ')' : ''))));
            if (m.body) { $b.append($('<div class="mt-1">').html(renderBody(m.body))); }
        } else {
            $b.append(renderBody(m.body));
        }

        var meta = [];
        if (m.dir === 'in') {
            if (m.hops != null) { meta.push('<span title="Počet skoků"><i class="bi bi-diagram-3"></i> ' + (m.hops === 0 ? 'přímo' : m.hops) + '</span>'); }
            if (m.snr != null) { meta.push('<span title="SNR">' + m.snr.toFixed(1) + ' dB</span>'); }
            if (m.id && routeReplyText(m)) {
                meta.push('<span class="route-reply' + (S.routeSent[m.id] ? ' sent' : '') + '" role="button" title="Odpovědět trasou – pošle do konverzace jméno a trasu, kudy zpráva přišla">' +
                    '<i class="bi bi-signpost-split"></i></span>');
            }
        } else if (m.pending) {
            meta.push('<span class="spinner-border spinner-border-sm" style="width:.6rem;height:.6rem"></span>');
        } else if (m.status === 'queued') {
            meta.push('<span class="text-warning" title="Integrace MeshCore teď omezuje provoz – zpráva se odešle automaticky, jakmile to půjde">' +
                '<i class="bi bi-hourglass-split"></i> čeká</span>');
        } else if (m.status !== 'failed') {
            var v = groupView(m);
            if (m.type === 'channel') {
                meta.push(heardBadge(v));
            } else if (v.status === 'delivered') {
                meta.push('<i class="bi bi-check2-all" title="Doručeno (ACK)"></i>');
            } else {
                meta.push('<i class="bi bi-check2" title="Odesláno"></i>');
            }
            if (m.type !== 'channel' && v.tries > 1) {
                meta.push('<span class="dm-tries" title="Odesláno ' + v.tries + '× (3× známou cestou, pak floodem)' + (v.flood ? ' – poslední pokus floodem' : '') + '">' +
                    '<i class="bi bi-arrow-repeat"></i>' + v.tries + (v.flood ? ' <i class="bi bi-broadcast"></i>' : '') + '</span>');
            }
        }
        if (m.dups > 0) {
            meta.unshift('<span class="dup-badge" title="' + (m.dir === 'out' ? 'Odesláno ' : 'Zpráva přišla ') + (m.dups + 1) + '× – klikni pro detail">' +
                '<i class="bi bi-layers"></i> ' + (m.dups + 1) + '×</span>');
        }
        if (m.scope) {
            meta.push('<span class="scope-badge" title="Region (scope) paketu">' + esc(fmtScope(m.scope)) + '</span>');
        }
        if (m.body) { meta.push('<span class="msg-bytes" title="Velikost textu zprávy">' + utf8Len(m.body) + ' B</span>'); }
        var gv = m.group ? groupView(m) : null;
        if (gv && gv._lastTs) {
            meta.push('<span title="Poslední pokus – první odeslání ' + esc(fmtTime(m.ts)) + '">' + esc(fmtTime(gv._lastTs)) + '</span>');
        } else {
            meta.push('<span>' + esc(fmtTime(m.ts)) + '</span>');
        }
        $b.append('<div class="meta">' + meta.join('') + '</div>');
        if (showRetryButton(m)) {
            var rv = groupView(m)._retry || m;
            $m.append($('<button type="button" class="btn btn-outline-secondary rounded-circle retry-btn">')
                .attr('title', m.type !== 'channel' ? 'Nepřišlo potvrzení doručení – odeslat znovu'
                    : rv.repeaters > 0 && heardZero(rv) ? 'Žádný observer zprávu nezachytil – odeslat znovu'
                    : 'Žádný repeater zprávu nezachytil – odeslat znovu')
                .html('<i class="bi bi-arrow-clockwise"></i>'));
        }
        return $m.append($b);
    }

    // ---------- odpověď trasou (náhrada bota „test“ v #bot) ----------
    // Formát: „@[jméno]: Rep1←Rep2←…“ (jména zkrácená na 10 znaků, neznámý repeater = hash),
    // při přímém příjmu „@[jméno]: →DIRECT←“. Bere se cesta s nejmenším počtem skoků.
    // Když se text nevejde do limitu, trasa (ne jméno odesílatele) se pošle bez diakritiky a emoji
    // se šipkami „<“ a v nouzi se zkrátí na konci („…“).
    function slugify(t) {
        return String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    }
    function routeReplyText(m) {
        if (m.dir !== 'in') { return null; }
        var c = S.convs[m.conv] || {};
        var sender = m.sender || (c.kind === 'contact' ? c.name : null);
        if (!sender) { return null; }
        var best = -1;
        (m.rx || []).forEach(function (r, i) {
            if (r.path_len != null && (best < 0 || r.path_len < m.rx[best].path_len)) { best = i; }
        });
        var names = null;
        if (best >= 0 && m.rx[best].path_len > 0) {
            var r = m.rx[best], hops = splitPath(r.path, r.path_len, r.path_hash_size);
            var picks = buildRoutes(m)[best].picks;
            names = hops.map(function (h, i) {
                // vybraný podle polohy, jinak první repeater s tímto hashem (i bez polohy), jinak hash
                var c = picks[i] || hopCandidates(h)[0];
                return c && c.name ? c.name : h;
            });
            if (!names.length) { names = null; }
        }
        var direct = !names && ((best >= 0 && m.rx[best].path_len === 0) || (best < 0 && m.hops === 0));
        if (!names && !direct) { return null; }
        var cut = function (t, n) { return Array.from(String(t)).slice(0, n).join(''); };
        var pretty = '@[' + sender + ']: ' + (direct ? '→DIRECT←' : names.map(function (n) { return cut(n, 10); }).join('←'));
        var lim = byteLimit(m.conv);
        if (utf8Len(pretty) <= lim) { return pretty; }
        // nevejde se: jméno odesílatele zůstává beze změny (aby zmínka fungovala),
        // trasa jde bez diakritiky a emoji se šipkami „<“ a případně se zkrátí na konci
        var head = '@[' + sender + ']: ';
        var route = direct ? 'DIRECT' : names.map(function (n) { return cut(slugify(n) || n, 10); }).join('<');
        var out = head + route;
        if (utf8Len(out) > lim) {
            while (route && utf8Len(head + route + '…') > lim) { route = route.slice(0, -1); }
            out = head + route + '…';
        }
        return out;
    }
    function routeReply(m) {
        var t = routeReplyText(m);
        if (!t) { return; }
        if (S.routeSent[m.id] && !confirm('Trasu k téhle zprávě už jsi poslal. Poslat znovu?')) { return; }
        S.routeSent[m.id] = true;
        $('#message-list .msg[data-id="' + m.id + '"] .route-reply').addClass('sent');
        sendText(m.conv, t).done(function () { toast('Trasa odeslána', 'success'); })
            .fail(function (xhr) { S.routeSent[m.id] = false; toast(errText(xhr), 'danger'); });
    }

    // Text zprávy: odkazy, vizitky <klíč:typ:jméno> a zmínky @[nick]; zbytek se escapuje
    var TOKEN_RE = /<([0-9a-fA-F]{64}):(\d{1,3}):([^<>\n]{0,64})>|(https?:\/\/[^\s<>"']+)|@\[([^\]\n]{1,64})\]/g;
    var CARD_RE = /<([0-9a-fA-F]{64}):(\d{1,3}):([^<>\n]{0,64})>/g;

    function renderBody(text) {
        var out = '', last = 0, m;
        text = String(text || '');
        TOKEN_RE.lastIndex = 0;
        while ((m = TOKEN_RE.exec(text)) !== null) {
            out += esc(text.slice(last, m.index));
            if (m[1]) {
                out += cardChip(m[1].toLowerCase(), +m[2], m[3]);
            } else if (m[4]) {
                var url = m[4], trail = '';
                var t = url.match(/[.,;:!?'")\]]+$/);
                if (t && !(t[0].charAt(0) === ')' && url.indexOf('(') >= 0)) {
                    trail = t[0];
                    url = url.slice(0, -trail.length);
                }
                out += '<a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer nofollow" class="msg-link">' + esc(url) + '</a>' + esc(trail);
            } else {
                out += '<span class="mention">@' + esc(m[5]) + '</span>';
            }
            last = TOKEN_RE.lastIndex;
        }
        return out + esc(text.slice(last));
    }

    function cardChip(pk, type, name) {
        return '<button type="button" class="card-chip" data-pk="' + pk + '" data-type="' + type + '" data-name="' + esc(name).replace(/"/g, '&quot;') + '">' +
            '<i class="bi bi-person-vcard"></i><span class="card-name">' + esc(name || pk.slice(0, 12)) + '</span>' +
            '<span class="card-type">' + esc(TYPE_NAMES[type] || 'Kontakt') + '</span></button>';
    }

    // Náhled v seznamu konverzací: vizitku zobrazit jako "📇 jméno"
    function previewText(text) {
        return String(text || '').replace(CARD_RE, function (a, pk, t, n) { return '📇 ' + (n || pk.slice(0, 12)); });
    }

    // ---------- vizitky a trasování ----------
    var TRACE_ERRORS = {
        contact_not_on_device: 'Kontakt není v companionu – nejdřív ho přidej.',
        contact_not_found: 'Companion kontakt nezná.',
        path_discovery_timeout: 'Nepodařilo se najít cestu k uzlu (vypršel čas).',
        timeout: 'Uzel na trasování neodpověděl.',
        not_connected: 'Companion není připojený.',
        no_coordinator: 'Integrace MeshCore v HA neběží.'
    };

    // Funkce, kterou aktuální napojení neumí (meshcore-server zatím: vzdálená správa, trace, room login…)
    function can(feature) { return !S.caps || S.caps[feature] !== false; }
    function isSrv() { return !!(S.caps && S.caps.backend === 'server'); }
    function backendName() { return isSrv() ? 'meshcore-server' : 'Home Assistant'; }

    function traceButton(pk12) {
        if (!can('trace')) { return ''; }
        return '<button type="button" class="btn btn-outline-secondary btn-sm btn-trace" data-pk="' + pk12 + '">' +
            '<i class="bi bi-signpost-split me-1"></i>Trasovat</button>';
    }

    function runTrace($btn) {
        var pk12 = $btn.attr('data-pk');
        var $out = $btn.closest('.modal-body').find('.trace-result');
        if (!$out.length) { $out = $('<div class="trace-result mt-3">').appendTo($btn.closest('.modal-body')); }
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Trasuji…');
        $out.html('<div class="small text-body-secondary">Trasování může trvat až 90 s, pokud companion k uzlu nejdřív hledá cestu.</div>');
        api('trace', { pk: pk12 }, 'POST').done(function (r) {
            if (!r.trace) {
                $out.html('<div class="alert alert-warning py-2 small mb-0">' + esc(TRACE_ERRORS[r.error] || ('Trasování selhalo' + (r.error ? ': ' + r.error : '.'))) + '</div>');
                return;
            }
            var t = r.trace, hops = t.path || [], parts = ['<span class="hop hop-end">ty</span>'];
            hops.forEach(function (h, i) {
                var snr = h.snr != null ? '<span class="trace-snr">' + esc(fmtNum(h.snr)) + ' dB</span>' : '';
                if (h.hash) {
                    var c = hopCandidates(String(h.hash).toLowerCase());
                    var label = c.length ? c[0].name + (c.length > 1 ? ' ?' + c.length : '') : h.hash;
                    parts.push('<span class="hop" data-hash="' + esc(String(h.hash).toLowerCase()) + '" title="' + esc(h.hash) + '">' + esc(label) + snr + '</span>');
                } else if (i === hops.length - 1) {
                    parts.push('<span class="hop hop-end">zpět' + snr + '</span>');
                }
            });
            $out.html('<h6 class="mb-2">Výsledek trasování</h6>' +
                '<div class="hop-path mb-2">' + parts.join('<i class="bi bi-arrow-right hop-arrow"></i>') + '</div>' +
                '<div class="small text-body-secondary">' +
                (t.round_trip_ms != null ? 'Odezva ' + esc(Math.round(t.round_trip_ms)) + ' ms' : '') +
                (t.final_snr != null ? ' · SNR na konci ' + esc(fmtNum(t.final_snr)) + ' dB' : '') +
                (t.hops != null ? ' · ' + esc(t.hops) + ' skoků' : '') + '</div>');
        }).fail(function (xhr) {
            $out.html('<div class="alert alert-danger py-2 small mb-0">' + esc(errText(xhr)) + '</div>');
        }).always(function () {
            $btn.prop('disabled', false).html('<i class="bi bi-signpost-split me-1"></i>Trasovat');
        });
    }

    function showCard(pk, type, name) {
        var pk12 = pk.slice(0, 12);
        var html = '<div class="d-flex align-items-center gap-3 mb-3">' +
            '<div class="avatar" style="background:' + hashColor('d:' + pk12) + '">' +
            (type && type !== 1 ? '<i class="bi ' + (TYPE_ICONS[type] || 'bi-cpu') + '"></i>' : esc(initials(name))) + '</div>' +
            '<div class="min-w-0"><div class="fw-semibold fs-5 text-truncate">' + esc(name || (pk.length < 64 ? 'Uzel ' + pk.slice(0, 12) : '(bez jména)')) + '</div>' +
            '<div class="small text-body-secondary">' + esc(TYPE_NAMES[type] || (type ? 'Typ ' + type : 'Kontakt')) + '</div></div></div>' +
            '<div class="small text-body-secondary mb-1">' + (pk.length < 64 ? 'Prefix veřejného klíče' : 'Veřejný klíč') + '</div>' +
            '<div class="font-monospace small text-break user-select-all p-2 rounded bg-body-tertiary mb-3">' + esc(pk) + '</div>' +
            '<div id="card-status"><div class="small text-body-secondary"><span class="spinner-border spinner-border-sm me-1"></span>Zjišťuji, jestli companion uzel zná…</div></div>';
        showModal('Vizitka kontaktu', html);

        var conv = 'd:' + pk12;
        if (S.convs[conv] && S.convs[conv].kind === 'contact') {
            renderKnown(S.convs[conv]);
            return;
        }
        api('card_lookup', { pk: pk }).done(function (r) {
            if (r.known) {
                renderKnown(S.convs[r.known.conv] || { conv: r.known.conv, name: r.known.name });
            } else if (r.discovered) {
                var d = r.discovered;
                $('#card-status').html(
                    '<div class="alert alert-info py-2 small">Companion tenhle uzel slyšel' +
                    (d.last_advert ? ' (advert ' + esc(fmtAgo(d.last_advert)) + ')' : '') +
                    ', ale nemáš ho v kontaktech.</div>' +
                    '<button type="button" class="btn btn-primary" id="card-add"><i class="bi bi-person-plus me-1"></i>Přidat do kontaktů</button>');
                $('#card-add').on('click', function () {
                    var $b = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Přidávám…');
                    api('contact_add', { pk: pk12 }, 'POST').done(function (res) {
                        setConversations(res.conversations);
                        toast('Kontakt přidán', 'success');
                        renderKnown(S.convs[conv] || { conv: conv, name: name });
                    }).fail(function (xhr) {
                        toast(errText(xhr), 'danger');
                        $b.prop('disabled', false).html('<i class="bi bi-person-plus me-1"></i>Přidat do kontaktů');
                    });
                });
            } else {
                $('#card-status').html('<div class="alert alert-secondary py-2 small mb-0">Companion tenhle uzel zatím neslyšel. ' +
                    'Přidat ho půjde, až zachytí jeho advert – můžeš odesílatele požádat, ať ho pošle.</div>');
            }
        }).fail(function (xhr) {
            $('#card-status').html('<div class="alert alert-danger py-2 small mb-0">' + esc(errText(xhr)) + '</div>');
        });

        function renderKnown(c) {
            $('#card-status').html(
                '<div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i>Kontakt máš v companionu' +
                (c.name && c.name !== name ? ' jako <strong>' + esc(c.name) + '</strong>' : '') +
                (c.last_advert ? ' · advert ' + esc(fmtAgo(c.last_advert)) : '') + '.</div>' +
                '<div class="d-flex flex-wrap gap-2">' +
                '<button type="button" class="btn btn-primary btn-sm" id="card-open"><i class="bi bi-chat-dots me-1"></i>Otevřít chat</button>' +
                traceButton(pk12) + '</div>');
            $('#card-open').on('click', function () {
                bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
                openConv(conv);
            });
        }
    }

    // ---------- heard (analyzer) ----------
    // Nikdo cizí zprávu nezachytil: konečný výsledek, 0 observerů, nebo ji analyzer nemá ani 2 min po odeslání
    var HEARD_ZERO_AFTER = 120; // s
    function heardZero(m) {
        var h = m.heard;
        if (!h) { return false; }
        var age = (Date.now() - new Date(m.ts).getTime()) / 1000;
        return h.state === 'none' || (h.state === 'heard' && h.count === 0) ||
            (h.state === 'pending' && age >= HEARD_ZERO_AFTER);
    }

    // Odchozí zpráva v kanálu: 📡 repeatery z HA[/heard z analyzeru]  👂 observeři kromě tvého
    function heardBadge(m) {
        var h = m.heard, rep = m.repeaters || 0;
        var heardTotal = h && h.state === 'heard' && h.repeats ? h.repeats : null;
        var out = '<span title="' + rep + ' repeaterů zachytil tvůj companion (' + backendName() + ')' +
            (heardTotal !== null ? ' / ' + heardTotal + '× heard v analyzeru' : '') + '">' +
            '<i class="bi bi-broadcast"></i> ' + rep + (heardTotal !== null ? '/' + heardTotal : '') + '</span>';
        if (!h) {
            var age = (Date.now() - new Date(m._lastTs || m.ts).getTime()) / 1000;
            return out + (S.analyzerOn && age < 3600
                ? '<span class="heard opacity-75" title="Kontrola v analyzeru proběhne za pár sekund"><i class="bi bi-ear"></i> …</span>' : '');
        }
        if (h.state === 'heard' && h.count > 0) {
            return out + '<span class="heard" title="Zachytilo ' + h.count + ' observerů (bez tvého) – analyzer">' +
                '<i class="bi bi-ear-fill"></i> ' + h.count + '</span>';
        }
        if (h.state === 'none' || h.state === 'heard' || (h.state === 'pending' && heardZero(m))) {
            return out + '<span class="heard" title="Žádný observer analyzeru (kromě tvého) zprávu nezachytil"><i class="bi bi-ear"></i> 0</span>';
        }
        if (h.state === 'pending') {
            return out + '<span class="heard opacity-75" title="Hledám zprávu v analyzeru…"><i class="bi bi-ear"></i> …</span>';
        }
        return out;
    }

    function heardSection(m) {
        if (m.dir !== 'out' || m.type !== 'channel' || !m.id) { return ''; }
        return '<div id="heard-wrap" class="mt-3"><h6>Zachyceno observery <span class="small text-body-secondary fw-normal">(analyzer)</span></h6>' +
            '<div class="text-body-secondary small"><span class="spinner-border spinner-border-sm me-1"></span> Načítám…</div></div>';
    }

    function renderHeard(m, observers, base) {
        var h = m.heard || {};
        var $w = $('#heard-wrap');
        if (!$w.length) { return; }
        var summary;
        switch (h.state) {
            case 'heard':
                summary = '<strong>' + h.count + '</strong> observerů kromě tvého' +
                    (h.total != null ? ' (celkem ' + h.total + ')' : '') + ', <strong>' + (h.repeats || 0) + '× heard</strong>.';
                break;
            case 'none': summary = 'Žádný observer analyzeru zprávu nezachytil.'; break;
            case 'pending': summary = (heardZero(m) ? 'Zatím žádný observer – ' : 'Zpráva zatím v analyzeru není – ') + 'zkusím to znovu později.'; break;
            case 'unsupported': summary = 'Tenhle kanál analyzer nedešifruje (soukromý kanál), nelze ověřit.'; break;
            case 'error': summary = 'Analyzer se nepodařilo kontaktovat.'; break;
            default: summary = 'Kontrola proběhne pár sekund po odeslání.';
        }
        var html = '<h6>Zachyceno observery' + (m.group && m.group.last ? ' – 1. pokus' : '') +
            ' <span class="small text-body-secondary fw-normal">(' + esc(base ? base.replace(/^https?:\/\//, '') : 'analyzer') + ')</span></h6>' +
            '<div class="small mb-2">' + summary + '</div>';
        if (observers && observers.length) {
            var first = Date.parse(observers[0].time);
            html += '<div class="table-responsive"><table class="table table-sm small align-middle mb-2"><thead><tr>' +
                '<th>Observer</th><th>Čas</th><th>SNR</th><th>RSSI</th><th>Cesta</th></tr></thead><tbody>';
            observers.forEach(function (o) {
                var rel = o.time ? Math.round((Date.parse(o.time) - first) / 1000) : null;
                var hops = (o.path || []).join('');
                var size = o.path && o.path.length ? o.path[0].length / 2 : 1;
                html += '<tr class="' + (o.own ? 'text-body-secondary' : '') + '"><td>' + esc(o.name || o.id) +
                    (o.own ? ' <span class="badge text-bg-secondary">tvůj</span>' : '') + '</td>' +
                    '<td class="text-nowrap">' + (rel === null ? '–' : '+' + rel + ' s') + '</td>' +
                    '<td>' + (o.snr != null ? esc(o.snr) : '–') + '</td>' +
                    '<td>' + (o.rssi != null ? esc(o.rssi) : '–') + '</td>' +
                    '<td>' + pathHtml(hops, o.path ? o.path.length : 0, size, null, true) + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        html += '<div class="d-flex flex-wrap align-items-center gap-2">' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" id="btn-heard-check"><i class="bi bi-arrow-repeat me-1"></i>Zkontrolovat znovu</button>' +
            (h.hash ? '<span class="small text-body-secondary">Paket <code class="user-select-all" title="Hash paketu – lze vyhledat v analyzeru">' + esc(h.hash) + '</code></span>' : '') +
            (h.checked ? '<span class="small text-body-secondary ms-auto">kontrola ' + esc(fmtAgo(h.checked)) + '</span>' : '') +
            '</div>';
        $w.html(html);
        $('#btn-heard-check').on('click', function () {
            var $b = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Kontroluji…');
            api('heard_check', { id: m.id }, 'POST').done(function (r) {
                if (r.message) { S.messages[r.message.id] = r.message; renderMessages(); }
                renderHeard(r.message || m, r.observers, base);
            }).fail(function (xhr) {
                toast(errText(xhr), 'danger');
                $b.prop('disabled', false).html('<i class="bi bi-arrow-repeat me-1"></i>Zkontrolovat znovu');
            });
        });
    }

    function loadHeard(m) {
        api('heard', { id: m.id }).done(function (r) {
            renderHeard(r.message || m, r.observers, r.analyzer);
        }).fail(function () { $('#heard-wrap').remove(); });
    }

    // ---------- zmínky / našeptávač ----------
    var MS = { open: false, items: [], idx: 0, ctx: null };

    function isChannel() { return !!S.current && S.current.charAt(0) === 'c'; }

    function loadNicks(conv) {
        S.nicks = [];
        if (conv.charAt(0) !== 'c') { return; }
        api('nicks', { conv: conv }).done(function (r) {
            if (S.current === conv) { S.nicks = r.nicks; }
        });
    }

    function touchNick(nick, ts) {
        var cnt = 0;
        S.nicks = (S.nicks || []).filter(function (n) {
            if (n.nick === nick) { cnt = n.count; return false; }
            return true;
        });
        S.nicks.unshift({ nick: nick, last: ts, count: cnt + 1 });
    }

    function norm(s) {
        return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function filterNicks(q, limit) {
        q = norm(q);
        var starts = [], contains = [];
        (S.nicks || []).forEach(function (n) {
            var k = norm(n.nick);
            if (!q || k.indexOf(q) === 0) { starts.push(n); } else if (k.indexOf(q) > 0) { contains.push(n); }
        });
        return starts.concat(contains).slice(0, limit || 8);
    }

    // Hledá rozepsanou zmínku před kurzorem: "@", "@pe", "@[pe"
    function mentionContext() {
        var el = $('#msg-input')[0], caret = el.selectionStart;
        if (caret !== el.selectionEnd) { return null; }
        var before = el.value.slice(0, caret);
        var m = before.match(/(^|\s)@\[?([^\s\[\]@]*)$/);
        if (!m) { return null; }
        return { start: caret - m[0].length + m[1].length, end: caret, query: m[2] };
    }

    function insertMention(nick, start, end) {
        var el = $('#msg-input')[0], val = el.value;
        if (start == null) {
            start = end = (document.activeElement === el || val) ? el.selectionStart : 0;
        }
        var before = val.slice(0, start), after = val.slice(end);
        var token = '@[' + nick + ']' + (before.trim() === '' ? ': ' : ' ');
        if (before && !/\s$/.test(before)) { token = ' ' + token; }
        after = after.replace(/^\s+/, '');
        el.value = before + token + after;
        var pos = (before + token).length;
        el.focus();
        el.setSelectionRange(pos, pos);
        hideSuggest();
        updateCount();
    }

    function hideSuggest() {
        MS.open = false;
        MS.ctx = null;
        $('#mention-suggest').addClass('d-none').empty();
    }

    function updateSuggest() {
        if (!isChannel()) { hideSuggest(); return; }
        var ctx = mentionContext();
        if (!ctx) { hideSuggest(); return; }
        var items = filterNicks(ctx.query, 8);
        if (!items.length) { hideSuggest(); return; }
        MS.items = items;
        MS.ctx = ctx;
        if (!MS.open) { MS.idx = 0; }
        MS.idx = Math.min(MS.idx, items.length - 1);
        MS.open = true;
        var $s = $('#mention-suggest').empty().removeClass('d-none');
        items.forEach(function (n, i) {
            $('<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between gap-3">')
                .toggleClass('active', i === MS.idx)
                .attr('data-i', i)
                .append($('<span class="fw-semibold text-truncate">').text(n.nick))
                .append($('<span class="mention-meta text-nowrap opacity-75">').text(fmtAgo(n.last)))
                .appendTo($s);
        });
    }

    function pickSuggest(i) {
        var n = MS.items[i];
        if (n && MS.ctx) { insertMention(n.nick, MS.ctx.start, MS.ctx.end); }
    }

    function scrollBottom(force) {
        var el = document.getElementById('messages');
        var nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 150;
        if (force || nearBottom) { el.scrollTop = el.scrollHeight; }
    }

    function markRead() {
        var c = S.convs[S.current];
        if (!c || document.hidden) { return; }
        var ids = Object.keys(S.messages).map(Number);
        if (!ids.length) { return; }
        var last = Math.max.apply(null, ids);
        if (c.unread) {
            c.unread = 0;
            renderBadges();
            $('#conv-list [data-conv="' + c.conv + '"] .badge').remove();
        }
        api('read', { conv: S.current, last_id: last }, 'POST');
    }

    // ---------- odesílání ----------
    // limit textu v bajtech: MeshCore MAX_TEXT_LEN 160 B, u kanálu firmware předřadí „jméno: “
    function byteLimit(conv) {
        return conv && conv.charAt(0) === 'c' ? 160 - utf8Len((S.selfName || '') + ': ') : 160;
    }
    function tooLong(conv, text) {
        var t = text.trim(), b = utf8Len(t), lim = byteLimit(conv);
        if (Array.from(t).length > S.maxLen) { return 'Zpráva je delší než ' + S.maxLen + ' znaků.'; }
        return b > lim ? 'Zpráva má ' + b + ' B, limit je ' + lim + ' B.' : null;
    }

    function updateCount() {
        var v = $('#msg-input').val(), n = Array.from(v).length, over = !!tooLong(S.current, v);
        $('#char-count').text(n + ' / ' + S.maxLen + (n ? ' · ' + utf8Len(v) + ' B' : ''))
            .toggleClass('over', over);
        $('#btn-send').prop('disabled', !v.trim() || over);
        $('#send-error').text(over ? tooLong(S.current, v) : '');
        var el = $('#msg-input')[0];
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 128) + 'px';
    }

    // Odešle text do konverzace, zobrazí dočasnou bublinu. Vrací jqXHR.
    function sendText(conv, text, resendOf) {
        var p = { pending: true, dir: 'out', conv: conv, body: text, ts: new Date().toISOString(),
                  type: conv.charAt(0) === 'c' ? 'channel' : 'direct', status: 'sent', repeaters: 0 };
        S.pending.push(p);
        if (S.current === conv) {
            renderMessages();
            scrollBottom(true);
        }
        return api('send', { conv: conv, text: text, resend_of: resendOf || null }, 'POST')
            .done(function () {
                // zpráva dorazí přes poll; pojistka, kdyby událost nepřišla
                setTimeout(function () { dropPending(p); }, 30000);
            })
            .fail(function () { dropPending(p); });
    }

    function send() {
        var text = $('#msg-input').val().trim();
        if (!text || !S.current) { return; }
        if (tooLong(S.current, text)) { $('#send-error').text(tooLong(S.current, text)); return; }
        var conv = S.current;
        $('#msg-input').val('');
        $('#send-error').text('');
        updateCount();
        sendText(conv, text).fail(function (xhr) {
            if (S.current === conv && !$('#msg-input').val()) { $('#msg-input').val(text); updateCount(); }
            $('#send-error').text(errText(xhr));
        });
    }

    // ---------- opětovné odeslání ----------
    var RETRY_AFTER = { channel: 20, direct: 30 }; // s – kdy nabídnout šipku u nepotvrzené zprávy

    function isResent(id) { return S.resent.indexOf(id) >= 0; }

    function markResent(id) {
        if (!isResent(id)) {
            S.resent.push(id);
            S.resent = S.resent.slice(-300);
            savePref('resent', S.resent);
        }
    }

    // Zpráva nebyla potvrzena: kanál bez repeaterů / přímá zpráva bez ACK / chyba odeslání
    function unconfirmed(m) {
        if (m.dir !== 'out' || m.pending || !m.id || !m.body) { return false; }
        if (m.status === 'queued') { return false; } // čeká na volný provoz, odešle se samo
        if (m.status === 'failed') { return true; }
        if (m.type === 'channel' && heardZero(m)) { return true; } // žádný observer – nabídnout znovu i s repeatery
        if (m.type === 'channel' ? m.repeaters > 0 : m.status === 'delivered') { return false; }
        var age = (Date.now() - new Date(m.ts).getTime()) / 1000;
        // přímá zpráva se ještě automaticky opakuje (3× cestou, 3× floodem) – šipku zatím nenabízet
        if (m.type !== 'channel' && m.tries != null && m.ack === null && age < 240) { return false; }
        return age >= RETRY_AFTER[m.type === 'channel' ? 'channel' : 'direct'];
    }

    // Odchozí zpráva s opakovanými odesláními: v bublině se ukazuje poslední pokus
    // (čísla z HA i z analyzeru); všechny pokusy jsou v detailu zprávy.
    function groupView(m) {
        var g = m.group;
        if (!g || !g.last) { return m; }
        var last = g.last;
        return $.extend({}, m, {
            repeaters: last.repeaters, heard: last.heard, status: last.status, _lastTs: last.ts,
            tries: last.tries, flood: last.flood, ack: last.ack,
            _retry: $.extend({}, last, { group: null })
        });
    }

    function showRetryButton(m) {
        var v = groupView(m), r = v._retry || v;
        return unconfirmed(r) && !isResent(r.id);
    }

    function resend(m, $btn) {
        if (tooLong(m.conv, m.body)) { toast(tooLong(m.conv, m.body), 'warning'); return; }
        if ($btn) { $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>'); }
        sendText(m.conv, m.body, m.dup_of || m.id)
            .done(function () {
                markResent(m.id);
                var gr = groupView(m)._retry;
                if (gr && gr.id) { markResent(gr.id); }
                toast('Zpráva odeslána znovu', 'success');
                if (S.current === m.conv) { renderMessages(); }
            })
            .fail(function (xhr) {
                toast(errText(xhr), 'danger');
                if ($btn) { $btn.prop('disabled', false).html('<i class="bi bi-arrow-clockwise"></i>'); }
            });
    }

    function dropPending(p) {
        var i = S.pending.indexOf(p);
        if (i >= 0) {
            S.pending.splice(i, 1);
            if (S.current === p.conv) { renderMessages(); }
        }
    }

    // ---------- long polling ----------
    var pollErrors = 0;
    function poll() {
        api('poll', { cursor: S.cursor }).done(function (r) {
            pollErrors = 0;
            S.cursor = r.cursor;
            setState(r.state);
            handleChanges(r.messages);
            poll();
        }).fail(function () {
            pollErrors++;
            setStatusDot('err', 'Spojení se serverem přerušeno');
            setTimeout(poll, Math.min(30000, 2000 * pollErrors));
        });
    }

    var reloadTimer = null;
    function handleChanges(list) {
        if (!list.length) { return; }
        var touchesCurrent = false, needReload = false;
        list.forEach(function (m) {
            var c = S.convs[m.conv];
            var isNew = !c || m.id > (c.last_id || 0);
            if (m.conv === S.current && m.dir === 'in' && m.sender && !m.dup_of && isNew && isChannel()) {
                touchNick(m.sender, m.ts);
            }
            if (m.dir === 'out' && isNew) {
                // nahradí optimistickou zprávu se stejným textem (i když je opakované odeslání skryté pod původní)
                for (var i = 0; i < S.pending.length; i++) {
                    if (S.pending[i].conv === m.conv && S.pending[i].body === m.body) {
                        S.pending.splice(i, 1);
                        if (m.conv === S.current) { touchesCurrent = true; }
                        break;
                    }
                }
            }
            if (m.conv === S.current && !(m.dup_of && S.hideDups)) {
                S.messages[m.id] = m;
                touchesCurrent = true;
            }
            if (isNew) {
                needReload = true;
                if (m.dir === 'in' && !m.dup_of && (m.conv !== S.current || document.hidden)) { notify(m); }
            }
        });
        if (touchesCurrent) {
            renderMessages();
            scrollBottom(false);
            markRead();
        }
        if (needReload) {
            clearTimeout(reloadTimer);
            reloadTimer = setTimeout(function () {
                api('conversations').done(function (r) { setConversations(r.conversations); });
            }, 300);
        }
    }

    // ---------- stav ----------
    function setStatusDot(cls, title) {
        $('#status-dot').removeClass('ok warn err').addClass(cls).attr('title', title);
    }
    // kredit zpráv z limitu provozu v HA (lane „messages“) – pod polem pro psaní
    var trafficTimer = null;
    function renderTraffic() {
        var t = S.state && !S.state.ble ? S.state.traffic : null, $el = $('#traffic-info');
        clearTimeout(trafficTimer);
        if (!t) { $el.addClass('d-none'); return; }
        var rate = t.per_hour / 3600, el = (Date.now() - S.trafficAt) / 1000, c = t.credits, next = t.next_in;
        if (next > 0 && el >= next) {          // mezitím přibyl kredit (do dalšího pollu dopočítat)
            c = Math.min(t.cap, c + 1 + Math.floor((el - next) * rate));
            next = c >= t.cap ? 0 : Math.ceil(1 / rate - ((el - next) % (1 / rate)));
        } else if (next > 0) {
            next = Math.ceil(next - el);
        }
        var q = S.state.queued || 0;
        var txt = '<i class="bi bi-envelope"></i> ' + c + '/' + t.cap +
            (c < 1 && next > 0 ? ' · další za ' + fmtWait(next) : '') + (q ? ' · ve frontě ' + q : '');
        $el.html(txt).removeClass('d-none text-warning text-danger')
            .addClass(c < 1 ? 'text-danger' : (c <= 3 ? 'text-warning' : ''))
            .attr('title', 'Kredit zpráv v Home Assistantu (limit provozu): ' + c + ' z ' + t.cap +
                ', doplňuje se ' + t.per_hour + ' za hodinu. Každé odeslání i opakování DM stojí 1 kredit.' +
                (c < 1 ? ' Zpráva počká ve frontě a odešle se sama.' : '') +
                (c <= 3 ? ' Opakování DM se teď zkracuje.' : ''));
        if (next > 0 || c < t.cap) { trafficTimer = setTimeout(renderTraffic, 1000); }
    }
    function fmtWait(s) {
        return s >= 60 ? Math.floor(s / 60) + ' min ' + (s % 60 ? (s % 60) + ' s' : '') : s + ' s';
    }

    function setState(st) {
        if (!st) { return; }
        S.state = st;
        S.trafficAt = Date.now();
        renderTraffic();
        var fw = st.firmware;
        $('#sidebar-footer').toggleClass('d-none', !fw)
            .html(fw ? '<i class="bi bi-cpu me-1"></i>Firmware ' + esc(fw.ver) + (fw.model ? ' · ' + esc(fw.model) : '') : '')
            .attr('title', fw ? 'Companion: firmware ' + fw.ver + (fw.build ? ' (sestaveno ' + fw.build + ')' : '') + (fw.model ? ', ' + fw.model : '') : '');
        if (st.ble) {
            $('#badge-devices').addClass('d-none');
            if (st.companion_connected) { setStatusDot('ok', 'Companion připojen přes Bluetooth'); }
            else if (st.connecting) { setStatusDot('warn', 'Připojuji companion…'); }
            else { setStatusDot('err', 'Companion není připojen'); }
            return;
        }
        if (st.sun) {
            savePref('sun', st.sun);
            if (window.mcTheme) { window.mcTheme.apply(); }
        }
        var offline = (st.devices_total - st.devices_online) + (st.mqtt_total - st.mqtt_connected);
        $('#badge-devices').text(offline).toggleClass('d-none', !offline)
            .attr('title', offline ? 'Některá zařízení nebo MQTT jsou offline' : '');
        if (!st.worker_alive) {
            setStatusDot('err', 'Worker neběží');
        } else if (!st.worker_connected) {
            setStatusDot('err', 'Worker není připojen k ' + (isSrv() ? 'meshcore-serveru' : 'Home Assistantu'));
        } else if (st.companion_connected === false) {
            setStatusDot('warn', 'MeshCore companion je odpojený');
        } else {
            setStatusDot('ok', 'Připojeno');
        }
    }

    function showModal(title, html) {
        $('#info-title').text(title);
        $('#info-body').html(html);
        bootstrap.Modal.getOrCreateInstance('#info-modal').show();
    }

    // Esc zavře okno i když fokus "vypadl" (např. po nahrazení tlačítka v obsahu)
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && $('#info-modal').hasClass('show') && !$.contains($('#info-modal')[0], document.activeElement)) {
            bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
        }
    });
    function row(k, v) {
        return '<tr><th class="text-body-secondary fw-normal">' + esc(k) + '</th><td>' + v + '</td></tr>';
    }
    function yesNo(v) {
        return v == null ? '<span class="text-body-secondary">neznámo</span>'
            : (v ? '<span class="text-success">ano</span>' : '<span class="text-danger">ne</span>');
    }

    function showStatus() {
        var st = S.state;
        if (S.ble) {
            var B = window.MC_BACKEND;
            showModal('Stav připojení', '<table class="table table-sm mb-3">' + B.statusRows(row, yesNo) + '</table>' +
                '<div class="d-grid"><button type="button" class="btn ' + (B.isConnected() ? 'btn-outline-secondary' : 'btn-primary') + '" id="ble-status-btn">' +
                '<i class="bi bi-bluetooth me-1"></i>' + (B.isConnected() ? 'Odpojit companion' : 'Připojit companion') + '</button></div>' +
                '<div class="small text-body-secondary mt-2">' + (B.server() ? 'Historie se ukládá do prohlížeče i na server (jsi přihlášen).'
                    : 'Historie se ukládá jen do tohoto prohlížeče. Po přihlášení se ukládá i na server.') + '</div>');
            $('#ble-status-btn').on('click', function () {
                bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
                if (B.isConnected()) { B.disconnect(); } else { B.connect(); }
            });
            return;
        }
        var html = '<table class="table table-sm mb-0">' +
            row('Worker běží', yesNo(st.worker_alive)) +
            row(isSrv() ? 'Připojeno k meshcore-serveru' : 'Připojeno k HA', yesNo(st.worker_connected)) +
            row('Companion připojen', yesNo(st.companion_connected)) +
            row('Poslední událost', esc(st.last_event_at ? new Date(st.last_event_at).toLocaleString('cs-CZ') : '–')) +
            (st.worker_error && !st.worker_connected ? row('Chyba workeru', '<span class="text-danger">' + esc(st.worker_error) + '</span>') : '') +
            (st.mqtt_total ? row('MQTT brokery', esc(st.mqtt_connected + ' / ' + st.mqtt_total + ' připojeno')) : '') +
            (st.devices_total ? row('Sledovaná zařízení', esc(st.devices_online + ' / ' + st.devices_total + ' online')) : '') +
            (st.traffic ? row('Kredit zpráv (limit HA)', esc(st.traffic.credits + ' / ' + st.traffic.cap + ', doplňuje se ' + st.traffic.per_hour + ' za hodinu' +
                (st.traffic.credits < 1 && st.traffic.next_in ? ', další za ' + fmtWait(st.traffic.next_in) : ''))) : '') +
            (st.queued ? row('Ve frontě k odeslání', esc(st.queued + ' zpráv do kanálů')) : '') +
            (st.last_send_error ? row('Poslední chyba odeslání', esc(st.last_send_error.reason + ' (' + new Date(st.last_send_error.at).toLocaleString('cs-CZ') + ')')) : '') +
            '</table>';
        showModal('Stav připojení', html);
    }

    function showMessageInfo(m) {
        var html = '<table class="table table-sm">' +
            row('Čas', esc(new Date(m.ts).toLocaleString('cs-CZ'))) +
            row('Směr', m.dir === 'in' ? 'Příchozí' : 'Odchozí') +
            (m.sender ? row('Odesílatel', esc(m.sender)) : '') +
            (m.hops != null ? row('Skoků', esc(m.hops)) : '') +
            (m.snr != null ? row('SNR', esc(m.snr) + ' dB') : '') +
            (m.rssi != null ? row('RSSI', esc(m.rssi) + ' dBm') : '') +
            (m.dir === 'out' ? row('Stav', esc({ sent: 'Odesláno', delivered: 'Doručeno', failed: 'Chyba', queued: 'Čeká na volný provoz (odešle se samo)' }[m.status] || m.status)) : '') +
            (m.dir === 'out' && m.type !== 'channel' && m.tries ? row('Pokusů', esc(m.tries + (m.flood ? ' (poslední floodem)' : ' (známou cestou)') +
                (m.status !== 'delivered' && m.ack === null ? ' – ještě se zkouší' : '') +
                (m.trimmed ? ' – část opakování vynechána kvůli limitu provozu' : ''))) : '') +
            (m.dir === 'out' && m.type === 'channel' ? row('Repeaterů', esc(m.repeaters)) : '') +
            (m.error ? row('Chyba', esc(m.error)) : '') +
            '</table>';
        if (m.rx && m.rx.length) {
            html += '<h6 class="mt-3">Zachycené cesty paketu</h6><div class="list-group list-group-flush small mb-1">';
            var routes = buildRoutes(m);
            m.rx.forEach(function (r, i) {
                html += '<div class="list-group-item px-0 py-2 rx-route" data-route="' + i + '">' +
                    '<div class="d-flex align-items-center gap-3 text-body-secondary mb-1">' +
                    (m.rx.length > 1 ? '<span class="route-dot me-n2" style="background:' + ROUTE_COLORS[i % ROUTE_COLORS.length] + '" title="Barva na mapě"></span>' : '') +
                    (r.snr != null ? '<span>SNR <strong class="text-body">' + esc(r.snr) + ' dB</strong></span>' : '') +
                    (r.rssi != null ? '<span>RSSI <strong class="text-body">' + esc(r.rssi) + ' dBm</strong></span>' : '') +
                    (r.route_typename ? '<span class="ms-auto">' + esc(r.route_typename) + '</span>' : '') + '</div>' +
                    pathHtml(r.path, r.path_len, r.path_hash_size, m.dir === 'in' ? (m.sender || 'odesílatel') : 'ty', false, null, routes[i].picks) +
                    '</div>';
            });
            html += '</div>';
            if (m.id && routeReplyText(m)) {
                html += '<div class="d-flex align-items-center gap-2 my-2"><button type="button" class="btn btn-sm btn-outline-primary text-nowrap" id="btn-route-reply">' +
                    '<i class="bi bi-signpost-split me-1"></i>Odpovědět trasou</button><code class="small text-truncate" title="' + esc(routeReplyText(m)) + '">' + esc(routeReplyText(m)) + '</code></div>';
            }
            html += '<div class="small text-body-secondary">Repeatery jsou dohledané podle hashe klíče; u nejednoznačného hashe ' +
                'se vybírá uzel, který na cestě dává geograficky smysl. <span class="hop-amb">Otazník</span> = hash sedí na víc uzlů, klikni pro kandidáty.</div>';
            html += '<div class="path-map-wrap mt-2" id="path-map-wrap"><div class="path-map" id="path-map"></div>' +
                '<button type="button" class="btn btn-sm btn-light path-map-expand" id="path-map-expand" title="Zvětšit mapu"><i class="bi bi-arrows-fullscreen"></i></button></div>' +
                '<div class="small text-body-secondary mt-1" id="path-map-note"></div>';
        }
        if (!(m.rx && m.rx.length) && m.id && routeReplyText(m)) {
            html += '<div class="d-flex align-items-center gap-2 my-2"><button type="button" class="btn btn-sm btn-outline-primary text-nowrap" id="btn-route-reply">' +
                '<i class="bi bi-signpost-split me-1"></i>Odpovědět trasou</button><code class="small text-truncate">' + esc(routeReplyText(m)) + '</code></div>';
        }
                html += heardSection(m);
        if (m.dups > 0 || m.dup_of) {
            html += '<div id="copies-wrap"><h6 class="mt-3">' + (m.dir === 'out' ? 'Odesláno vícekrát' : 'Přijato vícekrát') + '</h6>' +
                '<div class="text-body-secondary small"><span class="spinner-border spinner-border-sm me-1"></span> Načítám kopie…</div></div>';
        }
        if (m.dir === 'out' && m.body && m.id) {
            // u skupiny opakovaných odeslání rozhoduje poslední pokus (stejně jako šipka v bublině)
            var rm = groupView(m)._retry || m, multi = !!(m.group && m.group.last);
            var hint = '';
            if (unconfirmed(rm)) {
                hint = (multi ? 'Poslední pokus: ' : '') + (rm.status === 'failed' ? 'zprávu se nepodařilo odeslat.'
                    : (rm.type === 'channel' && rm.repeaters > 0 && heardZero(rm)) ? 'zprávu zatím nezachytil žádný observer analyzeru.'
                    : (rm.type === 'channel' ? 'zprávu zatím nezachytil žádný repeater.' : 'nepřišlo potvrzení doručení (ACK).'));
                hint = hint.charAt(0).toUpperCase() + hint.slice(1);
            }
            html += '<div class="d-grid mt-3">' +
                (hint ? '<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-circle me-1"></i>' + esc(hint) + '</div>' : '') +
                (isResent(rm.id) ? '<div class="small text-body-secondary mb-2"><i class="bi bi-check2 me-1"></i>' +
                    (multi ? 'Poslední pokus už byl odeslán znovu.' : 'Tahle zpráva už byla odeslána znovu.') + '</div>' : '') +
                '<button type="button" class="btn btn-primary" id="btn-resend"><i class="bi bi-arrow-clockwise me-1"></i> Odeslat znovu</button>' +
                '</div>';
        }
        showModal('Detail zprávy', html);
        if (m.id) {
            $('#info-body').append('<div class="text-end mt-3"><button type="button" class="btn btn-sm btn-outline-danger" id="btn-msg-delete">' +
                '<i class="bi bi-trash me-1"></i>Smazat zprávu</button></div>');
            $('#btn-msg-delete').on('click', function () {
                if (!confirm('Smazat tuto zprávu' + (m.dups ? ' i s jejími duplicitami' : '') + '?')) { return; }
                api('message_delete', { id: m.id }, 'POST').done(function () {
                    delete S.messages[m.id];
                    bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
                    renderMessages();
                    api('conversations').done(function (r) { setConversations(r.conversations); });
                }).fail(function (xhr) { toast(errText(xhr), 'danger'); });
            });
        }
        $('#btn-route-reply').on('click', function () {
            bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
            routeReply(m);
        });
                $('#btn-resend').off('click').on('click', function () {
            bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
            resend(m);
        });
        if (m.dups > 0 || m.dup_of) {
            loadCopies(m);
        }
        if (m.dir === 'out' && m.type === 'channel' && m.id) {
            loadHeard(m);
        }
        if (m.rx && m.rx.length) { initPathMap(m, routes); }
    }

    function loadCopies(m) {
        api('copies', { id: m.id }).done(function (r) {
            var list = r.messages;
            if (!list.length) { $('#copies-wrap').remove(); return; }
            var first = new Date(list[0].ts).getTime();
            if (m.dir === 'out') {
                var oh = '<h6 class="mt-3">Odesláno ' + list.length + '×</h6>' +
                    '<div class="table-responsive"><table class="table table-sm small align-middle" id="copies-table">' +
                    '<thead><tr><th>#</th><th>Čas</th><th title="Repeatery zachycené companionem">Repeatery</th>' +
                    (m.type === 'channel' ? '<th title="Heard v analyzeru">Heard</th><th title="Observeři kromě tvého">Observeři</th>' : '<th>Doručení</th>') +
                    '</tr></thead><tbody>';
                list.forEach(function (c, i) {
                    var diff = Math.round((new Date(c.ts).getTime() - first) / 1000);
                    var rel = i === 0 ? 'první' : '+' + fmtDiff(diff);
                    var h = c.heard || {};
                    oh += '<tr class="' + (c.id === m.id ? 'current' : '') + '"><td>' + (i + 1) + '</td>' +
                        '<td class="text-nowrap">' + esc(fmtTime(c.ts)) + ' <span class="text-body-secondary">(' + esc(rel) + ')</span></td>' +
                        '<td>' + (c.status === 'failed' ? '<span class="text-danger">chyba</span>' : esc(c.repeaters)) + '</td>' +
                        (m.type === 'channel'
                            ? '<td>' + (h.state === 'heard' ? esc(h.repeats || 0) : h.state === 'pending' ? '…' : h.state === 'none' ? '0' : '–') + '</td>' +
                              '<td>' + (h.state === 'heard' ? esc(h.count) : h.state === 'none' ? '0' : '–') + '</td>'
                            : '<td>' + (c.status === 'delivered' ? '<i class="bi bi-check2-all"></i> ACK' : '–') + '</td>') +
                        '</tr>';
                });
                $('#copies-wrap').html(oh + '</tbody></table></div>');
                return;
            }
            var html = '<h6 class="mt-3">Přijato ' + list.length + '×</h6>' +
                '<div class="table-responsive"><table class="table table-sm small align-middle" id="copies-table">' +
                '<thead><tr><th>#</th><th>Čas</th><th>Skoků</th><th>SNR</th><th>RSSI</th><th>Cesty</th></tr></thead><tbody>';
            list.forEach(function (c, i) {
                var diff = Math.round((new Date(c.ts).getTime() - first) / 1000);
                var rel = i === 0 ? 'první' : '+' + fmtDiff(diff);
                var paths = (c.rx || []).map(function (x) { return pathHtml(x.path, x.path_len, x.path_hash_size, null, true); }).join('');
                html += '<tr class="' + (c.id === m.id ? 'current' : '') + '">' +
                    '<td>' + (i + 1) + '</td>' +
                    '<td class="text-nowrap">' + esc(fmtTime(c.ts)) + ' <span class="text-body-secondary">(' + esc(rel) + ')</span></td>' +
                    '<td>' + (c.hops != null ? esc(c.hops === 0 ? 'přímo' : c.hops) : '–') + '</td>' +
                    '<td>' + (c.snr != null ? esc(c.snr.toFixed(1)) : '–') + '</td>' +
                    '<td>' + (c.rssi != null ? esc(c.rssi) : '–') + '</td>' +
                    '<td>' + (paths || '–') + '</td></tr>';
            });
            html += '</tbody></table></div>';
            $('#copies-wrap').html(html);
        }).fail(function () {
            $('#copies-wrap').html('<div class="text-danger small mt-3">Kopie se nepodařilo načíst.</div>');
        });
    }

    function showContactInfo(c) {
        var html = '<table class="table table-sm mb-0">' +
            row('Jméno', esc(c.name)) +
            row('Typ', esc(TYPE_NAMES[c.type] || 'neznámý')) +
            row('Klíč (prefix)', '<span class="font-monospace">' + esc(c.pk) + '</span>') +
            row('Cesta', c.path_len == null || c.path_len < 0 ? 'flood (neznámá)' : c.path_len === 0 ? 'přímo'
                : (c.path ? pathHtml(c.path, c.path_len, null, null, true) : esc(c.path_len) + ' hop')) +
            row('Poslední advert', esc(c.last_advert ? new Date(c.last_advert).toLocaleString('cs-CZ') + ' (' + fmtAgo(c.last_advert) + ')' : '–')) +
            (c.lat != null ? row('Poloha', '<a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=' +
                c.lat + '&mlon=' + c.lon + '#map=13/' + c.lat + '/' + c.lon + '">' + c.lat.toFixed(5) + ', ' + c.lon.toFixed(5) + '</a>') : '') +
            scopeRows(c.scopes) +
            '</table>';
        html += '<div class="d-flex gap-2 mt-3"><button type="button" class="btn btn-outline-secondary btn-sm" id="info-path">' +
            '<i class="bi bi-signpost-2 me-1"></i>Cesta…</button>' + traceButton(c.pk) + '</div>';
        var remote = (c.type === 2 || c.type === 3) && can('remote'); // repeater / room server
        if (remote) { html += remotePanelHtml(); }
        showModal(c.name, html);
        if (remote) { initRemote({ pk: c.pk, name: c.name }); }
    }

    // ---------- vzhled: pozadí ----------
    var PATTERN_NAMES = { dots: 'Tečky', grid: 'Mřížka', diagonal: 'Šrafy', checks: 'Šachovnice', circles: 'Kruhy' };
    var BG_COLORS = ['#f8f9fa', '#e7f1ff', '#e6f4ea', '#fff4e5', '#fdecef', '#f3e8ff', '#e0f7fa', '#fffde7',
                     '#2b2f36', '#1e2a3a', '#203a2d', '#3a2a1e'];
    var AVATAR_COLORS = ['#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#e0a800',
                         '#198754', '#20c997', '#0dcaf0', '#495057', '#795548'];

    function effectiveBg(conv) {
        var own = conv ? convSet(conv).bg : null;
        return own || S.settings.default_bg || null;
    }

    function paintBg($el, bg) {
        $el.removeClass(function (i, c) { return (c.match(/bg-pattern-\S+|bg-image/g) || []).join(' '); })
            .css({ backgroundColor: '', backgroundImage: '' });
        if (!bg || bg.type === 'none') { return; }
        if (bg.type === 'color') {
            $el.css('background-color', bg.color);
        } else if (bg.type === 'pattern') {
            $el.addClass('bg-pattern-' + bg.value);
            if (bg.color) { $el.css('background-color', bg.color); }
        } else if (bg.type === 'image') {
            var d = bg.dim || 0;
            $el.addClass('bg-image').css('background-image',
                'linear-gradient(rgba(0,0,0,' + d + '), rgba(0,0,0,' + d + ')), url("bg.php?f=' + encodeURIComponent(bg.value) + '")');
        }
    }

    function applyBackground() {
        paintBg($('#messages'), S.current ? effectiveBg(S.current) : null);
    }

    function sameBg(a, b) { return JSON.stringify(a || null) === JSON.stringify(b || null); }

    // Výběr pozadí. allowDefault = nabídnout "Výchozí" (u konverzace). onChange(bg) uloží změnu.
    function bgPicker($box, current, allowDefault, onChange) {
        function render() {
            var bg = current;
            var $o = $('<div class="bg-options mb-3">');
            function tile(label, value, paint) {
                var $t = $('<div class="bg-option">').toggleClass('active', sameBg(bg, value))
                    .append($('<span class="label">').text(label))
                    .on('click', function () { set(value); });
                if (paint) { paintBg($t, paint); }
                return $t;
            }
            if (allowDefault) { $o.append(tile('Výchozí', null, S.settings.default_bg)); }
            $o.append(tile('Žádné', { type: 'none' }, null));
            var baseColor = bg && (bg.type === 'pattern' || bg.type === 'color') ? bg.color : null;
            S.patterns.forEach(function (p) {
                var v = { type: 'pattern', value: p, color: baseColor || null };
                var $t = tile(PATTERN_NAMES[p] || p, v, v);
                $t.toggleClass('active', !!bg && bg.type === 'pattern' && bg.value === p);
                $o.append($t);
            });
            (S.images || []).forEach(function (img) {
                var v = { type: 'image', value: img, dim: bg && bg.type === 'image' ? bg.dim : 0.2 };
                var $t = $('<div class="bg-option">')
                    .toggleClass('active', !!bg && bg.type === 'image' && bg.value === img)
                    .on('click', function () { set(v); })
                    .append($('<button type="button" class="btn btn-danger del" title="Smazat obrázek">').html('<i class="bi bi-x"></i>')
                        .on('click', function (e) { e.stopPropagation(); deleteImage(img); }));
                paintBg($t, { type: 'image', value: img, dim: 0 });
                $o.append($t);
            });
            var $up = $('<label class="bg-option text-body-secondary" title="Nahrát obrázek">')
                .html('<span class="d-flex flex-column align-items-center h-100 justify-content-center"><i class="bi bi-upload fs-5"></i>Nahrát</span>')
                .append($('<input type="file" accept="image/*" class="d-none">').on('change', function () {
                    if (this.files[0]) { uploadImage(this.files[0], $up); }
                }));
            if (!S.ble) { $o.append($up); }

            var $colors = $('<div class="mb-2">');
            if (!bg || bg.type !== 'image') {
                $colors.append('<div class="small text-body-secondary mb-1">Barva podkladu</div>');
                var $sw = $('<div class="swatches">');
                $sw.append($('<div class="swatch d-flex align-items-center justify-content-center" title="Bez barvy">')
                    .html('<i class="bi bi-slash-lg small"></i>').toggleClass('active', !baseColor)
                    .on('click', function () { pickColor(null); }));
                BG_COLORS.forEach(function (col) {
                    $sw.append($('<div class="swatch">').css('background', col).attr('title', col)
                        .toggleClass('active', baseColor === col).on('click', function () { pickColor(col); }));
                });
                $sw.append($('<input type="color" class="swatch-custom" title="Vlastní barva">')
                    .val(baseColor || '#ffffff').on('change', function () { pickColor(this.value); }));
                $colors.append($sw);
            } else {
                $colors.append($('<label class="form-label small text-body-secondary mb-1">').text('Ztmavení obrázku: ' + Math.round((bg.dim || 0) * 100) + ' %'))
                    .append($('<input type="range" class="form-range" min="0" max="0.8" step="0.05">').val(bg.dim || 0)
                        .on('input', function () { $(this).prev().text('Ztmavení obrázku: ' + Math.round(this.value * 100) + ' %'); })
                        .on('change', function () { set({ type: 'image', value: bg.value, dim: +this.value }); }));
            }
            $box.empty().append($o, $colors);
        }
        function pickColor(col) {
            var bg = current;
            if (bg && bg.type === 'pattern') {
                set({ type: 'pattern', value: bg.value, color: col });
            } else {
                set(col ? { type: 'color', color: col } : (allowDefault ? null : { type: 'none' }));
            }
        }
        function set(v) {
            current = v;
            render();
            onChange(v);
        }
        function uploadImage(file, $tile) {
            $tile.html('<span class="spinner-border spinner-border-sm m-auto"></span>');
            resizeImage(file, 1920, function (blob) {
                if (!blob) { toast('Soubor se nepodařilo načíst jako obrázek.', 'danger'); render(); return; }
                var fd = new FormData();
                fd.append('image', blob, 'background.jpg');
                $.ajax({ url: 'api.php?action=bg_upload', method: 'POST', data: fd, processData: false, contentType: false,
                         dataType: 'json', headers: { 'X-CSRF-Token': CSRF } })
                    .done(function (r) {
                        S.images = r.images;
                        set({ type: 'image', value: r.image, dim: 0.2 });
                    })
                    .fail(function (xhr) { toast(errText(xhr), 'danger'); render(); });
            });
        }
        function deleteImage(img) {
            if (!confirm('Smazat obrázek? Chaty, které ho používají, dostanou výchozí pozadí.')) { return; }
            api('bg_delete', { image: img }, 'POST').done(function (r) {
                S.images = r.images;
                S.settings = r.settings;
                if (current && current.type === 'image' && current.value === img) { current = allowDefault ? null : { type: 'none' }; }
                render();
                applyBackground();
            }).fail(function (xhr) { toast(errText(xhr), 'danger'); });
        }
        render();
    }

    function resizeImage(file, max, cb) {
        var url = URL.createObjectURL(file), img = new Image();
        img.onload = function () {
            var k = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
            var c = document.createElement('canvas');
            c.width = Math.round(img.naturalWidth * k);
            c.height = Math.round(img.naturalHeight * k);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            URL.revokeObjectURL(url);
            c.toBlob(function (b) { cb(b); }, 'image/jpeg', 0.85);
        };
        img.onerror = function () { URL.revokeObjectURL(url); cb(null); };
        img.src = url;
    }

    function loadImages(cb) {
        api('bg_list').done(function (r) { S.images = r.images; cb(); }).fail(cb);
    }

    // ---------- okno Vzhled ----------
    var THEMES = [['light', 'bi-sun', 'Světlý'], ['dark', 'bi-moon-stars', 'Tmavý'],
                  ['auto', 'bi-circle-half', 'Podle systému'], ['sun', 'bi-sunrise', 'Podle slunce']];

    function sunInfo() {
        var sun = S.state.sun;
        if (!sun || !sun.state || sun.state === 'unknown') { return 'Stav slunce z Home Assistantu zatím není k dispozici – použije se nastavení systému.'; }
        var down = window.mcTheme.sunIsDown(sun);
        var next = down ? sun.rising : sun.setting;
        return (down ? 'Slunce je pod obzorem, východ ' : 'Slunce je nad obzorem, západ ') +
            (next ? new Date(next).toLocaleString('cs-CZ', { weekday: 'short', hour: '2-digit', minute: '2-digit' }) : '–') + '.';
    }

    function showAppearance() {
        loadImages(function () {
            var mode = loadPref('theme', 'auto');
            var html = '<h6>Motiv <span class="small text-body-secondary fw-normal">(jen na tomto zařízení)</span></h6>' +
                '<div class="row g-2 mb-2" role="group" id="theme-group">' +
                THEMES.map(function (t) {
                    return '<div class="col-6 col-sm-3"><input type="radio" class="btn-check" name="theme" id="th-' + t[0] + '" value="' + t[0] + '"' + (mode === t[0] ? ' checked' : '') + '>' +
                        '<label class="btn btn-outline-primary btn-sm w-100 py-2" for="th-' + t[0] + '"><i class="bi ' + t[1] + ' d-block fs-5"></i>' + t[2] + '</label></div>';
                }).join('') + '</div>' +
                '<div class="small text-body-secondary mb-4" id="sun-info"></div>' +
                '<h6>Výchozí pozadí chatů</h6><div class="small text-body-secondary mb-2">Platí pro všechny chaty, které nemají vlastní pozadí. Ukládá se na server.</div>' +
                '<div id="default-bg-picker"></div>';
            showModal('Vzhled', html);
            function updSun() { $('#sun-info').text(loadPref('theme', 'auto') === 'sun' ? sunInfo() : ''); }
            updSun();
            $('#theme-group input').on('change', function () {
                savePref('theme', this.value);
                window.mcTheme.apply();
                updSun();
            });
            bgPicker($('#default-bg-picker'), S.settings.default_bg, false, function (bg) {
                api('default_bg', { bg: bg }, 'POST').done(function (r) {
                    S.settings = r.settings;
                    applyBackground();
                }).fail(function (xhr) { toast(errText(xhr), 'danger'); });
            });
        });
    }

    // ---------- nastavení konverzace ----------
    function saveConv(conv, data) {
        return api('conv_settings', $.extend({ conv: conv }, data), 'POST').done(function (r) {
            S.settings = r.settings;
            renderList();
            renderBadges();
            if (S.current === conv) { renderHeader(); applyBackground(); }
        }).fail(function (xhr) { toast(errText(xhr), 'danger'); });
    }

    function toggleFav(conv) {
        var fav = !isFav(conv);
        saveConv(conv, { fav: fav }).done(function () {
            toast(fav ? 'Přidáno do oblíbených' : 'Odebráno z oblíbených', 'secondary');
        });
    }

    function showConvSettings(conv) {
        var c = S.convs[conv];
        if (!c) { return; }
        loadImages(function () {
            var cs = convSet(conv);
            var html =
                '<div class="form-check form-switch mb-4">' +
                '<input class="form-check-input" type="checkbox" role="switch" id="cs-fav"' + (cs.fav ? ' checked' : '') + '>' +
                '<label class="form-check-label" for="cs-fav"><i class="bi bi-star-fill fav-star me-1"></i>Oblíbené</label></div>' +
                '<h6>Barva kroužku</h6><div class="d-flex align-items-center gap-3 mb-4"><div id="cs-avatar"></div><div class="swatches" id="cs-colors"></div></div>' +
                '<h6>Pozadí chatu</h6><div id="cs-bg"></div>' +
                '<h6 class="mt-4">Historie zpráv</h6>' +
                '<div class="d-flex flex-wrap align-items-center gap-2 mb-2"><label class="small text-body-secondary" for="cs-retention">Uchovávat</label>' +
                '<select class="form-select form-select-sm w-auto" id="cs-retention">' + retentionOptions(cs.retention, true) + '</select></div>' +
                '<button type="button" class="btn btn-sm btn-outline-danger" id="cs-clear"><i class="bi bi-trash me-1"></i>Smazat historii konverzace</button>' +
                (c.kind === 'channel' ?
                    '<h6 class="mt-4">Region pro odesílání</h6>' +
                    '<div class="small text-body-secondary mb-2">Zprávy do tohoto kanálu se pošlou floodem omezeným na region (scope) – ' +
                    'repeatery mimo region je nepřepošlou. Platí jen pro odeslání, příjem se nemění.</div>' +
                    '<div class="d-flex flex-wrap align-items-center gap-2 mb-1">' +
                    '<select class="form-select form-select-sm w-auto" id="cs-scope-mode">' +
                    '<option value="">Výchozí companionu</option>' + (can('region_none') ? '<option value="*">Bez regionu (všude)</option>' : '') + '<option value="r">Region…</option></select>' +
                    '<div class="input-group input-group-sm w-auto d-none" id="cs-scope-box"><span class="input-group-text">#</span>' +
                    '<input type="text" class="form-control" id="cs-scope" list="cs-scope-list" maxlength="30" placeholder="např. cz" autocomplete="off" style="max-width:10rem">' +
                    '<button class="btn btn-outline-primary" type="button" id="cs-scope-save">Uložit</button></div>' +
                    '<datalist id="cs-scope-list">' + knownScopes().map(function (x) { return '<option value="' + esc(x) + '">'; }).join('') + '</datalist></div>' +
                    '<div class="small text-body-secondary mb-3">Záleží na velikosti písmen. Region musí znát repeatery v okolí, jinak zpráva nedojde nikam.</div>' +
                    '<h6 class="mt-4">Kanál</h6><div class="small text-body-secondary mb-2">Slot ' + c.idx + ' v companionu.</div>' +
                    '<div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="cs-secret"><i class="bi bi-key me-1"></i>Zobrazit klíč pro sdílení</button>' +
                    '<button type="button" class="btn btn-sm btn-danger" id="cs-remove"><i class="bi bi-x-circle me-1"></i>Odebrat kanál</button></div>' +
                    '<div id="cs-secret-out" class="mt-2"></div>' : '');
            showModal(c.name, html);

            $('#cs-retention').on('change', function () {
                saveConv(conv, { retention: this.value === '' ? null : +this.value }).done(function () {
                    toast('Doba uchování uložena', 'secondary');
                    if (S.current === conv) { openConv(conv, true); }
                });
            });
            $('#cs-clear').on('click', function () { clearConversation(conv); });
            var curScope = cs.scope || '';
            $('#cs-scope-mode').val(curScope === '' ? '' : curScope === '*' ? '*' : 'r');
            $('#cs-scope').val(curScope && curScope !== '*' ? curScope : '');
            $('#cs-scope-box').toggleClass('d-none', $('#cs-scope-mode').val() !== 'r');
            var saveScope = function (v) {
                saveConv(conv, { scope: v }).done(function () {
                    toast(v ? 'Zprávy do kanálu se pošlou ' + (v === '*' ? 'bez regionu' : 'do regionu #' + String(v).replace(/^#/, '')) : 'Region: výchozí companionu', 'secondary');
                });
            };
            $('#cs-scope-mode').on('change', function () {
                $('#cs-scope-box').toggleClass('d-none', this.value !== 'r');
                if (this.value === 'r') { $('#cs-scope').trigger('focus'); return; }
                saveScope(this.value);
            });
            $('#cs-scope-save').on('click', function () {
                var v = $.trim($('#cs-scope').val()).replace(/^#/, '');
                if (!v) { toast('Zadej název regionu', 'warning'); return; }
                saveScope(v);
            });
            $('#cs-scope').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#cs-scope-save').trigger('click'); } });
            $('#cs-secret').on('click', function () {
                var $b = $(this).prop('disabled', true);
                api('channel_secret', { idx: c.idx }, 'POST').done(function (r) {
                    $('#cs-secret-out').html('<div class="small text-body-secondary mb-1">Název a klíč pro přidání kanálu jinde (nesdílej veřejně):</div>' +
                        '<div class="font-monospace small p-2 rounded bg-body-tertiary user-select-all text-break">' + esc(r.name || c.name) + '<br>' + esc(r.secret) + '</div>');
                }).fail(function (xhr) { toast(errText(xhr), 'danger'); }).always(function () { $b.prop('disabled', false); });
            });
            $('#cs-remove').on('click', function () {
                removeChannel(conv, $(this), function () { bootstrap.Modal.getOrCreateInstance('#info-modal').hide(); });
            });

            $('#cs-fav').on('change', function () { saveConv(conv, { fav: this.checked }); });

            function renderColors() {
                var color = convSet(conv).color || null;
                $('#cs-avatar').empty().append(avatar(c));
                var $sw = $('#cs-colors').empty();
                $sw.append($('<div class="swatch d-flex align-items-center justify-content-center" title="Automaticky">')
                    .html('<i class="bi bi-magic small"></i>').toggleClass('active', !color)
                    .on('click', function () { setColor(null); }));
                AVATAR_COLORS.forEach(function (col) {
                    $sw.append($('<div class="swatch">').css('background', col).toggleClass('active', color === col)
                        .on('click', function () { setColor(col); }));
                });
                $sw.append($('<input type="color" class="swatch-custom" title="Vlastní barva">')
                    .val(color || '#0d6efd').on('change', function () { setColor(this.value); }));
            }
            function setColor(col) {
                S.settings.convs[conv] = $.extend({}, convSet(conv), { color: col }); // okamžitá odezva
                renderColors();
                saveConv(conv, { color: col }).done(renderColors);
            }
            renderColors();

            bgPicker($('#cs-bg'), cs.bg || null, true, function (bg) { saveConv(conv, { bg: bg }); });
        });
    }

    // ---------- doba uchování ----------
    var RETENTION = [[0, 'navždy'], [365, '1 rok'], [180, '6 měsíců'], [90, '3 měsíce'], [30, '1 měsíc'], [7, '1 týden']];

    function retentionName(d) {
        var f = RETENTION.filter(function (r) { return r[0] === d; })[0];
        return f ? f[1] : d + ' dní';
    }

    function retentionOptions(current, withDefault) {
        var o = withDefault ? '<option value=""' + (current == null ? ' selected' : '') + '>výchozí (' + esc(retentionName(S.settings.retention || 0)) + ')</option>' : '';
        RETENTION.forEach(function (r) {
            o += '<option value="' + r[0] + '"' + (current === r[0] ? ' selected' : '') + '>' + r[1] + '</option>';
        });
        return o;
    }

    function showRetention() {
        showModal('Historie zpráv',
            '<p class="small text-body-secondary">Zprávy starší než zvolená doba se automaticky mažou z databáze klienta (kontrola jednou za hodinu). ' +
            'U jednotlivých konverzací lze dobu změnit v jejich nastavení (ikona palety v hlavičce chatu).</p>' +
            '<label class="form-label" for="ret-global">Výchozí doba uchování</label>' +
            '<select class="form-select" id="ret-global">' + retentionOptions(S.settings.retention || 0, false) + '</select>');
        $('#ret-global').on('change', function () {
            var days = +this.value;
            if (days > 0 && !confirm('Smazat všechny zprávy starší než ' + retentionName(days) + ' (kromě konverzací s vlastním nastavením)?')) {
                this.value = S.settings.retention || 0;
                return;
            }
            api('retention', { days: days }, 'POST').done(function (r) {
                S.settings = r.settings;
                toast('Uloženo' + (r.deleted ? ' – smazáno zpráv: ' + r.deleted : ''), 'secondary');
                api('conversations').done(function (x) { setConversations(x.conversations); });
                if (S.current) { openConv(S.current, true); }
            }).fail(function (xhr) { toast(errText(xhr), 'danger'); });
        });
    }

    // ---------- cesta ke kontaktu (DM) ----------
    function pathSummary(c) {
        if (c.path_len == null || c.path_len < 0) {
            return '<div class="alert alert-secondary py-2 small mb-2">Cesta není známá – zprávy se posílají floodem přes celou síť.</div>';
        }
        if (c.path_len === 0) {
            return '<div class="hop-path mb-2"><span class="hop hop-end">ty</span><i class="bi bi-arrow-right hop-arrow"></i>' +
                '<span class="hop hop-end">' + esc(c.name) + '</span></div><div class="small text-body-secondary mb-2">Přímo, bez repeaterů.</div>';
        }
        return (c.path ? pathHtml(c.path, c.path_len, null, 'ty', false, c.name) : '') +
            '<div class="small text-body-secondary mb-2">' + c.path_len + ' ' + (c.path_len === 1 ? 'skok' : c.path_len < 5 ? 'skoky' : 'skoků') +
            ' – zprávy jdou přímo touto cestou.</div>';
    }

    function showPath(conv) {
        var c = S.convs[conv];
        if (!c || c.kind !== 'contact') { return; }
        showModal('Cesta – ' + c.name,
            '<div id="path-current">' + pathSummary(c) + '</div>' +
            '<div class="d-flex flex-wrap gap-2 mt-3">' +
            '<button type="button" class="btn btn-sm btn-outline-danger" id="path-reset"><i class="bi bi-arrow-counterclockwise me-1"></i>Resetovat cestu</button>' +
            (can('path_discover') ? '<button type="button" class="btn btn-sm btn-outline-primary" id="path-discover"><i class="bi bi-search me-1"></i>Zjistit cestu</button>' : '') +
            traceButton(c.pk) + '</div>' +
            '<div class="small text-body-secondary mt-2">Reset smaže uloženou cestu, další zpráva půjde floodem a cesta se naučí znovu. ' +
            '„Zjistit cestu“ pošle dotaz floodem a počká na odpověď (může trvat až desítky sekund).</div>');

        function refresh(r) {
            setConversations(r.conversations);
            var nc = S.convs[conv];
            if (nc) { $('#path-current').html(pathSummary(nc)); }
        }
        function busy($b, on, html) {
            $b.prop('disabled', on);
            if (on) { $b.data('html', $b.html()).html('<span class="spinner-border spinner-border-sm me-1"></span>' + html); } else { $b.html($b.data('html')); }
        }
        $('#path-reset').on('click', function () {
            if (!confirm('Resetovat cestu ke kontaktu „' + c.name + '“?')) { return; }
            var $b = $(this); busy($b, true, 'Resetuji…');
            api('path_reset', { pk: c.pk }, 'POST').done(function (r) { refresh(r); toast('Cesta resetována', 'secondary'); })
                .fail(function (xhr) { toast(errText(xhr), 'danger'); }).always(function () { busy($b, false); });
        });
        $('#path-discover').on('click', function () {
            var $b = $(this); busy($b, true, 'Hledám cestu…');
            api('path_discover', { pk: c.pk }, 'POST').done(function (r) { refresh(r); toast('Cesta zjištěna', 'success'); })
                .fail(function (xhr) { toast(errText(xhr), 'danger'); }).always(function () { busy($b, false); });
        });
    }

    // ---------- přidání kontaktu ----------
    function showAddContact() {
        var html =
            '<label class="form-label" for="ac-input">Vizitka, veřejný klíč nebo exportovaná karta</label>' +
            '<div class="input-group mb-1"><input type="text" class="form-control font-monospace" id="ac-input" autocomplete="off" spellcheck="false" ' +
            'placeholder="&lt;klíč:typ:jméno&gt; nebo hex"><button class="btn btn-primary" type="button" id="ac-go">Pokračovat</button></div>' +
            '<div class="form-text mb-1">Vizitka ze zprávy, veřejný klíč (64 hex) nebo jeho prefix (min. 12 hex), případně karta z <code>export_contact</code>.</div>' +
            '<div class="text-danger small mb-3" id="ac-error"></div>' +
            '<h6 class="mt-2 d-flex align-items-center justify-content-between">Slyšené uzly <span class="small text-body-secondary fw-normal" id="ac-count"></span></h6>' +
            '<div class="small text-body-secondary mb-2">Companion zachytil jejich advert, ale nemáš je v kontaktech.</div>' +
            '<input type="search" class="form-control form-control-sm mb-2" id="ac-filter" placeholder="Filtrovat podle jména nebo klíče…">' +
            '<div class="list-group list-group-flush small" id="ac-list"><div class="text-center p-3"><span class="spinner-border spinner-border-sm"></span></div></div>';
        showModal('Přidat kontakt', html);

        var nodes = [];
        function renderNodes() {
            var q = norm($('#ac-filter').val().trim());
            var list = nodes.filter(function (n) { return !q || norm(n.name).indexOf(q) >= 0 || n.pk.indexOf(q) === 0; });
            $('#ac-count').text(nodes.length ? list.length + ' / ' + nodes.length : '');
            var $l = $('#ac-list').empty();
            if (!list.length) {
                $l.append('<div class="text-body-secondary p-2">' + (nodes.length ? 'Nic neodpovídá filtru.' : 'Žádné slyšené uzly mimo kontakty.') + '</div>');
                return;
            }
            list.slice(0, 200).forEach(function (n) {
                $('<div class="list-group-item d-flex align-items-center gap-2 px-0">')
                    .append($('<i class="bi text-body-secondary">').addClass(TYPE_ICONS[n.type] || 'bi-cpu'))
                    .append($('<div class="flex-grow-1 min-w-0">')
                        .append($('<div class="fw-semibold text-truncate">').text(n.name || n.pk12))
                        .append($('<div class="text-body-secondary text-truncate">').text(
                            (TYPE_NAMES[n.type] || 'Uzel') + ' · ' + n.pk12 + ' · advert ' + fmtAgo(n.last_advert) +
                            (n.path_len != null && n.path_len >= 0 ? ' · ' + n.path_len + ' hop' : ''))))
                    .append($('<button type="button" class="btn btn-sm btn-outline-secondary text-nowrap">')
                        .attr('title', n.type === 2 || n.type === 3 ? 'Detail, stav / vzdálená správa' : 'Detail uzlu')
                        .attr('data-remote', n.pk12).html('<i class="bi ' + (n.type === 2 || n.type === 3 ? 'bi-activity' : 'bi-info-circle') + '"></i>'))
                    .append($('<button type="button" class="btn btn-sm btn-outline-primary text-nowrap">').attr('data-pk', n.pk12)
                        .html('<i class="bi bi-person-plus me-1"></i>Přidat'))
                    .appendTo($l);
            });
            if (list.length > 200) { $l.append('<div class="text-body-secondary p-2">… a dalších ' + (list.length - 200) + ' – upřesni filtr.</div>'); }
        }
        api('discovered').done(function (r) {
            if (!r.discovered.available) {
                $('#ac-list').html('<div class="text-body-secondary p-2">Seznam zatím není k dispozici – worker ho načítá z ' + (isSrv()
                    ? 'meshcore-serveru.</div>' : 'Home Assistantu (vyžaduje režim Contact Discovery „entity“ v integraci MeshCore).</div>'));
                return;
            }
            nodes = r.discovered.nodes;
            renderNodes();
        }).fail(function (xhr) { $('#ac-list').html('<div class="text-danger p-2">' + esc(errText(xhr)) + '</div>'); });
        $('#ac-filter').on('input', renderNodes);

        $('#ac-list').on('click', 'button[data-remote]', function () {
            var pk = $(this).attr('data-remote');
            nodes.forEach(function (n) { if (n.pk12 === pk) { showRemoteNode(n); } });
        });
        $('#ac-list').on('click', 'button[data-pk]', function () {
            var $b = $(this), pk = $b.attr('data-pk');
            $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
            api('contact_add', { pk: pk }, 'POST').done(function (r) {
                setConversations(r.conversations);
                nodes = nodes.filter(function (n) { return n.pk12 !== pk; });
                renderNodes();
                toast('Kontakt přidán', 'success');
            }).fail(function (xhr) {
                toast(errText(xhr), 'danger');
                $b.prop('disabled', false).html('<i class="bi bi-person-plus me-1"></i>Přidat');
            });
        });

        function go() {
            var v = $.trim($('#ac-input').val()), m;
            $('#ac-error').text('');
            CARD_RE.lastIndex = 0;
            if ((m = CARD_RE.exec(v))) { showCard(m[1].toLowerCase(), +m[2], m[3]); return; }
            var hex = v.replace(/\s+/g, '').toLowerCase();
            if (/^[0-9a-f]{100,}$/.test(hex)) {
                if (!can('contact_import')) { $('#ac-error').text('Import exportované karty meshcore-server zatím neumí – vlož vizitku nebo veřejný klíč.'); return; }
                var $b = $('#ac-go').prop('disabled', true);
                api('contact_import', { card: hex }, 'POST').done(function (r) {
                    setConversations(r.conversations);
                    toast('Kontakt naimportován', 'success');
                    $('#ac-input').val('');
                }).fail(function (xhr) { $('#ac-error').text(errText(xhr)); }).always(function () { $b.prop('disabled', false); });
                return;
            }
            if (/^[0-9a-f]{12,64}$/.test(hex)) { showCard(hex, null, ''); return; }
            if ((m = v.match(/[0-9a-fA-F]{64}/))) { showCard(m[0].toLowerCase(), null, ''); return; }
            $('#ac-error').text('Nerozpoznáno – vlož vizitku <klíč:typ:jméno>, veřejný klíč (64 hex), jeho prefix (min. 12 hex) nebo exportovanou kartu.');
        }
        $('#ac-go').on('click', go);
        $('#ac-input').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    }

    // ---------- room server ----------
    function roomPwKey(pk) { return 'mc.roompw.' + pk; }
    var roomAuto = {};

    function roomLogin(c, pw, $out, remember) {
        if (!can('room_login')) { return $.Deferred().resolve({}).promise(); }
        if ($out) { $out.html('<span class="text-body-secondary"><span class="spinner-border spinner-border-sm me-1"></span>Přihlašuji se do room serveru…</span>'); }
        return api('room_login', { pk: c.pk, password: pw, remember: remember !== false }, 'POST').done(function (r) {
            var msg = 'Přihlášen do „' + c.name + '“' + (r.admin === true ? ' jako admin' : r.admin === false ? ' jako host' : '') +
                '. Příspěvky, které jsi ještě neviděl, server pošle během několika sekund.';
            if ($out) { $out.html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + esc(msg) + '</span>'); } else { toast(msg, 'success'); }
        }).fail(function (xhr) {
            if ($out) { $out.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + esc(errText(xhr)) + '</span>'); }
            else { toast('Room server: ' + errText(xhr), 'warning'); }
        });
    }

    function showRoomLogin(c) {
        if (!c) { return; }
        var saved = null;
        try { saved = sessionStorage.getItem(roomPwKey(c.pk)); } catch (e) { /* nedostupné */ }
        var html = '<p class="small text-body-secondary">Room server uchovává příspěvky. Po přihlášení ti pošle ty, které jsi ještě neviděl, ' +
            'a dokud jsi přihlášen, posílá i nové. Psát do něj můžeš běžně v tomto chatu.</p>' +
            '<div class="input-group mb-2"><span class="input-group-text"><i class="bi bi-key"></i></span>' +
            '<input type="text" class="form-control rc-secret" id="room-pw" placeholder="Heslo (prázdné = host, pokud to server povoluje)" autocomplete="off" data-lpignore="true" data-1p-ignore></div>' +
            (isSrv()
                ? '<div class="form-check small mb-3"><input class="form-check-input" type="checkbox" id="room-remember" checked>' +
                  '<label class="form-check-label" for="room-remember">Uložit heslo na meshcore-serveru – po výpadku nebo restartu se přihlásí sám</label></div>'
                : '<div class="form-check small mb-3"><input class="form-check-input" type="checkbox" id="room-remember"' + (saved !== null ? ' checked' : '') + '>' +
                  '<label class="form-check-label" for="room-remember">Pamatovat heslo do zavření karty a přihlašovat se automaticky při otevření chatu</label></div>') +
            '<button type="button" class="btn btn-primary w-100" id="room-go"><i class="bi bi-box-arrow-in-right me-1"></i>Přihlásit</button>' +
            '<div class="small mt-2" id="room-out"></div>';
        showModal('Room server ' + c.name, html);
        if (saved !== null) { $('#room-pw').val(saved); }
        $('#room-go').on('click', function () {
            var pw = $('#room-pw').val(), $b = $(this).prop('disabled', true);
            if (isSrv()) {
                roomLogin(c, pw, $('#room-out'), $('#room-remember').prop('checked')).always(function () { $b.prop('disabled', false); });
                return;
            }
            try {
                if ($('#room-remember').prop('checked')) { sessionStorage.setItem(roomPwKey(c.pk), pw); } else { sessionStorage.removeItem(roomPwKey(c.pk)); }
            } catch (e) { /* nedostupné */ }
            roomLogin(c, pw, $('#room-out')).always(function () { $b.prop('disabled', false); });
        });
    }

    // při otevření chatu s room serverem se zapamatovaným heslem se přihlásit automaticky (jednou za načtení stránky)
    function maybeRoomAutoLogin(conv) {
        var c = S.convs[conv];
        // s meshcore-serverem se k roomu přihlašuje server sám (uložené heslo)
        if (!c || c.kind !== 'contact' || c.type !== 3 || roomAuto[c.pk] || isSrv()) { return; }
        var saved = null;
        try { saved = sessionStorage.getItem(roomPwKey(c.pk)); } catch (e) { /* nedostupné */ }
        if (saved === null) { return; }
        roomAuto[c.pk] = true;
        roomLogin(c, saved, null);
    }

    // ---------- úklid kontaktů ----------
    function showCleanup() {
        var st = { days: 90, types: { 1: true, 2: true, 3: true, 4: true }, sel: {} };
        var html =
            '<p class="small text-body-secondary mb-2">Companion má omezenou kapacitu kontaktů. Tady odebereš ty, které dlouho neslyšel (podle posledního advertu). ' +
            'Historie zpráv zůstane zachovaná.</p>' +
            '<div class="btn-group btn-group-sm w-100 mb-2" id="cl-days">' + [[30, '30 dní'], [90, '90 dní'], [180, '180 dní'], [365, 'rok']].map(function (d) {
                return '<button type="button" class="btn btn-outline-secondary' + (d[0] === st.days ? ' active' : '') + '" data-days="' + d[0] + '">déle než ' + d[1] + '</button>';
            }).join('') + '</div>' +
            '<div class="d-flex flex-wrap gap-1 mb-2" id="cl-types">' + [[2, 'Repeatery'], [1, 'Klienti'], [3, 'Room servery'], [4, 'Senzory']].map(function (t) {
                return '<button type="button" class="btn btn-sm btn-primary" data-type="' + t[0] + '"><i class="bi ' + TYPE_ICONS[t[0]] + ' me-1"></i>' + t[1] + '</button>';
            }).join('') + '</div>' +
            '<div class="d-flex align-items-center gap-2 small mb-1"><div class="form-check m-0"><input class="form-check-input" type="checkbox" id="cl-all">' +
            '<label class="form-check-label" for="cl-all">Vybrat vše (kromě chráněných)</label></div><span class="ms-auto text-body-secondary" id="cl-count"></span></div>' +
            '<div class="list-group list-group-flush small cl-list" id="cl-list"></div>' +
            '<div class="small text-body-secondary mt-2"><i class="bi bi-shield-check me-1"></i>Chráněné (oblíbené a kontakty s historií zpráv) se samy nevyberou – můžeš je zaškrtnout ručně.</div>' +
            '<div class="d-grid mt-3"><button type="button" class="btn btn-danger" id="cl-go" disabled><i class="bi bi-trash3 me-1"></i>Odebrat vybrané</button></div>';
        showModal('Úklid kontaktů', html);

        function candidates() {
            var lim = Date.now() - st.days * 86400000;
            return $.map(S.convs, function (c) { return c; }).filter(function (c) {
                return c.kind === 'contact' && c.pk && c.type != null && st.types[c.type] &&
                    (!c.last_advert || Date.parse(c.last_advert) < lim);
            }).sort(function (a, b) { return (Date.parse(a.last_advert || 0) || 0) - (Date.parse(b.last_advert || 0) || 0); });
        }
        function isProtected(c) { return isFav(c.conv) || !!c.last; }
        function render() {
            var list = candidates(), $l = $('#cl-list').empty();
            if (!list.length) { $l.append('<div class="text-body-secondary p-2">Žádné kontakty neodpovídají.</div>'); }
            list.slice(0, 500).forEach(function (c) {
                var prot = isProtected(c);
                $('<label class="list-group-item d-flex align-items-center gap-2 px-1">')
                    .append($('<input type="checkbox" class="form-check-input m-0 cl-cb">').attr('data-pk', c.pk).prop('checked', !!st.sel[c.pk]))
                    .append($('<i class="bi text-body-secondary">').addClass(TYPE_ICONS[c.type] || 'bi-cpu'))
                    .append($('<span class="flex-grow-1 text-truncate">').text(c.name))
                    .append(prot ? $('<span class="badge text-bg-light border">').text(isFav(c.conv) ? 'oblíbený' : 'historie') : '')
                    .append($('<span class="text-body-secondary text-nowrap">').text(c.last_advert ? fmtAgo(c.last_advert) : 'nikdy'))
                    .appendTo($l);
            });
            if (list.length > 500) { $l.append($('<div class="text-body-secondary p-2">').text('… zobrazeno 500 nejstarších z ' + list.length)); }
            count();
        }
        function count() {
            var n = Object.keys(st.sel).filter(function (k) { return st.sel[k]; }).length;
            $('#cl-count').text(candidates().length + ' kandidátů · vybráno ' + n);
            $('#cl-go').prop('disabled', !n).html('<i class="bi bi-trash3 me-1"></i>Odebrat vybrané' + (n ? ' (' + n + ')' : ''));
        }
        $('#cl-days').on('click', 'button', function () {
            st.days = +$(this).attr('data-days'); st.sel = {};
            $('#cl-days button').removeClass('active'); $(this).addClass('active'); $('#cl-all').prop('checked', false); render();
        });
        $('#cl-types').on('click', 'button', function () {
            var t = $(this).attr('data-type'); st.types[t] = !st.types[t]; st.sel = {};
            $(this).toggleClass('btn-primary', st.types[t]).toggleClass('btn-outline-secondary', !st.types[t]); $('#cl-all').prop('checked', false); render();
        });
        $('#cl-all').on('change', function () {
            var on = this.checked;
            st.sel = {};
            if (on) { candidates().forEach(function (c) { if (!isProtected(c)) { st.sel[c.pk] = true; } }); }
            render();
        });
        $('#cl-list').on('change', '.cl-cb', function () { st.sel[$(this).attr('data-pk')] = this.checked; count(); });
        $('#cl-go').on('click', function () {
            var pks = Object.keys(st.sel).filter(function (k) { return st.sel[k]; });
            if (!pks.length || !confirm('Odebrat ' + pks.length + ' kontaktů z companionu? Historie zpráv zůstane.')) { return; }
            var $b = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Odebírám…');
            api('contact_remove', { pks: pks }, 'POST').done(function (r) {
                setConversations(r.conversations);
                st.sel = {};
                toast('Odebráno ' + r.removed + ' kontaktů' + (r.failed ? ', ' + r.failed + ' se nepodařilo' : ''), r.failed ? 'warning' : 'success');
                render();
            }).fail(function (xhr) { toast(errText(xhr), 'danger'); count(); });
        });
        render();
    }

    // ---------- přidání kanálu ----------
    var PUBLIC_SECRET = '8b3387e9c5cdea6ac9e5edbaa115cd72';

    function randomSecret() {
        var b = new Uint8Array(16);
        crypto.getRandomValues(b);
        return Array.from(b).map(function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    }

    function showAddChannel() {
        var html =
            '<div class="list-group mb-3" id="ch-type">' +
            chType('hashtag', 'bi-hash', 'Veřejný hashtagový kanál', 'Např. #praha – klíč se odvodí z názvu, připojí se každý, kdo zná název.', true) +
            chType('public', 'bi-globe2', 'Public', 'Výchozí veřejný kanál MeshCore se standardním klíčem.') +
            chType('private', 'bi-key', 'Soukromý kanál (mám klíč)', 'Připojení k existujícímu soukromému kanálu – potřebuješ název a 32znakový hex klíč.') +
            chType('new', 'bi-shield-lock', 'Nový soukromý kanál', 'Vygeneruje náhodný klíč, který pak pošleš ostatním.') +
            '</div>' +
            '<div class="mb-3" id="ch-name-wrap"><label class="form-label" for="ch-name">Název</label>' +
            '<input type="text" class="form-control" id="ch-name" maxlength="31" autocomplete="off"></div>' +
            '<div class="mb-3 d-none" id="ch-secret-wrap"><label class="form-label" for="ch-secret">Klíč (32 hex znaků)</label>' +
            '<div class="input-group"><input type="text" class="form-control font-monospace" id="ch-secret" maxlength="32" autocomplete="off" spellcheck="false">' +
            '<button class="btn btn-outline-secondary d-none" type="button" id="ch-regen" title="Vygenerovat nový"><i class="bi bi-arrow-repeat"></i></button></div>' +
            '<div class="form-text d-none" id="ch-secret-hint">Klíč si ulož a pošli ostatním bezpečnou cestou – bez něj se ke kanálu nikdo nepřipojí.</div></div>' +
            '<div class="text-danger small mb-2" id="ch-error"></div>' +
            '<button type="button" class="btn btn-primary w-100" id="ch-save"><i class="bi bi-plus-lg me-1"></i>Přidat kanál</button>';
        showModal('Přidat kanál', html);

        function chType(v, icon, title, desc, checked) {
            return '<label class="list-group-item d-flex gap-2 align-items-start">' +
                '<input class="form-check-input flex-shrink-0 mt-1" type="radio" name="ch-type" value="' + v + '"' + (checked ? ' checked' : '') + '>' +
                '<span><i class="bi ' + icon + ' me-1"></i><strong>' + title + '</strong><br><small class="text-body-secondary">' + desc + '</small></span></label>';
        }
        function type() { return $('input[name="ch-type"]:checked').val(); }
        function update() {
            var t = type();
            $('#ch-name-wrap').toggleClass('d-none', t === 'public');
            $('#ch-secret-wrap').toggleClass('d-none', t !== 'private' && t !== 'new');
            $('#ch-regen, #ch-secret-hint').toggleClass('d-none', t !== 'new');
            $('#ch-secret').prop('readonly', t === 'new');
            $('#ch-name').attr('placeholder', t === 'hashtag' ? '#praha' : 'Název kanálu');
            if (t === 'new' && !/^[0-9a-f]{32}$/.test($('#ch-secret').val())) { $('#ch-secret').val(randomSecret()); }
            if (t === 'private' && $('#ch-secret').prop('readonly') === false && $('#ch-secret').data('gen')) { $('#ch-secret').val(''); }
            $('#ch-secret').data('gen', t === 'new');
            $('#ch-error').text('');
        }
        $('#ch-type input').on('change', update);
        $('#ch-regen').on('click', function () { $('#ch-secret').val(randomSecret()); });
        update();

        $('#ch-save').on('click', function () {
            var t = type(), name = $.trim($('#ch-name').val()), secret = $.trim($('#ch-secret').val()).toLowerCase().replace(/\s+/g, '');
            if (t === 'public') { name = 'Public'; secret = PUBLIC_SECRET; }
            if (t === 'hashtag') { name = '#' + name.replace(/^#+/, ''); secret = ''; }
            if (!name || name === '#') { $('#ch-error').text('Zadej název kanálu.'); return; }
            if (new TextEncoder().encode(name).length > 31) { $('#ch-error').text('Název je moc dlouhý (max. 31 bajtů).'); return; }
            if ((t === 'private' || t === 'new') && !/^[0-9a-f]{32}$/.test(secret)) { $('#ch-error').text('Klíč musí mít přesně 32 hex znaků (0–9, a–f).'); return; }
            var exists = $.map(S.convs, function (c) { return c; }).some(function (c) { return c.kind === 'channel' && c.name.toLowerCase() === name.toLowerCase(); });
            if (exists && !confirm('Kanál „' + name + '“ už máš. Přidat ho přesto znovu?')) { return; }
            var $b = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Přidávám…');
            api('channel_add', { name: name, secret: secret }, 'POST').done(function (r) {
                setConversations(r.conversations);
                toast('Kanál „' + name + '“ přidán do slotu ' + r.idx, 'success');
                if (t === 'new') {
                    $('#info-body').html('<div class="alert alert-success small">Kanál byl vytvořen. Pošli ostatním název a klíč:</div>' +
                        '<div class="font-monospace small p-2 rounded bg-body-tertiary user-select-all text-break mb-3">' + esc(name) + '<br>' + esc(secret) + '</div>' +
                        '<button type="button" class="btn btn-primary w-100" id="ch-open">Otevřít kanál</button>');
                    $('#ch-open').on('click', function () { bootstrap.Modal.getOrCreateInstance('#info-modal').hide(); openConv('c:' + r.idx); });
                } else {
                    bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
                    openConv('c:' + r.idx);
                }
            }).fail(function (xhr) {
                $('#ch-error').text(errText(xhr));
                $b.prop('disabled', false).html('<i class="bi bi-plus-lg me-1"></i>Přidat kanál');
            });
        });
    }

    // ---------- záložka Zařízení ----------
    var SENSOR_LABELS = {
        battery_percentage: 'Baterie', bat: 'Napětí baterie', battery_voltage: 'Napětí baterie', uptime: 'Uptime',
        noise_floor: 'Šumové pozadí', last_rssi: 'Poslední RSSI', last_snr: 'Poslední SNR',
        airtime_utilization: 'Vytížení vysílání', rx_airtime_utilization: 'Vytížení příjmu',
        out_path_len: 'Délka cesty', out_path: 'Cesta', request_successes: 'Úspěšné dotazy',
        request_failures: 'Neúspěšné dotazy', tx_queue_len: 'Fronta TX', nb_sent: 'Odesláno paketů',
        nb_recv: 'Přijato paketů', node_count: 'Počet uzlů', tx_power: 'Vysílací výkon', frequency: 'Frekvence',
        neighbor_count: 'Sousedé', recv_errors: 'Chyby příjmu', full_evts: 'Přetečení fronty'
    };

    function sval(s) {
        if (!s || s.state == null || s.state === 'unknown' || s.state === 'unavailable' || s.state === '') { return null; }
        return s.state;
    }

    function fmtSensor(key, s) {
        var v = sval(s);
        if (v === null) { return '–'; }
        if (key === 'uptime') { return fmtDuration(v, s.unit); }
        var n = parseFloat(v);
        if (!isNaN(n) && String(n) !== String(v).trim()) { v = String(v); }
        else if (!isNaN(n)) { v = Math.round(n * 100) / 100; }
        return v + (s.unit ? ' ' + s.unit : '');
    }

    function fmtDuration(v, unit) {
        var sec = parseFloat(v);
        if (isNaN(sec)) { return v; }
        sec *= { min: 60, h: 3600, d: 86400 }[unit] || 1;
        var d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60);
        return d ? d + ' d ' + h + ' h' : h ? h + ' h ' + m + ' min' : m + ' min';
    }

    function loadDevices() {
        return api('devices').done(function (r) {
            S.devices = r.devices;
            if (S.tab === 'devices') { renderDevices(); }
        });
    }

    function devStats(d) {
        var s = d.sensors || {}, out = [];
        var bat = sval(s.battery_percentage);
        if (bat !== null) { out.push('<span title="Baterie"><i class="bi bi-battery-half"></i> ' + esc(Math.round(bat)) + ' %</span>'); }
        else if (sval(s.bat) !== null) { out.push('<span title="Napětí baterie"><i class="bi bi-battery-half"></i> ' + esc(fmtSensor('bat', s.bat)) + '</span>'); }
        if (sval(s.uptime) !== null) { out.push('<span title="Uptime"><i class="bi bi-clock-history"></i> ' + esc(fmtSensor('uptime', s.uptime)) + '</span>'); }
        if (sval(s.noise_floor) !== null) { out.push('<span title="Šumové pozadí"><i class="bi bi-soundwave"></i> ' + esc(fmtSensor('noise_floor', s.noise_floor)) + '</span>'); }
        if (sval(s.last_snr) !== null) { out.push('<span title="Poslední SNR"><i class="bi bi-reception-3"></i> ' + esc(fmtSensor('last_snr', s.last_snr)) + '</span>'); }
        if (sval(s.airtime_utilization) !== null) { out.push('<span title="Vytížení vysílání"><i class="bi bi-broadcast"></i> ' + esc(fmtSensor('airtime_utilization', s.airtime_utilization)) + '</span>'); }
        if (sval(s.out_path_len) !== null) { out.push('<span title="Délka cesty"><i class="bi bi-diagram-3"></i> ' + esc(s.out_path_len.state) + ' hop</span>'); }
        return out.join('');
    }

    function renderDevices() {
        var $l = $('#conv-list').empty();
        var D = S.devices;
        if (!D) {
            $l.append('<div class="text-center p-4"><span class="spinner-border spinner-border-sm"></span></div>');
            loadDevices();
            return;
        }
        if (!D.available) {
            $l.append('<div class="text-center text-body-secondary small p-4">Stav zařízení zatím není k dispozici.<br>Worker ho načítá z ' + backendName() + ' – zkontroluj, že běží aktuální verze.</div>');
            return;
        }
        var q = norm($('#search').val().trim());

        if (D.mqtt.length) {
            $l.append('<div class="dev-section px-3 pt-2 pb-1">MQTT brokery</div>');
            D.mqtt.forEach(function (b) {
                $('<div class="list-group-item d-flex align-items-center gap-2 py-2">')
                    .append($('<span class="dev-dot">').addClass(b.connected ? 'on' : 'off'))
                    .append($('<div class="flex-grow-1 min-w-0">')
                        .append($('<div class="fw-semibold text-truncate">').text('Broker ' + (b.n || '') + (b.server ? ' – ' + b.server : '')))
                        .append($('<div class="small text-body-secondary">').text((b.connected ? 'Připojeno' : (b.state === 'unavailable' ? 'Nedostupný' : 'Odpojeno')) +
                            (b.changed ? ' · ' + fmtAgo(b.changed) : ''))))
                    .appendTo($l);
            });
        }

        if (D.companions.length) {
            $l.append('<div class="dev-section px-3 pt-3 pb-1">Companion</div>');
            D.companions.forEach(function (c) {
                $('<a class="list-group-item list-group-item-action dev-card py-2">')
                    .attr('data-dev', 'c:' + c.pk)
                    .append($('<div class="d-flex align-items-center gap-2">')
                        .append($('<span class="dev-dot">').addClass(/online|connected|ok/i.test(c.status) ? 'on' : ''))
                        .append($('<span class="fw-semibold text-truncate flex-grow-1">').text(c.name))
                        .append($('<span class="small text-body-secondary">').text(c.status || '')))
                    .append($('<div class="dev-stats text-body-secondary mt-1">').html(devStats(c)))
                    .appendTo($l);
            });
        }

        if (S.ble) {
            if (!D.companions.length) { $l.append('<div class="text-body-secondary small p-4 text-center">Companion zatím nebyl připojen.</div>'); }
            return;
        }
        var devs = D.devices.filter(function (d) { return !q || norm(d.name).indexOf(q) >= 0 || d.pk.indexOf(q) === 0; });
        $l.append('<div class="dev-section px-3 pt-3 pb-1">Sledovaná zařízení' +
            (D.devices.length ? ' (' + D.devices.filter(function (d) { return d.online; }).length + '/' + D.devices.length + ' online)' : '') + '</div>');
        if (!D.devices.length) {
            $l.append('<div class="text-body-secondary small px-3 pb-3">' + (isSrv() ? 'Sledování uzlů meshcore-server zatím neumí.'
                : 'Žádná. Sledování se zapíná v HA: MeshCore → Konfigurovat → Add Repeater Station / Add Tracked Client.') + '</div>');
        }
        devs.forEach(function (d) {
            var state = d.online === true ? 'online' : d.online === false ? 'offline' : 'neznámý stav';
            $('<a class="list-group-item list-group-item-action dev-card py-2">')
                .attr('data-dev', 'd:' + d.pk)
                .append($('<div class="d-flex align-items-center gap-2">')
                    .append($('<span class="dev-dot">').addClass(d.online === true ? 'on' : d.online === false ? 'off' : ''))
                    .append($('<i class="bi">').addClass(TYPE_ICONS[d.type] || 'bi-cpu').addClass('text-body-secondary'))
                    .append($('<span class="fw-semibold text-truncate flex-grow-1">').text(d.name))
                    .append($('<span class="small text-body-secondary text-nowrap">').text(state + (d.changed ? ' · ' + fmtAgo(d.changed) : ''))))
                .append($('<div class="dev-stats text-body-secondary mt-1">').html(devStats(d)))
                .appendTo($l);
        });
        $l.append($('<div class="small text-body-secondary text-center p-3">').text('Aktualizováno ' + fmtAgo(D.updated_at)));
    }

    // ---------- mapa sítě (záložka Mapa) ----------
    var NM = {
        map: null, layer: null, markers: {}, disc: null, fitted: false,
        f: $.extend({ types: { 1: true, 2: true, 3: true, 4: true }, disc: true, age: 30 }, loadPref('netmap', {}))
    };
    var NM_AGES = [[1, '24 h'], [7, '7 dní'], [30, '30 dní'], [0, 'vše']];

    function nmAgeClass(iso) {
        var h = iso ? (Date.now() - Date.parse(iso)) / 3600000 : Infinity;
        return h < 24 ? 'a0' : h < 24 * 7 ? 'a1' : h < 24 * 30 ? 'a2' : 'a3';
    }

    // všechny uzly s polohou: kontakty companionu + slyšené (nepřidané)
    function nmNodes() {
        var out = [], seen = {};
        $.each(S.convs, function (k, c) {
            if (c.kind !== 'contact' || !c.pk) { return; }
            seen[c.pk] = true;
            out.push({ pk: c.pk, name: c.name, type: c.type, lat: c.lat, lon: c.lon, last_advert: c.last_advert, path_len: c.path_len, conv: c.conv, disc: false });
        });
        (NM.disc || []).forEach(function (n) {
            if (seen[n.pk12]) { return; }
            out.push({ pk: n.pk12, name: n.name || n.pk12, type: n.type, lat: n.lat, lon: n.lon, last_advert: n.last_advert, path_len: n.path_len, disc: true, node: n });
        });
        return out;
    }

    function nmFiltered() {
        var q = norm($('#search').val().trim()), maxAge = NM.f.age ? NM.f.age * 86400000 : 0;
        return nmNodes().filter(function (n) {
            if (n.disc && !NM.f.disc) { return false; }
            if (!NM.f.types[n.type || 1]) { return false; }
            if (maxAge && (!n.last_advert || Date.now() - Date.parse(n.last_advert) > maxAge)) { return false; }
            return !q || norm(n.name).indexOf(q) >= 0 || n.pk.indexOf(q) === 0;
        });
    }

    function renderMapTab() {
        var $l = $('#conv-list').empty();
        var $f = $('<div class="px-3 pb-2 small nm-filters">');
        var types = [[2, 'Repeatery'], [1, 'Klienti'], [3, 'Room servery'], [4, 'Senzory']];
        var $t = $('<div class="d-flex flex-wrap gap-1 mb-2">');
        types.forEach(function (t) {
            $t.append($('<button type="button" class="btn btn-sm nm-type">').toggleClass('btn-primary', !!NM.f.types[t[0]])
                .toggleClass('btn-outline-secondary', !NM.f.types[t[0]]).attr('data-type', t[0])
                .html('<i class="bi ' + (TYPE_ICONS[t[0]] || 'bi-cpu') + ' me-1"></i>' + t[1]));
        });
        $t.append($('<button type="button" class="btn btn-sm nm-disc">').toggleClass('btn-primary', NM.f.disc).toggleClass('btn-outline-secondary', !NM.f.disc)
            .html('<i class="bi bi-ear me-1"></i>Slyšené'));
        $f.append($t);
        var $a = $('<div class="btn-group btn-group-sm w-100 mb-2">');
        NM_AGES.forEach(function (a) {
            $a.append($('<button type="button" class="btn nm-age">').toggleClass('btn-secondary', NM.f.age === a[0])
                .toggleClass('btn-outline-secondary', NM.f.age !== a[0]).attr('data-age', a[0]).text('advert ' + a[1]));
        });
        $f.append($a);
        $f.append('<div class="nm-legend text-body-secondary"><span><i class="nm-dot a0"></i>&lt; 24 h</span><span><i class="nm-dot a1"></i>&lt; 7 dní</span>' +
            '<span><i class="nm-dot a2"></i>&lt; 30 dní</span><span><i class="nm-dot a3"></i>starší</span><span><i class="nm-dot nm-dashed"></i>slyšený</span></div>');
        $l.append($f);

        var all = nmFiltered(), withPos = all.filter(hasPos);
        $('#map-subtitle').text(withPos.length + ' uzlů na mapě' + (all.length > withPos.length ? ', ' + (all.length - withPos.length) + ' bez polohy' : ''));
        $l.append($('<div class="d-grid px-3 pb-2 d-md-none">').append(
            $('<button type="button" class="btn btn-primary btn-sm" id="nm-show"><i class="bi bi-map me-1"></i>Zobrazit mapu</button>')));
        $l.append($('<div class="dev-section px-3 pt-2 pb-1">').text('Uzly (' + all.length + ')'));
        all.sort(function (a, b) { return (Date.parse(b.last_advert || 0) || 0) - (Date.parse(a.last_advert || 0) || 0); });
        all.slice(0, 300).forEach(function (n) {
            $('<a class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2 nm-item">').attr('data-pk', n.pk)
                .append($('<span class="nm-pin nm-pin-sm">').addClass(nmAgeClass(n.last_advert)).toggleClass('nm-dashed', n.disc)
                    .html('<i class="bi ' + (TYPE_ICONS[n.type] || 'bi-cpu') + '"></i>'))
                .append($('<div class="flex-grow-1 min-w-0">')
                    .append($('<div class="fw-semibold text-truncate">').text(n.name))
                    .append($('<div class="small text-body-secondary text-truncate">').text(
                        (TYPE_NAMES[n.type] || 'Uzel') + (n.disc ? ' · slyšený' : '') + ' · advert ' + fmtAgo(n.last_advert) + (hasPos(n) ? '' : ' · bez polohy'))))
                .appendTo($l);
        });
        if (all.length > 300) { $l.append($('<div class="small text-body-secondary p-3">').text('… a dalších ' + (all.length - 300) + ' – upřesni hledání.')); }
        if (S.current) { return; } // otevřený chat (z mapy) nepřepínat zpět na mapu

        if (NM.disc === null && !S.current) {
            NM.disc = [];
            api('discovered').done(function (r) {
                NM.disc = (r.discovered && r.discovered.nodes) || [];
                if (S.tab === 'map') { renderMapTab(); }
            });
        }
        showNetMap();
    }

    function showNetMap() {
        S.current = null;
        $('#chat-view').addClass('d-none');
        $('#chat-empty').addClass('d-none');
        $('#map-view').removeClass('d-none');
        if (window.matchMedia('(max-width: 767.98px)').matches && !NM.listMode) { $('#app').addClass('show-chat'); }
        ensureLeaflet().done(drawNetMap);
    }

    function hideNetMap() {
        if ($('#map-view').hasClass('d-none')) { return; }
        $('#map-view').addClass('d-none');
        if (!S.current) { $('#chat-empty').removeClass('d-none'); $('#app').removeClass('show-chat'); }
    }

    function nmPopup(n) {
        var h = '<div class="nm-popup"><div class="fw-semibold">' + esc(n.name) + '</div>' +
            '<div class="small text-body-secondary">' + esc(TYPE_NAMES[n.type] || 'Uzel') + (n.disc ? ' · slyšený (není v kontaktech)' : '') +
            ' · <span class="font-monospace">' + esc(n.pk) + '</span></div>' +
            '<div class="small">Advert ' + esc(fmtAgo(n.last_advert)) +
            (n.path_len != null ? ' · ' + (n.path_len < 0 ? 'flood' : n.path_len === 0 ? 'přímo' : n.path_len + ' hop') : '') + '</div>' +
            '<div class="d-flex flex-wrap gap-1 mt-2">';
        if (n.conv) {
            h += '<button type="button" class="btn btn-sm btn-primary nm-act" data-act="chat" data-pk="' + esc(n.pk) + '"><i class="bi bi-chat-dots me-1"></i>Chat</button>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary nm-act" data-act="info" data-pk="' + esc(n.pk) + '"><i class="bi bi-info-circle me-1"></i>Detail</button>';
        } else {
            h += '<button type="button" class="btn btn-sm btn-outline-primary nm-act" data-act="add" data-pk="' + esc(n.pk) + '"><i class="bi bi-person-plus me-1"></i>Přidat</button>';
            if (n.type === 2 || n.type === 3) {
                h += '<button type="button" class="btn btn-sm btn-outline-secondary nm-act" data-act="remote" data-pk="' + esc(n.pk) + '"><i class="bi bi-activity me-1"></i>Stav</button>';
            }
        }
        return h + '</div></div>';
    }

    function drawNetMap() {
        var el = document.getElementById('net-map');
        if (!el || $('#map-view').hasClass('d-none')) { return; }
        if (!NM.map) {
            NM.map = newMap(el, true);
            NM.layer = L.layerGroup().addTo(NM.map);
            NM.map.setView(S.home ? [S.home.lat, S.home.lon] : [49.8, 15.5], S.home ? 9 : 7);
        }
        setTimeout(function () { NM.map.invalidateSize(); }, 50);
        NM.layer.clearLayers();
        NM.markers = {};
        var pts = [];
        nmFiltered().filter(hasPos).forEach(function (n) {
            var mk = L.marker([n.lat, n.lon], {
                title: n.name, riseOnHover: true,
                icon: L.divIcon({ className: 'nm-icon', iconSize: [26, 26], iconAnchor: [13, 13],
                    html: '<span class="nm-pin ' + nmAgeClass(n.last_advert) + (n.disc ? ' nm-dashed' : '') + '"><i class="bi ' + (TYPE_ICONS[n.type] || 'bi-cpu') + '"></i></span>' })
            }).bindPopup(nmPopup(n), { maxWidth: 280 }).bindTooltip(esc(n.name), { direction: 'top', offset: [0, -12] });
            mk.addTo(NM.layer);
            NM.markers[n.pk] = mk;
            pts.push([n.lat, n.lon]);
        });
        if (S.home) {
            L.marker([S.home.lat, S.home.lon], { icon: L.divIcon({ className: 'nm-icon', iconSize: [26, 26], iconAnchor: [13, 13],
                html: '<span class="nm-pin nm-home"><i class="bi bi-house-fill"></i></span>' }) }).bindTooltip('Ty').addTo(NM.layer);
            pts.push([S.home.lat, S.home.lon]);
        }
        if (!NM.fitted && pts.length) { NM.fitted = true; NM.map.fitBounds(pts, { padding: [40, 40], maxZoom: 12 }); }
        NM.pts = pts;
    }

    function nmFocus(pk) {
        var mk = NM.markers[pk];
        if (window.matchMedia('(max-width: 767.98px)').matches) { NM.listMode = false; $('#app').addClass('show-chat'); }
        if (!mk) { toast('Uzel nemá známou polohu nebo je skrytý filtrem.', 'secondary'); return; }
        setTimeout(function () {
            NM.map.invalidateSize();
            NM.map.setView(mk.getLatLng(), Math.max(NM.map.getZoom(), 12));
            mk.openPopup();
        }, 80);
    }

    $('#conv-list').on('click', '.nm-type', function () {
        var t = $(this).attr('data-type'); NM.f.types[t] = !NM.f.types[t]; savePref('netmap', NM.f); renderMapTab();
    });
    $('#conv-list').on('click', '.nm-disc', function () { NM.f.disc = !NM.f.disc; savePref('netmap', NM.f); renderMapTab(); });
    $('#conv-list').on('click', '.nm-age', function () { NM.f.age = +$(this).attr('data-age'); savePref('netmap', NM.f); renderMapTab(); });
    $('#conv-list').on('click', '.nm-item', function (e) { e.preventDefault(); nmFocus($(this).attr('data-pk')); });
    $('#conv-list').on('click', '#nm-show', function () { NM.listMode = false; $('#app').addClass('show-chat'); setTimeout(function () { if (NM.map) { NM.map.invalidateSize(); } }, 50); });
    $('#btn-map-back').on('click', function () { NM.listMode = true; $('#app').removeClass('show-chat'); });
    $('#btn-map-fit').on('click', function () { if (NM.map && NM.pts && NM.pts.length) { NM.map.fitBounds(NM.pts, { padding: [40, 40], maxZoom: 12 }); } });
    $('#net-map').on('click', '.nm-act', function () {
        var pk = $(this).attr('data-pk'), act = $(this).attr('data-act'), n = null;
        nmNodes().forEach(function (x) { if (x.pk === pk) { n = x; } });
        if (!n) { return; }
        if (act === 'chat') { openConv(n.conv); }
        else if (act === 'info') { showContactInfo(S.convs[n.conv]); }
        else if (act === 'remote') { showRemoteNode(n.node); }
        else if (act === 'add') {
            var $b = $(this).prop('disabled', true);
            api('contact_add', { pk: pk }, 'POST').done(function (r) {
                setConversations(r.conversations);
                NM.disc = (NM.disc || []).filter(function (x) { return x.pk12 !== pk; });
                toast('Kontakt přidán', 'success');
                if (S.tab === 'map') { renderMapTab(); }
            }).fail(function (xhr) { toast(errText(xhr), 'danger'); $b.prop('disabled', false); });
        }
    });

    // ---------- mapa cesty zprávy ----------
    var ROUTE_COLORS = ['#0d6efd', '#d63384', '#fd7e14', '#198754', '#6f42c1', '#0dcaf0', '#dc3545', '#6c757d'];
    var leafletLoad = null;

    function ensureLeaflet() {
        if (window.L) { return $.Deferred().resolve().promise(); }
        if (!leafletLoad) {
            $('head').append('<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">');
            leafletLoad = $.ajax({ url: 'assets/vendor/leaflet/leaflet.js', dataType: 'script', cache: true })
                .fail(function () { leafletLoad = null; });
        }
        return leafletLoad;
    }

    function hasPos(o) { return o && o.lat != null && o.lon != null && !(o.lat === 0 && o.lon === 0); }

    function distKm(a, b) {
        var R = 6371, rad = Math.PI / 180, dLat = (b.lat - a.lat) * rad, dLon = (b.lon - a.lon) * rad;
        var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(x)));
    }

    // Poloha odesílatele: u DM kontakt konverzace, u kanálu kontakt se stejným jménem
    function senderPos(m) {
        if (m.dir === 'out') { return S.home ? { lat: S.home.lat, lon: S.home.lon, name: 'Ty', kind: 'home' } : null; }
        var c = S.convs[m.conv];
        if (c && c.kind === 'contact') { return hasPos(c) ? { lat: c.lat, lon: c.lon, name: c.name, kind: 'start' } : null; }
        if (!m.sender) { return null; }
        var want = norm(m.sender), found = null;
        $.each(S.convs, function (k, x) {
            if (x.kind === 'contact' && hasPos(x) && norm(x.name) === want && (!found || x.type === 1)) { found = x; }
        });
        return found ? { lat: found.lat, lon: found.lon, name: found.name, kind: 'start' } : null;
    }

    // Pro každý skok vybere kandidáta s polohou tak, aby celková délka cesty byla co nejkratší
    // (Viterbi přes kandidáty; konce cesty jsou odesílatel a companion, pokud mají polohu).
    function resolveHops(hops, start, end) {
        var layers = hops.map(function (h) {
            return hopCandidates(h).filter(hasPos).slice(0, 40);
        });
        var picks = hops.map(function () { return null; });
        var idx = [];
        layers.forEach(function (l, i) { if (l.length) { idx.push(i); } });
        if (!idx.length) { return picks; }
        // cost[k][j] = nejlepší délka do kandidáta j vrstvy idx[k]
        var cost = [], back = [];
        idx.forEach(function (li, k) {
            cost[k] = []; back[k] = [];
            layers[li].forEach(function (c, j) {
                var pen = j * 0.01; // při shodě vyhrává čerstvější advert (pořadí kandidátů)
                if (k === 0) {
                    cost[k][j] = (start ? distKm(start, c) : 0) + pen; back[k][j] = -1;
                } else {
                    var best = Infinity, bi = 0;
                    layers[idx[k - 1]].forEach(function (p, pi) {
                        var v = cost[k - 1][pi] + distKm(p, c);
                        if (v < best) { best = v; bi = pi; }
                    });
                    cost[k][j] = best + pen; back[k][j] = bi;
                }
            });
        });
        var K = idx.length - 1, last = cost[K], bj = 0, bv = Infinity;
        last.forEach(function (v, j) { var t = v + (end ? distKm(layers[idx[K]][j], end) : 0); if (t < bv) { bv = t; bj = j; } });
        for (var k = K; k >= 0; k--) { picks[idx[k]] = layers[idx[k]][bj]; bj = back[k][bj]; }
        return picks;
    }

    // Trasy pro mapu i textový výpis: body (odesílatel → repeatery → ty) a vybraní kandidáti
    function buildRoutes(m) {
        var start = senderPos(m), end = S.home ? { lat: S.home.lat, lon: S.home.lon, name: 'Ty', kind: 'home' } : null;
        return (m.rx || []).map(function (r) {
            var hops = splitPath(r.path, r.path_len, r.path_hash_size);
            var picks = resolveHops(hops, start, end);
            var pts = [];
            if (start) { pts.push({ key: start.kind === 'home' ? 'home' : 'start', lat: start.lat, lon: start.lon, name: m.dir === 'in' ? start.name : 'Ty (odesláno)', kind: start.kind, gap: false }); }
            var gap = !start && hops.length > 0;
            hops.forEach(function (h, i) {
                var c = picks[i];
                if (!c) { gap = true; return; }
                var all = hopCandidates(h).filter(hasPos);
                pts.push({ key: 'r:' + c.pk, lat: c.lat, lon: c.lon, name: c.name, hash: h, kind: 'hop', gap: gap,
                    alt: all.filter(function (x) { return x !== c; }).map(function (x) { return x.name; }) });
                gap = false;
            });
            if (end) { pts.push({ key: 'home', lat: end.lat, lon: end.lon, name: 'Ty', kind: 'home', gap: gap }); }
            return { picks: picks, points: pts, snr: r.snr, missing: hops.length - picks.filter(Boolean).length, startKnown: !!start };
        });
    }

    function drawRoutes(map, routes, big) {
        var layers = [], markers = {}, bounds = [];
        var origin = map.options.crs;
        routes.forEach(function (rt) { rt.points.forEach(function (p) { bounds.push([p.lat, p.lon]); }); });
        // pohled musí existovat dřív, než se přidají vektorové vrstvy
        if (bounds.length) { map.fitBounds(bounds, { padding: big ? [60, 60] : [24, 24], maxZoom: 13 }); }
        routes.forEach(function (rt, ri) {
            var color = ROUTE_COLORS[ri % ROUTE_COLORS.length], group = L.layerGroup().addTo(map);
            for (var i = 1; i < rt.points.length; i++) {
                var a = rt.points[i - 1], b = rt.points[i];
                if (a.lat === b.lat && a.lon === b.lon) { continue; }
                L.polyline([[a.lat, a.lon], [b.lat, b.lon]], {
                    color: color, weight: big ? 4 : 3, opacity: b.gap ? 0.55 : 0.9,
                    className: 'route-line' + (b.gap ? ' route-gap' : ''), interactive: false
                }).addTo(group);
                // šipka směru uprostřed úseku (úhel v Mercatoru nezávisí na zoomu)
                var pa = origin.latLngToPoint(L.latLng(a.lat, a.lon), 0), pb = origin.latLngToPoint(L.latLng(b.lat, b.lon), 0);
                var ang = Math.atan2(pb.y - pa.y, pb.x - pa.x) * 180 / Math.PI;
                L.marker([(a.lat + b.lat) / 2, (a.lon + b.lon) / 2], {
                    interactive: false, keyboard: false,
                    icon: L.divIcon({ className: 'route-arrow', iconSize: [22, 22], iconAnchor: [11, 11],
                        html: '<svg width="22" height="22" viewBox="0 0 18 18" style="transform:rotate(' + ang.toFixed(1) + 'deg)">' +
                            '<path d="M4 3 L15 9 L4 15 L7 9 Z" fill="' + color + '" stroke="#fff" stroke-width="1.2" stroke-linejoin="round"/></svg>' })
                }).addTo(group);
            }
            rt.points.forEach(function (p) {
                if (markers[p.key]) { return; }
                var fill = p.kind === 'home' ? '#dc3545' : p.kind === 'start' ? '#198754' : (routes.length > 1 ? '#343a40' : color);
                var mk = L.circleMarker([p.lat, p.lon], {
                    radius: p.kind === 'hop' ? (big ? 7 : 5) : (big ? 9 : 7), color: '#fff', weight: 2, fillColor: fill, fillOpacity: 1,
                    dashArray: p.alt && p.alt.length ? '3 3' : null
                }).addTo(map);
                var tip = '<strong>' + esc(p.name) + '</strong>' + (p.hash ? ' <span class="font-monospace">' + esc(p.hash) + '</span>' : '') +
                    (p.alt && p.alt.length ? '<br><span class="small">nejisté – hash sedí i na: ' + esc(p.alt.slice(0, 4).join(', ')) +
                        (p.alt.length > 4 ? ' a další' : '') + '</span>' : '');
                mk.bindTooltip(tip, big ? { permanent: true, direction: 'right', offset: [8, 0], className: 'route-label' } : { direction: 'top' });
                markers[p.key] = mk;
            });
            layers.push(group);
        });
        return layers;
    }

    function highlightRoute(layers, i) {
        layers.forEach(function (g, gi) {
            g.eachLayer(function (l) {
                var on = i == null || gi === i;
                if (l.setStyle) { l.setStyle({ opacity: on ? 0.9 : 0.12 }); if (on) { l.bringToFront(); } }
                else if (l.setOpacity) { l.setOpacity(on ? 1 : 0.15); }
            });
        });
    }

    function newMap(el, interactive) {
        var map = L.map(el, {
            zoomControl: interactive, attributionControl: true, dragging: interactive, scrollWheelZoom: interactive,
            doubleClickZoom: interactive, touchZoom: interactive, boxZoom: interactive, keyboard: interactive
        });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            // OSM dlaždice vyžadují Referer – stránka jinak posílá Referrer-Policy same-origin (viz .htaccess)
            referrerPolicy: 'strict-origin-when-cross-origin',
            maxZoom: 18, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
        }).addTo(map);
        map.attributionControl.setPrefix(false);
        return map;
    }

    function initPathMap(m, routes) {
        var usable = routes.filter(function (r) { return r.points.length >= 2; });
        var missing = routes.reduce(function (n, r) { return n + r.missing; }, 0);
        var notes = [];
        if (!S.home) { notes.push('tvoje poloha není známá (nastav app.home v konfiguraci)'); }
        if (m.dir === 'in' && routes.length && !routes[0].startKnown) { notes.push('odesílatel nemá známou polohu'); }
        if (missing) { notes.push(missing + '× repeater bez známé polohy – úsek je tečkovaný'); }
        if (!usable.length) {
            $('#path-map-wrap').remove();
            $('#path-map-note').text('Mapu nelze nakreslit – na cestě chybí body se známou polohou' + (notes.length ? ' (' + notes.join(', ') + ').' : '.'));
            return;
        }
        $('#path-map-note').text(notes.length ? 'Pozn.: ' + notes.join('; ') + '.' : '');
        ensureLeaflet().done(function () {
            var el = document.getElementById('path-map');
            if (!el) { return; }
            var map = newMap(el, false), layers = drawRoutes(map, routes, false);
            var fit = function () { if (document.getElementById('path-map')) { map.invalidateSize(); drawFit(); } };
            function drawFit() {
                var b = []; routes.forEach(function (r) { r.points.forEach(function (p) { b.push([p.lat, p.lon]); }); });
                map.fitBounds(b, { padding: [24, 24], maxZoom: 13 });
            }
            setTimeout(fit, 350);
            $('#info-modal').one('shown.bs.modal', fit);
            $('#path-map, #path-map-expand').on('click', function () {
                var act = $('.rx-route.route-active').attr('data-route');
                showBigMap(m, routes, act != null ? +act : null);
            });
            $('#info-body').off('click.route').on('click.route', '.rx-route', function (e) {
                if ($(e.target).closest('.hop').length) { return; }
                var i = +$(this).attr('data-route'), was = $(this).hasClass('route-active');
                $('.rx-route').removeClass('route-active');
                if (!was) { $(this).addClass('route-active'); }
                highlightRoute(layers, was ? null : i);
            });
            $('#info-modal').one('hidden.bs.modal', function () { map.remove(); $('#map-overlay').remove(); });
        }).fail(function () {
            $('#path-map-wrap').remove();
            $('#path-map-note').text('Mapovou knihovnu se nepodařilo načíst.');
        });
    }

    // Velká mapa přes celou obrazovku (uvnitř modalu, aby fungoval focus trap)
    function showBigMap(m, routes, active) {
        $('#map-overlay').remove();
        var legend = routes.length > 1 ? routes.map(function (r, i) {
            return '<button type="button" class="btn btn-sm btn-outline-secondary map-leg" data-route="' + i + '">' +
                '<span class="route-dot" style="background:' + ROUTE_COLORS[i % ROUTE_COLORS.length] + '"></span>Cesta ' + (i + 1) +
                (r.snr != null ? ' · SNR ' + esc(r.snr) + ' dB' : '') + '</button>';
        }).join('') : '';
        var $o = $('<div id="map-overlay" class="map-overlay">' +
            '<div class="map-overlay-bar"><strong class="me-auto text-truncate">Cesta zprávy' + (m.sender && m.dir === 'in' ? ' od ' + esc(m.sender) : '') + '</strong>' +
            '<div class="d-flex flex-wrap gap-1 map-legs">' + legend + '</div>' +
            '<button type="button" class="btn-close ms-2" id="map-overlay-close" aria-label="Zavřít"></button></div>' +
            '<div class="map-overlay-map" id="big-map"></div>' +
            '<div class="map-overlay-legend small"><span><i class="map-key" style="background:#198754"></i>odesílatel</span>' +
            '<span><i class="map-key" style="background:#343a40"></i>repeater</span><span><i class="map-key map-key-amb"></i>nejistý (víc kandidátů)</span>' +
            '<span><i class="map-key" style="background:#dc3545"></i>ty</span><span>tečkovaně = mezera (repeater bez polohy)</span></div></div>');
        $('#info-modal').append($o);
        var map = newMap(document.getElementById('big-map'), true), layers = drawRoutes(map, routes, true);
        if (active != null) { $o.find('.map-leg[data-route="' + active + '"]').addClass('active'); highlightRoute(layers, active); }
        setTimeout(function () { map.invalidateSize(); }, 50);
        function close() { map.remove(); $o.remove(); document.removeEventListener('keydown', onKey, true); }
        function onKey(e) {
            if (!document.getElementById('map-overlay')) { document.removeEventListener('keydown', onKey, true); return; }
            if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); close(); }
        }
        document.addEventListener('keydown', onKey, true);
        $('#map-overlay-close').on('click', close);
        $o.on('click', '.map-leg', function () {
            var i = +$(this).attr('data-route'), was = $(this).hasClass('active');
            $o.find('.map-leg').removeClass('active');
            if (!was) { $(this).addClass('active'); }
            highlightRoute(layers, was ? null : i);
        });
    }

    // ---------- překlad cesty na repeatery ----------
    var hopCache = {};

    // Kandidáti pro hash skoku: repeatery a room servery, jejichž klíč hashem začíná
    function hopCandidates(hash) {
        if (hopCache[hash]) { return hopCache[hash]; }
        var list = [];
        $.each(S.convs, function (k, c) {
            if (c.kind === 'contact' && (c.type === 2 || c.type === 3) && c.pk && c.pk.indexOf(hash) === 0) { list.push(c); }
        });
        list.sort(function (a, b) {
            return ((a.type === 2 ? 0 : 1) - (b.type === 2 ? 0 : 1)) ||
                (Date.parse(b.last_advert || 0) || 0) - (Date.parse(a.last_advert || 0) || 0);
        });
        hopCache[hash] = list;
        return list;
    }

    // Rozdělí hex cestu na skoky podle velikosti hashe (1–4 B)
    function splitPath(path, len, size) {
        path = String(path || '').toLowerCase().replace(/[^0-9a-f]/g, '');
        if (!path) { return []; }
        var sz = size > 0 ? size : (len > 0 ? path.length / 2 / len : 1);
        if (!(sz >= 1 && sz <= 4 && sz === Math.floor(sz))) { sz = 1; }
        var hops = [];
        for (var i = 0; i + sz * 2 <= path.length; i += sz * 2) { hops.push(path.substr(i, sz * 2)); }
        return hops;
    }

    // HTML cesty: [začátek →] repeater → repeater → … [→ ty]
    function pathHtml(path, len, size, from, compact, to, picks) {
        var hops = splitPath(path, len, size);
        var parts = [];
        if (from && !compact) { parts.push('<span class="hop hop-end">' + esc(from) + '</span>'); }
        if (!hops.length) {
            parts.push('<span class="text-body-secondary">' + (len === 0 ? 'přímo' : '–') + '</span>');
        }
        hops.forEach(function (h, i) {
            var cands = hopCandidates(h), pick = picks && picks[i];
            if (pick && cands.indexOf(pick) > 0) { cands = [pick].concat(cands.filter(function (c) { return c !== pick; })); }
            var title = cands.length
                ? cands.slice(0, 5).map(function (c) { return c.name + ' (' + (TYPE_NAMES[c.type] || '') + ', advert ' + fmtAgo(c.last_advert) + ')'; }).join('\n') +
                    (cands.length > 5 ? '\n… a dalších ' + (cands.length - 5) : '')
                : 'Neznámý repeater';
            var $h = $('<span class="hop">').attr('title', h + ': ' + title).attr('data-hash', h);
            if (!cands.length) {
                $h.addClass('hop-unknown').text(h);
            } else {
                $h.text(cands[0].name);
                if (cands.length > 1) { $h.addClass('hop-amb').append($('<sup>').text(' ?' + cands.length)); }
                if (!compact) { $h.append($('<span class="hop-hash">').text(h)); }
            }
            parts.push($h.prop('outerHTML'));
        });
        if (!compact && from) { parts.push('<span class="hop hop-end">' + esc(to || (from === 'ty' ? 'zpět k tobě' : 'ty')) + '</span>'); }
        return '<div class="hop-path' + (compact ? ' compact' : '') + '">' + parts.join('<i class="bi bi-arrow-right hop-arrow"></i>') + '</div>';
    }

    function showHopCandidates($hop) {
        var h = $hop.attr('data-hash'), cands = hopCandidates(h);
        var $wrap = $hop.closest('.hop-path');
        var $list = $wrap.next('.hop-cands');
        if ($list.length && $list.attr('data-hash') === h) { $list.remove(); return; }
        $list.remove();
        $list = $('<div class="hop-cands small">').attr('data-hash', h)
            .append($('<div class="text-body-secondary mb-1">').text('Hash ' + h + ' – ' +
                (cands.length ? 'možné uzly (' + cands.length + '):' : 'žádný známý repeater ani room server')));
        cands.slice(0, 10).forEach(function (c, i) {
            $('<div class="d-flex align-items-center gap-2">')
                .append($('<i class="bi">').addClass(TYPE_ICONS[c.type] || 'bi-cpu').addClass('text-body-secondary'))
                .append($('<span>').toggleClass('fw-semibold', i === 0).text(c.name))
                .append($('<span class="font-monospace text-body-secondary">').text(c.pk))
                .append(scopeLabel(c.scopes) ? $('<span class="scope-badge">').text(scopeLabel(c.scopes)) : '')
                .append($('<span class="ms-auto text-body-secondary text-nowrap">').text(fmtAgo(c.last_advert)))
                .appendTo($list);
        });
        if (cands.length > 10) {
            $list.append($('<div class="text-body-secondary">').text('… a dalších ' + (cands.length - 10)));
        }
        $wrap.after($list);
    }

    // ---------- graf historie baterie ----------
    var RANGES = [['24h', '24 h'], ['7d', '7 dní'], ['30d', '30 dní'], ['1y', 'Rok']];
    var SVGNS = 'http://www.w3.org/2000/svg';

    function batterySeries(d) {
        var s = d.sensors || {}, out = [];
        if (s.battery_percentage && s.battery_percentage.id) {
            out.push({ key: 'pct', label: '%', sensor: s.battery_percentage, yMin: 0, yMax: 100 });
        }
        var v = s.bat || s.battery_voltage;
        if (v && v.id) { out.push({ key: 'v', label: 'V', sensor: v }); }
        return out;
    }

    function batteryChartHtml(series) {
        return '<div class="d-flex align-items-center justify-content-between mt-1 mb-2 gap-2 flex-wrap">' +
            '<h6 class="m-0">Historie baterie</h6>' +
            (series.length > 1 ? '<div class="btn-group btn-group-sm" id="bat-unit">' + series.map(function (x, i) {
                return '<button type="button" class="btn btn-outline-secondary' + (i === 0 ? ' active' : '') + '" data-i="' + i + '">' + esc(x.label) + '</button>';
            }).join('') + '</div>' : '') + '</div>' +
            '<div class="btn-group btn-group-sm w-100 mb-2" id="bat-range">' + RANGES.map(function (r, i) {
                return '<button type="button" class="btn btn-outline-primary' + (i === 0 ? ' active' : '') + '" data-r="' + r[0] + '">' + r[1] + '</button>';
            }).join('') + '</div>' +
            '<div class="hist-chart" id="bat-chart"></div>' +
            '<div class="small text-body-secondary mt-1" id="bat-note"></div>' +
            '<details class="small mt-1 mb-3"><summary class="text-body-secondary">Tabulka hodnot</summary><div id="bat-table" class="hist-table"></div></details>';
    }

    function initBatteryChart(series) {
        var st = { i: 0, range: '24h', req: 0 };
        function load() {
            var ser = series[st.i], req = ++st.req;
            $('#bat-chart').html('<div class="hist-empty"><span class="spinner-border spinner-border-sm"></span></div>');
            $('#bat-note').text('');
            api('history', { entity: ser.sensor.id, range: st.range }).done(function (r) {
                if (req !== st.req) { return; }
                var h = r.history;
                drawHistory($('#bat-chart'), h, { unit: ser.sensor.unit || ser.label, yMin: ser.yMin, yMax: ser.yMax, range: st.range });
                var note = h.source === 'statistics'
                    ? (st.range === '1y' ? 'Denní průměry' : 'Hodinové průměry') + ' z dlouhodobých statistik HA, světlý pás = rozsah min–max.'
                    : 'Přesné hodnoty z historie HA.';
                if (h.source === 'history' && (st.range === '30d' || st.range === '1y')) {
                    note = 'Senzor nemá dlouhodobé statistiky – zobrazena jen historie, kterou HA drží (výchozí 10 dní).';
                }
                $('#bat-note').text(note);
                historyTable($('#bat-table'), h, ser.sensor.unit || ser.label);
            }).fail(function (xhr) {
                if (req !== st.req) { return; }
                $('#bat-chart').html('<div class="hist-empty text-danger">' + esc(errText(xhr)) + '</div>');
            });
        }
        $('#bat-range').on('click', 'button', function () {
            $('#bat-range button').removeClass('active');
            st.range = $(this).addClass('active').attr('data-r');
            load();
        });
        $('#bat-unit').on('click', 'button', function () {
            $('#bat-unit button').removeClass('active');
            st.i = +$(this).addClass('active').attr('data-i');
            load();
        });
        load();
    }

    function niceTicks(min, max, count) {
        var span = max - min || 1;
        var step = Math.pow(10, Math.floor(Math.log10(span / count)));
        var err = span / count / step;
        step *= err >= 7.5 ? 10 : err >= 3.5 ? 5 : err >= 1.5 ? 2 : 1;
        var ticks = [];
        for (var v = Math.ceil(min / step) * step; v <= max + step * 1e-9; v += step) { ticks.push(+v.toFixed(10)); }
        return ticks;
    }

    function fmtNum(v) {
        return (Math.round(v * 100) / 100).toLocaleString('cs-CZ', { maximumFractionDigits: 2 });
    }

    function fmtAxisTime(t, range) {
        var d = new Date(t);
        if (range === '24h') { return d.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' }); }
        if (range === '1y') { return d.toLocaleDateString('cs-CZ', { month: 'short', year: '2-digit' }); }
        return d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' });
    }

    function svg(tag, attrs, parent) {
        var el = document.createElementNS(SVGNS, tag);
        for (var k in attrs) { el.setAttribute(k, attrs[k]); }
        if (parent) { parent.appendChild(el); }
        return el;
    }

    // Překreslí graf při změně šířky (i když se vykreslil dřív, než se okno zobrazilo)
    function drawHistory($box, h, o) {
        var el = $box[0];
        el._hist = { h: h, o: o };
        if (window.ResizeObserver && !el._ro) {
            var lastW = 0;
            el._ro = new ResizeObserver(function () {
                var w = Math.round($box.width());
                if (w > 0 && Math.abs(w - lastW) > 4 && el._hist) {
                    lastW = w;
                    renderHistory($box, el._hist.h, el._hist.o);
                }
            });
            el._ro.observe(el);
        }
        renderHistory($box, h, o);
    }

    function renderHistory($box, h, o) {
        var pts = h.points || [];
        $box.empty();
        if (!pts.length) {
            $box.html('<div class="hist-empty text-body-secondary">V tomto období nejsou žádná data.</div>');
            return;
        }
        var band = pts[0].length === 4;
        var W = Math.max(260, $box.width()), H = 190, M = { l: 38, r: 62, t: 12, b: 26 };
        var x0 = Date.parse(h.from), x1 = Date.parse(h.to);
        var lo = Infinity, hi = -Infinity;
        pts.forEach(function (p) {
            lo = Math.min(lo, band ? p[2] : p[1]);
            hi = Math.max(hi, band ? p[3] : p[1]);
        });
        var yMin = o.yMin != null ? o.yMin : lo - (hi - lo) * 0.15 - 0.01;
        var yMax = o.yMax != null ? o.yMax : hi + (hi - lo) * 0.15 + 0.01;
        var X = function (t) { return M.l + (t - x0) / (x1 - x0) * (W - M.l - M.r); };
        var Y = function (v) { return M.t + (1 - (v - yMin) / (yMax - yMin)) * (H - M.t - M.b); };

        var root = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, width: '100%', height: H, role: 'img',
            'aria-label': 'Graf historie baterie' });
        var grid = svg('g', { class: 'hist-grid' }, root);
        niceTicks(yMin, yMax, 4).forEach(function (v) {
            if (v < yMin || v > yMax) { return; }
            svg('line', { x1: M.l, x2: W - M.r, y1: Y(v), y2: Y(v) }, grid);
            svg('text', { x: M.l - 6, y: Y(v) + 4, 'text-anchor': 'end', class: 'hist-axis' }, grid).textContent = fmtNum(v);
        });
        for (var i = 0; i <= 4; i++) {
            var t = x0 + (x1 - x0) * i / 4;
            svg('text', { x: X(t), y: H - 6, 'text-anchor': i === 0 ? 'start' : i === 4 ? 'end' : 'middle', class: 'hist-axis' }, grid)
                .textContent = fmtAxisTime(t, o.range);
        }

        var line = pts.map(function (p, i) { return (i ? 'L' : 'M') + X(p[0]).toFixed(1) + ',' + Y(p[1]).toFixed(1); }).join('');
        if (band) {
            var up = pts.map(function (p, i) { return (i ? 'L' : 'M') + X(p[0]).toFixed(1) + ',' + Y(p[3]).toFixed(1); }).join('');
            var down = pts.slice().reverse().map(function (p) { return 'L' + X(p[0]).toFixed(1) + ',' + Y(p[2]).toFixed(1); }).join('');
            svg('path', { d: up + down + 'Z', class: 'hist-band' }, root);
        } else {
            var last = pts[pts.length - 1], first = pts[0];
            svg('path', { d: line + 'L' + X(last[0]).toFixed(1) + ',' + Y(yMin) + 'L' + X(first[0]).toFixed(1) + ',' + Y(yMin) + 'Z', class: 'hist-area' }, root);
        }
        svg('path', { d: line, class: 'hist-line' }, root);

        // koncový bod s hodnotou
        var lp = pts[pts.length - 1];
        svg('circle', { cx: X(lp[0]), cy: Y(lp[1]), r: 4, class: 'hist-dot' }, root);
        svg('text', { x: X(lp[0]) + 8, y: Y(lp[1]) + 4, class: 'hist-endlabel' }, root).textContent = fmtNum(lp[1]) + ' ' + o.unit;

        // crosshair + tooltip
        var cross = svg('line', { y1: M.t, y2: H - M.b, class: 'hist-cross', visibility: 'hidden' }, root);
        var hot = svg('circle', { r: 4, class: 'hist-dot', visibility: 'hidden' }, root);
        var $tip = $('<div class="hist-tip d-none">');
        var hit = svg('rect', { x: M.l, y: 0, width: W - M.l - M.r, height: H, fill: 'transparent' }, root);
        function nearest(t) {
            var a = 0, b = pts.length - 1;
            while (b - a > 1) { var m = (a + b) >> 1; if (pts[m][0] < t) { a = m; } else { b = m; } }
            return Math.abs(pts[a][0] - t) <= Math.abs(pts[b][0] - t) ? pts[a] : pts[b];
        }
        function move(e) {
            var rect = root.getBoundingClientRect();
            var px = (e.clientX - rect.left) * W / rect.width;
            var t = x0 + (px - M.l) / (W - M.l - M.r) * (x1 - x0);
            var p = nearest(t), cx = X(p[0]);
            cross.setAttribute('x1', cx); cross.setAttribute('x2', cx); cross.setAttribute('visibility', 'visible');
            hot.setAttribute('cx', cx); hot.setAttribute('cy', Y(p[1])); hot.setAttribute('visibility', 'visible');
            $tip.empty()
                .append($('<div class="fw-semibold">').text(fmtNum(p[1]) + ' ' + o.unit + (band ? '' : '')))
                .append(band ? $('<div class="text-body-secondary">').text('min ' + fmtNum(p[2]) + ' · max ' + fmtNum(p[3])) : '')
                .append($('<div class="text-body-secondary">').text(new Date(p[0]).toLocaleString('cs-CZ',
                    o.range === '1y' ? { day: 'numeric', month: 'numeric', year: 'numeric' } : { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' })))
                .removeClass('d-none');
            var left = cx / W * rect.width, tw = $tip.outerWidth();
            $tip.css({ left: Math.min(Math.max(0, left - tw / 2), rect.width - tw), top: 0 });
        }
        function leave() {
            cross.setAttribute('visibility', 'hidden');
            hot.setAttribute('visibility', 'hidden');
            $tip.addClass('d-none');
        }
        hit.addEventListener('pointermove', move);
        hit.addEventListener('pointerdown', move);
        hit.addEventListener('pointerleave', leave);
        $box.append(root, $tip);
    }

    function historyTable($box, h, unit) {
        var pts = (h.points || []).slice().reverse();
        if (!pts.length) { $box.empty(); return; }
        var band = pts[0].length === 4;
        var $t = $('<table class="table table-sm table-striped mb-0">').append(
            '<thead><tr><th>Čas</th><th class="text-end">' + (band ? 'Průměr' : 'Hodnota') + '</th>' +
            (band ? '<th class="text-end">Min</th><th class="text-end">Max</th>' : '') + '</tr></thead>');
        var $b = $('<tbody>').appendTo($t);
        pts.forEach(function (p) {
            var $r = $('<tr>').append($('<td>').text(new Date(p[0]).toLocaleString('cs-CZ')))
                .append($('<td class="text-end">').text(fmtNum(p[1]) + ' ' + unit));
            if (band) {
                $r.append($('<td class="text-end">').text(fmtNum(p[2])), $('<td class="text-end">').text(fmtNum(p[3])));
            }
            $b.append($r);
        });
        $box.empty().append($t);
    }

    // Vzdálená správa (stav, advert) – heslo jen volitelně v sessionStorage, na server se neukládá
    function rcPwKey(pk) { return 'mc.rcpw.' + pk; }

    function remotePanelHtml() {
        return '<h6 class="mt-3">Vzdálená správa</h6>' +
            '<div class="input-group input-group-sm mb-2"><span class="input-group-text"><i class="bi bi-key"></i></span>' +
            '<input type="text" class="form-control rc-secret" id="rc-pw" placeholder="Heslo – prázdné = host (nebo admin přes ACL)" ' +
                'autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore data-form-type="other"></div>' +
            '<div class="form-check small mb-2"><input class="form-check-input" type="checkbox" id="rc-remember">' +
            '<label class="form-check-label" for="rc-remember">Pamatovat heslo do zavření karty prohlížeče</label></div>' +
            '<div class="d-flex flex-wrap gap-2">' +
            '<button type="button" class="btn btn-sm btn-outline-secondary rc-btn" data-cmd="status"><i class="bi bi-activity me-1"></i>Stav</button>' +
            '<button type="button" class="btn btn-sm btn-outline-primary rc-btn" data-cmd="advert"><i class="bi bi-broadcast-pin me-1"></i>Advert (flood)</button>' +
            '<button type="button" class="btn btn-sm btn-outline-primary rc-btn" data-cmd="advert.zerohop"><i class="bi bi-broadcast me-1"></i>Advert (local)</button></div>' +
            '<div class="form-text">Stav stačí jako host bez hesla, ostatní příkazy vyžadují admin heslo.</div>' +
            '<div class="input-group input-group-sm mt-2">' +
            '<select class="form-select" id="rc-cmd" style="max-width:14rem">' +
            '<option value="ver">Verze firmwaru</option><option value="clock sync">Synchronizovat čas</option>' +
            '<option value="name">Přejmenovat…</option><option value="latlon">Nastavit polohu…</option><option value="reboot">Restart (reboot)</option></select>' +
            '<button type="button" class="btn btn-outline-secondary rc-btn" data-cmd="_sel"><i class="bi bi-play me-1"></i>Odeslat</button></div>' +
            '<div class="mt-2 d-none" id="rc-arg-name"><input type="text" class="form-control form-control-sm" id="rc-name" maxlength="31" placeholder="Nové jméno uzlu"></div>' +
            '<div class="mt-2 d-none" id="rc-arg-pos"><div class="input-group input-group-sm">' +
            '<input type="text" class="form-control" id="rc-lat" placeholder="Zeměpisná šířka (50.0755)" inputmode="decimal">' +
            '<input type="text" class="form-control" id="rc-lon" placeholder="Délka (14.4378)" inputmode="decimal">' +
            '<button type="button" class="btn btn-outline-secondary" id="rc-gps" title="Moje poloha z prohlížeče"><i class="bi bi-crosshair"></i></button></div></div>' +
            '<div class="small mt-2" id="rc-out"></div><div id="rc-status"></div>';
    }

    function fmtNum(n) { return n == null ? '–' : Number(n).toLocaleString('cs-CZ'); }

    function remoteStatusHtml(st, at) {
        var up = st.uptime || 0, rows = '';
        function r(l, v) { rows += row(l, esc(v)); }
        if (st.bat) { r('Baterie', (st.bat / 1000).toFixed(2) + ' V'); }
        r('Uptime', fmtDuration(up));
        r('Airtime TX', fmtDuration(st.airtime || 0) + (up ? ' (' + (100 * (st.airtime || 0) / up).toFixed(1) + ' %)' : ''));
        if (st.rx_airtime != null) { r('Airtime RX', fmtDuration(st.rx_airtime) + (up ? ' (' + (100 * st.rx_airtime / up).toFixed(1) + ' %)' : '')); }
        r('Šum (noise floor)', st.noise_floor + ' dBm');
        r('Poslední RSSI / SNR', st.last_rssi + ' dBm / ' + st.last_snr + ' dB');
        r('Přijato paketů', fmtNum(st.nb_recv) + ' (flood ' + fmtNum(st.recv_flood) + ', direct ' + fmtNum(st.recv_direct) + ')');
        r('Odesláno paketů', fmtNum(st.nb_sent) + ' (flood ' + fmtNum(st.sent_flood) + ', direct ' + fmtNum(st.sent_direct) + ')');
        r('Duplicity', 'flood ' + fmtNum(st.flood_dups) + ', direct ' + fmtNum(st.direct_dups));
        if (st.recv_errors != null) { r('Chyby příjmu', fmtNum(st.recv_errors)); }
        r('Fronta TX / plná fronta', fmtNum(st.tx_queue_len) + ' / ' + fmtNum(st.full_evts) + '×');
        return '<table class="table table-sm small mt-2 mb-0"><caption class="caption-top pb-1">Stav k ' +
            esc(new Date(at).toLocaleTimeString('cs-CZ')) + '</caption>' + rows + '</table>';
    }

    // d = { pk, name }, onAdded = callback, když server uzel nově přidal do kontaktů companionu
    function initRemote(d, onAdded) {
        var saved = null;
        try { saved = sessionStorage.getItem(rcPwKey(d.pk)); } catch (e) { /* nedostupné */ }
        if (saved !== null) { $('#rc-pw').val(saved); $('#rc-remember').prop('checked', true); }
        $('#rc-cmd').on('change', function () {
            $('#rc-arg-name').toggleClass('d-none', this.value !== 'name');
            $('#rc-arg-pos').toggleClass('d-none', this.value !== 'latlon');
        });
        $('#rc-gps').on('click', function () {
            if (!navigator.geolocation) { toast('Prohlížeč neumí zjistit polohu.', 'warning'); return; }
            navigator.geolocation.getCurrentPosition(function (p) {
                $('#rc-lat').val(p.coords.latitude.toFixed(6)); $('#rc-lon').val(p.coords.longitude.toFixed(6));
            }, function () { toast('Polohu se nepodařilo zjistit.', 'warning'); }, { enableHighAccuracy: true, timeout: 15000 });
        });
        $('.rc-btn').on('click', function () {
            var cmd = $(this).attr('data-cmd'), pw = $('#rc-pw').val(), status = cmd === 'status', arg = null;
            if (cmd === '_sel') {
                cmd = $('#rc-cmd').val();
                if (cmd === 'name') { arg = $.trim($('#rc-name').val()); if (!arg) { toast('Zadej nové jméno.', 'warning'); return; } }
                if (cmd === 'latlon') { arg = { lat: $.trim($('#rc-lat').val()).replace(',', '.'), lon: $.trim($('#rc-lon').val()).replace(',', '.') }; }
                var q = { 'reboot': 'Restartovat uzel „' + d.name + '“? Na chvíli přestane přeposílat pakety.',
                    'name': 'Přejmenovat uzel „' + d.name + '“ na „' + arg + '“? Nové jméno se ostatním ukáže po dalším advertu.',
                    'latlon': 'Nastavit uzlu „' + d.name + '“ polohu ' + (arg && arg.lat) + ', ' + (arg && arg.lon) + '?',
                    'clock sync': 'Synchronizovat čas uzlu „' + d.name + '“ podle tohoto počítače? (Jde jen dopředu.)' }[cmd];
                if (q && !confirm(q)) { return; }
            }
            if (cmd === 'advert' && !confirm('Poslat uzlu „' + d.name + '“ příkaz k flood advertu? Rozešle se celou sítí.')) { return; }
            try {
                if ($('#rc-remember').prop('checked')) { sessionStorage.setItem(rcPwKey(d.pk), pw); } else { sessionStorage.removeItem(rcPwKey(d.pk)); }
            } catch (e) { /* nedostupné */ }
            var $btns = $('.rc-btn').prop('disabled', true);
            $('#rc-out').html('<span class="text-body-secondary"><span class="spinner-border spinner-border-sm me-1"></span>Přihlašuji se k uzlu ' +
                (pw === '' ? 'jako host' : 's heslem') + ' a ' + (status ? 'čekám na stav' : 'posílám příkaz') + '… (může trvat i desítky sekund)</span>');
            function added(r) {
                if (r && r.added) {
                    toast('Uzel „' + d.name + '“ byl přidán do kontaktů companionu', 'info');
                    if (onAdded) { onAdded(); }
                }
            }
            api(status ? 'remote_status' : 'remote_cmd', status ? { pk: d.pk, password: pw } : { pk: d.pk, cmd: cmd, arg: arg, password: pw }, 'POST').done(function (r) {
                added(r);
                var role = r.admin === true ? 'admin' : r.admin === false ? 'host' : null;
                if (status) {
                    $('#rc-out').html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>Stav načten' + (role ? ' (přihlášen jako ' + role + ')' : '') + '.</span>');
                    $('#rc-status').html(remoteStatusHtml(r.status, r.at));
                    return;
                }
                var msg = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Příkaz „' + esc(r.what) + '“ odeslán.</span>' +
                    (r.reply ? '<div class="mt-1">Odpověď uzlu: <code>' + esc(r.reply) + '</code></div>'
                        : ' <span class="text-body-secondary">' + (S.ble ? 'Uzel zatím neodpověděl (odpověď může přijít později jako upozornění).'
                            : 'Uzel zatím neodpověděl – odpověď se případně objeví v chatu s uzlem.') + '</span>');
                if (r.admin === false) {
                    msg += '<div class="text-warning mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Přihlášen jen jako host – uzel příkaz nejspíš odmítne. Zadej admin heslo.</div>';
                }
                $('#rc-out').html(msg);
            }).fail(function (xhr) {
                added(xhr.responseJSON);
                $('#rc-out').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + esc(errText(xhr)) + '</span>');
            }).always(function () {
                setTimeout(function () { $btns.prop('disabled', false); }, status ? 1000 : 5000);
            });
        });
    }

    // Stav / správa slyšeného (nepřidaného) repeateru nebo room serveru
    function showRemoteNode(n) {
        var html = '<table class="table table-sm mb-2">' +
            row('Typ', esc(TYPE_NAMES[n.type] || 'Uzel')) +
            row('Klíč (prefix)', '<span class="font-monospace">' + esc(n.pk12) + '</span>') +
            row('Poslední advert', esc(n.last_advert ? new Date(n.last_advert).toLocaleString('cs-CZ') + ' (' + fmtAgo(n.last_advert) + ')' : '–')) +
            (n.path_len != null && n.path_len >= 0 ? row('Vzdálenost', n.path_len + ' hop') : '') +
            (n.lat != null ? row('Poloha', '<a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=' + n.lat + '&mlon=' + n.lon +
                '#map=13/' + n.lat + '/' + n.lon + '">' + n.lat.toFixed(5) + ', ' + n.lon.toFixed(5) + '</a>') : '') +
            '</table>' +
            '<div class="d-flex align-items-center gap-2 mb-2"><button type="button" class="btn btn-sm btn-primary text-nowrap" id="rn-add">' +
            '<i class="bi bi-person-plus me-1"></i>Přidat do kontaktů</button>' +
            '<span class="small text-body-secondary" id="rn-add-note">Uzel není v kontaktech companionu.</span></div>' +
            (n.type === 2 || n.type === 3 ? '<div class="small text-body-secondary">Při první akci níže se do kontaktů přidá automaticky (bez toho se k němu nejde přihlásit).</div>' : '') +
            ((n.type === 2 || n.type === 3) && can('remote') ? remotePanelHtml() : '') +
            '<div class="d-grid mt-3"><button type="button" class="btn btn-outline-secondary btn-sm" id="rn-back"><i class="bi bi-arrow-left me-1"></i>Zpět na slyšené uzly</button></div>';
        showModal(n.name || n.pk12, html);
        if ((n.type === 2 || n.type === 3) && can('remote')) {
            initRemote({ pk: n.pk12, name: n.name || n.pk12 }, function () {
                api('conversations').done(function (r) { setConversations(r.conversations); });
            });
        }
        $('#rn-add').on('click', function () {
            var $b = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Přidávám…');
            api('contact_add', { pk: n.pk12 }, 'POST').done(function (r) {
                setConversations(r.conversations);
                toast('Kontakt přidán', 'success');
                $b.removeClass('btn-primary').addClass('btn-outline-success').html('<i class="bi bi-check-lg me-1"></i>V kontaktech');
                var conv = 'd:' + n.pk12;
                $('#rn-add-note').html(n.type === 1 && S.convs[conv]
                    ? '<a href="#" id="rn-open">Otevřít konverzaci</a>' : 'Uzel je teď v kontaktech companionu.');
                $('#rn-open').on('click', function (e) {
                    e.preventDefault();
                    bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
                    openConv(conv);
                });
            }).fail(function (xhr) {
                toast(errText(xhr), 'danger');
                $b.prop('disabled', false).html('<i class="bi bi-person-plus me-1"></i>Přidat do kontaktů');
            });
        });
        $('#rn-back').on('click', showAddContact);
    }

    function showDevice(key) {
        var D = S.devices, kind = key.charAt(0), pk = key.slice(2), d = null;
        (kind === 'c' ? D.companions : D.devices).forEach(function (x) { if (x.pk === pk) { d = x; } });
        if (!d) { return; }
        var html = '<table class="table table-sm mb-3">';
        if (kind === 'd') {
            html += row('Stav', d.online === true ? '<span class="text-success">online</span>' : d.online === false ? '<span class="text-danger">offline</span>' : 'neznámý') +
                row('Poslední změna', esc(d.changed ? new Date(d.changed).toLocaleString('cs-CZ') + ' (' + fmtAgo(d.changed) + ')' : '–')) +
                row('Klíč (prefix)', '<span class="font-monospace">' + esc(d.pk) + '</span>');
            html += scopeRows(d.scopes);
            if (d.lat != null) {
                html += row('Poloha', '<a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=' + d.lat + '&mlon=' + d.lon +
                    '#map=13/' + d.lat + '/' + d.lon + '">' + d.lat.toFixed(5) + ', ' + d.lon.toFixed(5) + '</a>');
            }
        } else {
            html += row('Stav', esc(d.status || '–'));
        }
        Object.keys(SENSOR_LABELS).forEach(function (k) {
            if (!d.sensors[k]) { return; }
            if (k === 'out_path') {
                var op = sval(d.sensors[k]), opl = d.sensors.out_path_len ? parseInt(sval(d.sensors.out_path_len), 10) : null;
                html += row('Cesta', op ? pathHtml(op, opl, null, null, true) : (opl === 0 ? 'přímo' : '–'));
                return;
            }
            html += row(SENSOR_LABELS[k], esc(fmtSensor(k, d.sensors[k])));
        });
        html += '</table>';
        var batSeries = batterySeries(d);
        if (batSeries.length) { html += batteryChartHtml(batSeries); }
        var tele = Object.keys(d.telemetry || {});
        if (tele.length) {
            html += '<h6>Telemetrie</h6><table class="table table-sm mb-3">';
            tele.forEach(function (k) { html += row(d.telemetry[k].name || k, esc(fmtSensor(k, d.telemetry[k]))); });
            html += '</table>';
        }
        var other = Object.keys(d.other || {});
        if (other.length) {
            html += '<details><summary class="small text-body-secondary mb-2">Další senzory (' + other.length + ')</summary><table class="table table-sm small">';
            other.forEach(function (k) { html += row(d.other[k].name || k, esc(fmtSensor(k, d.other[k]))); });
            html += '</table></details>';
        }
        var remote = kind === 'd' && d.type !== 1 && can('remote'); // repeater, room server, senzor (klienti CLI nemají)
        if (remote) { html += remotePanelHtml(); }
        if (d.conv && S.convs[d.conv]) {
            html += '<div class="d-grid mt-3"><button class="btn btn-primary" id="dev-open-chat"><i class="bi bi-chat-dots me-1"></i> Otevřít chat</button></div>';
        }
        showModal(d.name, html);
        if (remote) { initRemote(d); }
        if (batSeries.length) { initBatteryChart(batSeries); }
        $('#dev-open-chat').on('click', function () {
            bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
            openConv(d.conv);
        });
    }

    // ---------- nastavení companionu (Bluetooth) ----------
    var BW_OPTIONS = [7.8, 10.4, 15.6, 20.8, 31.25, 41.7, 62.5, 125, 250, 500];

    function showBleSettings() {
        api('ble_settings').done(function (r) {
            var s = r.self, dis = r.connected ? '' : ' disabled';
            var bw = (s.radioBw || 0) / 1000, freq = (s.radioFreq || 0) / 1000;
            var html = (r.connected ? '' : '<div class="alert alert-warning py-2 small">Companion není připojen – nastavení jen pro čtení.</div>') +
                '<form id="bs-form">' +
                '<div class="mb-3"><label class="form-label" for="bs-name">Jméno</label>' +
                '<input type="text" class="form-control" id="bs-name" maxlength="31" value="' + esc(s.name || '') + '"' + dis + '>' +
                '<div class="form-text">Ostatním se nové jméno ukáže po dalším advertu.</div></div>' +
                '<label class="form-label">Poloha (posílá se v advertu)</label><div class="input-group mb-3">' +
                '<input type="text" class="form-control" id="bs-lat" inputmode="decimal" placeholder="šířka" value="' + (s.lat != null ? s.lat : '') + '"' + dis + '>' +
                '<input type="text" class="form-control" id="bs-lon" inputmode="decimal" placeholder="délka" value="' + (s.lon != null ? s.lon : '') + '"' + dis + '>' +
                '<button type="button" class="btn btn-outline-secondary" id="bs-gps" title="Moje poloha z prohlížeče"' + dis + '><i class="bi bi-crosshair"></i></button>' +
                '<button type="button" class="btn btn-outline-secondary" id="bs-nopos" title="Polohu nesdílet (0, 0)"' + dis + '><i class="bi bi-eye-slash"></i></button></div>' +
                '<div class="row g-2 mb-3"><div class="col-6"><label class="form-label" for="bs-tx">Vysílací výkon (dBm)</label>' +
                '<input type="number" class="form-control" id="bs-tx" min="-9" max="' + (s.maxTxPower || 22) + '" value="' + (s.txPower != null ? s.txPower : '') + '"' + dis + '>' +
                '<div class="form-text">max. ' + (s.maxTxPower || 22) + ' dBm</div></div>' +
                '<div class="col-6 d-flex align-items-center"><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" id="bs-manual"' +
                (s.manualAdd ? ' checked' : '') + dis + '><label class="form-check-label" for="bs-manual">Kontakty přidávat ručně</label></div></div></div>' +
                '<details class="mb-3"><summary class="fw-semibold">Rádiové parametry</summary>' +
                '<div class="alert alert-danger py-2 small mt-2"><i class="bi bi-exclamation-triangle me-1"></i>Se špatnými hodnotami companion přestane slyšet síť ' +
                '(a ostatní jeho). Měň jen pokud víš, jaké parametry síť používá (v ČR typicky 869.618 MHz, BW 62.5, SF 8, CR 8).</div>' +
                '<div class="row g-2"><div class="col-6"><label class="form-label small" for="bs-freq">Frekvence (MHz)</label>' +
                '<input type="text" class="form-control form-control-sm" id="bs-freq" inputmode="decimal" value="' + freq.toFixed(3) + '"' + dis + '></div>' +
                '<div class="col-6"><label class="form-label small" for="bs-bw">Šířka pásma (kHz)</label><select class="form-select form-select-sm" id="bs-bw"' + dis + '>' +
                BW_OPTIONS.map(function (o) { return '<option value="' + o + '"' + (Math.abs(o - bw) < 0.05 ? ' selected' : '') + '>' + o + '</option>'; }).join('') + '</select></div>' +
                '<div class="col-6"><label class="form-label small" for="bs-sf">Spreading factor</label><select class="form-select form-select-sm" id="bs-sf"' + dis + '>' +
                [5, 6, 7, 8, 9, 10, 11, 12].map(function (o) { return '<option' + (o === s.radioSf ? ' selected' : '') + '>' + o + '</option>'; }).join('') + '</select></div>' +
                '<div class="col-6"><label class="form-label small" for="bs-cr">Coding rate (4/x)</label><select class="form-select form-select-sm" id="bs-cr"' + dis + '>' +
                [5, 6, 7, 8].map(function (o) { return '<option' + (o === s.radioCr ? ' selected' : '') + '>' + o + '</option>'; }).join('') + '</select></div></div></details>' +
                '<div class="text-danger small mb-2" id="bs-error"></div>' +
                '<button type="submit" class="btn btn-primary w-100"' + dis + '><i class="bi bi-check-lg me-1"></i>Uložit změny</button></form>';
            showModal('Nastavení companionu', html);
            $('#bs-gps').on('click', function () {
                if (!navigator.geolocation) { toast('Prohlížeč neumí zjistit polohu.', 'warning'); return; }
                navigator.geolocation.getCurrentPosition(function (p) {
                    $('#bs-lat').val(p.coords.latitude.toFixed(6)); $('#bs-lon').val(p.coords.longitude.toFixed(6));
                }, function () { toast('Polohu se nepodařilo zjistit.', 'warning'); }, { enableHighAccuracy: true, timeout: 15000 });
            });
            $('#bs-nopos').on('click', function () { $('#bs-lat').val('0'); $('#bs-lon').val('0'); });
            $('#bs-form').on('submit', function (e) {
                e.preventDefault();
                var q = {}, name = $.trim($('#bs-name').val());
                if (name !== (s.name || '')) { q.name = name; }
                var la = $.trim($('#bs-lat').val()), lo = $.trim($('#bs-lon').val());
                if (la !== String(s.lat != null ? s.lat : '') || lo !== String(s.lon != null ? s.lon : '')) { q.lat = la || '0'; q.lon = lo || '0'; }
                if (+$('#bs-tx').val() !== s.txPower) { q.tx = +$('#bs-tx').val(); }
                if ($('#bs-manual').prop('checked') !== !!s.manualAdd) { q.manual = $('#bs-manual').prop('checked'); }
                var rf = +$.trim($('#bs-freq').val()).replace(',', '.'), rb = +$('#bs-bw').val(), rs = +$('#bs-sf').val(), rc = +$('#bs-cr').val();
                if (Math.abs(rf - freq) > 0.0005 || Math.abs(rb - bw) > 0.05 || rs !== s.radioSf || rc !== s.radioCr) {
                    if (!confirm('Opravdu změnit rádiové parametry na ' + rf + ' MHz / BW ' + rb + ' / SF' + rs + ' / CR' + rc + '?\nSe špatnými hodnotami companion přestane komunikovat se sítí.')) { return; }
                    q.radio = { freq: rf, bw: rb, sf: rs, cr: rc };
                }
                if (!Object.keys(q).length) { $('#bs-error').text('Nic se nezměnilo.'); return; }
                var $b = $('#bs-form button[type=submit]').prop('disabled', true);
                api('ble_settings', q, 'POST').done(function () {
                    toast('Nastavení companionu uloženo', 'success');
                    bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
                }).fail(function (xhr) { $('#bs-error').text(errText(xhr)); $b.prop('disabled', false); });
            });
        }).fail(function (xhr) { toast(errText(xhr), 'warning'); });
    }

    // ---------- heslo k přihlášení ----------
    function showPassword() {
        showModal('Heslo k přihlášení', '<div class="text-center p-3"><span class="spinner-border spinner-border-sm"></span></div>');
        api('account').done(function (r) {
            var a = r.account, has = a.has_password;
            var html =
                '<p class="small text-body-secondary">' + (has
                    ? 'Heslo máš nastavené' + (a.changed_at ? ' (změněno ' + esc(new Date(a.changed_at).toLocaleString('cs-CZ')) + ')' : '') +
                      '. Přihlásit se můžeš heslem i jednorázovým kódem.'
                    : 'Zatím se přihlašuješ jen jednorázovým kódem. Po nastavení hesla si na přihlašovací stránce vybereš, jestli použiješ kód, nebo heslo.') + '</p>' +
                '<form id="pw-form" autocomplete="on">' +
                '<input type="text" name="username" autocomplete="username" value="' + esc(a.username || '') + '" class="d-none" readonly>' +
                '<div class="mb-3"><label class="form-label" for="pw-current">' + (has ? 'Současné heslo nebo kód z aplikace' : 'Jednorázový kód z aplikace') + '</label>' +
                '<input type="' + (has ? 'password' : 'text') + '" class="form-control" id="pw-current" autocomplete="' + (has ? 'current-password' : 'one-time-code') + '"' +
                (has ? '' : ' inputmode="numeric" maxlength="6"') + ' required></div>' +
                '<div class="mb-3"><label class="form-label" for="pw-new">' + (has ? 'Nové heslo' : 'Heslo') + '</label>' +
                '<input type="password" class="form-control" id="pw-new" autocomplete="new-password" minlength="' + a.min + '">' +
                '<div class="form-text">Aspoň ' + a.min + ' znaků. Delší fráze z několika slov je bezpečnější než krátké složité heslo.</div></div>' +
                '<div class="mb-3"><label class="form-label" for="pw-new2">Heslo znovu</label>' +
                '<input type="password" class="form-control" id="pw-new2" autocomplete="new-password"></div>' +
                '<div class="text-danger small mb-2" id="pw-error"></div>' +
                '<button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>' + (has ? 'Změnit heslo' : 'Nastavit heslo') + '</button>' +
                (has ? '<button type="button" class="btn btn-outline-danger btn-sm w-100 mt-2" id="pw-remove"><i class="bi bi-x-lg me-1"></i>Zrušit heslo (přihlašovat jen kódem)</button>' : '') +
                '</form>';
            $('#info-body').html(html);
            function send(data) {
                $('#pw-error').text('');
                var $b = $('#pw-form button').prop('disabled', true);
                api('password', data, 'POST').done(function () {
                    bootstrap.Modal.getOrCreateInstance('#info-modal').hide();
                    toast(data.remove ? 'Heslo zrušeno – přihlašuj se kódem' : (has ? 'Heslo změněno' : 'Heslo nastaveno'), 'success');
                }).fail(function (xhr) { $('#pw-error').text(errText(xhr)); $b.prop('disabled', false); });
            }
            $('#pw-form').on('submit', function (e) {
                e.preventDefault();
                var n = $('#pw-new').val(), n2 = $('#pw-new2').val();
                if (n.length < a.min) { $('#pw-error').text('Heslo musí mít aspoň ' + a.min + ' znaků.'); return; }
                if (n !== n2) { $('#pw-error').text('Hesla se neshodují.'); return; }
                send({ current: $('#pw-current').val(), new: n });
            });
            $('#pw-remove').on('click', function () {
                if (!$('#pw-current').val()) { $('#pw-error').text('Pro zrušení zadej současné heslo nebo kód.'); return; }
                if (!confirm('Zrušit heslo? Přihlásit se pak půjde jen jednorázovým kódem.')) { return; }
                send({ current: $('#pw-current').val(), remove: 1 });
            });
        }).fail(function (xhr) { $('#info-body').html('<div class="text-danger">' + esc(errText(xhr)) + '</div>'); });
    }

    // ---------- notifikace ----------
    function notify(m) {
        var c = S.convs[m.conv] || { name: m.sender || 'MeshCore' };
        if (!('Notification' in window) || Notification.permission !== 'granted') {
            if (!document.hidden) { toast(c.name + ': ' + m.body, 'primary'); }
            return;
        }
        var title = c.kind === 'channel' ? c.name + ' – ' + (m.sender || '') : c.name;
        var n = new Notification(title, { body: m.body, tag: m.conv });
        n.onclick = function () { window.focus(); openConv(m.conv); n.close(); };
    }

    // ---------- události UI ----------
    $('#tabs').on('click', '[data-tab]', function () {
        $('#tabs .nav-link').removeClass('active');
        $(this).addClass('active');
        S.tab = $(this).data('tab');
        savePref('tab', S.tab); // po obnovení stránky zůstane vybraná stejná karta
        if (S.tab === 'map') {
            NM.listMode = false; NM.disc = null; // čerstvý seznam slyšených uzlů
            if (S.current) { history.replaceState({}, '', location.pathname); closeConv(); }
        }
        renderList();
        if (S.tab === 'devices' && S.devices) { loadDevices(); }
    });
    $('#conv-list').on('click', '[data-dev]', function (e) {
        e.preventDefault();
        showDevice($(this).attr('data-dev'));
    });
    $('#btn-fav').on('click', function () { if (S.current) { toggleFav(S.current); } });
    $('#info-body').on('click', '.hop[data-hash]', function () { showHopCandidates($(this)); });
    $('#info-body').on('click', '.btn-trace', function () { runTrace($(this)); });
    $('#message-list').on('click', '.card-chip', function (e) {
        e.stopPropagation(); // neotevírat detail zprávy
        var $c = $(this);
        showCard($c.attr('data-pk'), +$c.attr('data-type'), $c.attr('data-name'));
    });
    $('#message-list').on('click', 'a.msg-link', function (e) { e.stopPropagation(); });
    $('#btn-conv-settings').on('click', function () { if (S.current) { showConvSettings(S.current); } });
    $('.btn-advert').on('click', function () {
        var flood = $(this).attr('data-flood') === '1';
        if (flood && !confirm('Odeslat flood advert? Rozešle se přes repeatery do celé sítě.')) { return; }
        var $all = $('.btn-advert').prop('disabled', true);
        bootstrap.Dropdown.getOrCreateInstance($('[data-bs-toggle="dropdown"]').first()[0]).hide();
        api('advert', { flood: flood }, 'POST').done(function () {
            toast(flood ? 'Flood advert odeslán' : 'Lokální advert odeslán', 'success');
        }).fail(function (xhr) {
            toast(errText(xhr), 'danger');
        }).always(function () {
            setTimeout(function () { $all.prop('disabled', false); }, 10000); // ochrana proti dvojkliku
        });
    });
    $('#conv-list').on('click', '#btn-add-channel', function () { showAddChannel(); });
    $('#conv-list').on('click', '#btn-add-contact', function () { showAddContact(); });
    $('#conv-list').on('click', '#btn-cleanup', function () { showCleanup(); });
    $('#btn-retention').on('click', function () {
        bootstrap.Dropdown.getOrCreateInstance($('[data-bs-toggle="dropdown"]').first()[0]).hide();
        showRetention();
    });
    $('#btn-appearance').on('click', function () {
        bootstrap.Dropdown.getOrCreateInstance($('[data-bs-toggle="dropdown"]').first()[0]).hide();
        showAppearance();
    });
    $('#btn-password').on('click', function () {
        bootstrap.Dropdown.getOrCreateInstance($('[data-bs-toggle="dropdown"]').first()[0]).hide();
        showPassword();
    });
    setInterval(function () { if (S.tab === 'devices' && !document.hidden) { loadDevices(); } }, 30000);
    $('#search').on('input', renderList);
    $('#conv-list').on('click', '.conv-act', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var conv = $(this).closest('[data-conv]').attr('data-conv');
        if ($(this).attr('data-act') === 'clear') { clearConversation(conv); } else { removeChannel(conv, $(this)); }
    });
    $('#conv-list').on('click', '[data-conv]', function (e) {
        e.preventDefault();
        openConv($(this).attr('data-conv'));
    });
    $('#btn-back').on('click', function () {
        if (history.state && history.state.pushed) {
            history.back();
        } else {
            history.replaceState({}, '', location.pathname);
            closeConv();
        }
    });
    $('#btn-info').on('click', function () { showContactInfo(S.convs[S.current]); });
    $('#btn-room').on('click', function () { showRoomLogin(S.convs[S.current]); });
    $('#btn-path').on('click', function () { if (S.current) { showPath(S.current); } });
    $('#info-body').on('click', '#info-path', function () { showPath(S.current); });
    $('#btn-status').on('click', showStatus);
    $('#btn-notify').on('click', function () {
        if (!('Notification' in window)) { toast('Prohlížeč notifikace nepodporuje (nebo stránka neběží přes HTTPS).', 'warning'); return; }
        Notification.requestPermission().then(function (p) {
            toast(p === 'granted' ? 'Notifikace povoleny' : 'Notifikace nejsou povoleny', p === 'granted' ? 'success' : 'warning');
        });
    });
    $('#opt-hide-dups').prop('checked', S.hideDups).on('change', function () {
        S.hideDups = this.checked;
        savePref('hideDups', S.hideDups);
        if (S.current) { openConv(S.current, true); }
    });
    $('#message-list').on('click', '.msg[data-id] .bubble', function () {
        var m = S.messages[$(this).parent().attr('data-id')];
        if (m) { showMessageInfo(m); }
    });
    $('#load-more').on('click', function () {
        var conv = S.current, el = document.getElementById('messages'), h = el.scrollHeight;
        api('messages', { conv: conv, before: S.oldestId, limit: 50, dups: S.hideDups ? 'hide' : 'show' }).done(function (r) {
            if (S.current !== conv) { return; }
            addMessages(r.messages, true);
            el.scrollTop = el.scrollHeight - h;
            $('#load-more-wrap').toggleClass('d-none', r.messages.length < 50);
        });
    });
    $('#msg-input').on('input', function () {
        updateCount();
        updateSuggest();
    }).on('keydown', function (e) {
        if (MS.open) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                MS.idx = (MS.idx + (e.key === 'ArrowDown' ? 1 : -1) + MS.items.length) % MS.items.length;
                $('#mention-suggest .list-group-item').removeClass('active').eq(MS.idx).addClass('active')[0].scrollIntoView({ block: 'nearest' });
                return;
            }
            if ((e.key === 'Enter' || e.key === 'Tab') && !e.isComposing) {
                e.preventDefault();
                pickSuggest(MS.idx);
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                hideSuggest();
                return;
            }
        }
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
    }).on('click', updateSuggest)
      .on('keyup', function (e) {
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home' || e.key === 'End') { updateSuggest(); }
    }).on('blur', function () {
        setTimeout(hideSuggest, 150);
    });

    $('#mention-suggest').on('mousedown', '.list-group-item', function (e) {
        e.preventDefault(); // nepřijít o fokus v políčku
        pickSuggest(+$(this).attr('data-i'));
    });

    $('#btn-mention').on('show.bs.dropdown', function () {
        var $m = $('#mention-menu').empty();
        var list = filterNicks('', 200);
        if (!list.length) {
            $m.append('<li><span class="dropdown-item-text text-body-secondary small">V kanálu zatím nikdo nepsal.</span></li>');
            return;
        }
        $m.append('<li><h6 class="dropdown-header">Odpovědět uživateli</h6></li>');
        list.forEach(function (n) {
            $('<li>').append(
                $('<button type="button" class="dropdown-item d-flex justify-content-between gap-3">')
                    .attr('data-nick', n.nick)
                    .append($('<span class="text-truncate">').text(n.nick))
                    .append($('<span class="mention-meta text-body-secondary text-nowrap">').text(fmtAgo(n.last)))
            ).appendTo($m);
        });
    });
    $('#mention-menu').on('click', '[data-nick]', function () {
        insertMention($(this).attr('data-nick'));
    });

    $('#message-list').on('click', '.bubble .route-reply', function (e) {
        e.stopPropagation(); // neotevírat detail zprávy
        var m = S.messages[$(this).closest('.msg').attr('data-id')];
        if (m) { routeReply(m); }
    });
    $('#message-list').on('click', '.bubble .sender', function (e) {
        e.stopPropagation(); // neotevírat detail zprávy
        insertMention($(this).attr('data-nick'));
    });
    $('#composer').on('submit', function (e) { e.preventDefault(); send(); });

    // ---------- sdílení vizitky a polohy ----------
    function insertText(t) {
        var el = $('#msg-input')[0], v = el.value, a = el.selectionStart != null ? el.selectionStart : v.length, b = el.selectionEnd != null ? el.selectionEnd : v.length;
        var pre = v.slice(0, a), post = v.slice(b);
        if (pre && !/\s$/.test(pre)) { t = ' ' + t; }
        if (post && !/^\s/.test(post)) { t = t + ' '; }
        el.value = pre + t + post;
        el.focus();
        el.selectionStart = el.selectionEnd = (pre + t).length;
        updateCount();
    }
    function posText(lat, lon) {
        var la = (+lat).toFixed(5), lo = (+lon).toFixed(5);
        return '📍 ' + la + ',' + lo + ' https://osm.org/?mlat=' + la + '&mlon=' + lo + '&zoom=15';
    }
    function selfInfo() {
        if (S.selfInfo) { return $.Deferred().resolve(S.selfInfo).promise(); }
        return api('self_info').then(function (r) { S.selfInfo = r.self; return r.self; });
    }
    $('#share-menu').on('click', '[data-share]', function () {
        var what = $(this).attr('data-share');
        if (what === 'pos-gps') {
            if (!navigator.geolocation) { toast('Prohlížeč neumí zjistit polohu.', 'warning'); return; }
            toast('Zjišťuji polohu…', 'secondary');
            navigator.geolocation.getCurrentPosition(function (p) {
                insertText(posText(p.coords.latitude, p.coords.longitude));
            }, function (e) {
                toast(e.code === 1 ? 'Přístup k poloze byl zamítnut.' : 'Polohu se nepodařilo zjistit.', 'warning');
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
            return;
        }
        selfInfo().done(function (si) {
            if (what === 'card') {
                insertText('<' + si.public_key + ':' + (si.type || 1) + ':' + String(si.name || '').replace(/[<>\n]/g, '').slice(0, 64) + '>');
            } else if (si.lat != null && si.lon != null) {
                insertText(posText(si.lat, si.lon));
            } else {
                toast('Companion nemá nastavenou polohu.', 'warning');
            }
        }).fail(function (xhr) { toast(errText(xhr), 'danger'); });
    });

    window.addEventListener('popstate', function (e) {
        var conv = (e.state && e.state.conv) || location.hash.slice(1);
        if (conv && S.convs[conv]) { openConv(conv, true); } else { closeConv(); }
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { markRead(); }
    });
    setInterval(function () { if (S.current) { renderHeader(); } }, 60000);

    // Šipka pro opětovné odeslání se objeví až po uplynutí čekací doby – průběžně kontrolujeme.
    var retryShown = 0;
    setInterval(function () {
        if (!S.current) { return; }
        var n = 0;
        $.each(S.messages, function (id, m) { if (showRetryButton(m)) { n++; } });
        if (n !== retryShown) {
            retryShown = n;
            renderMessages();
        }
    }, 5000);

    $('#message-list').on('click', '.retry-btn', function (e) {
        e.stopPropagation();
        var m = S.messages[$(this).closest('.msg').attr('data-id')];
        if (m) { resend(m, $(this)); }
    });

    // ---------- start ----------
    // rozhraní pro režim Bluetooth (assets/ble.js)
    window.MCUI = {
        toast: toast,
        bleSettings: function () { showBleSettings(); },
        reload: function () {
            api('conversations').done(function (r) { setConversations(r.conversations); });
            if (S.devices || S.tab === 'devices') { loadDevices(); }
            S.selfInfo = null;
            api('init').done(function (r) { S.home = r.home || null; S.selfName = r.self_name || null; });
        }
    };

    api('init').done(function (r) {
        S.cursor = r.cursor;
        S.maxLen = r.max_len;
        S.selfName = r.self_name || null;
        S.caps = r.caps || {};
        S.settings = r.settings || S.settings;
        S.patterns = r.patterns || [];
        $('#tabs .nav-link').removeClass('active').filter('[data-tab="' + S.tab + '"]').addClass('active');
        S.analyzerOn = !!r.analyzer;
        S.home = r.home || null;
        setState(r.state);
        setConversations(r.conversations);
        var hash = location.hash.slice(1);
        if (hash && S.convs[hash]) {
            history.replaceState({ conv: hash }, '', location.pathname + '#' + hash);
            openConv(hash, true);
        } else {
            history.replaceState({}, '', location.pathname);
        }
        poll();
    }).fail(function (xhr) {
        toast(errText(xhr), 'danger');
    });

})(jQuery);
