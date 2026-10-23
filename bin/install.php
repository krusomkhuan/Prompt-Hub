<?php
declare(strict_types=1);

/**
 * ติดตั้ง Prompt Hub
 *   php bin/install.php               สร้างฐานข้อมูล ตาราง และบัญชีผู้ดูแล
 *   php bin/install.php --with-demo   ติดตั้งพร้อมข้อมูลสาธิต 16 รายการ
 *   php bin/install.php --skip-admin  ไม่สร้างผู้ดูแล (สร้างภายหลังด้วย bin/create-admin.php)
 * ใช้กับฐานข้อมูลใหม่เท่านั้น หากพบตาราง users อยู่แล้วจะหยุดเพื่อไม่ทับข้อมูลเดิม
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use PromptHub\Cli\Console;
use PromptHub\Core\Database;
use PromptHub\Core\ValidationException;

$withDemo = in_array('--with-demo', $argv, true);
$skipAdmin = in_array('--skip-admin', $argv, true);

Console::line('Prompt Hub — ติดตั้งระบบ');
Console::line('PHP ' . PHP_VERSION);
if (PHP_VERSION_ID < 80000) {
    Console::error('ต้องใช้ PHP 8.0 ขึ้นไป (แนะนำ 8.2+)');
    exit(1);
}
if (PHP_VERSION_ID < 80200) {
    Console::line('คำเตือน: แนะนำ PHP 8.2 ขึ้นไป รุ่นนี้ใช้งานได้แต่หมดระยะสนับสนุนด้านความปลอดภัยแล้ว');
}
foreach (['mysqli', 'mbstring', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        Console::error("ยังไม่ได้เปิด extension: $ext (แก้ไขใน php.ini)");
        exit(1);
    }
}

try {
    $config = load_config();
    $dbc = $config['db'];
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', (string) $dbc['name'])) {
        throw new RuntimeException('ชื่อฐานข้อมูลใน config ใช้ได้เฉพาะ A-Z a-z 0-9 _');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli((string) $dbc['host'], (string) $dbc['user'], (string) $dbc['pass'], '', (int) $dbc['port']);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+00:00'");
    Console::line('เชื่อมต่อ MySQL สำเร็จ: ' . $conn->server_info);

    // ชื่อฐานข้อมูลผ่านการตรวจรูปแบบแล้ว จึงใส่เป็น identifier ได้
    $conn->query('CREATE DATABASE IF NOT EXISTS `' . $dbc['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $conn->select_db($dbc['name']);

    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $table = 'users';
    $stmt->bind_param('ss', $dbc['name'], $table);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    if ($exists) {
        Console::error("ฐานข้อมูล {$dbc['name']} มีตารางของระบบอยู่แล้ว — หยุดการติดตั้งเพื่อไม่ทับข้อมูล");
        Console::line('ถ้าต้องการเพิ่มผู้ดูแล ใช้: php bin/create-admin.php');
        exit(1);
    }

    Console::runSqlFile($conn, APP_ROOT . '/database/schema.sql');
    Console::line('สร้างตารางเรียบร้อย');
    if ($withDemo) {
        Console::runSqlFile($conn, APP_ROOT . '/database/demo.sql');
        Console::line('นำเข้าข้อมูลสาธิตเรียบร้อย');
    }
    $conn->close();

    if (!$skipAdmin) {
        $db = new Database($dbc);
        for ($try = 1; $try <= 3; $try++) {
            try {
                Console::createAdmin($db);
                Console::line('สร้างบัญชีผู้ดูแลเรียบร้อย');
                break;
            } catch (ValidationException $e) {
                foreach ($e->errors() as $msg) {
                    Console::error($msg);
                }
                if ($try === 3) {
                    Console::line('ติดตั้งตารางแล้ว แต่ยังไม่มีผู้ดูแล สร้างภายหลังด้วย: php bin/create-admin.php');
                    exit(1);
                }
                Console::line('ลองอีกครั้ง');
            }
        }
    }
    $base = rtrim((string) $config['app']['base_url'], '/');
    Console::line('');
    Console::line('ติดตั้งเสร็จสมบูรณ์ เปิดเว็บที่ http://localhost' . $base . '/');
    exit(0);
} catch (Throwable $e) {
    PromptHub\Core\Logger::error($e, 'install');
    Console::error('ติดตั้งไม่สำเร็จ: ' . ($e instanceof mysqli_sql_exception ? 'เชื่อมต่อหรือสร้างฐานข้อมูลไม่ได้ ตรวจค่าใน config/config.php และสิทธิ์ผู้ใช้ MySQL' : $e->getMessage()));
    Console::line('รายละเอียดอยู่ใน storage/logs/');
    exit(1);
}
