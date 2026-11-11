<?php
declare(strict_types=1);

/**
 * Databázové schéma: založení a migrace.
 *
 *   php bin/migrate.php            – prázdná DB: nahraje sql/schema.sql; jinak spustí nové migrace
 *   php bin/migrate.php --status   – vypíše stav migrací
 *
 * Spuštěné migrace se evidují v tabulce schema_migrations. U starší instalace bez evidence
 * se spustí všechny migrace (jsou psané tak, aby šly pustit opakovaně) a pak se zaevidují.
 * V Dockeru se volá automaticky při startu kontejneru.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$db   = db();
$pdo  = $db->pdo();

// v Dockeru startuje víc kontejnerů naráz – migrace smí běžet jen jednou
if ((int) $db->value("SELECT GET_LOCK('meshcore_klient_migrate', 120)") !== 1) {
    fwrite(STDERR, "Nepodařilo se získat zámek pro migrace.\n");
    exit(1);
}

$db->query('CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$files = glob($root . '/sql/migrations/*.sql') ?: [];
sort($files);
$all = array_map('basename', $files);
$done = array_column($db->all('SELECT name FROM schema_migrations'), 'name');

if (in_array('--status', $argv, true)) {
    foreach ($all as $f) {
        printf("%-40s %s\n", $f, in_array($f, $done, true) ? 'hotovo' : 'čeká');
    }
    exit(0);
}

$hasTables = (bool) $db->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'messages'");

if (!$hasTables) {
    echo "Prázdná databáze – zakládám schéma.\n";
    runSql($pdo, (string) file_get_contents($root . '/sql/schema.sql'));
    // schema.sql už obsahuje všechny migrace
    foreach ($all as $f) {
        $db->query('INSERT IGNORE INTO schema_migrations (name) VALUES (?)', [$f]);
    }
    echo 'Schéma založeno (' . count($all) . " migrací v něm už je).\n";
    exit(0);
}

$todo = array_values(array_diff($all, $done));
if (!$todo) {
    echo "Databáze je aktuální.\n";
    exit(0);
}
if (!$done) {
    echo "Starší instalace bez evidence migrací – spouštím všechny (opakované spuštění je bezpečné).\n";
}
foreach ($todo as $f) {
    echo "Migrace {$f}… ";
    runSql($pdo, (string) file_get_contents($root . '/sql/migrations/' . $f));
    $db->query('INSERT IGNORE INTO schema_migrations (name) VALUES (?)', [$f]);
    echo "ok\n";
}
echo "Hotovo.\n";

/** Spustí SQL soubor příkaz po příkazu (středníky v řetězcích a komentářích se ignorují). */
function runSql(PDO $pdo, string $sql): void
{
    foreach (splitSql($sql) as $stmt) {
        $pdo->exec($stmt);
    }
}

/** @return list<string> */
function splitSql(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\') {
                $buf .= $sql[++$i] ?? '';
            } elseif ($c === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf .= $c;
            continue;
        }
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            $buf .= "\n";
            continue;
        }
        if ($c === ';') {
            if (trim($buf) !== '') {
                $out[] = trim($buf);
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}
