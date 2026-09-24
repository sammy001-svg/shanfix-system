<?php

namespace App\Services\LiveChat;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Services\Mailer;
use App\Services\StaffNotifier;

/**
 * Telling somebody that a visitor is waiting.
 *
 * A chat system nobody is told about is a contact form that loses its
 * messages. The website used to run tawk.to, which shouted at a phone;
 * when that came off, the only remaining signal was a badge in the
 * sidebar — visible to whoever happened to have a system page open, and
 * to nobody else. This is the part that taps somebody on the shoulder.
 *
 * Two moments, deliberately unalike:
 *
 *   arrived()  — the instant a conversation starts. Everybody in the
 *                department gets the bell, and the desk makes a noise.
 *                Nothing is e-mailed. A question that has been waiting
 *                four seconds does not warrant five e-mails, and a
 *                mailbox full of them is a mailbox nobody reads.
 *
 *   sweep()    — run from cron. Anything still unanswered after
 *                livechat_alert_after minutes is somebody's problem now,
 *                so the department's leads are e-mailed, and texted if
 *                the office has asked for that. Once per conversation:
 *                a second warning about the same chat teaches people to
 *                ignore the first.
 *
 * Both are written so that failing to raise an alarm can never lose the
 * conversation itself. The visitor's message is already committed when
 * these run, and every path here swallows its own exceptions — a broken
 * SMTP server must not turn into a stranger being told their message
 * would not send.
 */
final class Alerts
{
    /**
     * Longest we will look back when sweeping, in hours.
     *
     * A conversation that has been waiting since last Tuesday is not an
     * emergency, it is a mess somebody should clear up in the inbox.
     * Without this, turning the cron on after a quiet fortnight would
     * e-mail the leads about every chat anybody ever missed.
     */
    private const SWEEP_WINDOW_HOURS = 24;

    // -----------------------------------------------------------------
    // Somebody has just started a conversation
    // -----------------------------------------------------------------

    /**
     * Ring the bell for everybody in the department.
     *
     * The bell only. See the class note for why nothing is mailed here.
     *
     * @return int how many people were told
     */
    public static function arrived(int $conversationId): int
    {
        try {
            $conversation = Database::first(
                'SELECT c.id, c.ref, c.department_id, c.visitor_name, c.page_title,
                        d.name AS department
                   FROM live_conversations c
              LEFT JOIN live_departments d ON d.id = c.department_id
                  WHERE c.id = :id',
                ['id' => $conversationId]
            );

            if (!$conversation) {
                return 0;
            }

            $staff = self::departmentStaff((int) $conversation['department_id']);

            if ($staff === []) {
                // Nobody is in the department at all. There is no bell to
                // ring, so this is written down instead — it is a setup
                // mistake somebody has to fix, and the sweep will mail
                // the fallback address in a few minutes.
                Logger::warning('Live chat: nobody is in this department, so nobody was told.', [
                    'conversation' => $conversation['ref'],
                    'department'   => $conversation['department'],
                ]);

                return 0;
            }

            $said = self::latestLine($conversationId);

            return StaffNotifier::notify($staff, [
                'event'       => 'livechat_waiting',
                'title'       => self::who($conversation) . ' is waiting in ' . ($conversation['department'] ?? 'live chat'),
                'body'        => $said,
                'link'        => '/livechat/' . $conversationId,
                'entity_type' => 'live_conversation',
                'entity_id'   => $conversationId,
            ]);
        } catch (\Throwable $e) {
            // The visitor's message is already saved. Whatever went wrong
            // here, they must still be told their message arrived.
            Logger::error('Live chat alert failed: ' . $e->getMessage(), ['conversation' => $conversationId]);

            return 0;
        }
    }

    // -----------------------------------------------------------------
    // Nobody has picked it up
    // -----------------------------------------------------------------

    /**
     * Warn the leads about conversations left waiting too long.
     *
     * @return array{checked:int, escalated:int, notes:list<string>}
     */
    public static function sweep(): array
    {
        $minutes = max(1, (int) Settings::get('livechat_alert_after', 5));

        $stale = Database::all(
            "SELECT c.id, c.ref, c.department_id, c.visitor_name, c.visitor_email,
                    c.page_title, c.created_at,
                    d.name AS department, d.fallback_email,
                    TIMESTAMPDIFF(MINUTE, c.created_at, NOW()) AS waited
               FROM live_conversations c
          LEFT JOIN live_departments d ON d.id = c.department_id
              WHERE c.status = 'waiting'
                AND c.escalated_at IS NULL
                AND c.created_at <= DATE_SUB(NOW(), INTERVAL :m MINUTE)
                AND c.created_at >  DATE_SUB(NOW(), INTERVAL :w HOUR)
           ORDER BY c.created_at
              LIMIT 50",
            ['m' => $minutes, 'w' => self::SWEEP_WINDOW_HOURS]
        );

        $out = ['checked' => count($stale), 'escalated' => 0, 'notes' => []];

        foreach ($stale as $conversation) {
            try {
                $note = self::escalate($conversation);

                // Stamped whatever happened, including when there was
                // nobody to tell. Leaving it null would mean trying the
                // same hopeless conversation again every five minutes
                // for a day.
                Database::run(
                    'UPDATE live_conversations SET escalated_at = NOW() WHERE id = :id',
                    ['id' => $conversation['id']]
                );

                $out['escalated']++;

                if ($note !== '') {
                    $out['notes'][] = $note;
                }
            } catch (\Throwable $e) {
                // Left unstamped on purpose: this one genuinely failed
                // and deserves another try on the next run.
                $out['notes'][] = 'Could not escalate ' . $conversation['ref'] . ': ' . $e->getMessage();
                Logger::error('Live chat escalation failed: ' . $e->getMessage(), [
                    'conversation' => $conversation['ref'],
                ]);
            }
        }

        return $out;
    }

