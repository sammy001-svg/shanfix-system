<?php
/**
 * Stylesheet and script URLs that change when the file does.
 *
 * The site used to link its CSS and JS with no version on the address —
 * `<script src="./main.js">` — which meant a browser that had been here
 * before went on running whatever it had cached, and so did the service
 * worker in front of it. That is not a theoretical problem: the printing
 * page was moved to read its catalogue from the stockroom, the change
 * went live, and returning visitors kept running the old script and saw
 * "our premium catalogue is currently being updated" against a shelf
 * that was full.
 *
 * Stamping the file's own modification time onto the query string makes
 * the address change whenever the file does, so a browser fetches it
 * again exactly when there is something new to fetch and never
 * otherwise.
 *
 * Root-relative rather than "./", because every page here is served from
 * the front door and a relative address quietly means something
 * different the moment one is not.
 */

if (!function_exists('site_asset')) {
    function site_asset(string $file): string
    {
        $relative = ltrim($file, './');
        $path     = __DIR__ . '/../' . $relative;

        // No stamp for something that is not there: a broken link is
        // easier to spot than a broken link wearing a version number.
        $stamp = is_file($path) ? @filemtime($path) : false;

        return '/' . $relative . ($stamp ? '?v=' . $stamp : '');
    }
}
