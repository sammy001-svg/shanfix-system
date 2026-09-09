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

// -----------------------------------------------------------------------
// The marketing site, exactly as Apache serves it
// -----------------------------------------------------------------------
// site/ is the company website: its own application, its own database,
// its own admin. It owns the front door and every path that really
// exists inside it, and the system owns everything else.
//
// This mirrors the rules in the root .htaccess deliberately. Without it,
// / would be the system's sign-in chooser here and the website in
// production, and the difference would only be found after a deployment.
$site = __DIR__ . '/site';

// The website's working files are not served. Same list as the .htaccess:
// includes/ carries the database password, and the rest is tooling.
$private = '#^/(includes|migrations)/|\.(sql|py|ps1|lock)$|^/(models|package|package-lock|vite\.config)\.(json|js)$|^/(migrate|migrate_users|debug)\.php$#i';

if (preg_match($private, $path)) {
    http_response_code(404);
    exit;
}

// The site's own client portal and admin were removed — there is one of
// each now and they belong to the system. Anyone still holding a
// bookmark or an old email is sent to the real one rather than a 404,
// which is what "we have lost your account" looks like to a customer.
if (preg_match('#^/client(/|$)#', $path)) {
    header('Location: /portal/login', true, 301);
    exit;
}

if (preg_match('#^/admin(/|$)#', $path)) {
    header('Location: /login', true, 301);
    exit;
}

/**
 * Run one of the website's pages the way Apache would.
 *
 * The working directory is moved to the page's own folder first, because
 * the site's pages include their header with a relative path and Apache
 * runs a script from where it lives. Without this they would be resolved
 * against the project root and found only by PHP's fallback.
 */
$runSitePage = static function (string $file): never {
    chdir(dirname($file));
    require $file;
    exit;
};

/**
 * Hand over one of the website's static files.
 *
 * The built-in server was started with -t public, so returning false
 * would send it looking under public/ and find nothing. These have to be
 * read out by hand.
 */
$sendSiteFile = static function (string $file): never {
    $types = [
        'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
        'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'txt' => 'text/plain', 'xml' => 'application/xml',
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
    ];

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
};

// One origin, one service worker. The system's wins — see the .htaccess
// for why — so this is checked before the website's files.
// The conventional address for a sitemap, answered by the generated one.
if ($path === '/sitemap.xml') {
    $runSitePage($site . '/sitemap.php');
}

if ($path !== '/sw.js') {
    if ($path === '/') {
        $runSitePage($site . '/index.php');
    }

    $sitePath = $site . $path;

    if (is_file($sitePath)) {
        str_ends_with(strtolower($sitePath), '.php')
            ? $runSitePage($sitePath)
            : $sendSiteFile($sitePath);
    }

    // A folder means its index, the way DirectoryIndex would.
    if (is_dir($sitePath) && is_file($sitePath . '/index.php')) {
        $runSitePage($sitePath . '/index.php');
    }
}

$file = __DIR__ . '/public' . $path;

// Let the built-in server serve existing static files (CSS, JS, images).
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/public/index.php';