    /**
     * Warn whoever is responsible for one conversation.
     *
     * @return string a line worth printing in the cron log, or ''
     */
    private static function escalate(array $conversation): string
    {
        $departmentId = (int) $conversation['department_id'];
        $leads        = $departmentId > 0 ? Departments::escalateTo($departmentId) : [];

        $waited  = (int) $conversation['waited'];
        $title   = self::who($conversation) . ' has waited ' . $waited
                 . ' minute' . ($waited === 1 ? '' : 's') . ' in '
                 . ($conversation['department'] ?? 'live chat');
        $body    = self::latestLine((int) $conversation['id']);

        if ($leads === []) {
            // No lead, no staff, possibly no department. The department's
            // own fallback address exists for exactly this.
            return self::toFallback($conversation, $title, $body);
        }

        StaffNotifier::notify(
            array_map(static fn(array $u): int => (int) $u['id'], $leads),
            [
                'event'       => 'livechat_overdue',
                'title'       => $title,
                'body'        => $body,
                'link'        => '/livechat/' . $conversation['id'],
                'entity_type' => 'live_conversation',
                'entity_id'   => (int) $conversation['id'],
            ],
            [
                'email' => Settings::bool('livechat_alert_email', true),
                'sms'   => Settings::bool('livechat_alert_sms', false),
            ]
        );

        return $conversation['ref'] . ': told ' . count($leads)
             . ' ' . (count($leads) === 1 ? 'person' : 'people')
             . ' after ' . $waited . ' min';
    }

    /**
     * Nobody in the department, so write to the address set up for it.
     *
     * Sent directly rather than queued: this is the path taken when the
     * ordinary path has already failed, and putting it on a queue that
     * also needs somebody to be watching would be the same mistake
     * twice.
     */
    private static function toFallback(array $conversation, string $title, string $body): string
    {
        $to = trim((string) ($conversation['fallback_email'] ?? ''))
           ?: trim((string) Settings::get('company_email', ''));

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $conversation['ref'] . ': nobody to tell — no staff in the department'
                 . ' and no fallback address';
        }

        if (!Settings::bool('smtp_enabled')) {
            return $conversation['ref'] . ': nobody to tell, and e-mail is switched off';
        }

        $mailer = new Mailer();

        if (!$mailer->isConfigured()) {
            return $conversation['ref'] . ': nobody to tell, and no mail server is set up';
        }

        $result = $mailer->send(
            $to,
            (string) ($conversation['department'] ?? 'Live chat'),
            $title,
            '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#0F1E2E">'
            . '<p style="margin:0 0 10px"><strong>' . e($title) . '</strong></p>'
            . '<p style="margin:0 0 14px;color:#5A6B7D">' . nl2br(e($body)) . '</p>'
            . '<p style="margin:0 0 14px">Nobody is listed as staff for this department,'
            . ' so there was no one to tell inside the system. Somebody should be added'
            . ' under Live Chat &rarr; Departments.</p>'
            . '<p style="margin:0;font-size:12px;color:#8A97A6">'
            . e(Settings::get('company_name', 'Shanfix Technology'))
            . ' — internal notification</p></div>'
        );

        return $result['ok']
            ? $conversation['ref'] . ': no staff in the department — wrote to ' . $to
            : $conversation['ref'] . ': no staff in the department, and the e-mail failed — '
              . ($result['error'] ?? 'unknown error');
    }

    // -----------------------------------------------------------------
    // Bits and pieces
    // -----------------------------------------------------------------

    /** Everyone active in a department, lead or not. */
    private static function departmentStaff(int $departmentId): array
    {
        if ($departmentId <= 0) {
            return [];
        }

        return array_map('intval', array_column(
            Database::all(
                'SELECT s.user_id
                   FROM live_department_staff s
                   JOIN users u ON u.id = s.user_id
                  WHERE s.department_id = :d AND u.is_active = 1',
                ['d' => $departmentId]
            ),
            'user_id'
        ));
    }

    /**
     * What they actually said, shortened.
     *
     * A notice that says only "somebody is waiting" makes the reader open
     * the conversation to find out whether it is urgent. Carrying their
     * own words lets them decide without doing that.
     *
     * The most recent message rather than the first, which is the same
     * thing for a conversation that has just started and the right thing
     * for one somebody has written to again after it was closed — there,
     * the opening line is a question we already answered last week.
     */
    private static function latestLine(int $conversationId): string
    {
        $body = (string) Database::scalar(
            "SELECT body FROM live_messages
              WHERE conversation_id = :c AND sender = 'visitor' AND is_note = 0
              ORDER BY id DESC LIMIT 1",
            ['c' => $conversationId],
            ''
        );

        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

        return mb_strlen($body) > 160 ? mb_substr($body, 0, 159) . '…' : $body;
    }

    /** A name to put in the notice, without pretending we know one. */
    private static function who(array $conversation): string
    {
        $name = trim((string) ($conversation['visitor_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        $page = trim((string) ($conversation['page_title'] ?? ''));

        return $page !== '' ? 'Somebody on ' . $page : 'Somebody on the website';
    }
}
