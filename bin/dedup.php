<?php
declare(strict_types=1);

/**
 * Přepočítá duplicity u všech uložených příchozích zpráv.
 * Použij po migraci sql/migrations/001_duplicates.sql nebo po změně app.dedup_window.
 *
 *   php bin/dedup.php
 */

use MeshClient\MessageStore;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$cfg   = app_config();
$db    = db();
$store = new MessageStore($db, (int) ($cfg['app']['dedup_window'] ?? 1800));

$db->query('UPDATE messages SET dup_of = NULL, dup_count = 0 WHERE dup_of IS NOT NULL OR dup_count <> 0');

$rows = $db->query(
    "SELECT id, conv_key, sender_name, body, msg_ts, direction FROM messages WHERE direction = 'in' OR status <> 'failed' ORDER BY id"
)->fetchAll();

$dups = 0;
foreach ($rows as $r) {
    if ($store->linkDuplicate($r) !== null) {
        $dups++;
    }
}

printf("Zpracováno %d zpráv, nalezeno %d duplicit / opakovaných odeslání.\n", count($rows), $dups);
