<?php
namespace App\Core;

/**
 * Session-backed authentication and role checks.
 *
 * A user may hold more than one role — the front desk that also raises
 * quotations, the office manager who also keeps the books. Permission
 * checks take the union, so adding a role only ever widens access. See the
 * ROLES constant below for what each one is for.
 */
class Auth
{
    private static ?array $user = null;

    /** roles() is called many times per request; keyed by user id. */
    private static array $roleCache = [];

    /**
     * Every role the system knows about, and what each is for.
     *
     * Order matters only for display — the widest access first, so the list
     * reads from most to least privileged.
     */
    public const ROLES = [
        'admin'      => 'Administrator — full access including users and settings',
        'manager'    => 'Manager — all operations, no user or settings administration',
        'finance'    => 'Finance — invoicing, payments, expenses and reports',
        'sales'      => 'Sales — leads, clients, quotations and invoices',
        'production' => 'Production — job cards, artwork, printing and delivery notes',
        'designer'   => 'Designer — artwork requests, proofs and client approvals',
        'reception'  => 'Reception — front desk: walk-in clients and enquiries, quotations, taking payment, job status',
        'staff'      => 'Staff — read-only across modules, plus team chat',
    ];

    /**
     * Capability => roles allowed.
     *
     * A user holding several roles gets the union of what those roles allow,
     * so adding a role can only ever widen access, never narrow it.
     *
     * Reception sits deliberately between sales and staff: the front desk
     * registers walk-ins, raises a quotation, takes a payment and says
     * whether a job is ready — but does not touch the books, the reports or
     * anyone's account.
     */
    private const PERMISSIONS = [
        'dashboard.view'    => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer'],

        'inventory.view'    => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer'],
        'inventory.manage'  => ['admin', 'manager', 'production'],

        'services.view'     => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer'],
        'services.manage'   => ['admin', 'manager'],

        'clients.view'      => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer'],
        'clients.manage'    => ['admin', 'manager', 'sales', 'reception'],
        'clients.delete'    => ['admin'],

        // Deleting anything at all. Held alongside whatever permission
        // already governs the record, so both must pass: a manager may
        // still edit an invoice and no longer delete one.
        //
        // Retracting your own chat message is not covered — that is
        // unsending something you just typed, it soft-deletes, and the
        // thread keeps the record either way.
        'records.delete'    => ['admin'],

        // Asking a client what they want, and reading what they said.
        // Reception raise these at the counter, so they are included.
        'requests.view'     => ['admin', 'manager', 'sales', 'production', 'reception', 'designer', 'staff'],
        'requests.manage'   => ['admin', 'manager', 'sales', 'reception'],

        // Company letters. Anyone client-facing may read what was sent;
        // writing one puts the company's name on it, so that is narrower.
        'letters.view'      => ['admin', 'manager', 'sales', 'finance', 'reception'],
        'letters.manage'    => ['admin', 'manager', 'sales'],

        'leads.view'        => ['admin', 'manager', 'sales', 'reception', 'staff'],
        // Everyone else sees only the leads allocated to them. Reception
        // is included because they log walk-ins before anyone owns them.
        'leads.view_all'    => ['admin', 'manager', 'reception'],
        'leads.manage'      => ['admin', 'manager', 'sales', 'reception'],
        'leads.delete'      => ['admin', 'manager'],

        'documents.view'    => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer'],
        'documents.manage'  => ['admin', 'manager', 'finance', 'sales', 'reception'],
        'documents.delete'  => ['admin', 'manager'],

        // Production floor
        'jobs.view'         => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer'],
        'jobs.manage'       => ['admin', 'manager', 'production'],
        'jobs.delete'       => ['admin', 'manager'],
        'jobs.assign'       => ['admin', 'manager', 'production'],
        'jobs.cost'         => ['admin', 'manager', 'finance'],

        'delivery.view'     => ['admin', 'manager', 'finance', 'sales', 'production', 'reception'],
        'delivery.manage'   => ['admin', 'manager', 'production'],

        // Reception may see what is owed and send an STK push to collect it,
        // but recording or reversing a payment stays with finance.
        'payments.view'     => ['admin', 'manager', 'finance', 'sales', 'reception'],
        'payments.manage'   => ['admin', 'manager', 'finance'],
        'payments.stk'      => ['admin', 'manager', 'finance', 'sales', 'reception'],

        'expenses.view'     => ['admin', 'manager', 'finance'],
        'expenses.manage'   => ['admin', 'manager', 'finance'],

        // Recurring services — hosting, domains, retainers. Reception can
        // look one up to answer "when does our site renew?"; changing the
        // arrangement or its price is for sales, finance and management.
        'subscriptions.view'   => ['admin', 'manager', 'finance', 'sales', 'reception'],
        'subscriptions.manage' => ['admin', 'manager', 'finance'],

        'reports.view'      => ['admin', 'manager', 'finance'],

        'chat.use'          => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer'],

        // Who may put people into a channel or take them out. The
        // channel's own creator can do it too — see ChatController —
        // so a team lead need not ask an administrator to add someone
        // to a channel they set up themselves.
        'chat.moderate'     => ['admin', 'manager'],

        // The company WhatsApp is one number answered by many people.
        // Everyone client-facing can read and reply; production and general
        // staff have no reason to be inside a customer's conversation.
        'whatsapp.view'     => ['admin', 'manager', 'finance', 'sales', 'reception'],
        'whatsapp.send'     => ['admin', 'manager', 'finance', 'sales', 'reception'],

        // Meetings are a workplace tool, like the chat: anyone on the team
        // can call one and take minutes in it. Only management can delete
        // a meeting, because its minutes are a record of what was agreed.
        'meetings.view'     => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer'],
        'meetings.manage'   => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer'],
        'meetings.delete'   => ['admin', 'manager'],

        // Buying binds the company to a supplier and changes what stock is
        // valued at, so it sits with the people who answer for the money.
        'purchases.view'    => ['admin', 'manager', 'finance', 'production'],
        'purchases.manage'  => ['admin', 'manager', 'finance'],
        'purchases.receive' => ['admin', 'manager', 'finance', 'production'],
        'purchases.delete'  => ['admin', 'manager'],

        // A campaign texts every client at once and spends real SMS units,
        // so it sits with management rather than with everyone who can send
        // a single message to one client.
        'sms.campaign'      => ['admin', 'manager'],

        // Artwork. A designer works their own queue and sends proofs to a
        // client; allocating the work is for whoever runs the studio.
        'artwork.view'      => ['admin', 'manager', 'designer', 'sales', 'production', 'reception'],
        'artwork.manage'    => ['admin', 'manager', 'designer', 'sales', 'reception'],
        'artwork.assign'    => ['admin', 'manager'],
        'artwork.design'    => ['admin', 'manager', 'designer'],
        'artwork.delete'    => ['admin', 'manager'],

        'users.view'        => ['admin'],
        'users.manage'      => ['admin'],
        'settings.manage'   => ['admin'],
        'audit.view'        => ['admin'],
    ];

