<?php

namespace App\Services\Social;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Services\StaffNotifier;

/**
 * Telling somebody a post is due, and that one was missed.
 *
 * A calendar nobody is reminded by is a calendar somebody fills in once
 * and then stops opening. Migration 053 seeded social_remind_hours and
 * nothing read it, so a post scheduled for nine tomorrow reminded
 * nobody and one that should have gone out an hour ago sat there
 * looking exactly like one that had.
 *
 * Three things, in order of how much anybody should care:
 *
 *   missed()   — the time came and went and nothing was published. This
 *                is a problem, not a note, and it goes to whoever can
 *                approve as well as to the writer: a post still sitting
 *                unapproved at its own publishing hour is usually
 *                waiting on somebody else.
 *
 *   due()      — some hours before. Easy to ignore, and meant to be:
 *                the writer knows what they are doing, this is just in
 *                case today ran away with them.
 *
 *   digest()   — the Monday line. What is coming this week, what is
 *                waiting on somebody, and what went out without anybody
 *                writing down how it did — which is the one that keeps
 *                the reports worth reading.
 *
 * Each stamps its own column so it fires once, and Posts::move() clears
 * both when a post is rescheduled, because a post moved to next week is
 * one nobody has been reminded about yet.
 */
final class Reminders
{
    /**
     * How far back to look for a missed post, in hours.
     *
     * Something three days late is not news, it is a decision somebody
     * made and did not write down. Without this, switching the cron on
     * after a quiet fortnight shouts about every post anybody ever left
     * on the calendar.
     */
    private const MISSED_WINDOW_HOURS = 48;

    // -----------------------------------------------------------------

