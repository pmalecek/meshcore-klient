<?php
declare(strict_types=1);

/*
 * MeshCore klient přes Bluetooth (Web Bluetooth) – běží celý v prohlížeči.
 * Bez přihlášení se historie ukládá jen do prohlížeče (IndexedDB),
 * přihlášenému uživateli se navíc zrcadlí do databáze na serveru.
 */
use MeshClient\Auth;

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$cfg  = app_config();
$auth = new Auth(db(), $cfg['auth']);
$auth->startSession();

$h        = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
$mode     = 'ble';
$username = $auth->userId() !== null ? (string) $auth->username() : null;
$csrf     = $auth->csrf();
// kontrola přes analyzer: přihlášený vždy (je-li zapnutý), nepřihlášený přes veřejný endpoint ble/heard.php
$analyzerOn = !empty($cfg['analyzer']['enabled']) && !empty($cfg['analyzer']['url'])
    && ($username !== null || !empty($cfg['ble']['public_heard']));
session_write_close();
require dirname(__DIR__, 2) . '/src/views/app.php';
