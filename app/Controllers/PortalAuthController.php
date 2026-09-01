<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\ClientAuth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Numbering;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\ClientOtp;
use App\Services\StaffNotifier;

/**
 * Getting in to the client portal.
 *
 * A client can be in one of three states, and there is a way in for each:
 *
 *   new to us            they register, and a client record is created
 *   on file, with email  a code to that address proves it is them
 *   on file, no email    they ask, and an administrator vouches for them
 *
 * Throughout, the portal never confirms whether an address or a name is
 * on file. "We have sent a code if that address is on our records" is the
 * same sentence whether or not it is, because the alternative lets a
 * stranger map the client list one guess at a time.
 */
class PortalAuthController extends Controller
{
    private function assertEnabled(): void
    {
        if (!Settings::bool('portal_enabled', true)) {
            throw new HttpException(404, 'The client portal is not available.');
        }
    }

    // -- Signing in --------------------------------------------------------

    public function showLogin(Request $request): void
    {
        $this->assertEnabled();

        if (ClientAuth::check()) {
            Response::to('/portal');
        }

        $this->view('portal/login', [
            'title'      => 'Client sign in',
            'company'    => Settings::company(),
            'selfSignup' => Settings::bool('portal_self_signup', true),
            'authKind'   => 'client',
        ], 'auth');
    }

    public function login(Request $request): void
    {
        $this->assertEnabled();

        $email    = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        $result = ClientAuth::attempt($email, $password);

        if (!$result['ok']) {
            Session::error($result['message'] ?? 'Sign in failed.');
            Session::flashInput(['email' => $email]);
            Response::to('/portal/login');
        }

        ClientAuth::login((int) $result['user']['id']);
        Response::to('/portal');
    }

    public function logout(Request $request): void
    {
        ClientAuth::logout();
        Session::success('You have been signed out.');
        Response::to('/portal/login');
    }

    // -- Starting an account -----------------------------------------------

    public function showStart(Request $request): void
    {
        $this->assertEnabled();

        $this->view('portal/start', [
            'title'      => 'Get access to your account',
            'company'    => Settings::company(),
            'selfSignup' => Settings::bool('portal_self_signup', true),
            'authKind'   => 'client',
        ], 'auth');
    }

