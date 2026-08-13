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
     * How many other people can still administer the system.
     *
     * Counts the join table rather than users.role, because an admin may
     * hold that role without it being their primary one — counting only
     * primaries would report zero and block a legitimate change.
     */
    private function otherActiveAdmins(int $excludingUserId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(DISTINCT u.id)
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
              WHERE ur.role = 'admin' AND u.is_active = 1 AND u.id <> :id",
            ['id' => $excludingUserId],
            0
        );
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

        $this->view('users/index', [
            'title'       => 'Users & Roles',
            'users'       => $users,
            'roles'       => self::ROLES,
            'assignments' => $assignments,
        ]);
    }

    public function create(Request $request): void
    {
        $this->view('users/form', [
            'title' => 'New User',
            'user'  => null,
            'roles' => self::ROLES,
            'held'  => [],
        ]);
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

        ActivityLog::record(
            'user_created',
            'user',
            $id,
            'Created ' . $request->input('name') . ' as ' . $this->describeRoles($roles)
        );

        Session::success($request->input('name') . ' can now sign in. Share their password securely.');
        Response::to('/users');
    }

    public function edit(Request $request): void
    {
        $user = $this->findOrFail($request->paramInt('id'));

        $this->view('users/form', [
            'title' => 'Edit ' . $user['name'],
            'user'  => $user,
            'roles' => self::ROLES,
            'held'  => $this->rolesOf((int) $user['id']),
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

        $isSelf   = (int) $user['id'] === (int) Auth::id();
        $newRole  = (string) $request->input('role');
        $isActive = $request->bool('is_active');
        $newRoles = $this->rolesFrom($request, $newRole);

        // Administrator access is now held in the set, not just the primary
        // role, so both safeguards below ask the same question of it.
        $keepsAdmin = in_array('admin', $newRoles, true);
        $wasAdmin   = in_array('admin', $this->rolesOf((int) $user['id']), true);

        // Never let an admin lock themselves — or everyone — out.
        if ($isSelf && (!$keepsAdmin || !$isActive)) {
            $v->custom('roles', false, 'You cannot remove your own administrator access or deactivate yourself.');
        }

        if ($wasAdmin && (!$keepsAdmin || !$isActive) && $this->otherActiveAdmins((int) $user['id']) === 0) {
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

        ActivityLog::record(
            'user_updated',
            'user',
            (int) $user['id'],
            'Updated user ' . $data['name'] . ' — ' . $this->describeRoles($newRoles)
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

        // Asks the role set, not just the primary role: someone can hold
        // administrator as a second role, and deactivating them would still
        // leave the system with nobody able to administer it.
        $isAdmin = in_array('admin', $this->rolesOf((int) $user['id']), true);

        if ($newState === 0 && $isAdmin && $this->otherActiveAdmins((int) $user['id']) === 0) {
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
        if (in_array('admin', $this->rolesOf((int) $user['id']), true)
            && $this->otherActiveAdmins((int) $user['id']) === 0) {
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
