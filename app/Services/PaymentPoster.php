<?php
namespace App\Services;

use App\Core\ActivityLog;
use App\Core\Database;
use App\Core\Numbering;

/**
 * The one place a payment is written and an invoice balance is moved.
 *
 * Manual entry and KopoKopo callbacks both come through here so an invoice
 * can never end up with a balance that disagrees with its payments.
 */
class PaymentPoster
{
    /**
     * Record a completed payment and re-derive the invoice balance.
     *
     * @param int|null $documentId Invoice to settle; null for an on-account payment
     *
     * @return array{payment_id:int, payment_number:string}
     */
    public static function post(
        int $clientId,
        float $amount,
        string $method,
        ?int $documentId = null,
        ?string $reference = null,
        ?string $notes = null,
        ?int $recordedBy = null,
        ?string $paidAt = null,
        string $status = 'completed'
    ): array {
        return Database::transaction(static function () use (
            $clientId, $amount, $method, $documentId, $reference, $notes, $recordedBy, $paidAt, $status
        ) {
            $paymentNumber = Numbering::next('payment');

            $paymentId = Database::insert('payments', [
                'payment_number' => $paymentNumber,
                'document_id'    => $documentId,
                'client_id'      => $clientId,
                'amount'         => $amount,
                'method'         => $method,
                'reference'      => $reference,
                'status'         => $status,
                'paid_at'        => $paidAt ?? date('Y-m-d H:i:s'),
                'notes'          => $notes,
                'recorded_by'    => $recordedBy,
            ]);

            if ($documentId !== null && $status === 'completed') {
                self::refreshInvoice($documentId);
            }

            return ['payment_id' => $paymentId, 'payment_number' => $paymentNumber];
        });
    }

    /**
     * Recompute amount_paid, balance and status for an invoice from the
     * payments actually recorded against it.
     */
    public static function refreshInvoice(int $documentId): void
    {
        $doc = Database::first(
            'SELECT id, total, due_date, status, doc_type FROM documents WHERE id = :id',
            ['id' => $documentId]
        );

        if (!$doc || $doc['doc_type'] !== 'invoice') {
            return;
        }

        $paid = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
              WHERE document_id = :id AND status = 'completed'",
            ['id' => $documentId],
            0
        );

        $total   = (float) $doc['total'];
        $paid    = DocumentCalculator::round($paid);
        $balance = DocumentCalculator::round(max(0, $total - $paid));

        $status = DocumentCalculator::invoiceStatus($total, $paid, $doc['due_date'], $doc['status']);

        Database::update('documents', [
            'amount_paid' => $paid,
            'balance'     => $balance,
            'status'      => $status,
        ], ['id' => $documentId]);
    }

    /**
     * Reverse a payment: mark it cancelled and put the balance back.
     */
    public static function reverse(int $paymentId, ?int $userId = null): bool
    {
        $payment = Database::first('SELECT * FROM payments WHERE id = :id', ['id' => $paymentId]);

        if (!$payment || $payment['status'] !== 'completed') {
            return false;
        }

        Database::transaction(static function () use ($payment, $userId) {
            Database::update('payments', ['status' => 'cancelled'], ['id' => $payment['id']]);

            if ($payment['document_id']) {
                self::refreshInvoice((int) $payment['document_id']);
            }

            ActivityLog::record(
                'payment_reversed',
                'payment',
                (int) $payment['id'],
                'Reversed ' . $payment['payment_number'] . ' (' . money($payment['amount']) . ')'
            );
        });

        return true;
    }
}
