<?php
declare(strict_types=1);

/**
 * สร้างบัญชีผู้ดูแลระบบเพิ่ม (ผู้ติดตั้งกำหนดรหัสผ่านเอง)
 *   php bin/create-admin.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use PromptHub\Cli\Console;
use PromptHub\Core\Database;
use PromptHub\Core\ValidationException;

try {
    $db = new Database(load_config()['db']);
    Console::createAdmin($db);
    Console::line('สร้างบัญชีผู้ดูแลเรียบร้อย');
    exit(0);
} catch (ValidationException $e) {
    foreach ($e->errors() as $msg) {
        Console::error($msg);
    }
    exit(1);
} catch (Throwable $e) {
    PromptHub\Core\Logger::error($e, 'create-admin');
    Console::error('สร้างบัญชีไม่สำเร็จ ตรวจการเชื่อมต่อฐานข้อมูลและดูรายละเอียดใน storage/logs/');
    exit(1);
}
