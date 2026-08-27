<?php
namespace App\Services;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;

/**
 * A price does not leave the company until an administrator has seen it.
 *
 * When somebody other than an administrator raises or changes a quotation
 * or an invoice, it waits: it cannot be printed, downloaded or sent, and
 * the client's own link will not open it, until it is approved. The
 * administrators are texted, because the person who raised it is often
 * standing in front of the client waiting to hand it over.
 *
 * Only quotations and invoices. A receipt records money already taken,
 * and a proposal or an agreement is prose — holding those back would stop
 * work without protecting anything.
 */
class DocumentApproval
{
    /** The two that commit the company to a price. */
    private const GOVERNED = ['quotation', 'invoice'];

    public static function enabled(): bool
    {
        return Settings::bool('approval_required', true);
    }

    public static function governs(string $docType): bool
    {
        return self::enabled() && in_array($docType, self::GOVERNED, true);
    }

    /**
     * Whoever can approve. Admins by definition — they are the people the
     * rule exists to route work to.
     *
     * @return array<int,int>
     */
    public static function approvers(): array
    {
        return StaffNotifier::withRole(['admin']);
    }

    public static function canApprove(): bool
    {
        return Auth::is('admin');
    }

    /**
     * Called after a governed document is saved.
     *
     * An administrator's own work needs no approval: they are the person
     * it would be sent to. Anybody else's goes on hold and the
     * administrators are told.
     */
    public static function afterSave(int $documentId, string $docType, bool $isEdit = false): void
    {
        if (!self::governs($docType)) {
            Database::update('documents', ['approval_status' => 'not_required'], ['id' => $documentId]);

            return;
        }

        if (self::canApprove()) {
            Database::update('documents', [
                'approval_status' => 'approved',
                'approved_by'     => Auth::id(),
                'approved_at'     => date('Y-m-d H:i:s'),
                'approval_note'   => null,
            ], ['id' => $documentId]);

            return;
        }

        Database::update('documents', [
            'approval_status' => 'pending',
            'approved_by'     => null,
            'approved_at'     => null,
            'approval_note'   => null,
        ], ['id' => $documentId]);

        self::notifyApprovers($documentId, $isEdit);
    }

    /** Whether this document is being held back right now. */
    public static function isPending(array $doc): bool
    {
        return ($doc['approval_status'] ?? 'approved') === 'pending';
    }

    /**
     * The sentence to show somebody who has just been stopped.
     *
     * Says who it is waiting on and why, because "permission denied" tells
     * a person nothing they can act on.
     */
    public static function heldMessage(array $doc): string
    {
        return ucfirst((string) ($doc['doc_type'] ?? 'document')) . ' '
             . ($doc['doc_number'] ?? '')
             . ' is waiting for an administrator to approve it. '
             . 'They have been notified — it cannot be printed or sent until then.';
    }

    /** An administrator says yes. */
    public static function approve(int $documentId): void
    {
        $doc = Database::first('SELECT * FROM documents WHERE id = :id', ['id' => $documentId]);

        if (!$doc) {
            return;
        }

        Database::update('documents', [
            'approval_status' => 'approved',
            'approved_by'     => Auth::id(),
            'approved_at'     => date('Y-m-d H:i:s'),
            'approval_note'   => null,
        ], ['id' => $documentId]);

        ActivityLog::record(
            'document_approved',
            'document',
            $documentId,
            'Approved ' . $doc['doc_type'] . ' ' . $doc['doc_number']
        );

        // Tell whoever raised it, so they are not left checking.
        if (!empty($doc['created_by']) && (int) $doc['created_by'] !== (int) Auth::id()) {
            StaffNotifier::notify(
                [(int) $doc['created_by']],
                [
                    'event'       => 'document_approved',
                    'title'       => ucfirst((string) $doc['doc_type']) . ' ' . $doc['doc_number'] . ' approved',
                    'body'        => 'You can print it and send it to the client now.',
                    'link'        => '/' . self::pathFor((string) $doc['doc_type']) . '/' . $documentId,
                    'entity_type' => 'document',
                    'entity_id'   => $documentId,
                ],
                ['email' => false, 'sms' => false]
            );
        }
    }

    /** An administrator sends it back with something to change. */
    public static function sendBack(int $documentId, string $note): void
    {
        $doc = Database::first('SELECT * FROM documents WHERE id = :id', ['id' => $documentId]);

        if (!$doc) {
            return;
        }

        Database::update('documents', [
            'approval_status' => 'pending',
            'approval_note'   => mb_substr(trim($note), 0, 255) ?: null,
        ], ['id' => $documentId]);

        ActivityLog::record(
            'document_sent_back',
            'document',
            $documentId,
            'Sent ' . $doc['doc_number'] . ' back: ' . str_excerpt($note, 60)
        );

        if (!empty($doc['created_by'])) {
            StaffNotifier::notify(
                [(int) $doc['created_by']],
                [
                    'event'       => 'document_sent_back',
                    'title'       => ucfirst((string) $doc['doc_type']) . ' ' . $doc['doc_number'] . ' needs a change',
                    'body'        => trim($note) !== '' ? $note : 'An administrator has asked for a change before this goes out.',
                    'link'        => '/' . self::pathFor((string) $doc['doc_type']) . '/' . $documentId,
                    'entity_type' => 'document',
                    'entity_id'   => $documentId,
                ],
                ['email' => true, 'sms' => false]
            );
        }
    }

    // -- Internals ---------------------------------------------------------

    /**
     * Text and email the administrators.
     *
     * SMS because the wait is the whole cost of this rule: the sooner an
     * administrator sees it, the shorter the client stands there.
     */
    private static function notifyApprovers(int $documentId, bool $isEdit): void
    {
        $doc = Database::first(
            'SELECT d.*, c.name AS client_name, u.name AS raised_by
               FROM documents d
          LEFT JOIN clients c ON c.id = d.client_id
          LEFT JOIN users u ON u.id = d.created_by
              WHERE d.id = :id',
            ['id' => $documentId]
        );

        if (!$doc) {
            return;
        }

        $approvers = self::approvers();

        if ($approvers === []) {
            return;
        }

        $what = ucfirst((string) $doc['doc_type']) . ' ' . $doc['doc_number'];

        StaffNotifier::notify(
            $approvers,
            [
                'event'       => 'document_approval',
                'title'       => $what . ($isEdit ? ' was changed and needs approval' : ' needs your approval'),
                'body'        => sprintf(
                    '%s for %s, %s. Raised by %s.',
                    $what,
                    $doc['client_name'] ?: 'a client',
                    money($doc['total'] ?? 0),
                    $doc['raised_by'] ?: 'a colleague'
                ),
                'link'        => '/' . self::pathFor((string) $doc['doc_type']) . '/' . $documentId,
                'entity_type' => 'document',
                'entity_id'   => $documentId,
            ],
            [
                'email' => Settings::bool('approval_notify_email', true),
                'sms'   => Settings::bool('approval_notify_sms', true),
            ]
        );
    }

    /** Where a document of this type lives. */
    public static function pathFor(string $docType): string
    {
        return [
            'quotation' => 'quotations',
            'invoice'   => 'invoices',
            'receipt'   => 'receipts',
            'proposal'  => 'proposals',
            'agreement' => 'agreements',
        ][$docType] ?? 'quotations';
    }
}
