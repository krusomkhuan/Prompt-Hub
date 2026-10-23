<?php
declare(strict_types=1);

namespace PromptHub\Core;

/**
 * เส้นทางแบบ index.php?r=ชื่อเส้นทาง (ไม่ต้องใช้ mod_rewrite)
 * ตรวจ HTTP method, CSRF ของทุก POST และสิทธิ์ (any | guest | member | admin) ก่อนเรียก controller
 */
final class Router
{
    public function __construct(private App $app, private array $routes)
    {
    }

    public function dispatch(string $name, string $method): void
    {
        if (!preg_match('/^[a-z.]{1,40}$/D', $name) || !isset($this->routes[$name])) {
            throw new HttpException(404, 'ไม่พบหน้าที่ต้องการ');
        }
        [$allowed, $class, $action, $access] = $this->routes[$name];
        $allowed = (array) $allowed;
        if (!in_array($method, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new HttpException(405, 'ไม่รองรับวิธีการร้องขอนี้');
        }
        if ($method === 'POST' && !Csrf::verify()) {
            throw new HttpException(403, 'คำขอไม่ถูกต้องหรือหมดอายุ (CSRF Token ไม่ผ่านการตรวจสอบ) กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
        }
        $auth = $this->app->auth;
        switch ($access) {
            case 'member':
                $auth->requireMember();
                break;
            case 'admin':
                $auth->requireAdmin();
                break;
            case 'guest':
                if ($auth->check()) {
                    redirect('dashboard');
                }
                break;
        }
        $controller = new $class($this->app);
        $controller->$action();
    }
}
