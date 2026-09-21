<?php

namespace App\Services\LiveChat;

use App\Core\Database;
use App\Core\Settings;

/**
 * Live conversations with people on the website.
 *
 * The visitor has no account. Their credential is a long random token
 * their own browser keeps, and only its hash is stored here — the same
 * treatment API keys get, so that reading this table does not let
 * somebody resume other people's conversations.
 *
 * A conversation is 'waiting' until a human replies, then 'open', then
 * 'closed'. The waiting queue is the one that matters: it is the list of
 * people currently sitting on our website looking at a message nobody
 * has answered, and everything in the staff inbox sorts around it.
 */
final class Conversations
{
    /** Longest single message we will take, in characters. */
    public const MAX_BODY = 4000;

    // -----------------------------------------------------------------
    // Starting and finding
    // -----------------------------------------------------------------

    /**
     * Open a conversation and record its first message.
     *
     * @param array{department?:int|string|null, name?:string, email?:string,
     *              phone?:string, message:string, page_url?:string,
     *              page_title?:string, referrer?:string, user_agent?:string,
     *              ip?:string, client_id?:int|null, client_user_id?:int|null,
     *              partner_id?:int|null} $in
     * @return array{ok:bool, token?:string, ref?:string, id?:int, error?:string}
     */
    public static function start(array $in): array
    {
        $body = self::clean($in['message'] ?? '');

        if ($body === '') {
            return ['ok' => false, 'error' => 'Type a message to start.'];
        }

        $department = Departments::route($in['department'] ?? null);

        // No department at all means nobody would ever see this. Better to
        // say so than to accept a message into a void.
        if ($department === null) {
            return ['ok' => false, 'error' => 'Chat is not set up just now. Please use the contact form.'];
        }

        $token = bin2hex(random_bytes(32));

        return Database::transaction(static function () use ($in, $body, $department, $token): array {
            $id = Database::insert('live_conversations', [
                'ref'            => self::newRef(),
                'department_id'  => (int) $department['id'],
                'token_hash'     => hash('sha256', $token),
                'visitor_name'   => self::trim($in['name'] ?? '', 100) ?: null,
                'visitor_email'  => self::email($in['email'] ?? ''),
                'visitor_phone'  => self::trim($in['phone'] ?? '', 30) ?: null,
                'client_id'      => $in['client_id'] ?? null,
                'client_user_id' => $in['client_user_id'] ?? null,
                'partner_id'     => $in['partner_id'] ?? null,
                'page_url'       => self::trim($in['page_url'] ?? '', 255) ?: null,
                'page_title'     => self::trim($in['page_title'] ?? '', 160) ?: null,
                'referrer'       => self::trim($in['referrer'] ?? '', 255) ?: null,
                'user_agent'     => self::trim($in['user_agent'] ?? '', 255) ?: null,
                'ip'             => self::packIp($in['ip'] ?? ''),
                'status'         => 'waiting',
                'last_visitor_at' => date('Y-m-d H:i:s'),
            ]);

            $messageId = Database::insert('live_messages', [
                'conversation_id' => $id,
                'sender'          => 'visitor',
                'sender_name'     => self::trim($in['name'] ?? '', 100) ?: 'Visitor',
                'body'            => $body,
            ]);

            $ref = (string) Database::scalar(
                'SELECT ref FROM live_conversations WHERE id = :id', ['id' => $id]);

            return ['ok' => true, 'id' => $id, 'ref' => $ref, 'token' => $token,
                    'message_id' => $messageId];
        });
    }

