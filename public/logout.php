<?php
declare(strict_types=1);

use MeshClient\Auth;

require dirname(__DIR__) . '/src/bootstrap.php';

$auth = new Auth(db(), app_config()['auth']);
$auth->startSession();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $auth->checkCsrf($_POST['csrf'] ?? null)) {
    $auth->logout();
}
header('Location: ' . (($_POST['next'] ?? '') === 'ble' ? 'ble/' : 'login.php'));
