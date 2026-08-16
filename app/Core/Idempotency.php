<?php
namespace App\Core;

/**
 * Makes a replayed request safe to receive twice.
 *
 * A device that worked offline replays its queued actions when it comes
 * back. Replay is inherently at-least-once: if the connection drops after
 * the server committed but before the response arrived, the device cannot
 * know it succeeded and will send it again. Without a guard, "mark job
 * ready" runs twice and the job history grows a duplicate entry.
 *
 * The key is generated on the device when the user presses the button, so
 * it is stable across every retry of that one action. The first arrival
 * claims it; later arrivals are recognised and skipped.
 */
class Idempotency
{
    /** Keys older than this are pruned; well past any offline stint. */
    public const RETENTION_DAYS = 30;

    /**
     * Claim a key for this request.
     *
     * @return bool true when this is the first time we have seen it and the
     *              caller should go ahead; false when it is a replay.
     */
    public static function claim(?string $key, ?string $route = null): bool
    {
        // No key means an ordinary online request — nothing to guard.
        if (!self::isWellFormed($key)) {
            return true;
        }

        try {
            Database::run(
                'INSERT INTO idempotency_keys (key_hash, user_id, route) VALUES (:k, :u, :r)',
                [
                    'k' => hash('sha256', (string) $key),
                    'u' => Auth::id(),
                    'r' => $route !== null ? mb_substr($route, 0, 190) : null,
                ]
            );
        } catch (\Throwable) {
            // The unique index rejected it: we have already done this one.
            return false;
        }

        return true;
    }

    /**
     * Reject anything that is not a plausible client key before it reaches
     * the database — the column is a hash, so a hostile value cannot do
     * damage, but there is no reason to store junk.
     */
    private static function isWellFormed(?string $key): bool
    {
        return is_string($key)
            && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key) === 1;
    }

    /** Called from cron; the keys are only useful while a replay is possible. */
    public static function prune(): int
    {
        return Database::run(
            'DELETE FROM idempotency_keys WHERE created_at < DATE_SUB(NOW(), INTERVAL '
            . self::RETENTION_DAYS . ' DAY)'
        )->rowCount();
    }
}
