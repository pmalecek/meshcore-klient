<?php
declare(strict_types=1);

/**
 * Worker – drží WebSocket spojení s Home Assistantem, odebírá události meshcore-ha
 * a ukládá je do databáze. Spouští se jako systemd služba (viz deploy/).
 *
 *   php bin/worker.php            – běh
 *   php bin/worker.php -v         – běh s podrobným výpisem
 */

use MeshClient\DmSender;
use MeshClient\HaTemplate;
use MeshClient\MessageStore;
use MeshClient\SendQueue;
use MeshClient\TrafficBudget;
use MeshClient\WebSocketClient;

require dirname(__DIR__) . '/src/bootstrap.php';

$cfg     = app_config();
$verbose = in_array('-v', $argv, true);
$store   = new MessageStore(db(), (int) ($cfg['app']['dedup_window'] ?? 1800));
$dm      = new DmSender(db());
$sq      = new SendQueue(db());

$events = [
    'meshcore_message',
    'meshcore_delivery_update',
    'meshcore_message_send_failed',
    'meshcore_connected',
    'meshcore_disconnected',
    'meshcore_raw_event', // jen kvůli autorům příspěvků v room serverech
];

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

function logmsg(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] $msg\n");
}

$wsUrl = preg_replace('#^http#', 'ws', rtrim($cfg['ha']['url'], '/')) . '/api/websocket';
$delay = 1;

