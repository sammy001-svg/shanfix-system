<?php
namespace App\Core;

/**
 * Who is signed in to the client portal.
 *
 * Deliberately not Auth. Staff accounts carry roles that reach the whole
 * business, and the two must never be able to be mistaken for one
 * another: a different table, a different session key, and a guard that
 * knows nothing about permissions. A client signed in here cannot become
 * staff by any path, because there is no path.
 *
 * The session key is 'client_user_id' and never 'user_id', so a portal
 * session cannot satisfy an Auth::check() anywhere in the staff system —
 * even if some future code forgets which guard it is behind.
 */
class ClientAuth
{
    private const SESSION_KEY = 'client_user_id';

    /** Cached for the life of the request. */
    private static ?array $cached = null;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $id = Session::get(self::SESSION_KEY);

        if (!$id) {
            return null;
        }

        $row = Database::first(
            'SELECT cu.*, c.name AS client_name, c.client_code, c.status AS client_status
               FROM client_users cu
          LEFT JOIN clients c ON c.id = cu.client_id
              WHERE cu.id = :id
              LIMIT 1',
            ['id' => (int) $id]
        );

        // An account switched off while somebody was signed in loses the
        // session on their next click rather than at their next login.
        if (!$row || $row['status'] !== 'active') {
            self::logout();

            return null;
        }

        self::$cached = $row;

        return $row;
    }

    public static function id(): ?int
    {
        $u = self::user();

        return $u ? (int) $u['id'] : null;
    }

    /** The client whose records this person may see. */
    public static function clientId(): ?int
    {
        $u = self::user();

        return $u && $u['client_id'] ? (int) $u['client_id'] : null;
    }

    public static function login(int $clientUserId): void
    {
        // A fresh id on sign-in, so a session handed over before login
        // cannot be reused after it.
        Session::regenerate();
        Session::put(self::SESSION_KEY, $clientUserId);
        self::$cached = null;

        Database::update('client_users', [
            'last_login_at'   => date('Y-m-d H:i:s'),
            'last_login_ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
            'failed_attempts' => 0,
            'locked_until'    => null,
        ], ['id' => $clientUserId]);
    }

    public static function logout(): void
    {
        Session::forget(self::SESSION_KEY);
        self::$cached = null;
    }

    /**
     * Try a password.
     *
     * @return array{ok:bool, message?:string, user?:array<string,mixed>}
     */
    public static function attempt(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        $row = Database::first(
            'SELECT * FROM client_users WHERE email = :email LIMIT 1',
            ['email' => $email]
        );

        // The same answer whether the address is unknown, the password is
        // wrong, or no password has been set. Anything more specific tells
        // a stranger which addresses have accounts.
        $refuse = ['ok' => false, 'message' => 'That email address and password do not match.'];

        if (!$row) {
            return $refuse;
        }

        if ($row['locked_until'] !== null && strtotime((string) $row['locked_until']) > time()) {
            $mins = max(1, (int) ceil((strtotime((string) $row['locked_until']) - time()) / 60));

            return ['ok' => false, 'message' => 'Too many attempts. Try again in ' . $mins . ' minute(s).'];
        }

        if (empty($row['password_hash']) || !password_verify($password, $row['password_hash'])) {
            self::countFailure($row);

            return $refuse;
        }

        if ($row['status'] === 'suspended') {
            return ['ok' => false, 'message' => 'This account has been turned off. Please contact us.'];
        }

        if ($row['status'] !== 'active') {
            return ['ok' => false, 'message' => 'This account is not verified yet. Check your email for the code.'];
        }

        return ['ok' => true, 'user' => $row];
    }

    /** A wrong password costs an attempt, and eventually a wait. */
    private static function countFailure(array $row): void
    {
        $attempts = (int) $row['failed_attempts'] + 1;
        $lockAt   = null;

        if ($attempts >= 5) {
            $lockAt   = date('Y-m-d H:i:s', time() + 15 * 60);
            $attempts = 0;
        }

        Database::update('client_users', [
            'failed_attempts' => $attempts,
            'locked_until'    => $lockAt,
        ], ['id' => $row['id']]);
    }
}
