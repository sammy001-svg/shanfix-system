<?php
/**
 * Shanfix BMS — root entry point.
 *
 * Upload this to the folder your subdomain serves — the one containing
 * app/, config/, public/ — alongside the .htaccess beside it.
 *
 * WHY THIS EXISTS
 * The application's real front controller is public/index.php, and the
 * tidy setup is to point the subdomain's document root at public/. Where
 * that cannot be changed, this file stands in for it: the document root
 * stays where it is, and requests land here instead.
 *
 * It deliberately does nothing but hand over. Because the file that runs
 * is /index.php at the web root, the application still sees itself as
 * living at "/" and every generated link stays clean — no /public/ in
 * any URL, and no configuration to remember.
 */

$front = __DIR__ . '/public/index.php';

if (!is_file($front)) {
    http_response_code(500);
    exit(
        'Setup problem: public/index.php was not found next to this file. '
        . 'This index.php must sit in the folder that contains app/, config/ and public/.'
    );
}

require $front;
