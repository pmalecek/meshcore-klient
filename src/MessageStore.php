<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Převádí události z meshcore-ha na záznamy v databázi.
 */
final class MessageStore
{
    public function __construct(
        private readonly Db $db,
        private readonly int $dedupWindow = 1800,
    ) {
    }

    /**
     * Pokud je příchozí zpráva duplicitou dřívější zprávy (stejná konverzace, odesílatel a text
     * v rámci okna od poslední kopie), označí ji a zvýší počítadlo u původní zprávy.
     * Vrací ID původní zprávy, nebo null.
     *
     * U odchozích zpráv (direction = out) se seskupí opakovaná odeslání stejného textu.
     *
     * @param array{id:int|string,conv_key:string,sender_name:?string,body:string,msg_ts:string,direction?:string} $m
     */
    public function linkDuplicate(array $m): ?int
    {
        if ($this->dedupWindow <= 0) {
            return null;
        }
        $body = trim((string) $m['body']);
        if ($body === '') {
            return null;
        }
        $out = ($m['direction'] ?? 'in') === 'out';
        // Porovnání binárně – výchozí kolace ignoruje diakritiku a velikost písmen.
        // U odchozích se jméno odesílatele neporovnává (jsme to vždy my), nepočítají se neodeslané.
        $prev = $this->db->one(
            "SELECT id, dup_of FROM messages
             WHERE conv_key = :conv AND direction = :dir AND id < :id
               AND TRIM(body) COLLATE utf8mb4_bin = :body
               AND (:out = 1 OR sender_name COLLATE utf8mb4_bin = :sender OR (sender_name IS NULL AND :sender2 IS NULL))
               AND (:out2 = 0 OR status <> 'failed')
               AND msg_ts >= :ts - INTERVAL :win SECOND
             ORDER BY id DESC LIMIT 1",
            [
                'conv'    => $m['conv_key'],
                'dir'     => $out ? 'out' : 'in',
                'id'      => (int) $m['id'],
                'body'    => $body,
                'out'     => $out ? 1 : 0,
                'out2'    => $out ? 1 : 0,
                'sender'  => $m['sender_name'],
                'sender2' => $m['sender_name'],
                'ts'      => $m['msg_ts'],
                'win'     => $this->dedupWindow,
            ]
        );
        if ($prev === null && $out) {
            // výslovné „odeslat znovu“ z webu – připojit k původní zprávě bez ohledu na stáří
            $key = self::resendKey($m['conv_key'], $body);
            $v = $this->db->value('SELECT v FROM worker_state WHERE k = ? AND updated_at > UTC_TIMESTAMP() - INTERVAL 5 MINUTE', [$key]);
            if ($v) {
                $this->db->query('DELETE FROM worker_state WHERE k = ?', [$key]);
                $prev = $this->db->one("SELECT id, dup_of FROM messages WHERE id = ? AND conv_key = ? AND direction = 'out' AND id < ?",
                    [(int) $v, $m['conv_key'], (int) $m['id']]);
            }
        }
        if ($prev === null) {
            return null;
        }
        $original = (int) ($prev['dup_of'] ?? $prev['id']);
        // jen pokud zpráva ještě není přiřazená (opakovaná událost nesmí počítadlo zvýšit znovu)
        $n = $this->db->query('UPDATE messages SET dup_of = ? WHERE id = ? AND dup_of IS NULL AND id <> ?',
            [$original, (int) $m['id'], $original])->rowCount();
        if ($n) {
            $this->db->query('UPDATE messages SET dup_count = dup_count + 1 WHERE id = ?', [$original]);
        }
        return $n ? $original : null;
    }

    /** Klíč ve worker_state pro výslovné opakované odeslání (web → worker) */
    public static function resendKey(string $conv, string $body): string
    {
        return 'resend:' . md5($conv . "\n" . trim($body));
    }

