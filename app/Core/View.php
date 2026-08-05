<?php
namespace App\Core;

/**
 * Plain-PHP templating with a single layout wrapper.
 *
 *   View::render('clients/show', ['client' => $client]);
 *   View::render('documents/print', $data, 'print');   // alternate layout
 *   View::render('reports/index', $data, null);        // no layout
 */
class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'app'): void
    {
        echo self::capture($template, $data, $layout);
    }

    public static function capture(string $template, array $data = [], ?string $layout = 'app'): string
    {
        $data    = array_merge(self::$shared, $data);
        $content = self::renderFile(self::path($template), $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderFile(
            self::path('layouts/' . $layout),
            array_merge($data, ['content' => $content])
        );
    }

    private static function path(string $template): string
    {
        $file = APP_PATH . '/Views/' . str_replace('.', '/', $template) . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template} (looked in {$file})");
        }

        return $file;
    }

    private static function renderFile(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
