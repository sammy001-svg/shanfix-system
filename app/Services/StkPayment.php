<?php
namespace App\Services;

use App\Core\ActivityLog;
use App\Core\Database;

/**
 * Sending an M-Pesa prompt for an invoice.
 *
 * Pulled out of PublicDocumentController so the client portal could offer
 * the same thing. This decides how much a person is asked to pay and how
 * often they can be asked — a second copy of it would mean any flaw had
 * to be found and fixed twice, and one of the two copies would stay
 * broken.
 *
 * The rules it exists to hold, whichever page is asking:
 *
 *   - The amount comes from the invoice, never from the form. A posted
 *     amount would let anybody settle a 50,000 invoice for 5.
 *   - Attempts are capped per invoice per hour, so the endpoint cannot be
 *     used to pester a phone number.
 *   - One prompt at a time. A second while the first is still on the
 *     handset only confuses the person holding it.
 */
class StkPayment
{
    /** Prompts one invoice may trigger in an hour. */
    public const MAX_ATTEMPTS_PER_HOUR = 6;

    /** Whether this invoice can be settled online at all. */
    public static function payable(array $doc): bool
    {
        return $doc['doc_type'] === 'invoice'
            && (float) $doc['balance'] > 0.009
            && !in_array($doc['status'], ['draft', 'cancelled', 'paid'], true)
            && ($doc['approval_status'] ?? 'approved') !== 'pending'
            && \App\Core\Settings::bool('kopokopo_enabled');
    }

    /**
     * Send the prompt.
     *
     * @param string $source where the request came from, for the record
     *
     * @return array{ok:bool, stk_id?:int, pending?:bool, error?:string}
     */
    public static function request(array $doc, string $rawPhone, string $source): array
    {
        if (!self::payable($doc)) {
            return ['ok' => false, 'error' => 'This invoice cannot be paid online. Please contact us.'];
        }

        $phone = normalize_phone($rawPhone);

        if ($phone === null) {
            return ['ok' => false, 'error' => 'Please enter a valid M-Pesa number, for example 0712345678.'];
        }

        // Always the full balance, read from the invoice. Part payment from
        // the client side would need a visible field and a deliberate
        // decision to allow it; until then, taking a number from the form
        // would only be a way to fire prompts for arbitrary sums.
        $amount = (float) $doc['balance'];

        $recent = (int) Database::scalar(
            'SELECT COUNT(*) FROM stk_requests
              WHERE document_id = :id AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            ['id' => $doc['id']],
            0
        );

        if ($recent >= self::MAX_ATTEMPTS_PER_HOUR) {
            return [
                'ok'    => false,
                'error' => 'Too many payment attempts on this invoice. Please try again later, '
                         . 'or contact us to pay another way.',
            ];
        }

        $pending = Database::first(
            "SELECT id FROM stk_requests
              WHERE document_id = :id AND status = 'pending'
                AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
              LIMIT 1",
            ['id' => $doc['id']]
        );

        if ($pending) {
            return [
                'ok'      => true,
                'pending' => true,
                'stk_id'  => (int) $pending['id'],
                'error'   => 'A payment request is already on your phone. Enter your M-Pesa PIN to complete it.',
            ];
        }

        $client = Database::first('SELECT * FROM clients WHERE id = :id', ['id' => $doc['client_id']]);

        $names     = preg_split('/\s+/', trim((string) ($client['name'] ?? 'Client')));
        $firstName = $names[0] ?? 'Client';
        $lastName  = count($names) > 1 ? end($names) : '-';

        // Recorded before the call goes out, so a callback that arrives
        // before the HTTP response still finds a row to attach itself to.
        $stkId = Database::insert('stk_requests', [
            'document_id'  => (int) $doc['id'],
            'client_id'    => (int) $doc['client_id'],
            'phone'        => $phone,
            'amount'       => $amount,
            'status'       => 'pending',
            'initiated_by' => null,   // the client did this, not a member of staff
        ]);

        $result = (new KopoKopo())->stkPush(
            phone:       $phone,
            amount:      $amount,
            callbackUrl: Notifier::absoluteUrl('/webhooks/kopokopo'),
            reference:   (string) $doc['doc_number'],
            firstName:   $firstName,
            lastName:    $lastName,
            email:       $client['email'] ?: null,
            metadata:    [
                'client_id'   => (string) $doc['client_id'],
                'document_id' => (string) $doc['id'],
                'stk_id'      => (string) $stkId,
                'source'      => $source,
            ]
        );

        Database::update('stk_requests', [
            'kopokopo_id'      => $result['id'] ?? null,
            'location_url'     => $result['location'] ?? null,
            'request_payload'  => json_encode($result['request'] ?? [], JSON_UNESCAPED_SLASHES),
            'response_payload' => mb_substr((string) ($result['response'] ?? ''), 0, 4000),
            'status'           => $result['ok'] ? 'pending' : 'failed',
            'result_desc'      => $result['ok'] ? null : mb_substr((string) ($result['error'] ?? ''), 0, 255),
        ], ['id' => $stkId]);

        if (!$result['ok']) {
            ActivityLog::record(
                'stk_failed',
                'stk_request',
                $stkId,
                'Client-initiated STK Push failed for ' . $doc['doc_number'] . ' (' . $source . ')'
            );

            return ['ok' => false, 'error' => 'We could not reach M-Pesa just now. Please try again in a moment.'];
        }

        ActivityLog::record(
            'stk_sent',
            'stk_request',
            $stkId,
            'Client paid ' . $doc['doc_number'] . ' from ' . $source . ' — prompt sent to ' . $phone
        );

        return ['ok' => true, 'stk_id' => $stkId];
    }

    /**
     * Where a prompt has got to.
     *
     * Scoped to the invoice as well as the request id, so an id belonging
     * to a different invoice answers nothing.
     *
     * @return array<string,mixed>|null
     */
    public static function status(int $stkId, int $documentId): ?array
    {
        if ($stkId < 1) {
            return null;
        }

        return Database::first(
            'SELECT id, status, amount, phone, mpesa_receipt, result_desc, created_at
               FROM stk_requests
              WHERE id = :id AND document_id = :doc',
            ['id' => $stkId, 'doc' => $documentId]
        );
    }
}
