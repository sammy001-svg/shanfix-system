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

        $this->view('portal/home', [
            'title'    => 'Your account',
            'me'       => $me,
            'summary'  => $summary,
            'recent'   => $recent,
            'renewals' => $renewals,
            'canPay'   => Settings::bool('kopokopo_enabled'),
            'company'  => Settings::company(),
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
}
