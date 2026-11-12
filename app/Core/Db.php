<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(string $path): PDO
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        }
        return self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ?? self::connect(self::path());
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function path(): string
    {
        $p = (string)env('DB_PATH', 'storage/db/srms.sqlite');
        if (preg_match('~^([A-Za-z]:[\\\\/]|/)~', $p) === 1) {
            return $p;
        }
        return APP_ROOT . '/' . $p;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::exec($sql, $params);
        return (int)self::pdo()->lastInsertId();
    }

    /** 쓰기 트랜잭션: 중첩 호출은 바깥 트랜잭션에 합류한다. */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $r = $fn();
            $pdo->exec('COMMIT');
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    /** 정수는 INTEGER 로 바인딩한다(문자열 바인딩이면 `:flag = 1` 같은 비교가 거짓이 된다). */
    private static function run(string $sql, array $params): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $type = match (true) {
                is_bool($v) => \PDO::PARAM_INT,
                is_int($v) => \PDO::PARAM_INT,
                $v === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };
            $st->bindValue(is_int($k) ? $k + 1 : (string)$k, is_bool($v) ? (int)$v : $v, $type);
        }
        $st->execute();
        return $st;
    }
}
