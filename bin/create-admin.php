<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require __DIR__ . "/../app/bootstrap.php";
$isTty = stream_isatty(STDIN);
function ask(string $label): string
{
    fwrite(STDOUT, $label . ": ");
    return trim((string) fgets(STDIN));
}
try {
    $username = Hub\text(["v" => ask("Username (a-z, 0-9, _)")], "v", 50, 3);
    if (!preg_match('/^[A-Za-z0-9_]{3,50}$/D', $username)) {
        throw new RuntimeException("Invalid username");
    }
    $email = ask("Email");
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Invalid email");
    }
    $name = Hub\text(["v" => ask("Display name")], "v", 100, 1);
    $hidden =
        PHP_OS_FAMILY !== "Windows" &&
        function_exists("shell_exec") &&
        $isTty;
    if ($hidden) {
        shell_exec("stty -echo");
    }
    try {
        $password = ask(
            "Password (12+ characters, max 72 bytes; Windows input is visible)"
        );
        $confirm = ask("Confirm password");
    } finally {
        if ($hidden) {
            shell_exec("stty echo");
            echo PHP_EOL;
        }
    }
    if (
        mb_strlen($password) < 12 ||
        strlen($password) > 72 ||
        $password !== $confirm
    ) {
        throw new RuntimeException("Password length or confirmation invalid");
    }
    $db->transaction(function () use (
        $db,
        $username,
        $email,
        $name,
        $password
    ) {
        $db->run(
            "INSERT INTO users(username,email,display_name,password_hash,role) VALUES(?,?,?,?,'admin')",
            [
                $username,
                $email,
                $name,
                password_hash($password, PASSWORD_DEFAULT),
            ]
        );
        $id = $db->id();
        $db->run(
            "INSERT INTO admin_activity_logs(admin_id,action,detail) VALUES(?,?,?)",
            [$id, "admin.create", "Created via CLI"]
        );
    });
    echo "Administrator created.\n";
} catch (Throwable $e) {
    fwrite(
        STDERR,
        "Unable to create administrator; check input or duplicate username/email.\n"
    );
    exit(1);
}
