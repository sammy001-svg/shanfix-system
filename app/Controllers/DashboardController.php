<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;

class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) Auth::id();

        // A salesperson gets their own pipeline instead of the general view.
        if ($this->isSalesFocused()) {
            $this->salesDashboard($userId);
            return;
        }

        // The dashboard is assembled from what this person is allowed to see.
        // The figures are gated in the view as well; skipping the queries here
        // means the numbers are never fetched for someone who may not see
        // them, rather than fetched and then hidden.
        $canSeeMoney  = Auth::can('payments.view');
        $canSeeMargin = Auth::can('expenses.view');

        $blankMoney = [
            'collected_month' => 0, 'collected_today' => 0, 'outstanding'    => 0,
            'overdue_value'   => 0, 'expenses_month'  => 0, 'invoiced_month' => 0,
        ];

        $money = (!$canSeeMoney && !$canSeeMargin) ? $blankMoney : Database::first(
            "SELECT
                COALESCE((SELECT SUM(amount) FROM payments
                           WHERE status='completed'
                             AND MONTH(COALESCE(paid_at, created_at)) = MONTH(CURDATE())
                             AND YEAR(COALESCE(paid_at, created_at)) = YEAR(CURDATE())), 0) AS collected_month,
                COALESCE((SELECT SUM(amount) FROM payments
                           WHERE status='completed'
                             AND DATE(COALESCE(paid_at, created_at)) = CURDATE()), 0) AS collected_today,
                COALESCE((SELECT SUM(balance) FROM documents
                           WHERE doc_type='invoice'
                             AND status NOT IN ('cancelled','paid','draft')), 0) AS outstanding,
                COALESCE((SELECT SUM(balance) FROM documents
                           WHERE doc_type='invoice'
                             AND status NOT IN ('cancelled','paid','draft')
                             AND due_date < CURDATE()), 0) AS overdue_value,
                COALESCE((SELECT SUM(amount) FROM expenses
                           WHERE MONTH(expense_date) = MONTH(CURDATE())
                             AND YEAR(expense_date) = YEAR(CURDATE())), 0) AS expenses_month,
                COALESCE((SELECT SUM(total) FROM documents
                           WHERE doc_type='invoice' AND status <> 'cancelled'
                             AND MONTH(issue_date) = MONTH(CURDATE())
                             AND YEAR(issue_date) = YEAR(CURDATE())), 0) AS invoiced_month"
        );

        // Same month last year, for a like-for-like comparison.
        $lastMonthCollected = !$canSeeMoney ? 0.0 : (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
              WHERE status='completed'
                AND COALESCE(paid_at, created_at) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                AND COALESCE(paid_at, created_at) <  DATE_FORMAT(CURDATE(), '%Y-%m-01')",
            [],
            0
        );

        $counts = Database::first(
            "SELECT
                (SELECT COUNT(*) FROM clients WHERE status='active') AS clients,
                (SELECT COUNT(*) FROM leads WHERE stage NOT IN ('won','lost')) AS open_leads,
                (SELECT COUNT(*) FROM documents
                  WHERE doc_type='quotation' AND status IN ('draft','sent')) AS open_quotes,
                (SELECT COUNT(*) FROM documents
                  WHERE doc_type='invoice' AND status NOT IN ('cancelled','paid','draft')
                    AND due_date < CURDATE()) AS overdue_invoices,
                (SELECT COUNT(*) FROM inventory_items
                  WHERE is_active=1 AND quantity <= reorder_level) AS low_stock"
        );

        $myReminders = Database::all(
            'SELECT r.*, l.name AS lead_name, c.name AS client_name
               FROM reminders r
          LEFT JOIN leads l ON l.id = r.lead_id
          LEFT JOIN clients c ON c.id = r.client_id
              WHERE r.user_id = :uid AND r.is_done = 0
           ORDER BY r.remind_at ASC
              LIMIT 8',
            ['uid' => $userId]
        );

        $overdueInvoices = Database::all(
            "SELECT d.id, d.doc_number, d.due_date, d.balance, c.name AS client_name, c.id AS client_id
               FROM documents d
               JOIN clients c ON c.id = d.client_id
              WHERE d.doc_type='invoice'
                AND d.status NOT IN ('cancelled','paid','draft')
                AND d.due_date < CURDATE() AND d.balance > 0
           ORDER BY d.due_date ASC
              LIMIT 8"
        );

        $recentPayments = !$canSeeMoney ? [] : Database::all(
            "SELECT p.payment_number, p.amount, p.method, p.paid_at, p.created_at,
                    c.name AS client_name, c.id AS client_id, d.doc_number
               FROM payments p
               JOIN clients c ON c.id = p.client_id
          LEFT JOIN documents d ON d.id = p.document_id
              WHERE p.status='completed'
           ORDER BY COALESCE(p.paid_at, p.created_at) DESC
              LIMIT 8"
        );

        $hotLeads = Database::all(
            "SELECT l.id, l.name, l.company, l.stage, l.estimated_value, l.probability,
                    s.name AS service_name, u.name AS assignee_name
               FROM leads l
          LEFT JOIN services s ON s.id = l.service_id
          LEFT JOIN users u ON u.id = l.assigned_to
              WHERE l.stage IN ('proposal','negotiation','qualified')
           ORDER BY l.estimated_value DESC
              LIMIT 6"
        );

        $lowStock = Database::all(
            'SELECT id, name, sku, quantity, reorder_level, unit
               FROM inventory_items
              WHERE is_active = 1 AND quantity <= reorder_level
           ORDER BY (quantity - reorder_level) ASC
              LIMIT 6'
        );

        // Production: what is due or late on the floor.
        $jobCounts = Database::first(
            "SELECT
                COUNT(CASE WHEN stage NOT IN ('delivered','cancelled') THEN 1 END) AS active,
                COUNT(CASE WHEN stage NOT IN ('delivered','cancelled')
                            AND due_date IS NOT NULL AND due_date < NOW() THEN 1 END) AS overdue,
                COUNT(CASE WHEN stage = 'ready' THEN 1 END) AS ready
               FROM jobs"
        );

        $urgentJobs = Database::all(
            "SELECT j.id, j.job_number, j.title, j.stage, j.priority, j.due_date,
                    c.name AS client_name, u.name AS assignee_name
               FROM jobs j
               JOIN clients c ON c.id = j.client_id
          LEFT JOIN users u ON u.id = j.assigned_to
              WHERE j.stage NOT IN ('delivered','cancelled')
           ORDER BY FIELD(j.priority,'urgent','high','normal','low'),
                    j.due_date IS NULL, j.due_date ASC
              LIMIT 6"
        );

        // Six-month cash trend for the mini chart.
        // Two queries a month for six months, feeding a chart only the
        // finance side is shown. Not worth running for anyone else.
        $trend    = [];
        $months   = Auth::can('reports.view') ? 5 : -1;

        for ($i = $months; $i >= 0; $i--) {
            $start = date('Y-m-01', strtotime("-{$i} months"));
            $end   = date('Y-m-t', strtotime($start));

            $trend[] = [
                'label'    => date('M', strtotime($start)),
                'income'   => (float) Database::scalar(
                    "SELECT COALESCE(SUM(amount), 0) FROM payments
                      WHERE status='completed'
                        AND DATE(COALESCE(paid_at, created_at)) BETWEEN :s AND :e",
                    ['s' => $start, 'e' => $end],
                    0
                ),
                'expenses' => (float) Database::scalar(
                    'SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN :s AND :e',
                    ['s' => $start, 'e' => $end],
                    0
                ),
            ];
        }

        $this->view('dashboard/index', [
            'title'              => 'Dashboard',
            'money'              => $money,
            'lastMonthCollected' => $lastMonthCollected,
            'counts'             => $counts,
            'myReminders'        => $myReminders,
            'overdueInvoices'    => $overdueInvoices,
            'recentPayments'     => $recentPayments,
            'hotLeads'           => $hotLeads,
            'lowStock'           => $lowStock,
            'jobCounts'          => $jobCounts,
            'urgentJobs'         => $urgentJobs,
            'trend'              => $trend,
            'dueReminders'       => $myReminders !== [],
        ]);
    }


    /**
     * Is this a salesperson rather than someone running the place?
     *
     * A manager who also holds the sales role wants the whole picture, so
     * the general dashboard wins whenever someone can see all leads.
     */
    private function isSalesFocused(): bool
    {
        return Auth::is('sales') && !Auth::can('leads.view_all');
    }

    /**
     * The dashboard a salesperson actually needs: their own pipeline, the
     * follow-ups due today, and what their quotations have turned into.
     *
     * Every figure is scoped to leads allocated to them, so it matches the
     * pipeline they can open. A number they cannot drill into would only
     * raise questions nobody could answer.
     */
    private function salesDashboard(int $userId): void
    {
        $stages = LeadController::STAGES;

        $pipeline = Database::all(
            "SELECT l.stage,
                    COUNT(*) AS count,
                    COALESCE(SUM(l.estimated_value), 0) AS value
               FROM leads l
              WHERE EXISTS (SELECT 1 FROM lead_assignees la
                             WHERE la.lead_id = l.id AND la.user_id = :me)
                AND l.stage NOT IN ('won', 'lost')
           GROUP BY l.stage",
            ['me' => $userId]
        );

        $byStage = [];
        $openValue = 0.0;
        $openCount = 0;

        foreach ($pipeline as $row) {
            $byStage[$row['stage']] = $row;
            $openValue += (float) $row['value'];
            $openCount += (int) $row['count'];
        }

        $thisMonth = Database::first(
            "SELECT COUNT(CASE WHEN l.stage = 'won'  THEN 1 END) AS won,
                    COUNT(CASE WHEN l.stage = 'lost' THEN 1 END) AS lost,
                    COALESCE(SUM(CASE WHEN l.stage = 'won' THEN l.estimated_value END), 0) AS won_value
               FROM leads l
              WHERE EXISTS (SELECT 1 FROM lead_assignees la
                             WHERE la.lead_id = l.id AND la.user_id = :me)
                AND l.updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
            ['me' => $userId]
        );

        // Leads with nothing logged for a fortnight. The most useful thing a
        // sales dashboard can do is name the ones going quiet.
        $stale = Database::all(
            "SELECT l.id, l.lead_number, l.name, l.company, l.stage, l.estimated_value,
                    l.updated_at
               FROM leads l
              WHERE EXISTS (SELECT 1 FROM lead_assignees la
                             WHERE la.lead_id = l.id AND la.user_id = :me)
                AND l.stage NOT IN ('won', 'lost')
                AND l.updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
           ORDER BY l.updated_at
              LIMIT 8",
            ['me' => $userId]
        );

        $reminders = Database::all(
            "SELECT r.*, l.name AS lead_name, l.lead_number
               FROM reminders r
          LEFT JOIN leads l ON l.id = r.lead_id
              WHERE r.user_id = :me AND r.is_done = 0
                AND r.remind_at <= DATE_ADD(NOW(), INTERVAL 1 DAY)
           ORDER BY r.remind_at
              LIMIT 10",
            ['me' => $userId]
        );

        // Quotations this person raised that nobody has answered yet.
        $awaiting = Database::all(
            "SELECT d.id, d.doc_number, d.doc_type, d.total, d.valid_until, c.name AS client_name
               FROM documents d
               JOIN clients c ON c.id = d.client_id
              WHERE d.created_by = :me
                AND d.doc_type IN ('quotation', 'proposal')
                AND d.status = 'sent'
           ORDER BY d.valid_until IS NULL, d.valid_until
              LIMIT 8",
            ['me' => $userId]
        );

        $this->view('dashboard/sales', [
            'title'      => 'My pipeline',
            'stages'     => $stages,
            'byStage'    => $byStage,
            'openValue'  => $openValue,
            'openCount'  => $openCount,
            'thisMonth'  => $thisMonth,
            'stale'      => $stale,
            'reminders'  => $reminders,
            'awaiting'   => $awaiting,
        ]);
    }
    /** Global search across clients, leads, documents and inventory. */
    public function search(Request $request): void
    {
        $q = trim((string) $request->query('q', ''));

        $results = ['clients' => [], 'leads' => [], 'documents' => [], 'inventory' => [], 'services' => []];

        if (mb_strlen($q) >= 2) {
            $like = '%' . $q . '%';

            if (Auth::can('clients.view')) {
                $results['clients'] = Database::all(
                    'SELECT id, name, client_code, phone, email, status
                       FROM clients
                      WHERE name LIKE :q OR client_code LIKE :q2 OR phone LIKE :q3 OR email LIKE :q4
                   ORDER BY name LIMIT 12',
                    ['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]
                );
            }

            if (Auth::can('leads.view')) {
                $results['leads'] = Database::all(
                    'SELECT id, name, company, lead_number, stage, estimated_value
                       FROM leads
                      WHERE name LIKE :q OR company LIKE :q2 OR lead_number LIKE :q3 OR phone LIKE :q4
                   ORDER BY updated_at DESC LIMIT 12',
                    ['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]
                );
            }

            if (Auth::can('documents.view')) {
                $results['documents'] = Database::all(
                    'SELECT d.id, d.doc_type, d.doc_number, d.title, d.total, d.status, d.issue_date,
                            c.name AS client_name
                       FROM documents d
                       JOIN clients c ON c.id = d.client_id
                      WHERE d.doc_number LIKE :q OR d.title LIKE :q2 OR c.name LIKE :q3
                   ORDER BY d.issue_date DESC LIMIT 15',
                    ['q' => $like, 'q2' => $like, 'q3' => $like]
                );
            }

            if (Auth::can('inventory.view')) {
                $results['inventory'] = Database::all(
                    'SELECT id, name, sku, selling_price, quantity, unit
                       FROM inventory_items
                      WHERE name LIKE :q OR sku LIKE :q2
                   ORDER BY name LIMIT 10',
                    ['q' => $like, 'q2' => $like]
                );

                $results['services'] = Database::all(
                    'SELECT id, name, code, price, pricing_type
                       FROM services
                      WHERE name LIKE :q OR code LIKE :q2
                   ORDER BY name LIMIT 10',
                    ['q' => $like, 'q2' => $like]
                );
            }
        }

        $total = array_sum(array_map('count', $results));

        $this->view('search/index', [
            'title'   => 'Search',
            'q'       => $q,
            'results' => $results,
            'total'   => $total,
        ]);
    }

    public function audit(Request $request): void
    {
        $action = (string) $request->query('action', '');
        $userId = (int) $request->query('user', 0);

        $where  = ['1=1'];
        $params = [];

        if ($action !== '') {
            $where[] = 'a.action LIKE :action';
            $params['action'] = '%' . $action . '%';
        }

        if ($userId > 0) {
            $where[] = 'a.user_id = :uid';
            $params['uid'] = $userId;
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM activity_log a WHERE {$clause}", $params, 0);
        $pager = $this->paginate($total, 50);

        $entries = Database::all(
            "SELECT a.*, u.name AS user_name, u.avatar_color
               FROM activity_log a
          LEFT JOIN users u ON u.id = a.user_id
              WHERE {$clause}
           ORDER BY a.created_at DESC, a.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $this->view('audit/index', [
            'title'   => 'Audit Trail',
            'entries' => $entries,
            'pager'   => $pager,
            'users'   => Database::all('SELECT id, name FROM users ORDER BY name'),
            'filters' => compact('action', 'userId'),
        ]);
    }
}
