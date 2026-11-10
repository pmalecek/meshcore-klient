<?php
declare(strict_types=1);

namespace MeshClient;

use InvalidArgumentException;

/**
 * Úložiště historie Bluetooth klienta (/ble) pro přihlášené uživatele.
 * Prohlížeč je „zdroj pravdy“ – server zprávy jen zrcadlí (JSON), aby šly načíst jinde.
 */
final class BleStore
{
    private const KV_KEYS = ['settings', 'reads', 'contacts', 'channels', 'self'];
    private const MAX_MSG_JSON = 64 * 1024;
    private const MAX_KV_JSON = 4 * 1024 * 1024;
    private const PULL_LIMIT = 3000;

    public function __construct(private readonly Db $db)
    {
    }

    public static function device(mixed $d): string
    {
        $d = strtolower((string) $d);
        if (!preg_match('/^[0-9a-f]{12}$/', $d)) {
            throw new InvalidArgumentException('Neplatné zařízení');
        }
        return $d;
    }

    /** @return list<array{device:string,name:?string,last_seen:string}> */
    public function devices(): array
    {
        return $this->db->all('SELECT device, name, last_seen FROM ble_devices ORDER BY last_seen DESC');
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @param list<int> $deleted
     * @param array<string,mixed> $kv
     */
    public function push(string $device, ?string $name, array $messages, array $deleted, array $kv): int
    {
        if (count($messages) > 500 || count($deleted) > 5000) {
            throw new InvalidArgumentException('Příliš velká dávka');
        }
        $this->db->query(
            'INSERT INTO ble_devices (device, name, last_seen) VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE name = COALESCE(VALUES(name), name), last_seen = UTC_TIMESTAMP()',
            [$device, $name !== null ? mb_substr($name, 0, 64) : null]
        );
        $n = 0;
        foreach ($messages as $m) {
            if (!is_array($m) || !isset($m['id']) || !is_numeric($m['id']) || (float) $m['id'] <= 0
                || !preg_match('/^(c:\d{1,3}|d:[0-9a-f]{12})$/', (string) ($m['conv'] ?? ''))) {
                continue;
            }
            $json = json_encode($m, JSON_UNESCAPED_UNICODE);
            if ($json === false || strlen($json) > self::MAX_MSG_JSON) {
                continue;
            }
            $ts = isset($m['ts']) && is_numeric($m['ts']) ? gmdate('Y-m-d H:i:s', (int) floor($m['ts'] / 1000)) : null;
            $this->db->query(
                'INSERT INTO ble_messages (device, id, data, msg_ts, deleted) VALUES (?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), msg_ts = VALUES(msg_ts), deleted = 0',
                [$device, (string) (int) $m['id'], $json, $ts]
            );
            $n++;
        }
        foreach ($deleted as $id) {
            if (is_numeric($id) && $id > 0) {
                $this->db->query(
                    'INSERT INTO ble_messages (device, id, data, deleted) VALUES (?, ?, NULL, 1)
                     ON DUPLICATE KEY UPDATE data = NULL, deleted = 1',
                    [$device, (string) (int) $id]
                );
            }
        }
        foreach ($kv as $k => $v) {
            if (!in_array($k, self::KV_KEYS, true)) {
                continue;
            }
            $json = json_encode($v, JSON_UNESCAPED_UNICODE);
            if ($json === false || strlen($json) > self::MAX_KV_JSON) {
                continue;
            }
            $this->db->query(
                'INSERT INTO ble_kv (device, k, v) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
                [$device, $k, $json]
            );
        }
        // smazané záznamy stačí držet měsíc (aby se smazání propsalo do ostatních prohlížečů)
        if (random_int(1, 50) === 1) {
            $this->db->query('DELETE FROM ble_messages WHERE deleted = 1 AND updated_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        }
        return $n;
    }

    /** @return array{messages: list<array>, deleted: list<int>, kv: array<string,mixed>, cursor: string, more: bool} */
    public function pull(string $device, string $since): array
    {
        if ($since !== '' && !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(\.\d{1,6})?$/', $since)) {
            $since = '';
        }
        $rows = $this->db->all(
            'SELECT id, data, deleted, DATE_FORMAT(updated_at, "%Y-%m-%d %H:%i:%s.%f") AS upd FROM ble_messages
             WHERE device = ? AND updated_at > ? AND updated_at <= UTC_TIMESTAMP(6) ' . ($since === '' ? 'AND deleted = 0 ' : '') .
            'ORDER BY updated_at LIMIT ' . self::PULL_LIMIT,
            [$device, $since === '' ? '1970-01-01 00:00:00' : $since]
        );
        $messages = [];
        $deleted = [];
        $cursor = $since;
        foreach ($rows as $r) {
            $cursor = max($cursor, (string) $r['upd']);
            if ((int) $r['deleted'] === 1) {
                $deleted[] = (int) $r['id'];
                continue;
            }
            $m = json_decode((string) $r['data'], true);
            if (is_array($m)) {
                $messages[] = $m;
            }
        }
        $kv = [];
        foreach ($this->db->all(
            'SELECT k, v, DATE_FORMAT(updated_at, "%Y-%m-%d %H:%i:%s.%f") AS upd FROM ble_kv WHERE device = ? AND updated_at > ? AND updated_at <= UTC_TIMESTAMP(6)',
            [$device, $since === '' ? '1970-01-01 00:00:00' : $since]
        ) as $r) {
            $kv[$r['k']] = json_decode((string) $r['v'], true);
            // kv nesmí posunout kurzor za nenačtené zprávy
            if (count($rows) < self::PULL_LIMIT) {
                $cursor = max($cursor, (string) $r['upd']);
            }
        }
        if ($cursor === '') {
            $cursor = (string) $this->db->value('SELECT DATE_FORMAT(UTC_TIMESTAMP(6), "%Y-%m-%d %H:%i:%s.%f")');
        }
        return ['messages' => $messages, 'deleted' => $deleted, 'kv' => $kv, 'cursor' => $cursor, 'more' => count($rows) >= self::PULL_LIMIT];
    }
}
