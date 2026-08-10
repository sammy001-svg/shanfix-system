<?php
namespace App\Services;

use App\Core\ActivityLog;
use App\Core\Database;
use App\Core\Logger;
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
     * Tell the client their payment landed.
     *
     * Called after post() rather than inside it, so a mail server problem
     * can never roll back a payment that genuinely happened.
     */
    public static function notifyClient(int $paymentId): void
    {
        try {
            $payment = Database::first(
                'SELECT p.*, c.name AS client_name, c.email AS client_email,
                        c.phone AS client_phone, c.contact_person AS client_contact
                   FROM payments p JOIN clients c ON c.id = p.client_id
                  WHERE p.id = :id',
                ['id' => $paymentId]
            );

            if (!$payment || $payment['status'] !== 'completed') {
                return;
            }

            $doc = $payment['document_id']
                ? Database::first('SELECT * FROM documents WHERE id = :id', ['id' => $payment['document_id']])
                : null;

            $context = $doc
                ? Notifier::documentContext(array_merge($doc, [
                    'client_name'    => $payment['client_name'],
                    'client_email'   => $payment['client_email'],
                    'client_phone'   => $payment['client_phone'],
                    'client_contact' => $payment['client_contact'],
                  ]))
                : [
                    'entity_type'  => 'payment',
                    'entity_id'    => $paymentId,
                    'client_id'    => (int) $payment['client_id'],
                    'client_name'  => $payment['client_name'],
                    'contact_name' => $payment['client_contact'] ?: $payment['client_name'],
                    'email'        => $payment['client_email'],
                    'phone'        => $payment['client_phone'],
                    'doc_number'   => $payment['payment_number'],
                  ];

            // The amount on a receipt is what was just paid, not the invoice total.
            $context['amount']      = money($payment['amount']);
            $context['paid_now']    = money($payment['amount']);
            $context['payment_ref'] = $payment['reference'] ?? '';
            $context['method']      = match ((string) ($payment['method'] ?? '')) {
                'mpesa_stk', 'mpesa_manual' => 'M-Pesa',
                'bank'   => 'bank transfer',
                'cash'   => 'cash',
                'cheque' => 'cheque',
                default  => 'payment',
            };

            // A part payment and a settled invoice deserve different words:
            // one says "thank you, here is what is left", the other says
            // "you are all square".
            $outstanding = (float) ($doc['balance'] ?? 0);
            $event = ($doc && $outstanding > 0.004) ? 'payment_partial' : 'payment_received';

            Notifier::dispatch($event, $context);
            Notifier::processQueue(5);
        } catch (\Throwable $e) {
            // A failed confirmation must never break payment recording.
            Logger::error('Payment confirmation failed to send: ' . $e->getMessage(), [
                'payment_id' => $paymentId,
            ]);
        }
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
