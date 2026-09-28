<?php

namespace App\Services\Social;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Numbering;
use App\Core\Settings;
use App\Services\StaffNotifier;

/**
 * What we are posting, and where it is up to.
 *
 * A post is one idea written once and sent to several places. The
 * caption, the picture and the date belong to the post; the link it
 * ended up at and the numbers it did belong to each place separately,
 * because the same words on Instagram and on LinkedIn are two different
 * results and averaging them hides which is worth the effort.
 *
 * Nothing here publishes anything. Facebook and Instagram will not take
 * a post from an application they have not reviewed, and that review is
 * paperwork rather than programming. So the person who writes the post
 * also posts it, and comes back to paste the link in — which is a
 * minute of work and leaves the planning, the approval and the record
 * exactly as useful as they would otherwise be. The columns a publisher
 * would fill are the ones a person fills now.
 */
final class Posts
{
    /**
     * Where a post can go from where it is.
     *
     * Written down rather than checked in an if: the states are few and
     * the wrong move is the kind of thing that is easy to allow by
     * accident — approving something straight from an idea, or
     * reopening something already out in the world.
     */
    private const MOVES = [
        'idea'      => ['draft', 'cancelled'],
        'draft'     => ['awaiting', 'approved', 'idea', 'cancelled'],
        'awaiting'  => ['approved', 'draft', 'cancelled'],
        'approved'  => ['published', 'draft', 'cancelled'],
        // Published is where a post stops. What went out went out, and
        // a record that can be walked backwards is not a record.
        'published' => [],
        'cancelled' => ['draft'],
    ];

    public const STATUSES = [
        'idea'      => 'Idea',
        'draft'     => 'Draft',
        'awaiting'  => 'Waiting for approval',
        'approved'  => 'Approved',
        'published' => 'Published',
        'cancelled' => 'Dropped',
    ];

    public const TYPES = [
        'post'     => 'Post',
        'story'    => 'Story',
        'reel'     => 'Reel',
        'video'    => 'Video',
        'carousel' => 'Carousel',
        'article'  => 'Article',
    ];

    // -----------------------------------------------------------------
    // Writing one
    // -----------------------------------------------------------------

