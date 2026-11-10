<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MeshClient\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/** @return array<string,mixed> */
function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config/config.php';
        date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
    }
    return $config;
}

function db(): MeshClient\Db
{
    static $db = null;
    if ($db === null) {
        $db = new MeshClient\Db(app_config()['database']);
    }
    return $db;
}

/** URL souboru v public/ s verzí podle data změny – po nasazení se v prohlížečích načte nová verze */
function asset(string $path): string
{
    $file = dirname(__DIR__) . '/public/' . $path;
    return htmlspecialchars($path . (is_file($file) ? '?v=' . filemtime($file) : ''), ENT_QUOTES);
}
