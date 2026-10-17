<?php
use function Hub\{e, text, number, csrf, thaiTime};
function input(
    string $name,
    string $label,
    string $value = "",
    string $type = "text",
    bool $required = true,
    int $max = 200
): void {
    echo '<div class="mb-3"><label class="form-label" for="' .
        e($name) .
        '">' .
        e($label) .
        '</label><input class="form-control" id="' .
        e($name) .
        '" name="' .
        e($name) .
        '" type="' .
        e($type) .
        '" value="' .
        e($value) .
        '" maxlength="' .
        $max .
        '" ' .
        ($required ? "required" : "") .
        " " .
        ($type === "password" ? 'autocomplete="new-password"' : "") .
        "></div>";
}
function area(
    string $name,
    string $label,
    string $value = "",
    bool $required = false,
    int $max = 10000
): void {
    echo '<div class="mb-3"><label class="form-label" for="' .
        e($name) .
        '">' .
        e($label) .
        '</label><textarea rows="6" class="form-control" id="' .
        e($name) .
        '" name="' .
        e($name) .
        '" maxlength="' .
        $max .
        '" ' .
        ($required ? "required" : "") .
        ">" .
        e($value) .
        "</textarea></div>";
}
function statusLabel(string $s): string
{
    return [
        "draft" => "ฉบับร่าง",
        "pending" => "รอตรวจสอบ",
        "published" => "เผยแพร่",
        "rejected" => "ไม่อนุมัติ",
    ][$s] ?? $s;
}
function cards(array $rows, bool $manage = false): void
{
    global $db;
    if (!$rows) {
        echo '<div class="empty"><i class="bi bi-search"></i><h3>ยังไม่มี Prompt ที่ตรงกับรายการนี้</h3><p>ลองเปลี่ยนคำค้นหาหรือตัวกรอง</p></div>';
        return;
    }
    echo '<div class="row g-4">';
    foreach ($rows as $p) {
        echo '<article class="col-md-6 col-lg-4"><div class="card h-100 p-4"><span class="text-primary small">' .
            e($p["category"]) .
            '</span><h3 class="h5 mt-2">' .
            e($p["title"]) .
            '</h3><p class="text-secondary">' .
            e($p["description"]) .
            '</p><div class="mb-3">';
        foreach (
            $db->all(
                "SELECT t.name FROM tags t JOIN prompt_tags pt ON pt.tag_id=t.id WHERE pt.prompt_id=?",
                [(int) $p["id"]]
            )
            as $t
        ) {
            echo '<span class="badge text-bg-light me-1">#' .
                e($t["name"]) .
                "</span>";
        }
        echo '</div><div class="mt-auto"><small class="text-secondary">' .
            e($p["author"]) .
            " · คัดลอก " .
            e($p["copy_count"]) .
            ' ครั้ง</small><a class="btn btn-outline-primary w-100 mt-3" href="' .
            e(url("detail", ["id" => $p["id"]])) .
            '">ดูรายละเอียด <i class="bi bi-arrow-right"></i></a>';
        if ($manage) {
            echo '<p class="mt-2 mb-0">' .
                e(statusLabel($p["status"])) .
                " · " .
                e($p["visibility"] === "private" ? "ส่วนตัว" : "สาธารณะ") .
                '</p><a href="' .
                e(url("edit", ["id" => $p["id"]])) .
                '">แก้ไข</a>';
        }
        echo "</div></div></article>";
    }
    echo "</div>";
}
function filters(): void
{
    global $categories,
        $tags,
        $tools; ?><form method="get" class="card p-3 mb-4"><input type="hidden" name="r" value="<?= e(
    $_GET["r"] ?? "browse"
) ?>"><div class="row g-2"><div class="col-lg-4"><label for="search" class="visually-hidden">ค้นหา Prompt</label><input id="search" class="form-control" name="q" maxlength="100" placeholder="วันนี้อยากให้ AI ช่วยอะไร?" value="<?= e(
    $_GET["q"] ?? ""
) ?>"></div><?php foreach (
    [
        "category" => ["หมวดหมู่", $categories],
        "tag" => ["แท็ก", $tags],
        "tool" => ["เครื่องมือ AI", $tools],
    ]
    as $key => [$label, $rows]
): ?><div class="col-6 col-lg"><label class="visually-hidden" for="filter-<?= e(
    $key
) ?>"><?= e($label) ?></label><select class="form-select" id="filter-<?= e(
    $key
) ?>" name="<?= e($key) ?>"><option value=""><?= e(
    $label
) ?>ทั้งหมด</option><?php foreach ($rows as $row): ?><option value="<?= e(
    $row["id"]
) ?>" <?= (string) $row["id"] === ($_GET[$key] ?? "")
    ? "selected"
    : "" ?>><?= e(
    $row["name"]
) ?></option><?php endforeach; ?></select></div><?php endforeach; ?><div class="col-6 col-lg"><label class="visually-hidden" for="sort">เรียงตาม</label><select class="form-select" id="sort" name="sort"><option value="latest">ล่าสุด</option><option value="copies" <?= ($_GET[
    "sort"
] ??
    "") ===