    /**
     * Start a post.
     *
     * @param array{title:string, caption?:string, hashtags?:string,
     *              link_url?:string, first_comment?:string, post_type?:string,
     *              scheduled_for?:?string, campaign_id?:?int, owner_id?:?int,
     *              notes?:string} $in
     */
    public static function create(array $in): int
    {
        return Database::insert('social_posts', [
            'ref'           => Numbering::next('social_post'),
            'campaign_id'   => $in['campaign_id'] ?? null,
            'title'         => mb_substr(trim($in['title']), 0, 140),
            'caption'       => self::clean($in['caption'] ?? ''),
            'hashtags'      => self::tags($in['hashtags'] ?? ''),
            'link_url'      => self::url($in['link_url'] ?? ''),
            'first_comment' => mb_substr(trim((string) ($in['first_comment'] ?? '')), 0, 500) ?: null,
            'post_type'     => isset(self::TYPES[$in['post_type'] ?? '']) ? $in['post_type'] : 'post',
            'status'        => 'draft',
            'scheduled_for' => self::when($in['scheduled_for'] ?? null),
            // Whoever is writing it, which is whoever started it unless
            // somebody says otherwise.
            'owner_id'      => $in['owner_id'] ?? Auth::id(),
            'created_by'    => Auth::id(),
            'notes'         => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 1000) ?: null,
        ]);
    }

    /** Change one. Never its status — that is move(). */
    public static function update(int $postId, array $in): void
    {
        // A post moved to another day is one nobody has been reminded
        // about yet, so the stamps come off with the date. Without
        // this, rescheduling means the reminder never fires again —
        // and the post that was moved is exactly the one most likely
        // to be forgotten.
        $was  = Database::scalar(
            'SELECT scheduled_for FROM social_posts WHERE id = :id', ['id' => $postId]
        );
        $now  = self::when($in['scheduled_for'] ?? null);

        $data = [
            'title'         => mb_substr(trim($in['title']), 0, 140),
            'caption'       => self::clean($in['caption'] ?? ''),
            'hashtags'      => self::tags($in['hashtags'] ?? ''),
            'link_url'      => self::url($in['link_url'] ?? ''),
            'first_comment' => mb_substr(trim((string) ($in['first_comment'] ?? '')), 0, 500) ?: null,
            'post_type'     => isset(self::TYPES[$in['post_type'] ?? '']) ? $in['post_type'] : 'post',
            'scheduled_for' => self::when($in['scheduled_for'] ?? null),
            'campaign_id'   => $in['campaign_id'] ?? null,
            'owner_id'      => $in['owner_id'] ?? null,
            'notes'         => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 1000) ?: null,
        ];

        if ($was !== $now) {
            $data['reminded_at'] = null;
            $data['missed_at']   = null;
        }

        Database::update('social_posts', $data, ['id' => $postId]);
    }

    public static function find(int $postId): ?array
    {
        return Database::first(
            'SELECT p.*, c.name AS campaign, u.name AS owner, a.name AS approver
               FROM social_posts p
          LEFT JOIN social_campaigns c ON c.id = p.campaign_id
          LEFT JOIN users u            ON u.id = p.owner_id
          LEFT JOIN users a            ON a.id = p.approved_by
              WHERE p.id = :id',
            ['id' => $postId]
        );
    }

    // -----------------------------------------------------------------
    // Moving it along
    // -----------------------------------------------------------------

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::MOVES[$from] ?? [], true);
    }

    /**
     * Move a post to another state.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function move(int $postId, string $to, string $note = ''): array
    {
        $post = Database::first('SELECT * FROM social_posts WHERE id = :id', ['id' => $postId]);

        if (!$post) {
            return ['ok' => false, 'error' => 'No such post.'];
        }

        if ($post['status'] === $to) {
            return ['ok' => true];
        }

        if (!self::canMove((string) $post['status'], $to)) {
            return ['ok' => false, 'error' => 'A post cannot go from '
                . self::STATUSES[$post['status']] . ' to ' . (self::STATUSES[$to] ?? $to) . '.'];
        }

        // Approving is the one move that is not simply the writer's to
        // make, and it is not one anybody may make on their own work.
        if ($to === 'approved') {
            if (!Auth::can('social.approve')) {
                return ['ok' => false, 'error' => 'Somebody else has to approve this.'];
            }

            if (self::needsApproval() && (int) $post['owner_id'] === (int) Auth::id()) {
                return ['ok' => false, 'error' =>
                    'This is yours, so somebody else has to approve it.'];
            }
        }

        $data = ['status' => $to];

        if ($to === 'approved') {
            $data['approved_by']   = Auth::id();
            $data['approved_at']   = date('Y-m-d H:i:s');
            $data['changes_asked'] = null;
        }

        // Sent back for changes: the reason travels with it, because
        // "not approved" on its own tells the writer nothing to act on.
        if ($to === 'draft' && $post['status'] === 'awaiting') {
            $data['changes_asked'] = mb_substr(trim($note), 0, 500) ?: null;
            $data['approved_by']   = null;
            $data['approved_at']   = null;
        }

        if ($to === 'published' && $post['published_at'] === null) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }

        Database::update('social_posts', $data, ['id' => $postId]);
        self::tell($post, $to, $note);

        return ['ok' => true];
    }

    /** Whether what goes out needs somebody else's yes first. */
    public static function needsApproval(): bool
    {
        return Settings::bool('social_approval_required', true);
    }

    // -----------------------------------------------------------------
    // Where it goes
    // -----------------------------------------------------------------

    /**
     * Set exactly which profiles this post is for.
     *
     * Sent as the whole list rather than one at a time, because that is
     * how the form presents it and because it makes "nowhere" sayable.
     * A profile already carrying a published link is kept whatever the
     * form says: that post is out in the world, and removing the row
     * would throw away the only record of where it went.
     *
     * @param int[] $accountIds
     */
    public static function target(int $postId, array $accountIds): void
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $accountIds))));

        $live = array_column(
            Database::all(
                'SELECT account_id FROM social_post_targets
                  WHERE post_id = :p AND (post_url IS NOT NULL OR published_at IS NOT NULL)',
                ['p' => $postId]
            ),
            'account_id'
        );

        $keep = array_values(array_unique(array_merge($wanted, array_map('intval', $live))));

        Database::transaction(static function () use ($postId, $keep): void {
            if ($keep === []) {
                Database::run('DELETE FROM social_post_targets WHERE post_id = :p', ['p' => $postId]);
                return;
            }

            $slots = implode(',', array_fill(0, count($keep), '?'));

            Database::run(
                "DELETE FROM social_post_targets WHERE post_id = ? AND account_id NOT IN ({$slots})",
                array_merge([$postId], $keep)
            );

            foreach ($keep as $accountId) {
                Database::run(
                    'INSERT INTO social_post_targets (post_id, account_id)
                     VALUES (:p, :a)
                     ON DUPLICATE KEY UPDATE post_id = post_id',
                    ['p' => $postId, 'a' => $accountId]
                );
            }
        });
    }

    /** @return list<array<string,mixed>> the profiles, with their numbers */
    public static function targetsOf(int $postId): array
    {
        return Database::all(
            'SELECT t.*, a.network, a.name AS account, a.handle
               FROM social_post_targets t
               JOIN social_accounts a ON a.id = t.account_id
              WHERE t.post_id = :p
           ORDER BY a.position, a.name',
            ['p' => $postId]
        );
    }

    /**
     * Record where it landed and how it did.
     *
     * metrics_at is only stamped when a figure is actually given. A post
     * nobody has checked on is not a post that reached nobody, and the
     * reports have to be able to tell those apart.
     */
    public static function record(int $targetId, array $in): void
    {
        $numbers = [];

        foreach (['reach', 'impressions', 'likes', 'comments', 'shares', 'saves', 'clicks', 'follows'] as $key) {
            if (array_key_exists($key, $in) && $in[$key] !== '' && $in[$key] !== null) {
                $numbers[$key] = max(0, (int) $in[$key]);
            }
        }

        $data = $numbers;

        if (array_key_exists('post_url', $in)) {
            $data['post_url'] = self::url((string) $in['post_url']);
        }

        if (!empty($in['published_at'])) {
            $data['published_at'] = self::when($in['published_at']);
        }

        if ($numbers !== []) {
            $data['metrics_at'] = date('Y-m-d H:i:s');
        }

        if ($data === []) {
            return;
        }

        Database::update('social_post_targets', $data, ['id' => $targetId]);
    }

    // -----------------------------------------------------------------
    // Telling people
    // -----------------------------------------------------------------

    /**
     * Who needs to know about this move.
     *
     * Only the two that somebody is waiting on: a post put up for
     * approval, and one sent back. Everything else is the writer's own
     * work and telling them about it is noise.
     */
    private static function tell(array $post, string $to, string $note): void
    {
        try {
            if ($to === 'awaiting') {
                $able = array_map(
                    static fn(array $u): int => (int) $u['id'],
                    Auth::usersWith('social.approve')
                );

                StaffNotifier::notify($able, [
                    'event'       => 'social_awaiting',
                    'title'       => 'A post is waiting for approval: ' . $post['title'],
                    'body'        => self::excerpt((string) $post['caption']),
                    'link'        => '/social/' . $post['id'],
                    'entity_type' => 'social_post',
                    'entity_id'   => (int) $post['id'],
                ]);

                return;
            }

            if ($to === 'draft' && $post['status'] === 'awaiting' && $post['owner_id']) {
                StaffNotifier::notify([(int) $post['owner_id']], [
                    'event'       => 'social_changes',
                    'title'       => 'Changes asked for on: ' . $post['title'],
                    'body'        => trim($note) !== '' ? $note : 'No reason was given.',
                    'link'        => '/social/' . $post['id'],
                    'entity_type' => 'social_post',
                    'entity_id'   => (int) $post['id'],
                ]);

                return;
            }

            if ($to === 'approved' && $post['owner_id']) {
                StaffNotifier::notify([(int) $post['owner_id']], [
                    'event'       => 'social_approved',
                    'title'       => 'Approved, ready to go out: ' . $post['title'],
                    'body'        => self::excerpt((string) $post['caption']),
                    'link'        => '/social/' . $post['id'],
                    'entity_type' => 'social_post',
                    'entity_id'   => (int) $post['id'],
                ]);
            }
        } catch (\Throwable $e) {
            // The move happened. Failing to announce it must not undo it.
            \App\Core\Logger::error('Social notice failed: ' . $e->getMessage(), [
                'post' => $post['id'] ?? null,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Tidying what people type
    // -----------------------------------------------------------------

    /**
     * A caption, as somebody pasted it.
     *
     * Emoji are the point of the exercise here, so nothing is stripped
     * but the control characters that cannot be typed on purpose — the
     * same repair the live chat makes, and for the same reason: a
     * caption pasted from a phone can carry bytes that are not UTF-8,
     * and preg_replace with /u on those returns null.
     */
    private static function clean(string $caption): string
    {
        $caption = trim($caption);

        if ($caption === '') {
            return '';
        }

        if (!mb_check_encoding($caption, 'UTF-8')) {
            $caption = mb_convert_encoding($caption, 'UTF-8', 'UTF-8');
        }

        $caption = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $caption) ?? '';

        return mb_substr($caption, 0, 5000);
    }

    /** Hashtags, however they were typed, as a single spaced line. */
    private static function tags(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $parts = preg_split('/[\s,]+/u', $raw) ?: [];
        $tags  = [];

        foreach ($parts as $part) {
            $tag = ltrim(trim($part), '#');

            if ($tag === '') {
                continue;
            }

            $tags['#' . $tag] = true;      // keyed, so a repeat is dropped
        }

        return $tags === [] ? null : mb_substr(implode(' ', array_keys($tags)), 0, 500);
    }

    private static function url(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // Somebody pastes "instagram.com/p/xyz" as often as the whole
        // thing. A link that does not open is worse than none.
        if (!preg_match('~^https?://~i', $raw)) {
            $raw = 'https://' . $raw;
        }

        return filter_var($raw, FILTER_VALIDATE_URL) ? mb_substr($raw, 0, 255) : null;
    }

    private static function when(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $when = strtotime($raw);

        return $when === false ? null : date('Y-m-d H:i:s', $when);
    }

    private static function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 159) . '…' : $text;
    }
}
