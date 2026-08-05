<?php
/**
 * Shanfix Technology BMS — front controller (flat cPanel layout).
 *
 * This file sits at the document root. Everything it needs is beside it,
 * protected from direct access by .htaccess.
 */

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\App;

// Guide the user through setup rather than showing a raw config error.
if (!is_file(CONFIG_PATH . '/config.php')) {
    require APP_PATH . '/Views/errors/setup.php';
    exit;
}

$app = (new App())->boot();

require BASE_PATH . '/routes.php';

$app->run();