    public static function attempt(string $email, string $password, string $ip): array
    {
        $max     = (int) Config::get('security.max_login_attempts', 5);
        $minutes = (int) Config::get('security.lockout_minutes', 15);

        $lockout = self::lockoutState(strtolower($email), $ip, $max, $minutes);

        if ($lockout !== null) {
            return ['ok' => false, 'message' => $lockout];
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
            self::recordFailure(strtolower($email), $ip);
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

        self::clearFailures(strtolower($email), $ip);
        self::login($user);

        return ['ok' => true, 'user' => $user];
    }

    /**
     * The audit-log description for a failed sign-in.
     *
     * The counter below matches on this exact text, so the two must be
     * written in one place. Keeping 'login_failed' as the action leaves the
     * audit trail readable rather than a column of hashes.
     */
    private static function failureNote(string $email): string
    {
        return 'Failed sign-in for ' . $email;
    }

    /**
     * The refusal message while locked out, or null to let the attempt run.
     *
     * Counted from the audit log rather than the session. The session-based
     * version could be sidestepped completely by discarding cookies between
     * requests, which costs an attacker nothing and made the limit decorative.
     * The audit log is server-side, so the count survives whatever the client
     * chooses to send.
     */
    private static function lockoutState(string $email, string $ip, int $max, int $minutes): ?string
    {
        try {
            // Scoped to this address as well as this account, so one person
            // mistyping cannot lock a colleague out from somewhere else.
            // The window is cast into the statement rather than bound: some
            // MySQL builds reject a placeholder inside INTERVAL. It comes from
            // config and is forced to an integer, so nothing can ride in on it.
            $window = max(1, $minutes);

            $row = Database::first(
                'SELECT COUNT(*) AS failures, MIN(created_at) AS first_at
                   FROM activity_log
                  WHERE action      = \'login_failed\'
                    AND description = :note
                    AND ip_address  = :ip
                    AND created_at  > (NOW() - INTERVAL ' . $window . ' MINUTE)',
                ['note' => self::failureNote($email), 'ip' => $ip]
            );
        } catch (\Throwable $e) {
            // A logging problem must never lock everyone out of the system.
            Logger::warning('Could not read login attempts: ' . $e->getMessage());
            return null;
        }

        if ((int) ($row['failures'] ?? 0) < $max) {
            return null;
        }

        $elapsed = (time() - strtotime((string) $row['first_at'])) / 60;
        $wait    = max(1, (int) ceil($minutes - $elapsed));

        return "Too many failed attempts. Try again in {$wait} minute(s).";
    }

    private static function recordFailure(string $email, string $ip): void
    {
        try {
            Database::insert('activity_log', [
                'user_id'     => null,
                'action'      => 'login_failed',
                'entity_type' => 'user',
                'description' => self::failureNote($email),
                'ip_address'  => $ip,
                // created_at is left to the column default so the database
                // stamps it. The lockout window compares against NOW(), and a
                // timestamp written by PHP can sit in a different timezone —
                // which silently pushes every failure outside the window and
                // stops the limit counting at all.
            ]);
        } catch (\Throwable $e) {
            Logger::warning('Could not record failed sign-in: ' . $e->getMessage());
        }
    }

    /** A correct password clears the slate for that person on that address. */
    private static function clearFailures(string $email, string $ip): void
    {
        try {
            Database::run(
                'DELETE FROM activity_log
                  WHERE action      = \'login_failed\'
                    AND description = :note
                    AND ip_address  = :ip',
                ['note' => self::failureNote($email), 'ip' => $ip]
            );
        } catch (\Throwable $e) {
            Logger::warning('Could not clear failed sign-ins: ' . $e->getMessage());
        }
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
        // Drop the persistent token too, or "sign out" would only last until
        // the next page load.
        self::forgetRemembered();

        self::$user = null;
        Session::destroy();
    }

    // -- "Keep me signed in" -------------------------------------------

    private const REMEMBER_COOKIE = 'SHANFIX_REMEMBER';

    /**
     * Issue a persistent-login cookie.
     *
     * The cookie carries "selector:validator". Only the selector is stored
     * in the clear; the validator is kept as a SHA-256 hash, so a leaked
     * database cannot be replayed as a login.
     */
    public static function remember(int $userId): void
    {
        if (!Settings::bool('remember_me_enabled', true)) {
            return;
        }

        $days = max(1, min(365, Settings::int('remember_me_days', 30)));

        $selector  = bin2hex(random_bytes(12));   // 24 chars
        $validator = bin2hex(random_bytes(32));   // 64 chars, the secret

        $expires = time() + ($days * 86400);

        Database::insert('remember_tokens', [
            'user_id'        => $userId,
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at'     => date('Y-m-d H:i:s', $expires),
            'user_agent'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'ip_address'     => self::ip(),
        ]);

        self::writeRememberCookie($selector . ':' . $validator, $expires);
    }

    /**
     * Sign in from the persistent cookie, if it is valid.
     *
     * Called during boot when there is no session. Returns true only when a
     * real login happened.
     */
    public static function loginFromCookie(): bool
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';

        if (!is_string($cookie) || !str_contains($cookie, ':')) {
            return false;
        }

        [$selector, $validator] = explode(':', $cookie, 2);

        // Shape check before touching the database.
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            self::forgetRemembered();
            return false;
        }

