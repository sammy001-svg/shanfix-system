<?php
namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper. Single shared connection, prepared statements only.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'] ?? 3306,
            $cfg['database'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // Reject zero dates and silent truncation so bad data fails loudly.
            self::$pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            throw new \RuntimeException('Database not initialised. Call Database::connect() first.');
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch a single scalar from the first column of the first row. */
    public static function scalar(string $sql, array $params = [], mixed $default = null): mixed
    {
        $val = self::run($sql, $params)->fetchColumn();
        return $val === false ? $default : $val;
    }

    /**
     * Insert an associative array into $table. Returns the new id.
     */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $cols) . '`',
            ':' . implode(', :', $cols)
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Update rows matching $where (column => value). Returns affected row count.
     */
    public static function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            return 0;
        }

        $set = [];
        $params = [];
        foreach ($data as $col => $val) {
            $set[] = "`{$col}` = :set_{$col}";
            $params["set_{$col}"] = $val;
        }

        $cond = [];
        foreach ($where as $col => $val) {
            $cond[] = "`{$col}` = :where_{$col}";
            $params["where_{$col}"] = $val;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $set),
            implode(' AND ', $cond)
        );

        return self::run($sql, $params)->rowCount();
    }

    public static function delete(string $table, array $where): int
    {
        $cond = [];
        foreach (array_keys($where) as $col) {
            $cond[] = "`{$col}` = :{$col}";
        }
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, implode(' AND ', $cond));
        return self::run($sql, $where)->rowCount();
    }

    public static function begin(): void
    {
        if (!self::pdo()->inTransaction()) {
            self::pdo()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    /**
     * Run $fn inside a transaction, rolling back on any exception.
     */
    public static function transaction(callable $fn): mixed
    {
        self::begin();
        try {
            $result = $fn();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }
}
