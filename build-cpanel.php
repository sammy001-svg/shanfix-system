<?php
/**
 * Builds a cPanel-ready copy of the system into deploy/.
 *
 *   php build-cpanel.php
 *
 * The normal layout expects the web server's document root to be the
 * public/ folder. On shared hosting that is not always changeable, so
 * this produces a "flat" build instead: index.php sits at the top, and
 * the folders that must never be served are protected by .htaccess.
 *
 * Upload the CONTENTS of deploy/ into whatever folder the subdomain
 * already serves. No document root change, no config edits.
 *
 * Trade-off, stated plainly: with the standard layout your code and
 * config sit physically outside the web root and cannot be served at
 * all. Here they sit inside it, protected by deny rules. Those rules
 * are verified by this script, but the standard layout is stronger.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("build-cpanel.php can only be run from the command line.\n");
}

$root   = __DIR__;
$out    = $root . '/deploy';

const OK = "\033[32m"; const ERR = "\033[31m"; const DIM = "\033[2m"; const OFF = "\033[0m";
function say(string $m, string $c = ''): void { echo $c . $m . ($c ? OFF : '') . PHP_EOL; }

say('');
say('Building cPanel deployment package…');
say('');

// ---------------------------------------------------------------------
// Start clean
// ---------------------------------------------------------------------
if (is_dir($out)) {
    rrmdir($out);
}
mkdir($out, 0755, true);

// ---------------------------------------------------------------------
// 1. Application folders, copied as-is
// ---------------------------------------------------------------------
foreach (['app', 'config', 'database'] as $dir) {
    copyTree($root . '/' . $dir, $out . '/' . $dir);
    say('  copied  ' . $dir . '/');
}

// The live config is never shipped — it holds the database password.
if (is_file($out . '/config/config.php')) {
    unlink($out . '/config/config.php');
    say('  ' . DIM . 'skipped  config/config.php (yours stays on the server)' . OFF);
}

// ---------------------------------------------------------------------
// 2. public/ contents move to the top level
// ---------------------------------------------------------------------
copyTree($root . '/public/assets', $out . '/assets');
say('  copied  assets/  ' . DIM . '(from public/assets)' . OFF);

// ---------------------------------------------------------------------
// 3. Front controller, with the require path adjusted for this layout
// ---------------------------------------------------------------------
$index = <<<'PHP'
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
PHP;

file_put_contents($out . '/index.php', $index . "\n");
say('  wrote   index.php  ' . DIM . '(paths adjusted for this layout)' . OFF);

copy($root . '/routes.php', $out . '/routes.php');
say('  copied  routes.php');

// ---------------------------------------------------------------------
// 4. The one .htaccess that does everything
// ---------------------------------------------------------------------
$htaccess = <<<'HT'
# =====================================================================
# Shanfix Technology BMS — cPanel (flat layout)
#
# index.php is the front controller. Everything else in here must never
# be served directly, and the rules below enforce that.
# =====================================================================

Options -Indexes

# --- Block the application internals ---------------------------------
# Belt: pattern rules, applied before anything else.
<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteRule ^(app|config|database|storage)(/|$)             - [F,L]
    RewriteRule ^(install|migrate|cron|dev-server|routes|build-cpanel)\.php$ - [F,L]

    # Force HTTPS (comment out until your SSL certificate is active)
    RewriteCond %{HTTPS} !=on
    RewriteCond %{HTTP:X-Forwarded-Proto} !https
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # Front controller: anything that is not a real file or folder
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>

# Braces: file-type rules, which still apply if mod_rewrite is missing.
<FilesMatch "\.(sql|log|ini|sh|bak|md|yml|yaml|lock|dist)$">
    Require all denied
</FilesMatch>

<FilesMatch "^\.">
    Require all denied
</FilesMatch>

<FilesMatch "^(install|migrate|cron|dev-server|routes|build-cpanel)\.php$">
    Require all denied
</FilesMatch>

# --- Caching ----------------------------------------------------------
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css               "access plus 7 days"
    ExpiresByType application/javascript "access plus 7 days"
    ExpiresByType image/png              "access plus 30 days"
    ExpiresByType image/jpeg             "access plus 30 days"
    ExpiresByType image/svg+xml          "access plus 30 days"
    ExpiresByType image/webp             "access plus 30 days"
</IfModule>

<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json text/plain
</IfModule>

# --- Upload limits ----------------------------------------------------
<IfModule mod_php.c>
    php_value upload_max_filesize 8M
    php_value post_max_size 12M
    php_value max_execution_time 120
</IfModule>
HT;

file_put_contents($out . '/.htaccess', $htaccess . "\n");
say('  wrote   .htaccess');

// ---------------------------------------------------------------------
// 5. Writable directories, each sealed off from the web
// ---------------------------------------------------------------------
$deny = "Require all denied\n";

foreach (['storage', 'storage/logs', 'storage/uploads', 'storage/uploads/receipts',
          'storage/uploads/chat', 'storage/uploads/logos', 'storage/uploads/artwork', 'storage/uploads/products', 'storage/uploads/branding'] as $dir) {
    mkdir($out . '/' . $dir, 0755, true);
    touch($out . '/' . $dir . '/.gitkeep');
}

foreach (['app', 'config', 'database', 'storage'] as $dir) {
    file_put_contents($out . '/' . $dir . '/.htaccess', $deny);
}
say('  wrote   deny rules in app/ config/ database/ storage/');

// ---------------------------------------------------------------------
// 6. CLI tools and the self-check page
// ---------------------------------------------------------------------
foreach (['install.php', 'migrate.php', 'cron.php'] as $file) {
    copy($root . '/' . $file, $out . '/' . $file);
}
say('  copied  install.php, migrate.php, cron.php  ' . DIM . '(CLI only, blocked over the web)' . OFF);

copy($root . '/check.php', $out . '/check.php');
say('  copied  check.php  ' . DIM . '(open it in a browser to diagnose the install)' . OFF);

copy($root . '/config/config.sample.php', $out . '/config/config.sample.php');

// ---------------------------------------------------------------------
// 7. Verify the build actually holds together
// ---------------------------------------------------------------------
say('');
say('Verifying…');

$problems = [];

foreach (['index.php', '.htaccess', 'routes.php', 'check.php',
          'app/bootstrap.php', 'app/Core/App.php', 'assets/css/app.css',
          'assets/js/app.js', 'database/schema.sql', 'database/seed.sql',
          'config/config.sample.php'] as $must) {
    if (!file_exists($out . '/' . $must)) {
        $problems[] = 'missing: ' . $must;
    }
}

foreach (['app', 'config', 'database', 'storage'] as $dir) {
    if (!file_exists($out . '/' . $dir . '/.htaccess')) {
        $problems[] = 'no deny rule in ' . $dir . '/';
    }
}

// Every PHP file must still parse after copying.
$bad = 0;
foreach (phpFiles($out) as $file) {
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $o, $code);
    if ($code !== 0) { $problems[] = 'syntax error: ' . $file; $bad++; }
}

if ($problems) {
    say('');
    foreach ($problems as $p) { say('  ' . $p, ERR); }
    say('');
    say('Build FAILED.', ERR);
    exit(1);
}

$count = iterator_count(phpFiles($out));
say('  ' . OK . 'all required files present' . OFF);
say('  ' . OK . $count . ' PHP files parse cleanly' . OFF);
say('  ' . OK . 'deny rules in place' . OFF);

say('');
say('Package ready: ' . $out, OK);
say('');
say('Next:');
say('  1. Zip the CONTENTS of deploy/ (not the folder itself)');
say('  2. Upload the zip into the folder your subdomain serves, and extract it there');
say('  3. Copy config/config.sample.php to config/config.php and fill in your database details');
say('  4. Visit https://your-domain/check.php to confirm everything is wired up');
say('  5. Delete check.php and install.php when the system is working', DIM);
say('');

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function copyTree(string $src, string $dst): void
{
    if (!is_dir($src)) {
        return;
    }

    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }

    foreach (scandir($src) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $from = $src . '/' . $entry;
        $to   = $dst . '/' . $entry;

        is_dir($from) ? copyTree($from, $to) : copy($from, $to);
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }

    rmdir($dir);
}

function phpFiles(string $dir): Generator
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            yield $file->getPathname();
        }
    }
}
