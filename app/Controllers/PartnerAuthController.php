<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\PartnerAuth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\Notifier;
use App\Services\PartnerOtp;
use App\Services\StaffNotifier;

/**
 * Getting into the partner portal, and asking to be let in.
 *
 * Two ways in, because a partner is in one of two states:
 *
 *   * they have been approved  — email and password
 *   * they have not applied    — an application, which an administrator
 *                                decides on
 *
 * An application and an approved partner are the same row in `partners`
 * at different statuses, so approving is a status change rather than a
 * copy between tables that can half-fail.
 */
class PartnerAuthController extends Controller
{
    private function assertEnabled(): void
    {
        if (!Settings::bool('partners_enabled', true)) {
            throw new HttpException(404, 'The partner portal is not available.');
        }
    }

    // -- Signing in --------------------------------------------------------

    public function showLogin(Request $request): void
    {
        $this->assertEnabled();

        if (PartnerAuth::check()) {
            Response::to('/partners');
        }

        $this->view('partner/login', [
            'title'     => 'Partner sign in',
            'company'   => Settings::company(),
            'signupOn'  => Settings::bool('partner_signup_enabled', true),
            'authKind'  => 'partner',
        ], 'auth');
    }

    public function login(Request $request): void
    {
        $this->assertEnabled();

        $email    = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        $attempt = PartnerAuth::attempt($email, $password);

        if (!$attempt['ok']) {
            Session::flash('error', $attempt['message'] ?? 'Sign in failed.');
            Session::flashInput(['email' => $email]);
            Response::to('/partners/login');
        }

        PartnerAuth::login((int) $attempt['user']['id']);

        $intended = Session::get('partner_intended_url');
        Session::forget('partner_intended_url');

        // Only ever back into the partner portal. An intended URL is
        // attacker-suppliable in principle, and this is the one place it
        // gets turned into a redirect.
        Response::to($intended && str_starts_with((string) $intended, '/partners') ? $intended : '/partners');
    }

    public function logout(Request $request): void
    {
        PartnerAuth::logout();
        Session::flash('success', 'You are signed out.');
        Response::to('/partners/login');
    }

    // -- Applying ----------------------------------------------------------

    public function showApply(Request $request): void
    {
        $this->assertEnabled();

        if (!Settings::bool('partner_signup_enabled', true)) {
            throw new HttpException(404, 'We are not taking new partner applications at the moment.');
        }

        $this->view('partner/apply', [
            'title'    => 'Become a partner',
            'company'  => Settings::company(),
            'terms'    => (string) Settings::get('partner_terms', ''),
            'rate'     => (float) Settings::get('partner_default_rate', 10),
            'authKind' => 'partner',
        ], 'auth');
    }

    public function apply(Request $request): void
    {
        $this->assertEnabled();

        if (!Settings::bool('partner_signup_enabled', true)) {
            throw new HttpException(404, 'We are not taking new partner applications at the moment.');
        }

        $v = new Validator($request->all());
        $v->require('name', 'Your name')
          ->maxLen('name', 140, 'Your name')
          ->maxLen('company', 180, 'Your business')
          ->email('email', 'Email address', true)
          ->phone('phone', 'Phone number', true)
          ->maxLen('kra_pin', 30, 'KRA PIN')
          ->require('pitch', 'Who you sell to');

        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Session::flashInput($request->all());
            Response::to('/partners/apply');
        }

        $email = strtolower(trim((string) $request->input('email')));

        $existing = Database::first('SELECT id, status FROM partners WHERE email = :e', ['e' => $email]);

        // Never say whether the address is already a partner of ours. An
        // application form that answers that question is a way to find out
        // who works with us, one guess at a time. Either way they are told
        // the same thing, and either way somebody looks at it.
        if (!$existing) {
            $partnerId = Database::insert('partners', [
                'name'         => trim((string) $request->input('name')),
                'company'      => trim((string) $request->input('company')) ?: null,
                'email'        => $email,
                'phone'        => trim((string) $request->input('phone')),
                'kra_pin'      => trim((string) $request->input('kra_pin')) ?: null,
                'pitch'        => trim((string) $request->input('pitch')),
                'default_rate' => (float) Settings::get('partner_default_rate', 10),
                'status'       => 'pending',
            ]);

            ActivityLog::record(
                'partner_applied',
                'partner',
                $partnerId,
                'New partner application from ' . $email
            );

            // Whoever decides these needs to know one is waiting.
            $admins = Database::all("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");

            if ($admins) {
                StaffNotifier::notify(
                    array_map(static fn(array $u): int => (int) $u['id'], $admins),
                    [
                        'event'       => 'partner_applied',
                        'title'       => 'A partner has applied',
                        'body'        => trim((string) $request->input('name'))
                                       . ' has asked to become a partner.',
                        'link'        => '/partners-admin/' . $partnerId,
                        'entity_type' => 'partner',
                        'entity_id'   => $partnerId,
                    ],
                    ['email' => true, 'sms' => false]
                );
            }
        }

