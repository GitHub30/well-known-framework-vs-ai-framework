<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOStatement;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $sqlitePath) {}

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO('sqlite:' . $this->sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA synchronous = NORMAL;');
        }
        return $this->pdo;
    }

    /** @param array<int|string, mixed> $params @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<int|string, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->run($sql, $params)->fetchColumn();
    }

    /** @param array<int|string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo()->lastInsertId();
    }

    /** @template T @param callable(self): T $fn @return T */
    public function transaction(callable $fn, bool $immediate = false): mixed
    {
        $pdo = $this->pdo();
        if ($immediate) {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $fn($this);
            if ($immediate) {
                $pdo->exec('COMMIT');
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            try {
                if ($immediate) {
                    $pdo->exec('ROLLBACK');
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /** @param array<int|string, mixed> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
