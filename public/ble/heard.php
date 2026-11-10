<?php
declare(strict_types=1);

/*
 * Veřejná kontrola přes analyzer (observeři, heard) pro Bluetooth klienta bez přihlášení.
 * Dělá jen jednu věc – dohledá odeslanou kanálovou zprávu v analyzeru – a je omezená
 * počtem požadavků na IP adresu, na companion a celkově.
 */
use MeshClient\Analyzer;
use MeshClient\RateLimit;

require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = app_config();
$ble = $cfg['ble'] ?? [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(['error' => 'Jen POST'], 405);
}
if (empty($ble['public_heard'])) {
    reply(['error' => 'Kontrola přes analyzer bez přihlášení je vypnutá'], 403);
}
// jen z vlastní stránky (ochrana proti zneužití z cizích webů)
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
if ($host === '' || strtolower((string) parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '')) !== $host
    || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    reply(['error' => 'Nepovolený původ požadavku'], 403);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    reply(['error' => 'Neplatný požadavek'], 400);
}
$device = strtolower((string) ($input['device'] ?? ''));
if (!preg_match('/^[0-9a-f]{12}$/', $device)) {
    reply(['error' => 'Neplatné zařízení'], 400);
}

try {
    $an = new Analyzer(db(), $cfg['analyzer']);
    if (!$an->enabled()) {
        reply(['error' => 'Kontrola přes analyzer je vypnutá'], 400);
    }
    $window = (int) ($ble['limit_window'] ?? 600);
    $over = (new RateLimit(db()))->check([
        'heard:ip:' . ($_SERVER['REMOTE_ADDR'] ?? '?') => (int) ($ble['limit_ip'] ?? 60),
        'heard:dev:' . $device                          => (int) ($ble['limit_device'] ?? 40),
        'heard:all'                                     => (int) ($ble['limit_total'] ?? 600),
    ], $window);
    if ($over !== null) {
        reply(['error' => 'Příliš mnoho kontrol, zkus to za pár minut.'], 429);
    }
    reply($an->lookup(
        mb_substr((string) ($input['channel'] ?? ''), 0, 64),
        mb_substr((string) ($input['body'] ?? ''), 0, 500),
        (int) ($input['ts'] ?? 0),
        mb_substr((string) ($input['sender'] ?? ''), 0, 64),
        isset($input['hash']) && is_string($input['hash']) ? strtolower($input['hash']) : null,
        is_array($input['exclude'] ?? null) ? array_slice($input['exclude'], 0, 500) : [],
        !empty($input['final']),
        is_array($input['siblings'] ?? null) ? $input['siblings'] : []
    ));
} catch (Throwable $e) {
    error_log('[meshcore-klient] ble/heard: ' . $e->getMessage());
    reply(['error' => 'Chyba serveru'], 500);
}