"copies"
    ? "selected"
    : "" ?>>คัดลอกมากที่สุด</option></select></div><div class="col-lg-auto"><button class="btn btn-primary w-100">ค้นหา</button></div></div></form><?php
}
function pages(int $count, int $current): void
{
    if ($count <= 12) {
        return;
    }
    echo '<nav class="mt-4 d-flex flex-wrap gap-2" aria-label="เปลี่ยนหน้า">';
    $total = (int) ceil($count / 12);
    foreach (
        array_unique([
            1,
            max(1, $current - 1),
            $current,
            min($total, $current + 1),
            $total,
        ])
        as $n
    ) {
        $args = $_GET;
        unset($args["r"]);
        $args["page"] = $n;
        echo '<a class="btn ' .
            ($n === $current ? "btn-primary" : "btn-outline-primary") .
            '" href="' .
            e(url($_GET["r"] ?? "browse", $args)) .
            '">' .
            $n .
            "</a>";
    }
    echo "</nav>";
}
if (
    in_array(
        $route,
        ["dashboard", "mine", "edit", "favorites", "profile"],
        true
    )
): ?><nav class="subnav mb-4"><a href="<?= e(
    url("dashboard")
) ?>">ภาพรวม</a><a href="<?= e(url("mine")) ?>">Prompt ของฉัน</a><a href="<?= e(
    url("edit")
) ?>">เพิ่ม Prompt</a><a href="<?= e(
    url("favorites")
) ?>">รายการโปรด</a><a href="<?= e(
    url("profile")
) ?>">โปรไฟล์</a></nav><?php endif;
if (
    str_starts_with($route, "admin")
): ?><nav class="subnav mb-4"><?php foreach (
    [
        "admin" => "ภาพรวม",
        "admin_users" => "สมาชิก",
        "admin_prompts" => "Prompt / อนุมัติ",
        "admin_taxonomy" => "หมวดหมู่ / แท็ก / AI",
        "admin_settings" => "ตั้งค่า",
        "admin_logs" => "บันทึกกิจกรรม",
    ]
    as $r => $l
): ?><a href="<?= e(url($r)) ?>"><?= e(
    $l
) ?></a><?php endforeach; ?></nav><?php endif;
switch ($route): case "home": ?>
<section class="hero mb-5"><span class="badge rounded-pill bg-white text-primary mb-3">พื้นที่แบ่งปันไอเดียสำหรับทุกคน</span><h1>ให้ทุกความคิด<br><span>ไปต่อได้ด้วย AI</span></h1><p class="lead"><?= e(
    $site["name"]
) ?> — <?= e($site["slogan"]) ?></p><p><?= e(
    $site["description"]
) ?></p><form action="index.php" class="d-flex gap-2 mx-auto search-hero"><input type="hidden" name="r" value="browse"><label class="visually-hidden" for="hero-q">ค้นหา Prompt</label><input id="hero-q" class="form-control form-control-lg" name="q" maxlength="100" placeholder="ค้นหาไอเดีย เช่น แผนการสอน การตลาด..."><button class="btn btn-primary">ค้นหา</button></form><a href="<?= e(
    url("browse")
) ?>" class="btn btn-outline-primary mt-3">สำรวจ Prompt ทั้งหมด</a></section><h2 class="h4 mb-3">เลือกสิ่งที่คุณอยากทำ</h2><div class="d-flex flex-wrap gap-2 mb-5"><?php foreach (
    $categories
    as $c
) {
    if ($c["active"]): ?><a class="category-chip" href="<?= e(
    url("browse", ["category" => $c["id"]])
) ?>"><i class="bi bi-grid"></i> <?= e($c["name"]) ?></a><?php endif;
} ?></div><h2 class="h4 mb-4">Prompt ล่าสุด</h2><?php
cards($prompts->listing([])["rows"]);
break;
case "browse":
    case "mine":
    case "favorites":
    case "admin_prompts":

        $mode = [
            "browse" => "public",
            "mine" => "mine",
            "favorites" => "favorites",
            "admin_prompts" => "admin",
        ][$route];
        $list = $prompts->listing($_GET, $mode);
        ?><h1 class="h3 mb-4"><?= e(
    [
        "browse" => "สำรวจ Prompt",
        "mine" => "Prompt ของฉัน",
        "favorites" => "รายการโปรด",
        "admin_prompts" => "จัดการ Prompt และการเผยแพร่",
    ][$route]
) ?></h1><?php
filters();
if ($route === "admin_prompts"): ?><div class="mb-3">สถานะ: <?php foreach (
    ["pending", "draft", "published", "rejected"]
    as $s
): ?><a class="me-2" href="<?= e(url($route, ["status" => $s])) ?>"><?= e(
    statusLabel($s)
) ?></a><?php endforeach; ?></div><?php endif;
?><p class="text-secondary">พบ <?= e($list["count"]) ?> รายการ</p><?php
 cards($list["rows"], in_array($mode, ["mine", "admin"], true));
 pages($list["count"], $list["page"]);
 break;

    case "categories": ?><h1 class="h3">หมวดหมู่ Prompt</h1><div class="row g-3 mt-3"><?php foreach (
    $categories
    as $c
) {
    if ($c["active"]): ?><div class="col-md-4"><a class="card p-4" href="<?= e(
    url("browse", ["category" => $c["id"]])
) ?>"><i class="bi bi-folder2-open"></i> <?= e(
    $c["name"]
) ?></a></div><?php endif;
} ?></div><?php break;case "about": ?><section class="card p-5"><h1>เกี่ยวกับ <?= e(
    $site["name"]
) ?></h1><p class="lead"><?= e($site["slogan"]) ?></p><p><?= e(
    $site["description"]
) ?></p><p>ค้นหา ปรับตัวแปร และคัดลอกคำสั่งไปใช้กับเครื่องมือ AI ที่คุณชื่นชอบ โปรดตรวจสอบความถูกต้องของผลลัพธ์ก่อนใช้งานจริง ข้อมูลเริ่มต้นที่มีป้ายข้อมูลสาธิตสร้างขึ้นเพื่อแนะนำการใช้งาน</p></section><?php break;case "login":
    case "register": ?><section class="card auth-card p-4 mx-auto"><h1 class="h3 mb-4"><?= $route ===
