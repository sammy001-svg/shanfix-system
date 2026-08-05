<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Settings;

/**
 * The client-facing view of a quotation, invoice or receipt.
 *
 * Reached from an email link with an unguessable token — no account, no
 * login. Strictly read-only, and deliberately shows nothing beyond the
 * document itself: no internal notes, no costs, no other clients.
 */
class PublicDocumentController extends Controller
{
    public function show(Request $request): void
    {
        $doc = $this->findByToken((string) $request->param('token'));

        $items = Database::all(
            'SELECT description, quantity, unit, unit_price, line_total
               FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $doc['id']]
        );

        $payments = Database::all(
            "SELECT amount, method, reference, paid_at, created_at
               FROM payments
              WHERE document_id = :id AND status = 'completed'
           ORDER BY created_at",
            ['id' => $doc['id']]
        );

        // Record the first open so the team can see the client received it.
        if (empty($doc['viewed_at'])) {
            Database::update('documents', ['viewed_at' => date('Y-m-d H:i:s')], ['id' => $doc['id']]);
        }

        $this->view('public/document', [
            'title'     => $doc['doc_number'],
            'doc'       => $doc,
            'items'     => $items,
            'payments'  => $payments,
            'company'   => Settings::company(),
            'token'     => $doc['public_token'],
            'autoPrint' => $request->query('print') === '1',
        ], 'public');
    }

    /**
     * Token lookup. Only documents that have actually been issued are
     * reachable — a draft is still internal.
     */
    private function findByToken(string $token): array
    {
        // Cheap shape check before touching the database.
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            throw new HttpException(404, 'This link is not valid.');
        }

        $doc = Database::first(
            'SELECT d.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
                    c.address AS client_address, c.city AS client_city,
                    c.kra_pin AS client_kra_pin, c.contact_person AS client_contact
               FROM documents d
               JOIN clients c ON c.id = d.client_id
              WHERE d.public_token = :token
              LIMIT 1',
            ['token' => $token]
        );

        if (!$doc) {
            throw new HttpException(404, 'This link is no longer valid. Please ask us to resend it.');
        }

        if ($doc['status'] === 'draft') {
            throw new HttpException(404, 'This document is not available yet.');
        }

        if ($doc['status'] === 'cancelled') {
            throw new HttpException(410, 'This document has been cancelled. Please contact us for an up-to-date copy.');
        }

        return $doc;
    }
}
