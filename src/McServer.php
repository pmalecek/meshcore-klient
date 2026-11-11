<?php
declare(strict_types=1);

namespace MeshClient;

use RuntimeException;

/**
 * REST klient pro meshcore-server (https://github.com/pmalecek/meshcore-server), API /api/v1.
 * Chyby vyhazuje jako McServerException s HTTP kódem a srozumitelnou zprávou ze serveru („detail“).
 */
final class McServer
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly bool $verifyTls = true,
    ) {
    }

    public static function fromConfig(array $cfg): self
    {
        $s = $cfg['server'] ?? [];
        if (empty($s['url']) || empty($s['token'])) {
            throw new RuntimeException('Chybí nastavení meshcore-serveru (server.url, server.token)');
        }
        return new self(rtrim((string) $s['url'], '/'), (string) $s['token'], (bool) ($s['verify_tls'] ?? true));
    }

    /** Je klient nastavený na meshcore-server? */
    public static function enabled(array $cfg): bool
    {
        return ($cfg['backend'] ?? 'ha') === 'server';
    }

    /** URL WebSocketu s tokenem (worker) */
    public function wsUrl(?int $since = null): string
    {
        return preg_replace('#^http#', 'ws', $this->baseUrl) . '/api/v1/ws?token=' . rawurlencode($this->token)
            . ($since !== null ? '&since=' . $since : '');
    }

    public function verifyTls(): bool
    {
        return $this->verifyTls;
    }

    /** @return array<mixed> */
    public function get(string $path, array $query = [], int $timeout = 20): array
    {
        return $this->request('GET', $path . ($query ? '?' . http_build_query($query) : ''), null, $timeout);
    }

    /** @return array<mixed> */
    public function post(string $path, ?array $body = null, int $timeout = 30): array
    {
        return $this->request('POST', $path, $body ?? (object) [], $timeout);
    }

    /** @return array<mixed> */
    public function put(string $path, array $body, int $timeout = 30): array
    {
        return $this->request('PUT', $path, $body, $timeout);
    }

    /** @return array<mixed> */
    public function patch(string $path, array $body, int $timeout = 30): array
    {
        return $this->request('PATCH', $path, $body, $timeout);
    }

    /** @return array<mixed> */
    public function delete(string $path, int $timeout = 30): array
    {
        return $this->request('DELETE', $path, null, $timeout);
    }

    /** @return array<mixed> */
    private function request(string $method, string $path, array|object|null $body, int $timeout): array
    {
        $ch = curl_init($this->baseUrl . '/api/v1' . $path);
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_USERAGENT      => 'meshcore-klient',
        ]);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            throw new McServerException("meshcore-server nedostupný: $err", 0);
        }
        $data = $resp === '' ? [] : json_decode((string) $resp, true);
        if ($code >= 400) {
            throw new McServerException(self::errorText($code, $data, (string) $resp), $code);
        }
        return is_array($data) ? $data : [];
    }

    private static function errorText(int $code, mixed $data, string $raw): string
    {
        $d = is_array($data) ? ($data['detail'] ?? null) : null;
        if (is_array($d)) {
            // chyba validace FastAPI: [{loc, msg}]
            $d = implode('; ', array_map(static fn($x) => is_array($x) ? (string) ($x['msg'] ?? '') : (string) $x, $d));
        }
        $msg = is_string($d) && $d !== '' ? $d : mb_substr(trim($raw), 0, 200);
        return match ($code) {
            401, 403 => 'meshcore-server odmítl token: ' . $msg,
            503      => 'Companion není připojený k meshcore-serveru',
            default  => $msg !== '' ? $msg : "meshcore-server vrátil chybu $code",
        };
    }
}