        $token = Database::first(
            'SELECT * FROM remember_tokens WHERE selector = :s LIMIT 1',
            ['s' => $selector]
        );

        if (!$token) {
            self::forgetRemembered();
            return false;
        }

        if (strtotime($token['expires_at']) < time()) {
            Database::delete('remember_tokens', ['id' => $token['id']]);
            self::forgetRemembered();
            return false;
        }

        // Constant-time comparison — a byte-by-byte one would leak the
        // validator through response timing.
        if (!hash_equals($token['validator_hash'], hash('sha256', $validator))) {
            // The selector is real but the secret is wrong: either a stale
            // cookie or someone guessing. Drop every token for this user so
            // a stolen-and-rotated cookie cannot be used either.
            Database::delete('remember_tokens', ['user_id' => $token['user_id']]);
            self::forgetRemembered();

            Logger::warning('Rejected a remember-me token with a bad validator', [
                'user_id' => $token['user_id'],
                'ip'      => self::ip(),
            ]);

            return false;
        }

        $user = Database::first(
            'SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => $token['user_id']]
        );

        if (!$user) {
            Database::delete('remember_tokens', ['id' => $token['id']]);
            self::forgetRemembered();
            return false;
        }

        // Rotate the secret on every use, so a cookie captured earlier stops
        // working as soon as the genuine user comes back.
        $newValidator = bin2hex(random_bytes(32));
        $days         = max(1, min(365, Settings::int('remember_me_days', 30)));
        $expires      = time() + ($days * 86400);