    /**
     * Everything the cron should do for social media this run.
     *
     * @return array{nudged:int, missed:int, digest:int, notes:list<string>}
     */
    public static function sweep(): array
    {
        $out = ['nudged' => 0, 'missed' => 0, 'digest' => 0, 'notes' => []];

        try {
            $out['missed'] = self::missed($out['notes']);
            $out['nudged'] = self::due($out['notes']);
            $out['digest'] = self::digest($out['notes']);
        } catch (\Throwable $e) {
            // Before migration 053 there is no social media to sweep,
            // and before 054 no stamps to mark. Neither should stop the
            // rest of the run.
            $out['notes'][] = 'sweep failed: ' . $e->getMessage();
            Logger::error('Social reminder sweep failed: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Posts whose time has passed with nothing published.
     *
     * @param list<string> $notes
     */
    public static function missed(array &$notes): int
    {
        $late = Database::all(
            "SELECT p.id, p.ref, p.title, p.status, p.scheduled_for,
                    -- Whoever is writing it, or failing that whoever
                    -- started it. A post whose owner was cleared is not
                    -- a post nobody needs reminding about — it is the
                    -- one most likely to be forgotten.
                    COALESCE(p.owner_id, p.created_by) AS owner_id
               FROM social_posts p
              WHERE p.status IN ('idea','draft','awaiting','approved')
                AND p.scheduled_for IS NOT NULL
                AND p.missed_at IS NULL
                AND p.scheduled_for < NOW()
                AND p.scheduled_for > DATE_SUB(NOW(), INTERVAL :w HOUR)
           ORDER BY p.scheduled_for
              LIMIT 40",
            ['w' => self::MISSED_WINDOW_HOURS]
        );

        $told = 0;

        foreach ($late as $post) {
            // A post still waiting for a yes at its own publishing hour
            // is not the writer's problem to solve alone.
            $who = (int) $post['owner_id'] > 0 ? [(int) $post['owner_id']] : [];

            if ($who === [] || in_array($post['status'], ['awaiting', 'draft'], true)) {
                $who = array_merge($who, array_map(
                    static fn(array $u): int => (int) $u['id'],
                    Auth::usersWith('social.approve')
                ));
            }

            $why = match ($post['status']) {
                'awaiting' => 'It is still waiting for approval.',
                'approved' => 'It is approved — it just has not been posted.',
                'draft'    => 'It is still a draft.',
                default    => 'It is still only an idea.',
            };

            StaffNotifier::notify($who, [
                'event'       => 'social_missed',
                'title'       => 'This should have gone out: ' . $post['title'],
                'body'        => 'Due ' . date('j M \a\t H:i', strtotime((string) $post['scheduled_for']))
                               . '. ' . $why,
                'link'        => '/social/' . $post['id'],
                'entity_type' => 'social_post',
                'entity_id'   => (int) $post['id'],
            ]);

            // Stamped whatever happened, including when there was nobody
            // to tell — otherwise the same post is shouted about every
            // fifteen minutes for two days.
            Database::run(
                'UPDATE social_posts SET missed_at = NOW() WHERE id = :id',
                ['id' => $post['id']]
            );

            $told++;
        }

        if ($told > 0) {
            $notes[] = $told . ' post' . ($told === 1 ? '' : 's') . ' did not go out on time';
        }

        return $told;
    }

    /**
     * Posts coming up inside the reminder window.
     *
     * @param list<string> $notes
     */
    public static function due(array &$notes): int
    {
        $hours = max(1, min(168, (int) Settings::get('social_remind_hours', 24)));

        $soon = Database::all(
            "SELECT p.id, p.ref, p.title, p.status, p.scheduled_for,
                    COALESCE(p.owner_id, p.created_by) AS owner_id
               FROM social_posts p
              WHERE p.status IN ('idea','draft','awaiting','approved')
                AND p.scheduled_for IS NOT NULL
                AND p.reminded_at IS NULL
                AND p.scheduled_for >= NOW()
                AND p.scheduled_for <= DATE_ADD(NOW(), INTERVAL :h HOUR)
           ORDER BY p.scheduled_for
              LIMIT 40",
            ['h' => $hours]
        );

        $told = 0;

        foreach ($soon as $post) {
            $ready = $post['status'] === 'approved';

            $who = (int) $post['owner_id'] > 0
                ? [(int) $post['owner_id']]
                : array_map(
                    static fn(array $u): int => (int) $u['id'],
                    Auth::usersWith('social.approve')
                );

            StaffNotifier::notify($who, [
                'event'       => 'social_due',
                'title'       => ($ready ? 'Ready to post: ' : 'Due soon, not approved yet: ')
                               . $post['title'],
                'body'        => 'Going out ' . date('j M \a\t H:i', strtotime((string) $post['scheduled_for']))
                               . ($ready ? '.' : '. It still needs somebody to approve it.'),
                'link'        => '/social/' . $post['id'],
                'entity_type' => 'social_post',
                'entity_id'   => (int) $post['id'],
            ]);

            Database::run(
                'UPDATE social_posts SET reminded_at = NOW() WHERE id = :id',
                ['id' => $post['id']]
            );

            $told++;
        }

        if ($told > 0) {
            $notes[] = $told . ' post' . ($told === 1 ? '' : 's') . ' coming up';
        }

        return $told;
    }

    /**
     * The Monday note.
     *
     * One message a week to everybody who works on this, saying what is
     * coming, what is stuck and what is owed numbers. Sent once on the
     * chosen day — the stamp is a setting rather than a column, because
     * it belongs to the week and not to any one post.
     *
     * @param list<string> $notes
     */
    public static function digest(array &$notes): int
    {
        if (!Settings::bool('social_weekly_digest', true)) {
            return 0;
        }

        $day = max(1, min(7, (int) Settings::get('social_digest_day', 1)));

        if ((int) date('N') !== $day) {
            return 0;
        }

        // Once a week, not once every fifteen minutes on a Monday.
        $week = date('o-\WW');

        if ((string) Settings::get('social_digest_sent', '') === $week) {
            return 0;
        }

        $ahead   = date('Y-m-d 23:59:59', strtotime('+6 days'));
        $counts  = Database::first(
            "SELECT
                SUM(p.scheduled_for BETWEEN NOW() AND :ahead
                    AND p.status IN ('idea','draft','awaiting','approved'))     AS coming,
                SUM(p.status = 'awaiting')                                      AS waiting,
                SUM(p.scheduled_for IS NULL
                    AND p.status IN ('idea','draft'))                           AS ideas
               FROM social_posts p",
            ['ahead' => $ahead]
        );

        // Published, but nobody has written down how it did. The line
        // that keeps the reports worth reading.
        $unchecked = (int) Database::scalar(
            "SELECT COUNT(DISTINCT p.id)
               FROM social_posts p
               JOIN social_post_targets t ON t.post_id = p.id
              WHERE p.status = 'published' AND t.metrics_at IS NULL
                AND p.published_at > DATE_SUB(NOW(), INTERVAL 60 DAY)",
            [],
            0
        );

        $coming  = (int) ($counts['coming'] ?? 0);
        $waiting = (int) ($counts['waiting'] ?? 0);
        $ideas   = (int) ($counts['ideas'] ?? 0);

        // Nothing planned, nothing stuck, nothing owed: no note. A
        // weekly message that says "nothing" every week is a weekly
        // message people stop opening.
        if ($coming === 0 && $waiting === 0 && $unchecked === 0) {
            Settings::set('social_digest_sent', $week);
            return 0;
        }

        $lines = [];
        $lines[] = $coming === 0
            ? 'Nothing is scheduled for this week.'
            : $coming . ' post' . ($coming === 1 ? '' : 's') . ' going out this week.';

        if ($waiting > 0) {
            $lines[] = $waiting . ' waiting for approval.';
        }

        if ($unchecked > 0) {
            $lines[] = $unchecked . ' published without any numbers written down yet.';
        }

        if ($coming === 0 && $ideas > 0) {
            $lines[] = $ideas . ' idea' . ($ideas === 1 ? '' : 's')
                     . ' with no date — one of those could fill the week.';
        }

        $told = StaffNotifier::notify(
            array_map(static fn(array $u): int => (int) $u['id'], Auth::usersWith('social.view')),
            [
                'event' => 'social_week',
                'title' => 'Social media this week',
                'body'  => implode(' ', $lines),
                'link'  => '/social',
            ]
        );

        Settings::set('social_digest_sent', $week);

        if ($told > 0) {
            $notes[] = 'told ' . $told . ' ' . ($told === 1 ? 'person' : 'people') . ' about the week';
        }

        return $told;
    }
}
