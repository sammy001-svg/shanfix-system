<?php
namespace App\Core;

/**
 * Session bootstrap plus flash messaging.
 */
class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = (int) Config::get('app.session_lifetime', 480) * 60;
        $secure   = (bool) Config::get('security.secure_cookies', true);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('SHANFIX_SESSID');
        session_start();

        // Idle timeout
        $now = time();
        if (isset($_SESSION['_last_activity']) && ($now - $_SESSION['_last_activity']) > $lifetime) {
            self::destroy();
            session_start();
            $_SESSION['_expired'] = true;
        }
        $_SESSION['_last_activity'] = $now;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // -- Flash ---------------------------------------------------------

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::flash('success', $message);
    }

    public static function error(string $message): void
    {
        self::flash('error', $message);
    }

    public static function warning(string $message): void
    {
        self::flash('warning', $message);
    }

    public static function info(string $message): void
    {
        self::flash('info', $message);
    }

    /** Read and clear all flash messages. */
    public static function pullFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    /**
     * Keep submitted form values so a failed form can be re-rendered filled in.
     */
    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirm'], $input['_token']);
        $_SESSION['_old'] = $input;
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old'][$key] ?? $default;
    }

    public static function clearOld(): void
    {
        unset($_SESSION['_old']);
    }

    public static function flashErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    public static function pullErrors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return $errors;
    }
}
