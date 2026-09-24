<?php

namespace App\Services\LiveChat;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Sending somebody the conversation they just had.
 *
 * A live chat is the one channel that leaves the customer with nothing.
 * An email thread is in their inbox, a phone call gets written into the
 * file, but a chat window closes and the price they were quoted, the
 * reference they were given and the promise somebody made all close
 * with it. Then they write in again next week asking what we said.
 *
 * So when a conversation ends and we know where to write, the whole
 * thread goes to them. live_conversations.transcript_sent_at has been
 * sitting in migration 045 waiting for this since the day it was
 * written.
 *
 * Two things this must never do:
 *
 *   - Send a private note. Those are staff talking to each other about
 *     the customer, in the same thread, and the one thing worse than
 *     losing a transcript is posting one that contains "this is the
 *     third time they've asked, just quote them high". messagesSince()
 *     is asked for the visitor's view, not the desk's.
 *   - Send twice. The stamp goes on when the message is queued, not
 *     when it is delivered, because the queue is what retries.
 */
final class Transcripts
{
    /**
     * Email the transcript, if there is somewhere to send it.
     *
     * Quiet about the ordinary reasons not to — a stranger who never
     * gave an address is the common case, not a fault.
     *
     * @return bool whether a message was queued
     */
    public static function send(int $conversationId): bool
    {
        try {
            if (!Settings::bool('livechat_transcript_email', true)) {
                return false;
            }

            if (!Settings::bool('smtp_enabled')) {
                return false;
            }

            $conversation = Database::first(
                'SELECT c.*, d.name AS department
                   FROM live_conversations c
              LEFT JOIN live_departments d ON d.id = c.department_id
                  WHERE c.id = :id',
                ['id' => $conversationId]
            );

            if (!$conversation) {
                return false;
            }

            if ($conversation['transcript_sent_at'] !== null) {
                return false;                       // already gone out
            }

            $to = trim((string) $conversation['visitor_email']);

            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return false;                       // nowhere to send it
            }

            // The visitor's view of the thread: no notes, whatever else
            // was said. Asked for in exactly the way the widget asks.
            $messages = Conversations::messagesSince($conversationId, 0, false);

            if ($messages === []) {
                return false;                       // nothing to send
            }

            // Stamped before queueing rather than after. If the insert
            // below throws, this row is still marked and nothing goes —
            // which is the right way round: a transcript that never
            // arrives is a disappointment, and two that do is a customer
            // wondering who else has their conversation.
            Database::run(
                'UPDATE live_conversations SET transcript_sent_at = NOW()
                  WHERE id = :id AND transcript_sent_at IS NULL',
                ['id' => $conversationId]
            );

            Database::insert('notifications', [
                'channel'        => 'email',
                'event'          => 'livechat_transcript',
                'recipient'      => $to,
                'recipient_name' => $conversation['visitor_name'] ?: null,
                'subject'        => self::subject($conversation),
                'body'           => self::body($conversation, $messages),
                'entity_type'    => 'live_conversation',
                'entity_id'      => $conversationId,
                'client_id'      => $conversation['client_id'] ?: null,
            ]);

            return true;
        } catch (\Throwable $e) {
            // The conversation is closed either way. Failing to post the
            // record of it must not undo that.
            Logger::error('Live chat transcript failed: ' . $e->getMessage(), [
                'conversation' => $conversationId,
            ]);

            return false;
        }
    }

    // -----------------------------------------------------------------

    private static function subject(array $conversation): string
    {
        $company = (string) Settings::get('company_name', 'Shanfix Technology');

        return mb_substr(
            'Your conversation with ' . $company . ' — ' . $conversation['ref'],
            0,
            255
        );
    }

    /**
     * The thread, as something readable in a mail client.
     *
     * Plainly laid out rather than branded. Somebody opens this to find
     * one line — what they were quoted, when it will be ready — and a
     * header image is in the way of that.
     */
    private static function body(array $conversation, array $messages): string
    {
        $company = (string) Settings::get('company_name', 'Shanfix Technology');
        $us      = '#0C2B4A';
        $them    = '#14874E';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;'
              . 'line-height:1.6;color:#17222E;max-width:620px">'
              . '<p style="margin:0 0 4px"><strong>' . e($company) . '</strong></p>'
              . '<p style="margin:0 0 18px;color:#5A6B7D;font-size:13px">'
              . 'Your chat on ' . e(date('j F Y', strtotime((string) $conversation['created_at'])))
              . ' · reference ' . e((string) $conversation['ref'])
              . ($conversation['department'] ? ' · ' . e((string) $conversation['department']) : '')
              . '</p>';

        foreach ($messages as $m) {
            $mine  = $m['sender'] === 'visitor';
            $who   = $mine
                ? ($conversation['visitor_name'] ?: 'You')
                : ($m['sender'] === 'system' ? '' : ($m['sender_name'] ?: $company));
            $shade = $mine ? $them : $us;

            if ($m['sender'] === 'system') {
                $html .= '<p style="margin:0 0 12px;color:#8A97A6;font-size:12.5px;'
                       . 'font-style:italic">' . e((string) $m['body']) . '</p>';
                continue;
            }

            $html .= '<div style="margin:0 0 14px;padding-left:11px;'
                   . 'border-left:3px solid ' . $shade . '">'
                   . '<div style="font-size:12px;color:#5A6B7D;margin-bottom:2px">'
                   . e($who) . ' · ' . e(date('H:i', strtotime((string) $m['created_at'])))
                   . '</div>'
                   // nl2br over an escaped body: the message is the
                   // visitor's own words and a member of staff's, and
                   // neither gets to put markup in somebody's mail.
                   . '<div>' . nl2br(e((string) $m['body'])) . '</div>'
                   . '</div>';
        }

        $html .= '<p style="margin:18px 0 0;padding-top:12px;border-top:1px solid #E2E8F0;'
               . 'font-size:12px;color:#8A97A6">'
               . 'Quote ' . e((string) $conversation['ref']) . ' if you write to us about this.'
               . '</p></div>';

        return $html;
    }
}
