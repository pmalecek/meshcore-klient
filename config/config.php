<?php
/**
 * Výchozí konfigurace. Neupravuj – hodnoty přepiš v config.local.php.
 */
$config = [
    'database' => [
        'dsn'      => 'mysql:host=localhost;dbname=meshcore;charset=utf8mb4',
        'user'     => '',
        'password' => '',
    ],
    // Odkud klient bere data: 'ha' = Home Assistant (integrace meshcore-ha), 'server' = meshcore-server
    'backend' => 'ha',
    'server' => [
        // Adresa meshcore-serveru (bez lomítka na konci), např. http://192.168.1.50:8080
        'url'        => 'http://127.0.0.1:8080',
        // API token (meshcore-server token create meshcore-klient --scope admin; write stačí bez správy nodu)
        'token'      => '',
        // Čtenář pro nepřečtené zprávy na serveru (stejný na všech zařízeních = počty se sdílí)
        'reader'     => 'klient',
        // Kolik dní historie stáhnout při první synchronizaci (0 = vše)
        'backfill_days' => 30,
        'verify_tls' => true,
    ],
    'ha' => [
        // Adresa Home Assistantu (bez lomítka na konci)
        'url'      => 'http://homeassistant.local:8123',
        // Long-lived access token (HA → Profil → Zabezpečení → Dlouhodobé přístupové tokeny)
        'token'    => '',
        // Pokud máš víc companionů, lze omezit na jeden entry_id (null = všechny)
        'entry_id' => null,
        // Ověřování TLS certifikátu při https/wss
        'verify_tls' => true,
    ],
    'worker' => [
        'ping_interval'     => 30,   // s – keepalive na HA WebSocket
        'contacts_refresh'  => 600,  // s – jak často znovu načíst kontakty a kanály
        'reconnect_max'     => 60,   // s – maximální prodleva mezi pokusy o připojení
    ],
    'auth' => [
        'issuer'            => 'MeshCore klient',
        'session_name'      => 'mcsid',
        'session_lifetime'  => 60 * 60 * 24 * 14, // 14 dní
        'max_failed'        => 5,                 // neúspěšných pokusů z jedné IP…
        'failed_window'     => 15 * 60,           // …za toto okno (s)
    ],
    'ble' => [
        // Kontrola přes analyzer (observeři, heard) i pro Bluetooth klienta bez přihlášení
        'public_heard'   => true,
        // limity požadavků za okno (s): na IP adresu, na companion (klíč z prohlížeče – jen doplněk) a celkem
        'limit_window'   => 10 * 60,
        'limit_ip'       => 60,
        'limit_device'   => 40,
        'limit_total'    => 600,
    ],
    'analyzer' => [
        // Kontrola, zda odeslanou kanálovou zprávu zachytili observeři (CoreScope API)
        'enabled'       => true,
        'url'           => 'https://analyzer.meshcore.cz',
        // Kdy po odeslání se ptát (s). Po nalezení paketu se už jen aktualizuje počet observerů.
        'schedule'      => [5, 30, 120, 600, 1800],
        // Tolerance mezi časem odeslání a časovým razítkem paketu (s)
        'match_window'  => 120,
        // Jméno tvého uzlu, jak ho vidí analyzer (např. "MujUzel🤖"). Prázdné = párovat jen podle textu a času.
        'node_name'     => '',
        // Názvy vlastních observerů, které se nepočítají (tvůj uzel se započítává automaticky)
        'own_observers' => [],
        'timeout'       => 15,
    ],
    'app' => [
        'timezone'          => 'Europe/Prague',
        'message_max_len'   => 140,
        'poll_timeout'      => 20, // s – long-polling
        // Příchozí zpráva se stejným textem od stejného odesílatele ve stejné konverzaci
        // v rámci tohoto okna (s) od původní zprávy se považuje za duplicitu.
        'dedup_window'      => 30 * 60,
        // Kam se ukládají nahrané obrázky pozadí (mimo document root, zapisovatelné pro www-data)
        'bg_dir'            => dirname(__DIR__) . '/data/backgrounds',
        // Poloha companionu pro mapu cesty zpráv [lat, lon] – použije se jen tehdy,
        // když ji integrace v HA nevystavuje (senzory latitude/longitude companionu).
        'home'              => null,
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

// Proměnné prostředí MC_* (Docker, meshcore-stack) mají přednost před config.local.php.
$env = static function (string $k): ?string {
    $v = getenv($k);
    if ($v === false || $v === '') {
        $f = getenv($k . '_FILE'); // např. Docker secrets
        $v = $f !== false && is_readable($f) ? trim((string) file_get_contents($f)) : null;
    }
    return $v === null || $v === '' ? null : $v;
};
$set = static function (array &$c, array $path, mixed $v): void {
    if ($v === null) {
        return;
    }
    $ref = &$c;
    foreach ($path as $p) {
        $ref = &$ref[$p];
    }
    $ref = $v;
};
$bool = static fn(?string $v): ?bool => $v === null ? null : in_array(strtolower($v), ['1', 'true', 'yes', 'on', 'ano'], true);
if (($h = $env('MC_DB_HOST')) !== null) {
    $config['database']['dsn'] = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $h, (int) ($env('MC_DB_PORT') ?? 3306), $env('MC_DB_NAME') ?? 'meshcore');
}
$set($config, ['database', 'dsn'], $env('MC_DB_DSN'));
$set($config, ['database', 'user'], $env('MC_DB_USER'));
$set($config, ['database', 'password'], $env('MC_DB_PASSWORD'));
$set($config, ['backend'], $env('MC_BACKEND'));
$set($config, ['server', 'url'], $env('MC_SERVER_URL'));
$set($config, ['server', 'token'], $env('MC_SERVER_TOKEN'));
$set($config, ['server', 'reader'], $env('MC_SERVER_READER'));
$set($config, ['server', 'backfill_days'], ($v = $env('MC_SERVER_BACKFILL_DAYS')) !== null ? (int) $v : null);
$set($config, ['ha', 'url'], $env('MC_HA_URL'));
$set($config, ['ha', 'token'], $env('MC_HA_TOKEN'));
$set($config, ['analyzer', 'enabled'], $bool($env('MC_ANALYZER_ENABLED')));
$set($config, ['analyzer', 'url'], $env('MC_ANALYZER_URL'));
$set($config, ['analyzer', 'node_name'], $env('MC_NODE_NAME'));
$set($config, ['app', 'timezone'], $env('MC_TIMEZONE'));
$set($config, ['app', 'bg_dir'], $env('MC_BG_DIR'));
unset($env, $set, $bool, $h, $v);

return $config;
