<?php
namespace App\Controllers;

use App\Core\ClientAuth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Settings;

/**
 * The client portal itself.
 *
 * Every query here is scoped to ClientAuth::clientId() and nothing else.
 * There is no path by which a signed-in client names a record: the client
 * they belong to comes from their session, so asking for somebody else's
 * invoice is not refused so much as impossible to express.
 */
class PortalController extends Controller
{
    public function home(Request $request): void
    {
        $me       = ClientAuth::user();
        $clientId = ClientAuth::clientId();

        if (!$me) {
            throw new HttpException(403, 'Please sign in.');
        }

        $counts = [
            'quotations' => 0,
            'invoices'   => 0,
            'owing'      => money(0),
            'owing_raw'  => 0.0,
        ];

        if ($clientId !== null) {
            $counts['quotations'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM documents
                  WHERE client_id = :c AND doc_type = 'quotation'
                    AND status <> 'draft' AND approval_status <> 'pending'",
                ['c' => $clientId],
                0
            );

            $counts['invoices'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM documents
                  WHERE client_id = :c AND doc_type = 'invoice'
                    AND status <> 'draft' AND approval_status <> 'pending'",
                ['c' => $clientId],
                0
            );

            $owing = (float) Database::scalar(
                "SELECT COALESCE(SUM(balance), 0) FROM documents
                  WHERE client_id = :c AND doc_type = 'invoice'
                    AND status NOT IN ('draft','cancelled','paid')
                    AND approval_status <> 'pending'",
                ['c' => $clientId],
                0
            );

            $counts['owing']     = money($owing);
            $counts['owing_raw'] = $owing;
        }

        $this->view('portal/home', [
            'title'   => 'Your account',
            'me'      => $me,
            'counts'  => $counts,
            'company' => Settings::company(),
        ], 'portal');
    }

    // -- Their documents ---------------------------------------------------

    /** Quotations, or invoices — the same list with a different filter. */
    public function documents(Request $request, string $type): void
    {
        $clientId = $this->mustHaveClient();

        $rows = Database::all(
            "SELECT id, doc_number, title, issue_date, due_date, valid_until,
                    status, total, amount_paid, balance
               FROM documents
              WHERE client_id = :c
                AND doc_type = :t
                AND status <> 'draft'
                AND approval_status <> 'pending'
           ORDER BY issue_date DESC, id DESC",
            ['c' => $clientId, 't' => $type]
        );

        $this->view('portal/documents', [
            'title'   => $type === 'invoice' ? 'Your invoices' : 'Your quotations',
            'me'      => ClientAuth::user(),
            'type'    => $type,
            'rows'    => $rows,
            'company' => Settings::company(),
        ], 'portal');
    }

    /** One document, in full. */
    public function document(Request $request, string $type): void
    {
        $clientId = $this->mustHaveClient();

        // Scoped by client in the lookup itself. A document belonging to
        // somebody else is not refused so much as not found, which is the
        // same answer a document that does not exist gets.
        $doc = Database::first(
            "SELECT * FROM documents
              WHERE id = :id AND client_id = :c AND doc_type = :t
                AND status <> 'draft' AND approval_status <> 'pending'",
            ['id' => $request->paramInt('id'), 'c' => $clientId, 't' => $type]
        );

        if (!$doc) {
            throw new HttpException(404, 'That document is not on your account.');
        }

        $this->view('portal/document', [
            'title'    => $doc['doc_number'],
            'me'       => ClientAuth::user(),
            'type'     => $type,
            'doc'      => $doc,
            'items'    => Database::all(
                'SELECT description, quantity, unit, unit_price, line_total
                   FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
                ['id' => $doc['id']]
            ),
            'sections' => Database::all(
                'SELECT heading, body FROM document_sections WHERE document_id = :id ORDER BY sort_order, id',
                ['id' => $doc['id']]
            ),
            'payments' => Database::all(
                "SELECT amount, method, reference, paid_at, created_at
                   FROM payments
                  WHERE document_id = :id AND status = 'completed'
               ORDER BY created_at",
                ['id' => $doc['id']]
            ),
            'company'  => Settings::company(),
        ], 'portal');
    }

    /** Their statement of account. */
    public function statement(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $client = Database::first('SELECT * FROM clients WHERE id = :id', ['id' => $clientId]);

        if (!$client) {
            throw new HttpException(404, 'Your account could not be found.');
        }

        $from = trim((string) $request->query('from', ''));
        $to   = trim((string) $request->query('to', ''));

        $statement = \App\Services\Statement::build(
            $client,
            $from !== '' && strtotime($from) ? date('Y-m-d', strtotime($from)) : null,
            $to   !== '' && strtotime($to)   ? date('Y-m-d', strtotime($to))   : null
        );

        $this->view('portal/statement', [
            'title'     => 'Your statement',
            'me'        => ClientAuth::user(),
            'statement' => $statement,
            'company'   => Settings::company(),
        ], 'portal');
    }

    // -- Internals ---------------------------------------------------------

    /**
     * The client this session may see, or nothing at all.
     *
     * An account with no client attached is a half-finished sign-up. It
     * has no records of its own, and guessing which client it meant is
     * exactly how somebody ends up reading another company's invoices.
     */
    private function mustHaveClient(): int
    {
        $clientId = ClientAuth::clientId();

        if ($clientId === null) {
            throw new HttpException(403, 'Your account is not linked to a customer record yet. Please contact us.');
        }

        return $clientId;
    }
}
