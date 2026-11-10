<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Datová vrstva pro webové API.
 */
final class Api
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $rows = $this->db->all('SELECT k, v, updated_at FROM worker_state');
        $s = [];
        foreach ($rows as $r) {
            $s[$r['k']] = $r['v'];
        }
        $beat = isset($s['worker_heartbeat']) ? strtotime($s['worker_heartbeat']) : 0;
        $err  = isset($s['last_send_error']) ? json_decode($s['last_send_error'], true) : null;
        $snap = isset($s['ha_snapshot']) ? json_decode($s['ha_snapshot'], true) : null;
        $mqtt = array_map(static fn($b) => $b['state'] === 'on', $snap['mqtt'] ?? []);
        $online = 0;
        foreach ($snap['devices'] ?? [] as $d) {
            $online += $d['state'] === 'on' ? 1 : 0;
        }
        return [
            'worker_alive'       => $beat > 0 && time() - $beat < 60,
            'worker_connected'   => ($s['worker_connected'] ?? '0') === '1',
            'worker_error'       => $s['worker_last_error'] ?? null,
            'companion_connected'=> isset($s['companion_connected']) ? $s['companion_connected'] === '1' : null,
            'last_event_at'      => $s['last_event_at'] ?? null,
            'last_send_error'    => $err,
            'sun'                => $snap['sun'] ?? null,
            'firmware'           => self::firmware($snap),
            'mqtt_total'         => count($mqtt),
            'mqtt_connected'     => count(array_filter($mqtt)),
            'devices_total'      => count($snap['devices'] ?? []),
            'devices_online'     => $online,
            // kredit zpráv z limitu provozu v HA (null = limit neplatí / neznámý)
            'traffic'            => TrafficBudget::estimate($this->db),
            'queued'             => (int) $this->db->value('SELECT COUNT(*) FROM send_queue'),
        ];
    }

    /**
     * Sledovaná zařízení, companiony a MQTT brokery ze snapshotu HA.
     * @return array<string,mixed>
     */
    /**
     * Verze firmwaru a model companionu ze snapshotu HA (zařízení companionu v registru HA).
     * @return array{ver: string, model: ?string}|null
     */
    private static function firmware(?array $snap): ?array
    {
        foreach ($snap['companions'] ?? [] as $c) {
            $ver = trim((string) ($c['fw'] ?? ''));
            if ($ver !== '' && strcasecmp($ver, 'unknown') !== 0 && $ver !== 'None') {
                $model = trim((string) ($c['model'] ?? ''));
                return ['ver' => $ver, 'model' => $model !== '' && $model !== 'None' ? $model : null];
            }
        }
        return null;
    }

    /**
     * Jméno vlastního companionu (u zpráv do kanálu ho firmware předřadí textu jako „jméno: “
     * a počítá do limitu 160 B). Z konfigurace (analyzer.node_name), jinak z názvu companionu v HA.
     */
    public function selfName(array $cfg): ?string
    {
        $n = trim((string) ($cfg['analyzer']['node_name'] ?? ''));
        if ($n !== '') {
            return $n;
        }
        $snap = json_decode((string) $this->db->value("SELECT v FROM worker_state WHERE k = 'ha_snapshot'"), true);
        foreach (is_array($snap) ? ($snap['companions'] ?? []) : [] as $c) {
            $name = preg_replace('/\s*Node Status$/i', '', (string) ($c['name'] ?? ''));
            $name = preg_replace('/^MeshCore\s+/i', '', (string) $name);
            $name = preg_replace('/\s*\([0-9a-f]{6}\)$/i', '', (string) $name);
            if (trim((string) $name) !== '') {
                return trim((string) $name);
            }
        }
        return null;
    }

    /** Limit textu zprávy v bajtech (MeshCore MAX_TEXT_LEN = 160 B; u kanálu včetně „jméno: “). */
    public static function byteLimit(string $conv, ?string $selfName): int
    {
        return str_starts_with($conv, 'c:') ? 160 - strlen(($selfName ?? '') . ': ') : 160;
    }

    /**
     * Poloha vlastního companionu (konec cesty na mapě): senzory latitude/longitude companionu z HA,
     * jinak pevná poloha z konfigurace app.home = [lat, lon].
     * @return array{lat: float, lon: float, source: string}|null
     */
    public function home(array $cfg): ?array
    {
        $snap = json_decode((string) $this->db->value("SELECT v FROM worker_state WHERE k = 'ha_snapshot'"), true);
        $lat = $lon = null;
        foreach (is_array($snap) ? ($snap['sensors'] ?? []) : [] as $s) {
            if (preg_match('/^sensor\.meshcore_[0-9a-f]{6}_(latitude|longitude)(_|$)/', (string) ($s['id'] ?? ''), $m) && is_numeric($s['state'] ?? null)) {
                $m[1] === 'latitude' ? $lat = (float) $s['state'] : $lon = (float) $s['state'];
            }
        }
        if ($lat !== null && $lon !== null && ($lat != 0.0 || $lon != 0.0) && abs($lat) <= 90 && abs($lon) <= 180) {
            return ['lat' => $lat, 'lon' => $lon, 'source' => 'companion'];
        }
        $h = $cfg['app']['home'] ?? null;
        if (is_array($h) && isset($h[0], $h[1]) && is_numeric($h[0]) && is_numeric($h[1])) {
            return ['lat' => (float) $h[0], 'lon' => (float) $h[1], 'source' => 'config'];
        }
        return null;
    }

    public function devices(): array
    {
        $row  = $this->db->one("SELECT v, updated_at FROM worker_state WHERE k = 'ha_snapshot'");
        $snap = $row ? json_decode((string) $row['v'], true) : null;
        if (!is_array($snap)) {
            return ['available' => false, 'devices' => [], 'companions' => [], 'mqtt' => []];
        }

        // senzory podle prefixu entity
        $byPrefix = [];
        foreach ($snap['sensors'] ?? [] as $s) {
            if (preg_match('/^sensor\.meshcore_([0-9a-f]{10})_(.+)$/', $s['id'], $m)) {
                $byPrefix[$m[1]][] = ['rest' => $m[2]] + $s;
            } elseif (preg_match('/^sensor\.meshcore_([0-9a-f]{6})_(.+)$/', $s['id'], $m)) {
                $byPrefix[$m[1]][] = ['rest' => $m[2]] + $s;
            }
        }
        $collect = static function (string $prefix, string $slug) use ($byPrefix): array {
            $known = []; $telemetry = []; $other = [];
            foreach ($byPrefix[$prefix] ?? [] as $s) {
                $key = $s['rest'];
                if ($slug !== '' && str_ends_with($key, '_' . $slug)) {
                    $key = substr($key, 0, -strlen($slug) - 1);
                } elseif (!in_array($key, self::DEVICE_KEYS, true)) {
                    // Senzory repeateru nesou v ID jméno z konfigurace repeateru v HA, které se může lišit
                    // od jména kontaktu (online senzor) – např. po přejmenování uzlu. Klíč pak hledáme podle začátku.
                    $best = '';
                    foreach (self::DEVICE_KEYS as $k) {
                        if (str_starts_with($key, $k . '_') && strlen($k) > strlen($best)) {
                            $best = $k;
                        }
                    }
                    if ($best !== '') {
                        $key = $best;
                    }
                }
                $item = ['id' => $s['id'], 'state' => $s['state'], 'unit' => $s['unit'], 'name' => $s['name'], 'updated' => $s['updated']];
                if (preg_match('/^ch\d+_/', $key) || preg_match('/_ch\d+$/', $key)) {
                    $telemetry[$key] = $item;
                } elseif (in_array($key, self::DEVICE_KEYS, true)) {
                    // při více entitách se stejným klíčem (staré + nové ID) vyhrává ta s platnou a novější hodnotou
                    $old = $known[$key] ?? null;
                    $valid = static fn(?array $x): bool => $x !== null && !in_array($x['state'], ['unavailable', 'unknown', null, ''], true);
                    if ($old === null || ($valid($item) && (!$valid($old) || (string) $item['updated'] > (string) $old['updated']))) {
                        $known[$key] = $item;
                    }
                } else {
                    $other[$key] = $item;
                }
            }
            ksort($telemetry);
            ksort($other);
            return ['sensors' => $known, 'telemetry' => $telemetry, 'other' => $other];
        };

        // kontakty pro propojení s konverzací
        $contacts = [];
        foreach ($this->db->all('SELECT pubkey_prefix, name, type, latitude, longitude, scopes FROM contacts') as $c) {
            $contacts[substr($c['pubkey_prefix'], 0, 10)] = $c;
        }

        $devices = [];
        foreach ($snap['devices'] ?? [] as $d) {
            if (!preg_match('/^binary_sensor\.meshcore_([0-9a-f]{10})_online_(.*)$/', $d['id'], $m)) {
                continue;
            }
            [$pk10, $slug] = [$m[1], $m[2]];
            $c = $contacts[$pk10] ?? null;
            $name = $c['name'] ?? preg_replace('/\s*Online$/i', '', (string) ($d['name'] ?? $pk10));
            $devices[] = [
                'pk'      => $pk10,
                'name'    => $name,
                'online'  => $d['state'] === 'on' ? true : ($d['state'] === 'off' ? false : null),
                'changed' => $d['changed'] ?? null,
                'conv'    => $c ? 'd:' . $c['pubkey_prefix'] : null,
                'type'    => $c && $c['type'] !== null ? (int) $c['type'] : null,
                'lat'     => $c && $c['latitude'] !== null ? (float) $c['latitude'] : null,
                'lon'     => $c && $c['longitude'] !== null ? (float) $c['longitude'] : null,
                'scopes'  => $c && $c['scopes'] ? json_decode($c['scopes'], true) : null,
            ] + $collect($pk10, $slug);
        }
        usort($devices, static fn($a, $b) => [$b['online'] === true, $a['name']] <=> [$a['online'] === true, $b['name']]);

        $companions = [];
        foreach ($snap['companions'] ?? [] as $c) {
            if (!preg_match('/^sensor\.meshcore_([0-9a-f]{6})_node_status_(.*)$/', $c['id'], $m)) {
                continue;
            }
            $companions[] = [
                'pk'     => $m[1],
                'name'   => preg_replace('/\s*Node Status$/i', '', (string) ($c['name'] ?? $m[2])),
                'status' => $c['state'],
            ] + $collect($m[1], $m[2]);
        }

        $mqtt = array_map(static fn($b) => [
            'n'         => $b['n'] ?? null,
            'server'    => $b['server'] ?? null,
            'connected' => $b['state'] === 'on',
            'state'     => $b['state'],
            'changed'   => $b['changed'] ?? null,
        ], $snap['mqtt'] ?? []);
        usort($mqtt, static fn($a, $b) => ($a['n'] ?? 0) <=> ($b['n'] ?? 0));

        return [
            'available'  => true,
            'updated_at' => self::iso($row['updated_at']),
            'devices'    => $devices,
            'companions' => $companions,
            'mqtt'       => $mqtt,
        ];
    }

    /**
     * Uzly, které companion slyšel (advert), ale nejsou v kontaktech – ze snapshotu HA.
     * @return array{available:bool,nodes:list<array<string,mixed>>}
     */
    public function discovered(): array
    {
        $snap = json_decode((string) $this->db->value("SELECT v FROM worker_state WHERE k = 'ha_snapshot'"), true);
        if (!is_array($snap) || !array_key_exists('discovered', $snap)) {
            return ['available' => false, 'nodes' => []];
        }
        $known = array_flip(array_column($this->db->all('SELECT pubkey_prefix FROM contacts'), 'pubkey_prefix'));
        $nodes = [];
        foreach ($snap['discovered'] as $d) {
            $pk = strtolower((string) ($d['pk'] ?? ''));
            if (!preg_match('/^[0-9a-f]{12}/', $pk) || isset($known[substr($pk, 0, 12)])) {
                continue;
            }
            $adv = $d['last_advert'] ?? null;
            $lat = isset($d['lat']) && is_numeric($d['lat']) ? (float) $d['lat'] : null;
            $lon = isset($d['lon']) && is_numeric($d['lon']) ? (float) $d['lon'] : null;
            $nodes[substr($pk, 0, 12)] = [
                'pk'          => $pk,
                'pk12'        => substr($pk, 0, 12),
                'name'        => (string) ($d['name'] ?? ''),
                'type'        => isset($d['type']) && is_numeric($d['type']) ? (int) $d['type'] : null,
                'last_advert' => is_numeric($adv) && $adv > 0 ? gmdate('c', (int) $adv) : (is_string($adv) && $adv !== '' ? $adv : null),
                'path_len'    => isset($d['path_len']) && is_numeric($d['path_len']) ? (int) $d['path_len'] : null,
                'lat'         => $lat && $lon ? $lat : null,
                'lon'         => $lat && $lon ? $lon : null,
            ];
        }
        $nodes = array_values($nodes);
        usort($nodes, static fn($a, $b) => strcmp((string) $b['last_advert'], (string) $a['last_advert']));
        return ['available' => true, 'nodes' => $nodes];
    }

    private const DEVICE_KEYS = [
        'battery_percentage', 'bat', 'battery_voltage', 'uptime', 'noise_floor', 'last_rssi', 'last_snr',
        'airtime_utilization', 'rx_airtime_utilization', 'out_path_len', 'out_path', 'request_successes',
        'request_failures', 'tx_queue_len', 'nb_sent', 'nb_recv', 'node_count', 'tx_power', 'frequency',
        'neighbor_count', 'recv_errors', 'full_evts',
    ];

    /** @return list<array<string,mixed>> */
    public function conversations(): array
    {
        $stats = [];
        $rows = $this->db->all(
            "SELECT m.conv_key, MAX(m.id) AS last_id,
                    SUM(m.direction = 'in' AND m.id > COALESCE(r.last_read_id, 0)) AS unread
             FROM messages m
             LEFT JOIN conv_reads r ON r.conv_key = m.conv_key
             WHERE m.dup_of IS NULL
             GROUP BY m.conv_key"
        );
        $lastIds = [];
        foreach ($rows as $r) {
            $stats[$r['conv_key']] = ['unread' => (int) $r['unread'], 'last_id' => (int) $r['last_id']];
            $lastIds[] = (int) $r['last_id'];
        }
        $last = [];
        if ($lastIds) {
            $in = implode(',', array_fill(0, count($lastIds), '?'));
            foreach ($this->db->all("SELECT * FROM messages WHERE id IN ($in)", $lastIds) as $m) {
                $last[$m['conv_key']] = $this->formatMessage($m);
            }
        }

        $out = [];
        foreach ($this->db->all('SELECT channel_idx, name FROM channels ORDER BY channel_idx') as $c) {
            $key = 'c:' . $c['channel_idx'];
            $out[$key] = [
                'conv'   => $key,
                'kind'   => 'channel',
                'name'   => $c['name'] ?: 'Kanál ' . $c['channel_idx'],
                'idx'    => (int) $c['channel_idx'],
            ];
        }
        foreach ($this->db->all("SELECT pubkey_prefix, name, type, latitude, longitude, last_advert, out_path_len, scopes,
                JSON_VALUE(raw, '$.out_path') AS out_path FROM contacts") as $c) {
            $key = 'd:' . $c['pubkey_prefix'];
            $out[$key] = [
                'conv'        => $key,
                'kind'        => 'contact',
                'name'        => $c['name'] ?: $c['pubkey_prefix'],
                'pk'          => $c['pubkey_prefix'],
                'type'        => $c['type'] !== null ? (int) $c['type'] : null,
                'lat'         => $c['latitude'] !== null ? (float) $c['latitude'] : null,
                'lon'         => $c['longitude'] !== null ? (float) $c['longitude'] : null,
                'last_advert' => self::iso($c['last_advert']),
                'path_len'    => $c['out_path_len'] !== null ? (int) $c['out_path_len'] : null,
                'path'        => is_string($c['out_path']) && preg_match('/^[0-9a-fA-F]*$/', $c['out_path']) ? strtolower($c['out_path']) : null,
                'scopes'      => $c['scopes'] ? json_decode($c['scopes'], true) : null,
            ];
        }
        // konverzace, ke kterým zatím chybí kontakt/kanál
        foreach (array_keys($stats) as $key) {
            if (!isset($out[$key])) {
                [$t, $id] = explode(':', $key, 2);
                $out[$key] = $t === 'c'
                    ? ['conv' => $key, 'kind' => 'channel', 'name' => "Kanál $id", 'idx' => (int) $id]
                    : ['conv' => $key, 'kind' => 'contact', 'name' => $last[$key]['sender'] ?? $id, 'pk' => $id, 'type' => null];
            }
        }
        foreach ($out as $key => &$c) {
            $c['unread']  = $stats[$key]['unread'] ?? 0;
            $c['last_id'] = $stats[$key]['last_id'] ?? 0;
            $c['last']    = $last[$key] ?? null;
        }
        unset($c);
        return array_values($out);
    }

    /** @return list<array<string,mixed>> */
    public function messages(string $conv, ?int $beforeId, int $limit = 50, bool $hideDups = true): array
    {
        $limit = max(1, min(200, $limit));
        $params = [$conv];
        $sql = 'SELECT * FROM messages WHERE conv_key = ?';
        if ($hideDups) {
            $sql .= ' AND dup_of IS NULL';
        }
        if ($beforeId) {
            $sql .= ' AND id < ?';
            $params[] = $beforeId;
        }
        $sql .= " ORDER BY id DESC LIMIT $limit";
        $rows = array_reverse($this->db->all($sql, $params));
        return $this->withGroups($rows);
    }

    /**
     * Zprávy nové nebo změněné od kurzoru.
     * @return array{messages:list<array<string,mixed>>,cursor:string}
     */
    public function changes(string $cursor): array
    {
        $rows = $this->db->all(
            'SELECT *, DATE_FORMAT(updated_at, "%Y-%m-%d %H:%i:%s.%f") AS upd FROM messages
             WHERE updated_at > ? AND updated_at <= UTC_TIMESTAMP(6) + INTERVAL 2 SECOND
             ORDER BY updated_at LIMIT 500',
            [$cursor]
        );
        $max = $cursor;
        $have = [];
        $origIds = [];
        foreach ($rows as $r) {
            if ($r['upd'] > $max) {
                $max = $r['upd'];
            }
            $have[(int) $r['id']] = true;
            if ($r['direction'] === 'out' && $r['dup_of'] !== null) {
                $origIds[(int) $r['dup_of']] = true; // změna opakovaného odeslání → přepočítat souhrn u původní zprávy
            }
        }
        $origIds = array_diff(array_keys($origIds), array_keys($have));
        if ($origIds) {
            $in = implode(',', array_map('intval', $origIds));
            $rows = array_merge($rows, $this->db->all("SELECT * FROM messages WHERE id IN ($in)"));
        }
        return ['messages' => $this->withGroups($rows), 'cursor' => $max];
    }

    public function currentCursor(): string
    {
        // nikdy ne v budoucnosti (ruční úprava v jiném časovém pásmu by jinak zablokovala aktualizace)
        return (string) ($this->db->value('SELECT DATE_FORMAT(LEAST(MAX(updated_at), UTC_TIMESTAMP(6)), "%Y-%m-%d %H:%i:%s.%f") FROM messages')
            ?? '1970-01-01 00:00:00.000000');
    }

    public function markRead(string $conv, int $lastId): void
    {
        $this->db->query(
            'INSERT INTO conv_reads (conv_key, last_read_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))',
            [$conv, $lastId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function search(string $q, int $limit = 100): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $rows = $this->db->all(
            "SELECT * FROM messages WHERE dup_of IS NULL AND (body LIKE ? OR sender_name LIKE ?) ORDER BY id DESC LIMIT " . (int) $limit,
            [$like, $like]
        );
        return array_map([$this, 'formatMessage'], $rows);
    }

    /**
     * Původní zpráva a všechny její duplicity (podle ID kterékoli z nich).
     * @return list<array<string,mixed>>
     */
    public function copies(int $id): array
    {
        $orig = (int) ($this->db->value('SELECT COALESCE(dup_of, id) FROM messages WHERE id = ?', [$id]) ?? 0);
        if (!$orig) {
            return [];
        }
        $rows = $this->db->all('SELECT * FROM messages WHERE id = ? OR dup_of = ? ORDER BY id', [$orig, $orig]);
        return array_map([$this, 'formatMessage'], $rows);
    }

    /**
     * Přezdívky odesílatelů v kanálu, seřazené podle poslední aktivity.
     * @return list<array{nick:string,last:?string,count:int}>
     */
    public function nicks(string $conv, int $limit = 200): array
    {
        $rows = $this->db->all(
            "SELECT sender_name COLLATE utf8mb4_bin AS nick, MAX(msg_ts) AS last, COUNT(*) AS cnt
             FROM messages
             WHERE conv_key = ? AND direction = 'in' AND dup_of IS NULL
               AND sender_name IS NOT NULL AND sender_name <> ''
             GROUP BY nick
             ORDER BY last DESC
             LIMIT " . max(1, min(500, $limit)),
            [$conv]
        );
        return array_map(static fn(array $r): array => [
            'nick'  => $r['nick'],
            'last'  => self::iso($r['last']),
            'count' => (int) $r['cnt'],
        ], $rows);
    }

    /** @return array<string,mixed>|null */
    public function message(int $id): ?array
    {
        $m = $this->db->one('SELECT * FROM messages WHERE id = ?', [$id]);
        return $m ? $this->withGroups([$m])[0] : null;
    }

    /** Seznam observerů, kteří zachytili odeslanou zprávu. */
    public function heardObservers(int $id): array
    {
        $v = $this->db->value('SELECT heard_observers FROM messages WHERE id = ?', [$id]);
        $list = $v ? json_decode((string) $v, true) : [];
        return is_array($list) ? $list : [];
    }

    /**
     * Naformátuje zprávy a k odchozím zprávám s opakovanými odesláními přidá souhrn skupiny:
     * nejlepší výsledek ze všech pokusů a poslední pokus (pro šipku „odeslat znovu“).
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withGroups(array $rows): array
    {
        $out = array_map([$this, 'formatMessage'], $rows);
        $ids = [];
        foreach ($rows as $r) {
            if ($r['direction'] === 'out' && (int) $r['dup_count'] > 0) {
                $ids[] = (int) $r['id'];
            }
        }
        if (!$ids) {
            return $out;
        }
        $in = implode(',', $ids);
        $agg = [];
        foreach ($this->db->all(
            "SELECT dup_of, COUNT(*) n, MAX(repeater_count) rep, MAX(COALESCE(heard_repeats, 0)) hr,
                    MAX(COALESCE(heard_count, 0)) hc, MAX(status = 'delivered') deliv, MAX(id) last_id
             FROM messages WHERE dup_of IN ($in) GROUP BY dup_of"
        ) as $a) {
            $agg[(int) $a['dup_of']] = $a;
        }
        $lastIds = array_map(static fn($a) => (int) $a['last_id'], $agg);
        $last = [];
        if ($lastIds) {
            foreach ($this->db->all('SELECT * FROM messages WHERE id IN (' . implode(',', $lastIds) . ')') as $l) {
                $last[(int) $l['id']] = $this->formatMessage($l);
            }
        }
        foreach ($out as &$m) {
            $a = $agg[$m['id']] ?? null;
            if ($a === null || $m['dir'] !== 'out') {
                continue;
            }
            $m['group'] = [
                'count'         => (int) $a['n'] + 1,
                'repeaters'     => max($m['repeaters'], (int) $a['rep']),
                'heard_repeats' => max((int) ($m['heard']['repeats'] ?? 0), (int) $a['hr']),
                'heard_count'   => max((int) ($m['heard']['count'] ?? 0), (int) $a['hc']),
                'delivered'     => $m['status'] === 'delivered' || (bool) $a['deliv'],
                'last'          => $last[(int) $a['last_id']] ?? null,
            ];
        }
        unset($m);
        return $out;
    }

    public static function validConv(string $conv): bool
    {
        return (bool) preg_match('/^(c:\d{1,3}|d:[0-9a-f]{12})$/', $conv);
    }

    /** @param array<string,mixed> $m */
    public function formatMessage(array $m): array
    {
        return [
            'id'        => (int) $m['id'],
            'conv'      => $m['conv_key'],
            'dir'       => $m['direction'],
            'type'      => $m['message_type'],
            'sender'    => $m['sender_name'],
            'author'    => $m['author_pk'] ?? null,
            'pk'        => $m['pubkey_prefix'],
            'body'      => $m['body'],
            'hops'      => $m['hop_count'] !== null ? (int) $m['hop_count'] : null,
            'snr'       => $m['snr'] !== null ? (float) $m['snr'] : null,
            'rssi'      => $m['rssi'] !== null ? (int) $m['rssi'] : null,
            'scope'     => $m['scope'] ?? null,
            'status'    => $m['status'],
            'repeaters' => (int) $m['repeater_count'],
            'ack'       => $m['ack_received'] !== null ? (bool) $m['ack_received'] : null,
            'tries'     => isset($m['dm_attempts']) && $m['dm_attempts'] !== null ? (int) $m['dm_attempts'] : null,
            'flood'     => isset($m['dm_flood']) && $m['dm_flood'] !== null ? (bool) $m['dm_flood'] : null,
            'trimmed'   => !empty($m['dm_trimmed']),
            'error'     => $m['error'],
            'dup_of'    => $m['dup_of'] !== null ? (int) $m['dup_of'] : null,
            'dups'      => (int) $m['dup_count'],
            'heard'     => isset($m['heard_state']) && $m['heard_state'] !== null ? [
                'state'     => $m['heard_state'],
                'count'     => $m['heard_count'] !== null ? (int) $m['heard_count'] : null,
                'total'     => $m['heard_total'] !== null ? (int) $m['heard_total'] : null,
                'repeats'   => $m['heard_repeats'] !== null ? (int) $m['heard_repeats'] : null,
                'hash'      => $m['heard_hash'],
                'checked'   => self::iso($m['heard_checked_at']),
                'attempts'  => (int) $m['heard_attempts'],
            ] : null,
            'rx'        => $m['rx_log'] ? json_decode($m['rx_log'], true) : null,
            'ts'        => self::iso($m['msg_ts']),
        ];
    }

    private static function iso(?string $utc): ?string
    {
        if (!$utc) {
            return null;
        }
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
