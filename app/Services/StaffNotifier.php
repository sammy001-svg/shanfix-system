<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Telling our own people something happened.
 *
 * Notifier sends outward, to clients. This sends inward: the bell in the
 * top bar, plus email and SMS for the people who need to know while they
 * are away from a screen.
 *
 * One row per recipient rather than one row with a list, so "read" means
 * read by that person — a shared row would be marked read by whoever
 * looked first and disappear for everyone else.
 */
class StaffNotifier
{
    /**
     * Tell a set of people something.
     *
     * @param array<int,int|null> $userIds  Recipients; nulls and duplicates
     *                                      are dropped, as is the person who
     *                                      caused the event
     * @param array{event:string, title:string, body?:string, link?:string,
     *              entity_type?:string, entity_id?:int} $notice
     * @param array{email?:bool, sms?:bool} $channels  Defaults to in-app only
     *
     * @return int how many people were reached
     */
    public static function notify(array $userIds, array $notice, array $channels = []): int
    {
        $recipients = self::resolve($userIds);

        if ($recipients === []) {
            return 0;
        }

        $wantEmail = ($channels['email'] ?? false) && Settings::bool('smtp_enabled');
        $wantSms   = ($channels['sms'] ?? false) && Settings::bool('sms_enabled');

        $sent = 0;

        foreach ($recipients as $user) {
            // The bell always. It costs nothing and it is the one channel
            // that cannot fail in a way the recipient never sees.
            try {
                Database::insert('staff_notifications', [
                    'user_id'     => $user['id'],
                    'event'       => $notice['event'],
                    'title'       => mb_substr($notice['title'], 0, 200),
                    'body'        => isset($notice['body']) ? mb_substr($notice['body'], 0, 500) : null,
                    'link'        => $notice['link'] ?? null,
                    'entity_type' => $notice['entity_type'] ?? null,
                    'entity_id'   => $notice['entity_id'] ?? null,
                ]);

                $sent++;
            } catch (\Throwable $e) {
                Logger::error('Staff notification failed: ' . $e->getMessage(), [
                    'user'  => $user['id'],
                    'event' => $notice['event'],
                ]);
                continue;
            }

            // Email and SMS are queued through the same machinery that
            // carries client messages, so a colleague's message shows up in
            // the message log and retries the same way.
            if ($wantEmail && !empty($user['email'])) {
                self::queue('email', $user, $notice);
            }

            if ($wantSms && !empty($user['phone'])) {
                self::queue('sms', $user, $notice);
            }
        }

        return $sent;
    }

    /**
     * Everyone holding one of these roles.
     *
     * @param array<int,string> $roles
     * @return array<int,int>
     */
    public static function withRole(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($roles), '?'));

        $rows = Database::all(
            "SELECT DISTINCT u.id
               FROM users u
          LEFT JOIN user_roles r ON r.user_id = u.id
              WHERE u.is_active = 1
                AND (u.role IN ({$in}) OR r.role IN ({$in}))",
            array_merge($roles, $roles)
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /** Unread count for the bell. */
    public static function unreadCount(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM staff_notifications WHERE user_id = :id AND read_at IS NULL',
            ['id' => $userId],
            0
        );
    }

    // -- internals -------------------------------------------------------

    /**
     * Turn a list of ids into people worth writing to.
     *
     * The person who caused the event is dropped: telling someone what they
     * just did is noise, and it is the fastest way to make people stop
     * reading the bell.
     *
     * @return array<int,array{id:int, name:string, email:?string, phone:?string}>
     */
    private static function resolve(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $me  = (int) Auth::id();

        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $me));

        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        return Database::all(
            "SELECT id, name, email, phone FROM users
              WHERE id IN ({$in}) AND is_active = 1",
            $ids
        );
    }

    /** Put a staff message on the same outbound queue clients use. */
    private static function queue(string $channel, array $user, array $notice): void
    {
        $body = trim(($notice['body'] ?? '') . "\n\n" . self::absoluteLink($notice));

        try {
            Database::insert('notifications', [
                'channel'        => $channel,
                'event'          => $notice['event'],
                'recipient'      => $channel === 'email'
                    ? $user['email']
                    : (normalize_phone((string) $user['phone']) ?? ''),
                'recipient_name' => $user['name'],
                'subject'        => $channel === 'email' ? mb_substr($notice['title'], 0, 255) : null,
                'body'           => $channel === 'email'
                    ? self::emailBody($notice)
                    : self::smsBody($notice),
                'entity_type'    => $notice['entity_type'] ?? null,
                'entity_id'      => $notice['entity_id'] ?? null,
                'created_by'     => Auth::id(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Staff ' . $channel . ' queue failed: ' . $e->getMessage(), [
                'user' => $user['id'] ?? null,
            ]);
        }

        unset($body);
    }

    /**
     * The SMS text.
     *
     * A text message that says only "Alice messaged you" makes the reader
     * open the app to find out whether it mattered. Carrying a line of the
     * thing itself lets them decide without doing that. The link is
     * reserved out of the 300 characters first so it always survives.
     */
    private static function smsBody(array $notice): string
    {
        $link = self::absoluteLink($notice);
        $text = trim($notice['title']);
        $brief = trim((string) ($notice['body'] ?? ''));

        if ($brief !== '') {
            $text .= ': ' . $brief;
        }

        $room = 300 - ($link === '' ? 0 : mb_strlen($link) + 1);

        if (mb_strlen($text) > $room) {
            $text = rtrim(mb_substr($text, 0, max(0, $room - 1))) . '…';
        }

        return $link === '' ? $text : $text . ' ' . $link;
    }
    private static function absoluteLink(array $notice): string
    {
        return isset($notice['link']) && $notice['link'] !== ''
            ? Notifier::absoluteUrl($notice['link'])
            : '';
    }

    /**
     * A deliberately plain internal email.
     *
     * The client templates are branded because they represent the company
     * to an outsider. This one goes to a colleague who wants the fact and
     * the link, so it stays out of the way.
     */
    private static function emailBody(array $notice): string
    {
        $link = self::absoluteLink($notice);

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#0F1E2E">'
              . '<p style="margin:0 0 10px"><strong>' . e($notice['title']) . '</strong></p>';

        if (!empty($notice['body'])) {
            $html .= '<p style="margin:0 0 14px;color:#5A6B7D">' . nl2br(e($notice['body'])) . '</p>';
        }

        if ($link !== '') {
            $html .= '<p style="margin:0 0 14px">'
                   . '<a href="' . e($link) . '" style="color:#14874E;font-weight:bold">Open it</a>'
                   . '</p>';
        }

        $html .= '<p style="margin:0;font-size:12px;color:#8A97A6">'
               . e(Settings::get('company_name', 'Shanfix Technology'))
               . ' — internal notification</p></div>';

        return $html;
    }
}
