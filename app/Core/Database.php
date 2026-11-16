<?php
declare(strict_types=1);

namespace App\Core;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use RuntimeException;

/**
 * Thin, safe mysqli wrapper. Every query uses prepared statements.
 * Aborts on SQL errors (MYSQLI_REPORT_STRICT) so a partial transaction
 * can never be committed silently.
 */
final class Database
{
    private static ?mysqli $conn = null;
    private static int $txDepth = 0;

    public static function conn(): mysqli
    {
        if (self::$conn instanceof mysqli) {
            return self::$conn;
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(
            (string) Config::get('DB_HOST', '127.0.0.1'),
            (string) Config::get('DB_USER', 'root'),
            (string) Config::get('DB_PASS', ''),
            (string) Config::get('DB_NAME', 'pos_saas'),
            Config::int('DB_PORT', 3306)
        );
        $conn->set_charset('utf8mb4');
        $conn->query("SET time_zone = '+00:00'");
        $conn->query('SET SESSION sql_mode = "STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION"');
        self::$conn = $conn;
        return $conn;
    }

    public static function stmt(string $sql, array $params = []): mysqli_stmt
    {
        $stmt = self::conn()->prepare($sql);
        if ($params !== []) {
            $types = '';
            foreach ($params as $p) {
                $types .= match (true) {
                    is_int($p) => 'i',
                    is_float($p) => 'd',
                    default => 's',
                };
            }
            $refs = [];
            foreach ($params as $k => $v) {
                $refs[$k] = &$params[$k];
            }
            $stmt->bind_param($types, ...$refs);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::stmt($sql, $params);
        $res = $stmt->get_result();
        $rows = $res instanceof mysqli_result ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $rows = self::all($sql, $params);
        return $rows[0] ?? null;
    }

    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $row = self::one($sql, $params);
        if ($row === null) {
            return $default;
        }
        return array_values($row)[0];
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::stmt($sql, $params);
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        self::assertIdentifier($table);
        foreach ($cols as $c) {
            self::assertIdentifier($c);
        }
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        $stmt = self::stmt($sql, array_values($data));
        $id = (int) self::conn()->insert_id;
        $stmt->close();
        return $id;
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        self::assertIdentifier($table);
        $sets = [];
        foreach (array_keys($data) as $c) {
            self::assertIdentifier($c);
            $sets[] = '`' . $c . '` = ?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        $stmt = self::stmt($sql, array_merge(array_values($data), $whereParams));
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    /**
     * Run a closure inside a transaction. Nested calls reuse the outer
     * transaction (savepoints are not needed by this domain model).
     */
    public static function tx(callable $fn): mixed
    {
        if (self::$txDepth > 0) {
            self::$txDepth++;
            try {
                return $fn();
            } finally {
                self::$txDepth--;
            }
        }
        $conn = self::conn();
        $conn->begin_transaction();
        self::$txDepth = 1;
        try {
            $result = $fn();
            $conn->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $conn->rollback();
            } catch (\Throwable) {
                // already rolled back
            }
            throw $e;
        } finally {
            self::$txDepth = 0;
        }
    }

    public static function limitIdentifier(string $value): void
    {
        self::assertIdentifier($value);
    }

    private static function assertIdentifier(string $value): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new RuntimeException('Invalid SQL identifier: ' . $value);
        }
    }
}
