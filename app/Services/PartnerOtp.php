<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * One-time codes for setting and resetting a partner's password.
 *
 * The same rules as ClientOtp, for the same reason: a code is a
 * credential while it lives. Hashed so a copy of the database does not
 * hand somebody a working one, single use, counted against, expiring,
 * and rate limited so the endpoint cannot be used to post a hundred
 * emails at a person.
 *
 * Kept separate from ClientOtp rather than sharing a table, because the
 * two grant access to different systems and a code issued for one must
 * never open the other.
 */
class PartnerOtp
{
    private const LENGTH    = 6;
    private const MAX_TRIES = 5;

    public static function minutes(): int
    {
        return max(2, Settings::int('portal_otp_minutes', 10));
    }

    /**
     * Issue a code for an address.
     *
     * Any code still outstanding for the same address is retired first,
     * so asking for a new one does not leave the old one working —
     * people ask again precisely because they think the first failed.
     *
     * @return array{ok:bool, code?:string, error?:string}
     */
    public static function issue(string $email): array
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'That does not look like an email address.'];
        }

        $perHour = max(1, Settings::int('portal_otp_per_hour', 5));

        $recent = (int) Database::scalar(
            'SELECT COUNT(*) FROM partner_otps
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
            'UPDATE partner_otps SET consumed_at = NOW()
              WHERE email = :e AND consumed_at IS NULL',
            ['e' => $email]
        );

        // random_int, not rand: this is a credential.
        $code = str_pad((string) random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);

        Database::insert('partner_otps', [
            'email'      => $email,
            'code_hash'  => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => date('Y-m-d H:i:s', time() + self::minutes() * 60),
        ]);

        return ['ok' => true, 'code' => $code];
    }

    /**
     * Check a code and spend it.
     *
     * Returns false for every kind of failure — wrong, expired, already
     * used, or tried too often. The caller has nothing useful to do with
     * the difference, and neither has anybody guessing.
     */
    public static function consume(string $email, string $code): bool
    {
        $email = strtolower(trim($email));
        $code  = trim($code);

        if ($code === '') {
            return false;
        }

        $row = Database::first(
            'SELECT * FROM partner_otps
              WHERE email = :e AND consumed_at IS NULL AND expires_at > NOW()
           ORDER BY id DESC LIMIT 1',
            ['e' => $email]
        );

        if (!$row) {
            return false;
        }

        // Six digits is an afternoon's work if guessing is free.
        if ((int) $row['attempts'] >= self::MAX_TRIES) {
            Database::update('partner_otps', ['consumed_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);

            return false;
        }

        if (!password_verify($code, (string) $row['code_hash'])) {
            Database::update('partner_otps', [
                'attempts' => (int) $row['attempts'] + 1,
            ], ['id' => $row['id']]);

            return false;
        }

        Database::update('partner_otps', [
            'consumed_at' => date('Y-m-d H:i:s'),
        ], ['id' => $row['id']]);

        return true;
    }

    /** Clear out codes nobody can use any more. */
    public static function prune(): int
    {
        return Database::run(
            'DELETE FROM partner_otps
              WHERE expires_at < DATE_SUB(NOW(), INTERVAL 2 DAY)'
        )->rowCount();
    }
}
