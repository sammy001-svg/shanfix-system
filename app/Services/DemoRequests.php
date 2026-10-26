<?php
namespace App\Services;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Numbering;
use App\Core\Settings;

/**
 * People asking to see one of the systems we sell.
 *
 * The website takes the request; this decides what happens to it. Kept
 * out of the controller because two different places create one — the
 * public site through the seam, and a member of staff typing in
 * somebody who rang up — and both have to raise the lead and tell the
 * same people.
 *
 * ABOUT THE PASSWORD
 *
 * Accepting a request sends somebody outside the company a working
 * login to a running system. The password is encrypted here with the
 * same key the settings secrets use, so a dump of this table is not a
 * list of live demo logins, and it is never written to the activity
 * log — the log records that a demo was sent, not what was in it.
 *
 * What that does not fix: the message carrying it sits in the
 * notifications queue with the password in the clear in its rendered
 * body, the way every password any system e-mails does. The real answer
 * is to send a link that lets the person set their own, which is a
 * change to the system being demonstrated rather than to this one. It
 * is written down rather than quietly assumed.
 */
class DemoRequests
{
    /**
     * What can be asked for.
     *
     * The name here is what the client is told they are being given a
     * demo of, so it matches the heading on the page they asked from.
     */
    public const PRODUCTS = [
        'sacco'  => 'Sacco Pro',
        'school' => 'School Management',
        'pos'    => 'Point of Sale',
        'erp'    => 'Business ERP',
    ];

    public const STATUSES = [
        'new'      => 'Waiting',
        'accepted' => 'Sent',
        'declined' => 'Declined',
        'expired'  => 'Expired',
    ];

    /**
     * How big they are, in their own units.
     *
     * Not a list. A sacco counts members, a school counts pupils and a
     * shop counts tills, so each page asks its own question and the
     * answer is stored as the words it offered. A fixed list here would
     * have to agree with four forms in four files, for ever, and the
     * first time it did not the size would be silently dropped.
     */
    public const SIZE_MAX = 40;

    /**
     * Take a request in.
     *
     * Raises a lead beside it so it sits in the pipeline like any other
     * enquiry, and tells whoever handles those. Returns the new id, or
     * null if it could not be stored at all — the caller decides what to
     * do about that, and on the website the answer is to keep it in the
     * site's own table rather than lose a sale.
     *
     * @param array<string,mixed> $in
     */
    public static function create(array $in): ?int
    {
        $name  = trim((string) ($in['name'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));

        if ($name === '' || $email === '' || $phone === '') {
            return null;
        }

        $product = (string) ($in['product'] ?? 'sacco');
        $product = isset(self::PRODUCTS[$product]) ? $product : 'sacco';

        try {
            $id = Database::insert('demo_requests', [
                'reference'    => Numbering::next('demo'),
                'product'      => $product,
                'name'         => mb_substr($name, 0, 160),
                'organisation' => mb_substr(trim((string) ($in['organisation'] ?? '')), 0, 180) ?: null,
                'role'         => mb_substr(trim((string) ($in['role'] ?? '')), 0, 120) ?: null,
                'email'        => mb_substr($email, 0, 190),
                'phone'        => mb_substr($phone, 0, 40),
                'members'      => mb_substr(trim((string) ($in['members'] ?? '')), 0, self::SIZE_MAX) ?: null,
                'message'      => trim((string) ($in['message'] ?? '')) ?: null,
                'source_page'  => mb_substr(trim((string) ($in['source_page'] ?? '')), 0, 120) ?: null,
                // Stored packed, which is what VARBINARY(16) is for, and
                // which holds an IPv6 address as readily as an IPv4 one.
                'source_ip'    => self::packIp((string) ($in['ip'] ?? '')),
            ]);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Demo request could not be stored: ' . $e->getMessage());

            return null;
        }

        self::raiseLead($id, $product, $in);
        self::tellSales($id, $product, $name, $in);

        return $id;
    }

    /**
     * Accept one, and send the client their login.
     *
     * @return array{ok:bool, error?:string, queued?:int, skipped?:array<int,string>}
     */
    public static function accept(int $id, array $details): array
    {
        $request = self::find($id);

        if (!$request) {
            return ['ok' => false, 'error' => 'That request is not there.'];
        }

        if ($request['status'] === 'accepted') {
            return ['ok' => false, 'error' => 'That one has already been sent.'];
        }

        $url      = trim((string) ($details['demo_url'] ?? ''));
        $username = trim((string) ($details['demo_username'] ?? ''));
        $password = (string) ($details['demo_password'] ?? '');

        if ($url === '' || $username === '' || $password === '') {
            return ['ok' => false, 'error' => 'The address, the username and the password are all needed.'];
        }

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'That demo address is not a link that opens.'];
        }

