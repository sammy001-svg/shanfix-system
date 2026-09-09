<?php
/**
 * Does the site's .env loader read a file written the way people write
 * one?
 *
 * This is the root cause of a live outage. The loader took the text after
 * the "=" exactly as written, so a perfectly ordinary file —
 *
 *     DB_USER="root"
 *
 * — asked MySQL for a user whose name included the quote marks, and was
 * refused. The site showed the same blank "System Maintenance" notice it
 * showed for every other cause, so there was nothing to go on.
 *
 * Each case below is a way somebody really does write a .env. Prints
 * "ok" only if every one of them parses to the same three settings.
 *
 * Usage:  php tests/helpers/site_env_parsing.php <path-to-site>
 */

$site = rtrim($argv[1] ?? (__DIR__ . '/../../site'), '/');
$file = $site . '/includes/env_loader.php';

if (!is_file($file)) {
    echo 'no loader at ' . $file;
    exit(1);
}

// Requiring it runs its own load of the real .env, which is harmless and
// happens once. Every case below calls loadEnv() directly instead.
require_once $file;

if (!function_exists('loadEnv')) {
    echo 'loadEnv is not defined';
    exit(1);
}

$path  = sys_get_temp_dir() . '/shanfix_env_' . getmypid() . '.env';
$keys  = ['DB_NAME', 'DB_USER', 'DB_PASS'];
$want  = 'shanfix_tech|root|secret';

$cases = [
    'plain'         => "DB_NAME=shanfix_tech\nDB_USER=root\nDB_PASS=secret\n",
    'double quoted' => "DB_NAME=\"shanfix_tech\"\nDB_USER=\"root\"\nDB_PASS=\"secret\"\n",
    'single quoted' => "DB_NAME='shanfix_tech'\nDB_USER='root'\nDB_PASS='secret'\n",
    'export prefix' => "export DB_NAME=shanfix_tech\nexport DB_USER=root\nexport DB_PASS=secret\n",
    'with comments' => "# database\nDB_NAME=shanfix_tech\n\nDB_USER=root\nDB_PASS=secret\n",
    'stray line'    => "DB_NAME=shanfix_tech\nthis line has no equals sign\nDB_USER=root\nDB_PASS=secret\n",
    'padded'        => "  DB_NAME = shanfix_tech  \n DB_USER = root \nDB_PASS = secret \n",
];

$failures = [];

foreach ($cases as $label => $body) {
    // loadEnv deliberately refuses to overwrite anything already set, so
    // each case starts from nothing.
    foreach ($keys as $key) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    file_put_contents($path, $body);
    loadEnv($path);

    $got = implode('|', array_map(static fn(string $k): string => $_ENV[$k] ?? '', $keys));

    if ($got !== $want) {
        $failures[] = $label . ' => ' . $got;
    }
}

@unlink($path);

echo $failures ? implode('; ', $failures) : 'ok';