    /**
     * Step one, for anybody with an email address.
     *
     * Whether they are on file or brand new, the same thing happens: a
     * code goes to the address. What differs is only what the code
     * unlocks, and that is decided later, at verification.
     */
    public function requestCode(Request $request): void
    {
        $this->assertEnabled();

        $email = strtolower(trim((string) $request->input('email')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::error('Please give a valid email address.');
            Response::to('/portal/start');
        }

        // An address that already has an account is deliberately not told
        // so. "You already have an account" is a plain answer to "is this
        // address registered with you?", which is exactly the question a
        // stranger is asking. It gets a code like everybody else, and the
        // code sets a new password — so the same door serves signing up
        // and having forgotten one.
        $onFile = Database::first(
            'SELECT id, name, phone FROM clients WHERE LOWER(email) = :e AND status = "active" LIMIT 1',
            ['e' => $email]
        );

        // Somebody we have never heard of, with self-signup switched off,
        // is told the same thing as everybody else. Saying "we do not know
        // you" would confirm which addresses are on file.
        $mayProceed = $onFile !== null || Settings::bool('portal_self_signup', true);

        if ($mayProceed) {
            $issued = ClientOtp::issue($email, 'verify_email');

            if (!$issued['ok']) {
                Session::error($issued['error']);
                Response::to('/portal/start');
            }

            ClientOtp::send($email, $issued['code'], $onFile['phone'] ?? null);
        }

        Session::put('portal_pending_email', $email);
        Session::success('If we can reach that address, a code is on its way. It is good for '
            . ClientOtp::minutes() . ' minutes.');

        Response::to('/portal/verify');
    }

    public function showVerify(Request $request): void
    {
        $this->assertEnabled();

        $email = (string) Session::get('portal_pending_email', '');

        if ($email === '') {
            Response::to('/portal/start');
        }

        $this->view('portal/verify', [
            'title'    => 'Enter your code',
            'company'  => Settings::company(),
            'email'    => $email,
            'minutes'  => ClientOtp::minutes(),
            'authKind' => 'client',
        ], 'auth');
    }

    /** Step two: the code, and the password they want. */
    public function verify(Request $request): void
    {
        $this->assertEnabled();

        $email = (string) Session::get('portal_pending_email', '');

        if ($email === '') {
            Response::to('/portal/start');
        }

        $code    = (string) $request->input('code');
        $pass    = (string) $request->input('password');
        $confirm = (string) $request->input('password_confirm');
        $name    = trim((string) $request->input('name'));

        $checked = ClientOtp::verify($email, $code, 'verify_email');

        if (!$checked['ok']) {
            Session::error($checked['error']);
            Response::to('/portal/verify');
        }

        if (mb_strlen($pass) < 8) {
            Session::error('Choose a password of at least 8 characters.');
            Response::to('/portal/verify');
        }

        if ($pass !== $confirm) {
            Session::error('The two passwords do not match.');
            Response::to('/portal/verify');
        }

        // The code proved the address. Now decide what it unlocks: an
        // existing client record, or a new one.
        $client = Database::first(
            'SELECT * FROM clients WHERE LOWER(email) = :e AND status = "active" LIMIT 1',
            ['e' => $email]
        );

        $clientId = $client['id'] ?? null;

        if ($clientId === null) {
            if (!Settings::bool('portal_self_signup', true)) {
                Session::error('We could not match that address to an account. Please contact us.');
                Response::to('/portal/start');
            }

            if ($name === '') {
                Session::error('Please give your name.');
                Response::to('/portal/verify');
            }

            $clientId = Database::insert('clients', [
                'client_code' => Numbering::next('client'),
                'client_type' => 'individual',
                'name'        => $name,
                'email'       => $email,
                'status'      => 'active',
                'notes'       => 'Registered through the client portal.',
            ]);

            ActivityLog::record('client_created', 'client', $clientId, $name . ' registered through the portal');
        }

        // Whether this is a first password or a replacement for a forgotten
        // one, the code proved the address and the same write serves both.
        $account = Database::first('SELECT * FROM client_users WHERE email = :e LIMIT 1', ['e' => $email]);

        $fields = [
            'client_id'         => $clientId,
            'name'              => $name !== '' ? $name : ($client['contact_person'] ?? $client['name'] ?? $email),
            'email'             => $email,
            'phone'             => $client['phone'] ?? null,
            'password_hash'     => password_hash($pass, PASSWORD_DEFAULT),
            'status'            => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
            'failed_attempts'   => 0,
            'locked_until'      => null,
        ];

        if ($account) {
            Database::update('client_users', $fields, ['id' => $account['id']]);
            $accountId = (int) $account['id'];
        } else {
            $accountId = Database::insert('client_users', $fields);
        }

        Session::forget('portal_pending_email');
        ClientAuth::login($accountId);

        Session::success('You are all set. Welcome.');
        Response::to('/portal');
    }

    // -- No email on file --------------------------------------------------

    public function showRequestAccess(Request $request): void
    {
        $this->assertEnabled();

        $this->view('portal/request-access', [
            'title'    => 'Ask us for access',
            'company'  => Settings::company(),
            'authKind' => 'client',
        ], 'auth');
    }

    /**
     * A client on file whose email we do not have.
     *
     * Nothing is checked here and nothing is confirmed back. The match
     * against the client record happens when an administrator approves
     * it — checking at this point, and saying so, would let a stranger
     * test names and numbers until one landed.
     */
    public function requestAccess(Request $request): void
    {
        $this->assertEnabled();

        $name  = trim((string) $request->input('full_name'));
        $phone = trim((string) $request->input('phone'));
        $email = strtolower(trim((string) $request->input('email')));

        if ($name === '' || $phone === '') {
            Session::error('Please give both your full name and the phone number we have for you.');
            Response::to('/portal/request-access');
        }

        // One pending request per number is enough. Somebody submitting
        // repeatedly is either impatient or probing, and neither is worth
        // a queue of rows.
        $already = Database::first(
            "SELECT id FROM client_access_requests
              WHERE phone = :p AND status = 'pending' LIMIT 1",
            ['p' => $phone]
        );

        if (!$already) {
            $id = Database::insert('client_access_requests', [
                'full_name'    => mb_substr($name, 0, 140),
                'phone'        => mb_substr($phone, 0, 30),
                'email'        => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'note'         => mb_substr(trim((string) $request->input('note')), 0, 255) ?: null,
                'requested_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            StaffNotifier::notify(
                StaffNotifier::withRole(['admin']),
                [
                    'event'       => 'portal_access_request',
                    'title'       => 'Someone is asking for portal access',
                    'body'        => $name . ' (' . $phone . ') has asked for access to the client portal.',
                    'link'        => '/portal-requests',
                    'entity_type' => 'client_access_request',
                    'entity_id'   => $id,
                ],
                ['email' => true, 'sms' => false]
            );
        }

        Session::success(
            'Thank you. We will check our records and text you on ' . $phone
            . ' once your access is ready. This usually takes a working day.'
        );

        Response::to('/portal/login');
    }
}