"login"
    ? "ยินดีต้อนรับกลับ"
    : "เริ่มแบ่งปันไอเดียของคุณ" ?></h1><form method="post"><?= csrf() ?><input type="hidden" name="action" value="<?= e(
    $route
) ?>"><?php
if ($route === "register") {
    input(
        "username",
        "ชื่อผู้ใช้ (a-z ตัวเลข _)",
        $_POST["username"] ?? "",
        "text",
        true,
        50
    );
    input("email", "อีเมล", $_POST["email"] ?? "", "email", true, 190);
    input(
        "display_name",
        "ชื่อที่แสดง",
        $_POST["display_name"] ?? "",
        "text",
        true,
        100
    );
} else {
    input(
        "login",
        "ชื่อผู้ใช้หรืออีเมล",
        $_POST["login"] ?? "",
        "text",
        true,
        190
    );
}
input(
    "password",
    $route === "register" ? "รหัสผ่าน 12–72 ตัวอักษร" : "รหัสผ่าน",
    "",
    "password",
    true,
    72
);
?><button class="btn btn-primary w-100"><?= $route === "login"
    ? "เข้าสู่ระบบ"
    : "สมัครสมาชิก" ?></button></form></section><?php break;case "dashboard":
        $counts = $db->all(
            "SELECT status,COUNT(*) n FROM prompts WHERE user_id=? GROUP BY status",
            [(int) $auth->user["id"]]
        ); ?><h1 class="h3">สวัสดี <?= e(
    $auth->user["display_name"]
) ?></h1><p class="text-secondary">จัดการคลังความคิดและแบ่งปัน Prompt ของคุณ</p><div class="row g-3 my-3"><?php foreach (
    ["draft", "pending", "published", "rejected"]
    as $s
):

    $n = 0;
    foreach ($counts as $row) {
        if ($row["status"] === $s) {
            $n = $row["n"];
        }
    }
    ?><div class="col-6 col-lg-3"><div class="card p-4"><strong class="display-6"><?= e(
    $n
) ?></strong><?= e(statusLabel($s)) ?></div></div><?php
endforeach; ?></div><a class="btn btn-primary" href="<?= e(
    url("edit")
) ?>">+ สร้าง Prompt ใหม่</a><?php break;
    case "profile": ?><h1 class="h3">โปรไฟล์และรหัสผ่าน</h1><form method="post" class="card p-4 auth-card"><?= csrf() ?><input type="hidden" name="action" value="profile"><?php
