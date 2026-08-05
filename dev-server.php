<?php
/**
 * Local development server — NOT for production.
 *
 * On cPanel, Apache and public/.htaccess handle static files and URL rewriting.
 * PHP's built-in server has no .htaccess, so this router does the same job:
 * serve real files as-is, send everything else to the front controller.
 *
 * Run:
 *   php -S 127.0.0.1:8000 -t public dev-server.php
 *
 * Then open http://127.0.0.1:8000
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit("dev-server.php is only used by PHP's built-in development server.\n");
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$file = __DIR__ . '/public' . $path;

// Let the built-in server serve existing static files (CSS, JS, images).
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/public/index.php';
