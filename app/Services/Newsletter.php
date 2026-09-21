<?php

namespace App\Services;

use App\Core\Database;

/**
 * The newsletter list: people who asked, from the website footer, to hear
 * from us.
 *
 * Two rules shape everything here.
 *
 * Asking twice is not an error. Somebody who subscribes a second time is
 * told they are subscribed, not "that address is already on our list" —
 * which would tell anybody typing addresses into our footer who else is
 * on it.
 *
 * Unsubscribing is remembered, not deleted. Consent withdrawn under the
 * Data Protection Act has to stay withdrawn; a deleted row is a clean
 * slate that the next import could fill again. The one way back is the
 * person subscribing again themselves, which is them giving consent anew.
 */
final class Newsletter
{
    /**
     * Add somebody to the list.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function subscribe(string $email, string $sourcePage = '', string $ip = ''): array
    {
        $email = mb_strtolower(trim($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        }

        $existing = Database::first(
            'SELECT id, status FROM newsletter_subscribers WHERE email = :e',
            ['e' => $email]
        );

        if ($existing) {
            // They unsubscribed once and are now asking again, which is
            // fresh consent. Already subscribed needs nothing at all.
            if ($existing['status'] === 'unsubscribed') {
                Database::run(
                    "UPDATE newsletter_subscribers
                        SET status = 'subscribed', subscribed_at = NOW(), unsubscribed_at = NULL,
                            source_page = :p
                      WHERE id = :id",
                    ['id' => $existing['id'], 'p' => self::cap($sourcePage, 255)]
                );
            }

            return ['ok' => true];
        }

        $packed = @inet_pton(trim($ip));

        Database::insert('newsletter_subscribers', [
            'email'             => $email,
            'status'            => 'subscribed',
            'source_page'       => self::cap($sourcePage, 255),
            'unsubscribe_token' => bin2hex(random_bytes(20)),
            'ip'                => $packed === false ? null : $packed,
        ]);

        return ['ok' => true];
    }

    /** Whoever holds this token, taken off the list. */
    public static function unsubscribe(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return false;
        }

        return Database::run(
            "UPDATE newsletter_subscribers
                SET status = 'unsubscribed', unsubscribed_at = COALESCE(unsubscribed_at, NOW())
              WHERE unsubscribe_token = :t",
            ['t' => $token]
        )->rowCount() > 0
            || (bool) Database::scalar(
                'SELECT 1 FROM newsletter_subscribers WHERE unsubscribe_token = :t',
                ['t' => $token]
            );
    }

    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }

        return Database::first(
            'SELECT * FROM newsletter_subscribers WHERE unsubscribe_token = :t',
            ['t' => $token]
        );
    }

    /** The address a subscriber can use to leave, for the foot of a mailing. */
    public static function unsubscribeUrl(array $subscriber): string
    {
        return Notifier::absoluteUrl('/newsletter/unsubscribe?t=' . $subscriber['unsubscribe_token']);
    }

    /** @return array{subscribed:int, unsubscribed:int, this_month:int} */
    public static function counts(): array
    {
        $row = Database::first(
            "SELECT SUM(status = 'subscribed')                                           AS subscribed,
                    SUM(status = 'unsubscribed')                                         AS unsubscribed,
                    SUM(status = 'subscribed' AND subscribed_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS this_month
               FROM newsletter_subscribers"
        );

        return [
            'subscribed'   => (int) ($row['subscribed'] ?? 0),
            'unsubscribed' => (int) ($row['unsubscribed'] ?? 0),
            'this_month'   => (int) ($row['this_month'] ?? 0),
        ];
    }

    private static function cap(string $value, int $length): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
