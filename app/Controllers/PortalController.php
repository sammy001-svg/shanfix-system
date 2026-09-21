<?php
namespace App\Controllers;

use App\Core\ClientAuth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Core\Response;
use App\Services\ClientNotifier;
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
    /**
     * The overview.
     *
     * A client arrives with three questions — what do I owe, what have you
     * quoted me, what is coming up — so this page answers those in that
     * order and offers the one action worth offering: paying. Everything
     * else on it is a shortcut to a page that already existed.
     */
    public function home(Request $request): void
    {
        $me       = ClientAuth::user();
        $clientId = ClientAuth::clientId();

        if (!$me) {
            throw new HttpException(403, 'Please sign in.');
        }

        $summary = [
            'owing'        => 0.0,
            'unpaid'       => 0,
            'oldest_days'  => null,
            'pay_invoice'  => null,   // the one a "Pay now" button should open
            'quotations'   => 0,
            'open_quotes'  => 0,
            'invoices'     => 0,
            'paid_total'   => 0.0,
        ];

        $recent   = [];
        $renewals = [];

        if ($clientId !== null) {
            $summary['quotations'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM documents
                  WHERE client_id = :c AND doc_type = 'quotation'
                    AND status <> 'draft' AND approval_status <> 'pending'",
                ['c' => $clientId],
                0
            );

            // Quotations still waiting on the client rather than on us.
            $summary['open_quotes'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM documents
                  WHERE client_id = :c AND doc_type = 'quotation'
                    AND status IN ('sent', 'viewed')
                    AND approval_status <> 'pending'
                    AND (valid_until IS NULL OR valid_until >= CURDATE())",
                ['c' => $clientId],
                0
            );

            $summary['invoices'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM documents
                  WHERE client_id = :c AND doc_type = 'invoice'
                    AND status <> 'draft' AND approval_status <> 'pending'",
                ['c' => $clientId],
                0
            );

            $owed = Database::first(
                "SELECT COALESCE(SUM(balance), 0) AS owing,
                        COUNT(*)                  AS unpaid,
                        MIN(issue_date)           AS oldest
                   FROM documents
                  WHERE client_id = :c AND doc_type = 'invoice'
                    AND status NOT IN ('draft','cancelled','paid')
                    AND approval_status <> 'pending'
                    AND balance > 0.009",
                ['c' => $clientId]
            ) ?: [];

            $summary['owing']  = (float) ($owed['owing'] ?? 0);
            $summary['unpaid'] = (int) ($owed['unpaid'] ?? 0);

            if (!empty($owed['oldest'])) {
                $summary['oldest_days'] = (int) floor(
                    (strtotime(date('Y-m-d')) - strtotime((string) $owed['oldest'])) / 86400
                );
            }

            // Oldest first: the one that has been waiting longest is the one
            // to offer, not whichever happens to be newest.
            $summary['pay_invoice'] = Database::first(
                "SELECT id, doc_number, balance FROM documents
                  WHERE client_id = :c AND doc_type = 'invoice'
                    AND status NOT IN ('draft','cancelled','paid')
                    AND approval_status <> 'pending'
                    AND balance > 0.009
               ORDER BY issue_date ASC, id ASC
                  LIMIT 1",
                ['c' => $clientId]
            );

            $recent = Database::all(
                "SELECT id, doc_type, doc_number, title, issue_date, status, total, balance
                   FROM documents
                  WHERE client_id = :c
                    AND doc_type IN ('quotation','invoice')
                    AND status <> 'draft' AND approval_status <> 'pending'
               ORDER BY issue_date DESC, id DESC
                  LIMIT 6",
                ['c' => $clientId]
            );

            $renewals = Database::all(
                "SELECT s.id, s.name, s.amount, s.next_renewal_date, s.billing_cycle
                   FROM subscriptions s
                  WHERE s.client_id = :c AND s.status = 'active'
                    AND s.next_renewal_date IS NOT NULL
               ORDER BY s.next_renewal_date ASC
                  LIMIT 3",
                ['c' => $clientId]
            );

            $today = strtotime(date('Y-m-d'));

            foreach ($renewals as $i => $row) {
                $renewals[$i]['days_away'] = (int) floor(
                    (strtotime((string) $row['next_renewal_date']) - $today) / 86400
                );
            }
        }

        // -- Phase 3 dashboard extras ----------------------------------------

        $activeJobs   = 0;
        $pendingProofs = 0;
        $recentNotifs = [];
        $pendingBriefs = 0;

        if ($clientId !== null) {
            $activeJobs = (int) Database::scalar(
                "SELECT COUNT(*) FROM jobs
                  WHERE client_id = :c
                    AND stage NOT IN ('delivered','cancelled','on_hold')",
                ['c' => $clientId],
                0
            );

            $pendingProofs = (int) Database::scalar(
                "SELECT COUNT(DISTINCT j.id) FROM jobs j
                   JOIN job_files f ON f.job_id = j.id
                  WHERE j.client_id = :c
                    AND j.stage = 'proof_sent'
                    AND f.file_type = 'proof'
                    AND f.status = 'pending'",
                ['c' => $clientId],
                0
            );

            // Pending briefs (job-detail requests staff have sent to the client
            // that the client has not yet submitted a quote brief for).
            $pendingBriefs = (int) Database::scalar(
                "SELECT COUNT(*) FROM job_requests
                  WHERE client_id = :c AND status IN ('sent','opened')",
                ['c' => $clientId],
                0
            );

            // Recent notifications — show up to 4 on the dashboard.
            try {
                $recentNotifs = Database::all(
                    'SELECT * FROM portal_notifications
                      WHERE client_id = :c
                   ORDER BY created_at DESC
                      LIMIT 4',
                    ['c' => $clientId]
                );
            } catch (\Throwable) {
                $recentNotifs = [];
            }
        }

        $this->view('portal/home', [
            'title'         => 'Your account',
            'me'            => $me,
            'summary'       => $summary,
            'recent'        => $recent,
            'renewals'      => $renewals,
            'canPay'        => Settings::bool('kopokopo_enabled'),
            'company'       => Settings::company(),
            'activeJobs'    => $activeJobs,
            'pendingProofs' => $pendingProofs,
            'recentNotifs'  => $recentNotifs,
            'pendingBriefs' => $pendingBriefs,
        ], 'portal');
    }

    // -- Their documents ---------------------------------------------------

    /** How many documents one page of the list holds. */
    private const PER_PAGE = 25;

    /** Quotations, or invoices — the same list with a different filter. */
    public function documents(Request $request, string $type): void
    {
        $clientId = $this->mustHaveClient();

        // A client who has been with us for years has hundreds of these. The
        // filter is what makes the list usable — almost always they came to
        // find what is still outstanding, not to read the archive.
        $show  = (string) $request->query('show', '');
        $show  = in_array($show, ['open', 'settled'], true) ? $show : '';
        $where = match ($show) {
            'open'    => $type === 'invoice'
                ? " AND balance > 0.009 AND status NOT IN ('cancelled')"
                : " AND status IN ('sent','viewed') AND (valid_until IS NULL OR valid_until >= CURDATE())",
            'settled' => $type === 'invoice'
                ? " AND balance <= 0.009"
                : " AND status IN ('accepted','rejected','expired','cancelled')",
            default   => '',
        };

        $base   = "FROM documents
                   WHERE client_id = :c AND doc_type = :t
                     AND status <> 'draft' AND approval_status <> 'pending'";
        $params = ['c' => $clientId, 't' => $type];

        $total = (int) Database::scalar("SELECT COUNT(*) " . $base . $where, $params, 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = max(1, min($pages, (int) $request->query('page', 1)));

        $rows = Database::all(
            "SELECT id, doc_number, title, issue_date, due_date, valid_until,
                    status, total, amount_paid, balance "
            . $base . $where . "
             ORDER BY issue_date DESC, id DESC
                LIMIT " . self::PER_PAGE . " OFFSET " . (($page - 1) * self::PER_PAGE),
            $params
        );

        // The tab counts, so the filter says how much is behind each one
        // rather than making them click to find out.
        $counts = [
            'all'  => (int) Database::scalar("SELECT COUNT(*) " . $base, $params, 0),
            'open' => (int) Database::scalar(
                "SELECT COUNT(*) " . $base . ($type === 'invoice'
                    ? " AND balance > 0.009 AND status NOT IN ('cancelled')"
                    : " AND status IN ('sent','viewed') AND (valid_until IS NULL OR valid_until >= CURDATE())"),
                $params,
                0
            ),
        ];
        $counts['settled'] = $counts['all'] - $counts['open'];

        $this->view('portal/documents', [
            'title'   => $type === 'invoice' ? 'Your invoices' : 'Your quotations',
            'me'      => ClientAuth::user(),
            'type'    => $type,
            'rows'    => $rows,
            'show'    => $show,
            'counts'  => $counts,
            'page'    => $page,
            'pages'   => $pages,
            'total'   => $total,
            'canPay'  => Settings::bool('kopokopo_enabled'),
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
            'payable'  => \App\Services\StkPayment::payable($doc),
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
            // What was asked for, not what the statement resolved it to.
            // Statement::build() fills an open end with today, and echoing
            // that back into the form makes it look like a filter is on
            // when none was set.
            'askedFrom' => $from,
            'askedTo'   => $to,
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

        // The pictures. /files is behind the staff guard, so until there
        // was a route for these a client browsing the catalogue saw no
        // photograph of anything we sell, while the images sat in the
        // database. One query per kind rather than one per row.
        $serviceImages = \App\Services\ImageLibrary::primaryFor(
            'service',
            array_map(static fn(array $r): int => (int) $r['id'], $services)
        );
        $productImages = \App\Services\ImageLibrary::primaryFor(
            'product',
            array_map(static fn(array $r): int => (int) $r['id'], $inventory)
        );

        foreach ($services as $n => $row) {
            $services[$n]['image'] = $serviceImages[(int) $row['id']] ?? null;
        }

        foreach ($inventory as $n => $row) {
            $inventory[$n]['image'] = $productImages[(int) $row['id']] ?? null;
        }

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

    // -- Job tracking ---------------------------------------------------------

    /**
     * Their production jobs, scoped to what a client should see.
     *
     * Production notes and internal staff detail are deliberately excluded.
     * The stage label and due date are what the client actually cares about,
     * not the internal checklist.
     */
    public function jobs(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $rows = Database::all(
            "SELECT j.id, j.job_number, j.title, j.stage, j.priority,
                    j.due_date, j.completed_at, j.delivered_at, j.created_at,
                    d.doc_number
               FROM jobs j
          LEFT JOIN documents d ON d.id = j.document_id
              WHERE j.client_id = :c AND j.stage NOT IN ('cancelled')
           ORDER BY FIELD(j.stage,
                    'pending','artwork','proof_sent','approved','production',
                    'finishing','ready','delivered','on_hold') ASC,
                    j.due_date ASC, j.id DESC",
            ['c' => $clientId]
        );

        $this->view('portal/jobs', [
            'title'   => 'Your jobs',
            'me'      => ClientAuth::user(),
            'rows'    => $rows,
            'company' => Settings::company(),
        ], 'portal');
    }

    /** One job card, trimmed for what a client should see. */
    public function job(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $job = Database::first(
            "SELECT j.id, j.job_number, j.title, j.stage, j.priority,
                    j.due_date, j.completed_at, j.delivered_at, j.created_at,
                    d.doc_number, d.id AS document_id
               FROM jobs j
          LEFT JOIN documents d ON d.id = j.document_id
              WHERE j.id = :id AND j.client_id = :c AND j.stage <> 'cancelled'",
            ['id' => $request->paramInt('id'), 'c' => $clientId]
        );

        if (!$job) {
            throw new HttpException(404, 'That job is not on your account.');
        }

        // Only show items — not production notes, not artwork files.
        $items = Database::all(
            'SELECT description, quantity, unit, is_done FROM job_items
              WHERE job_id = :id ORDER BY sort_order, id',
            ['id' => $job['id']]
        );

        // The public stage history — moves the client would understand.
        $history = Database::all(
            "SELECT to_stage, created_at FROM job_stages
              WHERE job_id = :id AND to_stage NOT IN ('on_hold')
           ORDER BY id ASC",
            ['id' => $job['id']]
        );

        // Proof files — only passed through when waiting on the client.
        $proofs = $job['stage'] === 'proof_sent'
            ? Database::all(
                "SELECT id, file_name FROM job_files
                  WHERE job_id = :id AND file_type = 'proof' AND status = 'pending'
               ORDER BY version DESC, id DESC",
                ['id' => $job['id']]
              )
            : [];

        $this->view('portal/job', [
            'title'   => $job['job_number'],
            'me'      => ClientAuth::user(),
            'job'     => $job,
            'items'   => $items,
            'history' => $history,
            'proofs'  => $proofs,
            'company' => Settings::company(),
        ], 'portal');
    }

    // -- Job requests (brief forms sent by staff) ----------------------------

    /**
     * Their outstanding job detail requests — briefs staff have asked
     * them to fill in.
     */
    public function jobRequests(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $rows = Database::all(
            "SELECT r.id, r.reference, r.brief_type, r.status,
                    r.title, r.created_at, r.submitted_at
               FROM job_requests r
              WHERE r.client_id = :c
                AND r.status NOT IN ('cancelled')
           ORDER BY FIELD(r.status,'sent','opened','draft','submitted','actioned') ASC,
                    r.created_at DESC",
            ['c' => $clientId]
        );

        $this->view('portal/job-requests', [
            'title'   => 'Your briefs',
            'me'      => ClientAuth::user(),
            'rows'    => $rows,
            'company' => Settings::company(),
        ], 'portal');
    }

    // -- Receipts -------------------------------------------------------------

    /** Their receipts — issued once a payment is recorded. */
    public function receipts(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $rows = Database::all(
            "SELECT id, doc_number, title, issue_date, total, status
               FROM documents
              WHERE client_id = :c AND doc_type = 'receipt'
                AND status <> 'cancelled'
           ORDER BY issue_date DESC, id DESC
              LIMIT 60",
            ['c' => $clientId]
        );

        $this->view('portal/receipts', [
            'title'   => 'Your receipts',
            'me'      => ClientAuth::user(),
            'rows'    => $rows,
            'company' => Settings::company(),
        ], 'portal');
    }

    /** One receipt, in full. Reuses the existing document view. */
    public function receipt(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $doc = Database::first(
            "SELECT * FROM documents
              WHERE id = :id AND client_id = :c AND doc_type = 'receipt'
                AND status <> 'cancelled'",
            ['id' => $request->paramInt('id'), 'c' => $clientId]
        );

        if (!$doc) {
            throw new HttpException(404, 'That receipt is not on your account.');
        }

        $this->view('portal/document', [
            'title'    => $doc['doc_number'],
            'me'       => ClientAuth::user(),
            'type'     => 'receipt',
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
            'payments' => [],   // a receipt is the payment record itself
            'payable'  => null, // receipts are already settled
            'company'  => Settings::company(),
        ], 'portal');
    }

    // -- Profile & notification preferences -----------------------------------

    public function profile(Request $request): void
    {
        $me = ClientAuth::user();

        if (!$me) {
            throw new HttpException(403, 'Please sign in.');
        }

        $this->view('portal/profile', [
            'title'   => 'Your profile',
            'me'      => $me,
            'company' => Settings::company(),
        ], 'portal');
    }

    public function updateProfile(Request $request): void
    {
        $me = ClientAuth::user();

        if (!$me) {
            throw new HttpException(403, 'Please sign in.');
        }

        $action = (string) $request->input('action', 'details');

        if ($action === 'password') {
            $current = (string) $request->input('current_password', '');
            $new     = (string) $request->input('new_password', '');
            $confirm = (string) $request->input('confirm_password', '');

            if (!password_verify($current, (string) ($me['password_hash'] ?? ''))) {
                Session::error('Your current password is not right.');
                Response::to('/portal/profile');
            }

            if (mb_strlen($new) < 8) {
                Session::error('Choose a new password of at least 8 characters.');
                Response::to('/portal/profile');
            }

            if ($new !== $confirm) {
                Session::error('The two passwords do not match.');
                Response::to('/portal/profile');
            }

            Database::update('client_users',
                ['password_hash' => password_hash($new, PASSWORD_DEFAULT)],
                ['id' => $me['id']]
            );

            Session::success('Password changed.');
            Response::to('/portal/profile');
        }

        // Default: update contact details.
        $name  = mb_substr(trim((string) $request->input('name', '')), 0, 160);
        $phone = mb_substr(trim((string) $request->input('phone', '')), 0, 30);

        if ($name === '') {
            Session::error('Your name cannot be blank.');
            Response::to('/portal/profile');
        }

        Database::update('client_users',
            ['name' => $name, 'phone' => $phone ?: null],
            ['id' => $me['id']]
        );

        Session::success('Profile updated.');
        Response::to('/portal/profile');
    }

    // -- Paying ---------------------------------------------------------------

    /** Send an M-Pesa prompt for one of their invoices. */
    public function pay(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $doc = Database::first(
            "SELECT * FROM documents
              WHERE id = :id AND client_id = :c AND doc_type = 'invoice'
                AND status <> 'draft' AND approval_status <> 'pending'",
            ['id' => $request->paramInt('id'), 'c' => $clientId]
        );

        if (!$doc) {
            throw new HttpException(404, 'That invoice is not on your account.');
        }

        // The amount, the rate limit and the one-prompt-at-a-time rule all
        // live in StkPayment, shared with the public share link. Two copies
        // of that reasoning would be two places to get it wrong.
        $result = \App\Services\StkPayment::request(
            $doc,
            (string) $request->input('phone', ''),
            'client portal'
        );

        if (!empty($result['stk_id'])) {
            // Held in the session so the page that follows knows which
            // request to watch, without an id in a URL anyone could change.
            Session::put('portal_stk_id', (int) $result['stk_id']);
        }

        if (!$result['ok']) {
            Session::error($result['error'] ?? 'The payment could not be started.');
        } elseif (!empty($result['pending'])) {
            Session::warning($result['error']);
        } else {
            Session::success('Check your phone and enter your M-Pesa PIN to complete the payment.');
        }

        Response::to('/portal/invoices/' . $doc['id']);
    }

    /** Polled by their browser while a prompt is outstanding. */
    public function payStatus(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $doc = Database::first(
            "SELECT id, balance, status FROM documents
              WHERE id = :id AND client_id = :c AND doc_type = 'invoice'",
            ['id' => $request->paramInt('id'), 'c' => $clientId]
        );

        if (!$doc) {
            Response::json(['ok' => false], 404);
        }

        $row = \App\Services\StkPayment::status(
            (int) Session::get('portal_stk_id', 0),
            (int) $doc['id']
        );

        if (!$row) {
            Response::json(['ok' => true, 'state' => 'none']);
        }

        Response::json([
            'ok'      => true,
            'state'   => $row['status'],
            'receipt' => $row['mpesa_receipt'] ?? null,
            'message' => $row['result_desc'] ?? null,
            'balance' => money($doc['balance']),
            'settled' => (float) $doc['balance'] <= 0.009,
        ]);
    }

    // -- Sending us files -----------------------------------------------------

    public function uploads(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $this->view('portal/uploads', [
            'title'   => 'Send us your artwork',
            'me'      => ClientAuth::user(),
            'rows'    => Database::all(
                'SELECT * FROM portal_uploads WHERE client_id = :c ORDER BY created_at DESC LIMIT 60',
                ['c' => $clientId]
            ),
            'enabled' => Settings::bool('portal_uploads_enabled', true),
            'maxMb'   => (int) \App\Core\Config::get('uploads.max_size_mb', 8),
            'company' => Settings::company(),
        ], 'portal');
    }

    /** Take whatever they sent. */
    public function upload(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        if (!Settings::bool('portal_uploads_enabled', true)) {
            Session::error('Sending files is switched off at the moment. Please email them to us.');
            Response::to('/portal/uploads');
        }

        // Print-ready artwork is large and the disk is not. Without a cap an
        // upload form is a way to fill it.
        $perHour = max(1, Settings::int('portal_uploads_per_hour', 20));

        $recent = (int) Database::scalar(
            'SELECT COUNT(*) FROM portal_uploads
              WHERE client_id = :c AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            ['c' => $clientId],
            0
        );

        if ($recent >= $perHour) {
            Session::error('That is a lot of files in one go. Please try again in an hour, or email the rest to us.');
            Response::to('/portal/uploads');
        }

        $files = $_FILES['files'] ?? null;

        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
            Session::error('Choose a file to send.');
            Response::to('/portal/uploads');
        }

        $note  = mb_substr(trim((string) $request->input('note')), 0, 500) ?: null;
        $me    = ClientAuth::user();
        $saved = 0;
        $count = min(count($files['name']), max(1, $perHour - $recent));

        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $one = [
                'name'     => $files['name'][$i]     ?? '',
                'type'     => $files['type'][$i]     ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error'    => $files['error'][$i]    ?? UPLOAD_ERR_OK,
                'size'     => $files['size'][$i]     ?? 0,
            ];

            // A bad file is reported and skipped rather than losing the
            // whole batch — somebody sending six files should not lose five
            // of them because the sixth was the wrong format.
            try {
                $stored = $this->storeUpload($one, 'portal/' . $clientId);
            } catch (\Throwable $e) {
                Session::error($one['name'] . ' was not sent: ' . $e->getMessage());
                continue;
            }

            if ($stored === null) {
                continue;
            }

            Database::insert('portal_uploads', [
                'client_id'      => $clientId,
                'client_user_id' => $me['id'] ?? null,
                'original_name'  => mb_substr((string) $one['name'], 0, 255),
                'stored_name'    => $stored,
                'mime'           => mb_substr((string) $one['type'], 0, 120),
                'bytes'          => (int) $one['size'],
                'note'           => $note,
            ]);

            $saved++;
        }

        if ($saved === 0) {
            Session::error('Nothing was sent. Check the file type and try again.');
            Response::to('/portal/uploads');
        }

        // Production are the people waiting on artwork.
        StaffNotifier::notify(
            StaffNotifier::withRole(['admin', 'manager', 'production', 'designer']),
            [
                'event'       => 'portal_upload',
                'title'       => ($me['client_name'] ?? 'A client') . ' has sent ' . $saved . ' file(s)',
                'body'        => $note ?: 'Artwork or documents sent through the portal.',
                'link'        => '/portal-uploads',
                'entity_type' => 'client',
                'entity_id'   => $clientId,
            ],
            ['email' => true, 'sms' => false]
        );

        Session::success($saved . ' file(s) sent. Our team will pick them up from here.');
        Response::to('/portal/uploads');
    }

    // -- Quotation acceptance -----------------------------------------------

    /**
     * The client accepts a quotation from inside the portal.
     *
     * Same outcome as the public share link, but scoped by their session so
     * no token is involved. The accepted_name comes from their profile rather
     * than being typed — the record is just as good and the UX is one click.
     */
    public function acceptQuotation(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $doc = Database::first(
            "SELECT * FROM documents
              WHERE id = :id AND client_id = :c AND doc_type = 'quotation'
                AND status NOT IN ('draft','cancelled','accepted','rejected')
                AND approval_status <> 'pending'",
            ['id' => $request->paramInt('id'), 'c' => $clientId]
        );

        if (!$doc) {
            throw new HttpException(404, 'That quotation is not available for acceptance.');
        }

        // Expired quotations cannot be accepted. The client must ask for a
        // fresh one — the price may have changed.
        if ($doc['valid_until'] && strtotime((string) $doc['valid_until']) < strtotime(date('Y-m-d'))) {
            Session::error('This quotation has expired. Please contact us for a current one.');
            Response::to('/portal/quotations/' . $doc['id']);
        }

        $me = ClientAuth::user();

        Database::update('documents', [
            'status'        => 'accepted',
            'accepted_at'   => date('Y-m-d H:i:s'),
            'accepted_name' => mb_substr((string) ($me['name'] ?? 'Portal user'), 0, 160),
            'accepted_ip'   => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ], ['id' => $doc['id']]);

        StaffNotifier::notify(
            StaffNotifier::withRole(['admin', 'manager', 'sales']),
            [
                'event'       => 'quotation_accepted',
                'title'       => ($me['client_name'] ?? 'A client') . ' has accepted ' . $doc['doc_number'],
                'body'        => $doc['title'] ?: '',
                'link'        => '/documents/' . $doc['id'],
                'entity_type' => 'document',
                'entity_id'   => (int) $doc['id'],
            ],
            ['email' => true, 'sms' => true]
        );

        Session::success('You have accepted ' . $doc['doc_number'] . '. We will be in touch to start the work.');
        Response::to('/portal/quotations/' . $doc['id']);
    }

    /**
     * The client declines a quotation from inside the portal.
     *
     * Staff are told so they can follow up. The reason is optional and kept
     * short — this is a reply, not a form.
     */
    public function rejectQuotation(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $doc = Database::first(
            "SELECT * FROM documents
              WHERE id = :id AND client_id = :c AND doc_type = 'quotation'
                AND status NOT IN ('draft','cancelled','accepted','rejected')
                AND approval_status <> 'pending'",
            ['id' => $request->paramInt('id'), 'c' => $clientId]
        );

        if (!$doc) {
            throw new HttpException(404, 'That quotation is not available.');
        }

        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 255) ?: null;

        Database::update('documents', [
            'status' => 'rejected',
        ], ['id' => $doc['id']]);

        // Store the reason in the notes field if given — there is no
        // dedicated rejection-reason column, and notes is the right place.
        if ($reason) {
            $existing = trim((string) ($doc['notes'] ?? ''));
            $note     = ($existing !== '' ? $existing . "\n\n" : '') . 'Declined via portal: ' . $reason;
            Database::update('documents', ['notes' => $note], ['id' => $doc['id']]);
        }

        $me = ClientAuth::user();

        StaffNotifier::notify(
            StaffNotifier::withRole(['admin', 'manager', 'sales']),
            [
                'event'       => 'quotation_rejected',
                'title'       => ($me['client_name'] ?? 'A client') . ' has declined ' . $doc['doc_number'],
                'body'        => $reason ?: 'No reason given.',
                'link'        => '/documents/' . $doc['id'],
                'entity_type' => 'document',
                'entity_id'   => (int) $doc['id'],
            ],
            ['email' => true, 'sms' => false]
        );

        Session::success('Noted — we have recorded that you are not proceeding with ' . $doc['doc_number'] . '.');
        Response::to('/portal/quotations/' . $doc['id']);
    }

    // -- Proof approval -------------------------------------------------------

    /**
     * The client approves or requests changes on a proof.
     *
     * Called from the job detail page when the job is at proof_sent stage.
     * The file record is updated; if approved the job advances to 'approved'.
     * Staff are notified either way.
     */
    public function approveProof(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $job = Database::first(
            "SELECT * FROM jobs
              WHERE id = :id AND client_id = :c AND stage = 'proof_sent'",
            ['id' => $request->paramInt('id'), 'c' => $clientId]
        );

        if (!$job) {
            throw new HttpException(404, 'No proof is waiting for your approval on that job.');
        }

        $action   = (string) $request->input('proof_action', 'approve');
        $feedback = mb_substr(trim((string) $request->input('feedback', '')), 0, 500) ?: null;

        if (!in_array($action, ['approve', 'changes'], true)) {
            $action = 'approve';
        }

        // Update all pending proof files for this job.
        Database::query(
            "UPDATE job_files
                SET status           = :s,
                    client_feedback  = :fb,
                    approved_at      = :at
              WHERE job_id   = :jid
                AND file_type = 'proof'
                AND status    = 'pending'",
            [
                's'   => $action === 'approve' ? 'approved' : 'rejected',
                'fb'  => $feedback,
                'at'  => $action === 'approve' ? date('Y-m-d H:i:s') : null,
                'jid' => $job['id'],
            ]
        );

        if ($action === 'approve') {
            Database::update('jobs', ['stage' => 'approved'], ['id' => $job['id']]);

            Database::insert('job_stages', [
                'job_id'     => $job['id'],
                'from_stage' => 'proof_sent',
                'to_stage'   => 'approved',
                'notes'      => 'Approved by client via portal.',
            ]);

            ClientNotifier::notify(
                $clientId,
                'proof_approved',
                'Proof approved — ' . $job['job_number'] . ' is going to production',
                'Your approval has been recorded and the job will move to press.',
                '/portal/jobs/' . $job['id']
            );

            StaffNotifier::notify(
                StaffNotifier::withRole(['admin', 'manager', 'production']),
                [
                    'event'       => 'proof_approved',
                    'title'       => 'Client approved proof on ' . $job['job_number'],
                    'body'        => $feedback ?: 'No notes.',
                    'link'        => '/jobs/' . $job['id'],
                    'entity_type' => 'job',
                    'entity_id'   => (int) $job['id'],
                ],
                ['email' => true, 'sms' => true]
            );

            Session::success('Proof approved. The job is now going to production.');
        } else {
            StaffNotifier::notify(
                StaffNotifier::withRole(['admin', 'manager', 'production']),
                [
                    'event'       => 'proof_changes_requested',
                    'title'       => 'Client requested changes on ' . $job['job_number'],
                    'body'        => $feedback ?: 'No notes left.',
                    'link'        => '/jobs/' . $job['id'],
                    'entity_type' => 'job',
                    'entity_id'   => (int) $job['id'],
                ],
                ['email' => true, 'sms' => true]
            );

            Session::success('Changes noted — our design team will revise the proof and send it again.');
        }

        Response::to('/portal/jobs/' . $job['id']);
    }

    /**
     * Serve a proof file to the authenticated client.
     *
     * Proof images are in the uploads directory behind staff auth normally.
     * This route checks that the file belongs to a job owned by this client
     * before streaming it — the file is never exposed to a different account.
     */
    public function proofFile(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        $file = Database::first(
            "SELECT f.file_path, f.file_name
               FROM job_files f
               JOIN jobs j ON j.id = f.job_id
              WHERE f.id = :id AND j.client_id = :c
                AND f.file_type IN ('proof','artwork','final')",
            ['id' => $request->paramInt('id'), 'c' => $clientId]
        );

        if (!$file) {
            throw new HttpException(404, 'File not found.');
        }

        $path = storage_path($file['file_path']);

        if (!file_exists($path)) {
            throw new HttpException(404, 'File not found on disk.');
        }

        $mime = mime_content_type($path) ?: 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . addslashes($file['file_name']) . '"');
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

    // -- Support messaging ----------------------------------------------------

    /** The client's message thread with the team. */
    public function support(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        // Mark all staff replies as read now the client has opened the thread.
        ClientNotifier::markMessagesRead($clientId);

        $messages = Database::all(
            'SELECT m.*, u.name AS staff_name
               FROM portal_messages m
          LEFT JOIN users u ON u.id = m.staff_user_id
             WHERE m.client_id = :c
          ORDER BY m.created_at ASC',
            ['c' => $clientId]
        );

        $this->view('portal/support', [
            'title'    => 'Support',
            'me'       => ClientAuth::user(),
            'messages' => $messages,
            'company'  => Settings::company(),
        ], 'portal');
    }

    /** The client sends a message. */
    public function sendMessage(Request $request): void
    {
        $clientId = $this->mustHaveClient();
        $me       = ClientAuth::user();

        $body = mb_substr(trim((string) $request->input('body', '')), 0, 3000);

        if ($body === '') {
            Session::error('Please write a message before sending.');
            Response::to('/portal/support');
        }

        Database::insert('portal_messages', [
            'client_id'      => $clientId,
            'sender'         => 'client',
            'body'           => $body,
            'client_user_id' => $me['id'] ?? null,
        ]);

        // Tell staff so the message does not sit unread.
        StaffNotifier::notify(
            StaffNotifier::withRole(['admin', 'manager']),
            [
                'event'       => 'portal_message',
                'title'       => ($me['client_name'] ?? 'A client') . ' sent a portal message',
                'body'        => mb_substr($body, 0, 120),
                'link'        => '/portal-support/' . $clientId,
                'entity_type' => 'client',
                'entity_id'   => $clientId,
            ],
            ['email' => true, 'sms' => false]
        );

        Session::success('Message sent.');
        Response::to('/portal/support');
    }

    // -- Notification feed ----------------------------------------------------

    /** The full notification list. Marks everything read on open. */
    public function notifications(Request $request): void
    {
        $clientId = $this->mustHaveClient();

        // Fetch before marking read so the page can still show which were new.
        $rows = Database::all(
            'SELECT * FROM portal_notifications
              WHERE client_id = :c
           ORDER BY created_at DESC
              LIMIT 80',
            ['c' => $clientId]
        );

        ClientNotifier::markAllRead($clientId);

        $this->view('portal/notifications', [
            'title'   => 'Notifications',
            'me'      => ClientAuth::user(),
            'rows'    => $rows,
            'company' => Settings::company(),
        ], 'portal');
    }

    /** Mark all as read (POST from the bell dropdown). */
    public function markNotificationsRead(Request $request): void
    {
        $clientId = $this->mustHaveClient();
        ClientNotifier::markAllRead($clientId);
        Response::json(['ok' => true]);
    }
}

