<?php
declare(strict_types=1);
use function Hub\{e, text, number, csrf, thaiTime};
function url(string $route = "home", array $args = []): string
{
    global $config;
    return rtrim($config["base_path"], "/") .
        "/index.php?" .
        http_build_query(["r" => $route] + $args);
}
function redirect(string $route = "home", array $args = []): never
{
    header("Location: " . url($route, $args), true, 303);
    exit();
}
try {
    require __DIR__ . "/../app/bootstrap.php";
    $route = text($_GET, "r", 40) ?: "home";
    $error = null;
    $status = 200;
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if (
            !is_string($_POST["csrf"] ?? null) ||
            !hash_equals($_SESSION["csrf"], $_POST["csrf"])
        ) {
            throw new Hub\HttpError(
                419,
                "คำขอหมดอายุหรือ CSRF Token ไม่ถูกต้อง"
            );
        }
        $action = text($_POST, "action", 40, 1);
        try {
            switch ($action) {
                case "register":
                    $username = text($_POST, "username", 50, 3);
                    if (!preg_match('/^[A-Za-z0-9_]{3,50}$/D', $username)) {
                        throw new DomainException(
                            "ชื่อผู้ใช้ใช้ a-z ตัวเลข และ _ อย่างน้อย 3 ตัว"
                        );
                    }
                    $email = text($_POST, "email", 190, 3);
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new DomainException("อีเมลไม่ถูกต้อง");
                    }
                    $password = text($_POST, "password", 72, 12);
                    $db->run(
                        "INSERT INTO users(username,email,display_name,password_hash) VALUES(?,?,?,?)",
                        [
                            $username,
                            $email,
                            text($_POST, "display_name", 100, 1),
                            password_hash($password, PASSWORD_DEFAULT),
                        ]
                    );
                    $_SESSION["flash"] = "สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ";
                    redirect("login");
                case "login":
                    $auth->login(
                        text($_POST, "login", 190, 1),
                        text($_POST, "password", 72, 1)
                    );
                    redirect("dashboard");
                case "logout":
                    $_SESSION = [];
                    session_regenerate_id(true);
                    $_SESSION["csrf"] = bin2hex(random_bytes(32));
                    redirect();
                case "save_prompt":
                    $id = $prompts->save($_POST);
                    $_SESSION["flash"] = "บันทึก Prompt แล้ว";
                    redirect("detail", ["id" => $id]);
                case "delete_prompt":
                    $id = number($_POST["id"] ?? null);
                    $prompts->owned($id);
                    $db->transaction(function () use ($db, $id, $auth) {
                        $db->run("DELETE FROM prompts WHERE id=?", [$id]);
                        if ($auth->user["role"] === "admin") {
                            $db->run(
                                "INSERT INTO admin_activity_logs(admin_id,action,detail) VALUES(?,?,?)",
                                [
                                    (int) $auth->user["id"],
                                    "prompt.delete",
                                    (string) $id,
                                ]
                            );
                        }
                    });
                    $_SESSION["flash"] = "ลบแล้ว";
                    redirect(
                        $auth->user["role"] === "admin"
                            ? "admin_prompts"
                            : "mine"
                    );
                case "favorite":
                    $u = $auth->requireUser();
                    $p = $prompts->get(number($_POST["id"] ?? null));
                    if (
                        $p["visibility"] !== "public" ||
                        $p["status"] !== "published"
                    ) {
                        throw new Hub\HttpError(404, "ไม่พบ Prompt สาธารณะ");
                    }
                    if (($_POST["remove"] ?? "") === "1") {
                        $db->run(
                            "DELETE FROM favorites WHERE user_id=? AND prompt_id=?",
                            [(int) $u["id"], (int) $p["id"]]
                        );
                    } else {
                        $db->run(
                            "INSERT IGNORE INTO favorites(user_id,prompt_id) VALUES(?,?)",
                            [(int) $u["id"], (int) $p["id"]]
                        );
                    }
                    redirect("detail", ["id" => $p["id"]]);
                case "copy":
                    $p = $prompts->get(number($_POST["id"] ?? null));
                    $db->run(
                        "UPDATE prompts SET copy_count=copy_count+1 WHERE id=?",
                        [(int) $p["id"]]
                    );
                    header("Content-Type: application/json; charset=utf-8");
                    echo json_encode(["ok" => true]);
                    exit();
                case "profile":
                    $u = $auth->requireUser();
                    $name = text($_POST, "display_name", 100, 1);
                    $new = text($_POST, "new_password", 72);
                    if ($new !== "") {
                        if (
                            mb_strlen($new) < 12 ||
                            !password_verify(
                                text($_POST, "old_password", 72, 1),
                                $u["password_hash"]
                            )
                        ) {
                            throw new DomainException(
                                "รหัสผ่านเดิมไม่ถูกต้อง หรือรหัสผ่านใหม่สั้นกว่า 12 ตัว"
                            );
                        }
                        $db->run(
                            "UPDATE users SET display_name=?,password_hash=? WHERE id=?",
                            [
                                $name,
                                password_hash($new, PASSWORD_DEFAULT),
                                (int) $u["id"],
                            ]
                        );
                        session_regenerate_id(true);
                    } else {
                        $db->run("UPDATE users SET display_name=? WHERE id=?", [
                            $name,
                            (int) $u["id"],
                        ]);
                    }
                    $_SESSION["flash"] = "อัปเดตโปรไฟล์แล้ว";
                    redirect("profile");
                default:
                    (new Hub\Admin($db, $auth))->act($action, $_POST);
                    $_SESSION["flash"] = "บันทึกข้อมูลแล้ว";
                    redirect(
                        $route,
                        isset($_GET["id"]) ? ["id" => number($_GET["id"])] : []
                    );
            }
        } catch (DomainException $ex) {
            $error = $ex->getMessage();
            $status = 422;
        } catch (mysqli_sql_exception $ex) {
            if ($ex->getCode() === 1062) {
                $error = "ชื่อผู้ใช้ อีเมล หรือชื่อรายการนี้มีอยู่แล้ว";
                $status = 409;
            } elseif ($ex->getCode() === 1451) {
                $error = "ข้อมูลยังถูกใช้งาน กรุณาย้ายข้อมูลก่อนลบ";
                $status = 409;
            } else {
                throw $ex;
            }
        }
    }
    $allowed = [
        "home",
        "browse",
        "detail",
        "categories",
        "login",
        "register",
        "about",
        "dashboard",
        "mine",
        "edit",
        "favorites",
        "profile",
        "admin",
        "admin_users",
        "admin_taxonomy",
        "admin_prompts",
        "admin_settings",
        "admin_logs",
    ];
    if (!in_array($route, $allowed, true)) {
        throw new Hub\HttpError(404, "ไม่พบหน้า");
    }
    if (
        in_array(
            $route,
            ["dashboard", "mine", "edit", "favorites", "profile"],
            true
        )
    ) {
        $auth->requireUser();
    }
    if (str_starts_with($route, "admin")) {
        $auth->requireUser(true);
    }
    $site = $db->one("SELECT * FROM site_settings WHERE id=1");
    $categories = $db->all("SELECT * FROM categories ORDER BY sort_order,name");
    $tags = $db->all("SELECT * FROM tags ORDER BY name");
    $tools = $db->all("SELECT * FROM ai_tools ORDER BY name");
    ob_start();
    require __DIR__ . "/../views/page.php";
    $body = ob_get_clean();
    http_response_code($status);
    require __DIR__ . "/../views/layout.php";
} catch (Throwable $ex) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    $code =
        $ex instanceof Hub\HttpError
            ? $ex->status
            : ($ex instanceof DomainException
                ? 400
                : 500);
    http_response_code($code);
    if ($code === 500) {
        error_log((string) $ex);
    }
    $msg =
        $code === 500
            ? "ระบบขัดข้อง กรุณาลองใหม่หรือติดต่อผู้ดูแล"
            : $ex->getMessage();
    echo '<!doctype html><html lang="th"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prompt Hub</title><h1>' .
        htmlspecialchars($msg, ENT_QUOTES, "UTF-8") .
        '</h1><p><a href="index.php">กลับหน้าหลัก</a></p></html>';
}
