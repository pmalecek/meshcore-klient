<?php
declare(strict_types=1);

namespace MeshClient;

use RuntimeException;

/**
 * Minimalistický WebSocket klient (RFC 6455) bez závislostí.
 * Umí ws:// i wss://, fragmentované zprávy, ping/pong a close.
 */
final class WebSocketClient
{
    /** @var resource|null */
    private $sock = null;
    private string $buffer = '';
    private string $fragments = '';
    private ?int $fragOpcode = null;

    public function __construct(
        private readonly string $url,
        private readonly bool $verifyTls = true,
        private readonly int $timeout = 10,
    ) {
    }

    public function connect(): void
    {
        $p = parse_url($this->url);
        if (!$p || empty($p['host'])) {
            throw new RuntimeException("Neplatná URL: {$this->url}");
        }
        $secure = ($p['scheme'] ?? 'ws') === 'wss';
        $host   = $p['host'];
        $port   = $p['port'] ?? ($secure ? 443 : 80);
        $path   = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');

        $ctx = stream_context_create(['ssl' => [
            'verify_peer'      => $this->verifyTls,
            'verify_peer_name' => $this->verifyTls,
            'SNI_enabled'      => true,
            'peer_name'        => $host,
        ]]);
        $remote = ($secure ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $sock = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new RuntimeException("Nelze se připojit k $remote: $errstr ($errno)");
        }
        stream_set_timeout($sock, $this->timeout);

        $key = base64_encode(random_bytes(16));
        $hostHeader = $host . (isset($p['port']) ? ':' . $p['port'] : '');
        $req = "GET $path HTTP/1.1\r\n"
            . "Host: $hostHeader\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "User-Agent: meshcore-klient\r\n\r\n";
        fwrite($sock, $req);

        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = fread($sock, 1024);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($sock);
                fclose($sock);
                throw new RuntimeException($meta['timed_out'] ? 'Timeout při handshake' : 'Spojení uzavřeno při handshake');
            }
            $head .= $chunk;
            if (strlen($head) > 16384) {
                fclose($sock);
                throw new RuntimeException('Příliš dlouhá hlavička odpovědi');
            }
        }
        [$headers, $rest] = explode("\r\n\r\n", $head, 2);
        if (!preg_match('#^HTTP/1\.[01] 101#', $headers)) {
            fclose($sock);
            throw new RuntimeException('Handshake selhal: ' . strtok($headers, "\r\n"));
        }
        $expect = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $headers, $m) || trim($m[1]) !== $expect) {
            fclose($sock);
            throw new RuntimeException('Neplatný Sec-WebSocket-Accept');
        }

        stream_set_blocking($sock, false);
        $this->sock = $sock;
        $this->buffer = $rest;
        $this->fragments = '';
        $this->fragOpcode = null;
    }

    public function isConnected(): bool
    {
        return $this->sock !== null && !feof($this->sock);
    }

    public function sendText(string $data): void
    {
        $this->sendFrame(0x1, $data);
    }

    public function sendJson(array $data): void
    {
        $this->sendText(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Počká až $timeout sekund na data a vrátí všechny kompletní textové zprávy.
     * @return list<string>
     */
    public function receive(float $timeout): array
    {
        if ($this->sock === null) {
            throw new RuntimeException('Nepřipojeno');
        }
        $messages = $this->parseFrames();
        if ($messages) {
            return $messages;
        }
        $r = [$this->sock];
        $w = $e = null;
        $sec  = (int) $timeout;
        $usec = (int) (($timeout - $sec) * 1_000_000);
        $n = @stream_select($r, $w, $e, $sec, $usec);
        if ($n === false) {
            return []; // přerušeno signálem
        }
        if ($n > 0) {
            while (true) {
                $chunk = fread($this->sock, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $this->buffer .= $chunk;
            }
            if (feof($this->sock)) {
                $messages = $this->parseFrames();
                $this->close(false);
                if (!$messages) {
                    throw new RuntimeException('Spojení uzavřeno protistranou');
                }
                return $messages;
            }
        }
        return $this->parseFrames();
    }

    public function close(bool $graceful = true): void
    {
        if ($this->sock !== null) {
            if ($graceful) {
                try {
                    $this->sendFrame(0x8, pack('n', 1000));
                } catch (\Throwable) {
                }
            }
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    /** @return list<string> */
    private function parseFrames(): array
    {
        $out = [];
        while (true) {
            $len = strlen($this->buffer);
            if ($len < 2) {
                break;
            }
            $b1 = ord($this->buffer[0]);
            $b2 = ord($this->buffer[1]);
            $fin    = ($b1 & 0x80) !== 0;
            $opcode = $b1 & 0x0F;
            $masked = ($b2 & 0x80) !== 0;
            $plen   = $b2 & 0x7F;
            $off = 2;
            if ($plen === 126) {
                if ($len < 4) break;
                $plen = unpack('n', substr($this->buffer, 2, 2))[1];
                $off = 4;
            } elseif ($plen === 127) {
                if ($len < 10) break;
                $plen = unpack('J', substr($this->buffer, 2, 8))[1];
                $off = 10;
            }
            $mask = '';
            if ($masked) {
                if ($len < $off + 4) break;
                $mask = substr($this->buffer, $off, 4);
                $off += 4;
            }
            if ($len < $off + $plen) {
                break;
            }
            $payload = substr($this->buffer, $off, $plen);
            $this->buffer = (string) substr($this->buffer, $off + $plen);
            if ($masked) {
                $payload = $this->applyMask($payload, $mask);
            }

            switch ($opcode) {
                case 0x0: // pokračování
                    $this->fragments .= $payload;
                    if ($fin && $this->fragOpcode !== null) {
                        if ($this->fragOpcode === 0x1) {
                            $out[] = $this->fragments;
                        }
                        $this->fragments = '';
                        $this->fragOpcode = null;
                    }
                    break;
                case 0x1: // text
                case 0x2: // binární
                    if ($fin) {
                        if ($opcode === 0x1) {
                            $out[] = $payload;
                        }
                    } else {
                        $this->fragOpcode = $opcode;
                        $this->fragments = $payload;
                    }
                    break;
                case 0x8: // close
                    $this->close(false);
                    throw new RuntimeException('Server ukončil spojení');
                case 0x9: // ping
                    $this->sendFrame(0xA, $payload);
                    break;
                case 0xA: // pong
                    break;
            }
        }
        return $out;
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        if ($this->sock === null) {
            throw new RuntimeException('Nepřipojeno');
        }
        $len = strlen($payload);
        $frame = chr(0x80 | $opcode);
        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $frame .= $mask . $this->applyMask($payload, $mask);

        // socket je neblokující – zapisujeme ve smyčce
        $written = 0;
        $total = strlen($frame);
        $deadline = microtime(true) + $this->timeout;
        while ($written < $total) {
            $n = @fwrite($this->sock, substr($frame, $written));
            if ($n === false) {
                throw new RuntimeException('Zápis do socketu selhal');
            }
            $written += $n;
            if ($written < $total) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Timeout při zápisu');
                }
                usleep(10_000);
            }
        }
    }

    private function applyMask(string $data, string $mask): string
    {
        $len = strlen($data);
        if ($len === 0) {
            return '';
        }
        $full = str_repeat($mask, intdiv($len, 4) + 1);
        return $data ^ substr($full, 0, $len);
    }
}
