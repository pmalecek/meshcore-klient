<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Rychlé reakce na zprávy (👍, ❤️ …).
 *
 * MeshCore žádný paket pro reakce nemá – aplikace (MeshCore One aj.) je posílají jako obyčejný text:
 *   kanál:  „@[autor]👍\nhash“  (starší verze „👍@[autor]\nhash“)
 *   DM:     „👍\nhash“
 * hash = prvních 40 bitů SHA-256( UTF-8 text původní zprávy + čas odesílatele jako u32 little-endian ),
 * zapsaných 8 znaky base32 (0-9 a-z bez i, l, o, u). U kanálu se hashuje text bez „jméno: “
 * a bez mezer a tabulátorů na krajích.
 *
 * Čas odesílatele zná jen režim meshcore-server (sloupec sender_ts). V režimu HA ho událost nenese,
 * proto se tam původní zpráva hledá zkusmo kolem času přijetí (WINDOW) a odesílat reakce nejde.
 */
final class Reactions
{
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';
    /** ± sekund kolem času přijetí, když čas odesílatele neznáme (hodiny uzlů se rozcházejí) */
    private const WINDOW = 180;
    /** Kolik dní nazpět hledat původní zprávu */
    private const LOOKBACK_DAYS = 7;
    /** Nabídka rychlých reakcí v rozhraní (posílá se v init) */
    public const QUICK = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ formát

    /** Otisk zprávy: 8 znaků base32 z prvních 40 bitů SHA-256(text + u32le(ts)). */
    public static function hash(string $text, int $senderTs): ?string
    {
        if ($senderTs < 0 || $senderTs > 0xFFFFFFFF) {
            return null;
        }
        $d = hash('sha256', $text . pack('V', $senderTs), true);
        $bits = 0;
        for ($i = 0; $i < 5; $i++) {
            $bits = ($bits << 8) | ord($d[$i]);
        }
        $out = '';
        for ($i = 7; $i >= 0; $i--) {
            $out .= self::ALPHABET[($bits >> ($i * 5)) & 31];
        }
        return $out;
    }

    /** Text, ze kterého se počítá otisk (kanál: bez mezer a tabulátorů na krajích). */
    public static function hashText(string $body, bool $channel): string
    {
        return $channel ? trim($body, " \t") : $body;
    }

    /** Otisk ve tvaru, jak přišel → kanonický (malá písmena, o→0, i/l→1), nebo null. */
    public static function normalizeHash(string $h): ?string
    {
        if (!preg_match('/^[0-9A-TV-Za-tv-z]{8}$/', $h)) {
            return null;
        }
        return strtr(strtolower($h), ['o' => '0', 'i' => '1', 'l' => '1']);
    }

    /** Jedno emoji (jeden grafém, který začíná emoji znakem). */
    public static function isEmoji(string $s): bool
    {
        if ($s === '' || strlen($s) > 32 || !preg_match('/^\X$/u', $s)) {
            return false;
        }
        $cps = mb_str_split($s);
        $cp = mb_ord($cps[0]);
        if ($cp > 0x238C) {
            return $cp < 0xE0000; // ne tagy apod.
        }
        // číslice, #, *, © apod. jsou emoji jen s variačním selektorem / keycapem
        return count($cps) > 1 && preg_match('/[\x{FE0F}\x{20E3}\x{200D}]/u', $s) === 1;
    }

    /**
     * Rozpozná reakci v textu zprávy.
     * @return array{emoji:string, target:?string, hash:string}|null
     */
    public static function parse(string $body, bool $channel): ?array
    {
        $n = strrpos($body, "\n");
        if ($n === false && $channel && str_contains($body, '@[')) {
            $n = strrpos($body, ' '); // některé cesty mění „\n“ na mezeru
        }
        if ($n === false) {
            return null;
        }
        $hash = self::normalizeHash(substr($body, $n + 1));
        if ($hash === null) {
            return null;
        }
        $head = substr($body, 0, $n);
        if (!$channel) {
            if (str_contains($head, '@[') || !self::isEmoji($head)) {
                return null;
            }
            return ['emoji' => $head, 'target' => null, 'hash' => $hash];
        }
        if (str_starts_with($head, '@[')) {
            $close = strpos($head, ']');
            if ($close === false) {
                return null;
            }
            $target = substr($head, 2, $close - 2);
            $emoji = substr($head, $close + 1);
        } else {
            $at = strpos($head, '@[');
            if ($at === false || !str_ends_with($head, ']')) {
                return null;
            }
            $emoji = substr($head, 0, $at);
            $target = substr($head, $at + 2, -1);
        }
        if ($target === '' || str_contains($target, ']') || !self::isEmoji($emoji)) {
            return null;
        }
        return ['emoji' => $emoji, 'target' => $target, 'hash' => $hash];
    }

