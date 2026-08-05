<?php
namespace App\Core;

/**
 * Per-session CSRF token, verified on every state-changing request.
 */
class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function check(?string $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? '';
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * Verify the token on a POST request or abort with 419.
     */
    public static function verify(Request $request): void
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $token = $request->input('_token') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        if (!self::check(is_string($token) ? $token : null)) {
            throw new HttpException(419, 'Your session expired or the form was tampered with. Please reload and try again.');
        }
    }
}
