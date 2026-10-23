<?php
declare(strict_types=1);

/**
 * Prompt Hub — Front Controller
 * ทุกคำขอเข้ามาที่ไฟล์นี้ (index.php?r=ชื่อเส้นทาง) โค้ดและไฟล์ตั้งค่าอยู่นอก public
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use PromptHub\Core\App;
use PromptHub\Core\HttpException;
use PromptHub\Core\Logger;
use PromptHub\Core\Router;
use PromptHub\Core\ValidationException;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
// ภาพตัวอย่างที่เก็บใน Google Drive แสดงจาก lh3.googleusercontent.com (เปลี่ยนได้ด้วย config drive.image_base สำหรับทดสอบ)
$cfgForCsp = is_file(APP_ROOT . '/config/config.php') ? (array) (require APP_ROOT . '/config/config.php') : [];
$driveImageBase = (string) ($cfgForCsp['drive']['image_base'] ?? 'https://lh3.googleusercontent.com/d');
$driveImg = preg_match('#^(https?://[A-Za-z0-9.-]+(?::\d+)?)#', $driveImageBase, $m) ? ' ' . $m[1] : '';
// ปุ่ม “เข้าสู่ระบบด้วย Google” / “เชื่อมต่อ Google Drive” ส่งฟอร์ม (POST + CSRF) แล้ว redirect ไปหน้าเลือกบัญชีของ Google
// form-action ต้องอนุญาตปลายทางของ redirect นั้น ไม่เช่นนั้นเบราว์เซอร์จะบล็อกและปุ่มค้าง
$googleAuth = (string) ($cfgForCsp['google']['auth_url'] ?? 'https://accounts.google.com/o/oauth2/v2/auth');
$formGoogle = preg_match('#^(https?://[A-Za-z0-9.-]+(?::\d+)?)#', $googleAuth, $m) ? ' ' . $m[1] : ' https://accounts.google.com';
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' https://cdn.jsdelivr.net; "
    . "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
    . "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; "
    . "img-src 'self' data: blob:" . $driveImg . "; connect-src 'self'; object-src 'none'; "
    . "base-uri 'self'; form-action 'self'" . $formGoogle . "; frame-ancestors 'none'"
);
header('Cache-Control: no-store, private');

$wantsJson = static function (): bool {
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
};

$renderError = static function (int $status, string $message) use ($wantsJson): void {
    if (!headers_sent()) {
        http_response_code($status);
    }
    if (ob_get_level() > 0) {
        ob_clean();
    }
    if ($wantsJson()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (App::booted()) {
        try {
            echo App::get()->view->render('errors/error', ['title' => 'เกิดข้อผิดพลาด', 'status' => $status, 'message' => $message]);
            return;
        } catch (Throwable $e) {
            Logger::error($e, 'error-page');
        }
    }
    // ระบบยังไม่พร้อม (เช่น เชื่อมฐานข้อมูลไม่ได้) แสดงหน้าข้อผิดพลาดแบบเรียบง่าย
    echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Prompt Hub</title></head><body>'
        . '<h1>Prompt Hub</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
};

ob_start();
try {
    $app = App::boot(load_config());
    $routes = require APP_ROOT . '/app/routes.php';
    $route = $_GET['r'] ?? 'home';
    $route = is_string($route) && $route !== '' ? $route : 'home';
    $app->view->share('app', $app);
    $app->view->share('auth', $app->auth);
    $app->view->share('route', $route);
    (new Router($app, $routes))->dispatch($route, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    ob_end_flush();
} catch (HttpException $e) {
    $renderError($e->status(), $e->getMessage());
    ob_end_flush();
} catch (ValidationException $e) {
    $renderError(422, $e->getMessage());
    ob_end_flush();
} catch (Throwable $e) {
    // บันทึกรายละเอียดลง log ภายใน ไม่แสดง SQL หรือข้อมูลภายในต่อผู้ใช้
    Logger::error($e, 'request');
    $renderError(500, 'ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง หากยังพบปัญหาโปรดติดต่อผู้ดูแลระบบ');
    ob_end_flush();
}
