<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Zrcadlení dat z meshcore-serveru do databáze klienta (backend = 'server').
 *
 * Klient dál čte vše ze své DB (vyhledávání, deduplikace, heard přes analyzer, retence fungují beze změny).
 * Zprávy se párují přes messages.srv_id. Stav companionu, slyšené uzly apod. se ukládají do worker_state
 * ve stejném tvaru jako snapshot z Home Assistantu (ha_snapshot), takže je Api zobrazí bez úprav.
 *
 * Mapování konverzací: server dm:<prefix> / room:<prefix> / ch:<n>  <->  klient d:<prefix> / c:<n>
 * (room server je v klientu běžná konverzace s kontaktem typu 3; autor příspěvku je v author_pk).
 */
final class ServerSync
{
    public function __construct(
        private readonly Db $db,
        private readonly McServer $srv,
        private readonly MessageStore $store,
        private readonly string $reader = 'klient',
    ) {
    }

    // ------------------------------------------------------------------ úplná synchronizace

    /** Načte vše přes REST (po startu workeru nebo po resync.required). */
    public function fullSync(int $backfillDays, ?callable $log = null): void
    {
        $this->syncDevice();
        $n = $this->syncContacts();
        $log && $log("Kontakty synchronizovány: $n");
        $n = $this->syncChannels();
        $log && $log("Kanály synchronizovány: $n");
        $n = $this->backfill($backfillDays);
        $log && $log("Zprávy doplněny: $n");
        $this->syncUnread();
    }

    /** Stav companionu (verze, jméno, poloha, připojení) jako snapshot pro Api. */
    public function syncDevice(): void
    {
        $status = $this->srv->get('/status');
        try {
            $dev = $this->srv->get('/device');
        } catch (McServerException $e) {
            $dev = null; // companion se ještě nepřipojil
        }
        $this->setState('srv_status', json_encode($status, JSON_UNESCAPED_UNICODE));
        if ($dev) {
            $this->setState('srv_device', json_encode($dev, JSON_UNESCAPED_UNICODE));
        }
        $this->onConnection((string) ($status['companion']['state'] ?? ''));
        $this->writeSnapshot();
    }

    public function onConnection(string $state): void
    {
        $this->setState('companion_connected', $state === 'connected' ? '1' : '0');
        $this->setState('companion_connection_type', 'meshcore-server');
    }

    /**
     * Kontakty: tabulka contacts klienta = kontakty v companionu (on_device), ostatní uložené
     * na serveru jsou „slyšené uzly“ (snapshot.discovered).
     */
    public function syncContacts(): int
    {
        $items = $this->srv->get('/contacts')['items'] ?? [];
        $on = [];
        $off = [];
        foreach ($items as $c) {
            if (!empty($c['on_device'])) {
                $on[] = self::haContact($c);
            } else {
                $off[] = $c;
            }
        }
        $n = $this->store->syncContacts($on);
        // kontakty, které už v companionu nejsou, z tabulky klienta odebrat (zprávy zůstávají)
        $keep = array_map(static fn($c) => substr((string) $c['public_key'], 0, 12), $on);
        if ($keep) {
            $in = implode(',', array_fill(0, count($keep), '?'));
            $this->db->query("DELETE FROM contacts WHERE pubkey_prefix NOT IN ($in) AND public_key IS NOT NULL", $keep);
        }
        $this->setState('srv_discovered', json_encode(array_map(static fn($c) => [
            'pk'          => (string) $c['public_key'],
            'name'        => $c['name'] ?? '',
            'type'        => $c['type'] ?? null,
            'last_advert' => $c['last_advert_at'] ?? null,
            'path_len'    => $c['out_path_len'] ?? null,
            'lat'         => $c['lat'] ?? null,
            'lon'         => $c['lon'] ?? null,
        ], $off), JSON_UNESCAPED_UNICODE));
        $this->writeSnapshot();
        return $n;
    }

    /** Jeden kontakt z události contact.updated */
    public function onContact(array $c): void
    {
        if (empty($c['public_key'])) {
            return;
        }
        if (!empty($c['on_device'])) {
            $this->store->syncContacts([self::haContact($c)]);
        } else {
            $this->db->query('DELETE FROM contacts WHERE pubkey_prefix = ?', [substr((string) $c['public_key'], 0, 12)]);
        }
        // seznam slyšených uzlů se přepočítá při další plné synchronizaci kontaktů
        $this->setState('srv_contacts_dirty', '1');
    }

