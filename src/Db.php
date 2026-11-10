<?php
declare(strict_types=1);

namespace MeshClient;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Tenká vrstva nad PDO s automatickým obnovením spojení
 * (worker běží dlouho a MariaDB po wait_timeout nečinné spojení zavírá).
 */
final class Db
{
    private ?PDO $pdo = null;

    /** @param array{dsn:string,user:string,password:string} $cfg */
    public function __construct(private readonly array $cfg)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->cfg['dsn'], $this->cfg['user'], $this->cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            $this->pdo->exec("SET time_zone = '+00:00'");
        }
        return $this->pdo;
    }

    /** @param array<int|string,mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            return $this->run($sql, $params);
        } catch (PDOException $e) {
            if (!self::isGoneAway($e)) {
                throw $e;
            }
            $this->pdo = null; // znovu připojit a zkusit ještě jednou
            return $this->run($sql, $params);
        }
    }

    /** @param array<int|string,mixed> $params */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function lastId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    public function ping(): void
    {
        $this->query('SELECT 1');
    }

    /** @param array<int|string,mixed> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private static function isGoneAway(PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return in_array($code, [2006, 2013, 4031], true)
            || str_contains($e->getMessage(), 'server has gone away')
            || str_contains($e->getMessage(), 'Lost connection');
    }
}
