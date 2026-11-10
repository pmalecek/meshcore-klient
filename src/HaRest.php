<?php
declare(strict_types=1);

namespace MeshClient;

use RuntimeException;

/**
 * Volání služeb Home Assistantu přes REST API (používá web pro odesílání zpráv).
 */
final class HaRest
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly bool $verifyTls = true,
    ) {
    }

    public static function fromConfig(array $cfg): self
    {
        return new self(rtrim($cfg['url'], '/'), (string) $cfg['token'], (bool) ($cfg['verify_tls'] ?? true));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<mixed>
     */
    public function callService(string $domain, string $service, array $data, bool $returnResponse = false, int $timeout = 20): array
    {
        $url = "{$this->baseUrl}/api/services/{$domain}/{$service}" . ($returnResponse ? '?return_response' : '');
        $res = $this->request('POST', $url, $data, $timeout);
        // s ?return_response vrací HA {"changed_states": [...], "service_response": {...}}
        return $returnResponse && array_key_exists('service_response', $res) ? (array) $res['service_response'] : $res;
    }

    /** @return array<mixed> */
    public function get(string $path): array
    {
        return $this->request('GET', $this->baseUrl . $path);
    }

    /** @return array<mixed> */
    public function ping(): array
    {
        return $this->request('GET', "{$this->baseUrl}/api/");
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<mixed>
     */
    private function request(string $method, string $url, ?array $body = null, int $timeout = 20): array
    {
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException("Home Assistant nedostupný: $err");
        }
        if ($code >= 400) {
            $msg = json_decode((string) $resp, true)['message'] ?? trim(strip_tags((string) $resp));
            throw new RuntimeException("Home Assistant vrátil chybu $code: " . mb_substr($msg, 0, 200));
        }
        $data = json_decode((string) $resp, true);
        return is_array($data) ? $data : [];
    }
}
