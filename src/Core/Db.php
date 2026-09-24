<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                App::env('DB_HOST', '127.0.0.1'),
                (int) App::env('DB_PORT', 3306),
                App::env('DB_DATABASE', 'fiberlink_notifications')
            );
            self::$pdo = new PDO($dsn, (string) App::env('DB_USERNAME', ''), (string) App::env('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            // Mantém NOW() do banco alinhado ao fuso da aplicação (Brasil não tem horário de verão desde 2019).
            $offset = (new \DateTimeImmutable('now'))->format('P');
            self::$pdo->exec("SET time_zone = '" . $offset . "', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        return self::$pdo;
    }

    /** Descarta a conexão (usado por processos longos após erro de "server has gone away"). */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(static fn ($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(',', array_map(static fn ($c) => "`$c` = ?", array_keys($data)));
        return self::run("UPDATE `$table` SET $set WHERE $where", [...array_values($data), ...$params])->rowCount();
    }

    /** @template T @param callable():T $fn @return T */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function ping(): bool
    {
        try {
            return (int) self::value('SELECT 1') === 1;
        } catch (\Throwable) {
            self::reset();
            return false;
        }
    }
}
