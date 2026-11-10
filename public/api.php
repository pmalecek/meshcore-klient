<?php
declare(strict_types=1);

use MeshClient\Analyzer;
use MeshClient\Api;
use MeshClient\Auth;
use MeshClient\BleStore;
use MeshClient\DmSender;
use MeshClient\SendQueue;
use MeshClient\HaRest;
use MeshClient\History;
use MeshClient\MessageStore;
use MeshClient\Retention;
use MeshClient\Settings;
use MeshClient\TrafficBudget;

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** entry_id pro služby HA, pokud je nastavený */
/**
 * Hex -> Python bytes literál pro execute_command ve tvaru funkce (set_channel(…) čeká klíč jako 16 bajtů,
 * řetězec hexu by knihovna meshcore odmítla výjimkou).
 */
function pyBytes(string $hex): string
{
    return 'b"' . implode('', array_map(fn($b) => '\\x' . $b, str_split(strtolower($hex), 2))) . '"';
}

function haEntry(array $cfg): array
{
    return !empty($cfg['ha']['entry_id']) ? ['entry_id' => $cfg['ha']['entry_id']] : [];
}

/**
 * Přihlášení k repeateru / room serveru přes companion. Uzel, který companion nezná
 * (jen „slyšený“), nejdřív přidá do kontaktů companionu – bez toho se přihlásit nedá.
 * Prázdné heslo = host (nebo admin, pokud je companion v ACL uzlu).
 * @return array{pk: string, admin: ?bool, added: bool}
 */
function remoteLogin(HaRest $ha, array $cfg, string $pk, string $pw): array
{
    $pk12 = substr(strtolower($pk), 0, 12);
    if (!preg_match('/^[0-9a-f]{10,12}$/', $pk12)) {
        out(['error' => 'Neplatný klíč uzlu'], 400);
    }
    $known = db()->value('SELECT pubkey_prefix FROM contacts WHERE pubkey_prefix LIKE ? LIMIT 1', [$pk12 . '%']);
    $added = false;
    if (!$known) {
        if (strlen($pk12) < 12) {
            out(['error' => 'Uzel není v kontaktech companionu'], 400);
        }
        $r = $ha->callService('meshcore', 'execute_command', ['command' => 'add_contact ' . $pk12] + haEntry($cfg), true);
        if (isset($r['error'])) {
            out(['error' => 'Uzel se nepodařilo přidat do kontaktů companionu: ' . ($r['detail'] ?? $r['error'])], 400);
        }
        $contacts = $ha->callService('meshcore', 'get_contacts', haEntry($cfg), true);
        (new MessageStore(db()))->syncContacts($contacts);
        $known = $pk12;
        $added = true;
    }
    $q = static fn(string $v): string => json_encode($v, JSON_UNESCAPED_UNICODE);
    $login = $ha->callService('meshcore', 'execute_command',
        ['command' => 'send_login_sync(' . $q($known) . ', ' . $q($pw) . ')'] + haEntry($cfg), true, 100);
    $ev = strtolower((string) ($login['event'] ?? $login['type'] ?? ''));
    if (!is_array($login) || isset($login['error']) || str_contains($ev, 'fail')) {
        $e = is_array($login) ? (string) ($login['error'] ?? $ev) : '';
        out(['error' => ($e === 'no_response'
                ? 'Uzel na přihlášení neodpověděl – špatné heslo, nebo je mimo dosah.'
                : 'Přihlášení k uzlu se nezdařilo' . ($e !== '' ? ': ' . $e : '') . '.')
            . ($pw === '' ? ' Bez hesla to projde jen u uzlu, který povoluje hosty (nebo má companion v ACL).' : ''),
            'added' => $added], 400);
    }
    $admin = $login['is_admin'] ?? (isset($login['permissions']) ? (((int) $login['permissions']) & 1) === 1 : null);
    return ['pk' => $known, 'admin' => $admin === null ? null : (bool) $admin, 'added' => $added];
}

/**
 * Povolené vzdálené příkazy (CLI repeateru / room serveru).
 * @return array{what: string, cli: list<string>}
 */
