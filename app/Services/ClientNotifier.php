<?php
namespace App\Services;

use App\Core\Database;

/**
 * Writes portal notification records and reads unread counts for the bell badge.
 *
 * Intentionally thin. The portal layout calls unreadCount() on every page;
 * keeping it to a single indexed query is what makes it cheap enough to do
 * that without a cache.
 *
 * Call notify() from anywhere that changes something a client should know about:
 * job stage changes, invoice issued, payment received, proof available. The link
 * should be a relative portal URL so the client lands on the right page.
 */
class ClientNotifier
{
    /**
     * Write a notification row for this client.
     *
     * @param int    $clientId The client (not client_user) id
     * @param string $event    Machine name, e.g. 'job_stage_changed'
     * @param string $title    One-line headline shown in the feed
     * @param string $body     Short supporting sentence (optional)
     * @param string $link     Relative portal URL, e.g. '/portal/jobs/4'
     */
    public static function notify(
        int    $clientId,
        string $event,
        string $title,
        string $body = '',
        string $link = ''
    ): void {
        Database::insert('portal_notifications', [
            'client_id' => $clientId,
            'event'     => mb_substr($event, 0, 60),
            'title'     => mb_substr($title, 0, 200),
            'body'      => $body !== '' ? mb_substr($body, 0, 500) : null,
            'link'      => $link !== '' ? mb_substr($link, 0, 255) : null,
        ]);
    }

    /**
     * How many unread notifications this client has.
     *
     * Used by the portal layout to paint the bell badge. Returns 0 when
     * the tables do not exist yet (before migration 044 is applied).
     */
    public static function unreadCount(int $clientId): int
    {
        try {
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM portal_notifications
                  WHERE client_id = :c AND read_at IS NULL',
                ['c' => $clientId],
                0
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * How many unread staff messages this client has.
     *
     * Separate from notifications — messages are replies from staff,
     * shown as a badge on the Support nav link.
     */
    public static function unreadMessages(int $clientId): int
    {
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM portal_messages
                  WHERE client_id = :c AND sender = 'staff' AND read_at IS NULL",
                ['c' => $clientId],
                0
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Mark all notifications for this client as read. */
    public static function markAllRead(int $clientId): void
    {
        Database::query(
            'UPDATE portal_notifications SET read_at = NOW()
              WHERE client_id = :c AND read_at IS NULL',
            ['c' => $clientId]
        );
    }

    /** Mark all staff messages for this client as read (client opened the thread). */
    public static function markMessagesRead(int $clientId): void
    {
        Database::query(
            "UPDATE portal_messages SET read_at = NOW()
              WHERE client_id = :c AND sender = 'staff' AND read_at IS NULL",
            ['c' => $clientId]
        );
    }
}
