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