function remoteCommands(string $cmd, mixed $arg): array
{
    switch ($cmd) {
        case 'advert':         return ['what' => 'flood advert', 'cli' => ['advert']];
        case 'advert.zerohop': return ['what' => 'lokální advert', 'cli' => ['advert.zerohop']];
        case 'ver':            return ['what' => 'verze firmwaru', 'cli' => ['ver']];
        case 'clock sync':     return ['what' => 'synchronizace času', 'cli' => ['clock sync']];
        case 'reboot':         return ['what' => 'restart', 'cli' => ['reboot']];
        case 'name':
            $name = trim((string) $arg);
            if ($name === '' || strlen($name) > 31 || preg_match('/[\x00-\x1f]/', $name)) {
                out(['error' => 'Jméno musí mít 1–31 bajtů'], 400);
            }
            return ['what' => 'přejmenování (' . $name . ')', 'cli' => ['set name ' . $name]];
        case 'latlon':
            $lat = is_array($arg) ? ($arg['lat'] ?? null) : null;
            $lon = is_array($arg) ? ($arg['lon'] ?? null) : null;
            if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
                out(['error' => 'Neplatná poloha'], 400);
            }
            return ['what' => 'nastavení polohy', 'cli' => [sprintf('set lat %.6f', (float) $lat), sprintf('set lon %.6f', (float) $lon)]];
    }
    out(['error' => 'Neplatný příkaz'], 400);
}

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg  = app_config();
$auth = new Auth(db(), $cfg['auth']);
$auth->startSession();

if ($auth->userId() === null) {
    out(['error' => 'Nepřihlášeno'], 401);
}

$api      = new Api(db());
$settings = new Settings(db(), $cfg['app']['bg_dir']);
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    if (!$auth->checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf'] ?? null))) {
        out(['error' => 'Neplatný CSRF token, obnov stránku.'], 403);
    }
}