        Session::flash(
            'success',
            'Thank you. We have your application and will come back to you on '
            . trim((string) $request->input('email')) . '.'
        );

        Response::to('/partners/login');
    }

    // -- Setting or resetting a password -----------------------------------

    public function showStart(Request $request): void
    {
        $this->assertEnabled();

        $this->view('partner/start', [
            'title'    => 'Set up your sign-in',
            'company'  => Settings::company(),
            'authKind' => 'partner',
        ], 'auth');
    }

    public function requestCode(Request $request): void
    {
        $this->assertEnabled();

        $email = strtolower(trim((string) $request->input('email')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flashErrors(['email' => 'Enter the email address you applied with.']);
            Response::to('/partners/start');
        }

        // A code is issued only to a partner we have approved, but the
        // answer is the same either way: telling a stranger that an
        // address is not one of ours is telling them which ones are.
        $partner = Database::first(
            "SELECT id, name, phone FROM partners WHERE email = :e AND status = 'active'",
            ['e' => $email]
        );

        if ($partner) {
            $issued = PartnerOtp::issue($email);

            if ($issued['ok']) {
                Notifier::dispatch('partner_code', [
                    'entity_type'  => 'partner',
                    'entity_id'    => (int) $partner['id'],
                    'contact_name' => $partner['name'],
                    'email'        => $email,
                    'phone'        => $partner['phone'],
                    'code'         => $issued['code'],
                    'minutes'      => PartnerOtp::minutes(),
                ], true);

                Notifier::processQueue(4);
            }
        }

        Session::put('partner_verify_email', $email);
        Response::to('/partners/verify');
    }

    public function showVerify(Request $request): void
    {
        $this->assertEnabled();

        $email = (string) Session::get('partner_verify_email', '');

        if ($email === '') {
            Response::to('/partners/start');
        }

        $this->view('partner/verify', [
            'title'    => 'Enter your code',
            'company'  => Settings::company(),
            'email'    => $email,
            'minutes'  => PartnerOtp::minutes(),
            'authKind' => 'partner',
        ], 'auth');
    }

    public function verify(Request $request): void
    {
        $this->assertEnabled();

        $email = (string) Session::get('partner_verify_email', '');

        if ($email === '') {
            Response::to('/partners/start');
        }

        $code     = trim((string) $request->input('code'));
        $password = (string) $request->input('password');
        $confirm  = (string) $request->input('password_confirm');

        if (strlen($password) < 8) {
            Session::flashErrors(['password' => 'Use at least 8 characters.']);
            Response::to('/partners/verify');
        }

        if ($password !== $confirm) {
            Session::flashErrors(['password_confirm' => 'The two passwords are not the same.']);
            Response::to('/partners/verify');
        }

        if (!PartnerOtp::consume($email, $code)) {
            Session::flashErrors(['code' => 'That code is wrong or has expired.']);
            Response::to('/partners/verify');
        }

        $partner = Database::first(
            "SELECT id FROM partners WHERE email = :e AND status = 'active'",
            ['e' => $email]
        );

        if (!$partner) {
            Session::flash('error', 'That account is not active. Please contact us.');
            Response::to('/partners/login');
        }

        Database::update('partners', [
            'password_hash'   => password_hash($password, PASSWORD_DEFAULT),
            'failed_attempts' => 0,
            'locked_until'    => null,
        ], ['id' => $partner['id']]);

        Session::forget('partner_verify_email');
        PartnerAuth::login((int) $partner['id']);

        Session::flash('success', 'You are all set.');
        Response::to('/partners');
    }
}
