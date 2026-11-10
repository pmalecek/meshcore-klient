<?php
declare(strict_types=1);

namespace MeshClient;

use InvalidArgumentException;

/**
 * Nastavení vzhledu a oblíbených – ukládá se na server, sdílí se mezi zařízeními.
 */
final class Settings
{
    public const PATTERNS = ['dots', 'grid', 'diagonal', 'checks', 'circles'];
    private const IMAGE_RE = '/^[0-9a-f]{40}\.(jpg|png|webp|gif)$/';
    private const MAX_UPLOAD = 6 * 1024 * 1024;

    public function __construct(private readonly Db $db, private readonly string $bgDir)
    {
    }

    /** @return array{convs: array<string,array<string,mixed>>, default_bg: ?array} */
    public function all(): array
    {
        $convs = [];
        foreach ($this->db->all('SELECT conv_key, color, background, favorite, retention_days FROM conv_settings') as $r) {
            $convs[$r['conv_key']] = [
                'color'     => $r['color'],
                'bg'        => $r['background'] !== null ? json_decode($r['background'], true) : null,
                'fav'       => (bool) $r['favorite'],
                'retention' => $r['retention_days'] !== null ? (int) $r['retention_days'] : null,
            ];
        }
        $def = $this->db->value("SELECT v FROM app_settings WHERE k = 'default_bg'");
        return ['convs' => $convs, 'default_bg' => $def ? json_decode($def, true) : null, 'retention' => $this->retention()];
    }

    /** Výchozí doba uchování zpráv ve dnech, 0 = navždy */
    public function retention(): int
    {
        $v = $this->db->value("SELECT v FROM app_settings WHERE k = 'retention_days'");
        return $v !== null ? (int) json_decode((string) $v) : 0;
    }

    public function setRetention(int $days): void
    {
        $this->db->query(
            "INSERT INTO app_settings (k, v) VALUES ('retention_days', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
            [json_encode(self::validDays($days))]
        );
    }

    public static function validDays(mixed $d): int
    {
        $d = (int) $d;
        if ($d < 0 || $d > 3650) {
            throw new InvalidArgumentException('Neplatná doba uchování');
        }
        return $d;
    }

    /**
     * Částečná aktualizace – mění jen klíče, které jsou v $data.
     * @param array<string,mixed> $data
     */
    public function updateConv(string $conv, array $data): void
    {
        $this->db->query('INSERT IGNORE INTO conv_settings (conv_key) VALUES (?)', [$conv]);
        if (array_key_exists('color', $data)) {
            $color = $data['color'];
            if ($color !== null && !preg_match('/^#[0-9a-f]{6}$/i', (string) $color)) {
                throw new InvalidArgumentException('Neplatná barva');
            }
            $this->db->query('UPDATE conv_settings SET color = ? WHERE conv_key = ?', [$color ? strtolower($color) : null, $conv]);
        }
        if (array_key_exists('bg', $data)) {
            $bg = self::normalizeBg($data['bg']);
            $this->db->query('UPDATE conv_settings SET background = ? WHERE conv_key = ?',
                [$bg === null ? null : json_encode($bg), $conv]);
        }
        if (array_key_exists('retention', $data)) {
            $r = $data['retention'] === null || $data['retention'] === '' ? null : self::validDays($data['retention']);
            $this->db->query('UPDATE conv_settings SET retention_days = ? WHERE conv_key = ?', [$r, $conv]);
        }
        if (array_key_exists('fav', $data)) {
            $this->db->query('UPDATE conv_settings SET favorite = ? WHERE conv_key = ?', [$data['fav'] ? 1 : 0, $conv]);
        }
        // úklid prázdných záznamů
        $this->db->query('DELETE FROM conv_settings WHERE conv_key = ? AND color IS NULL AND background IS NULL AND favorite = 0 AND retention_days IS NULL', [$conv]);
    }

    public function setDefaultBg(mixed $bg): void
    {
        $bg = self::normalizeBg($bg);
        $this->db->query(
            "INSERT INTO app_settings (k, v) VALUES ('default_bg', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
            [$bg === null ? null : json_encode($bg)]
        );
    }

