<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Odesílání přímých zpráv s opakováním (jako oficiální aplikace):
 * 3 pokusy známou cestou, když nepřijde potvrzení (ACK), reset cesty a 3 pokusy floodem.
 * Kontakt bez známé cesty dostane rovnou 3 pokusy floodem.
 *
 * Při docházejícím kreditu zpráv (limit provozu v HA, viz TrafficBudget) se další pokusy
 * zkrátí: opakování známou cestou se přeskočí rovnou na flood a opakování floodem se vynechá,
 * aby zbyl kredit na nové zprávy. Vždy proběhne aspoň 1 pokus (a 1 floodem po selhání cesty).
 * Nové zprávy a čekající zprávy do kanálů mají přednost před opakováním.
 *
 * Všechny pokusy mají stejnou časovou značku a rostoucí číslo pokusu, takže je příjemce
 * pozná jako opakování téže zprávy. Řídí to worker (bin/worker.php) přes frontu dm_queue.
 */
final class DmSender
{
    public const DIRECT_TRIES = 3;
    public const FLOOD_TRIES = 3;

    public function __construct(private readonly Db $db)
    {
    }

    /** Zařadí odeslání zprávy (řádek v messages už existuje). */
    public function enqueue(int $msgId, string $pk12, string $body, int $ts): void
    {
        $opl = $this->db->value('SELECT out_path_len FROM contacts WHERE pubkey_prefix = ?', [$pk12]);
        $known = $opl !== null && (int) $opl >= 0;
        $this->db->query(
            'INSERT INTO dm_queue (msg_id, pk, body, ts, direct_left, flood_left, flood, next_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$msgId, $pk12, $body, $ts, $known ? self::DIRECT_TRIES : 0, self::FLOOD_TRIES, $known ? 0 : 1]
        );
    }

