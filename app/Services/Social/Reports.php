<?php

namespace App\Services\Social;

use App\Core\Database;

/**
 * Whether any of this is working.
 *
 * The question somebody actually asks at the end of a month is not how
 * many posts went out. It is which of them were worth the afternoon
 * spent on them — and that is answered per network, because the same
 * caption does differently on Instagram and on LinkedIn, and an average
 * across both hides which of the two to keep doing.
 *
 * Every figure here comes from what somebody typed in after looking at
 * the app. So the one thing these reports must never do is treat a post
 * nobody has checked on as a post that reached nobody: metrics_at
 * separates "zero" from "not looked at", and anything averaged ignores
 * the second kind rather than counting it as failure.
 */
final class Reports
{
    /** The figures people actually compare, in the order they read them. */
    public const MEASURES = [
        'reach'       => 'Reach',
        'impressions' => 'Impressions',
        'likes'       => 'Likes',
        'comments'    => 'Comments',
        'shares'      => 'Shares',
        'saves'       => 'Saves',
        'clicks'      => 'Clicks',
        'follows'     => 'New followers',
    ];

    /**
     * How the month went.
     *
     * @return array{posted:int, planned:int, awaiting:int, unchecked:int,
     *               reach:int, engagement:int, follows:int}
     */
    public static function summary(string $from, string $to): array
    {
        $row = Database::first(
            "SELECT
                COUNT(DISTINCT CASE WHEN p.status = 'published' THEN p.id END)  AS posted,
                COUNT(DISTINCT CASE WHEN p.status IN ('idea','draft','approved')
                                    THEN p.id END)                              AS planned,
                COUNT(DISTINCT CASE WHEN p.status = 'awaiting' THEN p.id END)   AS awaiting,
                -- Places a published post went that nobody has looked at
                -- yet. The number that says how much of the rest to
                -- trust.
                COUNT(CASE WHEN p.status = 'published' AND t.metrics_at IS NULL
                           THEN 1 END)                                          AS unchecked,
                COALESCE(SUM(t.reach), 0)                                       AS reach,
                COALESCE(SUM(t.likes + t.comments + t.shares + t.saves), 0)     AS engagement,
                COALESCE(SUM(t.follows), 0)                                     AS follows
               FROM social_posts p
          LEFT JOIN social_post_targets t ON t.post_id = p.id
              WHERE " . self::window(),
            ['from' => $from, 'to' => $to]
        );

        return [
            'posted'     => (int) ($row['posted'] ?? 0),
            'planned'    => (int) ($row['planned'] ?? 0),
            'awaiting'   => (int) ($row['awaiting'] ?? 0),
            'unchecked'  => (int) ($row['unchecked'] ?? 0),
            'reach'      => (int) ($row['reach'] ?? 0),
            'engagement' => (int) ($row['engagement'] ?? 0),
            'follows'    => (int) ($row['follows'] ?? 0),
        ];
    }

    /**
     * Network by network.
     *
     * 'checked' travels beside every total so a row reading "0 reach"
     * can be told apart from one nobody has recorded yet — the whole
     * reason somebody distrusts a report is finding out too late that
     * it was averaging blanks.
     */
    public static function byNetwork(string $from, string $to): array
    {
        return Database::all(
            "SELECT a.network,
                    COUNT(*)                                              AS posts,
                    SUM(t.metrics_at IS NOT NULL)                         AS checked,
                    COALESCE(SUM(t.reach), 0)                             AS reach,
                    COALESCE(SUM(t.impressions), 0)                       AS impressions,
                    COALESCE(SUM(t.likes + t.comments + t.shares + t.saves), 0) AS engagement,
                    COALESCE(SUM(t.clicks), 0)                            AS clicks,
                    COALESCE(SUM(t.follows), 0)                           AS follows
               FROM social_post_targets t
               JOIN social_posts p    ON p.id = t.post_id
               JOIN social_accounts a ON a.id = t.account_id
              WHERE p.status = 'published' AND " . self::window() . "
           GROUP BY a.network
           ORDER BY reach DESC, posts DESC",
            ['from' => $from, 'to' => $to]
        );
    }

