<?php
declare(strict_types=1);

/**
 * Správa uživatelů z příkazové řádky.
 *
 *   php bin/user.php add <jmeno>      – vytvoří uživatele a vypíše TOTP pro aplikaci
 *   php bin/user.php add <jmeno> --password=<heslo>  – navíc nastaví heslo (bez dotazu, pro instalátory)
 *   php bin/user.php passwd <jmeno> --password=<heslo> – změna hesla bez dotazu
 *   php bin/user.php reset <jmeno>    – vygeneruje nový TOTP klíč
 *   php bin/user.php passwd <jmeno>   – nastaví heslo (zeptá se na něj)
 *   php bin/user.php nopasswd <jmeno> – zruší heslo (přihlášení jen kódem)
 *   php bin/user.php delete <jmeno>   – smaže uživatele
 *   php bin/user.php list             – vypíše uživatele
 */

use MeshClient\Totp;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$cmd  = $argv[1] ?? '';
$name = $argv[2] ?? '';
$optPassword = null;
foreach (array_slice($argv, 3) as $a) {
    if (str_starts_with($a, '--password=')) {
        $optPassword = substr($a, 11);
    }
}
$db   = db();
$cfg  = app_config();

/** Chybová hláška a návratový kód 1 (exit("…") by skončil s kódem 0). */
function fail(string $msg): never
{
    fwrite(STDERR, $msg);
    exit(1);
}

function showTotp(string $secret, string $name, array $cfg): void
{
    $uri = Totp::uri($secret, $name, $cfg['auth']['issuer']);
    echo "\nTajný klíč: $secret\n";
    echo "URI:        $uri\n\n";
    $qr = trim((string) shell_exec('command -v qrencode 2>/dev/null'));
    if ($qr !== '') {
        passthru('qrencode -t ANSIUTF8 ' . escapeshellarg($uri));
    } else {
        echo "Tip: nainstaluj 'qrencode' (apt install qrencode) a QR kód se vykreslí přímo v terminálu.\n";
    }
    echo "\nNaskenuj kód v aplikaci (Google Authenticator, Aegis, 2FAS…) nebo zadej klíč ručně.\n";
    echo "Kontrolní kód právě teď: " . Totp::code($secret, intdiv(time(), Totp::PERIOD)) . "\n";
}

switch ($cmd) {
    case 'add':
        if (!preg_match('/^[\w.@-]{2,64}$/u', $name)) {
            fail("Zadej platné jméno (2–64 znaků).\n");
        }
        if ($db->value('SELECT id FROM users WHERE username = ?', [$name])) {
            fail("Uživatel '$name' už existuje. Použij 'reset'.\n");
        }
        $secret = Totp::generateSecret();
        if ($optPassword !== null && mb_strlen($optPassword) < MeshClient\Auth::PASSWORD_MIN) {
            fail('Heslo musí mít aspoň ' . MeshClient\Auth::PASSWORD_MIN . " znaků.\n");
        }
        $db->query('INSERT INTO users (username, totp_secret, password_hash, password_changed_at) VALUES (?, ?, ?, ?)', [
            $name, $secret,
            $optPassword !== null ? password_hash($optPassword, PASSWORD_DEFAULT) : null,
            $optPassword !== null ? gmdate('Y-m-d H:i:s') : null,
        ]);
        echo "Uživatel '$name' vytvořen" . ($optPassword !== null ? ' (s heslem)' : '') . ".\n";
        if ($optPassword === null) {
            showTotp($secret, $name, $cfg);
        } else {
            echo "Přihlášení kódem z aplikace (TOTP) zapneš příkazem: php bin/user.php reset $name\n";
        }
        break;

    case 'reset':
        $secret = Totp::generateSecret();
        $n = $db->query('UPDATE users SET totp_secret = ?, last_totp_step = NULL WHERE username = ?', [$secret, $name])->rowCount();
        if (!$n) {
            fail("Uživatel '$name' neexistuje.\n");
        }
        echo "Nový klíč pro '$name':\n";
        showTotp($secret, $name, $cfg);
        break;

    case 'passwd':
        if (!$db->value('SELECT id FROM users WHERE username = ?', [$name])) {
            fail("Uživatel '$name' neexistuje.\n");
        }
        $ask = static function (string $prompt): string {
            echo $prompt;
            $tty = stream_isatty(STDIN);
            if ($tty) { shell_exec('stty -echo'); }
            $v = rtrim((string) fgets(STDIN), "\r\n");
            if ($tty) { shell_exec('stty echo'); echo "\n"; }
            return $v;
        };
        $pw = $optPassword ?? $ask('Nové heslo: ');
        if (mb_strlen($pw) < MeshClient\Auth::PASSWORD_MIN) {
            fail('Heslo musí mít aspoň ' . MeshClient\Auth::PASSWORD_MIN . " znaků.\n");
        }
        if ($optPassword === null && $ask('Heslo znovu: ') !== $pw) {
            fail("Hesla se neshodují.\n");
        }
        $db->query('UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP() WHERE username = ?',
            [password_hash($pw, PASSWORD_DEFAULT), $name]);
        echo "Heslo nastaveno.\n";
        break;

    case 'nopasswd':
        $n = $db->query('UPDATE users SET password_hash = NULL, password_changed_at = UTC_TIMESTAMP() WHERE username = ?', [$name])->rowCount();
        echo $n ? "Heslo zrušeno – přihlášení jen kódem.\n" : "Uživatel neexistuje (nebo heslo neměl).\n";
        break;

    case 'delete':
        $n = $db->query('DELETE FROM users WHERE username = ?', [$name])->rowCount();
        echo $n ? "Smazáno.\n" : "Uživatel neexistuje.\n";
        break;

    case 'list':
        foreach ($db->all('SELECT username, created_at, last_login_at, password_hash IS NOT NULL AS pw FROM users ORDER BY username') as $u) {
            printf("%-20s vytvořen %s, poslední přihlášení %s, heslo %s\n", $u['username'], $u['created_at'], $u['last_login_at'] ?? '–', $u['pw'] ? 'ano' : 'ne');
        }
        break;

    default:
        echo "Použití: php bin/user.php add|reset|passwd|nopasswd|delete <jmeno> | list\n";
        exit(1);
}
