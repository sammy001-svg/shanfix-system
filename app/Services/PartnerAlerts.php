<?php

namespace App\Services;

use App\Core\Database;

/**
 * Telling a partner what has happened on one of their customers.
 *
 * A partner cannot raise a quotation, an invoice or a payment. That is
 * ours, deliberately: they introduce and they earn, we bill and we
 * collect. The consequence is that everything which moves their money
 * happens where they cannot see it, so it has to be told to them.
 *
 * Everything here is best-effort and must never break the thing that
 * triggered it. Taking a payment is the important act; failing to send
 * the partner an email about it is not a reason to fail the payment.
 */
class PartnerAlerts
{
    /**
     * We have quoted or invoiced a partner's customer.
     *
     * Drafts are skipped. A quotation still being written is not news, and
     * a figure that moves before it is sent would only be relayed to the
     * customer and then contradicted.
     *
     * Sent once per document, ever. This is called both when a document is
     * created as issued and when one that needed approval is approved, so
     * without a lock a document that took the second road would be
     * announced twice.
     */
    public static function documentRaised(int $documentId): void
    {
        try {
            $doc = Database::first(
                "SELECT d.id, d.doc_type, d.doc_number, d.status, d.total,
                        c.name AS client_name, c.partner_id
                   FROM documents d
                   JOIN clients c ON c.id = d.client_id
                  WHERE d.id = :id
                    AND d.doc_type IN ('quotation', 'invoice')
                    AND d.status <> 'draft'",
                ['id' => $documentId]
            );

            if (!$doc || $doc['partner_id'] === null) {
                return;
            }

            $partner = self::partner((int) $doc['partner_id']);

            if (!$partner || !self::claim('partner_doc:' . $documentId)) {
                return;
            }

            Notifier::dispatch('partner_document', [
                'entity_type'  => 'partner',
                'entity_id'    => (int) $partner['id'],
                'contact_name' => $partner['name'],
                'email'        => $partner['email'],
                'phone'        => $partner['phone'],
                'client_name'  => $doc['client_name'],
                'doc_type'     => $doc['doc_type'] === 'quotation' ? 'quotation' : 'invoice',
                'doc_number'   => $doc['doc_number'],
                'amount'       => money($doc['total']),
            ]);

            Notifier::processQueue(4);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Could not tell the partner about a document: ' . $e->getMessage());
        }
    }

    /**
     * A partner's customer has paid us.
     *
     * The one that is actually money: commission is earned when the
     * customer pays, so this is the moment the partner has been waiting
     * for. Called after the ledger has been brought up to date, so the
     * figure quoted is the figure they will be paid.
     */
    public static function paymentTaken(int $paymentId): void
    {
        try {
            $payment = Database::first(
                'SELECT p.id, p.amount, p.document_id,
                        d.doc_number,
                        c.name AS client_name, c.partner_id
                   FROM payments p
              LEFT JOIN documents d ON d.id = p.document_id
                   JOIN clients   c ON c.id = p.client_id
                  WHERE p.id = :id',
                ['id' => $paymentId]
            );

            if (!$payment || $payment['partner_id'] === null) {
                return;
            }

            $partner = self::partner((int) $payment['partner_id']);

            if (!$partner || !self::claim('partner_pay:' . $paymentId)) {
                return;
            }

            // What this payment actually earned them. Read from the ledger
            // rather than worked out again here, so the figure in the
            // message is the figure they will be paid.
            $earned = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM commissions
                  WHERE payment_id = :pay AND partner_id = :p AND status <> 'void'",
                ['pay' => $paymentId, 'p' => $partner['id']],
                0
            );

            // If the row was written without a payment against it, take the
            // newest slice on the document — that is the one this payment
            // just created. Deliberately not the sum of them: on a second
            // part payment that would tell the partner they had just earned
            // everything the invoice has ever earned.
            if ($earned < 0.005 && $payment['document_id'] !== null) {
                $earned = (float) Database::scalar(
                    "SELECT amount FROM commissions
                      WHERE document_id = :d AND partner_id = :p AND status <> 'void'
                   ORDER BY id DESC LIMIT 1",
                    ['d' => $payment['document_id'], 'p' => $partner['id']],
                    0
                );
            }

            Notifier::dispatch('partner_payment', [
                'entity_type'  => 'partner',
                'entity_id'    => (int) $partner['id'],
                'contact_name' => $partner['name'],
                'email'        => $partner['email'],
                'phone'        => $partner['phone'],
                'client_name'  => $payment['client_name'],
                'doc_number'   => $payment['doc_number'] ?: 'their account',
                'amount'       => money($payment['amount']),
                'commission'   => money($earned),
            ]);

            Notifier::processQueue(4);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Could not tell the partner about a payment: ' . $e->getMessage());
        }
    }

    /** An active partner with somewhere to send a message. */
    private static function partner(int $partnerId): ?array
    {
        return Database::first(
            "SELECT id, name, email, phone FROM partners
              WHERE id = :id AND status = 'active'",
            ['id' => $partnerId]
        );
    }

    /**
     * Take the right to send this message, once.
     *
     * The unique key on lock_key is what makes it once: a second attempt
     * fails to insert and is told so, rather than both attempts reading
     * "nothing sent yet" and both sending.
     */
    private static function claim(string $key): bool
    {
        try {
            Database::run('INSERT INTO notification_locks (lock_key) VALUES (:k)', ['k' => $key]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
