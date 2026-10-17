<?php
declare(strict_types=1);
namespace Hub;
final class Prompts
{
    public const PUBLIC_SQL = "p.visibility='public' AND p.status='published'";
    public function __construct(private DB $db, private Auth $auth) {}
    public function get(int $id): array
    {
        $p = $this->db->one(
            "SELECT p.*,c.name category,u.display_name author FROM prompts p JOIN categories c ON c.id=p.category_id JOIN users u ON u.id=p.user_id WHERE p.id=?",
            [$id]
        );
        if (!$p) {
            throw new HttpError(404, "ไม่พบ Prompt");
        }
        $u = $this->auth->user;
        if (
            !($p["visibility"] === "public" && $p["status"] === "published") &&
            (!$u ||
                ($u["role"] !== "admin" &&
                    (int) $u["id"] !== (int) $p["user_id"]))
        ) {
            throw new HttpError(404, "ไม่พบ Prompt");
        }
        return $p;
    }
    public function owned(int $id): array
    {
        $u = $this->auth->requireUser();
        $p = $this->get($id);
        if ($u["role"] !== "admin" && (int) $p["user_id"] !== (int) $u["id"]) {
            throw new HttpError(403, "ไม่มีสิทธิ์แก้ไข");
        }
        return $p;
    }
    public function save(array $d): int
    {
        $u = $this->auth->requireUser();
        $id = empty($d["id"]) ? 0 : number($d["id"]);
        $old = $id ? $this->owned($id) : null;
        $title = text($d, "title", 200, 1);
        $description = text($d, "description", 500, 1);
        $content = text($d, "content", 50000, 1);
        $usage = text($d, "usage_example", 10000);
        $output = text($d, "output_example", 10000);
        $cat = number($d["category_id"] ?? null);
        $c = $this->db->one("SELECT * FROM categories WHERE id=?", [$cat]);
        if (
            !$c ||
            (!$c["active"] && (!$old || (int) $old["category_id"] !== $cat))
        ) {
            throw new \DomainException("กรุณาเลือกหมวดหมู่ที่เปิดใช้งาน");
        }
        $visibility = text($d, "visibility", 10);
        if (!in_array($visibility, ["private", "public"], true)) {
            throw new \DomainException("การมองเห็นไม่ถูกต้อง");
        }
        $submit = ($d["submit_mode"] ?? "") === "review";
        $status =
            $visibility === "public" &&
            ($submit || ($old && $old["status"] === "published"))
                ? "pending"
                : "draft";
        $relations = [];
        foreach (
            ["tags" => "tags", "ai_tools" => "ai_tools"]
            as $key => $table
        ) {
            $values = $d[$key] ?? [];
            if (!is_array($values) || count($values) > 30) {
                throw new \DomainException("รายการที่เลือกไม่ถูกต้อง");
            }
            $relations[$key] = array_unique(
                array_map(__NAMESPACE__ . '\\number', $values)
            );
            foreach ($relations[$key] as $v) {
                if (!$this->db->one("SELECT id FROM $table WHERE id=?", [$v])) {
                    throw new \DomainException("ไม่พบรายการที่เลือก");
                }
            }
        }
        return $this->db->transaction(function () use (
            $id,
            $u,
            $cat,
            $title,
            $description,
            $content,
            $usage,
            $output,
            $visibility,
            $status,
            $relations
        ) {
            $args = [
                $cat,
                $title,
                $description,
                $content,
                $usage,
                $output,
                $visibility,
                $status,
            ];
            if ($id) {
                $this->db->run(
                    "UPDATE prompts SET category_id=?,title=?,description=?,content=?,usage_example=?,output_example=?,visibility=?,status=?,rejection_reason=NULL WHERE id=?",
                    [...$args, $id]
                );
            } else {
                $this->db->run(
                    "INSERT INTO prompts(category_id,title,description,content,usage_example,output_example,visibility,status,user_id) VALUES(?,?,?,?,?,?,?,?,?)",
                    [...$args, (int) $u["id"]]
                );
                $id = $this->db->id();
            }
            foreach (
                [
                    "tags" => ["prompt_tags", "tag_id"],
                    "ai_tools" => ["prompt_ai_tools", "ai_tool_id"],
                ]
                as $key => [$table, $column]
            ) {
                $this->db->run("DELETE FROM $table WHERE prompt_id=?", [$id]);
                foreach ($relations[$key] as $v) {
                    $this->db->run(
                        "INSERT INTO $table(prompt_id,$column) VALUES(?,?)",
                        [$id, $v]
                    );
                }
            }
            if ($u["role"] === "admin") {
                $this->db->run(
                    "INSERT INTO admin_activity_logs(admin_id,action,detail) VALUES(?,?,?)",
                    [(int) $u["id"], "prompt.save", (string) $id]
                );
            }
            return $id;
        });
    }
    public function listing(array $q, string $mode = "public"): array
    {
        $args = [];
        $where = [self::PUBLIC_SQL];
        if ($mode === "mine") {
            $u = $this->auth->requireUser();
            $where = ["p.user_id=?"];
            $args[] = (int) $u["id"];
        } elseif ($mode === "admin") {
            $this->auth->requireUser(true);
            $where = ["1=1"];
        } elseif ($mode === "favorites") {
            $u = $this->auth->requireUser();
            $where[] =
                "EXISTS(SELECT 1 FROM favorites f WHERE f.prompt_id=p.id AND f.user_id=?)";
            $args[] = (int) $u["id"];
        }
        $search = text($q, "q", 100);
        if ($search !== "") {
            $where[] =
                "(p.title LIKE ? OR p.description LIKE ? OR p.content LIKE ? OR EXISTS(SELECT 1 FROM prompt_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.prompt_id=p.id AND t.name LIKE ?))";
            for ($i = 0; $i < 4; $i++) {
                $args[] = "%" . $search . "%";
            }
        }
        foreach (
            [
                "category" => "p.category_id=?",
                "tag" =>
                    "EXISTS(SELECT 1 FROM prompt_tags pt WHERE pt.prompt_id=p.id AND pt.tag_id=?)",
                "tool" =>
                    "EXISTS(SELECT 1 FROM prompt_ai_tools pa WHERE pa.prompt_id=p.id AND pa.ai_tool_id=?)",
            ]
            as $key => $sql
        ) {
            if (!empty($q[$key])) {
                $where[] = $sql;
                $args[] = number($q[$key]);
            }
        }
        if ($mode === "admin" && !empty($q["status"])) {
            $s = text($q, "status", 20);
            if (
                !in_array(
                    $s,
                    ["draft", "pending", "published", "rejected"],
                    true
                )
            ) {
                throw new \DomainException("สถานะไม่ถูกต้อง");
            }
            $where[] = "p.status=?";
            $args[] = $s;
        }
        $condition = implode(" AND ", $where);
        $count = (int) $this->db->one(
            "SELECT COUNT(*) n FROM prompts p WHERE $condition",
            $args
        )["n"];
        $page = empty($q["page"])
            ? 1
            : min(number($q["page"]), max(1, (int) ceil($count / 12)));
        $sort = text($q, "sort", 20);
        if (!in_array($sort, ["", "latest", "copies"], true)) {
            throw new \DomainException("การเรียงลำดับไม่ถูกต้อง");
        }
        $order =
            $sort === "copies"
                ? "p.copy_count DESC,p.id DESC"
                : "p.created_at DESC,p.id DESC";
        $rows = $this->db->all(
            "SELECT p.*,c.name category,u.display_name author FROM prompts p JOIN categories c ON c.id=p.category_id JOIN users u ON u.id=p.user_id WHERE $condition ORDER BY $order LIMIT ? OFFSET ?",
            [...$args, 12, ($page - 1) * 12]
        );
        return compact("rows", "count", "page");
    }
}
