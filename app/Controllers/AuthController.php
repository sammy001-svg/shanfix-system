<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

use App\Core\Settings;
use App\Services\UserOtp;

class AuthController extends Controller
{
    /**
     * The front door.
     *
     * Three kinds of account sign in here and none of them works in the
     * other two's form, so the first thing asked is which one you are.
     * Somebody already signed in is not asked at all — they are sent to
     * whichever of the three they are signed in to.
     */
    public function choose(Request $request): void
    {
        if (Auth::check()) {
            Response::to('/dashboard');
        }

        if (\App\Core\ClientAuth::check()) {
            Response::to('/portal');
        }

        if (\App\Core\PartnerAuth::check()) {
            Response::to('/partners');
        }

        $this->view('auth/choose', [
            'title'     => 'Sign in',
            'portalOn'  => \App\Core\Settings::bool('portal_enabled', true),
            'partnerOn' => \App\Core\Settings::bool('partners_enabled', true),
            // Not a door, so it wears no badge.
            'authKind'  => 'none',
        ], 'auth');
    }

    public function showLogin(Request $request): void
    {
        $this->view('auth/login', ['title' => 'Sign in'], 'auth');
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

        $result = Auth::verifyCredentials($email, $password, $request->ip());

        // The failure itself is recorded inside Auth::verifyCredentials, which also
        // counts it towards the lockout — every caller gets both.
        if (!$result['ok']) {
            Session::error($result['message']);
            Session::flashInput(['email' => $email]);
            Response::to('/login');
        }

        $user = $result['user'];

        // Send OTP to email and phone if enabled
        if (Settings::bool('user_otp_enabled', true)) {
            $issued = UserOtp::issue((int) $user['id'], $user['email']);

            if (!$issued['ok']) {
                Session::error($issued['error']);
                Session::flashInput(['email' => $email]);
                Response::to('/login');
            }

            UserOtp::send($user, $issued['code']);

            Session::put('auth_otp_user_id', (int) $user['id']);
            Session::put('auth_otp_remember', $request->bool('remember'));
            Session::put('auth_otp_email', $user['email']);
            Session::put('auth_otp_phone', $user['phone'] ?? '');

            Session::success('Credentials verified. An OTP code has been sent to your email and phone.');
            Response::to('/login/otp');
        }

        // Fallback if OTP is disabled globally
        if ($request->bool('remember')) {
            try {
                Auth::remember((int) $user['id']);
            } catch (\Throwable $e) {
                Logger::warning('Could not store remember-me token: ' . $e->getMessage());
            }
        }

        Auth::login($user);
        ActivityLog::record('login', 'user', (int) $user['id'], $user['name'] . ' signed in');

        $intended = Session::get('intended_url');
        Session::forget('intended_url');

        Session::success('Welcome back, ' . explode(' ', $user['name'])[0] . '.');

        if (is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
            Response::redirect($intended);
        }

        Response::to('/dashboard');
    }

    public function showOtp(Request $request): void
    {
        $userId = (int) Session::get('auth_otp_user_id', 0);

        if (!$userId) {
            Response::to('/login');
        }

        $this->view('auth/otp', [
            'title'    => 'Enter OTP Code',
            'email'    => (string) Session::get('auth_otp_email', ''),
            'phone'    => (string) Session::get('auth_otp_phone', ''),
            'minutes'  => UserOtp::minutes(),
            'authKind' => 'staff',
        ], 'auth');
    }

    public function verifyOtp(Request $request): void
    {
        $userId = (int) Session::get('auth_otp_user_id', 0);

        if (!$userId) {
            Response::to('/login');
        }

        $code = (string) $request->input('code', '');

        if (trim($code) === '') {
            Session::error('Please enter the 6-digit OTP code sent to your email/SMS.');
            Response::to('/login/otp');
        }

        $checked = UserOtp::verify($userId, $code);

        if (!$checked['ok']) {
            Session::error($checked['error']);
            Response::to('/login/otp');
        }

        $user = Database::first('SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $userId]);

        if (!$user) {
            Session::error('This account is not active or available.');
            Session::forget('auth_otp_user_id');
            Response::to('/login');
        }

        $remember = (bool) Session::get('auth_otp_remember', false);

        Session::forget('auth_otp_user_id');
        Session::forget('auth_otp_remember');
        Session::forget('auth_otp_email');
        Session::forget('auth_otp_phone');

        if ($remember) {
            try {
                Auth::remember((int) $user['id']);
            } catch (\Throwable $e) {
                Logger::warning('Could not store remember-me token: ' . $e->getMessage());
            }
        }

        Auth::login($user);
        ActivityLog::record('login', 'user', (int) $user['id'], $user['name'] . ' signed in with OTP');

        $intended = Session::get('intended_url');
        Session::forget('intended_url');

        Session::success('Welcome back, ' . explode(' ', $user['name'])[0] . '.');

        if (is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
            Response::redirect($intended);
        }

        Response::to('/dashboard');
    }

    public function resendOtp(Request $request): void
    {
        $userId = (int) Session::get('auth_otp_user_id', 0);

        if (!$userId) {
            Response::to('/login');
        }

        $user = Database::first('SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $userId]);

        if (!$user) {
            Response::to('/login');
        }

        $issued = UserOtp::issue((int) $user['id'], $user['email']);

        if (!$issued['ok']) {
            Session::error($issued['error']);
            Response::to('/login/otp');
        }

        UserOtp::send($user, $issued['code']);

        Session::success('A new OTP verification code has been sent to your email and phone.');
        Response::to('/login/otp');
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

        // Every "keep me signed in" device was trusted on the old password,
        // so they all have to sign in again — that is the point of changing it.
        Auth::forgetAllRemembered((int) $me['id']);

        // Force a fresh session so any other active session is invalidated.
        Session::regenerate();

        Session::success(
            'Your password has been changed. Any device you had chosen to stay '
            . 'signed in on will need the new password.'
        );

        Response::to('/profile');
    }
}