input(
    "display_name",
    "ชื่อที่แสดง",
    $_POST["display_name"] ?? $auth->user["display_name"],
    "text",
    true,
    100
);
input(
    "old_password",
    "รหัสผ่านเดิม (เมื่อเปลี่ยนรหัสผ่าน)",
    "",
    "password",
    false,
    72
);
input("new_password", "รหัสผ่านใหม่ 12–72 ตัวอักษร", "", "password", false, 72);
?><button class="btn btn-primary">บันทึก</button></form><?php break;case "edit":

        $p = !empty($_GET["id"]) ? $prompts->owned(number($_GET["id"])) : [];
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $p = array_merge($p, $_POST);
        }
        ?><h1 class="h3 mb-3"><?= empty($p["id"])
    ? "สร้าง"
    : "แก้ไข" ?> Prompt</h1><p>รายการสาธารณะต้องผ่านการอนุมัติ เมื่อแก้ไขรายการที่เผยแพร่แล้วจะกลับไปรอตรวจสอบ</p><form method="post" class="card p-4"><?= csrf() ?><input type="hidden" name="action" value="save_prompt"><input type="hidden" name="id" value="<?= e(
    $p["id"] ?? ""
) ?>"><?php
input("title", "ชื่อ Prompt", $p["title"] ?? "", "text", true, 200);
input(
    "description",
    "คำอธิบายสั้น",
    $p["description"] ?? "",
    "text",
    true,
    500
);
area(
    "content",
    "เนื้อหา Prompt (ใช้ [ชื่อตัวแปร] ได้)",
    $p["content"] ?? "",
    true,
    50000
);
?><label class="form-label" for="category_id">หมวดหมู่หลัก</label><select class="form-select mb-3" id="category_id" name="category_id" required><?php foreach (
    $categories
    as $c
) {
    if (
        $c["active"] ||
        ($p["category_id"] ?? 0) == $c["id"]
    ): ?><option value="<?= e($c["id"]) ?>" <?= ($p["category_id"] ?? 0) ==
$c["id"]
    ? "selected"
    : "" ?>><?=
e($c["name"]) . (!$c["active"] ? " (ปิดใช้งาน)" : "")
?></option><?php endif;
} ?></select><?php
foreach (
    [
        "tags" => [$tags, "แท็ก", "prompt_tags", "tag_id"],
        "ai_tools" => [
            $tools,
            "เครื่องมือ AI",
            "prompt_ai_tools",
            "ai_tool_id",
        ],
    ]
    as $key => [$rows, $label, $table, $col]
):
    $selected =
        $_POST[$key] ??
        (!empty($p["id"])
            ? array_column(
                $db->all("SELECT $col FROM $table WHERE prompt_id=?", [
                    (int) $p["id"],
                ]),
                $col
            )
            : []); ?><fieldset class="mb-3"><legend class="fs-6"><?= e(
    $label
) ?></legend><?php foreach (
    $rows
    as $row
): ?><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="<?= e(
    $key
) ?>[]" value="<?= e($row["id"]) ?>" <?= in_array(
    $row["id"],
    is_array($selected) ? $selected : []
)
    ? "checked"
    : "" ?>><?= e($row["name"]) ?></label><?php endforeach; ?></fieldset><?php
