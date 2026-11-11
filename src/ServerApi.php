<?php
declare(strict_types=1);

namespace MeshClient;

use InvalidArgumentException;

/**
 * Akce webového API (public/api.php) v režimu backend = 'server'.
 *
 * handle() vrací [data, HTTP kód] pro akce, které v tomto režimu obsluhuje meshcore-server,
 * nebo null – pak akci zpracuje společný kód v api.php (čtení z DB klienta, nastavení, heard…).
 * Opakování DM, fronta odesílání a regiony kanálů jsou na serveru; klientské DmSender/SendQueue
 * se v tomto režimu nepoužívají.
 */
final class ServerApi
{
    /** Akce, které meshcore-server zatím neumí. */
    private const UNSUPPORTED = [
        'POST contact_import'  => 'Import vizitky',
        'POST path_discover'   => 'Zjištění cesty',
        'POST trace'           => 'Trace',
        'GET history'          => 'Historie senzorů',
    ];

    private McServer $srv;

    public function __construct(
        private readonly Db $db,
        private readonly array $cfg,
        private readonly Api $api,
    ) {
        $this->srv = McServer::fromConfig($cfg);
    }

    /** Funkce, které rozhraní v režimu server skryje (posílá se v init). */
    public static function capabilities(): array
    {
        return [
            'backend' => 'server', 'remote' => true, 'room_login' => true, 'trace' => false,
            'path_discover' => false, 'contact_import' => false, 'ha_devices' => false, 'traffic' => false,
            'region_none' => false,
        ];
    }

    /** @return array{0:array,1:int}|null */
    public function handle(string $route, array $input): ?array
    {
        if (isset(self::UNSUPPORTED[$route])) {
            return [['error' => self::UNSUPPORTED[$route] . ' zatím meshcore-server neumí.'], 400];
        }
        try {
            return match ($route) {
                'GET self_info'        => $this->selfInfo(),
                'GET card_lookup'      => $this->cardLookup((string) ($_GET['pk'] ?? '')),
                'POST contact_add'     => $this->contactAdd((string) ($input['pk'] ?? '')),
                'POST contact_remove'  => $this->contactRemove(is_array($input['pks'] ?? null) ? $input['pks'] : []),
                'POST path_reset'      => $this->pathReset((string) ($input['pk'] ?? '')),
                'POST advert'          => $this->advert(!empty($input['flood'])),
                'POST channel_add'     => $this->channelAdd($input),
                'POST channel_delete'  => $this->channelDelete((int) ($input['idx'] ?? -1)),
                'POST channel_secret'  => $this->channelSecret((int) ($input['idx'] ?? -1)),
                'POST conv_settings'   => $this->convSettings($input),
                'POST read'            => $this->read($input),
                'POST send'            => $this->send($input),
                'POST room_login'      => $this->roomLogin($input),
                'POST remote_status'   => $this->remoteStatus($input),
                'POST remote_cmd'      => $this->remoteCmd($input),
                default                => null,
            };
        } catch (McServerException $e) {
            $code = $e->getCode();
            return [['error' => $e->getMessage()], $code === 503 ? 503 : ($code === 0 ? 502 : 400)];
        }
    }

    // ------------------------------------------------------------------ akce

    private function selfInfo(): array
    {
        $dev = $this->srv->get('/device');
        $c = $dev['config'] ?? [];
        $lat = isset($c['adv_lat']) && is_numeric($c['adv_lat']) && (float) $c['adv_lat'] != 0.0 ? (float) $c['adv_lat'] : null;
        $lon = isset($c['adv_lon']) && is_numeric($c['adv_lon']) && (float) $c['adv_lon'] != 0.0 ? (float) $c['adv_lon'] : null;
        return [['self' => [
            'public_key' => strtolower((string) ($dev['public_key'] ?? '')),
            'name'       => (string) ($dev['name'] ?? ''),
            'type'       => (int) ($dev['adv_type'] ?? 1) ?: 1,
            'lat'        => $lat,
            'lon'        => $lon,
        ]], 200];
    }

    private function cardLookup(string $pk): array
    {
        $pk = strtolower($pk);
        if (!preg_match('/^[0-9a-f]{12,64}$/', $pk)) {
            throw new InvalidArgumentException('Neplatný klíč');
        }
        $pk12 = substr($pk, 0, 12);
        $known = $this->db->one('SELECT pubkey_prefix, name FROM contacts WHERE pubkey_prefix = ?', [$pk12]);
        $disc = null;
        $err = null;
        if (!$known) {
            try {
                $c = $this->srv->get('/contacts/' . $pk);
                $disc = [
                    'name'        => $c['name'] ?? null,
                    'type'        => isset($c['type']) ? (int) $c['type'] : null,
                    'last_advert' => $c['last_advert_at'] ?? null,
                    'path_len'    => isset($c['out_path_len']) ? (int) $c['out_path_len'] : null,
                ];
            } catch (McServerException $e) {
                $err = $e->getCode() === 404 ? 'Uzel zatím server neslyšel (žádný advert)' : $e->getMessage();
            }
        }
        return [['known' => $known ? ['conv' => 'd:' . $known['pubkey_prefix'], 'name' => $known['name']] : null,
                 'discovered' => $disc, 'discovered_error' => $err], 200];
    }

