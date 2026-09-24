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

    /** Per-user permission exceptions, cached for the request like the roles. */
    private static array $overrideCache = [];

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
        'hr'         => 'HR — staff records, pay and payroll',
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
        'dashboard.view'    => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer', 'hr'],

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

        // Partners, and what they are owed.
        //
        // Sales are the contact point: they look after the relationship,
        // answer the partner's questions and see everything about the
        // account. What they cannot do is bring a partner into existence,
        // decide an application, move a rate, or pay one — each of those
        // commits the business to money, and none of them is a
        // relationship job.
        //
        // The split is deliberate rather than one 'partners.manage' doing
        // all of it, because "look after them" and "commit us to paying
        // them" are different authorities that happen to touch the same
        // record.
        'partners.view'     => ['admin', 'manager', 'sales', 'finance'],
        'partners.create'   => ['admin'],
        'partners.manage'   => ['admin', 'manager'],
        'partners.assign'   => ['admin', 'manager'],
        'partners.pay'      => ['admin', 'finance'],

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

        // Staff records and pay.
        //
        // Its own role rather than folded into 'manager', because an
        // employee record carries what that person earns, their ID number
        // and their bank account. A production manager needs none of that
        // to run a print floor, and every extra pair of eyes on a payroll
        // is a place it can leak from.
        //
        // Preparing a payroll and approving one are deliberately different
        // authorities, as with a partner payout: whoever works out what
        // everybody is paid should not also be the person who signs it
        // off, and paying it is finance's job either way.
        'hr.view'           => ['admin', 'hr'],
        'hr.manage'         => ['admin', 'hr'],
        'payroll.view'      => ['admin', 'hr', 'finance'],
        'payroll.run'       => ['admin', 'hr'],
        'payroll.approve'   => ['admin'],
        'payroll.pay'       => ['admin', 'finance'],

        // The machines. Nearly everyone needs to look one up — whether the
        // laminator is out of action decides what can be promised today —
        // but changing the register is for the people answerable for it.
        'equipment.view'    => ['admin', 'manager', 'finance', 'production', 'designer', 'sales', 'reception', 'staff', 'hr'],
        'equipment.manage'  => ['admin', 'manager', 'production'],

        'reports.view'      => ['admin', 'manager', 'finance'],

        'chat.use'          => ['admin', 'manager', 'finance', 'sales', 'production', 'reception', 'staff', 'designer', 'hr'],

        // Who may put people into a channel or take them out. The
        // channel's own creator can do it too — see ChatController —
        // so a team lead need not ask an administrator to add someone
        // to a channel they set up themselves.
        'chat.moderate'     => ['admin', 'manager'],

        // Live chat with people on the website. Whoever may hold a
        // conversation with a customer may answer one here — but what
        // they actually SEE is narrowed further by which departments
        // they belong to, in live_department_staff. The permission opens
        // the inbox; membership decides what is in it.
        //
        // Designers, production and general staff are left out: a
        // stranger asking about prices should reach somebody who can
        // answer about prices.
        'livechat.use'      => ['admin', 'manager', 'finance', 'sales', 'reception'],

        // Seeing every department regardless of membership, for whoever
        // is watching the queue as a whole rather than working it.
        'livechat.view_all' => ['admin', 'manager'],

        // Creating departments, moving staff between them, and the
        // greeting and office hours the public sees.
        'livechat.manage'   => ['admin', 'manager'],

        // The newsletter list from the website footer. Whoever does the
        // marketing needs it; the export holds only people who asked.
        'newsletter.view'   => ['admin', 'manager', 'sales'],

        // What the website says our clients said. Publishing words under a
        // client's name is marketing's job, so the same people as the
        // newsletter.
        'testimonials.manage' => ['admin', 'manager', 'sales'],

        // Everybody's own email. Every role, because everybody has a
        // mailbox; what each person can open is only ever their own.
        'mail.use'          => ['admin', 'manager', 'finance', 'hr', 'sales', 'production', 'designer', 'reception', 'staff'],

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

        // The Bulk SMS platform customers and partners send through.
        // Seeing it answers "why has my client not got their texts",
        // which sales and finance are asked. Moving units and approving
        // sender IDs is money and the networks' paperwork. The gateway
        // keys send as the whole company and are admin's alone.
        'bulksms.view'      => ['admin', 'manager', 'finance', 'sales'],
        'bulksms.manage'    => ['admin', 'manager', 'finance'],
        'bulksms.approve'   => ['admin', 'manager'],
        'bulksms.settings'  => ['admin'],

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

    /**
     * The permission map, arranged the way somebody thinks about it.
     *
     * The map above is grouped by prefix already — 'jobs.view',
     * 'jobs.manage' — so the grouping is taken from that rather than
     * listed twice and left to drift. This only supplies the words:
     * what to call the module, and one line on what it is for.
     *
     * A prefix missing from here still appears on the access screen,
     * under its own name. That matters more than tidiness: a permission
     * added to the map and forgotten here is one an administrator can
     * still see and set, rather than one that silently belongs to
     * nobody.
     *
     * Order is the order of the sidebar, because that is where somebody
     * looks for a module before they come here to ask who may open it.
     */
    public const MODULES = [
        'dashboard'     => ['Dashboard',        'The figures on the front page'],

        'leads'         => ['Leads',            'Enquiries before they become clients'],
        'clients'       => ['Clients',          'The client list and everything on it'],
        'requests'      => ['Job briefs',       'What a client has asked for'],
        'letters'       => ['Letters',          'Company letters under our name'],

        'documents'     => ['Quotations & invoices', 'Proposals, quotations, invoices and receipts'],

        'artwork'       => ['Artwork',          'Design work, proofs and client approvals'],
        'jobs'          => ['Job board',        'Work on the production floor'],
        'delivery'      => ['Delivery notes',   'What left the workshop and who signed for it'],

        'services'      => ['Services',         'The price list'],
        'subscriptions' => ['Recurring',        'Hosting, domains and retainers'],
        'inventory'     => ['Inventory',        'Stock on the shelf'],
        'purchases'     => ['Purchasing',       'Orders to suppliers'],

        'payments'      => ['Payments',         'Money in, and M-Pesa'],
        'expenses'      => ['Expenses',         'Money out'],
        'reports'       => ['Reports',          'The numbers, across everything'],

        'partners'      => ['Partners',         'The reseller programme and what they are owed'],

        'mail'          => ['Email',            'Their own mailbox — never anybody else\'s'],
        'livechat'      => ['Live chat',        'People on the website asking questions'],
        'whatsapp'      => ['WhatsApp',         'The company WhatsApp number'],
        'chat'          => ['Team chat',        'Colleagues talking to each other'],
        'meetings'      => ['Meetings',         'Video calls and their minutes'],

        'sms'           => ['Text our clients', 'Sending a text to every client at once'],
        'newsletter'    => ['Newsletter',       'The mailing list from the website'],
        'testimonials'  => ['Testimonials',     'What the website says our clients said'],
        'bulksms'       => ['SMS platform',     'The product customers and partners send through'],

        'hr'            => ['Staff records',    'Employee records, ID numbers, bank details'],
        'payroll'       => ['Payroll',          'What everybody is paid'],
        'equipment'     => ['Equipment',        'The machines and their servicing'],

        'users'         => ['Users & roles',    'Who may sign in, and what they may do'],
        'settings'      => ['Settings',         'How the whole system behaves'],
        'audit'         => ['Audit trail',      'Everything anybody did'],
        'records'       => ['Deleting records', 'Removing anything at all, anywhere'],
    ];

    /**
     * What an action is called, in words rather than in dots.
     *
     * Read by suffix so a new 'x.view' needs nothing added here, with
     * exceptions for the ones whose suffix does not say enough on its
     * own — 'payroll.run' and 'payroll.approve' are different jobs and
     * "Run" alone would not say so.
     */
    private const ACTION_WORDS = [
        'view'      => ['See it', 'Open the module and read what is in it'],
        'use'       => ['Use it', 'Open the module and work in it'],
        'manage'    => ['Add and change', 'Create records and edit existing ones'],
        'delete'    => ['Delete', 'Remove records for good'],
        'assign'    => ['Allocate', 'Decide who the work belongs to'],
        'approve'   => ['Approve', 'Sign off what somebody else prepared'],
        'pay'       => ['Pay', 'Release the money'],
        'send'      => ['Send', 'Send a message out'],
        'create'    => ['Create', 'Bring a new one into existence'],
        'receive'   => ['Receive', 'Book goods in against an order'],
        'settings'  => ['Settings', 'Change how it is configured'],
        'moderate'  => ['Moderate', 'Put people into a channel or take them out'],
        'cost'      => ['See costs', 'What a job costs us, not only what it sells for'],
        'run'       => ['Prepare', 'Work out what everybody is owed'],
        'stk'       => ['Ask for payment', 'Send an M-Pesa prompt to a phone'],
        'campaign'  => ['Send a campaign', 'Text every client at once — this spends SMS units'],
        'design'    => ['Do the design', 'Work the artwork queue'],
        'view_all'  => ['See everybody\'s', 'Not only the ones allocated to them'],
    ];

    /** The exceptions, where the module changes what a word means. */
    private const ACTION_OVERRIDES = [
        'partners.manage'   => ['Change the arrangement', 'Decide applications and move rates'],
        'partners.pay'      => ['Pay them', 'Release a payout, and see their bank details'],
        'payroll.approve'   => ['Approve a payroll', 'Sign off what HR prepared'],
        'records.delete'    => ['Delete anything', 'Needed on top of the module\'s own permission'],
        'bulksms.settings'  => ['Gateway keys', 'These send as the whole company'],
        'livechat.view_all' => ['See every department', 'Not only the ones they answer for'],
        'users.manage'      => ['Add and change people', 'Including what everybody else may do'],
    ];

    /**
     * Every module, with the permissions inside it.
     *
     * Built from PERMISSIONS, so a permission cannot be added to the
     * system and left off the screen that grants it.
     *
     * @return array<string, array{label:string, blurb:string,
     *                             permissions:array<string, array{label:string, hint:string}>}>
     */
    public static function modules(): array
    {
        static $built = null;

        if ($built !== null) {
            return $built;
        }

        $groups = [];

        foreach (array_keys(self::PERMISSIONS) as $permission) {
            [$prefix, $action] = array_pad(explode('.', $permission, 2), 2, '');
            $groups[$prefix][$permission] = self::describe($permission, $action);
        }

        // MODULES first and in its own order, then anything it does not
        // name — so a forgotten prefix is visible rather than missing.
        $out = [];

        foreach (self::MODULES as $prefix => [$label, $blurb]) {
            if (isset($groups[$prefix])) {
                $out[$prefix] = ['label' => $label, 'blurb' => $blurb,
                                 'permissions' => $groups[$prefix]];
                unset($groups[$prefix]);
            }
        }

        foreach ($groups as $prefix => $permissions) {
            $out[$prefix] = ['label' => label_of($prefix), 'blurb' => '',
                             'permissions' => $permissions];
        }

        return $built = $out;
    }

    /** @return array{label:string, hint:string} */
    private static function describe(string $permission, string $action): array
    {
        if (isset(self::ACTION_OVERRIDES[$permission])) {
            [$label, $hint] = self::ACTION_OVERRIDES[$permission];
            return ['label' => $label, 'hint' => $hint];
        }

        if (isset(self::ACTION_WORDS[$action])) {
            [$label, $hint] = self::ACTION_WORDS[$action];
            return ['label' => $label, 'hint' => $hint];
        }

        return ['label' => label_of($action), 'hint' => ''];
    }

    /** Whether a string names a permission this system has. */
    public static function isPermission(string $permission): bool
    {
        return isset(self::PERMISSIONS[$permission]);
    }

    /** @return string[] every permission the system defines */
    public static function allPermissions(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /**
     * What a set of roles allows, before anybody's exceptions.
     *
     * This is what the access screen starts from when somebody picks a
     * role, and what an account falls back to when it has no exceptions
     * of its own.
     *
     * @param string[] $roles
     * @return string[]
     */
    public static function permissionsForRoles(array $roles): array
    {
        $out = [];

        foreach (self::PERMISSIONS as $permission => $allowed) {
            if (array_intersect($allowed, $roles) !== []) {
                $out[] = $permission;
            }
        }

        return $out;
    }


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
     * Check password and account state without logging the user in yet.
     * Used by 2FA / OTP step.
     */
    public static function verifyCredentials(string $email, string $password, string $ip): array
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

        $hash  = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidi';
        $valid = password_verify($password, $hash);

        if (!$user || !$valid) {
            self::recordFailure(strtolower($email), $ip);
            return ['ok' => false, 'message' => 'Invalid email or password.'];
        }

        if ((int) $user['is_active'] !== 1) {
            return ['ok' => false, 'message' => 'This account has been deactivated. Contact your administrator.'];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ], ['id' => $user['id']]);
        }

        self::clearFailures(strtolower($email), $ip);

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
     * Drop what is cached about somebody's access, so the next check
     * re-reads the database. Roles and their own exceptions both, since
     * either can change in the same save.
     *
     * Call after changing either — otherwise an administrator who edits
     * their own account carries the old permissions for the rest of the
     * request, including the page that renders straight afterwards.
     */
    public static function forgetRoles(?int $userId = null): void
    {
        if ($userId === null) {
            self::$roleCache     = [];
            self::$overrideCache = [];
            return;
        }

        unset(self::$roleCache[$userId], self::$overrideCache[$userId]);
    }

    /** True when the user holds any of the named roles. */
    public static function is(string ...$roles): bool
    {
        return array_intersect($roles, self::roles()) !== [];
    }

    /**
     * The exceptions set for one person, permission => bool.
     *
     * Empty for almost everybody: a role decides what most people may
     * do, and this holds only where somebody has overruled that for one
     * account.
     *
     * A missing table is treated as "no exceptions" rather than as an
     * error. That is the safe direction — an upgrade applied only half
     * way leaves everyone on their role's access, which is what they
     * had before this existed, instead of locking the office out.
     *
     * @return array<string, bool>
     */
    public static function overridesFor(?int $userId = null): array
    {
        $id = $userId ?? self::id();

        if ($id === null) {
            return [];
        }

        if (isset(self::$overrideCache[$id])) {
            return self::$overrideCache[$id];
        }

        try {
            $rows = Database::all(
                'SELECT permission, allowed FROM user_permissions WHERE user_id = :id',
                ['id' => $id]
            );
        } catch (\Throwable $e) {
            Logger::warning('Could not read user_permissions, everyone is on their roles: ' . $e->getMessage());
            $rows = [];
        }

        $out = [];

        foreach ($rows as $row) {
            // A row naming a permission the system no longer has is
            // ignored: removing one from the map must not be able to
            // grant something by accident.
            if (isset(self::PERMISSIONS[$row['permission']])) {
                $out[$row['permission']] = (int) $row['allowed'] === 1;
            }
        }

        return self::$overrideCache[$id] = $out;
    }

    /**
     * Whether this account has been tailored at all.
     *
     * Used to say "on the Sales defaults" rather than "3 exceptions" on
     * a screen, which is a different question from what they may do.
     */
    public static function hasOverrides(int $userId): bool
    {
        return self::overridesFor($userId) !== [];
    }

    public static function can(string $permission): bool
    {
        $held = self::roles();

        // An exception set for this person settles it, either way. Asked
        // before the roles, because that is what an exception is for:
        // this salesperson may also buy, that designer may not see costs.
        $overrides = self::overridesFor();

        if (array_key_exists($permission, $overrides)) {
            return $overrides[$permission];
        }

        $allowed = self::PERMISSIONS[$permission] ?? null;

        // Unknown permission: deny by default, but never lock out an admin.
        if ($allowed === null) {
            return in_array('admin', $held, true);
        }

        // Holding several roles grants the union of what they allow.
        return array_intersect($allowed, $held) !== [];
    }

    /**
     * Everything this person may do, roles and exceptions together.
     *
     * The single answer to "what does this account actually have", for
     * the screen that sets it and for anybody reading it afterwards.
     *
     * @param string[] $roles
     * @param array<string, bool> $overrides
     * @return string[]
     */
    public static function effectivePermissions(array $roles, array $overrides = []): array
    {
        $out = [];

        foreach (self::PERMISSIONS as $permission => $allowed) {
            $out[$permission] = array_key_exists($permission, $overrides)
                ? $overrides[$permission]
                : array_intersect($allowed, $roles) !== [];
        }

        return array_keys(array_filter($out));
    }

    /**
     * Which roles hold a permission.
     *
     * Lets a query ask "who could own this?" without a second hand-written
     * list of role names drifting out of step with the table above. The
     * roles that may act on leads, for instance, are exactly the roles that
     * should appear in the box that assigns one.
     *
     * Roles only. Where the answer has to be people rather than roles,
     * usersWith() is the one to call — it knows about exceptions, and
     * this cannot.
     *
     * @return string[]
     */
    public static function rolesWith(string $permission): array
    {
        return self::PERMISSIONS[$permission] ?? [];
    }

    /**
     * Everybody who may do this, exceptions included.
     *
     * The question every "who should this be assigned to?" box is really
     * asking. A role list alone gets it wrong in both directions now:
     * it would leave out the salesperson granted purchasing by hand, and
     * offer the designer whose access to costs was taken away.
     *
     * Ordered by name, because these fill a picker.
     *
     * @return list<array{id:int, name:string, email:?string}>
     */
    public static function usersWith(string $permission): array
    {
        $roles = self::PERMISSIONS[$permission] ?? [];

        // Somebody whose role allows it and who has not been excepted
        // out of it, plus anybody excepted into it whatever their role.
        // One query rather than two merged in PHP, so the ordering is
        // the database's and not a second sort over the top.
        //
        // A permission no role holds leaves the role half as a literal
        // false: everybody in the answer is then there by exception,
        // which is correct and is not the same as everybody.
        $byRole = '0';
        $params = [$permission];

        if ($roles !== []) {
            $slots  = implode(',', array_fill(0, count($roles), '?'));
            // u.role as well as user_roles: the primary role counts even
            // where the join table has somehow missed it, which is the
            // same fallback roles() makes.
            $byRole = "(ur.role IN ({$slots}) OR u.role IN ({$slots}))";
            $params = array_merge($params, $roles, $roles);
        }

        return Database::all(
            "SELECT DISTINCT u.id, u.name, u.email
               FROM users u
          LEFT JOIN user_roles ur ON ur.user_id = u.id
          LEFT JOIN user_permissions px ON px.user_id = u.id AND px.permission = ?
              WHERE u.is_active = 1
                AND (px.allowed = 1 OR (px.allowed IS NULL AND {$byRole}))
           ORDER BY u.name",
            $params
        );
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
