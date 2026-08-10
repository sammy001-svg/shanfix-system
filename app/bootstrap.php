<?php
/**
 * Paths, autoloader and helper loading.
 * Included by public/index.php and by any CLI script.
 */

define('BASE_PATH',    dirname(__DIR__));
define('APP_PATH',     BASE_PATH . '/app');
define('CONFIG_PATH',  BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH',  BASE_PATH . '/public');

/**
 * PSR-4 style autoloader: App\Controllers\ClientController -> app/Controllers/ClientController.php
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

require_once APP_PATH . '/Core/helpers.php';

// Make sure writable directories exist on a fresh deployment.
foreach (['logs', 'uploads', 'uploads/receipts', 'uploads/chat', 'uploads/logos', 'uploads/artwork', 'uploads/products', 'uploads/branding'] as $dir) {
    $path = STORAGE_PATH . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
}
