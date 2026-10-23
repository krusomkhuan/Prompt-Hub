<?php
declare(strict_types=1);

namespace PromptHub\Core;

/** แสดงผล template PHP ใน views/ โดยครอบด้วย layout */
final class View
{
    private array $shared = [];

    public function share(string $key, $value): void
    {
        $this->shared[$key] = $value;
    }

    public function render(string $template, array $data = [], string $layout = 'layouts/main'): string
    {
        $content = $this->partial($template, $data);
        if ($layout === '') {
            return $content;
        }
        return $this->partial($layout, $data + ['content' => $content]);
    }

    public function partial(string $template, array $data = []): string
    {
        $file = APP_ROOT . '/views/' . $template . '.php';
        if (!preg_match('#^[a-z0-9_/-]+$#', $template) || !is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }
        $vars = $data + $this->shared;
        $vars['view'] = $this;
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })($file, $vars);
    }
}
