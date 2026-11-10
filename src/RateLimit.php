<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Jednoduché omezení počtu požadavků (posuvné okno) v tabulce rate_limits.
 */
final class RateLimit
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Vrací true, pokud ještě není limit vyčerpán (a požadavek započítá). */
    public function hit(string $bucket, int $max, int $windowSec): bool
    {
        $bucket = mb_substr($bucket, 0, 80);
        $n = (int) $this->db->value(
            'SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND created_at > UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [$bucket, $windowSec]
        );
        if ($n >= $max) {
            return false;
        }
        $this->db->query('INSERT INTO rate_limits (bucket, created_at) VALUES (?, UTC_TIMESTAMP())', [$bucket]);
        if (random_int(1, 100) === 1) {
            $this->db->query('DELETE FROM rate_limits WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        }
        return true;
    }

    /** Ověří všechny limity; vrací null nebo popis překročeného limitu (nic se nezapočítá, pokud už jeden neprošel). */
    public function check(array $limits, int $windowSec): ?string
    {
        foreach ($limits as $bucket => $max) {
            $n = (int) $this->db->value(
                'SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND created_at > UTC_TIMESTAMP() - INTERVAL ? SECOND',
                [mb_substr((string) $bucket, 0, 80), $windowSec]
            );
            if ($n >= $max) {
                return (string) $bucket;
            }
        }
        foreach (array_keys($limits) as $bucket) {
            $this->db->query('INSERT INTO rate_limits (bucket, created_at) VALUES (?, UTC_TIMESTAMP())', [mb_substr((string) $bucket, 0, 80)]);
        }
        if (random_int(1, 100) === 1) {
            $this->db->query('DELETE FROM rate_limits WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        }
        return null;
    }
}