    public function onContactDeleted(array $d): void
    {
        $pk = substr(strtolower((string) ($d['public_key'] ?? '')), 0, 12);
        if (preg_match('/^[0-9a-f]{12}$/', $pk)) {
            $this->db->query('DELETE FROM contacts WHERE pubkey_prefix = ?', [$pk]);
        }
    }

    public function syncChannels(?array $items = null): int
    {
        $items ??= $this->srv->get('/channels')['items'] ?? [];
        $list = [];
        foreach ($items as $c) {
            $list[] = ['channel_idx' => (int) $c['idx'], 'channel_name' => (string) $c['name']];
            // region kanálu drží server – klient ho jen zobrazuje (conv_settings.scope)
            $this->setScope((int) $c['idx'], $c['region'] ?? null);
        }
        $n = $this->store->syncChannels(['channels' => $list]);
        $dev = $this->state('srv_device');
        $max = is_array($dev) ? (int) ($dev['max_channels'] ?? 0) : 0;
        if ($max > 0) {
            $this->setState('channel_slots', (string) $max);
        }
        if (!$items) {
            $this->db->query('DELETE FROM channels');
        }
        return $n;
    }

    private function setScope(int $idx, mixed $region): void
    {
        $r = is_string($region) && trim($region) !== '' ? ltrim(trim($region), '#') : null;
        $conv = "c:$idx";
        if ($r === null) {
            $this->db->query('UPDATE conv_settings SET scope = NULL WHERE conv_key = ?', [$conv]);
            return;
        }
        $this->db->query('INSERT INTO conv_settings (conv_key, scope) VALUES (?, ?) ON DUPLICATE KEY UPDATE scope = VALUES(scope)',
            [$conv, mb_substr($r, 0, 32)]);
    }

