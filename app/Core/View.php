<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $vars = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::capture(APP_ROOT . '/app/Views/' . $view . '.php', $vars);
        if ($layout === null) {
            return $content;
        }
        return self::capture(APP_ROOT . '/app/Views/' . $layout . '.php', $vars + ['content' => $content]);
    }

    private static function capture(string $file, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