    /** @param array<string,mixed> $e */
    public function handleEvent(string $type, array $e): void
    {
        switch ($type) {
            case 'meshcore_message':
                $this->onMessage($e);
                break;
            case 'meshcore_delivery_update':
                $this->onDeliveryUpdate($e);
                break;
            case 'meshcore_message_send_failed':
                $this->onSendFailed($e);
                break;
            case 'meshcore_connected':
                $this->setState('companion_connected', '1');
                $this->setState('companion_connection_type', (string) ($e['connection_type'] ?? ''));
                break;
            case 'meshcore_disconnected':
                $this->setState('companion_connected', '0');
                break;
            case 'meshcore_raw_event':
                $this->onRaw($e);
                break;
        }
    }

    /** @var list<array{pk:string,text:string,author:string,t:int}> příspěvky z room serveru čekající na meshcore_message */
    private array $roomPending = [];

    /**
     * Surová událost SDK: zajímají nás jen příspěvky z room serveru (txt_type 2 = podepsaný text),
     * které nesou autora (prvních 4 B jeho klíče). meshcore_message ho neobsahuje.
     * @param array<string,mixed> $e
     */
    private function onRaw(array $e): void
    {
        $t = strtolower((string) ($e['event_type'] ?? ''));
        // potvrzení doručení přímé zprávy (odesílá DmSender)
        if (str_ends_with($t, '.ack') || $t === 'ack' || str_contains($t, 'acknowledgement')) {
            $code = is_array($e['payload'] ?? null) ? (string) ($e['payload']['code'] ?? '') : '';
            if ($code !== '') {
                (new DmSender($this->db))->onAck($code);
            }
            return;
        }
        if (!str_contains($t, 'contact_msg') && !str_contains($t, 'contact_message')) {
            return;
        }
        $p = is_array($e['payload'] ?? null) ? $e['payload'] : [];
        if ((int) ($p['txt_type'] ?? 0) !== 2 || !preg_match('/^[0-9a-f]{8}$/i', (string) ($p['signature'] ?? ''))) {
            return;
        }
        $pk = self::pk($p['pubkey_prefix'] ?? null);
        if ($pk === null) {
            return;
        }
        $item = ['pk' => $pk, 'text' => (string) ($p['text'] ?? ''), 'author' => strtolower((string) $p['signature']), 't' => time()];
        if (!$this->applyRoomAuthor($item)) {
            $this->roomPending[] = $item;
        }
        $this->roomPending = array_values(array_filter($this->roomPending, static fn($x) => $x['t'] > time() - 300));
    }

    /** Doplní autora k již uložené zprávě (vrací false, pokud ještě není v DB). */
    private function applyRoomAuthor(array $item): bool
    {
        $id = $this->db->value(
            "SELECT id FROM messages WHERE conv_key = ? AND direction = 'in' AND body = ? AND author_pk IS NULL
               AND msg_ts > UTC_TIMESTAMP() - INTERVAL 10 MINUTE ORDER BY id DESC LIMIT 1",
            ['d:' . $item['pk'], $item['text']]
        );
        if (!$id) {
            return false;
        }
        $name = $this->db->value('SELECT name FROM contacts WHERE pubkey_prefix LIKE ? LIMIT 1', [$item['author'] . '%']);
        $this->db->query('UPDATE messages SET author_pk = ?, sender_name = ? WHERE id = ?',
            [$item['author'], $name ?: $item['author'], $id]);
        return true;
    }

