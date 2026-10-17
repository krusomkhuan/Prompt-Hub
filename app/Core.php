<?php
declare(strict_types=1);
namespace Hub;
final class DB
{
    private \mysqli $link;
    public function __construct(array $c)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->link = new \mysqli(
            $c["host"],
            $c["username"],
            $c["password"],
            $c["database"],
            (int) $c["port"]
        );
        $this->link->set_charset("utf8mb4");
        $this->link->query("SET time_zone = '+00:00'");
    }
    public function run(string $sql, array $args = []): \mysqli_stmt
    {
        $s = $this->link->prepare($sql);
        if ($args) {
            $types = "";
            foreach ($args as $a) {
                $types .= is_int($a) ? "i" : "s";
            }
            $s->bind_param($types, ...$args);
        }
        $s->execute();
        return $s;
    }
    public function all(string $s, array $a = []): array
    {
        return $this->run($s, $a)
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);
    }
    public function one(string $s, array $a = []): ?array
    {
        return $this->all($s, $a)[0] ?? null;
    }
    public function id(): int
    {
        return (int) $this->link->insert_id;
    }
    public function transaction(callable $fn): mixed
    {
        $this->link->begin_transaction();
        try {
            $v = $fn();
            $this->link->commit();
            return $v;
        } catch (\Throwable $e) {
            $this->link->rollback();
            throw $e;
        }
    }
}
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}
function text(array $data, string $key, int $max, int $min = 0): string
{
    $v = $data[$key] ?? "";
    if (!is_string($v) || mb_strlen(trim($v)) < $min || mb_strlen($v) > $max) {
        throw new \DomainException(
            "ข้อมูล " . $key . " ไม่ถูกต้องหรือยาวเกินกำหนด"
        );
    }
    if (str_contains($key, "password")) {
        if (strlen($v) > 72) {
            throw new \DomainException("รหัสผ่านต้องไม่เกิน 72 ไบต์");
        }
        return $v;
    }
    return in_array($key, ["content", "usage_example", "output_example"], true)
        ? $v
        : trim($v);
}
function number(mixed $v): int
{
    if (!is_scalar($v) || !ctype_digit((string) $v) || (int) $v < 1) {
        throw new \DomainException("รหัสข้อมูลไม่ถูกต้อง");
    }
    return (int) $v;
}
function csrf(): string
{
    return '<input type="hidden" name="csrf" value="' .
        e($_SESSION["csrf"]) .
        '">';
}
function thaiTime(string $value): string
{
    return (new \DateTimeImmutable($value, new \DateTimeZone("UTC")))
        ->setTimezone(new \DateTimeZone("Asia/Bangkok"))
        ->format("d/m/Y H:i");
}
final class Auth
{
    public ?array $user = null;
    public function __construct(private DB $db)
    {
        if (isset($_SESSION["uid"])) {
            $this->user = $db->one(
                "SELECT * FROM users WHERE id=? AND active=1",
                [(int) $_SESSION["uid"]]
            );
            if (!$this->user) {
                unset($_SESSION["uid"]);
            }
        }
    }
    public function requireUser(bool $admin = false): array
    {
        if (!$this->user) {
            throw new HttpError(401, "กรุณาเข้าสู่ระบบ");
        }
        if ($admin && $this->user["role"] !== "admin") {
            throw new HttpError(403, "ไม่มีสิทธิ์เข้าถึง");
        }
        return $this->user;
    }
    public function login(string $login, string $password): void
    {
        $bucket = hash(
            "sha256",
            strtolower($login) . "|" . ($_SERVER["REMOTE_ADDR"] ?? "cli")
        );
        $this->db->run(
            "DELETE FROM login_attempts WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY"
        );
        $this->db->transaction(function () use ($bucket, $login, $password) {
            $this->db->run(
                "INSERT IGNORE INTO login_attempts(bucket,window_start) VALUES(?,UTC_TIMESTAMP())",
                [$bucket]
            );
            $a = $this->db->one(
                "SELECT *,window_start > UTC_TIMESTAMP()-INTERVAL 15 MINUTE AS recent FROM login_attempts WHERE bucket=? FOR UPDATE",
                [$bucket]
            );
            if (!$a["recent"]) {
                $this->db->run(
                    "UPDATE login_attempts SET attempts=0,window_start=UTC_TIMESTAMP() WHERE bucket=?",
                    [$bucket]
                );
                $a["attempts"] = 0;
            }
            if ($a["attempts"] >= 5) {
                throw new HttpError(429, "ลองใหม่หลังจาก 15 นาที");
            }
            $u = $this->db->one(
                "SELECT * FROM users WHERE username=? OR email=?",
                [$login, $login]
            );
            if (
                !$u ||
                !$u["active"] ||
                !password_verify($password, $u["password_hash"])
            ) {
                $this->db->run(
                    "UPDATE login_attempts SET attempts=attempts+1 WHERE bucket=?",
                    [$bucket]
                );
                return;
            }
            $this->db->run("DELETE FROM login_attempts WHERE bucket=?", [
                $bucket,
            ]);
            session_regenerate_id(true);
            $_SESSION["uid"] = $u["id"];
            $this->user = $u;
        });
        if (!$this->user) {
            throw new \DomainException("ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง");
        }
    }
}
final class HttpError extends \RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}
