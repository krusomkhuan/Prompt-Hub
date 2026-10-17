<?php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require_once __DIR__ . "/../app/Core.php";
$c = require __DIR__ . "/../config/local.php";
if ($c["host"] !== "prompt-hub-db" || $c["database"] !== "prompt_hub") {
    throw new RuntimeException(
        "Development script requires the dedicated Docker database configuration"
    );
}
try { $db = new Hub\DB($c); }
catch (mysqli_sql_exception $error) {
    if ($error->getCode() !== 1049) throw $error;
    require __DIR__ . '/install.php';
    $db = new Hub\DB($c);
    $schemaNew = true;
}
if (
    !$db->one(
        "SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'"
    )["n"]
) {
    require __DIR__ . "/install.php";
    $db = new Hub\DB($c);
    $schemaNew = true;
}
if (!empty($schemaNew)) {
    $m = new mysqli(
        $c["host"],
        $c["username"],
        $c["password"],
        $c["database"],
        (int) $c["port"]
    );
    $m->set_charset("utf8mb4");
    $m->multi_query(file_get_contents(__DIR__ . "/../database/demo.sql"));
    do {
        if ($r = $m->store_result()) {
            $r->free();
        }
    } while ($m->more_results() && $m->next_result());
    echo "Demo imported.\n";
}
echo "Development database initialized; existing data preserved.\n";
