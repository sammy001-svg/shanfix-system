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

        // A preference, not a promise. Staff check it before anything is
        // sent, and it cannot be changed from the portal afterwards —
        // moving where money goes is finance's call, not the account
        // holder's, so an application is the one moment they may say.
        $method = (string) $request->input('pay_method');
        $method = in_array($method, ['mpesa', 'bank'], true) ? $method : null;

        $v = new Validator($request->all());
        $v->require('first_name', 'First name')
          ->maxLen('first_name', 60, 'First name')
          ->maxLen('middle_name', 60, 'Second name')
          ->require('last_name', 'Third name')
          ->maxLen('last_name', 60, 'Third name')
          ->require('id_number', 'ID number')
          ->maxLen('id_number', 30, 'ID number')
          ->require('occupation', 'What you do')
          ->maxLen('occupation', 120, 'What you do')
          ->maxLen('company', 180, 'Your business')
          ->email('email', 'Email address', true)
          ->phone('phone', 'Phone number', true)
          ->require('office_location', 'Where you work from')
          ->maxLen('office_location', 200, 'Where you work from')
          ->maxLen('kra_pin', 30, 'KRA PIN')
          ->maxLen('pay_phone', 30, 'M-Pesa number')
          ->maxLen('bank_name', 120, 'Bank')
          ->maxLen('bank_branch', 120, 'Branch')
          ->maxLen('bank_account_name', 160, 'Account name')
          ->maxLen('bank_account_no', 40, 'Account number')
          ->require('pitch', 'Who you sell to')
          // Choosing a method and giving nothing to send money to is
          // worse than choosing nothing at all: it looks answered, and
          // only fails on payment day. Leaving it until later is a
          // perfectly good answer, and the form says so.
          ->custom(
              'bank_account_no',
              $method !== 'bank' || trim((string) $request->input('bank_account_no')) !== '',
              'Give the account number, or choose "tell us later".'
          );

        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Session::flashInput($request->all());
            Response::to('/partners/apply');
        }

        $email = strtolower(trim((string) $request->input('email')));
        $idNo  = trim((string) $request->input('id_number'));

        // An ID is one person, so a second application under one is the
        // same applicant again. Matched here rather than by a unique
        // index: a duplicate key would raise a database error on a public
        // form, and an error that appears only for real ID numbers is a
        // way to test whether one is on file.
        $existing = Database::first(
            'SELECT id FROM partners WHERE email = :e OR id_number = :i',
            ['e' => $email, 'i' => $idNo]
        );

        // Never say whether the address is already a partner of ours. An
        // application form that answers that question is a way to find out
        // who works with us, one guess at a time. Either way they are told
        // the same thing, and either way somebody looks at it.
        if (!$existing) {
            // Names are collected in three parts because that is how they
            // are written on an ID, which is what they have to match when
            // money moves. `name` is what everything downstream reads.
            $name = full_name(
                (string) $request->input('first_name'),
                (string) $request->input('middle_name'),
                (string) $request->input('last_name')
            );

            $partnerId = Database::insert('partners', [
                'first_name'      => trim((string) $request->input('first_name')),
                'middle_name'     => trim((string) $request->input('middle_name')) ?: null,
                'last_name'       => trim((string) $request->input('last_name')),
                'name'            => $name,
                'company'         => trim((string) $request->input('company')) ?: null,
                'occupation'      => trim((string) $request->input('occupation')),
                'email'           => $email,
                'phone'           => trim((string) $request->input('phone')),
                'kra_pin'         => trim((string) $request->input('kra_pin')) ?: null,
                'id_number'       => $idNo,
                'office_location' => trim((string) $request->input('office_location')),
                'pitch'           => trim((string) $request->input('pitch')),

                'pay_method'        => $method,
                'pay_phone'         => trim((string) $request->input('pay_phone')) ?: null,
                'bank_name'         => trim((string) $request->input('bank_name')) ?: null,
                'bank_branch'       => trim((string) $request->input('bank_branch')) ?: null,
                'bank_account_name' => trim((string) $request->input('bank_account_name')) ?: null,
                'bank_account_no'   => trim((string) $request->input('bank_account_no')) ?: null,

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
            // users has no 'status' column — it has is_active — so this
            // threw on every single application, after the row had already
            // been written. The applicant saw an error page having been
            // saved, and no administrator was ever told.
            //
            // Both places a role can live are checked, because Auth counts
            // the primary role even when the join table has missed it, and
            // an admin who is only an admin in one of them is still the
            // person who has to decide this.
            $admins = Database::all(
                "SELECT DISTINCT u.id
                   FROM users u
              LEFT JOIN user_roles ur ON ur.user_id = u.id
                  WHERE u.is_active = 1
                    AND (u.role = 'admin' OR ur.role = 'admin')"
            );

            if ($admins) {
                StaffNotifier::notify(
                    array_map(static fn(array $u): int => (int) $u['id'], $admins),
                    [
                        'event'       => 'partner_applied',
                        'title'       => 'A partner has applied',
                        'body'        => $name . ' has asked to become a partner.',
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