    /**
     * Pokusy, které je čas odeslat. Vrací příkazy pro execute_command.
     * $reserved = kredity, které si už v tomto kole vzala fronta kanálů.
     * @return list<array{qid:int, kind:string, command:string}>
     */
    public function tick(int $reserved = 0, bool $channelWaiting = false): array
    {
        // čekání na ACK vypršelo -> další pokus, přechod na flood, nebo konec
        foreach ($this->db->all("SELECT * FROM dm_queue WHERE state = 'wait' AND next_at <= UTC_TIMESTAMP() LIMIT 20") as $q) {
            $this->advance($q);
        }
        // odpověď na odeslání nepřišla (výpadek spojení s HA) -> zkusit znovu
        $this->db->query("UPDATE dm_queue SET state = 'pending', next_at = UTC_TIMESTAMP()
                          WHERE state = 'inflight' AND updated_at < UTC_TIMESTAMP() - INTERVAL 90 SECOND");

        $out = [];
        if (SendQueue::trafficUntil($this->db) > time()) {
            return $out; // HA teď odmítá odesílání – počkat společně se zprávami do kanálů
        }
        $avail = min(TrafficBudget::available($this->db), 1000) - $reserved;
        // nové zprávy dřív než opakování starších
        foreach ($this->db->all("SELECT * FROM dm_queue WHERE state = 'pending' AND next_at <= UTC_TIMESTAMP() ORDER BY attempt > 0, msg_id LIMIT 5") as $q) {
            $qid = (int) $q['msg_id'];
            // opakování nepředbíhá čekající zprávu do kanálu; bez kreditu počkat (HA by odmítl)
            if ($avail < ((int) $q['attempt'] > 0 && $channelWaiting ? 2 : 1)) {
                break;
            }
            $avail--;
            $q2 = fn(string $v): string => json_encode($v, JSON_UNESCAPED_UNICODE);
            if ((int) $q['need_reset'] === 1) {
                $out[] = ['qid' => $qid, 'kind' => 'reset', 'command' => 'reset_path ' . $q['pk']];
                $this->db->query('UPDATE dm_queue SET need_reset = 0 WHERE msg_id = ?', [$qid]);
                // companion po resetu posílá floodem – ať to ví i další zprávy (synchronizace kontaktů je až za pár minut)
                $this->db->query('UPDATE contacts SET out_path_len = -1 WHERE pubkey_prefix = ?', [$q['pk']]);
            }
            // čísla pokusů nad 3 firmware ukládá na konec zprávy (+2 B) – u dlouhé zprávy zůstaneme u 3
            $attempt = (int) $q['attempt'];
            if ($attempt > 3 && strlen((string) $q['body']) > 158) {
                $attempt = 3;
            }
            $out[] = ['qid' => $qid, 'kind' => 'send',
                'command' => 'send_msg(' . $q2((string) $q['pk']) . ', ' . $q2((string) $q['body']) . ', ' . (int) $q['ts'] . ', attempt=' . $attempt . ')'];
            $this->db->query("UPDATE dm_queue SET state = 'inflight' WHERE msg_id = ?", [$qid]);
        }
        return $out;
    }

    /** Výsledek execute_command send_msg (MSG_SENT s očekávaným ACK). */
    public function onSent(int $qid, bool $success, mixed $resp, ?string $err = null): void
    {
        $q = $this->db->one('SELECT * FROM dm_queue WHERE msg_id = ?', [$qid]);
        if (!$q || $q['state'] !== 'inflight') {
            return;
        }
        $ack = is_array($resp) ? strtolower((string) ($resp['expected_ack'] ?? '')) : '';
        if (!$success || !is_array($resp) || isset($resp['error']) || !preg_match('/^[0-9a-f]{8}$/', $ack)) {
            $reason = $err ?? (is_array($resp) ? (string) ($resp['detail'] ?? $resp['error'] ?? '') : '');
            if ((int) $q['attempt'] === 0 && str_contains($reason, 'contact_not_found')) {
                $this->finish($q, 'failed', 'Kontakt není v companionu');
                return;
            }
            // pokus se neodeslal (limit provozu, přetížení HA…) – počkat a zkusit znovu, bez započtení pokusu;
            // když se to nedaří 15 minut, vzdát to
            $age = (int) $this->db->value('SELECT TIMESTAMPDIFF(SECOND, msg_ts, UTC_TIMESTAMP()) FROM messages WHERE id = ?', [$qid]);
            if ($age > 900) {
                $this->finish($q, 'failed', 'Odeslání se nedaří: ' . mb_substr($reason, 0, 200));
                return;
            }
            $wait = SendQueue::trafficWait($reason);
            if ($wait !== null) {
                SendQueue::trafficHold($this->db, $wait + 1);
            }
            $wait ??= 15;
            $this->db->query("UPDATE dm_queue SET state = 'pending', next_at = UTC_TIMESTAMP() + INTERVAL ? SECOND WHERE msg_id = ?", [$wait + 1, $qid]);
            return;
        }
        TrafficBudget::spend($this->db);
        $timeout = (int) ($resp['suggested_timeout'] ?? 0);
        $wait = max(8, (int) ceil($timeout / 800) + 2);
        $acks = trim($q['acks'] . ',' . $ack, ',');
        $flood = (int) $q['flood'] === 1 || (int) ($resp['type'] ?? 0) === 1;
        $this->db->query(
            "UPDATE dm_queue SET state = 'wait', attempt = attempt + 1, acks = ?,
                direct_left = IF(flood = 0, GREATEST(direct_left - 1, 0), direct_left),
                flood_left = IF(flood = 1, GREATEST(flood_left - 1, 0), flood_left),
                next_at = UTC_TIMESTAMP() + INTERVAL ? SECOND WHERE msg_id = ?",
            [mb_substr($acks, -250), $wait, $qid]
        );
        $this->db->query('UPDATE messages SET dm_attempts = ?, dm_flood = ?, status = IF(status = \'delivered\', status, \'sent\') WHERE id = ?',
            [(int) $q['attempt'] + 1, $flood ? 1 : 0, $qid]);
    }

    /** Přišlo potvrzení doručení (ACK) – kód kteréhokoli pokusu znamená doručeno. */
    public function onAck(string $code): bool
    {
        $code = strtolower($code);
        if (!preg_match('/^[0-9a-f]{8}$/', $code)) {
            return false;
        }
        $q = $this->db->one(
            "SELECT * FROM dm_queue WHERE state IN ('inflight','wait','failed') AND FIND_IN_SET(?, acks) > 0
               AND updated_at > UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 1",
            [$code]
        );
        if (!$q) {
            return false;
        }
        $this->finish($q, 'done');
        return true;
    }

    /** @param array<string,mixed> $q */
    private function advance(array $q): void
    {
        $qid = (int) $q['msg_id'];
        // dochází kredit zpráv -> vynechat nepovinná opakování (další pokus známou cestou / další flood)
        $low = TrafficBudget::available($this->db) <= TrafficBudget::RESERVE;
        if ($low && (((int) $q['flood'] === 0 && (int) $q['direct_left'] > 0) || ((int) $q['flood'] === 1 && (int) $q['flood_left'] > 0
                && (int) $q['flood_left'] < self::FLOOD_TRIES))) {
            $this->db->query('UPDATE messages SET dm_trimmed = 1 WHERE id = ?', [$qid]);
            $q['direct_left'] = 0;
            if ((int) $q['flood'] === 1) {
                $q['flood_left'] = 0;
            }
        }
        if ((int) $q['flood'] === 0 && (int) $q['direct_left'] > 0) {
            $this->db->query("UPDATE dm_queue SET state = 'pending', next_at = UTC_TIMESTAMP() WHERE msg_id = ?", [$qid]);
        } elseif ((int) $q['flood'] === 0) {
            // známá cesta nefunguje -> zapomenout ji a zkusit flood
            $this->db->query("UPDATE dm_queue SET state = 'pending', flood = 1, need_reset = 1, next_at = UTC_TIMESTAMP() WHERE msg_id = ?", [$qid]);
        } elseif ((int) $q['flood_left'] > 0) {
            $this->db->query("UPDATE dm_queue SET state = 'pending', next_at = UTC_TIMESTAMP() WHERE msg_id = ?", [$qid]);
        } else {
            $this->finish($q, 'failed');
        }
    }

    /** @param array<string,mixed> $q */
    private function finish(array $q, string $state, ?string $error = null): void
    {
        $qid = (int) $q['msg_id'];
        if ($state === 'done') {
            // doručeno – záznam ve frontě už není potřeba
            $this->db->query('DELETE FROM dm_queue WHERE msg_id = ?', [$qid]);
        } else {
            // nedoručené/chybné necháme den – kdyby ACK dorazil se zpožděním
            $this->db->query('UPDATE dm_queue SET state = ? WHERE msg_id = ?', [$state, $qid]);
        }
        if ($state === 'done') {
            $this->db->query("UPDATE messages SET status = 'delivered', ack_received = 1, error = NULL WHERE id = ?", [$qid]);
        } elseif ($error !== null) {
            $this->db->query("UPDATE messages SET status = 'failed', error = ? WHERE id = ?", [$error, $qid]);
        } else {
            $this->db->query('UPDATE messages SET ack_received = 0 WHERE id = ? AND status <> \'delivered\'', [$qid]);
        }
        // staré záznamy fronty uklidit
        $this->db->query("DELETE FROM dm_queue WHERE state IN ('done','failed') AND updated_at < UTC_TIMESTAMP() - INTERVAL 1 DAY");
    }
}
