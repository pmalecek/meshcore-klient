<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Odhad zbývajícího kreditu zpráv v integraci MeshCore (režim „Governed“, lane „messages“:
 * zásoba 10, doplňuje se 60 za hodinu). Každé odeslání DM, zprávy do kanálu i příkazu
 * pro repeater stojí 1 kredit, i každý opakovaný pokus.
 *
 * Výchozí hodnota pochází ze senzoru „Request Rate Limiter“ (atributy messages_*). HA ho ale
 * zapisuje jen občas a šablona se kvůli němu přepočítá nejvýš 1× za minutu, proto se od jeho
 * posledního zápisu odečítají vlastní odeslání a připočítává doplňování (tabulka traffic_log).
 * Když HA odeslání odmítne, známe přesnou dobu čekání a kredit se srovná.
 *
 * Bez senzoru (režim Legacy, starší integrace) limit neplatí – estimate() vrací null.
 */
final class TrafficBudget
{
    /** Kolik kreditů nechat volných pro nové zprávy – opakování DM se pod tuto hranici nepouští. */
    public const RESERVE = 3;

    /**
     * Nový stav senzoru ze snapshotu HA (worker). $rl = null -> senzor není (limit neplatí).
     * @param array<string,mixed>|null $rl
     */
    public static function sync(Db $db, ?array $rl): void
    {
        if (!$rl || !is_numeric($rl['credits'] ?? null) || !is_numeric($rl['cap'] ?? null) || !is_numeric($rl['per_hour'] ?? null)) {
            self::setBase($db, null);
            return;
        }
        $old = self::base($db);
        $seen = (string) ($rl['updated'] ?? '');
        if ($old && ($old['seen'] ?? '') === $seen && (int) $old['credits'] === (int) $rl['credits']) {
            return; // stejný zápis senzoru (šablona se přepočítala kvůli jiné entitě)
        }
        $at = strtotime($seen) ?: time();
        self::setBase($db, [
            'credits'  => (int) $rl['credits'],
            'cap'      => max(1, (int) $rl['cap']),
            'per_hour' => max(1, (int) $rl['per_hour']),
            'at'       => min((float) $at, microtime(true)),
            'seen'     => $seen,
        ]);
        $db->query('DELETE FROM traffic_log WHERE at < ?', [$at - 3600]);
    }

    /** Odeslání prošlo – stálo 1 kredit. */
    public static function spend(Db $db, int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            $db->query("INSERT INTO traffic_log (at, kind) VALUES (?, 'spend')", [round(microtime(true), 3)]);
        }
    }

    /** HA odmítl odeslání: kredit je pod 1 a další přibude za $wait sekund. */
    public static function refused(Db $db, int $wait): void
    {
        $b = self::base($db);
        if (!$b) {
            return;
        }
        $rate = $b['per_hour'] / 3600;
        $db->query("INSERT INTO traffic_log (at, kind, val) VALUES (?, 'set', ?)",
            [round(microtime(true), 3), max(-$b['cap'], 1 - max(1, $wait) * $rate)]);
    }

    /**
     * Odhad v tuto chvíli.
     * @return array{credits:int, cap:int, per_hour:int, next_in:int}|null
     */
    public static function estimate(Db $db): ?array
    {
        $b = self::base($db);
        if (!$b) {
            return null;
        }
        $cap  = (int) $b['cap'];
        $rate = $b['per_hour'] / 3600;
        $c = (float) $b['credits'];
        $t = (float) $b['at'];
        foreach ($db->all('SELECT at, kind, val FROM traffic_log WHERE at > ? ORDER BY at, id', [$t]) as $e) {
            $at = (float) $e['at'];
            $c = min($cap, $c + max(0, $at - $t) * $rate);
            $c = $e['kind'] === 'set' ? (float) $e['val'] : max(0.0, $c - 1);
            $t = $at;
        }
        $c = min($cap, $c + max(0, microtime(true) - $t) * $rate);
        $credits = max(0, (int) floor($c + 1e-6));
        return [
            'credits'  => $credits,
            'cap'      => $cap,
            'per_hour' => (int) $b['per_hour'],
            'next_in'  => $credits >= $cap ? 0 : (int) ceil((floor($c + 1e-6) + 1 - $c) / $rate),
        ];
    }

    /** Kolik kreditů je teď k dispozici (bez limitu = hodně). */
    public static function available(Db $db): int
    {
        $e = self::estimate($db);
        return $e === null ? PHP_INT_MAX : $e['credits'];
    }

    /** @return array<string,mixed>|null */
    private static function base(Db $db): ?array
    {
        $v = $db->value("SELECT v FROM worker_state WHERE k = 'traffic_base'");
        $b = is_string($v) ? json_decode($v, true) : null;
        return is_array($b) ? $b : null;
    }

    /** @param array<string,mixed>|null $b */
    private static function setBase(Db $db, ?array $b): void
    {
        $db->query("INSERT INTO worker_state (k, v) VALUES ('traffic_base', ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP(6)",
            [$b === null ? '' : json_encode($b)]);
    }
}
