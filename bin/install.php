<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require_once __DIR__ . "/../app/Core.php";
try {
    $file = __DIR__ . "/../config/local.php";
    if (!is_file($file)) {
        throw new RuntimeException(
            "Copy config/example.php to config/local.php first"
        );
    }
    $c = require $file;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $m = new mysqli(
        $c["host"],
        $c["username"],
        $c["password"],
        "",
        (int) $c["port"]
    );
    $m->set_charset("utf8mb4");
    $m->query("SET time_zone='+00:00'");
    if ($c["database"] !== "prompt_hub") {
        throw new RuntimeException("Installer expects database prompt_hub");
    }
    $m->multi_query(file_get_contents(__DIR__ . "/../database/schema.sql"));
    do {
        if ($result = $m->store_result()) {
            $result->free();
        }
    } while ($m->more_results() && $m->next_result());
    echo "Schema installed. Import database/demo.sql for optional demonstration data.\n";
} catch (Throwable $e) {
    fwrite(
        STDERR,
        "Installation failed. This command requires a new database and CREATE privileges. See server error log.\n"
    );
    error_log((string) $e);
    exit(1);
}
