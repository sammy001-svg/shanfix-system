<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/**
 * One-time codes for the client portal.
 *
 * A six-digit code is a credential for as long as it lives, so it is
 * treated like one: hashed at rest, dead after a few minutes, dead after
 * a few wrong guesses, and dead the moment it is used once.
 *
 * Six digits is a million combinations. That sounds like a lot until you
 * remember a script can try a million things over lunch — the attempt
 * limit, not the length, is what makes it safe.
 */
class ClientOtp
{
    private const LENGTH   = 6;
    private const MAX_TRIES = 5;

    public static function minutes(): int
    {
        return max(2, Settings::int('portal_otp_minutes', 10));
    }

    /**
     * Issue a code for an address.
     *
     * Any code still outstanding for the same address and purpose is
     * retired first, so asking for a new one does not leave the old one
     * working — people ask again precisely because they think the first
     * one failed.
     *
     * @return array{ok:bool, code?:string, error?:string}
     */
    public static function issue(string $email, string $purpose = 'verify_email'): array
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'That does not look like an email address.'];
        }

        // A portal that will send a code to any address on demand is a
        // free way to post a hundred emails at somebody.
        $perHour = max(1, Settings::int('portal_otp_per_hour', 5));

        $recent = (int) Database::scalar(
            'SELECT COUNT(*) FROM client_otps
              WHERE email = :e AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            ['e' => $email],
            0
        );

        if ($recent >= $perHour) {
            return [
                'ok'    => false,
                'error' => 'Too many codes have been requested for this address. Please try again in an hour.',
            ];
        }

        Database::run(
            "UPDATE client_otps SET consumed_at = NOW()
              WHERE email = :e AND purpose = :p AND consumed_at IS NULL",
            ['e' => $email, 'p' => $purpose]
        );

        // random_int, not rand: this is a credential.
        $code = str_pad((string) random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);

        Database::insert('client_otps', [
            'email'        => $email,
            'code_hash'    => password_hash($code, PASSWORD_DEFAULT),
            'purpose'      => $purpose,
            'expires_at'   => date('Y-m-d H:i:s', time() + self::minutes() * 60),
            'requested_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return ['ok' => true, 'code' => $code];
    }

    /**
     * Check a code.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function verify(string $email, string $code, string $purpose = 'verify_email'): array
    {
        $email = strtolower(trim($email));
        $code  = preg_replace('/\D/', '', $code);

        $row = Database::first(
            "SELECT * FROM client_otps
              WHERE email = :e AND purpose = :p AND consumed_at IS NULL
           ORDER BY id DESC LIMIT 1",
            ['e' => $email, 'p' => $purpose]
        );

        // One message for every kind of failure. Distinguishing "no code
        // for that address" from "wrong code" tells a stranger which
        // addresses are worth attacking.
        $refuse = ['ok' => false, 'error' => 'That code is wrong or has expired. Ask for a new one.'];

        if (!$row) {
            return $refuse;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            Database::update('client_otps', ['consumed_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);

            return $refuse;
        }

        if ((int) $row['attempts'] >= self::MAX_TRIES) {
            Database::update('client_otps', ['consumed_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);

            return $refuse;
        }

        if ($code === '' || !password_verify($code, $row['code_hash'])) {
            Database::update('client_otps', ['attempts' => (int) $row['attempts'] + 1], ['id' => $row['id']]);

            return $refuse;
        }

        // Used once, and only once.
        Database::update('client_otps', ['consumed_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);

        return ['ok' => true];
    }

    /**
     * Send a code to an address, and to a phone if there is one.
     *
     * Both, where possible: an email can sit in a spam folder for an hour
     * and a text arrives while somebody is still on the page.
     *
     * @return array{sent:int, skipped:array<int,string>}
     */
    public static function send(string $email, string $code, ?string $phone = null): array
    {
        $company = Settings::get('company_name', 'Shanfix Technology');
        $minutes = (string) self::minutes();

        $context = [
            'email'        => $email,
            'phone'        => $phone ?: '',
            'code'         => $code,
            'minutes'      => $minutes,
            'company'      => $company,
            'company_name' => $company,
            'client_name'  => '',
            'contact_name' => '',
            'entity_type'  => 'client_otp',
            'entity_id'    => 0,
        ];

        $channels = ['email'];

        if ($phone !== null && trim($phone) !== '') {
            $channels[] = 'sms';
        }

        try {
            $result = Notifier::dispatch('client_otp', $context, true, $channels);
        } catch (\Throwable $e) {
            Logger::error('Client OTP dispatch failed: ' . $e->getMessage(), ['email' => $email]);

            return ['sent' => 0, 'skipped' => ['The code could not be sent. Please try again.']];
        }

        // Pushed out now rather than left for cron: somebody is sitting on
        // the page waiting for it.
        $queued = Database::all(
            "SELECT id FROM notifications
              WHERE event = 'client_otp' AND status = 'queued'
           ORDER BY id DESC LIMIT 2"
        );

        $sent = 0;

        foreach ($queued as $row) {
            $one  = Notifier::processQueue(1, (int) $row['id']);
            $sent += $one['sent'];
        }

        return ['sent' => $sent, 'skipped' => $result['skipped'] ?? []];
    }

    /** Codes that are spent or stale are of no further use. */
    public static function prune(): int
    {
        return Database::run(
            'DELETE FROM client_otps
              WHERE (consumed_at IS NOT NULL AND consumed_at < DATE_SUB(NOW(), INTERVAL 2 DAY))
                 OR expires_at < DATE_SUB(NOW(), INTERVAL 2 DAY)'
        )->rowCount();
    }
}