    /** Campaign by campaign, with what each one cost. */
    public static function byCampaign(string $from, string $to): array
    {
        return Database::all(
            "SELECT c.id, c.name, c.goal, c.spend, c.status,
                    COUNT(DISTINCT p.id)                                  AS posts,
                    COALESCE(SUM(t.reach), 0)                             AS reach,
                    COALESCE(SUM(t.likes + t.comments + t.shares + t.saves), 0) AS engagement,
                    COALESCE(SUM(t.clicks), 0)                            AS clicks,
                    COALESCE(SUM(t.follows), 0)                           AS follows
               FROM social_campaigns c
          LEFT JOIN social_posts p         ON p.campaign_id = c.id AND p.status = 'published'
                                          AND " . self::window('p') . "
          LEFT JOIN social_post_targets t  ON t.post_id = p.id
           GROUP BY c.id, c.name, c.goal, c.spend, c.status
             HAVING posts > 0 OR c.status IN ('planned','running')
           ORDER BY reach DESC, c.starts_on DESC",
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * The posts that did best, and the ones that did worst.
     *
     * Only what has been checked. A post nobody has recorded numbers for
     * is not the worst post of the month, it is the one somebody forgot
     * — and putting it at the bottom of this list would have the office
     * drawing a lesson from a blank.
     */
    public static function best(string $from, string $to, int $limit = 8, string $order = 'DESC'): array
    {
        $order = $order === 'ASC' ? 'ASC' : 'DESC';

        return Database::all(
            "SELECT p.id, p.ref, p.title, p.post_type, p.published_at,
                    c.name AS campaign,
                    GROUP_CONCAT(DISTINCT a.network ORDER BY a.network SEPARATOR ',') AS networks,
                    COALESCE(SUM(t.reach), 0)                             AS reach,
                    COALESCE(SUM(t.likes + t.comments + t.shares + t.saves), 0) AS engagement,
                    COALESCE(SUM(t.follows), 0)                           AS follows
               FROM social_posts p
               JOIN social_post_targets t ON t.post_id = p.id AND t.metrics_at IS NOT NULL
               JOIN social_accounts a     ON a.id = t.account_id
          LEFT JOIN social_campaigns c    ON c.id = p.campaign_id
              WHERE p.status = 'published' AND " . self::window() . "
           GROUP BY p.id, p.ref, p.title, p.post_type, p.published_at, c.name
           ORDER BY engagement {$order}, reach {$order}
              LIMIT " . max(1, min(50, $limit)),
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * What day and hour the good ones went out.
     *
     * Only ever a hint. A print shop posts a few times a week, so this
     * is a handful of rows and not a finding — the screen says as much
     * rather than letting somebody rebuild their week around four data
     * points.
     */
    public static function timing(string $from, string $to): array
    {
        return Database::all(
            "SELECT DAYOFWEEK(p.published_at) AS dow,
                    HOUR(p.published_at)      AS hour,
                    COUNT(DISTINCT p.id)      AS posts,
                    ROUND(AVG(t.likes + t.comments + t.shares + t.saves)) AS engagement
               FROM social_posts p
               JOIN social_post_targets t ON t.post_id = p.id AND t.metrics_at IS NOT NULL
              WHERE p.status = 'published' AND p.published_at IS NOT NULL
                AND " . self::window() . "
           GROUP BY dow, hour
             HAVING posts > 0
           ORDER BY engagement DESC
              LIMIT 12",
            ['from' => $from, 'to' => $to]
        );
    }

    /** Who wrote what, for a team of more than one. */
    public static function byPerson(string $from, string $to): array
    {
        return Database::all(
            "SELECT u.id, u.name,
                    COUNT(DISTINCT p.id)                                  AS posts,
                    COALESCE(SUM(t.reach), 0)                             AS reach,
                    COALESCE(SUM(t.likes + t.comments + t.shares + t.saves), 0) AS engagement
               FROM social_posts p
               JOIN users u ON u.id = p.owner_id
          LEFT JOIN social_post_targets t ON t.post_id = p.id
              WHERE p.status = 'published' AND " . self::window() . "
           GROUP BY u.id, u.name
           ORDER BY posts DESC",
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Where each profile's following stands.
     *
     * Typed in rather than fetched, so it carries when it was last
     * looked at — a follower count from March is worse than none if
     * nothing says it is from March.
     */
    public static function following(): array
    {
        return Database::all(
            "SELECT id, network, name, handle, followers, followers_at, profile_url
               FROM social_accounts
              WHERE status = 'active'
           ORDER BY position, name"
        );
    }

    // -----------------------------------------------------------------

    /**
     * The date window, as SQL.
     *
     * Published posts are counted by when they went out; everything
     * else by when it is due. A draft has no published_at, and dating
     * it by when somebody started typing would put next month's plan in
     * last month's report.
     */
    private static function window(string $alias = 'p'): string
    {
        return "COALESCE({$alias}.published_at, {$alias}.scheduled_for, {$alias}.created_at)"
             . ' BETWEEN :from AND :to';
    }
}
