<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

class UserController extends Controller
{
    /**
     * Defined once, on Auth, beside the permission map it has to agree with.
     * Kept here as an alias so existing references keep working.
     */
    public const ROLES = Auth::ROLES;

    private const AVATAR_COLORS = [
        '#0C2B4A', '#123A61', '#14874E', '#0F6B3D', '#1A4E80',
        '#9A5B08', '#A62A20', '#1B5E9E', '#08203A', '#0B5730',
    ];

    /**
     * The roles ticked on the form, cleaned up.
     *
     * The primary role is always included: it is what the badge shows and
     * what the account falls back to, so it must never be absent from the
     * set the permission checks read.
     *
     * @return string[]
     */
    private function rolesFrom(Request $request, string $primary): array
    {
        $submitted = $request->input('roles', []);
        $submitted = is_array($submitted) ? $submitted : [];

        // Only roles the system actually defines — nothing invented in the
        // form post can widen anyone's access.
        $roles = array_values(array_intersect(array_keys(Auth::ROLES), $submitted));

        if (!in_array($primary, $roles, true)) {
            array_unshift($roles, $primary);
        }

        return $roles;
    }

    /** Replace someone's role set in one go. */
    private function storeRoles(int $userId, array $roles): void
    {
        Database::run('DELETE FROM user_roles WHERE user_id = :id', ['id' => $userId]);

        foreach ($roles as $role) {
            Database::insert('user_roles', ['user_id' => $userId, 'role' => $role]);
        }

        // The signed-in user may have just edited their own roles.
        Auth::forgetRoles($userId);
    }