endforeach;
area("usage_example", "ตัวอย่างวิธีใช้งาน", $p["usage_example"] ?? "");
area("output_example", "ตัวอย่างผลลัพธ์", $p["output_example"] ?? "");
?><label for="visibility" class="form-label">การมองเห็น</label><select class="form-select mb-3" id="visibility" name="visibility"><option value="private">ส่วนตัว</option><option value="public" <?= ($p[
    "visibility"
] ??
    "") ===
"public"
    ? "selected"
    : "" ?>>สาธารณะ</option></select><div class="d-flex gap-2"><button class="btn btn-outline-primary" name="submit_mode" value="draft">บันทึกฉบับร่าง</button><button class="btn btn-primary" name="submit_mode" value="review">บันทึก / ส่งตรวจเพื่อเผยแพร่</button></div></form><?php break;
    case "detail":
        $p = $prompts->get(number($_GET["id"] ?? null)); ?><a href="<?= e(
    url("browse")
) ?>">← สำรวจ Prompt</a><article class="card p-4 p-lg-5 mt-3"><div class="text-primary"><?= e(
    $p["category"]
) ?></div><h1 class="h2 mt-2"><?= e($p["title"]) ?></h1><p class="lead"><?= e(
    $p["description"]
) ?></p><p class="text-secondary"><?= e($p["author"]) ?> · สร้าง <?= e(
     thaiTime($p["created_at"])
 ) ?> · แก้ไข <?= e(thaiTime($p["updated_at"])) ?> · คัดลอก <?= e(
     $p["copy_count"]
 ) ?> ครั้ง</p><div class="mb-3"><?php
 foreach (
     $db->all(
         "SELECT t.name FROM tags t JOIN prompt_tags pt ON pt.tag_id=t.id WHERE pt.prompt_id=?",
         [(int) $p["id"]]
     )
     as $t
 ): ?><span class="badge text-bg-light me-2">#<?= e(
    $t["name"]
) ?></span><?php endforeach;
 foreach (
     $db->all(
         "SELECT a.name FROM ai_tools a JOIN prompt_ai_tools pa ON pa.ai_tool_id=a.id WHERE pa.prompt_id=?",
         [(int) $p["id"]]
     )
     as $t
 ): ?><span class="badge text-bg-primary me-2"><?= e(
    $t["name"]
) ?></span><?php endforeach;
 ?></div><pre id="original" class="prompt-text"><?= e(
    $p["content"]
) ?></pre><div id="variables" class="row g-3 my-2"></div><label class="form-label" for="rendered">ข้อความพร้อมใช้งาน</label><textarea id="rendered" class="form-control mb-3" rows="9" readonly><?= e(
    $p["content"]
) ?></textarea><div class="d-flex gap-2 flex-wrap"><button id="copy" class="btn btn-primary" data-id="<?= e(
    $p["id"]
) ?>" data-csrf="<?= e($_SESSION["csrf"]) ?>">คัดลอก Prompt</button><?php if (
    $auth->user &&
    $p["visibility"] === "public" &&
    $p["status"] === "published"
):
    $fav = $db->one("SELECT 1 FROM favorites WHERE user_id=? AND prompt_id=?", [
        (int) $auth->user["id"],
        (int) $p["id"],
    ]); ?><form method="post"><?= csrf() ?><input type="hidden" name="action" value="favorite"><input type="hidden" name="id" value="<?= e(
    $p["id"]
) ?>"><input type="hidden" name="remove" value="<?= $fav
    ? "1"
    : "0" ?>"><button class="btn btn-outline-primary"><?= $fav
    ? "♥ ยกเลิกรายการโปรด"
    : "♡ บันทึกรายการโปรด" ?></button></form><?php
