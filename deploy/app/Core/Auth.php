<?php
namespace App\Core;

/**
 * Session-backed authentication and role checks.
 *
 * Roles, most to least privileged:
 *   admin      - everything, including users & settings
 *   manager    - everything operational, no user/settings admin
 *   finance    - finance, invoicing, payments, expenses, reports
 *   sales      - leads, clients, quotations, invoices
 *   production - the shop floor: job cards, stages, artwork, delivery notes
 *   staff      - read-only on most modules, plus chat
 */
class Auth
{
    private static ?array $user = null;

    /** Capability => roles allowed. */
    private const PERMISSIONS = [
        'dashboard.view'    => ['admin', 'manager', 'finance', 'sales', 'production', 'staff'],

        'inventory.view'    => ['admin', 'manager', 'finance', 'sales', 'production', 'staff'],
        'inventory.manage'  => ['admin', 'manager', 'production'],

        'services.view'     => ['admin', 'manager', 'finance', 'sales', 'production', 'staff'],
        'services.manage'   => ['admin', 'manager'],

        'clients.view'      => ['admin', 'manager', 'finance', 'sales', 'production', 'staff'],
        'clients.manage'    => ['admin', 'manager', 'sales'],
        'clients.delete'    => ['admin'],

        'leads.view'        => ['admin', 'manager', 'sales', 'staff'],
        'leads.manage'      => ['admin', 'manager', 'sales'],
        'leads.delete'      => ['admin', 'manager'],

        'documents.view'    => ['admin', 'manager', 'finance', 'sales', 'production', 'staff'],
        'documents.manage'  => ['admin', 'manager', 'finance', 'sales'],
        'documents.delete'  => ['admin', 'manager'],

        // Production floor
        'jobs.view'         => ['admin', 'manager', 'finance', 'sales', 'production', 'staff'],
        'jobs.manage'       => ['admin', 'manager', 'sales', 'production'],
        'jobs.delete'       => ['admin', 'manager'],
        'jobs.assign'       => ['admin', 'manager', 'production'],
        'jobs.cost'         => ['admin', 'manager', 'finance'],

        'delivery.view'     => ['admin', 'manager', 'finance', 'sales', 'production'],
        'delivery.manage'   => ['admin', 'manager', 'sales', 'production'],

        'payments.view'     => ['admin', 'manager', 'finance', 'sales'],
        'payments.manage'   => ['admin', 'manager', 'finance'],
        'payments.stk'      => ['admin', 'manager', 'finance', 'sales'],

        'expenses.view'     => ['admin', 'manager', 'finance'],
        'expenses.manage'   => ['admin', 'manager', 'finance'],

        'reports.view'      => ['admin', 'manager', 'finance'],

        'chat.use'          => ['admin', 'manager', 'finance', 'sales', 'production', 'staff'],

        'users.view'        => ['admin'],
        'users.manage'      => ['admin'],
        'settings.manage'   => ['admin'],
        'audit.view'        => ['admin'],
    ];

    public static function attempt(string $email, string $password, string $ip): array
    {
        $key = 'login_attempts_' . md5(strtolower($email) . '|' . $ip);
        $max = (int) Config::get('security.max_login_attempts', 5);
        $lockoutSeconds = (int) Config::get('security.lockout_minutes', 15) * 60;

        $state = Session::get($key, ['count' => 0, 'first' => time()]);

        // Reset the window once the lockout period has elapsed.
        if ((time() - $state['first']) > $lockoutSeconds) {
            $state = ['count' => 0, 'first' => time()];
        }

        if ($state['count'] >= $max) {
            $wait = (int) ceil(($lockoutSeconds - (time() - $state['first'])) / 60);
            return ['ok' => false, 'message' => "Too many failed attempts. Try again in {$wait} minute(s)."];
        }

        $user = Database::first(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => strtolower($email)]
        );

        // Always run a hash check so a missing account and a wrong password
        // take a comparable amount of time.
        $hash  = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidi';
        $valid = password_verify($password, $hash);

        if (!$user || !$valid) {
            $state['count']++;
            Session::put($key, $state);
            return ['ok' => false, 'message' => 'Invalid email or password.'];
        }

        if ((int) $user['is_active'] !== 1) {
            return ['ok' => false, 'message' => 'This account has been deactivated. Contact your administrator.'];
        }

        // Upgrade the stored hash if PHP's default cost has changed.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ], ['id' => $user['id']]);
        }

        Session::forget($key);
        self::login($user);

        return ['ok' => true, 'user' => $user];
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        self::$user = $user;

        Database::update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_seen_at'  => date('Y-m-d H:i:s'),
        ], ['id' => $user['id']]);
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::destroy();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = Session::get('user_id');
        if (!$id) {
            return null;
        }

        $user = Database::first(
            'SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => $id]
        );

        if (!$user) {
            // Account deleted or deactivated mid-session.
            Session::forget('user_id');
            return null;
        }

        self::$user = $user;
        return $user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function role(): string
    {
        return self::user()['role'] ?? 'guest';
    }

    public static function is(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    public static function can(string $permission): bool
    {
        $allowed = self::PERMISSIONS[$permission] ?? null;

        // Unknown permission: deny by default, but never lock out an admin.
        if ($allowed === null) {
            return self::role() === 'admin';
        }

        return in_array(self::role(), $allowed, true);
    }

    /** Abort with 403 unless the current user holds the permission. */
    public static function authorize(string $permission): void
    {
        if (!self::can($permission)) {
            throw new HttpException(403, 'You do not have permission to perform this action.');
        }
    }

    public static function touchSeen(): void
    {
        $id = Session::get('user_id');
        if ($id) {
            Database::update('users', ['last_seen_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        }
    }
}
