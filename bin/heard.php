<?php
declare(strict_types=1);

/**
 * Zjistí u nedávno odeslaných kanálových zpráv, kolik observerů analyzeru je zachytilo.
 * Spouští systemd timer (deploy/meshcore-heard.timer) každých 5 s.
 *
 *   php bin/heard.php        – zpracuje zprávy, u kterých nastal čas kontroly
 *   php bin/heard.php <id>   – okamžitě zkontroluje jednu zprávu
 *   php bin/heard.php scopes – hned stáhne regiony repeaterů z analyzeru
 */

use MeshClient\Analyzer;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$an = new Analyzer(db(), app_config()['analyzer']);

// jediná běžící instance
$lock = fopen(sys_get_temp_dir() . '/meshcore-heard.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

if (($argv[1] ?? '') === 'scopes') {
    echo 'Uzlů s regionem: ' . $an->syncScopes(0, true) . "\n";
    exit(0);
}

if (isset($argv[1]) && ctype_digit($argv[1])) {
    echo ($an->checkNow((int) $argv[1]) ?? 'zpráva nenalezena') . "\n";
    exit(0);
}

// úklid starých zpráv podle doby uchování – jednou za hodinu (běží i bez analyzeru)
try {
    $del = (new MeshClient\Retention(db()))->cleanup((new MeshClient\Settings(db(), app_config()['app']['bg_dir']))->retention());
    if ($del) {
        fwrite(STDERR, sprintf("[%s] uchování: smazáno %d starých zpráv\n", date('Y-m-d H:i:s'), $del));
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] uchování: ' . $e->getMessage() . "\n");
}

if (!$an->enabled()) {
    exit(0);
}
$s = $an->runDue();

// regiony příchozích zpráv v kanálech
try {
    $an->enrichIncomingScopes();
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] regiony zpráv: ' . $e->getMessage() . "\n");
}

// regiony repeaterů – jednou za 6 h
try {
    $n = $an->syncScopes();
    if ($n !== null) {
        fwrite(STDERR, sprintf("[%s] regiony: %d uzlů s regionem\n", date('Y-m-d H:i:s'), $n));
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] regiony: ' . $e->getMessage() . "\n");
}
if ($s['checked'] > 0) {
    fwrite(STDERR, sprintf("[%s] heard: zkontrolováno %d, slyšeno %d\n", date('Y-m-d H:i:s'), $s['checked'], $s['heard']));
}