while ($running) {
    $ws = new WebSocketClient($wsUrl, (bool) $cfg['ha']['verify_tls']);
    try {
        logmsg("Připojuji se k $wsUrl");
        $ws->connect();

        // --- autentizace ---
        $msg = waitFor($ws, static fn(array $m) => in_array($m['type'] ?? '', ['auth_required'], true), 10);
        $ws->sendJson(['type' => 'auth', 'access_token' => $cfg['ha']['token']]);
        $msg = waitFor($ws, static fn(array $m) => in_array($m['type'] ?? '', ['auth_ok', 'auth_invalid'], true), 10);
        if ($msg['type'] !== 'auth_ok') {
            throw new RuntimeException('Autentizace selhala: ' . ($msg['message'] ?? 'neplatný token'));
        }
        logmsg('Přihlášeno k Home Assistant ' . ($msg['ha_version'] ?? ''));

        $nextId  = 1;
        $pending = []; // id => popis požadavku
        $subs    = []; // id => event_type

        foreach ($events as $ev) {
            $id = $nextId++;
            $ws->sendJson(['id' => $id, 'type' => 'subscribe_events', 'event_type' => $ev]);
            $subs[$id] = $ev;
            $pending[$id] = "subscribe:$ev";
        }

        $requestSync = static function () use ($ws, &$nextId, &$pending, $cfg): void {
            foreach (['get_contacts', 'get_channels'] as $svc) {
                $id = $nextId++;
                $data = [];
                if (!empty($cfg['ha']['entry_id'])) {
                    $data['entry_id'] = $cfg['ha']['entry_id'];
                }
                $ws->sendJson([
                    'id'              => $id,
                    'type'            => 'call_service',
                    'domain'          => 'meshcore',
                    'service'         => $svc,
                    'service_data'    => (object) $data,
                    'return_response' => true,
                ]);
                $pending[$id] = $svc;
            }
        };
        $requestSync();

        // stav sledovaných zařízení, MQTT a slunce – HA posílá aktualizace sám
        $tplId = $nextId++;
        $ws->sendJson([
            'id'            => $tplId,
            'type'          => 'render_template',
            'template'      => HaTemplate::snapshot(),
            'report_errors' => true,
        ]);
        $pending[$tplId] = 'render_template';

        $store->setState('worker_connected', '1');
        $store->setState('worker_started_at', gmdate('c'));
        $delay = 1;

        $lastPing   = time();
        $lastPong   = time();
        $lastSync   = time();
        $lastBeat   = 0;
        $pingId     = null;

        while ($running) {
            foreach ($ws->receive(1.0) as $raw) {
                $m = json_decode($raw, true);
                if (!is_array($m)) {
                    continue;
                }
                $type = $m['type'] ?? '';
                $id   = $m['id'] ?? null;

                if ($type === 'pong') {
                    $lastPong = time();
                    continue;
                }
                if ($type === 'event' && $id === $tplId) {
                    $ev = $m['event'] ?? [];
                    if (isset($ev['error'])) {
                        logmsg('Chyba šablony stavu zařízení: ' . $ev['error']);
                        continue;
                    }
                    $res = $ev['result'] ?? null;
                    if (is_string($res)) {
                        $res = json_decode($res, true);
                    }
                    if (is_array($res)) {
                        $store->setState('ha_snapshot', json_encode($res, JSON_UNESCAPED_UNICODE));
                        TrafficBudget::sync(db(), is_array($res['traffic'] ?? null) ? $res['traffic'] : null);
                        if ($verbose) {
                            logmsg(sprintf('Stav zařízení: %d sledovaných, %d MQTT, slunce %s',
                                count($res['devices'] ?? []), count($res['mqtt'] ?? []), $res['sun']['state'] ?? '?'));
                        }
                    }
                    continue;
                }
                if ($type === 'event' && isset($subs[$id])) {
                    $ev = $m['event'] ?? [];
                    $evType = $ev['event_type'] ?? $subs[$id];
                    $data = $ev['data'] ?? [];
                    if (!empty($cfg['ha']['entry_id']) && ($data['entry_id'] ?? null) !== $cfg['ha']['entry_id']) {
                        continue;
                    }
                    if ($evType === 'meshcore_raw_event') {
                        // surových událostí je hodně – zpracovat tiše a bez zápisu stavu
                        try {
                            $store->handleEvent($evType, $data);
                        } catch (Throwable $e) {
                            logmsg('Chyba při zpracování raw události: ' . $e->getMessage());
                        }
                        continue;
                    }
                    if ($verbose) {
                        logmsg("Událost $evType: " . json_encode($data, JSON_UNESCAPED_UNICODE));
                    }
                    try {
                        $store->handleEvent($evType, $data);
                    } catch (Throwable $e) {
                        logmsg("Chyba při ukládání $evType: " . $e->getMessage());
                    }
                    $store->setState('last_event_at', gmdate('c'));
                    continue;
                }
                if ($type === 'result' && isset($pending[$id])) {
                    $what = $pending[$id];
                    unset($pending[$id]);
                    if (str_starts_with($what, 'dm:')) {
                        $resp = $m['result']['response'] ?? null;
                        $dm->onSent((int) substr($what, 3), !empty($m['success']), $resp,
                            empty($m['success']) ? (string) ($m['error']['message'] ?? 'chyba') : null);
                        continue;
                    }
                    if (str_starts_with($what, 'dmreset:')) {
                        continue;
                    }
                    if (str_starts_with($what, 'sq:')) {
                        $sq->onResult((int) substr($what, 3), !empty($m['success']), (string) ($m['error']['message'] ?? ''));
                        continue;
                    }
                    if (empty($m['success'])) {
                        logmsg("Požadavek $what selhal: " . json_encode($m['error'] ?? null, JSON_UNESCAPED_UNICODE));
                        continue;
                    }
                    $resp = $m['result']['response'] ?? null;
                    if ($what === 'get_contacts' && is_array($resp)) {
                        logmsg('Kontakty synchronizovány: ' . $store->syncContacts($resp));
                    } elseif ($what === 'get_channels' && is_array($resp)) {
                        logmsg('Kanály synchronizovány: ' . $store->syncChannels($resp));
                    }
                }
            }

            // přímé zprávy s opakováním (DmSender)
            try {
                // zprávy do kanálů čekající na volný provoz (SendQueue) – mají přednost před opakováním DM
                $sqWaiting = $sq->waiting();
                if ($q = $sq->due()) {
                    $rid = $nextId++;
                    $data = ['message' => (string) $q['body'], 'channel_idx' => (int) $q['channel_idx']];
                    if (!empty($cfg['ha']['entry_id'])) {
                        $data['entry_id'] = $cfg['ha']['entry_id'];
                    }
                    $ws->sendJson(['id' => $rid, 'type' => 'call_service', 'domain' => 'meshcore', 'service' => 'send_channel_message', 'service_data' => $data]);
                    $pending[$rid] = 'sq:' . $q['msg_id'];
                }
                foreach ($dm->tick($q ? 1 : 0, $sqWaiting) as $a) {
                    $rid = $nextId++;
                    $data = ['command' => $a['command']];
                    if (!empty($cfg['ha']['entry_id'])) {
                        $data['entry_id'] = $cfg['ha']['entry_id'];
                    }
                    $ws->sendJson([
                        'id' => $rid, 'type' => 'call_service', 'domain' => 'meshcore', 'service' => 'execute_command',
                        'service_data' => $data, 'return_response' => true,
                    ]);
                    $pending[$rid] = ($a['kind'] === 'reset' ? 'dmreset:' : 'dm:') . $a['qid'];
                    if ($verbose) {
                        logmsg('DM: ' . $a['command']);
                    }
                }
            } catch (Throwable $e) {
                logmsg('Chyba fronty odesílání: ' . $e->getMessage());
            }

            $now = time();
            if ($now - $lastPing >= $cfg['worker']['ping_interval']) {
                $ws->sendJson(['id' => $nextId++, 'type' => 'ping']);
                $lastPing = $now;
            }
            if ($now - $lastPong > $cfg['worker']['ping_interval'] * 2 + 10) {
                throw new RuntimeException('Home Assistant neodpovídá na ping');
            }
            if ($now - $lastSync >= $cfg['worker']['contacts_refresh']) {
                $requestSync();
                $lastSync = $now;
            }
            if ($now - $lastBeat >= 15) {
                $store->setState('worker_heartbeat', gmdate('c')); // zároveň udržuje DB spojení
                $lastBeat = $now;
            }
        }
        $ws->close();
    } catch (Throwable $e) {
        logmsg('Chyba: ' . $e->getMessage());
        $ws->close(false);
        try {
            $store->setState('worker_connected', '0');
            $store->setState('worker_last_error', mb_substr($e->getMessage(), 0, 500));
        } catch (Throwable) {
        }
        if (!$running) {
            break;
        }
        logmsg("Nový pokus za {$delay} s");
        for ($i = 0; $i < $delay && $running; $i++) {
            sleep(1);
        }
        $delay = min($delay * 2, (int) $cfg['worker']['reconnect_max']);
    }
}

try {
    $store->setState('worker_connected', '0');
} catch (Throwable) {
}
logmsg('Worker ukončen');

/** Čeká na zprávu splňující podmínku. */
function waitFor(WebSocketClient $ws, callable $match, int $timeout): array
{
    $deadline = time() + $timeout;
    while (time() < $deadline) {
        foreach ($ws->receive(1.0) as $raw) {
            $m = json_decode($raw, true);
            if (is_array($m) && $match($m)) {
                return $m;
            }
        }
    }
    throw new RuntimeException('Vypršel čas čekání na odpověď Home Assistantu');
}
