<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Mazání zpráv – ručně (zpráva, konverzace) i automaticky podle doby uchování.
 */
final class Retention
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Smaže zprávu i s jejími duplicitami. */
    public function deleteMessage(int $id): int
    {
        $n = $this->db->query('DELETE FROM messages WHERE id = ? OR dup_of = ?', [$id, $id])->rowCount();
        return $n;
    }

    /** Smaže celou historii konverzace. */
    public function deleteConversation(string $conv): int
    {
        $n = $this->db->query('DELETE FROM messages WHERE conv_key = ?', [$conv])->rowCount();
        $this->db->query('DELETE FROM conv_reads WHERE conv_key = ?', [$conv]);
        return $n;
    }

    /**
     * Smaže zprávy starší než nastavená doba uchování (výchozí + výjimky u konverzací).
     * Běží nejvýš jednou za hodinu, pokud není $force. Vrací počet smazaných zpráv, nebo null.
     */
    public function cleanup(int $defaultDays, bool $force = false): ?int
    {
        $last = $this->db->value("SELECT v FROM worker_state WHERE k = 'retention_at'");
        if (!$force && $last && time() - (int) strtotime((string) $last) < 3600) {
            return null;
        }
        $this->db->query("INSERT INTO worker_state (k, v) VALUES ('retention_at', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [gmdate('c')]);

        $deleted = 0;
        $overrides = $this->db->all('SELECT conv_key, retention_days FROM conv_settings WHERE retention_days IS NOT NULL');
        foreach ($overrides as $o) {
            if ((int) $o['retention_days'] > 0) {
                $deleted += $this->purge('m.conv_key = ?', [$o['conv_key']], (int) $o['retention_days']);
            }
        }
        if ($defaultDays > 0) {
            $keys = array_column($overrides, 'conv_key');
            $where = $keys ? 'm.conv_key NOT IN (' . implode(',', array_fill(0, count($keys), '?')) . ')' : '1 = 1';
            $deleted += $this->purge($where, $keys, $defaultDays);
        }
        return $deleted;
    }

    /** @param list<string> $params */
    private function purge(string $where, array $params, int $days): int
    {
        // smaže staré zprávy i duplicity, jejichž původní zpráva je stará
        return $this->db->query(
            "DELETE m FROM messages m LEFT JOIN messages o ON o.id = m.dup_of
             WHERE $where AND (m.msg_ts < UTC_TIMESTAMP() - INTERVAL ? DAY OR o.msg_ts < UTC_TIMESTAMP() - INTERVAL ? DAY)",
            array_merge($params, [$days, $days])
        )->rowCount();
    }
}
