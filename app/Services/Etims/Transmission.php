<?php

namespace App\Services\Etims;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Services\StaffNotifier;

/**
 * Getting invoices to KRA, and knowing which ones did not get there.
 *
 * This is the part that is ours: which invoices are owed to KRA, in what
 * order, how often to try again, when to stop trying and tell somebody.
 * What actually goes over the wire is Oscu's business, and the two are
 * kept apart deliberately — KRA change their API, and when they do the
 * thing that should need rewriting is the transport, not the record of
 * what the business has and has not declared.
 *
 * The queue is the documents table itself rather than a queue of its
 * own. A document has exactly one standing with KRA at any moment, and
 * a separate queue would be a second place for that answer to live. The
 * history lives in etims_log, which is a different question.
 *
 * Nothing here sends unless the operator has both filled in the
 * company's KRA details and switched sending on. Refusing to send is
 * always safe; sending something malformed is not, because a rejected
 * invoice is a compliance problem with a client's name on it.
 */
final class Transmission
{
    /**
     * How many times to try before it stops being a temporary problem.
     *
     * Low on purpose. A transmission that has failed four times is
     * failing for a reason nobody has looked at yet, and the useful
     * response is to tell a person rather than to go on knocking.
     */
    public const MAX_ATTEMPTS = 4;

    /** Only these ever go to KRA. */
    public const SENDABLE = ['invoice', 'receipt'];

