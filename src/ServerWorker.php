<?php
declare(strict_types=1);

namespace MeshClient;

use RuntimeException;
use Throwable;

/**
 * Worker pro backend = 'server': drží WebSocket s meshcore-serverem a zrcadlí data do DB klienta.
 *
 * - Po připojení se pokračuje od posledního zpracovaného seq (worker_state.srv_seq) – server pošle zmeškané události.
 * - Při prvním běhu nebo po {"type":"resync.required"} se vše načte znovu přes REST (ServerSync::fullSync).
 * - Odesílání, opakování DM, fronta a regiony kanálů řeší server – klientské DmSender/SendQueue se tu nepoužívají.
 */
final class ServerWorker
{
    private bool $running = true;

    public function __construct(
        private readonly array $cfg,
        private readonly MessageStore $store,
        private readonly bool $verbose = false,
    ) {
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function run(): void
    {
        $srv  = McServer::fromConfig($this->cfg);
        $sc   = $this->cfg['server'];
        $sync = new ServerSync(db(), $srv, $this->store, (string) ($sc['reader'] ?? 'klient'));
        $delay = 1;
        // oprava starších záznamů: 255 = směrovaná cesta, ne počet skoků
        $this->db()->query('UPDATE messages SET hop_count = NULL, rx_log = NULL, repeater_count = 0
            WHERE srv_id IS NOT NULL AND direction = \'in\' AND hop_count >= 255');

        while ($this->running) {
            $seq = $this->db()->value("SELECT v FROM worker_state WHERE k = 'srv_seq'");
            $since = is_numeric($seq) ? (int) $seq : null;
            $ws = new WebSocketClient($srv->wsUrl($since), $srv->verifyTls());
            try {
                $this->log('Připojuji se k meshcore-serveru ' . preg_replace('/token=[^&]+/', 'token=…', $srv->wsUrl($since)));
                $ws->connect();
                $hello = $this->waitFor($ws, static fn(array $m) => ($m['type'] ?? '') === 'hello', 10);
                $this->log('Připojeno k meshcore-serveru ' . ($hello['server'] ?? '') . ', seq ' . ($hello['seq'] ?? '?'));

                // první běh (bez seq): nejdřív vše přes REST, pak živé události od aktuálního seq
                if ($since === null) {
                    $sync->fullSync((int) ($sc['backfill_days'] ?? 30), fn($t) => $this->log($t));
                    $this->setSeq((int) ($hello['seq'] ?? 0));
                } else {
                    // stav companionu, kontakty a kanály se po výpadku obnoví vždy (levné)
                    $sync->syncDevice();
                    $sync->syncContacts();
                    $sync->syncChannels();
                }
                $this->store->setState('worker_connected', '1');
                $this->store->setState('worker_started_at', gmdate('c'));
                $this->store->setState('worker_last_error', null);
                $delay = 1;

                $lastPing = $lastPong = time();
                $lastBeat = 0;
                $lastContacts = time();
                $ping = (int) ($this->cfg['worker']['ping_interval'] ?? 30);

                while ($this->running) {
                    foreach ($ws->receive(1.0) as $raw) {
                        $m = json_decode($raw, true);
                        if (!is_array($m)) {
                            continue;
                        }
                        $type = (string) ($m['type'] ?? '');
                        if ($type === 'pong') {
                            $lastPong = time();
                            continue;
                        }
                        if ($type === 'resync.required') {
                            $this->log('Server už nemá zmeškané události – načítám vše znovu');
                            $sync->fullSync((int) ($sc['backfill_days'] ?? 30), fn($t) => $this->log($t));
                            $this->setSeq((int) ($m['seq'] ?? 0));
                            continue;
                        }
                        if (!isset($m['seq'])) {
                            continue;
                        }
                        try {
                            $this->handle($sync, $type, $m['data'] ?? null);
                        } catch (Throwable $e) {
                            $this->log("Chyba při zpracování $type: " . $e->getMessage());
                        }
                        $this->setSeq((int) $m['seq']);
                        $this->store->setState('last_event_at', gmdate('c'));
                    }

                    $now = time();
                    if ($now - $lastPing >= $ping) {
                        $ws->sendJson(['type' => 'ping']);
                        $lastPing = $now;
                    }
                    if ($now - $lastPong > $ping * 2 + 10) {
                        throw new RuntimeException('meshcore-server neodpovídá na ping');
                    }
                    // seznam slyšených uzlů (kontakty mimo companion) se obnovuje průběžně
                    $dirty = $this->db()->value("SELECT v FROM worker_state WHERE k = 'srv_contacts_dirty'") === '1';
                    if (($dirty && $now - $lastContacts >= 30) || $now - $lastContacts >= (int) ($this->cfg['worker']['contacts_refresh'] ?? 600)) {
                        $sync->syncContacts();
                        $this->store->setState('srv_contacts_dirty', '0');
                        $lastContacts = $now;
                    }
                    if ($now - $lastBeat >= 15) {
                        $this->store->setState('worker_heartbeat', gmdate('c'));
                        $lastBeat = $now;
                    }
                }
                $ws->close();
            } catch (Throwable $e) {
                $this->log('Chyba: ' . $e->getMessage());
                $ws->close(false);
                try {
                    $this->store->setState('worker_connected', '0');
                    $this->store->setState('companion_connected', '0');
                    $this->store->setState('worker_last_error', mb_substr($e->getMessage(), 0, 500));
                } catch (Throwable) {
                }
                if (!$this->running) {
                    break;
                }
                $this->log("Nový pokus za {$delay} s");
                for ($i = 0; $i < $delay && $this->running; $i++) {
                    sleep(1);
                }
                $delay = min($delay * 2, (int) ($this->cfg['worker']['reconnect_max'] ?? 60));
            }
        }
        try {
            $this->store->setState('worker_connected', '0');
        } catch (Throwable) {
        }
    }

    private function handle(ServerSync $sync, string $type, mixed $data): void
    {
        if ($this->verbose) {
            $this->log("Událost $type: " . mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 400));
        }
        if (!is_array($data)) {
            return;
        }
        switch ($type) {
            case 'message.received':
            case 'message.created':
                $sync->upsertMessage($data);
                break;
            case 'message.status':
                $sync->applyStatus($data);
                break;
            case 'contact.updated':
                $sync->onContact($data);
                break;
            case 'contact.deleted':
                $sync->onContactDeleted($data);
                break;
            case 'contacts.synced':
                $sync->syncContacts();
                break;
            case 'channels.updated':
                $sync->syncChannels($data);
                break;
            case 'read.updated':
                $sync->onRead($data);
                break;
            case 'device.updated':
            case 'device.connection':
                $sync->syncDevice();
                break;
            case 'advert.received':
                // nový uzel se objeví mezi slyšenými při další synchronizaci kontaktů
                $this->store->setState('srv_contacts_dirty', '1');
                break;
        }
    }

    private function setSeq(int $seq): void
    {
        $this->store->setState('srv_seq', (string) $seq);
    }

    private function db(): Db
    {
        return db();
    }

    private function waitFor(WebSocketClient $ws, callable $match, int $timeout): array
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
        throw new RuntimeException('meshcore-server neodpověděl (hello)');
    }

    private function log(string $msg): void
    {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] $msg\n");
    }
}
