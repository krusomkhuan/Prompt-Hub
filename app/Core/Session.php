<?php
declare(strict_types=1);

namespace PromptHub\Core;

final class Session
{
    public function __construct(private array $appConfig)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        session_name('PROMPTHUB_SID');
        $path = rtrim((string) $this->appConfig['base_url'], '/') . '/';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path,
            'secure' => (bool) ($this->appConfig['secure_cookie'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // หมดอายุเมื่อไม่ได้ใช้งานนานเกินกำหนด
        $idle = (int) ($this->appConfig['session_idle_seconds'] ?? 7200);
        $now = time();
        if (isset($_SESSION['_last_seen']) && $now - (int) $_SESSION['_last_seen'] > $idle) {
            $this->destroy();
            session_start();
            $this->flash('warning', 'หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบอีกครั้ง');
        }
        $_SESSION['_last_seen'] = $now;
    }

    /** เปลี่ยน Session ID (หลังเข้าสู่ระบบหรือเปลี่ยนรหัสผ่าน) */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }
    }

    public function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** ข้อความแจ้งผลครั้งเดียว ประเภท success | danger | warning | info */
    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public function takeFlashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    /** เก็บค่าที่กรอกในฟอร์มไว้แสดงซ้ำหลัง redirect เมื่อเกิดข้อผิดพลาด (ไม่เก็บรหัสผ่าน) */
    public function keepOld(array $input, array $errors = []): void
    {
        foreach ($input as $k => $v) {
            if (stripos((string) $k, 'password') !== false || $k === '_csrf') {
                unset($input[$k]);
            }
        }
        $_SESSION['_old'] = $input;
        $_SESSION['_errors'] = $errors;
    }

    public function takeOld(): array
    {
        $o = ['old' => $_SESSION['_old'] ?? [], 'errors' => $_SESSION['_errors'] ?? []];
        unset($_SESSION['_old'], $_SESSION['_errors']);
        return $o;
    }
}
