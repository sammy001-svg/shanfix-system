<?php
namespace App\Core;

/**
 * AES-256-GCM encryption for secrets held in the settings table.
 *
 * Keyed by security.app_key in config.php. When that is blank — the common
 * case on a hand-copied deployment — a key is generated once and kept in
 * storage/app.key, so nobody has to edit config.php by hand before they can
 * save an SMTP password. config.php still wins if it has a key, which keeps
 * existing installs decrypting exactly as before.
 */
class Crypto
{
    private const CIPHER   = 'aes-256-gcm';
    private const PREFIX   = 'enc:v1:';
    private const KEY_FILE = 'app.key';

    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $key = trim((string) Config::get('security.app_key', ''));

        if ($key === '') {
            $key = self::fileKey();
        }

        // Normalise any length of key material to 32 bytes.
        return self::$key = hash('sha256', $key, true);
    }

    /**
     * Read storage/app.key, generating it on first use.
     *
     * Losing this file has the same effect as changing app_key: stored
     * secrets stop decrypting and have to be re-entered. So a failure to
     * write it is fatal rather than silently falling back to a per-request
     * key, which would encrypt secrets nobody could ever read back.
     */
    private static function fileKey(): string
    {
        $path = STORAGE_PATH . '/' . self::KEY_FILE;

        if (is_file($path)) {
            $existing = trim((string) @file_get_contents($path));

            if ($existing !== '') {
                return $existing;
            }
        }

        $generated = bin2hex(random_bytes(32));

        // Exclusive create, so two simultaneous requests cannot each write a
        // key and leave the loser's secrets unreadable.
        $handle = @fopen($path, 'xb');

        if ($handle === false) {
            // Someone beat us to it — read theirs.
            $existing = trim((string) @file_get_contents($path));

            if ($existing !== '') {
                return $existing;
            }

            throw new \RuntimeException(
                'Could not create the encryption key at ' . $path . '. '
                . 'Make storage/ writable, or set security.app_key in config/config.php.'
            );
        }

        fwrite($handle, $generated);
        fclose($handle);
        @chmod($path, 0600);

        return $generated;
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