    /** @param array<string,mixed> $e */
    private function onMessage(array $e): void
    {
        $outgoing = !empty($e['outgoing']);
        $type     = ($e['message_type'] ?? '') === 'channel' ? 'channel' : 'direct';
        $pk       = self::pk($e['pubkey_prefix'] ?? null);
        $chIdx    = isset($e['channel_idx']) ? (int) $e['channel_idx'] : null;

        if ($type === 'direct' && $pk === null) {
            return; // bez odesílatele/příjemce nelze zařadit do konverzace
        }
        if ($type === 'channel' && $chIdx === null) {
            return;
        }
        $conv = $type === 'channel' ? "c:$chIdx" : "d:$pk";

        if ($type === 'channel' && isset($e['channel']) && $e['channel'] !== '') {
            $this->upsertChannel($chIdx, (string) $e['channel']);
        }
        if ($type === 'direct' && !$outgoing && !empty($e['sender_name'])) {
            $this->db->query(
                'INSERT INTO contacts (pubkey_prefix, name) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE name = COALESCE(contacts.name, VALUES(name))',
                [$pk, $e['sender_name']]
            );
        }

        $rxLog = is_array($e['rx_log_data'] ?? null) && $e['rx_log_data'] ? $e['rx_log_data'] : null;
        [$bestSnr, $bestRssi] = self::bestSignal($rxLog);
        $snr  = isset($e['snr']) ? (float) $e['snr'] : $bestSnr;
        $scope = self::scopeOf($rxLog) ?? self::str($e['scope'] ?? null, 64);

        if ($outgoing) {
            $status = 'sent';
            if ($type === 'direct' && !empty($e['ack_received'])) {
                $status = 'delivered';
            }
        } else {
            $status = 'received';
        }

        $params = [
            'entry_id'       => $e['entry_id'] ?? null,
            'entity_id'      => $e['entity_id'] ?? null,
            'conv_key'       => $conv,
            'direction'      => $outgoing ? 'out' : 'in',
            'message_type'   => $type,
            'pubkey_prefix'  => $pk,
            'channel_idx'    => $chIdx,
            'sender_name'    => self::str($e['sender_name'] ?? null, 100),
            'body'           => (string) ($e['message'] ?? ''),
            'hop_count'      => isset($e['hop_count']) ? (int) $e['hop_count'] : null,
            'snr'            => $snr,
            'rssi'           => $bestRssi,
            'scope'          => $scope,
            'send_id'        => self::str($e['send_id'] ?? null, 8),
            'status'         => $status,
            'repeater_count' => (int) ($e['repeater_count'] ?? ($rxLog ? count($rxLog) : 0)),
            'ack_received'   => isset($e['ack_received']) ? (int) (bool) $e['ack_received'] : null,
            'rx_log'         => $rxLog ? json_encode($rxLog, JSON_UNESCAPED_UNICODE) : null,
            'msg_ts'         => self::ts($e['timestamp'] ?? null),
        ];

        // zpráva do kanálu, která čekala ve frontě na volný provoz -> nahradit zástupný řádek
        if ($outgoing && $type === 'channel') {
            $ph = (new SendQueue($this->db))->placeholderFor($conv, $params['body']);
            if ($ph !== null) {
                $this->db->query(
                    "UPDATE messages SET entry_id = :entry_id, entity_id = :entity_id, send_id = :send_id, status = 'sent',
                        repeater_count = :repeater_count, rx_log = :rx_log, scope = :scope, sender_name = :sender_name, msg_ts = :msg_ts
                     WHERE id = :id",
                    ['entry_id' => $params['entry_id'], 'entity_id' => $params['entity_id'], 'send_id' => $params['send_id'],
                     'repeater_count' => $params['repeater_count'], 'rx_log' => $params['rx_log'], 'scope' => $params['scope'],
                     'sender_name' => $params['sender_name'], 'msg_ts' => $params['msg_ts'], 'id' => $ph]
                );
                return;
            }
        }

        $cols = implode(', ', array_keys($params));
        $vals = ':' . implode(', :', array_keys($params));
        // Odchozí zprávy jsou unikátní podle send_id – opakovaná událost jen aktualizuje stav.
        $ins = $this->db->query(
            "INSERT INTO messages ($cols) VALUES ($vals)
             ON DUPLICATE KEY UPDATE
                status = IF(messages.status = 'delivered', messages.status, VALUES(status)),
                scope = COALESCE(messages.scope, VALUES(scope)),
                ack_received = COALESCE(VALUES(ack_received), messages.ack_received)",
            $params
        );

        // rowCount 1 = nový řádek (2 = aktualizace existující odchozí zprávy podle send_id)
        if ($ins->rowCount() === 1 && !$outgoing && $type === 'direct' && $this->roomPending) {
            foreach ($this->roomPending as $i => $item) {
                if ($item['pk'] === $pk && $item['text'] === $params['body'] && $this->applyRoomAuthor($item)) {
                    unset($this->roomPending[$i]);
                    $this->roomPending = array_values($this->roomPending);
                    break;
                }
            }
        }
        if ($ins->rowCount() === 1) {
            $this->linkDuplicate([
                'id'          => $this->db->lastId(),
                'conv_key'    => $conv,
                'sender_name' => $params['sender_name'],
                'body'        => $params['body'],
                'msg_ts'      => $params['msg_ts'],
                'direction'   => $outgoing ? 'out' : 'in',
            ]);
        }
    }

