<?php
namespace App\Core;

/**
 * AES-256-GCM encryption for secrets held in the settings table.
 * Keyed by security.app_key in config.php.
 */
class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'enc:v1:';

    private static function key(): string
    {
        $key = (string) Config::get('security.app_key', '');

        if ($key === '') {
            throw new \RuntimeException(
                'security.app_key is not set in config/config.php. '
                . 'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        // Normalise any length of key material to 32 bytes.
        return hash('sha256', $key, true);
    }

    public static function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Returns null when the value cannot be decrypted (wrong/rotated app_key).
     * Values that were never encrypted are returned unchanged, which keeps
     * settings saved before app_key was configured readable.
     */
    public static function decrypt(string $payload): ?string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            return $payload;
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }
}
