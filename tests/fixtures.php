<?php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require __DIR__ . "/../app/bootstrap.php";
if (($argv[1] ?? "") === "create") {
    $suffix = bin2hex(random_bytes(6));
    $result = ["suffix" => $suffix, "password" => bin2hex(random_bytes(12))];
    foreach (["admin", "member", "other"] as $role) {
        $name = "test_" . $role . "_" . $suffix;
        $db->run(
            "INSERT INTO users(username,email,display_name,password_hash,role) VALUES(?,?,?,?,?)",
            [
                $name,
                $name . "@example.invalid",
                $name,
                password_hash($result["password"], PASSWORD_DEFAULT),
                $role === "admin" ? "admin" : "member",
            ]
        );
        $result[$role] = ["id" => $db->id(), "name" => $name];
    }
    echo json_encode($result);
} elseif (($argv[1] ?? "") === "cleanup") {
    $suffix = $argv[2] ?? "";
    if (!preg_match('/^[a-f0-9]{12}$/D', $suffix)) {
        exit(1);
    }
    $db->transaction(function () use ($db, $suffix) {
        $users = $db->all("SELECT id FROM users WHERE username IN (?,?,?,?)", [
            "test_admin_" . $suffix,
            "test_member_" . $suffix,
            "test_other_" . $suffix,
            "test_cli_" . $suffix,
        ]);
        foreach ($users as $u) {
            $db->run("DELETE FROM admin_activity_logs WHERE admin_id=?", [
                (int) $u["id"],
            ]);
            $db->run("DELETE FROM users WHERE id=?", [(int) $u["id"]]);
        }
        $db->run("DELETE FROM login_attempts");
    });
}
elseif (($argv[1] ?? '') === 'query') {
    // CLI-only test bridge: reuse the application's local DB binding without exposing credentials.
    $request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $statement = $db->run($request['sql'], $request['params'] ?? []);
    $result = $statement->get_result();
    echo json_encode($result ? $result->fetch_all(MYSQLI_NUM) : [], JSON_UNESCAPED_UNICODE);
}
