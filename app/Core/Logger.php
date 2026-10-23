<?php
declare(strict_types=1);

namespace PromptHub\Core;

/** บันทึกข้อผิดพลาดฝั่งเซิร์ฟเวอร์ลง storage/logs (นอก public) ไม่แสดงต่อผู้ใช้ */
final class Logger
{
    public static function error(\Throwable $e, string $context = ''): void
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s %s: %s in %s:%d%s\n%s\n",
            gmdate('Y-m-d H:i:s') . ' UTC',
            $context,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            isset($_SERVER['REQUEST_URI']) ? ' uri=' . substr((string) $_SERVER['REQUEST_URI'], 0, 300) : '',
            $e->getTraceAsString()
        );
        if (@file_put_contents($dir . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX) === false) {
            error_log($line);
        }
    }
}
