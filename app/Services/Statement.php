<?php
namespace App\Services;

use App\Core\Database;

/**
 * A client's account, as a statement.
 *
 * Every invoice raised and every payment received, in date order, with a
 * running balance — plus an ageing summary of what is still owed and how
 * long it has been owed for.
 *
 * The same builder serves the staff view and the client's share link, so
 * the figures a client sees can never drift from the ones the office is
 * looking at.
 */
class Statement
{
    /**
     * Documents that never belong on a statement.
     *
     * A draft was never issued, a cancelled one was withdrawn, and a
     * receipt is an acknowledgement of a payment already counted as a
     * credit — including it would show the money twice.
     */
    private const EXCLUDED_STATUSES = ['draft', 'cancelled'];

    /**
     * @return array{
     *   client: array, from: ?string, to: string, opening: float,
     *   rows: array<int,array>, closing: float, invoiced: float, paid: float,
     *   ageing: array<string,float>, ageing_total: float, open_invoices: array
     * }
     */
    public static function build(array $client, ?string $from = null, ?string $to = null): array
    {
        $clientId = (int) $client['id'];
        $to     ??= date('Y-m-d');

        $opening = $from !== null ? self::openingBalance($clientId, $from) : 0.0;

        $rows     = [];
        $invoiced = 0.0;
        $paid     = 0.0;

        foreach (self::invoices($clientId, $from, $to) as $invoice) {
            $invoiced += (float) $invoice['total'];

            $rows[] = [
                'date'        => $invoice['issue_date'],
                'sort'        => $invoice['issue_date'] . ' 0 ' . str_pad((string) $invoice['id'], 10, '0', STR_PAD_LEFT),
                'type'        => 'invoice',
                'ref'         => $invoice['doc_number'],
                'link_id'     => (int) $invoice['id'],
                'description' => $invoice['title'] ?: 'Invoice',
                'due_date'    => $invoice['due_date'],
                'debit'       => (float) $invoice['total'],
                'credit'      => 0.0,
            ];
        }

        foreach (self::payments($clientId, $from, $to) as $payment) {
            $paid += (float) $payment['amount'];

            $rows[] = [
                'date'        => substr((string) ($payment['paid_at'] ?: $payment['created_at']), 0, 10),
                'sort'        => substr((string) ($payment['paid_at'] ?: $payment['created_at']), 0, 10)
                                 . ' 1 ' . str_pad((string) $payment['id'], 10, '0', STR_PAD_LEFT),
                'type'        => 'payment',
                'ref'         => $payment['payment_number'],
                'link_id'     => null,
                'description' => self::paymentLabel($payment),
                'due_date'    => null,
                'debit'       => 0.0,
                'credit'      => (float) $payment['amount'],
            ];
        }

        // Same-day entries: invoice first, then the payment against it, so
        // the running balance never dips below zero and back.
        usort($rows, static fn(array $a, array $b): int => strcmp($a['sort'], $b['sort']));

        $balance = $opening;
        foreach ($rows as $i => $row) {
            $balance += $row['debit'] - $row['credit'];
            $rows[$i]['balance'] = $balance;
        }

        $open = self::openInvoices($clientId);

        return [
            'client'       => $client,
            'from'         => $from,
            'to'           => $to,
            'opening'      => $opening,
            'rows'         => $rows,
            'closing'      => $balance,
            'invoiced'     => $invoiced,
            'paid'         => $paid,
            'ageing'       => self::ageing($open),
            'ageing_total' => array_sum(self::ageing($open)),
            'open_invoices' => $open,
        ];
    }

    /** Everything owed before the statement period began, as one figure. */
    private static function openingBalance(int $clientId, string $from): float
    {
        $billed = (float) Database::scalar(
            "SELECT COALESCE(SUM(total), 0) FROM documents
              WHERE client_id = :id AND doc_type = 'invoice'
                AND status NOT IN ('draft','cancelled') AND issue_date < :from",
            ['id' => $clientId, 'from' => $from],
            0
        );

        $received = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
              WHERE client_id = :id AND status = 'completed'
                AND DATE(COALESCE(paid_at, created_at)) < :from",
            ['id' => $clientId, 'from' => $from],
            0
        );

