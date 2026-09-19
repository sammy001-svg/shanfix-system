<?php
namespace App\Services\BulkSms;

use App\Core\Database;
use App\Core\Logger;
use RuntimeException;

/**
 * Buying units.
 *
 * A purchase is recorded pending, and completing it is what moves the
 * units: out of the seller's balance, into the buyer's, in one
 * transaction. The seller is the house for a direct client or a partner,
 * and the partner for one of a partner's own clients — fixed on the
 * purchase when it is made.
 *
 * Completing is idempotent. An M-Pesa confirmation can arrive twice (the
 * webhook, then the poll that checks on it), and the row is claimed with
 * a locking read so the second arrival finds it already done and changes
 * nothing. Paying twice for one purchase is impossible; crediting twice
 * for one payment is what this prevents.
 */
final class Purchases
{
    public const METHODS = [
        'mpesa_stk'     => 'M-Pesa (paid on the phone)',
        'manual_mpesa'  => 'M-Pesa (paid to till, confirmed by hand)',
        'bank'          => 'Bank transfer',
        'cash'          => 'Cash',
        'complimentary' => 'Complimentary',
    ];

    /**
     * Price and record a purchase. Nothing moves yet.
     *
     * @param array{plan_id?:int, units?:float, amount?:float, method?:string, phone?:string,
     *              transaction_ref?:string, channel?:string, actor_type?:string, actor_id?:int} $data
     * @return array{ok:bool, id?:int, error?:string, amount?:float, units?:float}
     */
    public static function create(int $accountId, array $data): array
    {
        $account = Accounts::find($accountId);

        if ($account === null || $account['owner_type'] === 'house') {
            return ['ok' => false, 'error' => 'That account cannot buy units.'];
        }

        $seller  = Accounts::find((int) $account['parent_id']);
        $channel = $data['channel'] ?? 'sms';
        $method  = $data['method'] ?? 'mpesa_stk';

        if ($seller === null) {
            return ['ok' => false, 'error' => 'This account has no supplier to buy from.'];
        }

        if (!isset(self::METHODS[$method])) {
            return ['ok' => false, 'error' => 'Unknown payment method.'];
        }

        $planId = !empty($data['plan_id']) ? (int) $data['plan_id'] : null;
        $unitPrice = null;

        if ($channel !== 'sms') {
            // WhatsApp and USSD are wallets of money, not units.
            $amount = round((float) ($data['amount'] ?? 0), 2);
            $units  = 0.0;

            if ($amount <= 0) {
                return ['ok' => false, 'error' => 'Enter an amount to top up.'];
            }
        } elseif ($planId !== null) {
            // A plan from the seller's own list: a partner's clients buy
            // from the partner's plans, everybody else from ours.
            $plan = Database::first(
                'SELECT * FROM bulk_plans WHERE id = :id AND owner_account_id = :o AND is_active = 1',
                ['id' => $planId, 'o' => $seller['id']]
            );

            if ($plan === null) {
                return ['ok' => false, 'error' => 'That plan is not available.'];
            }

            $units  = (float) $plan['units'];
            $amount = (float) $plan['price'];
            $unitPrice = $units > 0 ? round($amount / $units, 4) : null;
        } else {
            $units = round((float) ($data['units'] ?? 0), 4);

            if ($units <= 0) {
                return ['ok' => false, 'error' => 'Enter how many units to buy.'];
            }

            $unitPrice = Accounts::unitPrice($account);
            $amount    = round($units * $unitPrice, 2);

            // An amount given explicitly (an office recording a deal) wins
            // over the computed one.
            if (isset($data['amount']) && (float) $data['amount'] > 0) {
                $amount = round((float) $data['amount'], 2);
            }
        }

        if ($method === 'complimentary') {
            $amount = 0.0;
        }

        // Refused up front when the seller could not deliver, rather than
        // taking the customer's money for units nobody has.
        if ($channel === 'sms' && $seller['owner_type'] !== 'house' && (float) $seller['sms_units'] < $units) {
            return ['ok' => false, 'error' => 'Your supplier does not have enough units to sell right now. Please try a smaller amount or contact them.'];
        }

        $id = Database::insert('bulk_purchases', [
            'account_id'        => $accountId,
            'seller_account_id' => $seller['id'],
            'channel'           => $channel,
            'plan_id'           => $planId,
            'units'             => $units,
            'amount'            => $amount,
            'unit_price'        => $unitPrice,
            'method'            => $method,
            'phone'             => $data['phone'] ?? null,
            'transaction_ref'   => isset($data['transaction_ref']) && $data['transaction_ref'] !== ''
                                   ? mb_substr(strtoupper(trim((string) $data['transaction_ref'])), 0, 100) : null,
            'actor_type'        => $data['actor_type'] ?? 'system',
            'actor_id'          => $data['actor_id'] ?? null,
        ]);

        return ['ok' => true, 'id' => $id, 'amount' => $amount, 'units' => $units];
    }