try {
    switch ("$method $action") {
        case 'GET state':
            out(['state' => $api->state()]);

        case 'GET init':
            out([
                'state'         => $api->state(),
                'conversations' => $api->conversations(),
                'cursor'        => $api->currentCursor(),
                'max_len'       => (int) $cfg['app']['message_max_len'],
                'settings'      => $settings->all(),
                'patterns'      => Settings::PATTERNS,
                'analyzer'      => !empty($cfg['analyzer']['enabled']) && !empty($cfg['analyzer']['url']),
                'home'          => $api->home($cfg),
                'self_name'     => $api->selfName($cfg),
            ]);

        case 'GET account':
            out(['account' => $auth->account() + ['username' => $auth->username(), 'min' => Auth::PASSWORD_MIN]]);

        case 'POST password':
            $remove = !empty($input['remove']);
            $new = $remove ? null : (string) ($input['new'] ?? '');
            $err = $auth->setPassword((string) ($input['current'] ?? ''), $new, $_SERVER['REMOTE_ADDR'] ?? '');
            if ($err !== null) {
                out(['error' => $err], 400);
            }
            out(['ok' => true, 'account' => $auth->account(), 'csrf' => $auth->csrf()]);

        // ---- Bluetooth klient (/ble): zrcadlení historie a dotazy na analyzer ----
        case 'GET ble_devices':
            out(['devices' => (new BleStore(db()))->devices()]);

        case 'GET ble_pull':
            session_write_close();
            out((new BleStore(db()))->pull(BleStore::device($_GET['device'] ?? ''), (string) ($_GET['since'] ?? '')));

        case 'POST ble_push':
            session_write_close();
            $n = (new BleStore(db()))->push(
                BleStore::device($input['device'] ?? ''),
                isset($input['name']) && is_string($input['name']) ? $input['name'] : null,
                is_array($input['messages'] ?? null) ? $input['messages'] : [],
                is_array($input['deleted'] ?? null) ? $input['deleted'] : [],
                is_array($input['kv'] ?? null) ? $input['kv'] : []
            );
            out(['ok' => true, 'saved' => $n]);

        case 'POST ble_heard':
            session_write_close();
            $an = new Analyzer(db(), $cfg['analyzer']);
            if (!$an->enabled()) {
                out(['error' => 'Kontrola přes analyzer je vypnutá'], 400);
            }
            out($an->lookup(
                mb_substr((string) ($input['channel'] ?? ''), 0, 64),
                (string) ($input['body'] ?? ''),
                (int) ($input['ts'] ?? 0),
                mb_substr((string) ($input['sender'] ?? ''), 0, 64),
                isset($input['hash']) && is_string($input['hash']) ? strtolower($input['hash']) : null,
                is_array($input['exclude'] ?? null) ? array_slice($input['exclude'], 0, 500) : [],
                !empty($input['final']),
                is_array($input['siblings'] ?? null) ? $input['siblings'] : []
            ));

        case 'GET self_info':
            // vlastní companion (veřejný klíč, jméno, poloha) – pro vizitku a sdílení polohy; cache 10 min
            session_write_close();
            $row = db()->one("SELECT v, updated_at FROM worker_state WHERE k = 'self_info'");
            $info = $row ? json_decode((string) $row['v'], true) : null;
            if (!is_array($info) || strtotime($row['updated_at'] . ' UTC') < time() - 600 || !empty($_GET['fresh'])) {
                $r = HaRest::fromConfig($cfg['ha'])->callService('meshcore', 'execute_command', ['command' => 'send_appstart'] + haEntry($cfg), true);
                if (is_array($r) && !empty($r['public_key']) && preg_match('/^[0-9a-f]{64}$/i', (string) $r['public_key'])) {
                    $info = [
                        'public_key' => strtolower((string) $r['public_key']),
                        'name'       => (string) ($r['name'] ?? ''),
                        'type'       => (int) ($r['adv_type'] ?? 1) ?: 1,
                        'lat'        => isset($r['adv_lat']) && is_numeric($r['adv_lat']) && (float) $r['adv_lat'] != 0.0 ? (float) $r['adv_lat'] : null,
                        'lon'        => isset($r['adv_lon']) && is_numeric($r['adv_lon']) && (float) $r['adv_lon'] != 0.0 ? (float) $r['adv_lon'] : null,
                    ];
                    db()->query("INSERT INTO worker_state (k, v) VALUES ('self_info', ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP(6)",
                        [json_encode($info, JSON_UNESCAPED_UNICODE)]);
                } elseif (!is_array($info)) {
                    out(['error' => 'Údaje o companionu se nepodařilo načíst' . (isset($r['error']) ? ': ' . $r['error'] : '')], 400);
                }
            }
            out(['self' => $info]);

        case 'GET settings':
            out(['settings' => $settings->all()]);

        case 'POST conv_settings':
            $conv = (string) ($input['conv'] ?? '');
            if (!Api::validConv($conv)) {
                out(['error' => 'Neplatná konverzace'], 400);
            }
            $settings->updateConv($conv, array_intersect_key($input, array_flip(['color', 'bg', 'fav', 'retention'])));
            if (array_key_exists('retention', $input)) {
                (new Retention(db()))->cleanup($settings->retention(), true);
            }
            out(['settings' => $settings->all()]);

        case 'POST default_bg':
            $settings->setDefaultBg($input['bg'] ?? null);
            out(['settings' => $settings->all()]);

        case 'GET bg_list':
            out(['images' => $settings->images()]);

        case 'POST bg_upload':
            if (empty($_FILES['image'])) {
                out(['error' => 'Chybí soubor (možná překročil upload_max_filesize)'], 400);
            }
            out(['image' => $settings->upload($_FILES['image']), 'images' => $settings->images()]);

        case 'POST bg_delete':
            $settings->deleteImage((string) ($input['image'] ?? ''));
            out(['images' => $settings->images(), 'settings' => $settings->all()]);

        case 'GET history':
            session_write_close();
            $history = new History($cfg['ha']);
            out(['history' => $history->fetch((string) ($_GET['entity'] ?? ''), (string) ($_GET['range'] ?? '24h'))]);

        case 'GET devices':
            out(['devices' => $api->devices()]);

        case 'GET conversations':
            out(['conversations' => $api->conversations()]);

        case 'GET messages':
            $conv = (string) ($_GET['conv'] ?? '');
            if (!Api::validConv($conv)) {
                out(['error' => 'Neplatná konverzace'], 400);
            }
            $before = isset($_GET['before']) ? (int) $_GET['before'] : null;
            $hideDups = ($_GET['dups'] ?? 'hide') !== 'show';
            out(['messages' => $api->messages($conv, $before, (int) ($_GET['limit'] ?? 50), $hideDups)]);

        case 'GET nicks':
            $conv = (string) ($_GET['conv'] ?? '');
            if (!Api::validConv($conv)) {
                out(['error' => 'Neplatná konverzace'], 400);
            }
            out(['nicks' => $api->nicks($conv)]);

        case 'GET heard':
            $id = (int) ($_GET['id'] ?? 0);
            out(['message' => $api->message($id), 'observers' => $api->heardObservers($id),
                 'analyzer' => rtrim((string) $cfg['analyzer']['url'], '/')]);

        case 'POST heard_check':
            session_write_close();
            $an = new Analyzer(db(), $cfg['analyzer']);
            if (!$an->enabled()) {
                out(['error' => 'Kontrola přes analyzer je vypnutá'], 400);
            }
            $id = (int) ($input['id'] ?? 0);
            $an->checkNow($id);
            out(['message' => $api->message($id), 'observers' => $api->heardObservers($id)]);

        case 'GET card_lookup':
            $pk = strtolower((string) ($_GET['pk'] ?? ''));
            if (!preg_match('/^[0-9a-f]{12,64}$/', $pk)) {
                out(['error' => 'Neplatný klíč'], 400);
            }
            $pk12 = substr($pk, 0, 12);
            $known = db()->one('SELECT pubkey_prefix, name, type, last_advert, out_path_len FROM contacts WHERE pubkey_prefix = ?', [$pk12]);
            $disc = null;
            $discError = null;
            if (!$known) {
                $ha = HaRest::fromConfig($cfg['ha']);
                $r = $ha->callService('meshcore', 'get_discovered_contact', ['pubkey_prefix' => $pk12] + haEntry($cfg), true);
                $c = $r['contact'] ?? null;
                if (is_array($c)) {
                    $adv = $c['last_advert'] ?? null;
                    $disc = [
                        'name'        => $c['adv_name'] ?? $c['name'] ?? null,
                        'type'        => isset($c['type']) ? (int) $c['type'] : null,
                        'last_advert' => is_numeric($adv) && $adv > 0 ? gmdate('c', (int) $adv) : null,
                        'path_len'    => isset($c['out_path_len']) ? (int) $c['out_path_len'] : null,
                    ];
                } else {
                    $discError = $r['error'] ?? null;
                }
            }
            out(['known' => $known ? ['conv' => 'd:' . $known['pubkey_prefix'], 'name' => $known['name']] : null,
                 'discovered' => $disc, 'discovered_error' => $discError]);

        case 'GET discovered':
            out(['discovered' => $api->discovered()]);

        case 'POST contact_import':
            $hex = strtolower(preg_replace('/\s+/', '', (string) ($input['card'] ?? '')));
            if (!preg_match('/^[0-9a-f]{100,1000}$/', $hex) || strlen($hex) % 2) {
                out(['error' => 'Karta kontaktu musí být hex řetězec (export z MeshCore)'], 400);
            }
            $ha = HaRest::fromConfig($cfg['ha']);
            $r = $ha->callService('meshcore', 'execute_command', ['command' => 'import_contact ' . $hex] + haEntry($cfg), true);
            if (isset($r['error'])) {
                out(['error' => 'Companion kartu nepřijal: ' . $r['error']], 400);
            }
            $contacts = $ha->callService('meshcore', 'get_contacts', haEntry($cfg), true);
            (new MessageStore(db()))->syncContacts($contacts);
            out(['ok' => true, 'conversations' => $api->conversations()]);

        case 'POST path_reset':
        case 'POST path_discover':
            session_write_close();
            set_time_limit(120);
            $pk12 = substr(strtolower((string) ($input['pk'] ?? '')), 0, 12);
            if (!preg_match('/^[0-9a-f]{12}$/', $pk12)) {
                out(['error' => 'Neplatný klíč'], 400);
            }
            $ha = HaRest::fromConfig($cfg['ha']);
            $cmd = ($action === 'path_reset' ? 'reset_path ' : 'send_path_discovery_sync ') . $pk12;
            $r = $ha->callService('meshcore', 'execute_command', ['command' => $cmd] + haEntry($cfg), true, 100);
            if (isset($r['error'])) {
                out(['error' => ($action === 'path_reset' ? 'Cestu se nepodařilo resetovat: ' : 'Cestu se nepodařilo zjistit: ') . $r['error']], 400);
            }
            $contacts = $ha->callService('meshcore', 'get_contacts', haEntry($cfg), true);
            (new MessageStore(db()))->syncContacts($contacts);
            out(['ok' => true, 'result' => $r, 'conversations' => $api->conversations()]);

        case 'POST room_login':
            // přihlášení do room serveru – server pak pošle příspěvky, které jsme ještě neviděli
            session_write_close();
            set_time_limit(120);
            $pw = (string) ($input['password'] ?? '');
            if (strlen($pw) > 64 || preg_match('/[\x00-\x1f]/', $pw)) {
                out(['error' => 'Neplatné heslo'], 400);
            }
            $rl = remoteLogin(HaRest::fromConfig($cfg['ha']), $cfg, (string) ($input['pk'] ?? ''), $pw);
            out(['ok' => true, 'admin' => $rl['admin'], 'added' => $rl['added']]);

        case 'POST remote_cmd':
        case 'POST remote_status':
            // vzdálená správa repeateru / room serveru: přihlášení (host bez hesla, admin s heslem) + stav nebo příkaz
            session_write_close();
            set_time_limit(240);
            $cmd = (string) ($input['cmd'] ?? '');
            $plan = $action === 'remote_cmd' ? remoteCommands($cmd, $input['arg'] ?? null) : null;
            $pw = (string) ($input['password'] ?? '');
            if (strlen($pw) > 64 || preg_match('/[\x00-\x1f]/', $pw)) {
                out(['error' => 'Neplatné heslo'], 400);
            }
            $ha = HaRest::fromConfig($cfg['ha']);
            $rl = remoteLogin($ha, $cfg, (string) ($input['pk'] ?? ''), $pw);
            $q = static fn(string $v): string => json_encode($v, JSON_UNESCAPED_UNICODE);
            if ($action === 'remote_status') {
                $r = $ha->callService('meshcore', 'execute_command',
                    ['command' => 'req_status_sync(' . $q($rl['pk']) . ', min_timeout=15)'] + haEntry($cfg), true, 120);
                if (!is_array($r) || isset($r['error']) || !isset($r['uptime'])) {
                    $e = is_array($r) ? ($r['error'] ?? '') : '';
                    out(['error' => $e === 'no_response' ? 'Uzel na žádost o stav neodpověděl (mimo dosah nebo vytížená síť).'
                        : 'Stav se nepodařilo získat' . ($e !== '' ? ': ' . $e : '') . '.', 'admin' => $rl['admin'], 'added' => $rl['added']], 400);
                }
                $status = array_intersect_key($r, array_flip(['bat', 'tx_queue_len', 'noise_floor', 'last_rssi', 'last_snr', 'nb_recv', 'nb_sent',
                    'airtime', 'rx_airtime', 'uptime', 'sent_flood', 'sent_direct', 'recv_flood', 'recv_direct', 'full_evts',
                    'direct_dups', 'flood_dups', 'recv_errors']));
                out(['ok' => true, 'status' => $status, 'admin' => $rl['admin'], 'added' => $rl['added'], 'at' => gmdate('c')]);
            }
            // odpověď repeateru dorazí jako zpráva od uzlu (worker ji uloží) – počkáme na ni
            $conv = 'd:' . substr($rl['pk'], 0, 12);
            $since = (int) (db()->value('SELECT MAX(id) FROM messages') ?? 0);
            foreach ($plan['cli'] as $line) {
                $r = $ha->callService('meshcore', 'execute_command',
                    ['command' => 'send_cmd(' . $q($rl['pk']) . ', ' . $q($line) . ')'] + haEntry($cfg), true, 60);
                if (isset($r['error'])) {
                    out(['error' => 'Příkaz se nepodařilo odeslat: ' . $r['error']], 400);
                }
                TrafficBudget::spend(db()); // send_cmd jde z kreditu zpráv
            }
            $reply = null;
            if ($cmd !== 'reboot') {
                $deadline = time() + 15;
                while (time() < $deadline) {
                    $rows = db()->all("SELECT body FROM messages WHERE conv_key = ? AND direction = 'in' AND id > ? ORDER BY id", [$conv, $since]);
                    if (count($rows) >= count($plan['cli'])) {
                        $reply = implode(' · ', array_column($rows, 'body'));
                        break;
                    }
                    usleep(700_000);
                }
                if ($reply === null) {
                    $rows = db()->all("SELECT body FROM messages WHERE conv_key = ? AND direction = 'in' AND id > ? ORDER BY id", [$conv, $since]);
                    $reply = $rows ? implode(' · ', array_column($rows, 'body')) : null;
                }
            }
            out(['ok' => true, 'what' => $plan['what'], 'admin' => $rl['admin'], 'added' => $rl['added'], 'reply' => $reply]);

        case 'POST contact_remove':
            // úklid: odebrání kontaktů z companionu (historie zpráv zůstává)
            session_write_close();
            set_time_limit(300);
            $pks = array_values(array_unique(array_filter(array_map(
                static fn($p) => substr(strtolower((string) $p), 0, 12),
                is_array($input['pks'] ?? null) ? $input['pks'] : []
            ), static fn($p) => (bool) preg_match('/^[0-9a-f]{12}$/', $p))));
            if (!$pks || count($pks) > 500) {
                out(['error' => 'Vyber 1–500 kontaktů'], 400);
            }
            $ha = HaRest::fromConfig($cfg['ha']);
            $removed = 0;
            $failed = 0;
            foreach ($pks as $pk) {
                $r = $ha->callService('meshcore', 'execute_command', ['command' => 'remove_contact ' . $pk] + haEntry($cfg), true);
                if (isset($r['error'])) {
                    $failed++;
                    continue;
                }
                db()->query('DELETE FROM contacts WHERE pubkey_prefix = ?', [$pk]);
                $removed++;
            }
            $contacts = $ha->callService('meshcore', 'get_contacts', haEntry($cfg), true);
            (new MessageStore(db()))->syncContacts($contacts);
            out(['ok' => true, 'removed' => $removed, 'failed' => $failed, 'conversations' => $api->conversations()]);

        case 'POST contact_add':
            $pk12 = substr(strtolower((string) ($input['pk'] ?? '')), 0, 12);
            if (!preg_match('/^[0-9a-f]{12}$/', $pk12)) {
                out(['error' => 'Neplatný klíč'], 400);
            }
            $ha = HaRest::fromConfig($cfg['ha']);
            $r = $ha->callService('meshcore', 'execute_command', ['command' => 'add_contact ' . $pk12] + haEntry($cfg), true);
            if (isset($r['error'])) {
                out(['error' => 'Companion kontakt nepřidal: ' . $r['error']], 400);
            }
            // okamžitá synchronizace kontaktů, ať je nový kontakt hned vidět
            $contacts = $ha->callService('meshcore', 'get_contacts', haEntry($cfg), true);
            (new MessageStore(db()))->syncContacts($contacts);
            out(['ok' => true, 'conversations' => $api->conversations()]);

        case 'POST trace':
            session_write_close();
            set_time_limit(120);
            $pk12 = substr(strtolower((string) ($input['pk'] ?? '')), 0, 12);
            if (!preg_match('/^[0-9a-f]{12}$/', $pk12)) {
                out(['error' => 'Neplatný klíč'], 400);
            }
            $ha = HaRest::fromConfig($cfg['ha']);
            $r = $ha->callService('meshcore', 'trace', ['pubkey_prefix' => $pk12, 'timeout' => 30] + haEntry($cfg), true, 110);
            out(['trace' => $r['trace'] ?? null, 'error' => $r['error'] ?? null]);

        case 'POST advert':
            $flood = !empty($input['flood']);
            $ha = HaRest::fromConfig($cfg['ha']);
            $r = $ha->callService('meshcore', 'execute_command',
                ['command' => 'send_advert ' . ($flood ? 'true' : 'false')] + haEntry($cfg), true);
            if (isset($r['error'])) {
                out(['error' => 'Companion advert neodeslal: ' . $r['error']], 400);
            }
            out(['ok' => true, 'flood' => $flood]);

        case 'GET channels_info':
            out([
                'slots'    => (int) (db()->value("SELECT v FROM worker_state WHERE k = 'channel_slots'") ?: 0),
                'channels' => db()->all('SELECT channel_idx, name FROM channels ORDER BY channel_idx'),
            ]);

        case 'POST channel_add':
            $name = trim((string) ($input['name'] ?? ''));
            $secret = strtolower(trim((string) ($input['secret'] ?? '')));
            if ($name === '' || strlen($name) > 31 || preg_match('/[\x00-\x1f]/', $name)) {
                out(['error' => 'Název kanálu musí mít 1–31 bajtů'], 400);
            }
            if ($secret !== '' && !preg_match('/^[0-9a-f]{32}$/', $secret)) {
                out(['error' => 'Klíč musí mít 32 hex znaků (16 bajtů)'], 400);
            }
            if ($secret === '' && $name[0] !== '#') {
                out(['error' => 'Kanál bez klíče musí být hashtagový (začínat #)'], 400);
            }
            $used = array_map('intval', array_column(db()->all('SELECT channel_idx FROM channels'), 'channel_idx'));
            $slots = (int) (db()->value("SELECT v FROM worker_state WHERE k = 'channel_slots'") ?: 0);
            $max = $slots > 0 ? $slots : 40;
            $idx = null;
            for ($i = 0; $i < $max; $i++) {
                if (!in_array($i, $used, true)) { $idx = $i; break; }
            }
            if ($idx === null) {
                out(['error' => "Companion nemá volný slot pro kanál (obsazeno $max)"], 400);
            }
            $ha = HaRest::fromConfig($cfg['ha']);
            $cmd = 'set_channel(' . $idx . ', ' . json_encode($name, JSON_UNESCAPED_UNICODE)
                . ($secret !== '' ? ', ' . pyBytes($secret) : '') . ')';
            $r = $ha->callService('meshcore', 'execute_command', ['command' => $cmd] + haEntry($cfg), true);
            if (isset($r['error'])) {
                out(['error' => 'Companion kanál nepřidal: ' . $r['error']], 400);
            }
            // nový kanál ve slotu nesmí převzít starou historii
            (new Retention(db()))->deleteConversation('c:' . $idx);
            (new MessageStore(db()))->syncChannels($ha->callService('meshcore', 'get_channels', haEntry($cfg), true));
            out(['ok' => true, 'idx' => $idx, 'conversations' => $api->conversations()]);

        case 'POST channel_delete':
            $idx = (int) ($input['idx'] ?? -1);
            if ($idx < 0 || $idx > 255) {
                out(['error' => 'Neplatný kanál'], 400);
            }
            $ha = HaRest::fromConfig($cfg['ha']);
            $r = $ha->callService('meshcore', 'execute_command',
                ['command' => 'set_channel(' . $idx . ', "", ' . pyBytes(str_repeat('0', 32)) . ')'] + haEntry($cfg), true);
            if (isset($r['error'])) {
                out(['error' => 'Companion kanál neodebral: ' . $r['error']], 400);
            }
            (new MessageStore(db()))->syncChannels($ha->callService('meshcore', 'get_channels', haEntry($cfg), true));
            db()->query('DELETE FROM channels WHERE channel_idx = ?', [$idx]);
            (new Retention(db()))->deleteConversation('c:' . $idx);
            db()->query('DELETE FROM conv_settings WHERE conv_key = ?', ['c:' . $idx]);
            out(['ok' => true, 'conversations' => $api->conversations()]);

        case 'POST channel_secret':
            $idx = (int) ($input['idx'] ?? -1);
            $ha = HaRest::fromConfig($cfg['ha']);
            $r = $ha->callService('meshcore', 'execute_command', ['command' => 'get_channel ' . $idx] + haEntry($cfg), true);
            $sec = $r['channel_secret'] ?? $r['secret'] ?? null;
            if (is_array($sec)) {
                $sec = implode('', array_map(static fn($b) => sprintf('%02x', $b), $sec));
            }
            if (!is_string($sec) || !preg_match('/^[0-9a-fA-F]{32}$/', $sec)) {
                out(['error' => 'Klíč kanálu se nepodařilo načíst' . (isset($r['error']) ? ': ' . $r['error'] : '')], 400);
            }
            out(['secret' => strtolower($sec), 'name' => $r['channel_name'] ?? $r['name'] ?? null]);

        case 'POST message_delete':
            out(['deleted' => (new Retention(db()))->deleteMessage((int) ($input['id'] ?? 0))]);

        case 'POST conv_delete':
            $conv = (string) ($input['conv'] ?? '');
            if (!Api::validConv($conv)) {
                out(['error' => 'Neplatná konverzace'], 400);
            }
            out(['deleted' => (new Retention(db()))->deleteConversation($conv), 'conversations' => $api->conversations()]);

        case 'POST retention':
            $settings->setRetention((int) ($input['days'] ?? 0));
            $del = (new Retention(db()))->cleanup($settings->retention(), true);
            out(['settings' => $settings->all(), 'deleted' => $del]);

        case 'GET copies':
            out(['messages' => $api->copies((int) ($_GET['id'] ?? 0))]);

        case 'GET poll':
            // long-polling: čekáme na změnu nebo timeout
            session_write_close();
            $cursor = (string) ($_GET['cursor'] ?? '');
            if (!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(\.\d{1,6})?$/', $cursor)) {
                $cursor = $api->currentCursor();
            }
            $deadline = time() + (int) $cfg['app']['poll_timeout'];
            do {
                $ch = $api->changes($cursor);
                if ($ch['messages']) {
                    break;
                }
                if (connection_aborted()) {
                    exit;
                }
                usleep(1_000_000);
            } while (time() < $deadline);
            out($ch + ['state' => $api->state()]);

        case 'GET search':
            out(['messages' => $api->search((string) ($_GET['q'] ?? ''))]);

        case 'POST read':
            $conv = (string) ($input['conv'] ?? '');
            if (!Api::validConv($conv)) {
                out(['error' => 'Neplatná konverzace'], 400);
            }
            $api->markRead($conv, (int) ($input['last_id'] ?? 0));
            out(['ok' => true]);

        case 'POST send':
            $conv = (string) ($input['conv'] ?? '');
            $text = trim((string) ($input['text'] ?? ''));
            if (!Api::validConv($conv)) {
                out(['error' => 'Neplatná konverzace'], 400);
            }
            if ($text === '') {
                out(['error' => 'Prázdná zpráva'], 400);
            }
            if (mb_strlen($text) > $cfg['app']['message_max_len']) {
                out(['error' => 'Zpráva je příliš dlouhá'], 400);
            }
            $limit = Api::byteLimit($conv, $api->selfName($cfg));
            if (strlen($text) > $limit) {
                out(['error' => 'Zpráva má ' . strlen($text) . ' B, limit je ' . $limit . ' B.'], 400);
            }
            $resendOf = (int) ($input['resend_of'] ?? 0);
            $resendRoot = $resendOf > 0 ? db()->value("SELECT COALESCE(dup_of, id) FROM messages WHERE id = ? AND conv_key = ? AND direction = 'out'", [$resendOf, $conv]) : null;
            [$t, $id] = explode(':', $conv, 2);

            if ($t === 'd') {
                // přímá zpráva: odesílá worker s opakováním (3× známou cestou, pak 3× floodem)
                if (!db()->value('SELECT pubkey_prefix FROM contacts WHERE pubkey_prefix = ?', [$id])) {
                    out(['error' => 'Kontakt není v companionu'], 400);
                }
                $name = db()->value('SELECT name FROM contacts WHERE pubkey_prefix = ?', [$id]);
                db()->query(
                    "INSERT INTO messages (entry_id, conv_key, direction, message_type, pubkey_prefix, sender_name, body, send_id, status, repeater_count, msg_ts, dup_of)
                     VALUES (?, ?, 'out', 'direct', ?, ?, ?, ?, 'sent', 0, UTC_TIMESTAMP(3), ?)",
                    [$cfg['ha']['entry_id'] ?? null, $conv, $id, $name, $text, bin2hex(random_bytes(4)), $resendRoot ? (int) $resendRoot : null]
                );
                $msgId = db()->lastId();
                if ($resendRoot) {
                    db()->query('UPDATE messages SET dup_count = dup_count + 1 WHERE id = ?', [(int) $resendRoot]);
                }
                (new DmSender(db()))->enqueue($msgId, $id, $text, time());
                out(['ok' => true, 'id' => $msgId]);
            }

            // „odeslat znovu“ – worker novou zprávu připojí k původní
            if ($resendRoot) {
                db()->query('INSERT INTO worker_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP(6)',
                    [MessageStore::resendKey($conv, $text), (string) $resendOf]);
            }
            $data = ['message' => $text, 'channel_idx' => (int) $id];
            if (!empty($cfg['ha']['entry_id'])) {
                $data['entry_id'] = $cfg['ha']['entry_id'];
            }
            // limit provozu v integraci: zprávu zařadit do fronty, worker ji odešle po uvolnění
            $sq = new SendQueue(db());
            $budget = TrafficBudget::estimate(db());
            if ($sq->busy() || SendQueue::trafficUntil(db()) > time() || ($budget !== null && $budget['credits'] < 1)) {
                $wait = $budget !== null && $budget['credits'] < 1 ? $budget['next_in'] : 0;
                $qid = $sq->enqueue($conv, (int) $id, $text, $wait, $resendRoot ? (int) $resendRoot : null);
                out(['ok' => true, 'queued' => true, 'wait' => $wait, 'id' => $qid]);
            }
            try {
                HaRest::fromConfig($cfg['ha'])->callService('meshcore', 'send_channel_message', $data);
            } catch (RuntimeException $e) {
                $wait = SendQueue::trafficWait($e->getMessage());
                if ($wait === null && SendQueue::transient($e->getMessage())
                    && db()->value("SELECT id FROM messages WHERE conv_key = ? AND direction = 'out' AND body = ? AND status <> 'queued'
                                      AND msg_ts > UTC_TIMESTAMP() - INTERVAL 30 SECOND", [$conv, $text])) {
                    out(['ok' => true]); // HA zprávu přes chybu odeslal (událost už dorazila)
                }
                if ($wait === null && SendQueue::transient($e->getMessage())) {
                    // HA vrací u limitu provozu přes REST jen obecnou chybu 500 (bez počtu sekund);
                    // worker pak přes WebSocket dostane přesnou hlášku i dobu čekání.
                    // Kdyby HA zprávu přece jen odeslal, dorazí událost a fronta ji podruhé neposílá.
                    $wait = SendQueue::ERROR_WAIT;
                }
                if ($wait !== null) {
                    SendQueue::trafficHold(db(), $wait);
                }
                if ($wait === null) {
                    throw $e;
                }
                $qid = $sq->enqueue($conv, (int) $id, $text, $wait, $resendRoot ? (int) $resendRoot : null);
                out(['ok' => true, 'queued' => true, 'wait' => $wait, 'id' => $qid]);
            }
            TrafficBudget::spend(db());
            // samotná zpráva dorazí do DB přes worker (událost meshcore_message s outgoing=true)
            out(['ok' => true]);

        default:
            out(['error' => 'Neznámá akce'], 404);
    }
} catch (InvalidArgumentException $e) {
    out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[meshcore-klient] ' . $e->getMessage());
    out(['error' => $e->getMessage()], 500);
}