        return round($billed - $received, 2);
    }

    private static function invoices(int $clientId, ?string $from, ?string $to): array
    {
        $where  = ["client_id = :id", "doc_type = 'invoice'", "issue_date <= :to"];
        $params = ['id' => $clientId, 'to' => $to];

        $where[] = "status NOT IN ('" . implode("','", self::EXCLUDED_STATUSES) . "')";

        if ($from !== null) {
            $where[] = 'issue_date >= :from';
            $params['from'] = $from;
        }

        return Database::all(
            'SELECT id, doc_number, title, issue_date, due_date, total, balance, status
               FROM documents WHERE ' . implode(' AND ', $where) . ' ORDER BY issue_date, id',
            $params
        );
    }

    private static function payments(int $clientId, ?string $from, ?string $to): array
    {
        $where  = ["p.client_id = :id", "p.status = 'completed'",
                   "DATE(COALESCE(p.paid_at, p.created_at)) <= :to"];
        $params = ['id' => $clientId, 'to' => $to];

        if ($from !== null) {
            $where[] = 'DATE(COALESCE(p.paid_at, p.created_at)) >= :from';
            $params['from'] = $from;
        }

        return Database::all(
            'SELECT p.id, p.payment_number, p.amount, p.method, p.reference,
                    p.paid_at, p.created_at, d.doc_number AS against
               FROM payments p
          LEFT JOIN documents d ON d.id = p.document_id
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY COALESCE(p.paid_at, p.created_at), p.id',
            $params
        );
    }

    /** Invoices still carrying a balance, whatever the statement period. */
    private static function openInvoices(int $clientId): array
    {
        return Database::all(
            "SELECT id, doc_number, issue_date, due_date, total, balance
               FROM documents
              WHERE client_id = :id AND doc_type = 'invoice'
                AND status NOT IN ('draft','cancelled') AND balance > 0.004
           ORDER BY COALESCE(due_date, issue_date)",
            ['id' => $clientId]
        );
    }

    /**
     * Outstanding money split by how overdue it is.
     *
     * Measured from the due date, falling back to the issue date when an
     * invoice has none — an invoice with no terms is due on issue.
     *
     * @return array<string,float>
     */
    private static function ageing(array $openInvoices): array
    {
        $buckets = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '90_plus' => 0.0];
        $today   = strtotime('today');

        foreach ($openInvoices as $invoice) {
            $balance = (float) $invoice['balance'];
            $due     = strtotime((string) ($invoice['due_date'] ?: $invoice['issue_date']));

            if ($due === false) {
                $buckets['current'] += $balance;
                continue;
            }

            $days = (int) floor(($today - $due) / 86400);

            $key = match (true) {
                $days <= 0  => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default     => '90_plus',
            };

            $buckets[$key] += $balance;
        }

        return array_map(static fn(float $v): float => round($v, 2), $buckets);
    }

    private static function paymentLabel(array $payment): string
    {
        $method = match ((string) ($payment['method'] ?? '')) {
            'mpesa_stk', 'mpesa_manual' => 'M-Pesa',
            'bank'   => 'Bank transfer',
            'cash'   => 'Cash',
            'cheque' => 'Cheque',
            default  => 'Payment',
        };

        $parts = [$method . ' received'];

        if (!empty($payment['against'])) {
            $parts[] = 'for ' . $payment['against'];
        }

        if (!empty($payment['reference'])) {
            $parts[] = '(' . $payment['reference'] . ')';
        }

        return implode(' ', $parts);
    }

    /** Mint the share token the first time a client statement is sent out. */
    public static function ensureToken(int $clientId, ?string $existing = null): string
    {
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(24));

        Database::update('clients', ['public_token' => $token], ['id' => $clientId]);

        return $token;
    }
}
