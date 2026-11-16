<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'app'): void
    {
        $file = BASE_PATH . '/resources/views/' . $view . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        $content = (string) ob_get_clean();

        if ($layout === null) {
            echo $content;
            return;
        }
        $layoutFile = BASE_PATH . '/resources/views/layouts/' . $layout . '.php';
        if (!is_file($layoutFile)) {
            throw new RuntimeException('Layout not found: ' . $layout);
        }
        include $layoutFile;
    }

    public static function partial(string $view, array $data = []): string
    {
        $file = BASE_PATH . '/resources/views/' . $view . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