        Database::update('remember_tokens', [
            'validator_hash' => hash('sha256', $newValidator),
            'expires_at'     => date('Y-m-d H:i:s', $expires),
            'last_used_at'   => date('Y-m-d H:i:s'),
            'ip_address'     => self::ip(),
        ], ['id' => $token['id']]);

        self::writeRememberCookie($selector . ':' . $newValidator, $expires);

        self::login($user);
        Session::put('via_remember', true);

        return true;
    }

    /** Remove this device's token and its cookie. */
    public static function forgetRemembered(): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';

        if (is_string($cookie) && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);

            if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
                try {
                    Database::delete('remember_tokens', ['selector' => $selector]);
                } catch (\Throwable) {
                    // Table may not exist yet on an un-migrated install.
                }
            }
        }

        self::writeRememberCookie('', time() - 3600);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    /**
     * Drop every persistent login for a user — used when the password
     * changes, so old devices cannot stay signed in on the old credential.
     */
    public static function forgetAllRemembered(int $userId): void
    {
        try {
            Database::delete('remember_tokens', ['user_id' => $userId]);
        } catch (\Throwable) {
            // Nothing to clean up on an un-migrated install.
        }
    }

    private static function writeRememberCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => base_path() === '' ? '/' : base_path() . '/',
            'domain'   => '',
            'secure'   => (bool) Config::get('security.secure_cookies', true),
            'httponly' => true,          // JavaScript must never read this
            'samesite' => 'Lax',
        ]);
    }

    private static function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
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

    /**
     * The primary role — what shows on the badge beside someone's name.
     * Permission checks use roles(); this is for display and for choosing a
     * sensible landing page.
     */
    public static function role(): string
    {
        return self::user()['role'] ?? 'guest';
    }

    /**
     * Every role this user holds, primary included.
     *
     * Read from user_roles, which the migration backfilled from users.role.
     * Should that table be unreadable — an upgrade applied only half-way —
     * this falls back to the primary role rather than returning nothing,
     * because an empty set would silently strip everyone of all access.
     */
    public static function roles(): array
    {
        $id = self::id();

        if ($id === null) {
            return [];
        }

        if (isset(self::$roleCache[$id])) {
            return self::$roleCache[$id];
        }

        $primary = self::role();

        try {
            $rows  = Database::all('SELECT role FROM user_roles WHERE user_id = :id', ['id' => $id]);
            $roles = array_column($rows, 'role');
        } catch (\Throwable $e) {
            Logger::warning('Could not read user_roles, falling back to the primary role: ' . $e->getMessage());
            $roles = [];
        }

        // The primary role always counts, even if the join table missed it.
        if ($primary !== 'guest' && !in_array($primary, $roles, true)) {
            $roles[] = $primary;
        }

        return self::$roleCache[$id] = array_values(array_unique($roles));
    }

    /**
     * Drop the cached role set, so the next check re-reads the database.
     * Call after changing someone's roles — otherwise an administrator who
     * edits their own account carries the old permissions for the rest of
     * the request, including the page that renders straight afterwards.
     */
    public static function forgetRoles(?int $userId = null): void
    {
        if ($userId === null) {
            self::$roleCache = [];
            return;
        }

        unset(self::$roleCache[$userId]);
    }

    /** True when the user holds any of the named roles. */
    public static function is(string ...$roles): bool
    {
        return array_intersect($roles, self::roles()) !== [];
    }

    public static function can(string $permission): bool
    {
        $allowed = self::PERMISSIONS[$permission] ?? null;
        $held    = self::roles();

        // Unknown permission: deny by default, but never lock out an admin.
        if ($allowed === null) {
            return in_array('admin', $held, true);
        }

        // Holding several roles grants the union of what they allow.
        return array_intersect($allowed, $held) !== [];
    }

    /**
     * Which roles hold a permission.
     *
     * Lets a query ask "who could own this?" without a second hand-written
     * list of role names drifting out of step with the table above. The
     * roles that may act on leads, for instance, are exactly the roles that
     * should appear in the box that assigns one.
     *
     * @return string[]
     */
    public static function rolesWith(string $permission): array
    {
        return self::PERMISSIONS[$permission] ?? [];
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
