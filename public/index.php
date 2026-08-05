<?php
/**
 * Shanfix Technology BMS - front controller.
 *
 * On cPanel, the contents of this /public folder become public_html,
 * and /app, /config, /storage, /database sit one level above the web root.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;

// Guide the user through setup rather than showing a raw config error.
if (!is_file(CONFIG_PATH . '/config.php')) {
    require APP_PATH . '/Views/errors/setup.php';
    exit;
}

$app = (new App())->boot();

require BASE_PATH . '/routes.php';

$app->run();
