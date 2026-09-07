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

            [$base, $commissionTotal] = self::valueOf($documentId, (float) $partner['default_rate'], $doc);

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
            $share  = $total > 0.009 ? min(1.0, $paid / $total) : 0.0;
            $target = round($commissionTotal * $share, 2);

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

            // What has already been written for THIS partner, ignoring
            // anything voided. Counting another partner's rows here is what
            // would leave the new one earning nothing at all.
            $already = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM commissions
                  WHERE document_id = :id AND partner_id = :p AND status <> 'void'",
                ['id' => $documentId, 'p' => $partnerId],
                0
            );

            $delta = round($target - $already, 2);

            if (abs($delta) < 0.005) {
                return;
            }

            $effectiveRate = $base > 0.009 ? round($commissionTotal / $base * 100, 2) : 0.0;

            if ($delta > 0) {
                Database::insert('commissions', [
                    'partner_id'  => $partnerId,
                    'client_id'   => (int) $doc['client_id'],
                    'document_id' => $documentId,
                    'payment_id'  => self::latestPaymentId($documentId),
                    'base_amount' => $base,
                    'rate'        => $effectiveRate,
                    'amount'      => $delta,
                    'status'      => 'earned',
                ]);

                return;
            }

            // The customer's payment was reversed, so some of what was
            // earned has to come back. Anything already paid out to the
            // partner is left alone — that money has gone, and clawing it
            // back is a conversation, not a database write.
            self::clawBack($documentId, $partnerId, abs($delta));
        } catch (\Throwable $e) {
            // Commission must never be able to break payment recording.
            Logger::error('Commission sync failed: ' . $e->getMessage(), [
                'document_id' => $documentId,
            ]);
        }
    }

    /**
     * What an invoice is worth in commission if it were paid in full.
     *
     * @return array{0: float, 1: float} the commissionable base, and the commission on it
     */
    public static function valueOf(int $documentId, float $defaultRate, ?array $doc = null): array
    {
        $doc ??= Database::first(
            'SELECT subtotal, discount_amount FROM documents WHERE id = :id',
            ['id' => $documentId]
        );

        if (!$doc) {
            return [0.0, 0.0];
        }

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

            // Null means "no rate of its own, use the partner's". Zero is a
            // real answer and means this line pays nothing, which is why the
            // two cannot be the same value.
            $rate = $item['commission_rate'] === null
                ? $defaultRate
                : (float) $item['commission_rate'];

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
     * Take back $amount of commission earned on this invoice.
     *
     * Newest first, and only what has not been paid out. A row is voided
     * whole where it can be and trimmed where it cannot, so the ledger
     * still sums to what is genuinely owed.
     */
    private static function clawBack(int $documentId, int $partnerId, float $amount): void
    {
        $rows = Database::all(
            "SELECT id, amount FROM commissions
              WHERE document_id = :id AND partner_id = :p AND status = 'earned'
           ORDER BY id DESC",
            ['id' => $documentId, 'p' => $partnerId]
        );

        $left = $amount;

        foreach ($rows as $row) {
            if ($left < 0.005) {
                break;
            }

            $rowAmount = (float) $row['amount'];

            if ($rowAmount <= $left + 0.005) {
                Database::update('commissions', [
                    'status' => 'void',
                    'notes'  => 'Withdrawn: the payment behind it was reversed',
                ], ['id' => $row['id']]);

                $left -= $rowAmount;
                continue;
            }

            Database::update('commissions', [
                'amount' => round($rowAmount - $left, 2),
                'notes'  => 'Reduced: part of the payment behind it was reversed',
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
