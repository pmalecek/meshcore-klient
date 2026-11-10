<?php
declare(strict_types=1);

namespace MeshClient;

use RuntimeException;

/**
 * Kontrola „heard“ přes CoreScope analyzer (např. analyzer.meshcore.cz).
 *
 * Odeslanou kanálovou zprávu dohledá v dešifrovaných zprávách kanálu (shoda textu a času,
 * volitelně jména uzlu), získá hash paketu a z /api/traces/{hash} zjistí, kteří observeři
 * paket zachytili. Vlastní observer (tvůj uzel) se do počtu nezapočítává.
 */
final class Analyzer
{
    private ?array $channelsCache = null;
    /** @var array<string,array> */
    private array $messagesCache = [];
    private ?string $ownPk = null;

    public function __construct(private readonly Db $db, private readonly array $cfg)
    {
    }

    public function enabled(): bool
    {
        return !empty($this->cfg['enabled']) && !empty($this->cfg['url']);
    }

    /**
     * Zpracuje zprávy, u kterých podle rozvrhu nastal čas kontroly.
     * @return array{checked:int,heard:int}
     */
    public function runDue(): array
    {
        $sched = array_values($this->cfg['schedule']);
        $max = count($sched);
        $horizon = end($sched) + 3600;
        $rows = $this->db->all(
            "SELECT id, channel_idx, body, msg_ts, heard_hash, heard_attempts FROM messages
             WHERE direction = 'out' AND message_type = 'channel' AND status <> 'failed' AND body <> ''
               AND heard_attempts < ? AND msg_ts > UTC_TIMESTAMP() - INTERVAL ? SECOND
             ORDER BY id",
            [$max, $horizon]
        );
        $now = time();
        $stats = ['checked' => 0, 'heard' => 0];
        foreach ($rows as $r) {
            $sent = self::utc($r['msg_ts']);
            $att = (int) $r['heard_attempts'];
            if ($now < $sent + $sched[$att]) {
                continue; // ještě není čas
            }
            $state = $this->checkRow($r, $att + 1 >= $max);
            $stats['checked']++;
            $stats['heard'] += $state === 'heard' ? 1 : 0;
        }
        return $stats;
    }

