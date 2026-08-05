<?php
namespace App\Core;

class Response
{
    public static function redirect(string $url, int $status = 302): never
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
        }
        exit;
    }

    /** Redirect to a path relative to the app root. */
    public static function to(string $path): never
    {
        self::redirect(url($path));
    }

    public static function back(string $fallback = '/dashboard'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        // Only follow same-host referrers.
        if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) {
            self::redirect($ref);
        }
        self::to($fallback);
    }

    public static function json(array $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function download(string $filePath, string $downloadName): never
    {
        if (!is_file($filePath)) {
            throw new HttpException(404, 'File not found.');
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    /** Stream a generated CSV without writing to disk. */
    public static function csv(string $filename, array $headers, array $rows): never
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }

        $out = fopen('php://output', 'w');
        // BOM so Excel opens UTF-8 correctly
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}
