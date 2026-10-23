<?php
declare(strict_types=1);

namespace PromptHub\Controllers;

use PromptHub\Core\App;
use PromptHub\Core\Auth;
use PromptHub\Core\Database;
use PromptHub\Core\HttpException;
use PromptHub\Core\Session;

abstract class Controller
{
    protected Database $db;
    protected Auth $auth;
    protected Session $session;

    public function __construct(protected App $app)
    {
        $this->db = $app->db;
        $this->auth = $app->auth;
        $this->session = $app->session;
    }

    protected function render(string $template, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        echo $this->app->view->render($template, $data);
    }

    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    protected function flash(string $type, string $message): void
    {
        $this->session->flash($type, $message);
    }

    /** ค่าข้อความจาก query string (ตัดความยาว ไม่รับ array) */
    protected function query(string $key, int $max = 100): string
    {
        $v = $_GET[$key] ?? '';
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '');
        return mb_substr($v, 0, $max);
    }

    /** จำนวนเต็มบวกจาก query string ค่าไม่ถูกต้องคืน 0 */
    protected function queryId(string $key): int
    {
        $v = $_GET[$key] ?? '';
        return is_string($v) && preg_match('/^[1-9]\d{0,9}$/D', $v) ? (int) $v : 0;
    }

    /** ค่าที่อยู่ในรายการอนุญาต ไม่เช่นนั้นคืนค่าเริ่มต้น */
    protected function queryIn(string $key, array $allowed, string $default = ''): string
    {
        $v = $_GET[$key] ?? '';
        return is_string($v) && in_array($v, $allowed, true) ? $v : $default;
    }

    protected function page(): int
    {
        $p = $this->queryId('page');
        return $p > 0 ? min($p, 100000) : 1;
    }

    /** รหัสจากฟอร์ม POST ต้องเป็นจำนวนเต็มบวก */
    protected function postId(string $key = 'id'): int
    {
        $v = $_POST[$key] ?? '';
        if (!is_string($v) || !preg_match('/^[1-9]\d{0,9}$/D', $v)) {
            throw new HttpException(400, 'รหัสข้อมูลไม่ถูกต้อง');
        }
        return (int) $v;
    }

    protected function postString(string $key): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    protected static function pagination(int $total, int $page, int $perPage): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        return ['total' => $total, 'page' => min($page, $pages), 'pages' => $pages, 'per_page' => $perPage];
    }
}
