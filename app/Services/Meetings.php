<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * The parts of a meeting that both the staff room and the guest room need.
 *
 * A colleague and an outside client sit in the same room and do the same
 * things — watch a screen, read the notes, add one. Keeping that here means
 * the two entry points differ only in how the person was identified, which
 * is the only way they should differ.
 */
class Meetings
{
    /** How long a signalling row is of any use. */
    private const SIGNAL_TTL_MINUTES = 30;

    public static function joinUrl(string $token): string
    {
        return Notifier::absoluteUrl('/join/' . $token);
    }

    /** @return array<string,mixed> counts for the list page */
    public static function summary(int $userId): array
    {
        return Database::first(
            "SELECT
                COALESCE(SUM(CASE WHEN status IN ('scheduled','in_progress')
                                   AND scheduled_at >= NOW() THEN 1 ELSE 0 END), 0) AS upcoming,
                COALESCE(SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END), 0) AS live,
                COALESCE(SUM(CASE WHEN status IN ('scheduled','in_progress')
                                   AND DATE(scheduled_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today,
                COALESCE(SUM(CASE WHEN status = 'ended' THEN 1 ELSE 0 END), 0) AS held
               FROM meetings"
        ) ?: ['upcoming' => 0, 'live' => 0, 'today' => 0, 'held' => 0];
    }

    public static function participants(int $meetingId): array
    {
        return Database::all(
            "SELECT p.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                    u.avatar_color
               FROM meeting_participants p
          LEFT JOIN users u ON u.id = p.user_id
              WHERE p.meeting_id = :id
           ORDER BY FIELD(p.invite_role,'host','required','optional'), p.name",
            ['id' => $meetingId]
        );
    }

    /** Invite a colleague. Re-inviting somebody already there does nothing. */
    public static function addUser(int $meetingId, int $userId, string $role = 'required'): void
    {
        $user = Database::first('SELECT name, email, phone FROM users WHERE id = :id', ['id' => $userId]);

        if (!$user) {
            return;
        }

        try {
            Database::insert('meeting_participants', [
                'meeting_id'  => $meetingId,
                'user_id'     => $userId,
                'name'        => $user['name'],
                'email'       => $user['email'],
                'phone'       => $user['phone'],
                'invite_role' => $role,
            ]);
        } catch (\Throwable) {
            // Already invited. The unique key is the check; nothing to do.
        }
    }

    /** Invite someone outside the company. */
    public static function addGuest(int $meetingId, string $name, ?string $email, ?string $phone): void
    {
        try {
            Database::insert('meeting_participants', [
                'meeting_id'  => $meetingId,
                'user_id'     => null,
                'name'        => mb_substr($name, 0, 160),
                'email'       => $email,
                'phone'       => $phone ? normalize_phone($phone) : null,
                'invite_role' => 'required',
            ]);
        } catch (\Throwable) {
            // Same address invited twice.
        }
    }

    /**
     * Drop anyone no longer on the list.
     *
     * The host stays regardless: a meeting with no host has nobody who can
     * start it, and removing yourself from the form by accident should not
     * be able to do that.
     */
    public static function pruneMissing(int $meetingId, array $keepUserIds, array $keepEmails): void
    {
        foreach (self::participants($meetingId) as $p) {
            if ($p['invite_role'] === 'host') {
                continue;
            }

            $stillThere = $p['user_id'] !== null
                ? in_array((int) $p['user_id'], $keepUserIds, true)
                : in_array((string) $p['email'], $keepEmails, true);

            if (!$stillThere) {
                Database::delete('meeting_participants', ['id' => $p['id']]);
            }
        }
    }

    /**
     * Record that someone turned up.
     *
     * A guest is identified by their participant row, a colleague by their
     * user id — hence two ways in. COALESCE keeps the first arrival time
     * rather than resetting it each time they refresh the page.
     */
    public static function markJoined(int $meetingId, ?int $userId, ?int $participantId = null): void
    {
        if ($participantId !== null) {
            Database::run(
                "UPDATE meeting_participants
                    SET joined_at = COALESCE(joined_at, NOW()), response = 'accepted'
                  WHERE id = :id",
                ['id' => $participantId]
            );
            return;
        }

        if ($userId === null) {
            return;
        }

        Database::run(
            "UPDATE meeting_participants
                SET joined_at = COALESCE(joined_at, NOW()), response = 'accepted'
              WHERE meeting_id = :m AND user_id = :u",
            ['m' => $meetingId, 'u' => $userId]
        );
    }

    /** @return array notes, newest last, optionally only those after $sinceId */
    public static function notes(int $meetingId, int $sinceId = 0): array
    {
        return Database::all(
            'SELECT id, author_name, body, kind, created_at
               FROM meeting_notes
              WHERE meeting_id = :id AND id > :since
           ORDER BY id ASC',
            ['id' => $meetingId, 'since' => $sinceId]
        );
    }

    /**
     * Add a line to the running record.
     *
     * Returns the stored row so the browser that wrote it can show it at
     * once rather than waiting for the next poll.
     */
    public static function addNote(
        int $meetingId,
        string $body,
        string $author,
        ?int $userId,
        string $kind = 'note'
    ): ?array {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        $id = Database::insert('meeting_notes', [
            'meeting_id'  => $meetingId,
            'user_id'     => $userId,
            'author_name' => mb_substr($author, 0, 160),
            'body'        => mb_substr($body, 0, 2000),
            'kind'        => in_array($kind, ['note', 'decision', 'action'], true) ? $kind : 'note',
        ]);

        return Database::first(
            'SELECT id, author_name, body, kind, created_at FROM meeting_notes WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * How the browsers should try to reach each other.
     *
     * Public STUN is enough when at least one end has a permissive router,
     * which covers most offices and home connections. Where both ends are
     * behind strict NAT the traffic has to be relayed, and that needs a
     * TURN server — configured in Settings, empty until someone provides
     * one. Saying so here rather than silently failing to connect.
     */
    public static function iceServers(): array
    {
        $servers = [
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun1.l.google.com:19302'],
        ];

        $turn = trim((string) Settings::get('webrtc_turn_url', ''));

        if ($turn !== '') {
            $servers[] = [
                'urls'       => $turn,
                'username'   => (string) Settings::get('webrtc_turn_username', ''),
                'credential' => (string) Settings::get('webrtc_turn_password', ''),
            ];
        }

        return $servers;
    }

    /**
     * Accept one signalling message and put it in the postbox.
     *
     * Deliberately dumb: the server does not read or validate the payload,
     * it only carries it between two browsers in the same room. Size is
     * capped so a malformed client cannot fill the table.
     */
    public static function handleSignal(Request $request, int $meetingId): void
    {
        $from = trim((string) $request->input('from', ''));
        $kind = (string) $request->input('kind', '');

        if ($from === '' || !in_array($kind, ['hello', 'offer', 'answer', 'ice', 'bye'], true)) {
            Response::json(['ok' => false, 'error' => 'Bad signal.'], 400);
        }

        Database::insert('meeting_signals', [
            'meeting_id' => $meetingId,
            'from_peer'  => mb_substr($from, 0, 40),
            'to_peer'    => ($to = trim((string) $request->input('to', ''))) !== '' ? mb_substr($to, 0, 40) : null,
            'kind'       => $kind,
            'payload'    => mb_substr((string) $request->input('payload', ''), 0, 60000),
        ]);

        Response::json(['ok' => true]);
    }

    /** Hand a browser everything addressed to it since it last asked. */
    public static function deliverSignals(Request $request, int $meetingId): void
    {
        $peer  = trim((string) $request->query('peer', ''));
        $since = (int) $request->query('since', 0);

        if ($peer === '') {
            Response::json(['ok' => false, 'error' => 'Who is asking?'], 400);
        }

        $rows = Database::all(
            'SELECT id, from_peer, to_peer, kind, payload
               FROM meeting_signals
              WHERE meeting_id = :m
                AND id > :since
                AND from_peer <> :self
                AND (to_peer IS NULL OR to_peer = :peer)
           ORDER BY id ASC
              LIMIT 60',
            ['m' => $meetingId, 'since' => $since, 'self' => $peer, 'peer' => $peer]
        );

        // Cheap housekeeping on the way past: signalling is worthless once
        // it is minutes old, and this saves needing a cron entry for it.
        if (random_int(1, 20) === 1) {
            Database::run(
                'DELETE FROM meeting_signals WHERE created_at < DATE_SUB(NOW(), INTERVAL '
                . self::SIGNAL_TTL_MINUTES . ' MINUTE)'
            );
        }

        Response::json([
            'ok'      => true,
            'signals' => $rows,
            'last'    => $rows ? (int) end($rows)['id'] : $since,
        ]);
    }

    /**
     * Meetings due to start within one of their reminder windows.
     *
     * Matched to the minute: cron runs every few minutes, so a window of
     * ±2 minutes around the mark catches it without firing twice — the
     * lock in the notifier is what actually guarantees once-only.
     *
     * @return array<int,array{meeting:array, minutes:int}>
     */
    public static function dueForReminder(): array
    {
        $fallback = (string) Settings::get('meeting_reminder_mins', '60,30');

        $meetings = Database::all(
            "SELECT * FROM meetings
              WHERE status = 'scheduled'
                AND scheduled_at > NOW()
                AND scheduled_at < DATE_ADD(NOW(), INTERVAL 1 DAY)"
        );

        $due = [];

        foreach ($meetings as $m) {
            $offsets = trim((string) $m['reminder_mins']) !== '' ? $m['reminder_mins'] : $fallback;

            $minutesAway = (int) round((strtotime($m['scheduled_at']) - time()) / 60);

            foreach (explode(',', $offsets) as $offset) {
                $offset = (int) trim($offset);

                if ($offset <= 0) {
                    continue;
                }

                if (abs($minutesAway - $offset) <= 2) {
                    $due[] = ['meeting' => $m, 'minutes' => $offset];
                    break;
                }
            }
        }

        return $due;
    }
}
