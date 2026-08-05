<?php
namespace App\Core;

/**
 * Database-backed application settings, cached per request.
 *
 * Values whose key is listed in SECRETS are stored encrypted at rest
 * (KopoKopo client secret / API key) using the app_key from config.php.
 */
class Settings
{
    private static ?array $cache = null;

    private const SECRETS = [
        'kopokopo_client_secret',
        'kopokopo_api_key',
    ];

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = Database::all('SELECT setting_key, setting_value FROM settings');
        $out  = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }

        self::$cache = $out;
        return $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::load();

        if (!array_key_exists($key, $all) || $all[$key] === null || $all[$key] === '') {
            return $default;
        }

        $value = $all[$key];

        if (in_array($key, self::SECRETS, true)) {
            $plain = Crypto::decrypt($value);
            return $plain === null ? $default : $plain;
        }

        return $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $v = self::get($key);
        return $v === null ? $default : (float) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array((string) $v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, mixed $value): void
    {
        $stored = (string) $value;

        if (in_array($key, self::SECRETS, true) && $stored !== '') {
            $stored = Crypto::encrypt($stored);
        }

        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['k' => $key, 'v' => $stored]
        );

        // Keep the in-memory cache holding the raw stored form so a later
        // get() in the same request decrypts consistently.
        self::$cache[$key] = $stored;
    }

    public static function setMany(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            self::set($k, $v);
        }
    }

    /** True when a secret has a stored value, without revealing it. */
    public static function hasSecret(string $key): bool
    {
        $all = self::load();
        return !empty($all[$key]);
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    // -- Convenience accessors used across views ------------------------

    public static function company(): array
    {
        return [
            'name'    => self::get('company_name', 'Shanfix Technology'),
            'tagline' => self::get('company_tagline', ''),
            'email'   => self::get('company_email', ''),
            'phone'   => self::get('company_phone', ''),
            'address' => self::get('company_address', ''),
            'website' => self::get('company_website', ''),
            'kra_pin' => self::get('company_kra_pin', ''),
            'logo'    => self::get('company_logo', ''),
        ];
    }

    public static function currency(): string
    {
        return self::get('currency', 'KES');
    }

    public static function vatRate(): float
    {
        return self::float('vat_rate', 16.0);
    }
}
