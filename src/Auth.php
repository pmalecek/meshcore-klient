<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Přihlášení jednorázovým kódem (TOTP) nebo heslem a správa session.
 */
final class Auth
{
    public function __construct(private readonly Db $db, private readonly array $cfg)
    {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name($this->cfg['session_name']);
        ini_set('session.gc_maxlifetime', (string) $this->cfg['session_lifetime']);
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => $this->cfg['session_lifetime'],
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        if (isset($_SESSION['uid'], $_SESSION['last_seen'])
            && time() - $_SESSION['last_seen'] > $this->cfg['session_lifetime']) {
            $this->logout();
            session_start();
        }
        if (isset($_SESSION['uid'])) {
            $_SESSION['last_seen'] = time();
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
    }

    public function userId(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    public function username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }

    public function csrf(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public function checkCsrf(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->csrf(), $token);
    }

    public function isBlocked(string $ip): bool
    {
        $failed = (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [$ip, $this->cfg['failed_window']]
        );
        return $failed >= $this->cfg['max_failed'];
    }

    public const PASSWORD_MIN = 10;

    /**
     * Přihlášení jednorázovým kódem ($method = 'code') nebo heslem ($method = 'password').
     * Vrací null při úspěchu, jinak chybovou hlášku.
     */
    public function login(string $username, string $secret, string $ip, string $method = 'code'): ?string
    {
        if ($this->isBlocked($ip)) {
            return 'Příliš mnoho neúspěšných pokusů. Zkus to za chvíli.';
        }
        $user = $this->db->one('SELECT * FROM users WHERE username = ?', [trim($username)]);
        $ok = false;
        if ($method === 'password') {
            $ok = $this->checkPassword($user, $secret);
        } else {
            $ok = $this->checkCode($user, $secret);
        }
        $this->logAttempt($ip, $username, $ok);
        if (!$ok) {
            return $method === 'password' ? 'Neplatné jméno nebo heslo.' : 'Neplatné jméno nebo kód.';
        }
        $this->db->query('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['last_seen'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return null;
    }

    /** Ověří TOTP kód; úspěšně použitý kód se zneplatní (každý kód jen jednou). */
    private function checkCode(?array $user, string $code): bool
    {
        $step = $user ? Totp::verify($user['totp_secret'], trim($code)) : null;
        if ($step === null || ($user['last_totp_step'] !== null && $step <= (int) $user['last_totp_step'])) {
            return false;
        }
        $this->db->query('UPDATE users SET last_totp_step = ? WHERE id = ?', [$step, $user['id']]);
        return true;
    }

    private function checkPassword(?array $user, string $password): bool
    {
        $hash = $user['password_hash'] ?? null;
        if (!$hash) {
            // stejná časová náročnost i pro neexistujícího uživatele / uživatele bez hesla
            password_verify($password, '$2y$10$8I7OQ93D67ktfd2N0wrpQuNssKbswucqqPMS.erg57C0RBMyKF.06');
            return false;
        }
        if (!password_verify($password, $hash)) {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->db->query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        return true;
    }

    private function logAttempt(string $ip, string $username, bool $ok): void
    {
        $this->db->query(
            'INSERT INTO login_attempts (ip, username, success, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [$ip, mb_substr($username, 0, 64), $ok ? 1 : 0]
        );
    }

    /** @return array{has_password: bool, changed_at: ?string} */
    public function account(): array
    {
        $u = $this->db->one('SELECT password_hash, password_changed_at FROM users WHERE id = ?', [$this->userId()]);
        return [
            'has_password' => !empty($u['password_hash']),
            'changed_at'   => !empty($u['password_changed_at']) ? gmdate('c', strtotime($u['password_changed_at'] . ' UTC')) : null,
        ];
    }

    /**
     * Nastaví nebo změní heslo přihlášeného uživatele ($new = null heslo zruší – zůstane jen kód).
     * Změnu je nutné potvrdit současným heslem nebo jednorázovým kódem. Vrací null při úspěchu.
     */
    public function setPassword(string $current, ?string $new, string $ip): ?string
    {
        if ($this->isBlocked($ip)) {
            return 'Příliš mnoho neúspěšných pokusů. Zkus to za chvíli.';
        }
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$this->userId()]);
        if (!$user) {
            return 'Uživatel neexistuje.';
        }
        if ($new !== null) {
            if (mb_strlen($new) < self::PASSWORD_MIN) {
                return 'Heslo musí mít aspoň ' . self::PASSWORD_MIN . ' znaků.';
            }
            if (strlen($new) > 200) {
                return 'Heslo je příliš dlouhé.';
            }
            if (mb_strtolower($new) === mb_strtolower($user['username'])) {
                return 'Heslo nesmí být stejné jako jméno.';
            }
        }
        $current = trim($current) === '' ? '' : $current;
        $ok = preg_match('/^\d{6}$/', trim($current)) && $this->checkCode($user, $current);
        if (!$ok) {
            $ok = $this->checkPassword($user, $current);
        }
        $this->logAttempt($ip, (string) $user['username'], $ok);
        if (!$ok) {
            return !empty($user['password_hash']) ? 'Současné heslo ani kód nesouhlasí.' : 'Neplatný jednorázový kód.';
        }
        $this->db->query(
            'UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP() WHERE id = ?',
            [$new === null ? null : password_hash($new, PASSWORD_DEFAULT), $user['id']]
        );
        session_regenerate_id(true);
        return null;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}