    /** @param array<string,mixed> $e */
    private function onDeliveryUpdate(array $e): void
    {
        $rxLog = is_array($e['rx_log_data'] ?? null) ? $e['rx_log_data'] : [];
        [$bestSnr, $bestRssi] = self::bestSignal($rxLog ?: null);
        $repeaters = (int) ($e['repeater_count'] ?? count($rxLog));
        $rxJson = $rxLog ? json_encode($rxLog, JSON_UNESCAPED_UNICODE) : null;

        if (!empty($e['send_id'])) {
            // odchozí zpráva
            $ack = isset($e['ack_received']) ? (bool) $e['ack_received'] : null;
            $this->db->query(
                "UPDATE messages SET
                    repeater_count = GREATEST(repeater_count, :rc),
                    rx_log = COALESCE(:rx, rx_log),
                    scope = COALESCE(scope, :scope),
                    ack_received = COALESCE(:ack, ack_received),
                    status = IF(:ack2 = 1, 'delivered', status)
                 WHERE send_id = :sid",
                [
                    'rc'   => $repeaters,
                    'rx'   => $rxJson,
                    'scope'=> self::scopeOf($rxLog ?: null),
                    'ack'  => $ack === null ? null : (int) $ack,
                    'ack2' => $ack ? 1 : 0,
                    'sid'  => $e['send_id'],
                ]
            );
            return;
        }

        // příchozí kanálová zpráva (adaptivní režim) – dohledáme podle entity, času a textu
        if (empty($e['entity_id']) || $rxJson === null) {
            return;
        }
        $this->db->query(
            "UPDATE messages SET
                repeater_count = GREATEST(repeater_count, :rc),
                rx_log = :rx,
                snr = COALESCE(snr, :snr),
                rssi = COALESCE(rssi, :rssi),
                scope = COALESCE(scope, :scope)
             WHERE entity_id = :ent AND msg_ts = :ts AND direction = 'in'
               AND body = :body
             ORDER BY id DESC LIMIT 1",
            [
                'rc'   => $repeaters,
                'rx'   => $rxJson,
                'snr'  => $bestSnr,
                'rssi' => $bestRssi,
                'scope'=> self::scopeOf($rxLog),
                'ent'  => $e['entity_id'],
                'ts'   => self::ts($e['timestamp'] ?? null),
                'body' => (string) ($e['message'] ?? ''),
            ]
        );
    }