    /** "Sales and Reception" — for the audit trail. */
    private function describeRoles(array $roles): string
    {
        $labels = array_map(static fn(string $r): string => label_of($r), $roles);

        if (count($labels) < 2) {
            return $labels[0] ?? 'no role';
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' and ' . $last;
    }

    /** @return string[] every role held, primary included */
    private function rolesOf(int $userId): array
    {
        return array_column(
            Database::all('SELECT role FROM user_roles WHERE user_id = :id', ['id' => $userId]),
            'role'
        );
    }

    /**
     * What the access boxes on the form came back as.
     *
     * Returns the exceptions to store — permission => bool — and not the
     * whole ticked list. Only where somebody has overruled their roles is
     * anything written down; see migration 050 for why.
     *
     * An untouched form, with the tailoring switch off, returns nothing
     * at all, which puts the account back on its roles.
     *
     * @param string[] $roles the roles the account will hold after this save
     * @return array<string, bool>
     */
    private function overridesFrom(Request $request, array $roles): array
    {
        if (!$request->bool('custom_access')) {
            return [];
        }

        $ticked = $request->input('permissions', []);
        $ticked = is_array($ticked) ? $ticked : [];

        // Only permissions this system defines. Nothing invented in a
        // form post can grant access to something that does not exist,
        // and nothing left over from an older version can linger.
        $ticked = array_flip(array_filter(
            array_map('strval', $ticked),
            static fn(string $p): bool => Auth::isPermission($p)
        ));

        $fromRoles = array_flip(Auth::permissionsForRoles($roles));
        $out       = [];

        foreach (Auth::allPermissions() as $permission) {
            $wanted  = isset($ticked[$permission]);
            $byRole  = isset($fromRoles[$permission]);

            // Agreeing with the role is not an exception, so it is not
            // stored. That is what keeps a change to what Sales means
            // reaching every salesperson.
            if ($wanted !== $byRole) {
                $out[$permission] = $wanted;
            }
        }

        return $out;
    }

    /** Replace someone's exceptions in one go. */
    private function storeOverrides(int $userId, array $overrides): void
    {
        Database::run('DELETE FROM user_permissions WHERE user_id = :id', ['id' => $userId]);

        foreach ($overrides as $permission => $allowed) {
            Database::insert('user_permissions', [
                'user_id'    => $userId,
                'permission' => $permission,
                'allowed'    => $allowed ? 1 : 0,
                'granted_by' => Auth::id(),
            ]);
        }

        // The signed-in user may have just edited their own access.
        Auth::forgetRoles($userId);
    }

    /** @return array<string, bool> the exceptions already on an account */
    private function overridesOf(int $userId): array
    {
        $out = [];

        foreach (Database::all(
            'SELECT permission, allowed FROM user_permissions WHERE user_id = :id',
            ['id' => $userId]
        ) as $row) {
            $out[$row['permission']] = (int) $row['allowed'] === 1;
        }

        return $out;
    }

    /**
     * "2 added, 1 taken away" — for the audit trail and the user list.
     *
     * Counted rather than listed: a hand-tailored account can carry a
     * dozen exceptions and a log line naming all of them is one nobody
     * reads to the end.
     */
    private function describeOverrides(array $overrides): string
    {
        if ($overrides === []) {
            return 'their roles as they stand';
        }

        $added = count(array_filter($overrides));
        $taken = count($overrides) - $added;

        $bits = [];

        if ($added > 0) {
            $bits[] = $added . ' permission' . ($added === 1 ? '' : 's') . ' added';
        }

        if ($taken > 0) {
            $bits[] = $taken . ' taken away';
        }

        return implode(' and ', $bits);
    }

    /**
     * Whether this account can actually administer the system.
     *
     * Not "holds the admin role": an exception can take users.manage
     * away from somebody who holds it, and a role alone would then say
     * yes about a person who cannot open the users screen.
     */
    private function isAdministrator(int $userId): bool
    {
        return in_array('users.manage', Auth::effectivePermissions(
            $this->rolesOf($userId),
            $this->overridesOf($userId)
        ), true);
    }

    /**
     * How many other people can still administer the system.
     *
     * Asks what each of them may actually do rather than what role they
     * hold, because an administrator whose users.manage has been taken
     * away by an exception cannot administer anything — counting them
     * would be counting somebody who could not unlock the door.
     */
    private function otherAdministrators(int $excludingUserId): int
    {
        $able = Auth::usersWith('users.manage');

        return count(array_filter(
            $able,
            static fn(array $u): bool => (int) $u['id'] !== $excludingUserId
        ));
    }


    public function index(Request $request): void
    {
        $users = Database::all(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM leads WHERE assigned_to = u.id) AS lead_count,
                    (SELECT COUNT(*) FROM documents WHERE created_by = u.id) AS doc_count
               FROM users u
           ORDER BY u.is_active DESC, u.name'
        );

        // One query for every assignment, then grouped in PHP — avoids a
        // per-user query inside the list.
        $assignments = [];
        foreach (Database::all('SELECT user_id, role FROM user_roles') as $row) {
            $assignments[(int) $row['user_id']][] = $row['role'];
        }

        // How many permissions each account has had set by hand. One
        // query for everybody, like the assignments above.
        $exceptions = [];
        foreach (Database::all(
            'SELECT user_id, COUNT(*) AS n FROM user_permissions GROUP BY user_id'
        ) as $row) {
            $exceptions[(int) $row['user_id']] = (int) $row['n'];
        }

        $this->view('users/index', [
            'title'       => 'Users & Roles',
            'users'       => $users,
            'roles'       => self::ROLES,
            'assignments' => $assignments,
            'exceptions'  => $exceptions,
        ]);
    }

    public function create(Request $request): void
    {
        $this->view('users/form', [
            'title'     => 'New User',
            'user'      => null,
            'roles'     => self::ROLES,
            'held'      => [],
            'modules'   => Auth::modules(),
            'overrides' => [],
            // What each role allows, so the boxes can follow the role
            // picker without a round trip. Sent for every role, because
            // whoever is filling the form has not chosen one yet.
            'byRole'    => $this->permissionsByRole(),
        ]);
    }

    /**
     * What every role allows, for the access boxes to read.
     *
     * The form has to be able to answer "what does Sales get?" the
     * instant somebody picks Sales, and it must be the same answer the
     * server would give. So the map goes to the page rather than being
     * written out a second time in JavaScript.
     *
     * @return array<string, string[]>
     */
    private function permissionsByRole(): array
    {
        $out = [];

        foreach (array_keys(Auth::ROLES) as $role) {
            $out[$role] = Auth::permissionsForRoles([$role]);
        }

        return $out;
    }

