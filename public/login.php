<?php
declare(strict_types=1);

use MeshClient\Auth;

require dirname(__DIR__) . '/src/bootstrap.php';

$cfg  = app_config();
$auth = new Auth(db(), $cfg['auth']);
$auth->startSession();

// kam po přihlášení (jen povolené cíle)
$next = ($_GET['next'] ?? $_POST['next'] ?? '') === 'ble' ? 'ble' : '';
$target = $next === 'ble' ? 'ble/' : './';

if ($auth->userId() !== null) {
    header('Location: ' . $target);
    exit;
}

$error = null;
$username = '';
$method = ($_COOKIE['mc_login_method'] ?? '') === 'password' ? 'password' : 'code';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $method = ($_POST['method'] ?? '') === 'password' ? 'password' : 'code';
    if (!$auth->checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Platnost formuláře vypršela, zkus to znovu.';
    } else {
        $secret = $method === 'password' ? (string) ($_POST['password'] ?? '') : (string) ($_POST['code'] ?? '');
        $error = $auth->login($username, $secret, $_SERVER['REMOTE_ADDR'] ?? '', $method);
        if ($error === null) {
            // zapamatuje zvolený způsob přihlášení pro příště (není citlivé)
            setcookie('mc_login_method', $method, ['expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true]);
            header('Location: ' . $target);
            exit;
        }
    }
}
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="cs" data-bs-theme="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Přihlášení – MeshCore</title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
    <script src="<?= asset('assets/theme.js') ?>"></script>
</head>
<body class="bg-body-tertiary">
<main class="container d-flex align-items-center justify-content-center min-vh-100 py-4">
    <form class="card shadow-sm w-100" style="max-width: 380px" method="post" id="login-form">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-broadcast-pin display-5 text-primary"></i>
                <h1 class="h4 mt-2 mb-1">MeshCore</h1>
                <p class="text-body-secondary small mb-0" id="login-hint"><?= $method === 'password'
                    ? 'Přihlas se jménem a heslem.' : 'Přihlas se kódem z autentizační aplikace.' ?></p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small"><?= $h($error) ?></div>
            <?php endif; ?>
            <input type="hidden" name="csrf" value="<?= $h($auth->csrf()) ?>">
            <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= $h($next) ?>"><?php endif; ?>
            <div class="btn-group w-100 mb-3" role="group" aria-label="Způsob přihlášení">
                <input type="radio" class="btn-check" name="method" id="m-code" value="code" <?= $method === 'code' ? 'checked' : '' ?>>
                <label class="btn btn-outline-primary" for="m-code"><i class="bi bi-shield-lock me-1"></i>Kód</label>
                <input type="radio" class="btn-check" name="method" id="m-password" value="password" <?= $method === 'password' ? 'checked' : '' ?>>
                <label class="btn btn-outline-primary" for="m-password"><i class="bi bi-key me-1"></i>Heslo</label>
            </div>
            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="username" name="username" placeholder="Uživatel"
                       value="<?= $h($username) ?>" required autocapitalize="none" autocomplete="username" <?= $username === '' ? 'autofocus' : '' ?>>
                <label for="username">Uživatel</label>
            </div>
            <div class="form-floating mb-3 <?= $method === 'password' ? 'd-none' : '' ?>" id="f-code">
                <input type="text" class="form-control text-center fs-4 font-monospace" id="code" name="code" placeholder="000000"
                       inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code"
                       <?= $method === 'code' ? 'required' : 'disabled' ?> <?= $username !== '' && $method === 'code' ? 'autofocus' : '' ?>>
                <label for="code">Jednorázový kód</label>
            </div>
            <div class="form-floating mb-3 <?= $method === 'code' ? 'd-none' : '' ?>" id="f-password">
                <input type="password" class="form-control" id="password" name="password" placeholder="Heslo" autocomplete="current-password"
                       <?= $method === 'password' ? 'required' : 'disabled' ?> <?= $username !== '' && $method === 'password' ? 'autofocus' : '' ?>>
                <label for="password">Heslo</label>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-box-arrow-in-right me-1"></i> Přihlásit
            </button>
            <p class="small text-body-secondary text-center mt-3 mb-0">Heslo si nastavíš po přihlášení kódem v menu → Heslo k přihlášení.</p>
            <p class="small text-center mt-2 mb-0"><a href="ble/"><i class="bi bi-bluetooth me-1"></i><?= $next === 'ble' ? 'Zpět na Bluetooth klienta bez přihlášení' : 'Bluetooth klient bez přihlášení' ?></a></p>
        </div>
    </form>
    <script>
    document.querySelectorAll('input[name="method"]').forEach(function (r) {
        r.addEventListener('change', function () {
            var pw = document.getElementById('m-password').checked;
            document.getElementById('f-code').classList.toggle('d-none', pw);
            document.getElementById('f-password').classList.toggle('d-none', !pw);
            var c = document.getElementById('code'), p = document.getElementById('password');
            c.disabled = pw; c.required = !pw; p.disabled = !pw; p.required = pw;
            document.getElementById('login-hint').textContent = pw ? 'Přihlas se jménem a heslem.' : 'Přihlas se kódem z autentizační aplikace.';
            var u = document.getElementById('username');
            (u.value ? (pw ? p : c) : u).focus();
        });
    });
    </script>
</main>
</body>
</html>