    /** Okamžitá kontrola jedné zprávy (tlačítko ve webu). */
    public function checkNow(int $id): ?string
    {
        // ruční kontrola: celou skupinu opakovaných odeslání spárovat znovu od začátku
        $root = (int) ($this->db->value('SELECT COALESCE(dup_of, id) FROM messages WHERE id = ?', [$id]) ?? 0);
        $rows = $this->db->all(
            "SELECT id, channel_idx, body, msg_ts, heard_hash, heard_attempts FROM messages
             WHERE (id = ? OR dup_of = ?) AND direction = 'out' AND message_type = 'channel' AND body <> '' ORDER BY id",
            [$root, $root]
        );
        if (!$rows) {
            return null;
        }
        $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $rows));
        $this->db->query("UPDATE messages SET heard_hash = NULL, heard_repeats = NULL, heard_count = NULL, heard_total = NULL, heard_observers = NULL WHERE id IN ($ids)");
        $state = null;
        foreach ($rows as $r) {
            $r['heard_hash'] = null;
            $st = $this->checkRow($r, true, false);
            if ((int) $r['id'] === $id) {
                $state = $st;
            }
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $r
     * @param bool $final  poslední pokus – pokud se paket nenajde, výsledek je „none“
     */
    private function checkRow(array $r, bool $final, bool $countAttempt = true): string
    {
        $id = (int) $r['id'];
        try {
            $name = $this->db->value('SELECT name FROM channels WHERE channel_idx = ?', [(int) $r['channel_idx']]);
            $channel = $name ? $this->analyzerChannel((string) $name) : null;
            if ($channel === null) {
                $this->save($id, 'unsupported', null, null, $countAttempt ? 99 : null);
                return 'unsupported';
            }

            $hash = $r['heard_hash'] ?: null;
            $sender = null;
            $repeats = 0;
            if ($hash === null) {
                [$hash, $sender, $repeats] = $this->findPacket((string) $channel, (string) $r['body'], self::utc($r['msg_ts']), $id);
            }
            if ($hash === null) {
                $state = $final ? 'none' : 'pending';
                $this->save($id, $state, null, $final ? ['count' => 0, 'total' => 0, 'repeats' => 0, 'observers' => []] : null,
                    $countAttempt ? (int) $r['heard_attempts'] + 1 : null);
                return $state;
            }

            $traces = $this->traces($hash, $sender);
            $traces['repeats'] = max($traces['repeats'], $repeats); // „heard“ jak ho ukazuje analyzer
            $this->save($id, 'heard', $hash, $traces, $countAttempt ? (int) $r['heard_attempts'] + 1 : null);
            return 'heard';
        } catch (\Throwable $e) {
            error_log('[meshcore-klient] analyzer: ' . $e->getMessage());
            $this->save($id, $final ? 'error' : 'pending', null, null, $countAttempt ? (int) $r['heard_attempts'] + 1 : null);
            return 'error';
        }
    }

    /**
     * Najde paket v dešifrovaných zprávách kanálu.
     * @return array{0:?string,1:?string,2:int} [hash, jméno odesílatele v analyzeru, počet zachycení]
     */
    private function findPacket(string $channel, string $body, int $sentAt, int $id, ?string $nodeName = null, ?array $used = null, ?array $siblings = null): array
    {
        $list = $this->channelMessages($channel);
        $body = trim($body);
        $nodeName = trim($nodeName ?? (string) ($this->cfg['node_name'] ?? ''));
        $window = (int) ($this->cfg['match_window'] ?? 120);

        // hashe už přiřazené jiným zprávám (opakované odeslání stejného textu)
        $used ??= array_column($this->db->all(
            "SELECT heard_hash FROM messages WHERE heard_hash IS NOT NULL AND id <> ? AND msg_ts > UTC_TIMESTAMP() - INTERVAL 2 DAY",
            [$id]
        ), 'heard_hash');
        $used = array_map('strtolower', array_map('strval', $used));

        // časy ostatních odeslání stejného textu (opakované odeslání) – paket analyzeru patří
        // tomu pokusu, jehož čas je mu nejblíž, ne prvnímu, který se zeptá
        if ($siblings === null) {
            $siblings = [];
            if ($id > 0) {
                foreach ($this->db->all(
                    "SELECT m.msg_ts FROM messages m JOIN messages me ON me.id = ? AND m.conv_key = me.conv_key
                     WHERE m.id <> me.id AND m.direction = 'out' AND m.body = me.body AND m.msg_ts > UTC_TIMESTAMP() - INTERVAL 2 DAY",
                    [$id]
                ) as $sr) {
                    $siblings[] = self::utc((string) $sr['msg_ts']);
                }
            }
        }
        $siblings = array_values(array_filter(array_map('intval', $siblings), static fn($t) => abs($t - $sentAt) <= 2 * $window));

        $best = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($list as $m) {
            if (!isset($m['packetHash'], $m['text']) || trim((string) $m['text']) !== $body) {
                continue;
            }
            if ($nodeName !== '' && ($m['sender'] ?? '') !== $nodeName) {
                continue;
            }
            if (in_array(strtolower((string) $m['packetHash']), $used, true)) {
                continue;
            }
            $ts = isset($m['sender_timestamp']) ? (int) $m['sender_timestamp']
                : (isset($m['first_seen']) ? (int) strtotime($m['first_seen']) : null);
            if ($ts === null) {
                continue;
            }
            $diff = abs($ts - $sentAt);
            foreach ($siblings as $sib) {
                if (abs($ts - $sib) < $diff) {
                    continue 2; // jiný pokus je tomuto paketu blíž
                }
            }
            if ($diff <= $window && $diff < $bestDiff) {
                $best = $m;
                $bestDiff = $diff;
            }
        }
        if ($best === null) {
            return [null, null, 0];
        }
        $hash = strtolower((string) $best['packetHash']);
        if ($id > 0 && !empty($best['scope_name'])) {
            $this->db->query('UPDATE messages SET scope = COALESCE(scope, ?) WHERE id = ?', [mb_substr((string) $best['scope_name'], 0, 64), $id]);
        }
        return [preg_match('/^[0-9a-f]{16}$/', $hash) ? $hash : null, $best['sender'] ?? null, (int) ($best['repeats'] ?? 0)];
    }

    /**
     * Kontrola zprávy odeslané z Bluetooth klienta (historie není v tabulce messages).
     * @param list<string> $exclude hashe už přiřazené jiným zprávám
     * @return array{heard: array<string,mixed>, observers: list<array>}
     */
    public function lookup(string $channelName, string $body, int $sentAt, string $sender, ?string $hash, array $exclude, bool $final, array $siblings = []): array
    {
        $empty = ['count' => 0, 'total' => 0, 'repeats' => 0, 'hash' => null];
        try {
            $channel = $this->analyzerChannel($channelName);
            if ($channel === null) {
                return ['heard' => ['state' => 'unsupported'] + $empty, 'observers' => []];
            }
            $repeats = 0;
            $name = null;
            if ($hash === null || !preg_match('/^[0-9a-f]{16}$/', $hash)) {
                $exclude = array_values(array_filter($exclude, static fn($h) => is_string($h) && preg_match('/^[0-9a-f]{16}$/', $h)));
                [$hash, $name, $repeats] = $this->findPacket($channel, $body, $sentAt, 0, $sender, $exclude,
                    array_slice(array_filter($siblings, 'is_numeric'), 0, 20));
            }
            if ($hash === null) {
                return ['heard' => ['state' => $final ? 'none' : 'pending'] + $empty, 'observers' => []];
            }
            $t = $this->traces($hash, $name ?? ($sender !== '' ? $sender : null));
            return [
                'heard' => ['state' => 'heard', 'count' => $t['count'], 'total' => $t['total'],
                    'repeats' => max($t['repeats'], $repeats), 'hash' => $hash],
                'observers' => $t['observers'],
            ];
        } catch (\Throwable $e) {
            error_log('[meshcore-klient] analyzer (ble): ' . $e->getMessage());
            return ['heard' => ['state' => $final ? 'error' : 'pending'] + $empty, 'observers' => []];
        }
    }

    /** @return array{count:int,total:int,repeats:int,observers:list<array>} */
    private function traces(string $hash, ?string $sender): array
    {
        $data = $this->get('/api/traces/' . rawurlencode($hash));
        $own = array_map('strval', (array) ($this->cfg['own_observers'] ?? []));
        if ($sender) {
            $own[] = $sender; // observer se obvykle jmenuje stejně jako uzel
        }
        $ownPk = $this->ownPk();

        $byObs = [];
        foreach ($data['traces'] ?? [] as $t) {
            $oid = strtolower((string) ($t['observer'] ?? ''));
            if ($oid === '') {
                continue;
            }
            $path = json_decode((string) ($t['path_json'] ?? '[]'), true);
            $entry = [
                'id'   => substr($oid, 0, 12),
                'name' => (string) ($t['observer_name'] ?? ''),
                'snr'  => isset($t['snr']) ? (float) $t['snr'] : null,
                'rssi' => isset($t['rssi']) ? (int) $t['rssi'] : null,
                'path' => is_array($path) ? array_values(array_map(static fn($h) => strtolower((string) $h), $path)) : [],
                'time' => $t['time'] ?? null,
            ];
            $entry['own'] = in_array($entry['name'], $own, true) || ($ownPk !== null && str_starts_with($oid, $ownPk));
            // stejný observer může paket slyšet víckrát – necháme nejlepší signál, nejdřívější čas
            if (!isset($byObs[$oid])) {
                $byObs[$oid] = $entry;
            } elseif (($entry['snr'] ?? -99) > ($byObs[$oid]['snr'] ?? -99)) {
                $entry['time'] = min((string) $entry['time'], (string) $byObs[$oid]['time']);
                $byObs[$oid] = $entry;
            }
        }
        $obs = array_values($byObs);
        usort($obs, static fn($a, $b) => strcmp((string) $a['time'], (string) $b['time']));
        $others = count(array_filter($obs, static fn($o) => !$o['own']));
        return [
            'count'     => $others,
            'total'     => count($obs),
            'repeats'   => count($data['traces'] ?? []),
            'observers' => array_slice($obs, 0, 200),
        ];
    }

    /**
     * Doplní region (scope) u příchozích zpráv v kanálech podle analyzeru (pole scope_name).
     * Kontroly 10 s, 1 min a 5 min po přijetí; zprávy jednoho kanálu se stahují nejvýš jednou za 20 s.
     * U příchozích zpráv se jako počítadlo pokusů používá sloupec heard_attempts (u nich se jinak nevyužívá).
     * @return int počet zpráv, kterým se region doplnil
     */
    public function enrichIncomingScopes(): int
    {
        $sched = [10, 60, 300];
        $rows = $this->db->all(
            "SELECT id, channel_idx, sender_name, body, msg_ts, heard_attempts, rx_log FROM messages
             WHERE direction = 'in' AND message_type = 'channel' AND scope IS NULL AND dup_of IS NULL
               AND heard_attempts < ? AND body <> '' AND msg_ts > UTC_TIMESTAMP() - INTERVAL 900 SECOND
             ORDER BY id",
            [count($sched)]
        );
        $now = time();
        $byChannel = [];
        foreach ($rows as $r) {
            // paket bez regionu (region_scope = false ve všech kopiích) – není co dohledávat
            $rx = $r['rx_log'] ? json_decode((string) $r['rx_log'], true) : null;
            if (is_array($rx) && $rx && !array_filter($rx, static fn($x) => !empty($x['region_scope']))) {
                $this->db->query('UPDATE messages SET heard_attempts = ? WHERE id = ?', [count($sched), (int) $r['id']]);
                continue;
            }
            if ($now >= self::utc($r['msg_ts']) + $sched[(int) $r['heard_attempts']]) {
                $byChannel[(int) $r['channel_idx']][] = $r;
            }
        }
        $found = 0;
        foreach ($byChannel as $idx => $list) {
            $name = $this->db->value('SELECT name FROM channels WHERE channel_idx = ?', [$idx]);
            $channel = $name ? $this->analyzerChannel((string) $name) : null;
            $ids = array_map(static fn($r) => (int) $r['id'], $list);
            if ($channel === null) {
                // soukromý kanál – analyzer ho nedešifruje, dál nezkoušet
                $this->db->query('UPDATE messages SET heard_attempts = ? WHERE id IN (' . implode(',', $ids) . ')', [count($sched)]);
                continue;
            }
            $key = 'scopefetch:' . mb_substr($channel, 0, 50);
            $last = $this->db->value('SELECT v FROM worker_state WHERE k = ?', [$key]);
            if ($last && $now - (int) $last < 20) {
                continue; // šetříme analyzer, zkusí se při dalším běhu
            }
            $this->db->query('INSERT INTO worker_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, (string) $now]);
            $msgs = $this->channelMessages($channel);

            foreach ($list as $r) {
                $match = $this->matchIncoming($msgs, $r);
                if ($match === null) {
                    $this->db->query('UPDATE messages SET heard_attempts = heard_attempts + 1 WHERE id = ?', [(int) $r['id']]);
                    continue;
                }
                $scope = trim((string) ($match['scope_name'] ?? ''));
                $this->db->query('UPDATE messages SET scope = ?, heard_attempts = ? WHERE id = ?',
                    [$scope !== '' ? mb_substr($scope, 0, 64) : null, count($sched), (int) $r['id']]);
                $found += $scope !== '' ? 1 : 0;
            }
        }
        return $found;
    }

    /** @param list<array<string,mixed>> $msgs */
    private function matchIncoming(array $msgs, array $r): ?array
    {
        $body = trim((string) $r['body']);
        $sender = trim((string) ($r['sender_name'] ?? ''));
        $ts = self::utc($r['msg_ts']);
        $best = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($msgs as $m) {
            if (trim((string) ($m['text'] ?? '')) !== $body) {
                continue;
            }
            if ($sender !== '' && trim((string) ($m['sender'] ?? '')) !== $sender) {
                continue;
            }
            $diffs = [];
            if (isset($m['sender_timestamp'])) {
                $diffs[] = abs((int) $m['sender_timestamp'] - $ts);
            }
            foreach (['timestamp', 'first_seen'] as $f) {
                if (!empty($m[$f]) && ($t = strtotime((string) $m[$f])) !== false) {
                    $diffs[] = abs($t - $ts);
                }
            }
            $diff = $diffs ? min($diffs) : PHP_INT_MAX;
            if ($diff <= 300 && $diff < $bestDiff) {
                $best = $m;
                $bestDiff = $diff;
            }
        }
        return $best;
    }

    /**
     * Stáhne z analyzeru regiony (scope) repeaterů a room serverů a uloží je ke kontaktům.
     * Běží nejvýš jednou za $everySec (stav v worker_state). Vrací počet uzlů s regionem, nebo null když nebylo potřeba.
     */
    public function syncScopes(int $everySec = 21600, bool $force = false): ?int
    {
        $last = $this->db->value("SELECT v FROM worker_state WHERE k = 'scopes_synced_at'");
        if (!$force && $last && time() - (int) strtotime((string) $last) < $everySec) {
            return null;
        }
        // nejdřív zapsat čas – při chybě se nebude zkoušet každých 5 s
        $this->db->query("INSERT INTO worker_state (k, v) VALUES ('scopes_synced_at', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [gmdate('c')]);

        $found = [];
        foreach (['repeater', 'room'] as $role) {
            $offset = 0;
            for ($page = 0; $page < 60; $page++) {
                $data = $this->get('/api/nodes?role=' . $role . '&limit=200&offset=' . $offset);
                $nodes = is_array($data['nodes'] ?? null) ? $data['nodes'] : [];
                foreach ($nodes as $n) {
                    $pk = strtolower((string) ($n['public_key'] ?? ''));
                    if (!preg_match('/^[0-9a-f]{12}/', $pk)) {
                        continue;
                    }
                    $sc = self::nodeScopes($n);
                    if ($sc) {
                        $found[substr($pk, 0, 12)] = $sc;
                    }
                }
                $offset += count($nodes);
                if (!$nodes || empty($data['has_more'])) {
                    break;
                }
            }
        }

        // uložit jen ke kontaktům, které známe; u ostatních regiony smazat (už nejsou nastavené)
        $this->db->query('UPDATE contacts SET scopes = NULL, scopes_at = UTC_TIMESTAMP() WHERE scopes IS NOT NULL');
        $n = 0;
        foreach ($found as $pk12 => $sc) {
            $n += $this->db->query('UPDATE contacts SET scopes = ?, scopes_at = UTC_TIMESTAMP() WHERE pubkey_prefix = ?',
                [json_encode($sc, JSON_UNESCAPED_UNICODE), $pk12])->rowCount();
        }
        return $n;
    }

    /** @return array<string,mixed>|null */
    private static function nodeScopes(array $n): ?array
    {
        $list = static function ($v): array {
            if (is_string($v)) {
                $v = preg_split('/\s*,\s*/', $v, -1, PREG_SPLIT_NO_EMPTY);
            }
            if (!is_array($v)) {
                return [];
            }
            $out = [];
            foreach ($v as $x) {
                $x = is_array($x) ? ($x['name'] ?? $x['scope'] ?? null) : $x;
                if (is_scalar($x) && trim((string) $x) !== '') {
                    $out[] = mb_substr(trim((string) $x), 0, 64);
                }
            }
            return array_values(array_unique($out));
        };
        $sc = array_filter([
            'default'     => isset($n['default_scope']) && $n['default_scope'] !== '' ? mb_substr((string) $n['default_scope'], 0, 64) : null,
            'configured'  => $list($n['configured_scope'] ?? null),
            'transported' => $list($n['transported_scopes'] ?? null),
            'declared'    => $list($n['declared_regions'] ?? null),
        ], static fn($v) => $v !== null && $v !== []);
        return $sc ?: null;
    }

    /** @param array{count:int,total:int,repeats:int,observers:array}|null $t */
    private function save(int $id, string $state, ?string $hash, ?array $t, ?int $attempts): void
    {
        $this->db->query(
            'UPDATE messages SET heard_state = ?, heard_hash = COALESCE(?, heard_hash),
                heard_count = COALESCE(?, heard_count), heard_total = COALESCE(?, heard_total),
                heard_repeats = GREATEST(COALESCE(heard_repeats, 0), COALESCE(?, 0)), heard_observers = COALESCE(?, heard_observers),
                heard_attempts = COALESCE(?, heard_attempts), heard_checked_at = UTC_TIMESTAMP()
             WHERE id = ?',
            [
                $state, $hash,
                $t['count'] ?? null, $t['total'] ?? null, $t['repeats'] ?? null,
                $t !== null ? json_encode($t['observers'], JSON_UNESCAPED_UNICODE) : null,
                $attempts, $id,
            ]
        );
    }

    /**
     * Najde kanál v analyzeru a vrátí jeho identifikátor pro /api/channels/{hash}/messages.
     * Porovnává bez ohledu na velikost písmen a úvodní '#'. Null = analyzer kanál nezná.
     */
    private function analyzerChannel(string $name): ?string
    {
        if ($this->channelsCache === null) {
            $data = $this->get('/api/channels');
            $list = isset($data['channels']) && is_array($data['channels']) ? $data['channels'] : $data;
            $this->channelsCache = [];
            foreach ($list as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $id = (string) ($c['hash'] ?? $c['name'] ?? '');
                if ($id !== '') {
                    $this->channelsCache[self::normChannel($id)] = $id;
                    if (isset($c['name'])) {
                        $this->channelsCache[self::normChannel((string) $c['name'])] = $id;
                    }
                }
            }
            if (!$this->channelsCache) {
                // prázdný seznam = problém na straně analyzeru, ne soukromý kanál
                $this->channelsCache = null;
                throw new RuntimeException('Analyzer nevrátil seznam kanálů');
            }
        }
        return $this->channelsCache[self::normChannel($name)] ?? null;
    }

    private static function normChannel(string $s): string
    {
        return mb_strtolower(ltrim(trim($s), '#'));
    }

    /** @return list<array<string,mixed>> */
    private function channelMessages(string $channel): array
    {
        if (!isset($this->messagesCache[$channel])) {
            $data = $this->get('/api/channels/' . rawurlencode($channel) . '/messages');
            $this->messagesCache[$channel] = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        }
        return $this->messagesCache[$channel];
    }

    /** Prefix klíče vlastního companionu (ze snapshotu HA) – jeho observer se nepočítá. */
    private function ownPk(): ?string
    {
        if ($this->ownPk === null) {
            $snap = json_decode((string) $this->db->value("SELECT v FROM worker_state WHERE k = 'ha_snapshot'"), true);
            $this->ownPk = '';
            foreach ($snap['companions'] ?? [] as $c) {
                if (preg_match('/^sensor\.meshcore_([0-9a-f]{6})_/', (string) ($c['id'] ?? ''), $m)) {
                    $this->ownPk = $m[1];
                    break;
                }
            }
        }
        return $this->ownPk !== '' ? $this->ownPk : null;
    }

    /** @return array<mixed> */
    private function get(string $path): array
    {
        $ch = curl_init(rtrim($this->cfg['url'], '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => (int) ($this->cfg['timeout'] ?? 15),
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'meshcore-klient (heard check)',
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $code >= 400) {
            throw new RuntimeException("Analyzer $path: " . ($resp === false ? $err : "HTTP $code"));
        }
        $data = json_decode((string) $resp, true);
        if (!is_array($data)) {
            throw new RuntimeException("Analyzer $path: neplatná odpověď");
        }
        return $data;
    }

    private static function utc(string $ts): int
    {
        return (new \DateTimeImmutable($ts, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
