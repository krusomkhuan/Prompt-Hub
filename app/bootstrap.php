<?php
declare(strict_types=1);

/**
 * โหลดคลาส ตั้งค่าการแสดงข้อผิดพลาด และอ่านไฟล์ตั้งค่า
 * ใช้ทั้งเว็บ (public/index.php) และ CLI (bin/*.php)
 */

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/version.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
// ไม่เก็บค่าพารามิเตอร์ (เช่นรหัสผ่าน) ไว้ใน stack trace ของ exception
ini_set('zend.exception_ignore_args', '1');
error_reporting(E_ALL);
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    $prefix = 'PromptHub\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/helpers.php';

function load_config(): array
{
    $file = APP_ROOT . '/config/config.php';
    if (!is_file($file)) {
        throw new RuntimeException('ยังไม่มีไฟล์ config/config.php — คัดลอกจาก config/config.example.php ตามคู่มือติดตั้ง');
    }
    $config = require $file;
    if (!is_array($config) || !isset($config['db'], $config['app'])) {
        throw new RuntimeException('รูปแบบไฟล์ config/config.php ไม่ถูกต้อง');
    }
    return $config;
}