        $days    = max(1, min(365, (int) ($details['days'] ?? Settings::int('demo_default_days', 14))));
        $expires = date('Y-m-d', strtotime('+' . $days . ' days'));

        Database::update('demo_requests', [
            'status'        => 'accepted',
            'demo_url'      => mb_substr($url, 0, 255),
            'demo_username' => mb_substr($username, 0, 160),
            // Encrypted at rest. A dump of this table is not a list of
            // live logins.
            'demo_password' => Crypto::encrypt($password),
            'expires_on'    => $expires,
            'decided_by'    => Auth::id(),
            'decided_at'    => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        $result = self::send($id, $request, $url, $username, $password, $expires);

        // Said without the password in it. The log records that a demo
        // went out, not what was in it.
        ActivityLog::record(
            'demo_accepted',
            'demo_request',
            $id,
            'Sent ' . $request['name'] . ' a ' . self::productName($request['product'])
            . ' demo, good until ' . $expires
        );

        return $result;
    }

    public static function decline(int $id, string $reason): array
    {
        $request = self::find($id);

        if (!$request) {
            return ['ok' => false, 'error' => 'That request is not there.'];
        }

        Database::update('demo_requests', [
            'status'         => 'declined',
            'decline_reason' => mb_substr(trim($reason), 0, 400) ?: null,
            'decided_by'     => Auth::id(),
            'decided_at'     => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        ActivityLog::record('demo_declined', 'demo_request', $id,
            'Declined a demo request from ' . $request['name']);

        // The client is told nothing automatically. A demo turned down
        // is a conversation, and a form letter saying no is worse than
        // somebody ringing them.
        return ['ok' => true];
    }

    /**
     * Send the details, by both channels.
     *
     * Forced past the per-event switches: the whole feature is telling
     * them, and an accepted demo the client never hears about is worse
     * than no demo. Whether each channel is actually configured is
     * still checked inside — a missing SMTP server comes back as a
     * skip, not as a lie.
     */
    private static function send(
        int $id,
        array $request,
        string $url,
        string $username,
        string $password,
        string $expires
    ): array {
        $company = Settings::company();

        $result = Notifier::dispatch('demo_ready', [
            'client_name'  => $request['organisation'] ?: $request['name'],
            'contact_name' => $request['name'],
            'email'        => $request['email'],
            'phone'        => $request['phone'],
            'product'      => self::productName($request['product']),
            'link'         => $url,
            'short_link'   => $url,
            'username'     => $username,
            'password'     => $password,
            'expires'      => fdate($expires),
            'reference'    => $request['reference'],
            'company_name' => $company['name'],
            'entity_type'  => 'demo_request',
            'entity_id'    => $id,
        ], true);

        if (($result['queued'] ?? 0) > 0) {
            Database::update('demo_requests', ['sent_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        }

        return [
            'ok'      => ($result['queued'] ?? 0) > 0,
            'queued'  => $result['queued'] ?? 0,
            'skipped' => $result['skipped'] ?? [],
            'error'   => ($result['queued'] ?? 0) > 0
                ? null
                : 'Nothing could be sent: ' . implode(' ', $result['skipped'] ?? ['no channel is set up.']),
        ];
    }

    /**
     * Send the same details again.
     *
     * People lose e-mails. Re-reads the stored password rather than
     * asking for it again, which is the only reason it is kept at all.
     */
    public static function resend(int $id): array
    {
        $request = self::find($id);

        if (!$request || $request['status'] !== 'accepted') {
            return ['ok' => false, 'error' => 'There is nothing to send again.'];
        }

        $password = $request['demo_password'] !== null
            ? Crypto::decrypt($request['demo_password'])
            : null;

        if ($password === null) {
            return ['ok' => false, 'error' =>
                'The stored password could not be read, so it cannot be sent again. '
                . 'Set a new one on the demo and accept it again.'];
        }

        $result = self::send(
            $id,
            $request,
            (string) $request['demo_url'],
            (string) $request['demo_username'],
            $password,
            (string) $request['expires_on']
        );

        ActivityLog::record('demo_resent', 'demo_request', $id,
            'Sent ' . $request['name'] . ' their demo details again');

        return $result;
    }

    /**
     * Mark the ones whose time is up.
     *
     * Ours only — the demo system does its own locking out. This is so
     * the list on the screen says what is still live.
     */
    public static function sweepExpired(): int
    {
        return Database::run(
            "UPDATE demo_requests
                SET status = 'expired'
              WHERE status = 'accepted'
                AND expires_on IS NOT NULL
                AND expires_on < CURDATE()"
        )->rowCount();
    }

    // -- Reading -----------------------------------------------------------

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM demo_requests WHERE id = :id', ['id' => $id]);
    }

    public static function waiting(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM demo_requests WHERE status = 'new'");
    }

    public static function productName(string $key): string
    {
        return self::PRODUCTS[$key] ?? ucfirst($key);
    }

    // -- Internals ---------------------------------------------------------

    /**
     * A demo request is an enquiry, so it becomes one.
     *
     * Without this it would sit in a list of its own that somebody has
     * to remember to open, while the sales pipeline — the screen they
     * actually work from — showed nothing.
     */
    private static function raiseLead(int $id, string $product, array $in): void
    {
        try {
            $requirement = 'Asked to see ' . self::productName($product) . ' on the website.' . "\n\n"
                . (trim((string) ($in['members'] ?? '')) !== '' ? 'Size: ' . $in['members'] . "\n" : '')
                . ($in['role'] ?? '' ? 'Their role: ' . $in['role'] . "\n" : '')
                . (trim((string) ($in['message'] ?? '')) !== ''
                    ? "\n" . trim((string) $in['message']) : '');

            $leadId = Database::insert('leads', [
                'lead_number' => Numbering::next('lead'),
                'name'        => mb_substr((string) $in['name'], 0, 180),
                'company'     => trim((string) ($in['organisation'] ?? '')) ?: null,
                'email'       => trim((string) ($in['email'] ?? '')) ?: null,
                'phone'       => trim((string) ($in['phone'] ?? '')) ?: null,
                'source'      => 'website',
                'requirement' => $requirement,
                'stage'       => 'new',
            ]);

            Database::update('demo_requests', ['lead_id' => $leadId], ['id' => $id]);
        } catch (\Throwable $e) {
            // The request itself is stored; the pipeline entry is a
            // convenience and must not take the request down with it.
            \App\Core\Logger::warning('Demo request lead could not be raised: ' . $e->getMessage());
        }
    }

    private static function tellSales(int $id, string $product, string $name, array $in): void
    {
        try {
            $people = Auth::usersWith('demos.view');

            if ($people === []) {
                return;
            }

            StaffNotifier::notify(array_column($people, 'id'), [
                'event'       => 'demo_request',
                'title'       => 'Somebody wants to see ' . self::productName($product),
                'body'        => $name
                    . (trim((string) ($in['organisation'] ?? '')) !== ''
                        ? ' of ' . $in['organisation'] : '')
                    . ' asked for a demo. They are waiting on a login.',
                'link'        => '/demos/' . $id,
                'entity_type' => 'demo_request',
                'entity_id'   => $id,
            ], ['email' => true, 'sms' => false]);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Could not tell anybody about a demo request: ' . $e->getMessage());
        }
    }

    /** @return string|null packed address, or null if it is not one */
    private static function packIp(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));

        return $packed === false ? null : $packed;
    }
}
