<?php
declare(strict_types=1);

namespace PromptHub\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /** ตรวจ token จากฟิลด์ _csrf หรือ header X-CSRF-Token */
    public static function verify(): bool
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $known = $_SESSION['_csrf'] ?? '';
        return is_string($sent) && is_string($known) && $known !== '' && hash_equals($known, $sent);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }
}