    public function store(Request $request): void
    {
        $v = new Validator($request->all());
        $v->require('name', 'Full name')
          ->maxLen('name', 120, 'Full name')
          ->email('email', 'Email address', true)
          ->unique('email', 'users', 'email', 'Email address')
          ->phone('phone', 'Phone number')
          ->in('role', array_keys(self::ROLES), 'Role')
          ->require('password', 'Password')
          ->minLen('password', 8, 'Password')
          ->matches('password_confirm', 'password', 'Passwords');

        if ($v->fails()) {
            $v->redirectBack('/users/create');
        }

        $primary = (string) $request->input('role', 'staff');
        $roles   = $this->rolesFrom($request, $primary);

        $id = Database::insert('users', [
            'name'          => (string) $request->input('name'),
            'email'         => strtolower((string) $request->input('email')),
            'phone'         => $request->input('phone') ?: null,
            'password_hash' => password_hash((string) $request->input('password'), PASSWORD_DEFAULT),
            'role'          => $primary,
            'job_title'     => $request->input('job_title') ?: null,
            'avatar_color'  => self::AVATAR_COLORS[array_rand(self::AVATAR_COLORS)],
            'is_active'     => $request->bool('is_active') ? 1 : 0,
        ]);

        $this->storeRoles($id, $roles);

        $overrides = $this->overridesFrom($request, $roles);
        $this->storeOverrides($id, $overrides);

        ActivityLog::record(
            'user_created',
            'user',
            $id,
            'Created ' . $request->input('name') . ' as ' . $this->describeRoles($roles)
            . ($overrides === [] ? '' : ', with ' . $this->describeOverrides($overrides))
        );

        Session::success($request->input('name') . ' can now sign in. Share their password securely.');
        Response::to('/users');
    }

    public function edit(Request $request): void
    {
        $user = $this->findOrFail($request->paramInt('id'));

        $this->view('users/form', [
            'title'     => 'Edit ' . $user['name'],
            'user'      => $user,
            'roles'     => self::ROLES,
            'held'      => $this->rolesOf((int) $user['id']),
            'modules'   => Auth::modules(),
            'overrides' => $this->overridesOf((int) $user['id']),
            'byRole'    => $this->permissionsByRole(),
        ]);
    }

