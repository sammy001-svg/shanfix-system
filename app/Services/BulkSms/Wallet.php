<?php
namespace App\Services\BulkSms;

use App\Core\Database;
use RuntimeException;

/**
 * Where SMS units live, and the only code that moves them.
 *
 * Every change to a balance goes through here and writes a ledger row in
 * the same transaction, so the ledger and the balance cannot disagree:
 * either both happen or neither does. Nothing else in the system updates
 * bulk_accounts.sms_units directly, and a test enforces that.
 *
 * Debits are conditional updates — "take N if there are at least N" —
 * so two sends racing for the last units cannot both succeed and leave
 * an account below zero. The old platform did the same; this keeps it.
 */
final class Wallet
{
    /** Who is moving units, recorded on every ledger row. */
    public const ACTORS = ['staff', 'client_user', 'partner', 'system', 'api'];

    private const CHANNEL_COLUMNS = [
        'sms'      => 'sms_units',
        'whatsapp' => 'whatsapp_balance',
        'ussd'     => 'ussd_balance',
    ];

    /**
     * Add to an account.
     *
     * @param array{ref_type?:string, ref_id?:int, note?:string, counterparty_id?:int,
     *              actor_type?:string, actor_id?:int, channel?:string} $meta
     * @return float the balance afterwards
     */
    public static function credit(int $accountId, float $amount, string $kind, array $meta = []): float
    {
        if ($amount <= 0) {
            throw new RuntimeException('A credit has to be more than nothing.');
        }

        return self::atomically(static function () use ($accountId, $amount, $kind, $meta): float {
            $column = self::column($meta['channel'] ?? 'sms');

            $changed = Database::run(
                "UPDATE bulk_accounts SET {$column} = {$column} + :amount WHERE id = :id",
                ['amount' => $amount, 'id' => $accountId]
            )->rowCount();

            if ($changed === 0) {
                throw new RuntimeException('There is no SMS account #' . $accountId . '.');
            }

            return self::record($accountId, $amount, $kind, $meta);
        });
    }

    /**
     * Take from an account, but only if it holds enough.
     *
     * @return float|null the balance afterwards, or null when the account
     *                    did not have enough — nothing is taken in that case
     */
    public static function debit(int $accountId, float $amount, string $kind, array $meta = []): ?float
    {
        if ($amount <= 0) {
            throw new RuntimeException('A debit has to be more than nothing.');
        }

        return self::atomically(static function () use ($accountId, $amount, $kind, $meta): ?float {
            $column = self::column($meta['channel'] ?? 'sms');

            // The condition is the whole point. Without it, two batches
            // that each checked the balance first would both pass the
            // check and both spend it.
            $taken = Database::run(
                "UPDATE bulk_accounts SET {$column} = {$column} - :amount
                  WHERE id = :id AND {$column} >= :enough",
                ['amount' => $amount, 'id' => $accountId, 'enough' => $amount]
            )->rowCount();

            if ($taken === 0) {
                return null;
            }

            return self::record($accountId, -$amount, $kind, $meta);
        });
    }

    /**
     * Move units from one account to another: a sale, or a hand transfer.
     *
     * Both halves in one transaction, and the receiving half only happens
     * if the giving half could. Units are never created here.
     *
     * @return bool false when the sender did not hold enough
     */
    public static function move(
        int $fromId,
        int $toId,
        float $amount,
        string $outKind,
        string $inKind,
        array $meta = []
    ): bool {
        if ($fromId === $toId) {
            throw new RuntimeException('An account cannot sell units to itself.');
        }

        return self::atomically(static function () use ($fromId, $toId, $amount, $outKind, $inKind, $meta): bool {
            $out = self::debit($fromId, $amount, $outKind, $meta + ['counterparty_id' => $toId]);

            if ($out === null) {
                return false;
            }

            self::credit($toId, $amount, $inKind, ['counterparty_id' => $fromId] + $meta);

            return true;
        });
    }

    /**
     * Set the house balance to what the gateway says we hold.
     *
     * The house is the only account allowed to be set rather than moved,
     * because its balance is a mirror of Onfon's, not a thing of ours. The
     * difference is still written to the ledger as an adjustment, so a
     * sync that jumps by thousands is visible afterwards.
     */
    public static function syncHouse(float $gatewayBalance, array $meta = []): float
    {
        return self::atomically(static function () use ($gatewayBalance, $meta): float {
            $house = Accounts::house();

            $current = (float) Database::scalar(
                'SELECT sms_units FROM bulk_accounts WHERE id = :id FOR UPDATE',
                ['id' => $house['id']]
            );

            $delta = round($gatewayBalance - $current, 4);

            if (abs($delta) < 0.0001) {
                return $current;
            }

            Database::run(
                'UPDATE bulk_accounts SET sms_units = :b WHERE id = :id',
                ['b' => $gatewayBalance, 'id' => $house['id']]
            );

            return self::record((int) $house['id'], $delta, 'adjustment',
                $meta + ['note' => 'Synced to the gateway balance']);
        });
    }

    /** One account's balance, straight from the row. */
    public static function balance(int $accountId, string $channel = 'sms'): float
    {
        $column = self::column($channel);

        return (float) Database::scalar(
            "SELECT {$column} FROM bulk_accounts WHERE id = :id",
            ['id' => $accountId],
            0
        );
    }

    // -----------------------------------------------------------------

    /**
     * Write the ledger row for a change that has just been made.
     *
     * Reads the balance back inside the same transaction. The UPDATE that
     * changed it still holds the row lock, so what comes back is our own
     * result and not another request's.
     */
    private static function record(int $accountId, float $amount, string $kind, array $meta): float
    {
        $channel = $meta['channel'] ?? 'sms';
        $column  = self::column($channel);

        $after = (float) Database::scalar(
            "SELECT {$column} FROM bulk_accounts WHERE id = :id",
            ['id' => $accountId]
        );

        $actor = $meta['actor_type'] ?? 'system';

        if (!in_array($actor, self::ACTORS, true)) {
            $actor = 'system';
        }

        Database::insert('bulk_ledger', [
            'account_id'      => $accountId,
            'kind'            => $kind,
            'channel'         => $channel,
            'amount'          => round($amount, 4),
            'balance_after'   => round($after, 4),
            'counterparty_id' => $meta['counterparty_id'] ?? null,
            'ref_type'        => $meta['ref_type'] ?? null,
            'ref_id'          => $meta['ref_id'] ?? null,
            'note'            => isset($meta['note']) ? mb_substr((string) $meta['note'], 0, 255) : null,
            'actor_type'      => $actor,
            'actor_id'        => $meta['actor_id'] ?? null,
        ]);

        return $after;
    }

    private static function column(string $channel): string
    {
        return self::CHANNEL_COLUMNS[$channel]
            ?? throw new RuntimeException('Unknown channel: ' . $channel);
    }

    /**
     * Run inside a transaction — joining the caller's if there is one.
     *
     * Database::transaction() cannot be nested: an inner one would commit
     * the outer halfway through, which for move() would mean the debit
     * committed and the credit left to chance.
     */
    private static function atomically(callable $fn): mixed
    {
        $pdo = Database::pdo();

        if ($pdo->inTransaction()) {
            return $fn();
        }

        $pdo->beginTransaction();

        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