    /** Zprávy novější než poslední známá (při prvním běhu nejvýš $days dní zpět). */
    public function backfill(int $days): int
    {
        $after = (int) $this->db->value('SELECT COALESCE(MAX(srv_id), 0) FROM messages');
        $n = 0;
        if ($after > 0) {
            // doplnění po výpadku: od poslední známé dopředu
            while (true) {
                $items = $this->srv->get('/messages', ['after' => $after, 'limit' => 200])['items'] ?? [];
                if (!$items) {
                    break;
                }
                usort($items, static fn($a, $b) => $a['id'] <=> $b['id']);
                foreach ($items as $m) {
                    $this->upsertMessage($m);
                    $after = max($after, (int) $m['id']);
                    $n++;
                }
                if (count($items) < 200) {
                    break;
                }
            }
            return $n;
        }
        // první běh: od nejnovějších dozadu do limitu dní, pak uložit od nejstarších (pořadí id v klientu)
        $limitTs = $days > 0 ? time() - $days * 86400 : 0;
        $all = [];
        $before = null;
        do {
            $q = ['limit' => 200] + ($before ? ['before' => $before] : []);
            $r = $this->srv->get('/messages', $q);
            foreach ($r['items'] ?? [] as $m) {
                if ($limitTs && strtotime((string) $m['received_at']) < $limitTs) {
                    $r['next_before'] = null;
                    break;
                }
                $all[] = $m;
            }
            $before = $r['next_before'] ?? null;
        } while ($before && count($all) < 50000);
        foreach (array_reverse($all) as $m) {
            $this->upsertMessage($m);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ zprávy

    /**
     * Uloží nebo aktualizuje zprávu ze serveru. Vrací ID v klientu.
     * @param array<string,mixed> $m zpráva ve tvaru API serveru
     */
    public function upsertMessage(array $m): ?int
    {
        $srvId = (int) ($m['id'] ?? 0);
        if ($srvId <= 0) {
            return null;
        }
        $isCh = ($m['kind'] ?? '') === 'channel';
        $pk = $isCh ? null : self::pk($m['contact_prefix'] ?? $m['contact_key'] ?? null);
        $idx = $isCh && isset($m['channel_idx']) ? (int) $m['channel_idx'] : null;
        if ($isCh ? $idx === null : $pk === null) {
            return null;
        }
        $conv = $isCh ? "c:$idx" : "d:$pk";
        $out = ($m['direction'] ?? '') === 'out';

        $exists = $this->db->value('SELECT id FROM messages WHERE srv_id = ?', [$srvId]);
        if ($exists) {
            $this->applyStatus(['id' => $srvId] + $m);
            return (int) $exists;
        }

        $rx = self::rxLog($m);
        $sender = $m['sender_name'] ?? null;
        if (!$out && !$isCh && ($sender === null || $sender === '')) {
            $sender = $this->db->value('SELECT name FROM contacts WHERE pubkey_prefix = ?', [$pk]) ?: null;
        }
        [$status, $ack] = self::status($m);
        try {
            $this->insertMessage($m, $srvId, $conv, $out, $isCh, $pk, $idx, $sender, $rx, $status, $ack);
        } catch (\PDOException $e) {
            // souběh: stejnou zprávu mezitím uložil worker (WebSocket) nebo API – jen aktualizovat stav
            if ($e->getCode() === '23000' && ($id = $this->db->value('SELECT id FROM messages WHERE srv_id = ?', [$srvId]))) {
                $this->applyStatus(['id' => $srvId] + $m);
                return (int) $id;
            }
            throw $e;
        }
        $id = $this->db->lastId();
        if (!$isCh && !$out && !empty($m['sender_name']) && ($m['kind'] ?? '') !== 'room') {
            $this->db->query('INSERT INTO contacts (pubkey_prefix, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = COALESCE(contacts.name, VALUES(name))',
                [$pk, mb_substr((string) $m['sender_name'], 0, 100)]);
        }
        $this->store->linkDuplicate([
            'id' => $id, 'conv_key' => $conv, 'sender_name' => $sender, 'body' => (string) ($m['text'] ?? ''),
            'msg_ts' => self::ts($m['received_at'] ?? null), 'direction' => $out ? 'out' : 'in',
        ]);
        return $id;
    }

    private function insertMessage(array $m, int $srvId, string $conv, bool $out, bool $isCh, ?string $pk, ?int $idx,
                                   mixed $sender, ?array $rx, string $status, ?int $ack): void
    {
        $this->db->query(
            'INSERT INTO messages (srv_id, conv_key, direction, message_type, pubkey_prefix, channel_idx, sender_name, author_pk, body,
                                   hop_count, snr, rssi, status, repeater_count, ack_received, dm_attempts, dm_flood, error, rx_log, msg_ts)
             VALUES (:srv, :conv, :dir, :type, :pk, :idx, :sender, :author, :body, :hops, :snr, :rssi, :status, :rc, :ack, :att, :flood, :err, :rx, :ts)',
            [
                'srv'    => $srvId,
                'conv'   => $conv,
                'dir'    => $out ? 'out' : 'in',
                'type'   => $isCh ? 'channel' : 'direct',
                'pk'     => $pk,
                'idx'    => $idx,
                'sender' => is_string($sender) && $sender !== '' ? mb_substr($sender, 0, 100) : null,
                'author' => !$out && preg_match('/^[0-9a-f]{2,16}$/i', (string) ($m['author_prefix'] ?? '')) ? strtolower((string) $m['author_prefix']) : null,
                'body'   => (string) ($m['text'] ?? ''),
                'hops'   => !$out ? self::hops($m) : null,
                'snr'    => isset($m['snr']) ? (float) $m['snr'] : null,
                'rssi'   => isset($m['rssi']) ? (int) $m['rssi'] : null,
                'status' => $status,
                'rc'     => $out ? (int) ($m['heard_repeats'] ?? 0) : ($rx ? 1 : 0),
                'ack'    => $ack,
                'att'    => $out && !$isCh && !empty($m['attempts']) ? (int) $m['attempts'] : null,
                'flood'  => null,
                'err'    => isset($m['error']) && $m['error'] !== '' ? mb_substr((string) $m['error'], 0, 255) : null,
                'rx'     => $out ? self::heardLog($m['heard_by'] ?? null) : ($rx ? json_encode($rx, JSON_UNESCAPED_UNICODE) : null),
                'ts'     => self::ts($m['received_at'] ?? null),
            ]
        );
    }

    /** Událost message.status: stav odchozí zprávy, pokusy, slyšená opakování. */
    public function applyStatus(array $s): void
    {
        $srvId = (int) ($s['id'] ?? 0);
        if ($srvId <= 0 || !isset($s['status'])) {
            return;
        }
        [$status, $ack] = self::status($s);
        $heard = array_key_exists('heard_by', $s) ? self::heardLog($s['heard_by']) : null;
        $this->db->query(
            "UPDATE messages SET
                status = IF(status = 'delivered' AND :st1 <> 'delivered', status, :st2),
                ack_received = COALESCE(:ack, ack_received),
                dm_attempts = IF(message_type = 'direct' AND :att > 0, :att2, dm_attempts),
                error = IF(:st3 = 'failed', :err, error),
                repeater_count = GREATEST(repeater_count, :rc),
                rx_log = COALESCE(:rx, rx_log)
             WHERE srv_id = :srv",
            [
                'st1' => $status, 'st2' => $status, 'st3' => $status,
                'ack' => $ack,
                'att' => (int) ($s['attempts'] ?? 0), 'att2' => (int) ($s['attempts'] ?? 0),
                'err' => isset($s['error']) && $s['error'] !== '' ? mb_substr((string) $s['error'], 0, 255) : null,
                'rc'  => (int) ($s['heard_repeats'] ?? 0),
                'rx'  => $heard,
                'srv' => $srvId,
            ]
        );
    }

    // ------------------------------------------------------------------ nepřečtené

    /** Stav přečtení ze serveru (čtenář z konfigurace) -> conv_reads klienta. */
    public function syncUnread(): void
    {
        $r = $this->srv->get('/unread', ['reader' => $this->reader]);
        foreach ($r['items'] ?? [] as $it) {
            if (!empty($it['last_read_message_id'])) {
                $this->applyRead((string) $it['conversation'], (int) $it['last_read_message_id']);
            }
        }
    }

    /** Událost read.updated (jiné zařízení / klient téhož čtenáře označil konverzaci jako přečtenou). */
    public function onRead(array $d): void
    {
        if ((string) ($d['reader'] ?? '') !== $this->reader || empty($d['last_read_message_id'])) {
            return;
        }
        $this->applyRead((string) $d['conversation'], (int) $d['last_read_message_id']);
    }

    private function applyRead(string $srvConv, int $srvMsgId): void
    {
        $conv = self::clientConv($srvConv);
        if ($conv === null) {
            return;
        }
        // poslední zpráva klienta s srv_id <= značka (i když přesně ta u nás chybí)
        $id = (int) $this->db->value('SELECT MAX(id) FROM messages WHERE conv_key = ? AND srv_id IS NOT NULL AND srv_id <= ?', [$conv, $srvMsgId]);
        if ($id > 0) {
            $this->db->query('INSERT INTO conv_reads (conv_key, last_read_id) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE last_read_id = VALUES(last_read_id)', [$conv, $id]);
        }
    }

    /** Přečteno v klientu -> poslat na server (po poslední zprávu se srv_id). */
    public static function pushRead(McServer $srv, Db $db, string $reader, string $conv, int $lastId): void
    {
        $srvConv = self::serverConv($conv, $db);
        $srvId = (int) $db->value('SELECT MAX(srv_id) FROM messages WHERE conv_key = ? AND id <= ?', [$conv, $lastId]);
        if ($srvConv === null || $srvId <= 0) {
            return;
        }
        $srv->post('/read', ['conversation' => $srvConv, 'message_id' => $srvId, 'reader' => $reader]);
    }

    // ------------------------------------------------------------------ snapshot pro Api

    /** Zapíše worker_state.ha_snapshot ve tvaru, který zná Api (companion, slyšené uzly, poloha). */
    public function writeSnapshot(): void
    {
        $dev = $this->state('srv_device');
        $status = $this->state('srv_status');
        $disc = $this->state('srv_discovered');
        $pk6 = is_array($dev) ? substr((string) ($dev['public_key'] ?? ''), 0, 6) : '';
        $name = is_array($dev) ? (string) ($dev['name'] ?? '') : (string) ($status['companion']['name'] ?? '');
        $snap = [
            'source'     => 'meshcore-server',
            'devices'    => [],
            'mqtt'       => [],
            'sun'        => null,
            'sensors'    => [],
            'companions' => [],
            'discovered' => is_array($disc) ? $disc : [],
        ];
        if ($pk6 !== '') {
            $snap['companions'][] = [
                'id'    => "sensor.meshcore_{$pk6}_node_status_server",
                'state' => ($status['companion']['state'] ?? '') === 'connected' ? 'online' : 'offline',
                'name'  => $name,
                'fw'    => $dev['firmware_ver'] ?? null,
                'model' => $dev['model'] ?? null,
            ];
            $cfg = $dev['config'] ?? [];
            if (isset($cfg['adv_lat'], $cfg['adv_lon'])) {
                $snap['sensors'][] = ['id' => "sensor.meshcore_{$pk6}_latitude", 'state' => (string) $cfg['adv_lat'], 'unit' => '°', 'name' => 'Latitude', 'updated' => null];
                $snap['sensors'][] = ['id' => "sensor.meshcore_{$pk6}_longitude", 'state' => (string) $cfg['adv_lon'], 'unit' => '°', 'name' => 'Longitude', 'updated' => null];
            }
        }
        $this->setState('ha_snapshot', json_encode($snap, JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------------ pomocné

    /** Kontakt serveru -> tvar z HA, který zná MessageStore::syncContacts */
    private static function haContact(array $c): array
    {
        return [
            'public_key'   => (string) $c['public_key'],
            'adv_name'     => $c['name'] ?? null,
            'type'         => $c['type'] ?? null,
            'adv_lat'      => $c['lat'] ?? null,
            'adv_lon'      => $c['lon'] ?? null,
            'out_path_len' => $c['out_path_len'] ?? null,
            'out_path'     => $c['out_path'] ?? null,
            'last_advert'  => isset($c['last_advert_at']) ? strtotime((string) $c['last_advert_at']) : null,
            'favorite'     => !empty($c['favorite']),
        ];
    }

    /** @return array{0:string,1:?int} [status klienta, ack_received] */
    private static function status(array $m): array
    {
        return match ((string) ($m['status'] ?? 'received')) {
            'pending' => ['queued', null],
            'sent'    => ['sent', null],
            'acked'   => ['delivered', 1],
            'failed'  => ['failed', 0],
            default   => [($m['direction'] ?? 'in') === 'out' ? 'sent' : 'received', null],
        };
    }

    /** Trasa příchozí zprávy ve tvaru rx_log z HA (jedna zachycená cesta). */
    /**
     * Počet skoků příchozí zprávy. 255 (0xFF) = zpráva přišla směrovanou cestou (direct routing) –
     * počet skoků se v tom případě nepřenáší, takže ho neukazujeme (stejně jako HA a BLE režim).
     */
    private static function hops(array $m): ?int
    {
        if (!isset($m['path_len']) || !is_numeric($m['path_len'])) {
            return null;
        }
        $n = (int) $m['path_len'];
        return $n < 0 || $n >= 255 ? null : ($n & 0x3F);
    }

    private static function rxLog(array $m): ?array
    {
        if (self::hops($m) === null) {
            return null;
        }
        $len = (int) self::hops($m);
        $path = strtolower((string) ($m['path'] ?? ''));
        $size = $len > 0 && $path !== '' ? max(1, intdiv(strlen($path), 2 * $len)) : 1;
        return [[
            'path'           => $path,
            'path_len'       => $len,
            'path_hash_size' => $size,
            'snr'            => isset($m['snr']) ? (float) $m['snr'] : null,
            'rssi'           => isset($m['rssi']) ? (int) $m['rssi'] : null,
        ]];
    }

    /** Opakování odchozí zprávy, která slyšel companion (heard_by) -> rx_log (jeden záznam na repeater). */
    private static function heardLog(mixed $heardBy): ?string
    {
        if (!is_array($heardBy) || !$heardBy) {
            return null;
        }
        $rx = [];
        foreach ($heardBy as $h) {
            $hash = strtolower((string) ($h['hash'] ?? ''));
            if (preg_match('/^[0-9a-f]{2,6}$/', $hash)) {
                $rx[] = ['path' => $hash, 'path_len' => 1, 'path_hash_size' => intdiv(strlen($hash), 2), 'snr' => null, 'rssi' => null];
            }
        }
        return $rx ? json_encode($rx) : null;
    }

    public static function clientConv(string $srvConv): ?string
    {
        if (preg_match('/^ch:(\d+)$/', $srvConv, $m)) {
            return 'c:' . (int) $m[1];
        }
        if (preg_match('/^(?:dm|room):([0-9a-f]{12})/i', $srvConv, $m)) {
            return 'd:' . strtolower($m[1]);
        }
        return null;
    }

    /** Konverzace klienta -> server; u room serveru (kontakt typu 3) room:<prefix>. */
    public static function serverConv(string $conv, ?Db $db = null): ?string
    {
        if (preg_match('/^c:(\d+)$/', $conv, $m)) {
            return 'ch:' . (int) $m[1];
        }
        if (preg_match('/^d:([0-9a-f]{12})$/', $conv, $m)) {
            $room = $db !== null && (int) $db->value('SELECT type FROM contacts WHERE pubkey_prefix = ?', [$m[1]]) === 3;
            return ($room ? 'room:' : 'dm:') . $m[1];
        }
        return null;
    }

    private static function pk(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[0-9a-f]{12}/i', $v) ? strtolower(substr($v, 0, 12)) : null;
    }

    private static function ts(mixed $v): string
    {
        try {
            $d = is_string($v) && $v !== '' ? new \DateTimeImmutable($v) : new \DateTimeImmutable();
        } catch (\Exception) {
            $d = new \DateTimeImmutable();
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function state(string $k): mixed
    {
        $v = $this->db->value('SELECT v FROM worker_state WHERE k = ?', [$k]);
        return is_string($v) ? json_decode($v, true) : null;
    }

    private function setState(string $k, ?string $v): void
    {
        $this->store->setState($k, $v);
    }
}