    /**
     * Ověří a normalizuje popis pozadí.
     * {type: none} | {type: color, color} | {type: pattern, value, color?} | {type: image, value, dim}
     */
    public static function normalizeBg(mixed $bg): ?array
    {
        if ($bg === null || $bg === '' || $bg === []) {
            return null; // = výchozí
        }
        if (!is_array($bg)) {
            throw new InvalidArgumentException('Neplatné pozadí');
        }
        $color = isset($bg['color']) && preg_match('/^#[0-9a-f]{6}$/i', (string) $bg['color']) ? strtolower($bg['color']) : null;
        switch ($bg['type'] ?? '') {
            case 'none':
                return ['type' => 'none'];
            case 'color':
                if ($color === null) {
                    throw new InvalidArgumentException('Chybí barva');
                }
                return ['type' => 'color', 'color' => $color];
            case 'pattern':
                if (!in_array($bg['value'] ?? '', self::PATTERNS, true)) {
                    throw new InvalidArgumentException('Neznámý vzor');
                }
                return ['type' => 'pattern', 'value' => $bg['value'], 'color' => $color];
            case 'image':
                if (!preg_match(self::IMAGE_RE, (string) ($bg['value'] ?? ''))) {
                    throw new InvalidArgumentException('Neplatný obrázek');
                }
                $dim = max(0.0, min(0.8, (float) ($bg['dim'] ?? 0.2)));
                return ['type' => 'image', 'value' => $bg['value'], 'dim' => round($dim, 2)];
        }
        throw new InvalidArgumentException('Neznámý typ pozadí');
    }

    // ---------- obrázky ----------

    /** @param array{tmp_name:string,error:int,size:int} $file */
    public function upload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Nahrání selhalo (kód ' . ($file['error'] ?? '?') . ')');
        }
        if ($file['size'] > self::MAX_UPLOAD) {
            throw new InvalidArgumentException('Obrázek je příliš velký');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
        if ($ext === null || @getimagesize($file['tmp_name']) === false) {
            throw new InvalidArgumentException('Podporované jsou jen obrázky JPG, PNG, WebP a GIF');
        }
        if (!is_dir($this->bgDir) && !mkdir($this->bgDir, 0775, true) && !is_dir($this->bgDir)) {
            throw new \RuntimeException('Nelze vytvořit adresář pro obrázky');
        }
        $name = sha1_file($file['tmp_name']) . '.' . $ext;
        $target = $this->bgDir . '/' . $name;
        if (!is_file($target) && !move_uploaded_file($file['tmp_name'], $target)) {
            throw new \RuntimeException('Obrázek se nepodařilo uložit');
        }
        return $name;
    }

    /** @return list<string> */
    public function images(): array
    {
        $files = is_dir($this->bgDir) ? (glob($this->bgDir . '/*.{jpg,png,webp,gif}', GLOB_BRACE) ?: []) : [];
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        return array_values(array_filter(array_map('basename', $files), static fn($f) => (bool) preg_match(self::IMAGE_RE, $f)));
    }

    public function deleteImage(string $name): void
    {
        if (!preg_match(self::IMAGE_RE, $name)) {
            throw new InvalidArgumentException('Neplatný obrázek');
        }
        @unlink($this->bgDir . '/' . $name);
        // nastavení, která obrázek používala, vrátíme na výchozí
        $this->db->query(
            "UPDATE conv_settings SET background = NULL WHERE JSON_VALUE(background, '$.value') = ?", [$name]
        );
        $this->db->query(
            "UPDATE app_settings SET v = NULL WHERE k = 'default_bg' AND JSON_VALUE(v, '$.value') = ?", [$name]
        );
    }

    public function imagePath(string $name): ?string
    {
        if (!preg_match(self::IMAGE_RE, $name)) {
            return null;
        }
        $p = $this->bgDir . '/' . $name;
        return is_file($p) ? $p : null;
    }
}
