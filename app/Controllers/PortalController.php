<?php
namespace App\Controllers;

use App\Core\ClientAuth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Core\Response;
use App\Services\StaffNotifier;
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

    // -- What renews ---------------------------------------------------------

    /** Their recurring services, and when each is next due. */
    public function services(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $rows = Database::all(
            "SELECT s.*, sv.name AS catalogue_name
               FROM subscriptions s
          LEFT JOIN services sv ON sv.id = s.service_id
              WHERE s.client_id = :c
           ORDER BY FIELD(s.status, 'active') DESC, s.next_renewal_date ASC, s.id",
            ['c' => $clientId]
        );

        $today = strtotime(date('Y-m-d'));

        foreach ($rows as $i => $row) {
            $due = $row['next_renewal_date'] ? strtotime((string) $row['next_renewal_date']) : null;

            $rows[$i]['days_away'] = $due !== null ? (int) floor(($due - $today) / 86400) : null;
        }

        $this->view('portal/services', [
            'title'   => 'Your recurring services',
            'me'      => ClientAuth::user(),
            'rows'    => $rows,
            'company' => Settings::company(),
        ], 'portal');
    }

    // -- The catalogue -------------------------------------------------------

    public function catalogue(Request $request): void
    {
        $this->mustHaveClient();

        $search    = trim((string) $request->query('q', ''));
        $showStock = Settings::bool('portal_show_inventory', true);

        $like   = '%' . $search . '%';
        $params = $search !== '' ? ['q' => $like, 'q2' => $like] : [];
        $where  = $search !== '' ? ' AND (name LIKE :q OR description LIKE :q2)' : '';

        $services = Database::all(
            "SELECT id, code, name, description, pricing_type, price, unit_label, lead_time
               FROM services
              WHERE is_active = 1" . $where . "
           ORDER BY name",
            $params
        );

        $inventory = $showStock
            ? Database::all(
                "SELECT id, sku, name, description, unit, selling_price
                   FROM inventory_items
                  WHERE is_active = 1" . $where . "
               ORDER BY name",
                $params
              )
            : [];

        $this->view('portal/catalogue', [
            'title'      => 'What we do',
            'me'         => ClientAuth::user(),
            'services'   => $services,
            'inventory'  => $inventory,
            'search'     => $search,
            'showPrices' => Settings::bool('portal_show_prices', true),
            'company'    => Settings::company(),
        ], 'portal');
    }

    /** They ticked some things and asked us about the price of them. */
    public function requestPrice(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $picked = (array) $request->input('items', []);
        $kind   = (string) $request->input('kind', 'quotation');

        if (!in_array($kind, ['review', 'quotation', 'discount'], true)) {
            $kind = 'quotation';
        }

        // Read back from the catalogue rather than trusting what arrived. A
        // price posted from a browser is a number somebody could have typed;
        // what we answer about has to be what we hold.
        $lines = [];

        foreach ($picked as $token) {
            // A browser can post items[0][x]=1 as easily as items[]=service:1,
            // and casting that array to a string is a warning in the log for
            // no gain. Anything that is not a plain string is simply not a
            // tick.
            if (!is_string($token)) {
                continue;
            }

            [$type, $id] = array_pad(explode(':', $token, 2), 2, null);
            $id = (int) $id;

            if ($id < 1 || !in_array($type, ['service', 'inventory'], true)) {
                continue;
            }

            $row = $type === 'service'
                ? Database::first('SELECT name, price FROM services WHERE id = :id AND is_active = 1', ['id' => $id])
                : Database::first('SELECT name, selling_price AS price FROM inventory_items WHERE id = :id AND is_active = 1', ['id' => $id]);

            if (!$row) {
                continue;
            }

            $lines[] = [
                'item_type'      => $type,
                'ref_id'         => $id,
                'name_snapshot'  => mb_substr((string) $row['name'], 0, 200),
                'price_snapshot' => (float) $row['price'],
                'quantity'       => 1,
            ];
        }

        if ($lines === []) {
            Session::error('Tick at least one thing you would like us to price.');
            Response::to('/portal/catalogue');
        }

        $me = ClientAuth::user();

        $requestId = Database::transaction(function () use ($clientId, $me, $kind, $request, $lines) {
            $id = Database::insert('price_requests', [
                'reference'      => $this->priceRequestReference(),
                'client_id'      => $clientId,
                'client_user_id' => $me['id'] ?? null,
                'kind'           => $kind,
                'note'           => trim((string) $request->input('note')) ?: null,
            ]);

            foreach ($lines as $line) {
                Database::insert('price_request_items', array_merge($line, ['request_id' => $id]));
            }

            return $id;
        });

        // Worth knowing quickly: a client asking about a price is a client
        // who is close to buying.
        StaffNotifier::notify(
            StaffNotifier::withRole(['admin', 'manager', 'sales']),
            [
                'event'       => 'price_request',
                'title'       => ($me['client_name'] ?? 'A client') . ' has asked about prices',
                'body'        => count($lines) . ' item(s) — ' . match ($kind) {
                    'review'   => 'they want to know whether the prices are current.',
                    'discount' => 'they are asking what can be done on the price.',
                    default    => 'they would like a quotation.',
                },
                'link'        => '/price-requests',
                'entity_type' => 'price_request',
                'entity_id'   => $requestId,
            ],
            ['email' => true, 'sms' => false]
        );

        Session::success(
            'Thank you — that has gone to our team. We will come back to you'
            . ($kind === 'quotation' ? ' with a quotation.' : ' shortly.')
        );

        Response::to('/portal/requests');
    }

    /** What they have asked us, and what came back. */
    public function priceRequests(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $rows = Database::all(
            'SELECT r.*, d.doc_number, d.id AS quotation_id
               FROM price_requests r
          LEFT JOIN documents d ON d.id = r.document_id
              WHERE r.client_id = :c
           ORDER BY r.created_at DESC',
            ['c' => $clientId]
        );

        foreach ($rows as $i => $row) {
            $rows[$i]['items'] = Database::all(
                'SELECT * FROM price_request_items WHERE request_id = :id ORDER BY id',
                ['id' => $row['id']]
            );
        }

        $this->view('portal/price-requests', [
            'title'   => 'What you have asked us',
            'me'      => ClientAuth::user(),
            'rows'    => $rows,
            'company' => Settings::company(),
        ], 'portal');
    }

    /** PRQ-2026-0001. */
    private function priceRequestReference(): string
    {
        $prefix = Settings::get('price_request_prefix', 'PRQ');
        $year   = date('Y');

        $seq = (int) Database::scalar(
            'SELECT COUNT(*) + 1 FROM price_requests WHERE YEAR(created_at) = :y',
            ['y' => $year],
            1
        );

        do {
            $ref   = sprintf('%s-%s-%04d', $prefix, $year, $seq);
            $taken = Database::scalar('SELECT id FROM price_requests WHERE reference = :r', ['r' => $ref]);
            $seq++;
        } while ($taken);

        return $ref;
    }
}
