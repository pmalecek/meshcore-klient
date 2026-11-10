<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Zprávy do kanálů, které integrace MeshCore odmítla kvůli limitu provozu
 * („Mesh traffic messages lane is empty; try again in N seconds“). Počkají ve frontě
 * a worker je odešle, jakmile se limit uvolní. V konverzaci je mezitím vidět zástupná
 * bublina se stavem „čeká“, kterou pak nahradí skutečně odeslaná zpráva.
 */
final class SendQueue
{
    private const MAX_ATTEMPTS = 20;
    private const MAX_ERROR_ATTEMPTS = 5;   // jiné (přechodné) chyby než limit provozu
    public const ERROR_WAIT = 10;

    /**
     * Přechodná chyba při přetížení (HA vrátí 500, vyprší čas, companion neodpoví) – stojí za zopakování.
     */
    public static function transient(string $error): bool
    {
        return (bool) preg_match('/chybu 5\d\d|nedostupný|timed? ?out|timeout|internal server error|got itself in trouble|unknown error|not connected|no_response/i', $error);
    }

    public function __construct(private readonly Db $db)
    {
    }

    // ---- společné čekání na limit provozu (platí pro DM i kanály) ----

    /** Do kdy (unix čas) HA odmítá odesílání kvůli limitu provozu; 0 = neomezeno. */
    public static function trafficUntil(Db $db): int
    {
        return (int) ($db->value("SELECT v FROM worker_state WHERE k = 'traffic_until'") ?? 0);
    }

    /** Zaznamená čekání (prodlužuje, nezkracuje). */
    public static function trafficHold(Db $db, int $seconds): void
    {
        $until = time() + max(1, $seconds);
        TrafficBudget::refused($db, $seconds);
        if ($until > self::trafficUntil($db)) {
            $db->query("INSERT INTO worker_state (k, v) VALUES ('traffic_until', ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP(6)",
                [(string) $until]);
        }
    }

    /** Počet sekund z hlášky limitu provozu, jinak null (nejde o limit). */
    public static function trafficWait(string $error): ?int
    {
        if (preg_match('/try again in (\d+) second/i', $error, $m)) {
            return max(1, (int) $m[1]);
        }
        return stripos($error, 'traffic') !== false && stripos($error, 'lane') !== false ? 30 : null;
    }

    /** Čeká ve frontě zpráva, kterou je už čas poslat? (DM opakování ji nepředbíhají) */
    public function waiting(): bool
    {
        return (bool) $this->db->value("SELECT 1 FROM send_queue WHERE state = 'pending' AND next_at <= UTC_TIMESTAMP() LIMIT 1");
    }

    /** Je ve frontě něco, co má jít dřív? (zachování pořadí zpráv) */
    public function busy(): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM send_queue LIMIT 1');
    }

    /** Založí zástupnou zprávu a zařadí ji. Vrací ID zprávy. */
    public function enqueue(string $conv, int $channelIdx, string $body, int $waitSec, ?int $dupOf = null): int
    {
        $this->db->query(
            "INSERT INTO messages (conv_key, direction, message_type, channel_idx, body, status, repeater_count, msg_ts, dup_of)
             VALUES (?, 'out', 'channel', ?, ?, 'queued', 0, UTC_TIMESTAMP(3), ?)",
            [$conv, $channelIdx, $body, $dupOf]
        );
        $id = $this->db->lastId();
        if ($dupOf) {
            $this->db->query('UPDATE messages SET dup_count = dup_count + 1 WHERE id = ?', [$dupOf]);
        }
        // za poslední čekající, aby se nepředbíhaly
        $last = $this->db->value('SELECT MAX(next_at) FROM send_queue');
        $this->db->query(
            'INSERT INTO send_queue (msg_id, channel_idx, body, next_at)
             VALUES (?, ?, ?, GREATEST(UTC_TIMESTAMP() + INTERVAL ? SECOND, COALESCE(? + INTERVAL 2 SECOND, UTC_TIMESTAMP())))',
            [$id, $channelIdx, $body, max($waitSec + 1, self::trafficUntil($this->db) - time()), $last]
        );
        return $id;
    }

    /** Další zpráva k odeslání (vždy jen jedna – limit provozu je společný). */
    public function due(): ?array
    {
        if (self::trafficUntil($this->db) > time() || TrafficBudget::available($this->db) < 1) {
            return null; // HA by teď odmítl – počkat na kredit
        }
        $this->db->query("UPDATE send_queue SET state = 'pending' WHERE state = 'inflight' AND updated_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND");
        if ($this->db->value("SELECT 1 FROM send_queue WHERE state = 'inflight' LIMIT 1")) {
            return null;
        }
        $q = $this->db->one("SELECT * FROM send_queue WHERE state = 'pending' AND next_at <= UTC_TIMESTAMP() ORDER BY next_at, msg_id LIMIT 1");
        if ($q && $this->db->value('SELECT status FROM messages WHERE id = ?', [$q['msg_id']]) !== 'queued') {
            // HA zprávu přes chybovou odpověď přece jen odeslal (dorazila událost) – znovu neposílat
            $this->db->query('DELETE FROM send_queue WHERE msg_id = ?', [$q['msg_id']]);
            return null;
        }
        if ($q) {
            $this->db->query("UPDATE send_queue SET state = 'inflight', attempts = attempts + 1 WHERE msg_id = ?", [$q['msg_id']]);
        }
        return $q ?: null;
    }

    /** Výsledek volání send_channel_message. */
    public function onResult(int $msgId, bool $success, string $error = ''): void
    {
        $q = $this->db->one('SELECT * FROM send_queue WHERE msg_id = ?', [$msgId]);
        if (!$q) {
            return;
        }
        if ($success) {
            // zprávu teď převezme událost meshcore_message (MessageStore nahradí zástupný řádek)
            $this->db->query('DELETE FROM send_queue WHERE msg_id = ?', [$msgId]);
            TrafficBudget::spend($this->db);
            return;
        }
        $wait = self::trafficWait($error);
        if ($wait !== null) {
            self::trafficHold($this->db, $wait + 1);
        }
        if ($wait !== null && (int) $q['attempts'] < self::MAX_ATTEMPTS) {
            $this->db->query("UPDATE send_queue SET state = 'pending', next_at = UTC_TIMESTAMP() + INTERVAL ? SECOND WHERE msg_id = ?", [$wait + 1, $msgId]);
            return;
        }
        if ($wait === null && self::transient($error) && (int) $q['attempts'] < self::MAX_ERROR_ATTEMPTS) {
            $this->db->query("UPDATE send_queue SET state = 'pending', next_at = UTC_TIMESTAMP() + INTERVAL ? SECOND WHERE msg_id = ?", [self::ERROR_WAIT, $msgId]);
            return;
        }
        $this->db->query('DELETE FROM send_queue WHERE msg_id = ?', [$msgId]);
        $this->db->query("UPDATE messages SET status = 'failed', error = ? WHERE id = ?", [mb_substr($error ?: 'Odeslání selhalo', 0, 250), $msgId]);
    }

    /** Odeslaná zpráva dorazila z HA – najít její zástupný řádek. */
    public function placeholderFor(string $conv, string $body): ?int
    {
        $id = $this->db->value(
            "SELECT id FROM messages WHERE conv_key = ? AND direction = 'out' AND status = 'queued' AND body = ?
               AND msg_ts > UTC_TIMESTAMP() - INTERVAL 1 HOUR ORDER BY id LIMIT 1",
            [$conv, $body]
        );
        return $id ? (int) $id : null;
    }
}
