<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/**
  * One-time password (OTP) service for staff/system user login.
  *
  * Requires verification of a 6-digit OTP code sent via Email and SMS
  * after valid email/password login credentials are provided.
  */
class UserOtp
{
    private const LENGTH    = 6;
    private const MAX_TRIES = 5;

    public static function minutes(): int
    {
        return max(2, Settings::int('user_otp_minutes', 10));
    }

    /**
     * Issue a new 6-digit OTP code for a user.
     *
     * @return array{ok:bool, code?:string, error?:string}
     */
    public static function issue(int $userId, string $email): array
    {
        $email = strtolower(trim($email));

        $perHour = max(1, Settings::int('user_otp_per_hour', 10));

        $recent = (int) Database::scalar(
            'SELECT COUNT(*) FROM user_otps
              WHERE user_id = :uid AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            ['uid' => $userId],
            0
        );

        if ($recent >= $perHour) {
            return [
                'ok'    => false,
                'error' => 'Too many login verification codes requested. Please wait before trying again.',
            ];
        }

        // Retire any previous unconsumed codes for this user
        Database::run(
            'UPDATE user_otps SET consumed_at = NOW()
              WHERE user_id = :uid AND consumed_at IS NULL',
            ['uid' => $userId]
        );

        // Generate 6-digit code using secure random integer
        $code = str_pad((string) random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);

        Database::insert('user_otps', [
            'user_id'      => $userId,
            'email'        => $email,
            'code_hash'    => password_hash($code, PASSWORD_DEFAULT),
            'expires_at'   => date('Y-m-d H:i:s', time() + self::minutes() * 60),
            'requested_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return ['ok' => true, 'code' => $code];
    }

    /**
     * Verify an OTP code for a user.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function verify(int $userId, string $code): array
    {
        $code = preg_replace('/\D/', '', trim($code));

        $row = Database::first(
            'SELECT * FROM user_otps
              WHERE user_id = :uid AND consumed_at IS NULL
           ORDER BY id DESC LIMIT 1',
            ['uid' => $userId]
        );

        $refuse = ['ok' => false, 'error' => 'That code is wrong or has expired. Please ask for a new one.'];

        if (!$row) {
            return $refuse;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            Database::update('user_otps', ['consumed_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);

            return $refuse;
        }

        if ((int) $row['attempts'] >= self::MAX_TRIES) {
            Database::update('user_otps', ['consumed_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);

            return $refuse;
        }

        if ($code === '' || !password_verify($code, (string) $row['code_hash'])) {
            Database::update('user_otps', ['attempts' => (int) $row['attempts'] + 1], ['id' => $row['id']]);

            return $refuse;
        }

        // Spend the code (single use)
        Database::update('user_otps', ['consumed_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);

        return ['ok' => true];
    }

    /**
     * Send the OTP code to user's Email and SMS.
     *
     * @return array{sent:int, errors:array<int,string>}
     */
    public static function send(array $user, string $code): array
    {
        $company = Settings::get('company_name', 'Shanfix Technology');
        $minutes = (string) self::minutes();
        $email   = trim((string) ($user['email'] ?? ''));
        $phone   = trim((string) ($user['phone'] ?? ''));
        $sent    = 0;
        $errors  = [];

        $smsText = "{$company}: Your login verification code is {$code}. It expires in {$minutes} minutes. Do not share it with anyone.";

        // 1. Send via Email
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $mailer = new Mailer();
                if ($mailer->isConfigured()) {
                    $subject = "Your {$company} Login Verification Code";
                    $name    = $user['name'] ?? 'User';
                    $body    = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#0F1E2E">'
                             . '<p style="margin:0 0 10px">Hello <strong>' . e($name) . '</strong>,</p>'
                             . '<p style="margin:0 0 14px">Your security OTP verification code for sign-in is:</p>'
                             . '<div style="font-size:26px;font-weight:bold;letter-spacing:6px;color:#14874E;padding:14px 24px;background:#f4f6f8;display:inline-block;border-radius:8px;margin:10px 0;border:1px solid #e1e8ed;">' . e($code) . '</div>'
                             . '<p style="margin:14px 0 0;font-size:13px;color:#5A6B7D">This code is good for ' . $minutes . ' minutes. If you did not request this, please secure your account.</p>'
                             . '<p style="margin:20px 0 0;font-size:12px;color:#8A97A6">&copy; ' . date('Y') . ' ' . e($company) . ' Security</p>'
                             . '</div>';

                    $res = $mailer->send($email, $subject, $body, $name);
                    if ($res['ok']) {
                        $sent++;
                    } else {
                        $errors[] = 'Email: ' . ($res['error'] ?? 'Could not send email');
                    }
                }
            } catch (\Throwable $e) {
                Logger::error('User OTP Email dispatch error: ' . $e->getMessage(), ['user_id' => $user['id'] ?? null]);
                $errors[] = 'Email error: ' . $e->getMessage();
            }
        }

        // 2. Send via SMS
        if ($phone !== '') {
            try {
                $sms = new Sms();
                if ($sms->isConfigured()) {
                    $res = $sms->send($phone, $smsText);
                    if ($res['ok']) {
                        $sent++;
                    } else {
                        $errors[] = 'SMS: ' . ($res['error'] ?? 'Could not send SMS');
                    }
                }
            } catch (\Throwable $e) {
                Logger::error('User OTP SMS dispatch error: ' . $e->getMessage(), ['user_id' => $user['id'] ?? null]);
                $errors[] = 'SMS error: ' . $e->getMessage();
            }
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    /** Clear stale OTP records */
    public static function prune(): int
    {
        return Database::run(
            'DELETE FROM user_otps
              WHERE (consumed_at IS NOT NULL AND consumed_at < DATE_SUB(NOW(), INTERVAL 2 DAY))
                 OR expires_at < DATE_SUB(NOW(), INTERVAL 2 DAY)'
        )->rowCount();
    }
}