    private function contactAdd(string $pk): array
    {
        $pk12 = self::pk12($pk);
        try {
            $this->srv->post('/contacts/' . $pk12 . '/device');
        } catch (McServerException $e) {
            if ($e->getCode() === 404) {
                throw new InvalidArgumentException('Uzel zatím server neslyšel – kontakt jde přidat až po jeho advertu.');
            }
            throw $e;
        }
        $this->sync()->syncContacts();
        return [['ok' => true, 'conversations' => $this->api->conversations()], 200];
    }

    private function contactRemove(array $pks): array
    {
        $pks = array_values(array_unique(array_filter(array_map(
            static fn($p) => substr(strtolower((string) $p), 0, 12), $pks
        ), static fn($p) => (bool) preg_match('/^[0-9a-f]{12}$/', $p))));
        if (!$pks || count($pks) > 500) {
            throw new InvalidArgumentException('Vyber 1–500 kontaktů');
        }
        set_time_limit(300);
        $removed = $failed = 0;
        foreach ($pks as $pk) {
            try {
                $this->srv->delete('/contacts/' . $pk . '/device');
                $this->db->query('DELETE FROM contacts WHERE pubkey_prefix = ?', [$pk]);
                $removed++;
            } catch (McServerException $e) {
                if ($e->getCode() === 503) {
                    throw $e;
                }
                $failed++;
            }
        }
        $this->sync()->syncContacts();
        return [['ok' => true, 'removed' => $removed, 'failed' => $failed, 'conversations' => $this->api->conversations()], 200];
    }

    private function pathReset(string $pk): array
    {
        $r = $this->srv->post('/contacts/' . self::pk12($pk) . '/reset-path');
        $this->sync()->syncContacts();
        return [['ok' => true, 'result' => $r, 'conversations' => $this->api->conversations()], 200];
    }

    private function advert(bool $flood): array
    {
        $this->srv->post('/device/advert', ['flood' => $flood]);
        return [['ok' => true, 'flood' => $flood], 200];
    }

    private function channelAdd(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $secret = strtolower(trim((string) ($input['secret'] ?? '')));
        if ($name === '' || strlen($name) > 31 || preg_match('/[\x00-\x1f]/', $name)) {
            throw new InvalidArgumentException('Název kanálu musí mít 1–31 bajtů');
        }
        if ($secret !== '' && !preg_match('/^[0-9a-f]{32}$/', $secret)) {
            throw new InvalidArgumentException('Klíč musí mít 32 hex znaků (16 bajtů)');
        }
        if ($secret === '' && $name[0] !== '#') {
            throw new InvalidArgumentException('Kanál bez klíče musí být hashtagový (začínat #)');
        }
        $used = array_map('intval', array_column($this->srv->get('/channels')['items'] ?? [], 'idx'));
        $slots = (int) ($this->db->value("SELECT v FROM worker_state WHERE k = 'channel_slots'") ?: 8);
        $idx = null;
        for ($i = 0; $i < $slots; $i++) {
            if (!in_array($i, $used, true)) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            throw new InvalidArgumentException("Companion nemá volný slot pro kanál (obsazeno $slots)");
        }
        $this->srv->put('/channels/' . $idx, ['name' => $name] + ($secret !== '' ? ['secret' => $secret] : []));
        (new Retention($this->db))->deleteConversation('c:' . $idx);
        $this->db->query('DELETE FROM conv_settings WHERE conv_key = ?', ['c:' . $idx]);
        $this->sync()->syncChannels();
        return [['ok' => true, 'idx' => $idx, 'conversations' => $this->api->conversations()], 200];
    }

    private function channelDelete(int $idx): array
    {
        if ($idx < 0 || $idx > 255) {
            throw new InvalidArgumentException('Neplatný kanál');
        }
        $this->srv->delete('/channels/' . $idx);
        $this->db->query('DELETE FROM channels WHERE channel_idx = ?', [$idx]);
        (new Retention($this->db))->deleteConversation('c:' . $idx);
        $this->db->query('DELETE FROM conv_settings WHERE conv_key = ?', ['c:' . $idx]);
        $this->sync()->syncChannels();
        return [['ok' => true, 'conversations' => $this->api->conversations()], 200];
    }

