<?php
declare(strict_types=1);

namespace MeshClient;

use InvalidArgumentException;
use RuntimeException;

/**
 * Historie senzorů z Home Assistantu.
 *  - krátká období (24 h, 7 d): historie stavů přes REST (/api/history) – přesné hodnoty
 *  - dlouhá období (30 d, 1 rok): dlouhodobé statistiky přes WebSocket (průměr/min/max)
 */
final class History
{
    public const RANGES = [
        '24h' => ['seconds' => 86400,       'source' => 'history'],
        '7d'  => ['seconds' => 7 * 86400,   'source' => 'history'],
        '30d' => ['seconds' => 30 * 86400,  'source' => 'statistics', 'period' => 'hour'],
        '1y'  => ['seconds' => 365 * 86400, 'source' => 'statistics', 'period' => 'day'],
    ];
    private const MAX_POINTS = 400;

    /** @param array<string,mixed> $haCfg */
    public function __construct(private readonly array $haCfg)
    {
    }

    public static function validEntity(string $id): bool
    {
        // jen senzory meshcore-ha – web nesmí číst libovolné entity z HA
        return (bool) preg_match('/^sensor\.meshcore_[0-9a-f]{6,10}_[a-z0-9_]{1,120}$/', $id);
    }

    /**
     * @return array{source:string,points:list<array{0:int,1:float,2?:float,3?:float}>,from:string,to:string}
     */
    public function fetch(string $entity, string $range): array
    {
        if (!self::validEntity($entity)) {
            throw new InvalidArgumentException('Neplatná entita');
        }
        $r = self::RANGES[$range] ?? throw new InvalidArgumentException('Neplatné období');
        $to   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $from = $to->modify('-' . $r['seconds'] . ' seconds');

        $source = $r['source'];
        $points = $source === 'statistics'
            ? $this->statistics($entity, $from, $to, $r['period'])
            : [];
        if ($source === 'statistics' && !$points) {
            // senzor nemá dlouhodobé statistiky (chybí state_class) → aspoň dostupná historie
            $source = 'history';
        }
        if ($source === 'history') {
            $points = $this->downsample($this->history($entity, $from, $to));
        }
        return [
            'source' => $source,
            'points' => $points,
            'from'   => $from->format('c'),
            'to'     => $to->format('c'),
        ];
    }

    /** @return list<array{0:int,1:float}> */
    private function history(string $entity, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $ha = HaRest::fromConfig($this->haCfg);
        $path = '/api/history/period/' . rawurlencode($from->format('Y-m-d\TH:i:s\Z'))
            . '?filter_entity_id=' . rawurlencode($entity)
            . '&end_time=' . rawurlencode($to->format('Y-m-d\TH:i:s\Z'))
            . '&minimal_response&no_attributes';
        $data = $ha->get($path);
        $out = [];
        foreach ($data[0] ?? [] as $row) {
            $v = $row['state'] ?? null;
            $t = $row['last_changed'] ?? ($row['last_updated'] ?? null);
            if (!is_numeric($v) || !$t) {
                continue; // unknown / unavailable
            }
            $out[] = [(int) (strtotime($t) * 1000), (float) $v];
        }
        return $out;
    }

    /** @return list<array{0:int,1:float,2:float,3:float}> */
    private function statistics(string $entity, \DateTimeImmutable $from, \DateTimeImmutable $to, string $period): array
    {
        $res = $this->wsCall([
            'type'          => 'recorder/statistics_during_period',
            'start_time'    => $from->format('c'),
            'end_time'      => $to->format('c'),
            'statistic_ids' => [$entity],
            'period'        => $period,
            'types'         => ['mean', 'min', 'max'],
        ]);
        $out = [];
        foreach ($res[$entity] ?? [] as $row) {
            if (!isset($row['mean']) || !is_numeric($row['mean'])) {
                continue;
            }
            $start = $row['start'];
            $ms = is_numeric($start) ? (int) ($start < 1e11 ? $start * 1000 : $start) : (int) (strtotime((string) $start) * 1000);
            $out[] = [$ms, (float) $row['mean'], (float) ($row['min'] ?? $row['mean']), (float) ($row['max'] ?? $row['mean'])];
        }
        return $out;
    }

    /**
     * Jednorázový dotaz přes WebSocket API Home Assistantu.
     * @param array<string,mixed> $payload
     * @return array<mixed>
     */
    private function wsCall(array $payload): array
    {
        $url = preg_replace('#^http#', 'ws', rtrim($this->haCfg['url'], '/')) . '/api/websocket';
        $ws = new WebSocketClient($url, (bool) ($this->haCfg['verify_tls'] ?? true));
        $ws->connect();
        try {
            $this->waitFor($ws, static fn($m) => ($m['type'] ?? '') === 'auth_required');
            $ws->sendJson(['type' => 'auth', 'access_token' => $this->haCfg['token']]);
            $m = $this->waitFor($ws, static fn($m) => in_array($m['type'] ?? '', ['auth_ok', 'auth_invalid'], true));
            if ($m['type'] !== 'auth_ok') {
                throw new RuntimeException('Home Assistant odmítl token');
            }
            $ws->sendJson(['id' => 1] + $payload);
            $m = $this->waitFor($ws, static fn($m) => ($m['id'] ?? null) === 1 && ($m['type'] ?? '') === 'result');
            if (empty($m['success'])) {
                throw new RuntimeException('Home Assistant: ' . ($m['error']['message'] ?? 'chyba dotazu'));
            }
            return is_array($m['result'] ?? null) ? $m['result'] : [];
        } finally {
            $ws->close();
        }
    }

    private function waitFor(WebSocketClient $ws, callable $match, int $timeout = 15): array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            foreach ($ws->receive(0.5) as $raw) {
                $m = json_decode($raw, true);
                if (is_array($m) && $match($m)) {
                    return $m;
                }
            }
        }
        throw new RuntimeException('Home Assistant neodpověděl včas');
    }

    /**
     * Zmenší počet bodů průměrováním do časových přihrádek (zachová tvar křivky).
     * @param list<array{0:int,1:float}> $pts
     * @return list<array{0:int,1:float}>
     */
    private function downsample(array $pts): array
    {
        $n = count($pts);
        if ($n <= self::MAX_POINTS) {
            return $pts;
        }
        $t0 = $pts[0][0];
        $span = max(1, $pts[$n - 1][0] - $t0);
        $buckets = [];
        foreach ($pts as [$t, $v]) {
            $b = min(self::MAX_POINTS - 1, (int) floor(($t - $t0) / $span * self::MAX_POINTS));
            $buckets[$b][] = [$t, $v];
        }
        $out = [];
        foreach ($buckets as $items) {
            $ts = array_sum(array_column($items, 0)) / count($items);
            $vs = array_sum(array_column($items, 1)) / count($items);
            $out[] = [(int) $ts, round($vs, 3)];
        }
        return $out;
    }
}