    /** @param array<string,mixed> $e */
    private function onSendFailed(array $e): void
    {
        $reason = (string) ($e['reason'] ?? 'unknown');
        $detail = isset($e['detail']) ? ': ' . $e['detail'] : '';
        $this->setState('last_send_error', json_encode([
            'reason' => $reason,
            'detail' => $e['detail'] ?? null,
            'type'   => $e['message_type'] ?? null,
            'target' => $e['target'] ?? ($e['channel_idx'] ?? null),
            'at'     => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE));

        // Limit provozu řeší fronta (SendQueue / DmSender) – zpráva se odešle sama později.
        if ($reason === 'traffic_policy') {
            return;
        }

        // Zaznamenáme i do konverzace, aby uživatel chybu viděl.
        $conv = null;
        $pk = null;
        $ch = null;
        if (($e['message_type'] ?? '') === 'channel' && isset($e['channel_idx'])) {
            $ch = (int) $e['channel_idx'];
            $conv = "c:$ch";
        } elseif (isset($e['target']) && preg_match("/'([0-9a-f]{6,64})'/i", (string) $e['target'], $m)) {
            $prefix = strtolower($m[1]);
            // přiřadíme jen při jednoznačné shodě prefixu
            $matches = $this->db->all(
                'SELECT pubkey_prefix FROM contacts WHERE pubkey_prefix LIKE ? LIMIT 2',
                [substr($prefix, 0, 12) . '%']
            );
            if (count($matches) === 1) {
                $pk = $matches[0]['pubkey_prefix'];
                $conv = "d:$pk";
            }
        }
        if ($conv === null) {
            return;
        }
        $this->db->query(
            "INSERT INTO messages (entry_id, conv_key, direction, message_type, pubkey_prefix, channel_idx,
                                   body, status, error, msg_ts)
             VALUES (?, ?, 'out', ?, ?, ?, '', 'failed', ?, ?)",
            [
                $e['entry_id'] ?? null, $conv,
                ($e['message_type'] ?? '') === 'channel' ? 'channel' : 'direct',
                $pk, $ch, mb_substr($reason . $detail, 0, 255), self::ts($e['timestamp'] ?? null),
            ]
        );
    }

    /** @param array<mixed> $response */
    public function syncContacts(array $response): int
    {
        $list = $response['contacts'] ?? $response;
        if (!is_array($list)) {
            return 0;
        }
        $n = 0;
        foreach ($list as $key => $c) {
            if (!is_array($c)) {
                continue;
            }
            $pub = $c['public_key'] ?? (is_string($key) && ctype_xdigit($key) ? $key : null);
            $pk  = self::pk($c['pubkey_prefix'] ?? ($pub ? substr($pub, 0, 12) : null));
            if ($pk === null) {
                continue;
            }
            $lat = $c['latitude'] ?? $c['adv_lat'] ?? null;
            $lon = $c['longitude'] ?? $c['adv_lon'] ?? null;
            if ((float) $lat === 0.0 && (float) $lon === 0.0) {
                $lat = $lon = null;
            }
            $adv = $c['last_advert'] ?? null;
            $this->db->query(
                'INSERT INTO contacts (pubkey_prefix, public_key, name, type, latitude, longitude, out_path_len, last_advert, raw)
                 VALUES (:pk, :pub, :name, :type, :lat, :lon, :opl, :adv, :raw)
                 ON DUPLICATE KEY UPDATE public_key = COALESCE(VALUES(public_key), public_key),
                    name = COALESCE(VALUES(name), name), type = VALUES(type),
                    latitude = VALUES(latitude), longitude = VALUES(longitude),
                    out_path_len = VALUES(out_path_len), last_advert = VALUES(last_advert), raw = VALUES(raw)',
                [
                    'pk'   => $pk,
                    'pub'  => is_string($pub) && strlen($pub) === 64 ? strtolower($pub) : null,
                    'name' => self::str($c['adv_name'] ?? $c['name'] ?? null, 100),
                    'type' => isset($c['type']) ? (int) $c['type'] : null,
                    'lat'  => $lat !== null ? round((float) $lat, 6) : null,
                    'lon'  => $lon !== null ? round((float) $lon, 6) : null,
                    'opl'  => isset($c['out_path_len']) ? (int) $c['out_path_len'] : null,
                    'adv'  => is_numeric($adv) && $adv > 0 ? gmdate('Y-m-d H:i:s', (int) $adv) : null,
                    'raw'  => json_encode($c, JSON_UNESCAPED_UNICODE),
                ]
            );
            $n++;
        }
        return $n;
    }

