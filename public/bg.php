<?php
declare(strict_types=1);

/**
 * Vydává nahrané obrázky pozadí (jen přihlášeným).
 */

use MeshClient\Auth;
use MeshClient\Settings;

require dirname(__DIR__) . '/src/bootstrap.php';

$cfg  = app_config();
$auth = new Auth(db(), $cfg['auth']);
$auth->startSession();
session_write_close();

if ($auth->userId() === null) {
    http_response_code(401);
    exit;
}

$settings = new Settings(db(), $cfg['app']['bg_dir']);
$path = $settings->imagePath((string) ($_GET['f'] ?? ''));
if ($path === null) {
    http_response_code(404);
    exit;
}

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$etag = '"' . basename($path) . '"';
header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
header('Cache-Control: private, max-age=31536000, immutable'); // název = hash obsahu
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($path));
readfile($path);
