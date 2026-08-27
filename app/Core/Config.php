<?php
namespace App\Core;

/**
 * Immutable access to config/config.php using dot notation.
 */
class Config
{
    private static array $items = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException(
                'Missing config file. Copy config/config.sample.php to config/config.php and fill in your settings.'
            );
        }
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new \RuntimeException('config.php must return an array.');
        }
        self::$items = $loaded;

        // Set here rather than in App::run(), because App::run() is the web
        // entry point and cron.php never reaches it. Left there, PHP on the
        // command line stayed on UTC while MySQL and every web request ran
        // on Nairobi — three hours apart. A cron writing a timestamp, or
        // reading date('H') to decide whether it is inside the send window,
        // was working from the wrong clock.
        date_default_timezone_set(self::get('app.timezone', 'Africa/Nairobi'));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function all(): array
    {
        return self::$items;
    }
}