    /** Klíč kanálu ke sdílení – server ho vydá jen v exportu (token s oprávněním admin). */
    private function channelSecret(int $idx): array
    {
        try {
            $exp = $this->srv->get('/export');
        } catch (McServerException $e) {
            if (in_array($e->getCode(), [401, 403], true)) {
                throw new InvalidArgumentException('Klíč kanálu vydá meshcore-server jen tokenu s oprávněním admin.');
            }
            throw $e;
        }
        foreach ($exp['channels'] ?? [] as $c) {
            if ((int) ($c['idx'] ?? -1) === $idx && is_string($c['secret'] ?? null) && preg_match('/^[0-9a-f]{32}$/i', $c['secret'])) {
                return [['secret' => strtolower($c['secret']), 'name' => $c['name'] ?? null], 200];
            }
        }
        throw new InvalidArgumentException('Klíč kanálu se nepodařilo načíst');
    }

    /** Region kanálu drží server (PATCH /channels/{idx}); ostatní nastavení zpracuje api.php. */
    private function convSettings(array $input): ?array
    {
        $conv = (string) ($input['conv'] ?? '');
        if (!array_key_exists('scope', $input) || !preg_match('/^c:(\d+)$/', $conv, $m)) {
            return null;
        }
        $scope = Settings::normScope($input['scope']);
        if ($scope === '*') {
            throw new InvalidArgumentException('„Bez regionu“ meshcore-server nepodporuje – zvol výchozí nebo konkrétní region.');
        }
        $this->srv->patch('/channels/' . (int) $m[1], ['region' => $scope === null ? null : '#' . $scope]);
        return null; // uložení do conv_settings (zobrazení) zařídí api.php
    }

    /** Přečteno: uložit v klientu (api.php) a poslat i na server, ať se počty srovnají na všech zařízeních. */
    private function read(array $input): ?array
    {
        $conv = (string) ($input['conv'] ?? '');
        if (Api::validConv($conv)) {
            try {
                ServerSync::pushRead($this->srv, $this->db, (string) ($this->cfg['server']['reader'] ?? 'klient'), $conv, (int) ($input['last_id'] ?? 0));
            } catch (McServerException) {
                // nevadí – přečtení platí aspoň lokálně
            }
        }
        return null;
    }

    private function send(array $input): array
    {
        $conv = (string) ($input['conv'] ?? '');
        $text = trim((string) ($input['text'] ?? ''));
        if (!Api::validConv($conv)) {
            throw new InvalidArgumentException('Neplatná konverzace');
        }
        if ($text === '') {
            throw new InvalidArgumentException('Prázdná zpráva');
        }
        $limit = Api::byteLimit($conv, $this->api->selfName($this->cfg));
        if (strlen($text) > $limit) {
            throw new InvalidArgumentException('Zpráva má ' . strlen($text) . ' B, limit je ' . $limit . ' B.');
        }
        [$t, $to] = explode(':', $conv, 2);
        // „odeslat znovu“ – připojit novou zprávu k původní (MessageStore::linkDuplicate)
        $resendOf = (int) ($input['resend_of'] ?? 0);
        if ($resendOf > 0 && $this->db->value("SELECT id FROM messages WHERE id = ? AND conv_key = ? AND direction = 'out'", [$resendOf, $conv])) {
            $this->db->query('INSERT INTO worker_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP(6)',
                [MessageStore::resendKey($conv, $text), (string) $resendOf]);
        }
        $r = $this->srv->post('/messages', ['kind' => $t === 'c' ? 'channel' : 'dm', 'to' => $t === 'c' ? (int) $to : $to, 'text' => $text]);
        // zprávu uložit hned (worker ji dostane i přes WebSocket – podle srv_id se nezdvojí)
        $id = is_array($r['message'] ?? null) ? $this->sync()->upsertMessage($r['message']) : null;
        return [['ok' => true, 'id' => $id], 200];
    }

    // ------------------------------------------------------------------ room servery a vzdálená správa

