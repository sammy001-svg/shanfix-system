<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Numbering;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\Notifier;
use App\Services\Statement;

class ClientController extends Controller
{
    public function index(Request $request): void
    {
        $search = (string) $request->query('q', '');
        $status = (string) $request->query('status', '');
        $type   = (string) $request->query('type', '');
        $sort   = (string) $request->query('sort', 'recent');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(c.name LIKE :q OR c.email LIKE :q2 OR c.phone LIKE :q3 OR c.client_code LIKE :q4 OR c.contact_person LIKE :q5)';
            $params['q'] = $params['q2'] = $params['q3'] = $params['q4'] = $params['q5'] = '%' . $search . '%';
        }

        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }

        if (in_array($type, ['individual', 'company'], true)) {
            $where[] = 'c.client_type = :type';
            $params['type'] = $type;
        }

        $orderBy = match ($sort) {
            'name'    => 'c.name ASC',
            'balance' => 'outstanding DESC',
            'billed'  => 'total_billed DESC',
            default   => 'c.created_at DESC',
        };

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM clients c WHERE {$clause}", $params, 0);
        $pager = $this->paginate($total, 25);

        // Aggregate billing figures per client in one pass.
        $clients = Database::all(
            "SELECT c.*,
                    COALESCE(inv.total_billed, 0)  AS total_billed,
                    COALESCE(inv.outstanding, 0)   AS outstanding,
                    COALESCE(inv.invoice_count, 0) AS invoice_count
               FROM clients c
          LEFT JOIN (
                    SELECT client_id,
                           SUM(total)   AS total_billed,
                           SUM(balance) AS outstanding,
                           COUNT(*)     AS invoice_count
                      FROM documents
                     WHERE doc_type = 'invoice' AND status <> 'cancelled'
                  GROUP BY client_id
                 ) inv ON inv.client_id = c.id
              WHERE {$clause}
           ORDER BY {$orderBy}
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $summary = Database::first(
            "SELECT
                (SELECT COUNT(*) FROM clients WHERE status = 'active') AS active_clients,
                (SELECT COUNT(*) FROM clients) AS all_clients,
                (SELECT COALESCE(SUM(balance), 0) FROM documents
                  WHERE doc_type = 'invoice' AND status NOT IN ('cancelled','paid')) AS total_outstanding,
                (SELECT COALESCE(SUM(total), 0) FROM documents
                  WHERE doc_type = 'invoice' AND status <> 'cancelled'
                    AND YEAR(issue_date) = YEAR(CURDATE())) AS billed_ytd"
        );

        $this->view('clients/index', [
            'title'   => 'Clients',
            'clients' => $clients,
            'pager'   => $pager,
            'summary' => $summary,
            'filters' => compact('search', 'status', 'type', 'sort'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('clients.manage');

        $this->view('clients/form', [
            'title'  => 'New Client',
            'client' => null,
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('clients.manage');

        $data = $this->validated($request, null);
        $data['client_code'] = Numbering::next('client');
        $data['created_by']  = Auth::id();

        $id = Database::insert('clients', $data);

        ActivityLog::record('client_created', 'client', $id, 'Registered client ' . $data['name']);
        Session::success($data['name'] . ' has been registered as a client.');
        Response::to('/clients/' . $id);
    }

    /**
     * Client profile. The tab controls which related data is loaded so a
     * client with hundreds of documents still opens quickly.
     */
    public function show(Request $request): void
    {
        $client = $this->findOrFail($request->paramInt('id'));
        $tab    = (string) $request->query('tab', 'overview');

        $allowedTabs = ['overview', 'quotations', 'invoices', 'receipts', 'payments', 'activity'];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'overview';
        }

        $stats = Database::first(
            "SELECT
                COALESCE(SUM(CASE WHEN doc_type='invoice' AND status <> 'cancelled' THEN total END), 0)   AS total_billed,
                COALESCE(SUM(CASE WHEN doc_type='invoice' AND status <> 'cancelled' THEN amount_paid END), 0) AS total_paid,
                COALESCE(SUM(CASE WHEN doc_type='invoice' AND status NOT IN ('cancelled','paid') THEN balance END), 0) AS outstanding,
                COUNT(CASE WHEN doc_type='quotation' THEN 1 END) AS quotation_count,
                COUNT(CASE WHEN doc_type='invoice'   THEN 1 END) AS invoice_count,
                COUNT(CASE WHEN doc_type='receipt'   THEN 1 END) AS receipt_count,
                COUNT(CASE WHEN doc_type='invoice' AND status = 'overdue' THEN 1 END) AS overdue_count
               FROM documents
              WHERE client_id = :id",
            ['id' => $client['id']]
        );

        $paymentCount = (int) Database::scalar(
            "SELECT COUNT(*) FROM payments WHERE client_id = :id AND status = 'completed'",
            ['id' => $client['id']],
            0
        );

        // Recurring services this client has with us — the websites we host
        // and maintain, and what is still owed on them.
        $subscriptions = Auth::can('subscriptions.view')
            ? Database::all(
                "SELECT s.*,
                        (SELECT COALESCE(SUM(d.balance), 0)
                           FROM subscription_renewals r
                           JOIN documents d ON d.id = r.document_id
                          WHERE r.subscription_id = s.id
                            AND d.status NOT IN ('cancelled','paid','draft')) AS due_balance
                   FROM subscriptions s
                  WHERE s.client_id = :id
               ORDER BY FIELD(s.status,'active','paused','cancelled'), s.next_renewal_date",
                ['id' => $client['id']]
            )
            : [];

        $data = [
            'title'         => $client['name'],
            'client'        => $client,
            'stats'         => $stats,
            'subscriptions' => $subscriptions,
            'renewalDue'    => \App\Services\Renewals::balanceForClient((int) $client['id']),
            'siteCount'     => \App\Services\Renewals::countForClient((int) $client['id']),
            'paymentCount'  => $paymentCount,
            'tab'          => $tab,
            'documents'    => [],
            'payments'     => [],
            'activities'   => [],
            'openInvoices' => [],
            'stkEnabled'   => \App\Core\Settings::bool('kopokopo_enabled'),
        ];

        // Invoices with a balance — the STK Push panel targets these.
        $data['openInvoices'] = Database::all(
            "SELECT id, doc_number, total, balance, due_date, status
               FROM documents
              WHERE client_id = :id AND doc_type = 'invoice'
                AND status NOT IN ('cancelled','paid','draft')
                AND balance > 0
           ORDER BY due_date ASC, id DESC",
            ['id' => $client['id']]
        );

        if (in_array($tab, ['overview', 'quotations', 'invoices', 'receipts'], true)) {
            $typeFilter = $tab === 'overview' ? '' : " AND doc_type = '" . rtrim($tab, 's') . "'";
            $limit      = $tab === 'overview' ? 8 : 100;

            $data['documents'] = Database::all(
                "SELECT id, doc_type, doc_number, title, issue_date, due_date, valid_until,
                        status, total, amount_paid, balance
                   FROM documents
                  WHERE client_id = :id {$typeFilter}
               ORDER BY issue_date DESC, id DESC
                  LIMIT {$limit}",
                ['id' => $client['id']]
            );
        }

        if (in_array($tab, ['overview', 'payments'], true)) {
            $data['payments'] = Database::all(
                'SELECT p.*, d.doc_number, u.name AS recorded_by_name
                   FROM payments p
              LEFT JOIN documents d ON d.id = p.document_id
              LEFT JOIN users u ON u.id = p.recorded_by
                  WHERE p.client_id = :id
               ORDER BY p.created_at DESC
                  LIMIT ' . ($tab === 'overview' ? 6 : 100),
                ['id' => $client['id']]
            );
        }

        if ($tab === 'activity') {
            $data['activities'] = Database::all(
                'SELECT a.*, u.name AS user_name
                   FROM activity_log a
              LEFT JOIN users u ON u.id = a.user_id
                  WHERE a.entity_type = :type AND a.entity_id = :id
               ORDER BY a.created_at DESC
                  LIMIT 100',
                ['type' => 'client', 'id' => $client['id']]
            );
        }

        $this->view('clients/show', $data);
    }

    public function edit(Request $request): void
    {
        $this->authorize('clients.manage');

        $client = $this->findOrFail($request->paramInt('id'));

        $this->view('clients/form', [
            'title'  => 'Edit ' . $client['name'],
            'client' => $client,
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('clients.manage');

        $client = $this->findOrFail($request->paramInt('id'));
        $data   = $this->validated($request, (int) $client['id']);

        Database::update('clients', $data, ['id' => $client['id']]);

        ActivityLog::record('client_updated', 'client', (int) $client['id'], 'Updated client ' . $data['name']);
        Session::success('Client details updated.');
        Response::to('/clients/' . $client['id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('clients.delete');

        $client = $this->findOrFail($request->paramInt('id'));

        $docCount = (int) Database::scalar(
            'SELECT COUNT(*) FROM documents WHERE client_id = :id',
            ['id' => $client['id']],
            0
        );

        // Financial records must survive; archive rather than delete.
        if ($docCount > 0) {
            Database::update('clients', ['status' => 'inactive'], ['id' => $client['id']]);
            ActivityLog::record('client_archived', 'client', (int) $client['id'], 'Archived ' . $client['name']);
            Session::warning(
                $client['name'] . ' has ' . $docCount . ' document(s) on file, so the record was '
                . 'marked inactive instead of deleted. Financial history is preserved.'
            );
            Response::to('/clients/' . $client['id']);
        }

        Database::delete('clients', ['id' => $client['id']]);
        ActivityLog::record('client_deleted', 'client', (int) $client['id'], 'Deleted ' . $client['name']);
        Session::success($client['name'] . ' has been deleted.');
        Response::to('/clients');
    }

    public function export(Request $request): void
    {
        $this->authorize('clients.view');

        $rows = Database::all(
            "SELECT c.client_code, c.name, c.client_type, c.contact_person, c.email, c.phone,
                    c.kra_pin, c.city, c.status,
                    COALESCE(SUM(CASE WHEN d.doc_type='invoice' AND d.status<>'cancelled' THEN d.total END),0) AS billed,
                    COALESCE(SUM(CASE WHEN d.doc_type='invoice' AND d.status NOT IN ('cancelled','paid') THEN d.balance END),0) AS outstanding
               FROM clients c
          LEFT JOIN documents d ON d.client_id = c.id
           GROUP BY c.id
           ORDER BY c.name"
        );

        $out = array_map(static fn($r) => [
            $r['client_code'], $r['name'], $r['client_type'], $r['contact_person'] ?? '',
            $r['email'] ?? '', $r['phone'] ?? '', $r['kra_pin'] ?? '', $r['city'] ?? '',
            $r['status'], $r['billed'], $r['outstanding'],
        ], $rows);

        ActivityLog::record('clients_exported', 'client', null, 'Exported client list');

        Response::csv(
            'shanfix-clients-' . date('Y-m-d') . '.csv',
            ['Code', 'Name', 'Type', 'Contact Person', 'Email', 'Phone', 'KRA PIN', 'City', 'Status', 'Total Billed', 'Outstanding'],
            $out
        );
    }

    // -- Internals -----------------------------------------------------

    private function validated(Request $request, ?int $ignoreId): array
    {
        $v = new Validator($request->all());
        $v->require('name', 'Client name')
          ->maxLen('name', 180, 'Client name')
          ->in('client_type', ['individual', 'company'], 'Client type')
          ->email('email', 'Email address')
          ->phone('phone', 'Phone number')
          ->phone('alt_phone', 'Alternative phone')
          ->maxLen('kra_pin', 30, 'KRA PIN')
          ->maxLen('address', 255, 'Address')
          ->numeric('credit_limit', 'Credit limit')
          ->min('credit_limit', 0, 'Credit limit')
          ->in('status', ['active', 'inactive'], 'Status');

        // A client needs at least one way to be contacted.
        if (!$request->input('email') && !$request->input('phone')) {
            $v->custom('phone', false, 'Provide at least a phone number or an email address.');
        }

        if ($v->fails()) {
            $v->redirectBack($ignoreId ? "/clients/{$ignoreId}/edit" : '/clients/create');
        }

        return [
            'client_type'    => (string) $request->input('client_type', 'company'),
            'name'           => (string) $request->input('name'),
            'contact_person' => $request->input('contact_person') ?: null,
            'email'          => $request->input('email') ? strtolower((string) $request->input('email')) : null,
            'phone'          => $request->input('phone') ?: null,
            'alt_phone'      => $request->input('alt_phone') ?: null,
            'kra_pin'        => $request->input('kra_pin') ? strtoupper((string) $request->input('kra_pin')) : null,
            'address'        => $request->input('address') ?: null,
            'city'           => $request->input('city') ?: null,
            'industry'       => $request->input('industry') ?: null,
            'notes'          => $request->input('notes') ?: null,
            'credit_limit'   => $request->decimal('credit_limit'),
            'status'         => (string) $request->input('status', 'active'),
        ];
    }

    /**
     * Statement of account for one client.
     *
     * The share token is minted the first time an operator opens this, not
     * when the client record is created — a client nobody has ever sent a
     * statement to has no link that could leak.
     */
    public function statement(Request $request): void
    {
        $client = $this->findOrFail($request->paramInt('id'));

        $from = $this->dateInput($request->query('from'));
        $to   = $this->dateInput($request->query('to')) ?? date('Y-m-d');

        $token = Statement::ensureToken((int) $client['id'], $client['public_token'] ?? null);

        $this->view('clients/statement', [
            'title'     => 'Statement · ' . $client['name'],
            'statement' => Statement::build($client, $from, $to),
            'company'   => Settings::company(),
            'isPublic'  => false,
            'shareLink' => Notifier::absoluteUrl('/statement/' . $token),
            'autoPrint' => $request->query('auto') === '1',
        ], 'print');
    }

    /**
     * Send this client their statement now.
     *
     * An operator pressing send overrides the per-event switches, but only
     * on the channels they ticked — same rule as sending a document.
     */
    public function sendStatement(Request $request): void
    {
        $this->authorize('documents.manage');

        $client    = $this->findOrFail($request->paramInt('id'));
        $statement = Statement::build($client, null, date('Y-m-d'));

        $channels = array_values(array_intersect(
            $request->array('channels') ?: ['email'],
            ['email', 'sms']
        ));

        if ($channels === []) {
            Session::error('Choose at least one way to send it.');
            Response::back('/clients/' . $client['id'] . '/statement');
        }

        $result = Notifier::dispatch(
            'statement_sent',
            Notifier::statementContext($client, $statement),
            true,
            $channels
        );

        if ($result['queued'] === 0) {
            foreach ($result['skipped'] as $reason) {
                Session::error($reason);
            }
            if ($result['skipped'] === []) {
                Session::error('Nothing could be queued for sending.');
            }
            Response::back('/clients/' . $client['id'] . '/statement');
        }

        $send = Notifier::processQueue(10);

        if ($send['sent'] > 0) {
            Database::update(
                'clients',
                ['statement_sent_at' => date('Y-m-d H:i:s')],
                ['id' => $client['id']]
            );

            ActivityLog::record(
                'statement_sent',
                'client',
                (int) $client['id'],
                'Statement sent to ' . $client['name']
            );

            Session::success($send['sent'] . ' message(s) sent to ' . $client['name'] . '.');
        }

        if ($send['failed'] > 0) {
            Session::error('Some messages failed — check the message log.');
        }

        foreach ($result['skipped'] as $reason) {
            Session::warning($reason);
        }

        Response::to('/clients/' . $client['id'] . '/statement');
    }

    /** A yyyy-mm-dd query value, or null when absent or malformed. */
    private function dateInput(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function findOrFail(int $id): array
    {
        $client = Database::first(
            'SELECT c.*, u.name AS created_by_name
               FROM clients c
          LEFT JOIN users u ON u.id = c.created_by
              WHERE c.id = :id',
            ['id' => $id]
        );

        if (!$client) {
            throw new HttpException(404, 'That client does not exist.');
        }

        return $client;
    }
}
