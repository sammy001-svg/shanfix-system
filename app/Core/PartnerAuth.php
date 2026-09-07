<?php
namespace App\Core;

/**
 * Who is signed in to the partner portal.
 *
 * The third guard, and deliberately neither of the other two. Staff carry
 * roles that reach the whole business; clients see one company's records;
 * a partner sees their own customers and what they have earned. Three
 * tables, three session keys, three guards, and no path from any one of
 * them to another.
 *
 * The session key is 'partner_id' and never 'user_id' or
 * 'client_user_id', so a partner session cannot satisfy Auth::check() or
 * ClientAuth::check() anywhere — even if some future code forgets which
 * guard it is behind.
 *
 * A partner IS the login here, rather than an account belonging to one.
 * A client company can have several people who need its invoices; a
 * partner is one person or one agency we deal with, so an application and
 * an approved partner are the same row in different states.
 */
class PartnerAuth
{
    private const SESSION_KEY = 'partner_id';

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
            'SELECT * FROM partners WHERE id = :id LIMIT 1',
            ['id' => (int) $id]
        );

        // A partner suspended while they were signed in loses the session
        // on their next click rather than at their next sign-in.
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

    public static function login(int $partnerId): void
    {
        // A fresh id on sign-in, so a session handed over before login
        // cannot be reused after it.
        Session::regenerate();
        Session::put(self::SESSION_KEY, $partnerId);
        self::$cached = null;

        Database::update('partners', [
            'last_login_at'   => date('Y-m-d H:i:s'),
            'failed_attempts' => 0,
            'locked_until'    => null,
        ], ['id' => $partnerId]);
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
            'SELECT * FROM partners WHERE email = :email LIMIT 1',
            ['email' => $email]
        );

        // The same answer whether the address is unknown, the password is
        // wrong, or no password has been set. Anything more specific tells
        // a stranger which addresses are partners of ours.
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

        // An application still being considered is told where it stands.
        // That is not an enumeration leak: they had to know the password
        // to get this far, and only somebody who applied has one.
        if ($row['status'] === 'pending') {
            return ['ok' => false, 'message' => 'Your application is still with us. We will be in touch as soon as it is decided.'];
        }

        if ($row['status'] === 'rejected') {
            return ['ok' => false, 'message' => 'This application was not taken forward. Please contact us if you think that is wrong.'];
        }

        if ($row['status'] !== 'active') {
            return ['ok' => false, 'message' => 'This account has been turned off. Please contact us.'];
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

        Database::update('partners', [
            'failed_attempts' => $attempts,
            'locked_until'    => $lockAt,
        ], ['id' => $row['id']]);
    }
}