    /**
     * Přihlášení k room serveru / repeateru přes meshcore-server (čeká na odpověď uzlu).
     * Prázdné heslo = host. $remember: server si heslo uloží a po výpadku se přihlásí sám.
     * @return array{pk:string, admin:?bool, added:bool}
     */
    private function login(string $pk, string $pw, bool $remember): array
    {
        $pk12 = self::pk12($pk);
        if (strlen($pw) > 15 || preg_match('/[\x00-\x1f]/', $pw)) {
            throw new InvalidArgumentException('Heslo může mít nejvýš 15 bajtů');
        }
        $onDevice = (bool) $this->db->value('SELECT 1 FROM contacts WHERE pubkey_prefix = ? AND public_key IS NOT NULL', [$pk12]);
        try {
            $r = $this->srv->post('/contacts/' . $pk12 . '/login', ['password' => $pw, 'remember' => $remember], 120);
        } catch (McServerException $e) {
            if ($e->getCode() === 404) {
                throw new InvalidArgumentException('Uzel zatím server neslyšel – přihlásit se jde až po jeho advertu.');
            }
            if ($e->getCode() === 409 || $e->getCode() === 400) {
                $msg = $e->getMessage();
                throw new InvalidArgumentException(str_contains($msg, 'neodpověděl')
                    ? 'Uzel na přihlášení neodpověděl – je mimo dosah, nebo je síť vytížená.'
                    : (str_contains($msg, 'heslo') ? 'Nesprávné heslo.' . ($pw === '' ? ' Bez hesla to projde jen u uzlu, který povoluje hosty.' : '')
                        : 'Přihlášení k uzlu se nezdařilo: ' . $msg));
            }
            throw $e;
        }
        if (!$onDevice) {
            $this->sync()->syncContacts(); // server uzel kvůli přihlášení přidal do companionu
        }
        return ['pk' => $pk12, 'admin' => isset($r['is_admin']) ? (bool) $r['is_admin'] : null, 'added' => !$onDevice];
    }

    private function roomLogin(array $input): array
    {
        // heslo si uloží server – po restartu nebo výpadku companionu se k roomu přihlásí sám
        $rl = $this->login((string) ($input['pk'] ?? ''), (string) ($input['password'] ?? ''), ($input['remember'] ?? true) !== false);
        return [['ok' => true, 'admin' => $rl['admin'], 'added' => $rl['added']], 200];
    }

    private function remoteStatus(array $input): array
    {
        set_time_limit(240);
        $rl = $this->login((string) ($input['pk'] ?? ''), (string) ($input['password'] ?? ''), false);
        try {
            $r = $this->srv->post('/contacts/' . $rl['pk'] . '/status', null, 120);
        } catch (McServerException $e) {
            return [['error' => str_contains($e->getMessage(), 'neodpověděl') || $e->getCode() === 504
                ? 'Uzel na žádost o stav neodpověděl (mimo dosah nebo vytížená síť).'
                : 'Stav se nepodařilo získat: ' . $e->getMessage(), 'admin' => $rl['admin'], 'added' => $rl['added']], 400];
        }
        $status = array_intersect_key((array) ($r['status'] ?? []), array_flip(['bat', 'tx_queue_len', 'noise_floor', 'last_rssi', 'last_snr',
            'nb_recv', 'nb_sent', 'airtime', 'rx_airtime', 'uptime', 'sent_flood', 'sent_direct', 'recv_flood', 'recv_direct', 'full_evts',
            'direct_dups', 'flood_dups', 'recv_errors']));
        return [['ok' => true, 'status' => $status, 'admin' => $rl['admin'], 'added' => $rl['added'], 'at' => gmdate('c')], 200];
    }

    private function remoteCmd(array $input): array
    {
        set_time_limit(240);
        $cmd = (string) ($input['cmd'] ?? '');
        // povolené příkazy a jejich CLI řádky jsou společné s HA režimem (public/api.php)
        $plan = \remoteCommands($cmd, $input['arg'] ?? null);
        $rl = $this->login((string) ($input['pk'] ?? ''), (string) ($input['password'] ?? ''), false);
        $replies = [];
        foreach ($plan['cli'] as $line) {
            try {
                $r = $this->srv->post('/contacts/' . $rl['pk'] . '/cli', ['command' => $line], 120);
                $t = trim((string) ($r['reply'] ?? ''));
                if ($t !== '') {
                    $replies[] = $t;
                }
            } catch (McServerException $e) {
                if ($cmd === 'reboot') {
                    break; // restartovaný uzel už neodpoví
                }
                if (in_array($e->getCode(), [401, 403], true)) {
                    throw new InvalidArgumentException('Příkazy na uzlu vyžadují token meshcore-serveru s oprávněním admin.');
                }
                return [['error' => 'Příkaz se nepodařilo provést: ' . $e->getMessage(), 'admin' => $rl['admin'], 'added' => $rl['added']], 400];
            }
        }
        return [['ok' => true, 'what' => $plan['what'], 'admin' => $rl['admin'], 'added' => $rl['added'],
                 'reply' => $replies ? implode(' · ', $replies) : null], 200];
    }

    // ------------------------------------------------------------------ pomocné

    private function sync(): ServerSync
    {
        return new ServerSync($this->db, $this->srv, new MessageStore($this->db, (int) ($this->cfg['app']['dedup_window'] ?? 1800)),
            (string) ($this->cfg['server']['reader'] ?? 'klient'));
    }

    private static function pk12(string $pk): string
    {
        $pk12 = substr(strtolower($pk), 0, 12);
        if (!preg_match('/^[0-9a-f]{12}$/', $pk12)) {
            throw new InvalidArgumentException('Neplatný klíč');
        }
        return $pk12;
    }
}