    /** Text reakce k odeslání. */
    public static function build(bool $channel, ?string $target, string $emoji, string $hash): string
    {
        return $channel ? '@[' . $target . ']' . $emoji . "\n" . $hash : $emoji . "\n" . $hash;
    }

    // ------------------------------------------------------------------ zpracování uložených zpráv

    /** Po uložení zprávy: spočítat její otisk, rozpoznat reakci a propojit reakce s původní zprávou. */
    public function onInserted(int $id): void
    {
        $m = $this->db->one(
            'SELECT id, conv_key, direction, message_type, sender_name, author_pk, body, msg_ts, sender_ts, dup_of
             FROM messages WHERE id = ?',
            [$id]
        );
        if (!$m || !preg_match('/^[cd]:/', (string) $m['conv_key']) || $m['author_pk'] !== null) {
            return; // room servery (author_pk) mají vlastní formát textu
        }
        $channel = $m['message_type'] === 'channel';
        $body = (string) $m['body'];
        $own = $m['sender_ts'] !== null ? self::hash(self::hashText($body, $channel), (int) $m['sender_ts']) : null;
        $r = strlen($body) <= 200 ? self::parse($body, $channel) : null;

        if ($own === null && $r === null) {
            $this->attachPendingByWindow($m, $channel);
            return;
        }
        $this->db->query(
            'UPDATE messages SET react_hash = ?, reaction_emoji = ?, reaction_target = ?, reaction_hash = ? WHERE id = ?',
            [$own, $r['emoji'] ?? null, $r !== null && $r['target'] !== null ? mb_substr($r['target'], 0, 100) : null, $r['hash'] ?? null, $id]
        );
        if ($r !== null) {
            $of = $this->findTarget($m, $r, $channel);
            if ($of !== null) {
                $this->db->query('UPDATE messages SET reaction_of = ? WHERE id = ?', [$of, $id]);
            }
            return;
        }
        // reakce, které přišly dřív než původní zpráva (jiná cesta, zpoždění)
        $this->db->query(
            'UPDATE messages SET reaction_of = ? WHERE conv_key = ? AND reaction_hash = ? AND reaction_of IS NULL AND id <> ?',
            [$id, $m['conv_key'], $own, $id]
        );
    }

    /** ID původní zprávy pro reakci, nebo null. */
    private function findTarget(array $m, array $r, bool $channel): ?int
    {
        $id = $this->db->value(
            'SELECT id FROM messages WHERE conv_key = ? AND react_hash = ? AND reaction_emoji IS NULL AND dup_of IS NULL AND id <> ?
             ORDER BY id DESC LIMIT 1',
            [$m['conv_key'], $r['hash'], $m['id']]
        );
        if ($id) {
            return (int) $id;
        }
        // bez času odesílatele (režim HA, starší zprávy): zkusit časy kolem přijetí
        $params = [$m['conv_key'], $m['msg_ts'], $m['msg_ts'], $m['id']];
        $who = '';
        if ($channel) {
            $who = " AND (direction = 'out' OR sender_name = ?)";
            $params[] = $r['target'];
        }
        $rows = $this->db->all(
            "SELECT id, body, msg_ts FROM messages
             WHERE conv_key = ? AND sender_ts IS NULL AND reaction_emoji IS NULL AND dup_of IS NULL
               AND msg_ts BETWEEN ? - INTERVAL " . self::LOOKBACK_DAYS . " DAY AND ? + INTERVAL 1 MINUTE AND id <> ?$who
             ORDER BY id DESC LIMIT 100",
            $params
        );
        foreach ($rows as $c) {
            if ($this->windowMatch((string) $c['body'], (string) $c['msg_ts'], $r['hash'], $channel)) {
                return (int) $c['id'];
            }
        }
        return null;
    }