endif; ?></div><p id="copy-status" role="status" class="mt-2"></p><h2 class="h5 mt-4">ตัวอย่างวิธีใช้งาน</h2><pre class="plain"><?= e(
    $p["usage_example"]
) ?></pre><h2 class="h5">ตัวอย่างผลลัพธ์</h2><pre class="plain"><?= e(
    $p["output_example"]
) ?></pre><?php
if (
    $auth->user &&
    ($auth->user["role"] === "admin" ||
        (int) $auth->user["id"] === (int) $p["user_id"])
): ?><div class="border-top pt-4"><p>สถานะ: <?= e(
    statusLabel($p["status"])
) ?> · <?= e(
     $p["visibility"] === "private" ? "ส่วนตัว" : "สาธารณะ"
 ) ?></p><?php if (
    $p["rejection_reason"]
): ?><div class="alert alert-warning">เหตุผลไม่อนุมัติ: <?= e(
    $p["rejection_reason"]
) ?></div><?php endif; ?><a class="btn btn-outline-primary" href="<?= e(
    url("edit", ["id" => $p["id"]])
) ?>">แก้ไข Prompt</a><form class="d-inline" method="post" data-confirm="ต้องการลบ Prompt นี้อย่างถาวรหรือไม่?"><?= csrf() ?><input type="hidden" name="action" value="delete_prompt"><input type="hidden" name="id" value="<?= e(
    $p["id"]
) ?>"><button class="btn btn-outline-danger">ลบ Prompt</button></form></div><?php endif;
if (
    $auth->user &&
    $auth->user["role"] === "admin" &&
    $p["visibility"] === "public" &&
    $p["status"] === "pending"
): ?><form class="mt-4 border-top pt-3" method="post"><?= csrf() ?><input type="hidden" name="action" value="moderate"><input type="hidden" name="id" value="<?= e(
    $p["id"]
) ?>"><?php area(
    "reason",
    "เหตุผล (จำเป็นเมื่อไม่อนุมัติ)",
    "",
    false,
    1000
); ?><button class="btn btn-success" name="status" value="published">อนุมัติ</button> <button class="btn btn-danger" name="status" value="rejected">ไม่อนุมัติ</button></form><?php endif;
?></article><?php break;
    case "admin": ?><h1 class="h3 mb-4">ภาพรวมระบบ</h1><div class="row g-3"><?php foreach (
    [
        "สมาชิก" => "SELECT COUNT(*) n FROM users",
        "Prompt" => "SELECT COUNT(*) n FROM prompts",
        "หมวดหมู่" => "SELECT COUNT(*) n FROM categories",
        "รอตรวจสอบ" => "SELECT COUNT(*) n FROM prompts WHERE status='pending'",
    ]
    as $label => $sql
): ?><div class="col-6 col-lg-3"><div class="card p-4"><strong class="display-6"><?= e(
    $db->one($sql)["n"]
) ?></strong><?= e(
    $label
) ?></div></div><?php endforeach; ?></div><?php break;case "admin_users":

        $q = text($_GET, "q", 100);
        $n = (int) $db->one(
            "SELECT COUNT(*) n FROM users WHERE username LIKE ? OR email LIKE ?",
            ["%" . $q . "%", "%" . $q . "%"]
        )["n"];
        $page = empty($_GET["page"])
            ? 1
            : min(number($_GET["page"]), max(1, (int) ceil($n / 12)));
        $rows = $db->all(
            "SELECT * FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?",
            ["%" . $q . "%", "%" . $q . "%", 12, ($page - 1) * 12]
        );
        ?><h1 class="h3">จัดการสมาชิก</h1><form class="d-flex gap-2 my-3"><input type="hidden" name="r" value="admin_users"><label for="users-q" class="visually-hidden">ค้นหาสมาชิก</label><input class="form-control" id="users-q" name="q" value="<?= e(
    $q
) ?>" maxlength="100" placeholder="ชื่อผู้ใช้หรืออีเมล"><button class="btn btn-primary">ค้นหา</button></form><div class="table-responsive"><table class="table align-middle"><thead><tr><th>ชื่อผู้ใช้ / อีเมล</th><th>บทบาทและสถานะ</th></tr></thead><tbody><?php foreach (
    $rows
    as $u
): ?><tr><td><?= e($u["username"]) ?><br><small><?= e(
    $u["email"]
) ?></small></td><td><form class="d-flex flex-wrap gap-2" method="post" data-confirm="ยืนยันการเปลี่ยนสิทธิ์หรือสถานะสมาชิก?"><?= csrf() ?><input type="hidden" name="action" value="user"><input type="hidden" name="id" value="<?= e(
    $u["id"]
) ?>"><select class="form-select w-auto" name="role" aria-label="บทบาท"><option value="member" <?= $u[
    "role"
] === "member"
    ? "selected"
    : "" ?>>สมาชิก</option><option value="admin" <?= $u["role"] === "admin"
    ? "selected"
    : "" ?>>ผู้ดูแล</option></select><select class="form-select w-auto" name="active" aria-label="สถานะ"><option value="1" <?= $u[
    "active"
]
    ? "selected"
    : "" ?>>เปิดใช้งาน</option><option value="0" <?= !$u["active"]
    ? "selected"
    : "" ?>>ระงับ</option></select><button class="btn btn-outline-primary" name="operation" value="save">บันทึก</button><button class="btn btn-outline-danger" name="operation" value="delete">ลบสมาชิกและ Prompt ของสมาชิก</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php
