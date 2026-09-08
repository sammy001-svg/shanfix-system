<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * What a partner has earned, and when.
 *
 * Three rules, decided once and enforced only here:
 *
 *   1. A CUSTOMER belongs to a partner. Every invoice to a tagged client
 *      earns commission, including repeat work years later.
 *
 *   2. It is earned WHEN THE CUSTOMER PAYS. A part payment earns a
 *      proportional part. We never owe commission on money we have not
 *      collected, so a bad debt costs us the sale and not the sale plus
 *      the commission.
 *
 *   3. The rate is per service where one is set, falling back to the
 *      partner's default for everything else.
 *
 * The ledger is DERIVED, never accumulated. sync() recomputes what a
 * partner should have earned on an invoice from the payments actually
 * recorded against it, and writes only the difference. That is what makes
 * a reversed payment take its commission back with it without anyone
 * remembering to do it, and what stops rounding drifting over a dozen
 * part payments: the running total is corrected on every write rather
 * than being the sum of independently rounded slices.
 *
 * Commission is worked out on the VAT-exclusive value, net of any
 * discount. Paying a partner a share of the VAT would be paying them a
 * share of money that belongs to KRA.
 */
class Commission
{
    /**
     * Recompute the commission ledger for one invoice.
     *
     * Safe to call as often as you like, and called from the one place a
     * payment can change an invoice.
     */
    public static function sync(int $documentId): void
    {
        try {
            $doc = Database::first(
                "SELECT d.id, d.client_id, d.doc_type, d.status, d.total,
                        d.subtotal, d.discount_amount, d.amount_paid,
                        c.partner_id
                   FROM documents d
              LEFT JOIN clients c ON c.id = d.client_id
                  WHERE d.id = :id",
                ['id' => $documentId]
            );

            if (!$doc || $doc['doc_type'] !== 'invoice') {
                return;
            }

            $partnerId = $doc['partner_id'] ? (int) $doc['partner_id'] : null;

            // No partner, or the invoice was cancelled: nothing is owed, and
            // anything previously earned on it is withdrawn.
            if ($partnerId === null || $doc['status'] === 'cancelled') {
                self::voidFor($documentId);
                return;
            }

            $partner = Database::first(
                'SELECT id, default_rate, status FROM partners WHERE id = :id',
                ['id' => $partnerId]
            );

            // A partner we have since rejected keeps what they already
            // earned, but a suspended or refused one earns nothing new.
            if (!$partner || in_array($partner['status'], ['rejected'], true)) {
                self::voidFor($documentId);
                return;
            }

            [$base, $commissionTotal] = self::valueOf($documentId, $partnerId, $doc);

            $total = (float) $doc['total'];
            $paid  = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM payments
                  WHERE document_id = :id AND status = 'completed'",
                ['id' => $documentId],
                0
            );

            // The share of the commission the customer has actually paid
            // for. Computed against the running total rather than payment
            // by payment, so the ledger cannot drift by a cent a time.
            // A client can be moved from one partner to another. Anything
            // the previous partner earned on this invoice and has not been
            // paid is withdrawn; what they have already been paid stays
            // theirs, because that money has gone. The new partner then
            // earns against the same invoice from a clean slate.
            Database::run(
                "UPDATE commissions SET status = 'void',
                        notes = 'Withdrawn: this customer was moved to another partner'
                  WHERE document_id = :id AND status = 'earned' AND partner_id <> :p",
                ['id' => $documentId, 'p' => $partnerId]
            );

            // The ledger is kept in SHARES of the invoice, not in money.
            //
            // Recomputing the money instead looks equivalent and is not: it
            // prices the whole invoice at today's rates every time, so
            // moving a rate re-prices commission that was earned months ago
            // and already agreed. A partner who earned 3% on a paid invoice
            // would find themselves on 50% of it because somebody changed a
            // rate for future work.
            //
            // So each row records the slice of the invoice it covers, in
            // base_amount. What is already earned keeps the rate it was
            // earned at, and only the slice that is genuinely new is priced
            // at today's rates.
            $paidShare = $total > 0.009 ? min(1.0, $paid / $total) : 0.0;

            $earnedBase = (float) Database::scalar(
                "SELECT COALESCE(SUM(base_amount), 0) FROM commissions
                  WHERE document_id = :id AND partner_id = :p AND status <> 'void'",
                ['id' => $documentId, 'p' => $partnerId],
                0
            );

            $earnedShare = $base > 0.009 ? $earnedBase / $base : 0.0;
            $deltaShare  = $paidShare - $earnedShare;

            // A hundredth of a percent of an invoice is not worth a row.
            if (abs($deltaShare) < 0.0001) {
                return;
            }

            if ($deltaShare > 0) {
                $sliceBase = round($base * $deltaShare, 2);
                $amount    = round($commissionTotal * $deltaShare, 2);

                if ($amount < 0.005 && $sliceBase < 0.005) {
                    return;
                }

                Database::insert('commissions', [
                    'partner_id'  => $partnerId,
                    'client_id'   => (int) $doc['client_id'],
                    'document_id' => $documentId,
                    'payment_id'  => self::latestPaymentId($documentId),
                    // The slice this row covers, not the whole invoice, so
                    // the shares add up and a statement can show what each
                    // entry was worked out on.
                    'base_amount' => $sliceBase,
                    'rate'        => $sliceBase > 0.009 ? round($amount / $sliceBase * 100, 2) : 0.0,
                    'amount'      => $amount,
                    // The month it was earned in — the month the customer
                    // paid us — because that is the month we owe it for and
                    // the month both sides will reconcile against.
                    'period'      => date('Y-m'),
                    'status'      => 'earned',
                ]);

                return;
            }

            // The customer's payment was reversed, so some of what was
            // earned has to come back. Anything already paid out to the
            // partner is left alone — that money has gone, and clawing it
            // back is a conversation, not a database write.
            self::clawBack($documentId, $partnerId, round($base * -$deltaShare, 2));
        } catch (\Throwable $e) {
            // Commission must never be able to break payment recording.
            Logger::error('Commission sync failed: ' . $e->getMessage(), [
                'document_id' => $documentId,
            ]);
        }
    }

    /**
     * The rate one partner earns on one thing.
     *
     * Three places a rate can come from, checked most specific first:
     *
     *   1. this partner, this thing   an override they negotiated
     *   2. this thing, any partner    the service's own rate
     *   3. this partner, anything     their default
     *
     * Zero is a real answer at every level and means "earns nothing on
     * this"; it is absence, not zero, that falls through to the next rule.
     *
     * @param array<string,float> $overrides keyed "type:refId"
     */
    public static function rateFor(
        array $overrides,
        string $itemType,
        ?int $refId,
        ?float $serviceRate,
        float $default
    ): float {
        $key = $itemType . ':' . (int) $refId;

        if ($refId !== null && array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        if ($serviceRate !== null) {
            return (float) $serviceRate;
        }

        return $default;
    }

    /**
     * Every rate one partner has negotiated, keyed "type:refId".
     *
     * @return array<string,float>
     */
    public static function overridesFor(int $partnerId): array
    {
        $rows = Database::all(
            'SELECT item_type, ref_id, rate FROM partner_rates WHERE partner_id = :p',
            ['p' => $partnerId]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[$row['item_type'] . ':' . (int) $row['ref_id']] = (float) $row['rate'];
        }

        return $out;
    }

    /**
     * What an invoice is worth in commission if it were paid in full.
     *
     * Takes the partner rather than a bare rate, because what a line pays
     * now depends on who introduced the customer as well as what the line
     * is.
     *
     * @return array{0: float, 1: float} the commissionable base, and the commission on it
     */
    public static function valueOf(int $documentId, int $partnerId, ?array $doc = null): array
    {
        $doc ??= Database::first(
            'SELECT subtotal, discount_amount FROM documents WHERE id = :id',
            ['id' => $documentId]
        );

        if (!$doc) {
            return [0.0, 0.0];
        }

        $default = (float) Database::scalar(
            'SELECT default_rate FROM partners WHERE id = :p',
            ['p' => $partnerId],
            0
        );

        $overrides = self::overridesFor($partnerId);

        $items = Database::all(
            "SELECT i.item_type, i.ref_id, i.line_total, s.commission_rate
               FROM document_items i
          LEFT JOIN services s ON s.id = i.ref_id AND i.item_type = 'service'
              WHERE i.document_id = :id",
            ['id' => $documentId]
        );

        $lines = 0.0;
        foreach ($items as $item) {
            $lines += (float) $item['line_total'];
        }

        if ($lines <= 0.009) {
            return [0.0, 0.0];
        }

        // A discount is taken off the invoice as a whole, so it comes off
        // every line in proportion. Without this a discounted invoice pays
        // commission on the undiscounted price.
        $discount = (float) ($doc['discount_amount'] ?? 0);
        $netFactor = $discount > 0.009 ? max(0.0, ($lines - $discount) / $lines) : 1.0;

        $base       = 0.0;
        $commission = 0.0;

        foreach ($items as $item) {
            $net = (float) $item['line_total'] * $netFactor;

            $rate = self::rateFor(
                $overrides,
                (string) $item['item_type'],
                $item['ref_id'] === null ? null : (int) $item['ref_id'],
                $item['commission_rate'] === null ? null : (float) $item['commission_rate'],
                $default
            );

            $base       += $net;
            $commission += $net * $rate / 100;
        }

        return [round($base, 2), round($commission, 2)];
    }

    /** Withdraw everything not yet paid out on an invoice. */
    private static function voidFor(int $documentId): void
    {
        Database::run(
            "UPDATE commissions SET status = 'void'
              WHERE document_id = :id AND status = 'earned'",
            ['id' => $documentId]
        );
    }

    /**
     * Take back a slice of the invoice from what a partner earned on it.
     *
     * Measured in base rather than money, because that is what the ledger
     * is kept in — and because a row must keep the rate it was earned at
     * even while it shrinks. Newest first, and only what has not been paid
     * out: money that has gone is a conversation, not a database write.
     *
     * @param float $baseToRemove the slice, as a share of the invoice base
     */
    private static function clawBack(int $documentId, int $partnerId, float $baseToRemove): void
    {
        $rows = Database::all(
            "SELECT id, base_amount, amount FROM commissions
              WHERE document_id = :id AND partner_id = :p AND status = 'earned'
           ORDER BY id DESC",
            ['id' => $documentId, 'p' => $partnerId]
        );

        $left = $baseToRemove;

        foreach ($rows as $row) {
            if ($left < 0.005) {
                break;
            }

            $rowBase   = (float) $row['base_amount'];
            $rowAmount = (float) $row['amount'];

            if ($rowBase <= $left + 0.005) {
                Database::update('commissions', [
                    'status' => 'void',
                    'notes'  => 'Withdrawn: the payment behind it was reversed',
                ], ['id' => $row['id']]);

                $left -= $rowBase;
                continue;
            }

            // Part of this row survives. It keeps its rate, so the money
            // shrinks in proportion to the slice rather than being
            // recomputed at whatever the rate is today.
            $keepBase = round($rowBase - $left, 2);
            $keepRate = $rowBase > 0.009 ? $rowAmount / $rowBase : 0.0;

            Database::update('commissions', [
                'base_amount' => $keepBase,
                'amount'      => round($keepBase * $keepRate, 2),
                'notes'       => 'Reduced: part of the payment behind it was reversed',
            ], ['id' => $row['id']]);

            $left = 0.0;
        }
    }

    /** The most recent completed payment on an invoice, for the audit trail. */
    private static function latestPaymentId(int $documentId): ?int
    {
        $row = Database::first(
            "SELECT id FROM payments
              WHERE document_id = :id AND status = 'completed'
           ORDER BY id DESC LIMIT 1",
            ['id' => $documentId]
        );

        return $row ? (int) $row['id'] : null;
    }

    /**
     * What a partner is owed, has been paid, and has coming.
     *
     * @return array{earned:float, paid:float, due:float, clients:int, invoices:int}
     */
    public static function summaryFor(int $partnerId): array
    {
        $row = Database::first(
            "SELECT COALESCE(SUM(CASE WHEN status IN ('earned','paid') THEN amount END), 0) AS earned,
                    COALESCE(SUM(CASE WHEN status = 'paid'   THEN amount END), 0)           AS paid,
                    COALESCE(SUM(CASE WHEN status = 'earned' THEN amount END), 0)           AS due,
                    COUNT(DISTINCT document_id)                                             AS invoices
               FROM commissions
              WHERE partner_id = :p AND status <> 'void'",
            ['p' => $partnerId]
        ) ?: [];

        return [
            'earned'   => (float) ($row['earned'] ?? 0),
            'paid'     => (float) ($row['paid'] ?? 0),
            'due'      => (float) ($row['due'] ?? 0),
            'invoices' => (int) ($row['invoices'] ?? 0),
            'clients'  => (int) Database::scalar(
                'SELECT COUNT(*) FROM clients WHERE partner_id = :p',
                ['p' => $partnerId],
                0
            ),
        ];
    }

    /**
     * What a partner earned, month by month.
     *
     * Commission is paid monthly, so the month is the unit both sides
     * reconcile in — this is the same grouping the payout run uses and
     * the one the partner sees, rather than two summaries that can
     * disagree.
     *
     * @return list<array<string,mixed>>
     */
    public static function byMonth(int $partnerId, int $limit = 24): array
    {
        return Database::all(
            "SELECT period,
                    COALESCE(SUM(CASE WHEN status = 'earned' THEN amount END), 0) AS due,
                    COALESCE(SUM(CASE WHEN status = 'paid'   THEN amount END), 0) AS paid,
                    COALESCE(SUM(amount), 0)                                      AS total,
                    COUNT(*)                                                      AS entries,
                    MIN(paid_at)                                                  AS paid_at,
                    MAX(payout_ref)                                               AS payout_ref
               FROM commissions
              WHERE partner_id = :p AND status <> 'void' AND period IS NOT NULL
           GROUP BY period
           ORDER BY period DESC
              LIMIT " . max(1, $limit),
            ['p' => $partnerId]
        );
    }

    /**
     * Everyone owed something for a given month.
     *
     * @return list<array<string,mixed>>
     */
    public static function runFor(string $period): array
    {
        return Database::all(
            "SELECT p.id, p.partner_code, p.name, p.company, p.email, p.phone,
                    p.kra_pin, p.status,
                    COALESCE(SUM(CASE WHEN cm.status = 'earned' THEN cm.amount END), 0) AS due,
                    COALESCE(SUM(CASE WHEN cm.status = 'paid'   THEN cm.amount END), 0) AS paid,
                    COUNT(*)                                                            AS entries
               FROM commissions cm
               JOIN partners p ON p.id = cm.partner_id
              WHERE cm.period = :period AND cm.status <> 'void'
           GROUP BY p.id, p.partner_code, p.name, p.company, p.email, p.phone, p.kra_pin, p.status
           ORDER BY due DESC, p.name",
            ['period' => $period]
        );
    }

    /** The months that have any commission in them at all, newest first. */
    public static function periods(int $limit = 24): array
    {
        $rows = Database::all(
            "SELECT period FROM commissions
              WHERE status <> 'void' AND period IS NOT NULL
           GROUP BY period ORDER BY period DESC LIMIT " . max(1, $limit)
        );

        return array_column($rows, 'period');
    }

    /**
     * Rebuild the ledger for every invoice belonging to one client.
     *
     * Used when a client is tagged to a partner after the fact, or moved
     * from one partner to another: the commission history has to follow
     * the tag, or the books say one thing and the portal another.
     */
    public static function resyncClient(int $clientId): int
    {
        $ids = Database::all(
            "SELECT id FROM documents
              WHERE client_id = :c AND doc_type = 'invoice' AND status <> 'draft'",
            ['c' => $clientId]
        );

        foreach ($ids as $row) {
            self::sync((int) $row['id']);
        }

        return count($ids);
    }
}