    /** Nová zpráva bez času odesílatele: nečeká na ni nějaká dřív přijatá reakce? */
    private function attachPendingByWindow(array $m, bool $channel): void
    {
        $params = [$m['conv_key'], $m['msg_ts'], $m['msg_ts']];
        $who = '';
        if ($channel && $m['direction'] === 'in') {
            $who = ' AND reaction_target = ?';
            $params[] = $m['sender_name'];
        }
        $pending = $this->db->all(
            "SELECT id, reaction_hash FROM messages
             WHERE conv_key = ? AND reaction_hash IS NOT NULL AND reaction_of IS NULL
               AND msg_ts BETWEEN ? - INTERVAL 10 MINUTE AND ? + INTERVAL 1 DAY$who LIMIT 20",
            $params
        );
        foreach ($pending as $p) {
            if ($this->windowMatch((string) $m['body'], (string) $m['msg_ts'], (string) $p['reaction_hash'], $channel)) {
                $this->db->query('UPDATE messages SET reaction_of = ? WHERE id = ?', [$m['id'], $p['id']]);
            }
        }
    }

    private function windowMatch(string $body, string $msgTs, string $hash, bool $channel): bool
    {
        $text = self::hashText($body, $channel);
        $t = (int) (new \DateTimeImmutable($msgTs, new \DateTimeZone('UTC')))->format('U');
        for ($d = 0; $d <= self::WINDOW; $d++) {
            if (self::hash($text, $t - $d) === $hash || ($d > 0 && self::hash($text, $t + $d) === $hash)) {
                return true;
            }
        }
        return false;
    }

    /** Jednorázově rozpoznat reakce v dřív uložených zprávách (po aktualizaci klienta). */
    public function backfill(): void
    {
        if ($this->db->value("SELECT v FROM worker_state WHERE k = 'reactions_backfill'") === '1') {
            return;
        }
        $this->db->query("INSERT INTO worker_state (k, v) VALUES ('reactions_backfill', '1') ON DUPLICATE KEY UPDATE v = '1'");
        $ids = $this->db->all(
            "SELECT id FROM messages
             WHERE reaction_emoji IS NULL AND author_pk IS NULL AND CHAR_LENGTH(body) BETWEEN 10 AND 120
               AND (body LIKE '%\n%' OR body LIKE '%@[%') AND msg_ts > UTC_TIMESTAMP() - INTERVAL 30 DAY
             ORDER BY id"
        );
        foreach ($ids as $r) {
            $this->onInserted((int) $r['id']);
        }
    }

    // ------------------------------------------------------------------ odeslání

    /**
     * Text reakce na zprávu $id. Vyžaduje čas odesílatele původní zprávy (režim meshcore-server).
     * @return array{conv:string, text:string}
     */
    public function textFor(int $id, string $emoji, string $selfName): array
    {
        if (!self::isEmoji($emoji)) {
            throw new \InvalidArgumentException('Neplatné emoji');
        }
        $m = $this->db->one('SELECT * FROM messages WHERE id = ?', [$id]);
        if (!$m || !preg_match('/^[cd]:/', (string) $m['conv_key'])) {
            throw new \InvalidArgumentException('Zpráva neexistuje');
        }
        if ($m['author_pk'] !== null) {
            throw new \InvalidArgumentException('Na zprávy v room serveru reagovat nejde');
        }
        if ($m['reaction_emoji'] !== null) {
            throw new \InvalidArgumentException('Na reakci reagovat nejde');
        }
        if ($m['dup_of'] !== null) {
            $m = $this->db->one('SELECT * FROM messages WHERE id = ?', [(int) $m['dup_of']]) ?: $m;
        }
        $channel = $m['message_type'] === 'channel';
        if ($m['sender_ts'] === null) {
            throw new \InvalidArgumentException('U téhle zprávy neznám čas odeslání, takže na ni reagovat nejde (starší zpráva).');
        }
        $hash = $m['react_hash'] ?: self::hash(self::hashText((string) $m['body'], $channel), (int) $m['sender_ts']);
        $target = null;
        if ($channel) {
            $target = $m['direction'] === 'out' ? ((string) $m['sender_name'] ?: $selfName) : (string) $m['sender_name'];
            if ($target === '') {
                throw new \InvalidArgumentException('Neznám autora zprávy');
            }
        }
        return ['conv' => (string) $m['conv_key'], 'text' => self::build($channel, $target, $emoji, (string) $hash)];
    }
}