    /**
     * Put an invoice in the queue.
     *
     * Idempotent: an invoice already sent stays sent, and one already
     * queued is not queued twice. Called both by hand and when an
     * invoice is raised with automatic sending on.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function queue(int $documentId, bool $force = false): array
    {
        $doc = Database::first(
            'SELECT id, doc_type, doc_number, status, etims_status, etims_attempts, total
               FROM documents WHERE id = :id',
            ['id' => $documentId]
        );

        if (!$doc) {
            return ['ok' => false, 'error' => 'That document does not exist.'];
        }

        if (!in_array($doc['doc_type'], self::SENDABLE, true)) {
            return ['ok' => false, 'error' =>
                'Only invoices and receipts go to KRA. A '
                . $doc['doc_type'] . ' is not a tax document.'];
        }

        if ($doc['etims_status'] === 'sent' && !$force) {
            return ['ok' => false, 'error' =>
                $doc['doc_number'] . ' has already been sent to KRA.'];
        }

        if ($doc['etims_status'] === 'voided') {
            return ['ok' => false, 'error' =>
                $doc['doc_number'] . ' has been voided with KRA and cannot be sent again.'];
        }

        // A draft is not a tax document. Sending one declares income
        // from a sale that has not been agreed, and the correction is a
        // credit note rather than a delete.
        if ($doc['status'] === 'draft') {
            return ['ok' => false, 'error' =>
                $doc['doc_number'] . ' is still a draft. Mark it sent or unpaid first.'];
        }

        Database::update('documents', [
            'etims_status'     => 'queued',
            'etims_queued_at'  => date('Y-m-d H:i:s'),
            'etims_last_error' => null,
            // Queueing again does not forgive the attempts already made
            // — otherwise anybody pressing Send on an invoice that has
            // failed four times resets the count and it knocks for ever.
            // Only an explicit "try this again from scratch" does.
            'etims_attempts'   => $force ? 0 : (int) $doc['etims_attempts'],
        ], ['id' => $documentId]);

        self::log($documentId, 'queue', true, null, 'Queued for KRA.');

        return ['ok' => true];
    }

    /**
     * Send everything waiting.
     *
     * Oldest first, because the order invoices were raised in is the
     * order KRA should receive them in.
     *
     * @return array{sent:int, failed:int, held:int, reason?:string}
     */
    public static function sweep(?int $limit = null): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'held' => 0];

        if (!Setup::canSend()) {
            // Not an error. The operator has not finished setting this
            // up, or has switched it off, and both are decisions.
            $out['held']   = self::waitingCount();
            $out['reason'] = Setup::describe();

            return $out;
        }

        $rows = Database::all(
            "SELECT id FROM documents
              WHERE etims_status IN ('queued', 'failed')
                AND etims_attempts < :max
           ORDER BY etims_queued_at, id
              LIMIT " . max(1, (int) ($limit ?? 50)),
            ['max' => self::MAX_ATTEMPTS]
        );

        foreach ($rows as $row) {
            $result = self::send((int) $row['id']);
            $result['ok'] ? $out['sent']++ : $out['failed']++;
        }

        return $out;
    }

    /**
     * One invoice, once.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function send(int $documentId): array
    {
        if (!Setup::canSend()) {
            return ['ok' => false, 'error' => Setup::describe()];
        }

        $doc = Database::first('SELECT * FROM documents WHERE id = :id', ['id' => $documentId]);

        if (!$doc) {
            return ['ok' => false, 'error' => 'That document does not exist.'];
        }

        if ($doc['etims_status'] === 'sent') {
            return ['ok' => false, 'error' => 'Already sent.'];
        }

        $items = Database::all(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $documentId]
        );

        // Claimed, so two sweeps running at once do not both send it.
        // Not a lock in any strong sense — it is one machine and one
        // cron — but it is what stops a hand-pressed Send racing the
        // sweep that was already running.
        $claimed = Database::run(
            "UPDATE documents
                SET etims_status = 'sending', etims_attempts = etims_attempts + 1
              WHERE id = :id AND etims_status <> 'sending'",
            ['id' => $documentId]
        )->rowCount() > 0;

        if (!$claimed) {
            return ['ok' => false, 'error' => 'That invoice is already being sent.'];
        }

        $started = microtime(true);

        try {
            $result = Oscu::sendInvoice($doc, $items);
        } catch (\Throwable $e) {
            Logger::error('eTIMS transmission threw: ' . $e->getMessage());
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        $tookMs = (int) round((microtime(true) - $started) * 1000);

        if ($result['ok'] ?? false) {
            self::recordSuccess($documentId, $result, $tookMs);

            return ['ok' => true];
        }

        return self::recordFailure($documentId, $doc, $result, $tookMs);
    }

    // -- What came back ---------------------------------------------------

    private static function recordSuccess(int $documentId, array $result, int $tookMs): void
    {
        $fiscal = $result['fiscal'] ?? [];

        Database::update('documents', [
            'etims_status'        => 'sent',
            'etims_sent_at'       => date('Y-m-d H:i:s'),
            'etims_last_error'    => null,
            'etims_receipt_no'    => $fiscal['receipt_no']    ?? null,
            'etims_invoice_no'    => $fiscal['invoice_no']    ?? null,
            'etims_signature'     => $fiscal['signature']     ?? null,
            'etims_internal_data' => $fiscal['internal_data'] ?? null,
            'etims_signed_at'     => $fiscal['signed_at']     ?? null,
            'etims_verify_url'    => $fiscal['verify_url']    ?? null,
            'etims_response'      => $result['raw'] ?? null,
        ], ['id' => $documentId]);

        self::log($documentId, 'send', true, $result['http'] ?? null,
                  'Accepted by KRA.', $result['request'] ?? null,
                  $result['raw'] ?? null, $tookMs);
    }

    /**
     * @return array{ok:bool, error:string}
     */
    private static function recordFailure(int $documentId, array $doc, array $result, int $tookMs): array
    {
        $error    = mb_substr((string) ($result['error'] ?? 'KRA did not accept it.'), 0, 500);
        $attempts = (int) $doc['etims_attempts'] + 1;

        Database::update('documents', [
            'etims_status'     => 'failed',
            'etims_last_error' => $error,
            'etims_response'   => $result['raw'] ?? null,
        ], ['id' => $documentId]);

        self::log($documentId, 'send', false, $result['http'] ?? null, $error,
                  $result['request'] ?? null, $result['raw'] ?? null, $tookMs);

        // Out of tries. This stops being something to retry and starts
        // being something a person has to deal with, so a person is
        // told — once, on the attempt that gives up, rather than on
        // every attempt along the way.
        if ($attempts >= self::MAX_ATTEMPTS) {
            self::tellSomebody($doc, $error);
        }

        return ['ok' => false, 'error' => $error];
    }

    private static function tellSomebody(array $doc, string $error): void
    {
        // Finance, not everybody who can raise an invoice. An invoice
        // the business has not declared is a filing problem, and
        // reception being told about it helps nobody.
        $people = Auth::usersWith('reports.view');

        if ($people === []) {
            return;
        }

        StaffNotifier::notify(
            array_column($people, 'id'),
            [
                'event'       => 'etims_failed',
                'title'       => 'KRA has not accepted ' . $doc['doc_number'],
                'body'        => 'After ' . self::MAX_ATTEMPTS . ' attempts: ' . $error
                                 . ' The invoice stands, but it has not been declared.',
                'url'         => '/invoices/' . $doc['id'],
                'entity_type' => 'document',
                'entity_id'   => (int) $doc['id'],
            ],
            ['email']
        );
    }

    // -- Reading the state ------------------------------------------------

    /** How many invoices are owed to KRA and have not got there. */
    public static function waitingCount(): int
    {
        return (int) Database::first(
            "SELECT COUNT(*) AS n FROM documents
              WHERE etims_status IN ('queued', 'failed', 'sending')"
        )['n'];
    }

    /**
     * The ones that have given up, which is the list somebody actually
     * has to act on.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function stuck(int $limit = 20): array
    {
        return Database::all(
            "SELECT d.id, d.doc_number, d.total, d.issue_date,
                    d.etims_attempts, d.etims_last_error, c.name AS client_name
               FROM documents d
          LEFT JOIN clients c ON c.id = d.client_id
              WHERE d.etims_status = 'failed'
                AND d.etims_attempts >= :max
           ORDER BY d.issue_date DESC, d.id DESC
              LIMIT " . max(1, $limit),
            ['max' => self::MAX_ATTEMPTS]
        );
    }

    /** What happened to one document, most recent first. */
    public static function historyFor(int $documentId, int $limit = 20): array
    {
        return Database::all(
            'SELECT * FROM etims_log WHERE document_id = :id
           ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            ['id' => $documentId]
        );
    }

    private static function log(
        int $documentId,
        string $action,
        bool $ok,
        ?int $httpCode = null,
        ?string $message = null,
        ?string $request = null,
        ?string $response = null,
        ?int $tookMs = null
    ): void {
        Database::insert('etims_log', [
            'document_id' => $documentId,
            'action'      => $action,
            'ok'          => $ok ? 1 : 0,
            'http_code'   => $httpCode,
            'message'     => $message !== null ? mb_substr($message, 0, 500) : null,
            'request'     => $request,
            'response'    => $response,
            'took_ms'     => $tookMs,
            'user_id'     => Auth::id(),
        ]);
    }
}
