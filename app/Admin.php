<?php
declare(strict_types=1);
namespace Hub;
final class Admin
{
    public function __construct(private DB $db, private Auth $auth) {}
    public function act(string $action, array $d): void
    {
        $u = $this->auth->requireUser(true);
        $this->db->transaction(function () use ($action, $d, $u) {
            $id = empty($d["id"]) ? 0 : number($d["id"]);
            switch ($action) {
                case "moderate":
                    $p = $this->db->one(
                        "SELECT * FROM prompts WHERE id=? FOR UPDATE",
                        [$id]
                    );
                    if (
                        !$p ||
                        $p["visibility"] !== "public" ||
                        $p["status"] !== "pending"
                    ) {
                        throw new \DomainException(
                            "ตรวจสอบได้เฉพาะรายการสาธารณะที่รอตรวจสอบ"
                        );
                    }
                    $s = text($d, "status", 20);
                    if (!in_array($s, ["published", "rejected"], true)) {
                        throw new \DomainException("สถานะไม่ถูกต้อง");
                    }
                    $reason = text(
                        $d,
                        "reason",
                        1000,
                        $s === "rejected" ? 1 : 0
                    );
                    $this->db->run(
                        "UPDATE prompts SET status=?,rejection_reason=? WHERE id=?",
                        [$s, $s === "rejected" ? $reason : null, $id]
                    );
                    break;
                case "user":
                    $admins = $this->db->all(
                        "SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id FOR UPDATE"
                    );
                    $target = $this->db->one(
                        "SELECT * FROM users WHERE id=? FOR UPDATE",
                        [$id]
                    );
                    if (!$target) {
                        throw new HttpError(404, "ไม่พบสมาชิก");
                    }
                    $delete = ($d["operation"] ?? "") === "delete";
                    $active = $delete
                        ? 0
                        : (($d["active"] ?? "") === "1"
                            ? 1
                            : 0);
                    $role = text($d, "role", 10);
                    if (!in_array($role, ["admin", "member"], true)) {
                        throw new \DomainException("บทบาทไม่ถูกต้อง");
                    }
                    if (
                        (int) $u["id"] === $id &&
                        (!$active || $role !== "admin")
                    ) {
                        throw new \DomainException(
                            "ไม่สามารถระงับหรือลดสิทธิ์บัญชีที่กำลังใช้งาน"
                        );
                    }
                    if (
                        $target["role"] === "admin" &&
                        $target["active"] &&
                        (!$active || $role !== "admin") &&
                        count($admins) <= 1
                    ) {
                        throw new \DomainException(
                            "ต้องมีผู้ดูแลที่ใช้งานได้อย่างน้อยหนึ่งคน"
                        );
                    }
                    if ($delete) {
                        $this->db->run("DELETE FROM users WHERE id=?", [$id]);
                    } else {
                        $this->db->run(
                            "UPDATE users SET active=?,role=? WHERE id=?",
                            [$active, $role, $id]
                        );
                    }
                    break;
                case "taxonomy":
                    $kind = text($d, "kind", 20);
                    $table = match ($kind) {
                        "categories" => "categories",
                        "tags" => "tags",
                        "ai_tools" => "ai_tools",
                        default => throw new \DomainException(
                            "ประเภทไม่ถูกต้อง"
                        ),
                    };
                    $delete = ($d["operation"] ?? "") === "delete";
                    if ($delete) {
                        if ($table === "categories") {
                            $move = empty($d["move_to"])
                                ? 0
                                : number($d["move_to"]);
                            $used = $this->db->one(
                                "SELECT COUNT(*) n FROM prompts WHERE category_id=?",
                                [$id]
                            );
                            if ($used["n"]) {
                                if (
                                    $move === $id ||
                                    !$this->db->one(
                                        "SELECT id FROM categories WHERE id=? AND active=1",
                                        [$move]
                                    )
                                ) {
                                    throw new \DomainException(
                                        "เลือกหมวดหมู่ปลายทางที่เปิดใช้งานก่อนลบ"
                                    );
                                }
                                $this->db->run(
                                    "UPDATE prompts SET category_id=? WHERE category_id=?",
                                    [$move, $id]
                                );
                            }
                        }
                        $this->db->run("DELETE FROM $table WHERE id=?", [$id]);
                    } else {
                        $name = text(
                            $d,
                            "name",
                            $table === "categories" ? 100 : 80,
                            1
                        );
                        if ($table === "categories") {
                            $active = isset($d["active"]) ? 1 : 0;
                            $sort = filter_var(
                                $d["sort_order"] ?? 0,
                                FILTER_VALIDATE_INT
                            );
                            if ($sort === false || abs($sort) > 100000) {
                                throw new \DomainException("ลำดับไม่ถูกต้อง");
                            }
                            if ($id) {
                                $this->db->run(
                                    "UPDATE categories SET name=?,active=?,sort_order=? WHERE id=?",
                                    [$name, $active, $sort, $id]
                                );
                            } else {
                                $this->db->run(
                                    "INSERT INTO categories(name,active,sort_order) VALUES(?,?,?)",
                                    [$name, $active, $sort]
                                );
                            }
                        } else {
                            if ($id) {
                                $this->db->run(
                                    "UPDATE $table SET name=? WHERE id=?",
                                    [$name, $id]
                                );
                            } else {
                                $this->db->run(
                                    "INSERT INTO $table(name) VALUES(?)",
                                    [$name]
                                );
                            }
                        }
                    }
                    break;
                case "settings":
                    $this->db->run(
                        "UPDATE site_settings SET name=?,slogan=?,description=? WHERE id=1",
                        [
                            text($d, "name", 100, 1),
                            text($d, "slogan", 150, 1),
                            text($d, "description", 1000, 1),
                        ]
                    );
                    break;
                default:
                    throw new HttpError(400, "คำสั่งไม่ถูกต้อง");
            }
            $this->db->run(
                "INSERT INTO admin_activity_logs(admin_id,action,detail) VALUES(?,?,?)",
                [
                    (int) $u["id"],
                    $action,
                    json_encode(
                        [
                            "id" => $id,
                            "kind" => $d["kind"] ?? null,
                            "status" => $d["status"] ?? null,
                            "reason" => $d["reason"] ?? null,
                        ],
                        JSON_UNESCAPED_UNICODE
                    ),
                ]
            );
        });
    }
}
