<?php
declare(strict_types=1);

namespace PromptHub\Core;

use mysqli;
use mysqli_stmt;

/**
 * ตัวห่อ MySQLi ที่บังคับใช้ Prepared Statements ทุกคำสั่งที่มีค่าจากภายนอก
 * ทุก connection ใช้ utf8mb4 และ time_zone UTC
 */
final class Database
{
    private mysqli $conn;
    private int $transactionDepth = 0;

    public function __construct(array $cfg)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->conn = new mysqli(
            (string) $cfg['host'],
            (string) $cfg['user'],
            (string) $cfg['pass'],
            (string) $cfg['name'],
            (int) ($cfg['port'] ?? 3306)
        );
        $this->conn->set_charset('utf8mb4');
        $this->conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->conn->query("SET time_zone = '+00:00'");
    }

    /** เตรียมและรันคำสั่ง SQL พร้อมผูกค่าด้วย bind_param */
    public function run(string $sql, array $params = []): mysqli_stmt
    {
        $stmt = $this->conn->prepare($sql);
        if ($params) {
            $types = '';
            $values = [];
            foreach ($params as $p) {
                if (is_int($p) || is_bool($p)) {
                    $types .= 'i';
                    $values[] = (int) $p;
                } elseif (is_float($p)) {
                    $types .= 'd';
                    $values[] = $p;
                } else {
                    $types .= 's';
                    $values[] = $p === null ? null : (string) $p;
                }
            }
            $stmt->bind_param($types, ...$values);
        }
        $stmt->execute();
        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->run($sql, $params);
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql, $params);
        return $rows[0] ?? null;
    }

    /** คืนค่าคอลัมน์แรกของแถวแรก */
    public function value(string $sql, array $params = [])
    {
        $row = $this->one($sql, $params);
        return $row === null ? null : array_values($row)[0];
    }

    /** รันคำสั่งเปลี่ยนข้อมูล คืนจำนวนแถวที่ได้รับผล */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->run($sql, $params);
        $affected = $stmt->affected_rows;
        $stmt->close();
        return (int) $affected;
    }

    public function lastId(): int
    {
        return (int) $this->conn->insert_id;
    }

    /** รันฟังก์ชันใน Transaction ถ้าเกิด exception จะ rollback ทั้งหมด (รองรับการเรียกซ้อน) */
    public function transaction(callable $fn)
    {
        if ($this->transactionDepth > 0) {
            return $fn();
        }
        $this->conn->begin_transaction();
        $this->transactionDepth++;
        try {
            $result = $fn();
            $this->conn->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->conn->rollback();
            throw $e;
        } finally {
            $this->transactionDepth--;
        }
    }

    /** สร้าง placeholder ?,?,? ตามจำนวนค่า (ใช้กับ IN) */
    public static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, max(1, $count), '?'));
    }

    /** escape อักขระ wildcard ของ LIKE ก่อนนำไปผูกเป็นพารามิเตอร์ */
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '\\%_') . '%';
    }
}