    /** @param array<mixed> $response */
    /**
     * Synchronizuje kanály ze seznamu z HA. Kanály, které v seznamu nejsou (odebrané), smaže
     * z tabulky channels – historie zpráv zůstává. Počet slotů uloží do worker_state.
     */
    public function syncChannels(array $response): int
    {
        $list = $response['channels'] ?? $response;
        if (!is_array($list)) {
            return 0;
        }
        $n = 0;
        $seen = [];
        $slots = 0;
        foreach ($list as $key => $c) {
            if (!is_array($c)) {
                continue;
            }
            $idx = $c['channel_idx'] ?? $c['idx'] ?? $c['index'] ?? (is_int($key) ? $key : null);
            $name = $c['channel_name'] ?? $c['name'] ?? null;
            if ($idx !== null) {
                $slots = max($slots, (int) $idx + 1);
            }
            if ($idx === null || $name === null || $name === '') {
                continue; // prázdný slot
            }
            $this->upsertChannel((int) $idx, (string) $name);
            $seen[] = (int) $idx;
            $n++;
        }
        if ($seen) {
            $in = implode(',', array_map('intval', $seen));
            $this->db->query("DELETE FROM channels WHERE channel_idx NOT IN ($in)");
        } elseif ($list === []) {
            // prázdná odpověď nebereme jako „žádné kanály“ – mohla být chyba spojení
        }
        $this->setState('channel_slots', (string) max($slots, count($list)));
        return $n;
    }

    public function setState(string $k, ?string $v): void
    {
        $this->db->query(
            'INSERT INTO worker_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP(6)',
            [$k, $v]
        );
    }

    private function upsertChannel(int $idx, string $name): void
    {
        $this->db->query(
            'INSERT INTO channels (channel_idx, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)',
            [$idx, mb_substr($name, 0, 64)]
        );
    }

    /**
     * @param list<array<string,mixed>>|null $rx
     * @return array{0:?float,1:?int}
     */
    private static function bestSignal(?array $rx): array
    {
        if (!$rx) {
            return [null, null];
        }
        $snr = $rssi = null;
        foreach ($rx as $r) {
            if (isset($r['snr']) && ($snr === null || $r['snr'] > $snr)) {
                $snr = (float) $r['snr'];
            }
            if (isset($r['rssi']) && ($rssi === null || $r['rssi'] > $rssi)) {
                $rssi = (int) $r['rssi'];
            }
        }
        return [$snr, $rssi];
    }

    /** Region (flood scope) z přijatých kopií paketu */
    private static function scopeOf(?array $rx): ?string
    {
        foreach ($rx ?? [] as $r) {
            $s = $r['flood_scope'] ?? null;
            if (is_string($s) && trim($s) !== '') {
                return mb_substr(trim($s), 0, 64);
            }
        }
        return null;
    }

    private static function pk(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^[0-9a-f]{12}/i', $v)) {
            return null;
        }
        return strtolower(substr($v, 0, 12));
    }

    private static function str(mixed $v, int $max): ?string
    {
        return is_string($v) && $v !== '' ? mb_substr($v, 0, $max) : null;
    }

    private static function ts(mixed $v): string
    {
        try {
            if (is_numeric($v)) {
                $d = new \DateTimeImmutable('@' . (int) $v);
            } elseif (is_string($v) && $v !== '') {
                $d = new \DateTimeImmutable($v);
            } else {
                $d = new \DateTimeImmutable();
            }
        } catch (\Exception) {
            $d = new \DateTimeImmutable();
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
