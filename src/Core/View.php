<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View não encontrada: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function page(string $template, array $data = [], int $status = 200): never
    {
        Response::html(self::render($template, $data), $status);
    }
}
