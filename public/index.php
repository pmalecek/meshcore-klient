<?php
declare(strict_types=1);

use MeshClient\Auth;

require dirname(__DIR__) . '/src/bootstrap.php';

$cfg  = app_config();
$auth = new Auth(db(), $cfg['auth']);
$auth->startSession();

if ($auth->userId() === null) {
    header('Location: login.php');
    exit;
}
$h        = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
$mode     = 'ha';
$username = (string) $auth->username();
$csrf     = $auth->csrf();
require dirname(__DIR__) . '/src/views/app.php';
