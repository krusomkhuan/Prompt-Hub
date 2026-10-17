<?php
declare(strict_types=1);
require __DIR__ . "/Core.php";
require __DIR__ . "/Prompts.php";
require __DIR__ . "/Admin.php";
ini_set("display_errors", "0");
ini_set("log_errors", "1");
ini_set("error_log", __DIR__ . "/../storage/error.log");
date_default_timezone_set("UTC");
$cfile = __DIR__ . "/../config/local.php";
if (!is_file($cfile)) {
    throw new RuntimeException("กรุณาติดตั้ง config/local.php ตามคู่มือ");
}
$config = require $cfile;
if (PHP_SAPI !== "cli") {
    ini_set("session.use_strict_mode", "1");
    session_set_cookie_params([
        "httponly" => true,
        "secure" => (bool) $config["secure_cookie"],
        "samesite" => "Lax",
        "path" => rtrim($config["base_path"], "/") . "/",
    ]);
    session_start();
    $_SESSION["csrf"] ??= bin2hex(random_bytes(32));
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: same-origin");
    header("X-Frame-Options: DENY");
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    );
    header("Cache-Control: no-store");
}
$db = new Hub\DB($config);
$auth = new Hub\Auth($db);
$prompts = new Hub\Prompts($db, $auth);