    /**
     * Hand the units over. Safe to call more than once for one purchase.
     *
     * @param array{type:string, id:?int} $by who decided: staff, partner or system
     * @return array{ok:bool, error?:string, already?:bool}
     */
    public static function complete(int $purchaseId, array $by = ['type' => 'system', 'id' => null], ?string $ref = null): array
    {
        $pdo   = Database::pdo();
        $owned = !$pdo->inTransaction();

        if ($owned) {
            $pdo->beginTransaction();
        }

        try {
            $p = Database::first(
                "SELECT * FROM bulk_purchases WHERE id = :id FOR UPDATE",
                ['id' => $purchaseId]
            );

            if ($p === null) {
                throw new RuntimeException('There is no such purchase.');
            }

            if ($p['status'] !== 'pending') {
                if ($owned) {
                    $pdo->rollBack();
                }

                return ['ok' => $p['status'] === 'completed', 'already' => true,
                        'error' => $p['status'] === 'completed' ? null : 'That purchase is ' . $p['status'] . '.'];
            }

            $meta = [
                'ref_type'   => 'purchase',
                'ref_id'     => $purchaseId,
                'actor_type' => $by['type'] === 'partner' ? 'partner' : ($by['type'] === 'staff' ? 'staff' : 'system'),
                'actor_id'   => $by['id'] ?? null,
                'note'       => self::METHODS[$p['method']] . ($ref ? ' · ' . $ref : ($p['transaction_ref'] ? ' · ' . $p['transaction_ref'] : '')),
            ];

            if ($p['channel'] === 'sms') {
                $moved = Wallet::move(
                    (int) $p['seller_account_id'],
                    (int) $p['account_id'],
                    (float) $p['units'],
                    'sale',
                    'purchase',
                    $meta
                );

                if (!$moved) {
                    if ($owned) {
                        $pdo->rollBack();
                    }

                    // Left pending, not failed: the money may well have been
                    // paid, and the fix is for the seller to top up and
                    // complete it — not for the customer to pay again.
                    $seller = Accounts::find((int) $p['seller_account_id']);
                    Logger::warning('Bulk SMS purchase #' . $purchaseId . ' waiting: the seller is short of units.');

                    return ['ok' => false, 'error' => $seller && $seller['owner_type'] === 'house'
                        ? 'The house balance is too low to hand these units over. Sync or top up the gateway balance, then complete it.'
                        : 'There are not enough units to hand these over. Top up first, then approve it.'];
                }
            } else {
                Wallet::credit((int) $p['account_id'], (float) $p['amount'], 'purchase', $meta + ['channel' => $p['channel']]);
            }

            Database::run(
                "UPDATE bulk_purchases
                    SET status = 'completed', completed_at = NOW(),
                        transaction_ref = COALESCE(:ref, transaction_ref),
                        decided_by_type = :t, decided_by_id = :d
                  WHERE id = :id",
                ['ref' => $ref ? mb_substr(strtoupper($ref), 0, 100) : null,
                 't' => in_array($by['type'], ['staff', 'partner', 'system'], true) ? $by['type'] : 'system',
                 'd' => $by['id'] ?? null, 'id' => $purchaseId]
            );

            if ($owned) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owned && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Alerts::toppedUp($purchaseId);
        Alerts::lowBalanceCheck((int) $p['account_id']);

        return ['ok' => true];
    }

    /** Refuse a pending purchase. Nothing had moved, so nothing moves back. */
    public static function fail(int $purchaseId, string $reason, array $by = ['type' => 'system', 'id' => null]): bool
    {
        return Database::run(
            "UPDATE bulk_purchases
                SET status = 'failed', failure_reason = :r, decided_by_type = :t, decided_by_id = :d
              WHERE id = :id AND status = 'pending'",
            ['r' => mb_substr($reason, 0, 255),
             't' => in_array($by['type'], ['staff', 'partner', 'system'], true) ? $by['type'] : 'system',
             'd' => $by['id'] ?? null, 'id' => $purchaseId]
        )->rowCount() > 0;
    }
}
