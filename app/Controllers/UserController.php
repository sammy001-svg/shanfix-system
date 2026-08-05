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
    public const ROLES = [
        'admin'      => 'Administrator — full access including users and settings',
        'manager'    => 'Manager — all operations, no user or settings administration',
        'finance'    => 'Finance — invoicing, payments, expenses and reports',
        'sales'      => 'Sales — leads, clients, quotations and invoices',
        'production' => 'Production — job cards, artwork, printing and delivery notes',
        'staff'      => 'Staff — read-only across modules, plus team chat',
    ];

    private const AVATAR_COLORS = [
        '#0C2B4A', '#123A61', '#14874E', '#0F6B3D', '#1A4E80',
        '#9A5B08', '#A62A20', '#1B5E9E', '#08203A', '#0B5730',
    ];

    public function index(Request $request): void
    {
        $users = Database::all(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM leads WHERE assigned_to = u.id) AS lead_count,
                    (SELECT COUNT(*) FROM documents WHERE created_by = u.id) AS doc_count
               FROM users u
           ORDER BY u.is_active DESC, u.name'
        );

        $this->view('users/index', [
            'title' => 'Users & Roles',
            'users' => $users,
            'roles' => self::ROLES,
        ]);
    }

    public function create(Request $request): void
    {
        $this->view('users/form', [
            'title' => 'New User',
            'user'  => null,
            'roles' => self::ROLES,
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

        $id = Database::insert('users', [
            'name'          => (string) $request->input('name'),
            'email'         => strtolower((string) $request->input('email')),
            'phone'         => $request->input('phone') ?: null,
            'password_hash' => password_hash((string) $request->input('password'), PASSWORD_DEFAULT),
            'role'          => (string) $request->input('role', 'staff'),
            'job_title'     => $request->input('job_title') ?: null,
            'avatar_color'  => self::AVATAR_COLORS[array_rand(self::AVATAR_COLORS)],
            'is_active'     => $request->bool('is_active') ? 1 : 0,
        ]);

        ActivityLog::record(
            'user_created',
            'user',
            $id,
            'Created ' . $request->input('name') . ' as ' . label_of((string) $request->input('role'))
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

        // Never let an admin lock themselves — or everyone — out.
        if ($isSelf && ($newRole !== 'admin' || !$isActive)) {
            $v->custom('role', false, 'You cannot remove your own administrator access or deactivate yourself.');
        }

        if ($user['role'] === 'admin' && ($newRole !== 'admin' || !$isActive)) {
            $otherAdmins = (int) Database::scalar(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> :id",
                ['id' => $user['id']],
                0
            );

            if ($otherAdmins === 0) {
                $v->custom('role', false, 'This is the only active administrator. Promote someone else first.');
            }
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

        ActivityLog::record('user_updated', 'user', (int) $user['id'], 'Updated user ' . $data['name']);
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

        if ($newState === 0 && $user['role'] === 'admin') {
            $otherAdmins = (int) Database::scalar(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> :id",
                ['id' => $user['id']],
                0
            );

            if ($otherAdmins === 0) {
                Session::error('This is the only active administrator and cannot be deactivated.');
                Response::to('/users');
            }
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