    public function update(Request $request): void
    {
        $user = $this->findOrFail($request->paramInt('id'));

        $v = new Validator($request->all());
        $v->require('name', 'Full name')
          ->maxLen('name', 120, 'Full name')
          ->email('email', 'Email address', true)
          ->unique('email', 'users', 'email', 'Email address', (int) $user['id'])
          ->phone('phone', 'Phone number')
          ->in('role', array_keys(self::ROLES), 'Role');

        $isSelf      = (int) $user['id'] === (int) Auth::id();
        $newRole     = (string) $request->input('role');
        $isActive    = $request->bool('is_active');
        $newRoles    = $this->rolesFrom($request, $newRole);
        $newOverride = $this->overridesFrom($request, $newRoles);

        // What this save would actually leave them able to do. Asked of
        // the result rather than of the roles, because an exception can
        // now take away what a role gives — somebody could hold 'admin'
        // and have had users.manage unticked under it.
        $effective  = Auth::effectivePermissions($newRoles, $newOverride);
        $keepsAdmin = in_array('users.manage', $effective, true);
        $wasAdmin   = in_array('users.manage', Auth::effectivePermissions(
            $this->rolesOf((int) $user['id']),
            $this->overridesOf((int) $user['id'])
        ), true);

        // Never let an admin lock themselves — or everyone — out.
        if ($isSelf && (!$keepsAdmin || !$isActive)) {
            $v->custom('roles', false, 'You cannot remove your own administrator access or deactivate yourself.');
        }

        if ($wasAdmin && (!$keepsAdmin || !$isActive) && $this->otherAdministrators((int) $user['id']) === 0) {
            $v->custom('roles', false, 'This is the only active administrator. Promote someone else first.');
        }

        if ($v->fails()) {
            $v->redirectBack('/users/' . $user['id'] . '/edit');
        }

        $data = [
            'name'      => (string) $request->input('name'),
            'email'     => strtolower((string) $request->input('email')),
            'phone'     => $request->input('phone') ?: null,
            'role'      => $newRole,
            'job_title' => $request->input('job_title') ?: null,
            'is_active' => $isActive ? 1 : 0,
        ];

        // Password is optional on edit.
        $password = (string) $request->input('password', '');
        if ($password !== '') {
            if (strlen($password) < 8) {
                Session::error('The new password must be at least 8 characters.');
                Response::to('/users/' . $user['id'] . '/edit');
            }
            if ($password !== (string) $request->input('password_confirm', '')) {
                Session::error('The passwords do not match.');
                Response::to('/users/' . $user['id'] . '/edit');
            }
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        Database::update('users', $data, ['id' => $user['id']]);
        $this->storeRoles((int) $user['id'], $newRoles);
        $this->storeOverrides((int) $user['id'], $newOverride);

        ActivityLog::record(
            'user_updated',
            'user',
            (int) $user['id'],
            'Updated user ' . $data['name'] . ' — ' . $this->describeRoles($newRoles)
            . ', ' . $this->describeOverrides($newOverride)
        );
        Session::success('User updated.' . ($password !== '' ? ' Their password has been reset.' : ''));
        Response::to('/users');
    }

    public function toggleActive(Request $request): void
    {
        $user = $this->findOrFail($request->paramInt('id'));

        if ((int) $user['id'] === (int) Auth::id()) {
            Session::error('You cannot deactivate your own account.');
            Response::to('/users');
        }

        $newState = (int) $user['is_active'] === 1 ? 0 : 1;

        // Asks what they can actually do, not what role they hold:
        // somebody can administer the system as a second role, and
        // deactivating them would still leave nobody able to.
        $isAdmin = $this->isAdministrator((int) $user['id']);

        if ($newState === 0 && $isAdmin && $this->otherAdministrators((int) $user['id']) === 0) {
            Session::error('This is the only active administrator and cannot be deactivated.');
            Response::to('/users');
        }

        Database::update('users', ['is_active' => $newState], ['id' => $user['id']]);

        ActivityLog::record(
            $newState ? 'user_activated' : 'user_deactivated',
            'user',
            (int) $user['id'],
            ($newState ? 'Activated ' : 'Deactivated ') . $user['name']
        );

        Session::success($user['name'] . ' has been ' . ($newState ? 'activated' : 'deactivated') . '.');
        Response::to('/users');
    }

    public function destroy(Request $request): void
    {
        $user = $this->findOrFail($request->paramInt('id'));

        if ((int) $user['id'] === (int) Auth::id()) {
            Session::error('You cannot delete your own account.');
            Response::to('/users');
        }

        // Either branch below ends with this account unable to sign in, so
        // the same protection the edit screen applies belongs here too.
        if ($this->isAdministrator((int) $user['id'])
            && $this->otherAdministrators((int) $user['id']) === 0) {
            Session::error('This is the only active administrator and cannot be removed. Promote someone else first.');
            Response::to('/users');
        }

        // Anyone with history is deactivated so their records keep an author.
        $hasHistory = (int) Database::scalar(
            'SELECT (SELECT COUNT(*) FROM documents WHERE created_by = :id1)
                  + (SELECT COUNT(*) FROM payments WHERE recorded_by = :id2)
                  + (SELECT COUNT(*) FROM chat_messages WHERE user_id = :id3)',
            ['id1' => $user['id'], 'id2' => $user['id'], 'id3' => $user['id']],
            0
        );

        if ($hasHistory > 0) {
            Database::update('users', ['is_active' => 0], ['id' => $user['id']]);
            ActivityLog::record('user_deactivated', 'user', (int) $user['id'], 'Deactivated ' . $user['name']);
            Session::warning(
                $user['name'] . ' has records in the system, so the account was deactivated rather than deleted. '
                . 'Their history stays intact.'
            );
            Response::to('/users');
        }

        Database::delete('users', ['id' => $user['id']]);
        ActivityLog::record('user_deleted', 'user', (int) $user['id'], 'Deleted user ' . $user['name']);
        Session::success($user['name'] . ' has been deleted.');
        Response::to('/users');
    }

    private function findOrFail(int $id): array
    {
        $user = Database::first('SELECT * FROM users WHERE id = :id', ['id' => $id]);

        if (!$user) {
            throw new HttpException(404, 'That user does not exist.');
        }

        return $user;
    }
}