pages($n, $page);
break;

    case "admin_taxonomy": ?><h1 class="h3 mb-3">หมวดหมู่ แท็ก และเครื่องมือ AI</h1><?php
foreach (
    [
        "categories" => [$categories, "หมวดหมู่"],
        "tags" => [$tags, "แท็ก"],
        "ai_tools" => [$tools, "เครื่องมือ AI"],
    ]
    as $kind => [$rows, $label]
): ?><section class="card p-4 mb-4"><h2 class="h5"><?= e(
    $label
) ?></h2><?php foreach (
    array_merge($rows, [
        ["id" => "", "name" => "", "active" => 1, "sort_order" => 0],
    ])
    as $row
): ?><form method="post" class="row g-2 border-bottom py-3" data-confirm-delete="ยืนยันลบรายการนี้? หากเป็นหมวดหมู่ที่มี Prompt ต้องเลือกหมวดหมู่ปลายทางก่อน"><?= csrf() ?><input type="hidden" name="action" value="taxonomy"><input type="hidden" name="kind" value="<?= e(
    $kind
) ?>"><input type="hidden" name="id" value="<?= e(
    $row["id"]
) ?>"><div class="col-md"><label class="visually-hidden" for="<?= e(
    $kind . $row["id"]
) ?>">ชื่อ<?= e($label) ?></label><input id="<?= e(
    $kind . $row["id"]
) ?>" class="form-control" name="name" value="<?= e(
    $row["name"]
) ?>" placeholder="ชื่อ<?= e($label) ?>" maxlength="<?= $kind === "categories"
    ? 100
    : 80 ?>"></div><?php if (
    $kind === "categories"
): ?><div class="col-md-2"><input class="form-control" type="number" name="sort_order" value="<?= e(
    $row["sort_order"]
) ?>" aria-label="ลำดับแสดงผล" min="-100000" max="100000"></div><div class="col-md-auto"><label><input type="checkbox" name="active" <?= $row[
    "active"
]
    ? "checked"
    : "" ?>> เปิดใช้งาน</label></div><?php if (
    $row["id"]
): ?><div class="col-md"><select class="form-select" name="move_to" aria-label="หมวดหมู่ปลายทาง"><option value="">ย้าย Prompt ไป... (เมื่อลบ)</option><?php foreach (
    $categories
    as $c
) {
    if ($c["id"] !== $row["id"] && $c["active"]): ?><option value="<?= e(
    $c["id"]
) ?>"><?= e($c["name"]) ?></option><?php endif;
} ?></select></div><?php endif;endif; ?><div class="col-md-auto"><button class="btn btn-outline-primary" name="operation" value="save"><?= $row[
    "id"
]
    ? "บันทึก"
    : "เพิ่ม" ?></button><?php if (
    $row["id"]
): ?> <button class="btn btn-outline-danger" name="operation" value="delete">ลบ</button><?php endif; ?></div></form><?php endforeach; ?></section><?php endforeach;
break;
case "admin_settings": ?><h1 class="h3">ตั้งค่าเว็บไซต์</h1><form method="post" class="card p-4 mt-3"><?= csrf() ?><input type="hidden" name="action" value="settings"><?php
input(
    "name",
    "ชื่อเว็บไซต์",
    $_POST["name"] ?? $site["name"],
    "text",
    true,
    100
);
input(
    "slogan",
    "สโลแกน",
    $_POST["slogan"] ?? $site["slogan"],
    "text",
    true,
    150
);
area(
    "description",
    "คำอธิบายเว็บไซต์",
    $_POST["description"] ?? $site["description"],
    true,
    1000
);
?><button class="btn btn-primary">บันทึก</button></form><?php break;case "admin_logs":

        $n = (int) $db->one("SELECT COUNT(*) n FROM admin_activity_logs")["n"];
        $page = empty($_GET["page"])
            ? 1
            : min(number($_GET["page"]), max(1, (int) ceil($n / 12)));
        $rows = $db->all(
            "SELECT l.*,u.username FROM admin_activity_logs l LEFT JOIN users u ON u.id=l.admin_id ORDER BY l.id DESC LIMIT ? OFFSET ?",
            [12, ($page - 1) * 12]
        );
        ?><h1 class="h3 mb-3">บันทึกกิจกรรมผู้ดูแล</h1><div class="table-responsive"><table class="table"><thead><tr><th>เวลา</th><th>ผู้ดูแล</th><th>กิจกรรม</th><th>รายละเอียด</th></tr></thead><tbody><?php foreach (
    $rows
    as $row
): ?><tr><td><?= e(thaiTime($row["created_at"])) ?></td><td><?= e(
    $row["username"] ?? "บัญชีที่ลบแล้ว"
) ?></td><td><?= e($row["action"]) ?></td><td class="log-detail"><?= e(
    $row["detail"]
) ?></td></tr><?php endforeach; ?></tbody></table></div><?php
pages($n, $page);
break;

endswitch;