    /**
     * The conversation this token opens, or null.
     *
     * Looked up by hash, so the token never has to be compared in PHP and
     * a stolen database is no use for reading conversations.
     */
    public static function byToken(string $token): ?array
    {
        if (strlen($token) < 32) {
            return null;
        }

        return Database::first(
            'SELECT * FROM live_conversations WHERE token_hash = :h',
            ['h' => hash('sha256', $token)]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM live_conversations WHERE id = :id', ['id' => $id]);
    }

    // -----------------------------------------------------------------
    // Talking
    // -----------------------------------------------------------------

    /** The visitor says something. */
    public static function visitorSays(array $conversation, string $message): array
    {
        $body = self::clean($message);

        if ($body === '') {
            return ['ok' => false, 'error' => 'Empty message.'];
        }

        $messageId = Database::insert('live_messages', [
            'conversation_id' => $conversation['id'],
            'sender'          => 'visitor',
            'sender_name'     => $conversation['visitor_name'] ?: 'Visitor',
            'body'            => $body,
        ]);

        // A closed conversation that gets another message is not closed.
        // Re-opening it as 'waiting' rather than 'open' is deliberate: it
        // needs a human again, so it belongs back in the queue.
        Database::run(
            "UPDATE live_conversations
                SET last_visitor_at = NOW(),
                    status = CASE WHEN status = 'closed' THEN 'waiting' ELSE status END,
                    closed_at = CASE WHEN status = 'closed' THEN NULL ELSE closed_at END
              WHERE id = :id",
            ['id' => $conversation['id']]
        );

        // The id goes back so the widget can recognise its own message
        // when it comes round again on the next poll, and not draw it a
        // second time underneath the one it already showed.
        return ['ok' => true, 'id' => $messageId];
    }

    /**
     * Somebody here replies.
     *
     * A note is for the next member of staff and is never sent to the
     * visitor — it does not open the conversation, does not count as a
     * reply, and does not stop the clock on how long they have waited.
     */
    public static function staffSays(
        array $conversation,
        int $userId,
        string $userName,
        string $message,
        bool $isNote = false
    ): array {
        $body = self::clean($message);

        if ($body === '') {
            return ['ok' => false, 'error' => 'Empty message.'];
        }

        Database::insert('live_messages', [
            'conversation_id' => $conversation['id'],
            'sender'          => 'staff',
            'staff_user_id'   => $userId,
            'sender_name'     => $userName,
            'body'            => $body,
            'is_note'         => $isNote ? 1 : 0,
            // Staff have by definition read what they are replying to.
            'read_by_staff_at' => date('Y-m-d H:i:s'),
        ]);

        if ($isNote) {
            return ['ok' => true, 'note' => true];
        }

        // first_reply_at is only ever set once — it is how long this
        // person waited for a human, and a later reply does not change
        // that. COALESCE rather than an if, so two agents replying at the
        // same moment cannot both claim it.
        Database::run(
            "UPDATE live_conversations
                SET last_staff_at   = NOW(),
                    first_reply_at  = COALESCE(first_reply_at, NOW()),
                    status          = 'open',
                    closed_at       = NULL,
                    assigned_user_id = COALESCE(assigned_user_id, :u),
                    assigned_at     = COALESCE(assigned_at, NOW())
              WHERE id = :id",
            ['id' => $conversation['id'], 'u' => $userId]
        );

        return ['ok' => true];
    }

    /** A line from the system itself, which the visitor does see. */
    public static function systemSays(int $conversationId, string $message): void
    {
        Database::insert('live_messages', [
            'conversation_id' => $conversationId,
            'sender'          => 'system',
            'sender_name'     => null,
            'body'            => self::clean($message),
            'read_by_staff_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Everything said since $after.
     *
     * Notes are left out unless the caller is staff, which is the whole
     * point of them.
     */
    public static function messagesSince(int $conversationId, int $after = 0, bool $forStaff = false): array
    {
        return Database::all(
            'SELECT id, sender, sender_name, body, is_note, created_at
               FROM live_messages
              WHERE conversation_id = :c AND id > :after'
            . ($forStaff ? '' : ' AND is_note = 0') . '
              ORDER BY id
              LIMIT 200',
            ['c' => $conversationId, 'after' => $after]
        );
    }

    // -----------------------------------------------------------------
    // Handling
    // -----------------------------------------------------------------

    /**
     * Take a conversation.
     *
     * Conditional on it still being free, so that two people clicking at
     * once cannot both end up believing they own it — the second gets
     * false and can see who won.
     */
    public static function claim(int $conversationId, int $userId): bool
    {
        return Database::run(
            'UPDATE live_conversations
                SET assigned_user_id = :u, assigned_at = NOW()
              WHERE id = :id AND (assigned_user_id IS NULL OR assigned_user_id = :u2)',
            ['id' => $conversationId, 'u' => $userId, 'u2' => $userId]
        )->rowCount() > 0;
    }

    /** Hand it to somebody else, or back to the queue. */
    public static function assign(int $conversationId, ?int $userId): void
    {
        Database::run(
            'UPDATE live_conversations
                SET assigned_user_id = :u,
                    assigned_at = ' . ($userId === null ? 'NULL' : 'NOW()') . '
              WHERE id = :id',
            ['id' => $conversationId, 'u' => $userId]
        );
    }

    /**
     * Move it to another department.
     *
     * The visitor is told, because otherwise the next voice is a stranger
     * with no explanation. Whoever was handling it is released: they are
     * probably not in the new department.
     */
    public static function transfer(int $conversationId, int $departmentId, string $byName): bool
    {
        $to = Departments::find($departmentId);

        if (!$to) {
            return false;
        }

        Database::run(
            'UPDATE live_conversations
                SET department_id = :d, assigned_user_id = NULL, assigned_at = NULL
              WHERE id = :id',
            ['id' => $conversationId, 'd' => $departmentId]
        );

        self::systemSays($conversationId, 'Passed to ' . $to['name'] . '. Somebody there will be with you shortly.');

        return true;
    }

    public static function close(int $conversationId, ?int $userId, string $reason = ''): void
    {
        Database::run(
            "UPDATE live_conversations
                SET status = 'closed', closed_at = NOW(), closed_by = :u, close_reason = :r
              WHERE id = :id",
            [
                'id' => $conversationId,
                'u'  => $userId,
                'r'  => self::trim($reason, 160) ?: null,
            ]
        );
    }

    public static function reopen(int $conversationId): void
    {
        Database::run(
            "UPDATE live_conversations
                SET status = 'open', closed_at = NULL, close_reason = NULL
              WHERE id = :id",
            ['id' => $conversationId]
        );
    }

    /** Mark everything the visitor has said as seen. */
    public static function markReadByStaff(int $conversationId): void
    {
        Database::run(
            "UPDATE live_messages SET read_by_staff_at = NOW()
              WHERE conversation_id = :c AND sender = 'visitor' AND read_by_staff_at IS NULL",
            ['c' => $conversationId]
        );
    }

    /** The visitor rates the conversation. Only ever once. */
    public static function rate(int $conversationId, int $score, string $comment = ''): bool
    {
        if ($score < 1 || $score > 5) {
            return false;
        }

        return Database::run(
            'UPDATE live_conversations SET rating = :s, rating_comment = :c
              WHERE id = :id AND rating IS NULL',
            ['id' => $conversationId, 's' => $score, 'c' => self::trim($comment, 255) ?: null]
        )->rowCount() > 0;
    }

    // -----------------------------------------------------------------
    // Are we here?
    // -----------------------------------------------------------------

    /**
     * Whether somebody is likely to answer right now.
     *
     * Office hours rather than who is logged in: a member of staff with a
     * browser tab open is not a promise, and a visitor told "we are
     * online" who then waits forty minutes is worse off than one who was
     * told the truth and left an e-mail address.
     */
    public static function atTheDesk(): bool
    {
        $days = array_filter(array_map(
            'intval',
            explode(',', (string) Settings::get('livechat_hours_days', '1,2,3,4,5,6'))
        ), static fn($d) => $d >= 0 && $d <= 6);

        if (!in_array((int) date('w'), $days, true)) {
            return false;
        }

        $from = (string) Settings::get('livechat_hours_from', '08:00');
        $to   = (string) Settings::get('livechat_hours_to', '17:30');
        $now  = date('H:i');

        // A window that wraps past midnight is unusual but not wrong.
        return $from <= $to
            ? ($now >= $from && $now <= $to)
            : ($now >= $from || $now <= $to);
    }

    // -----------------------------------------------------------------
    // Housekeeping
    // -----------------------------------------------------------------

    /**
     * A short reference somebody can read down the phone.
     *
     * Digits only after the prefix, and checked for collision rather than
     * assumed unique — the column is unique, so a clash would otherwise
     * surface as a failed insert in front of a visitor.
     */
    private static function newRef(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $ref = 'C' . date('ymd') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            if (!Database::scalar('SELECT 1 FROM live_conversations WHERE ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }

        return 'C' . date('ymdHis');
    }

    /**
     * Trim, cap, and strip control characters that have no business here.
     *
     * The order matters. This used to run the strip with the /u flag
     * first, which looks right and is not: on input that is not valid
     * UTF-8, preg_replace returns null, and null became an empty string
     * — so one malformed byte silently discarded the visitor's entire
     * message and told them it was empty. A stranger typing a question
     * from a phone keyboard is exactly who would hit that.
     *
     * So anything malformed is repaired first, and the strip then runs
     * without /u. That is safe rather than sloppy: in UTF-8 the bytes
     * 00-1F only ever stand for themselves, never as part of a
     * multi-byte character, so matching them bytewise cannot damage one.
     */
    private static function clean(string $body): string
    {
        $body = trim($body);

        if (!mb_check_encoding($body, 'UTF-8')) {
            // Substitutes what it cannot read rather than dropping it, so
            // the rest of the sentence survives.
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        }

        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $body) ?? '';

        return mb_substr($body, 0, self::MAX_BODY);
    }

    private static function trim(string $v, int $len): string
    {
        return mb_substr(trim($v), 0, $len);
    }

    private static function email(string $v): ?string
    {
        $v = trim($v);

        return $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) ? mb_substr($v, 0, 160) : null;
    }

    /**
     * IP as bytes, which holds IPv6 in 16 and IPv4 in 4.
     *
     * Kept for abuse handling only. inet_pton returns false on anything
     * malformed, which becomes null rather than a stored lie.
     */
    private static function packIp(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));

        return $packed === false ? null : $packed;
    }
}
