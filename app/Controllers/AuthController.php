<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

class AuthController extends Controller
{
    public function showLogin(Request $request): void
    {
        $this->view('auth/login', ['title' => 'Sign in'], 'blank');
    }

    public function login(Request $request): void
    {
        $email    = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');

        $v = new Validator($request->all());
        $v->require('email', 'Email address')
          ->require('password', 'Password');

        if ($v->fails()) {
            $v->redirectBack('/login');
        }

        $result = Auth::attempt($email, $password, $request->ip());

        if (!$result['ok']) {
            ActivityLog::record('login_failed', 'user', null, 'Failed sign-in for ' . $email);
            Session::error($result['message']);
            Session::flashInput(['email' => $email]);
            Response::to('/login');
        }

        ActivityLog::record('login', 'user', (int) $result['user']['id'], $result['user']['name'] . ' signed in');

        $intended = Session::get('intended_url');
        Session::forget('intended_url');

        Session::success('Welcome back, ' . explode(' ', $result['user']['name'])[0] . '.');

        // Only follow an internal path.
        if (is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
            Response::redirect($intended);
        }

        Response::to('/dashboard');
    }

    public function logout(Request $request): void
    {
        if (Auth::check()) {
            ActivityLog::record('logout', 'user', Auth::id(), Auth::user()['name'] . ' signed out');
        }

        Auth::logout();
        Session::start();
        Session::info('You have been signed out.');
        Response::to('/login');
    }

    // -- Profile -------------------------------------------------------

    public function profile(Request $request): void
    {
        $me = Auth::user();

        $stats = [
            'clients'  => (int) Database::scalar('SELECT COUNT(*) FROM clients WHERE created_by = :id', ['id' => $me['id']], 0),
            'leads'    => (int) Database::scalar('SELECT COUNT(*) FROM leads WHERE assigned_to = :id', ['id' => $me['id']], 0),
            'invoices' => (int) Database::scalar("SELECT COUNT(*) FROM documents WHERE created_by = :id AND doc_type='invoice'", ['id' => $me['id']], 0),
        ];

        $this->view('auth/profile', [
            'title' => 'My Profile',
            'me'    => $me,
            'stats' => $stats,
        ]);
    }

    public function updateProfile(Request $request): void
    {
        $me = Auth::user();

        $v = new Validator($request->all());
        $v->require('name', 'Full name')
          ->maxLen('name', 120, 'Full name')
          ->email('email', 'Email address', true)
          ->unique('email', 'users', 'email', 'Email address', (int) $me['id'])
          ->phone('phone', 'Phone number');

        if ($v->fails()) {
            $v->redirectBack('/profile');
        }

        Database::update('users', [
            'name'      => $request->input('name'),
            'email'     => strtolower((string) $request->input('email')),
            'phone'     => $request->input('phone') ?: null,
            'job_title' => $request->input('job_title') ?: null,
        ], ['id' => $me['id']]);

        ActivityLog::record('profile_updated', 'user', (int) $me['id'], 'Updated own profile');
        Session::success('Your profile has been updated.');
        Response::to('/profile');
    }

    public function changePassword(Request $request): void
    {
        $me = Auth::user();

        $current = (string) $request->input('current_password', '');
        $new     = (string) $request->input('new_password', '');

        $v = new Validator($request->all());
        $v->require('current_password', 'Current password')
          ->require('new_password', 'New password')
          ->minLen('new_password', 8, 'New password')
          ->matches('new_password_confirm', 'new_password', 'Passwords')
          ->custom('current_password', password_verify($current, $me['password_hash']), 'Your current password is incorrect.')
          ->custom('new_password', $current !== $new, 'The new password must be different from the current one.');

        if ($v->fails()) {
            $v->redirectBack('/profile');
        }

        Database::update('users', [
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
        ], ['id' => $me['id']]);

        ActivityLog::record('password_changed', 'user', (int) $me['id'], 'Changed own password');

        // Force a fresh session so any other active session is invalidated.
        Session::regenerate();
        Session::success('Your password has been changed.');
        Response::to('/profile');
    }
}
