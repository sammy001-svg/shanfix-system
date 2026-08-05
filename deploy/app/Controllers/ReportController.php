<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

class ReportController extends Controller
{
    public function index(Request $request): void
    {
        $from = (string) $request->query('from', date('Y-01-01'));
        $to   = (string) $request->query('to', date('Y-m-d'));

        $from = date('Y-m-d', strtotime($from) ?: time());
        $to   = date('Y-m-d', strtotime($to) ?: time());

        $range = ['from' => $from, 'to' => $to];

        // -- Headline figures
        $revenue = Database::first(
            "SELECT COALESCE(SUM(total), 0)       AS invoiced,
                    COALESCE(SUM(amount_paid), 0) AS collected,
                    COALESCE(SUM(balance), 0)     AS outstanding,
                    COALESCE(SUM(vat_amount), 0)  AS output_vat,
                    COUNT(*)                      AS invoice_count
               FROM documents
              WHERE doc_type = 'invoice' AND status <> 'cancelled'
                AND issue_date BETWEEN :from AND :to",
            $range
        );

        $expenses = Database::first(
            'SELECT COALESCE(SUM(amount), 0) AS total,
                    COALESCE(SUM(vat_amount), 0) AS input_vat,
                    COUNT(*) AS count
               FROM expenses
              WHERE expense_date BETWEEN :from AND :to',
            $range
        );

        $collected = (float) $revenue['collected'];
        $spent     = (float) $expenses['total'];

        // -- Month-by-month revenue vs expenses (last 12 months)
        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("-{$i} months"));
            $monthEnd   = date('Y-m-t', strtotime($monthStart));

            $invoiced = (float) Database::scalar(
                "SELECT COALESCE(SUM(total), 0) FROM documents
                  WHERE doc_type='invoice' AND status <> 'cancelled'
                    AND issue_date BETWEEN :s AND :e",
                ['s' => $monthStart, 'e' => $monthEnd],
                0
            );

            $paid = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM payments
                  WHERE status='completed'
                    AND DATE(COALESCE(paid_at, created_at)) BETWEEN :s AND :e",
                ['s' => $monthStart, 'e' => $monthEnd],
                0
            );

            $cost = (float) Database::scalar(
                'SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN :s AND :e',
                ['s' => $monthStart, 'e' => $monthEnd],
                0
            );

            $monthly[] = [
                'label'    => date('M', strtotime($monthStart)),
                'year'     => date('Y', strtotime($monthStart)),
                'invoiced' => $invoiced,
                'paid'     => $paid,
                'expenses' => $cost,
                'profit'   => $paid - $cost,
            ];
        }

        // -- Top clients by value invoiced
        $topClients = Database::all(
            "SELECT c.id, c.name,
                    SUM(d.total)   AS invoiced,
                    SUM(d.balance) AS outstanding,
                    COUNT(*)       AS invoices
               FROM documents d
               JOIN clients c ON c.id = d.client_id
              WHERE d.doc_type='invoice' AND d.status <> 'cancelled'
                AND d.issue_date BETWEEN :from AND :to
           GROUP BY c.id, c.name
           ORDER BY invoiced DESC
              LIMIT 10",
            $range
        );

        // -- What sells: services vs inventory
        $topLines = Database::all(
            "SELECT di.description, di.item_type,
                    SUM(di.quantity)   AS qty,
                    SUM(di.line_total) AS revenue
               FROM document_items di
               JOIN documents d ON d.id = di.document_id
              WHERE d.doc_type='invoice' AND d.status <> 'cancelled'
                AND d.issue_date BETWEEN :from AND :to
           GROUP BY di.description, di.item_type
           ORDER BY revenue DESC
              LIMIT 10",
            $range
        );

        $revenueMix = Database::all(
            "SELECT di.item_type, SUM(di.line_total) AS revenue
               FROM document_items di
               JOIN documents d ON d.id = di.document_id
              WHERE d.doc_type='invoice' AND d.status <> 'cancelled'
                AND d.issue_date BETWEEN :from AND :to
           GROUP BY di.item_type
           ORDER BY revenue DESC",
            $range
        );

        // -- Expenses by category
        $expenseMix = Database::all(
            "SELECT COALESCE(c.name, 'Uncategorised') AS name, SUM(e.amount) AS total
               FROM expenses e
          LEFT JOIN categories c ON c.id = e.category_id
              WHERE e.expense_date BETWEEN :from AND :to
           GROUP BY c.id, c.name
           ORDER BY total DESC
              LIMIT 10",
            $range
        );

        // -- Receivables ageing, as at today
        $ageing = Database::first(
            "SELECT
                COALESCE(SUM(CASE WHEN due_date >= CURDATE() THEN balance END), 0) AS not_due,
                COALESCE(SUM(CASE WHEN due_date < CURDATE()
                                   AND due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                             THEN balance END), 0) AS days_0_30,
                COALESCE(SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                   AND due_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                             THEN balance END), 0) AS days_31_60,
                COALESCE(SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                                   AND due_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                             THEN balance END), 0) AS days_61_90,
                COALESCE(SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                             THEN balance END), 0) AS days_90_plus
               FROM documents
              WHERE doc_type='invoice' AND status NOT IN ('cancelled','paid','draft') AND balance > 0"
        );

        // -- Sales pipeline
        $pipeline = Database::all(
            "SELECT stage, COUNT(*) AS count, COALESCE(SUM(estimated_value), 0) AS value
               FROM leads
              WHERE stage NOT IN ('won','lost')
           GROUP BY stage"
        );

        $leadPerformance = Database::all(
            "SELECT u.name,
                    COUNT(l.id) AS total_leads,
                    COUNT(CASE WHEN l.stage='won' THEN 1 END)  AS won,
                    COUNT(CASE WHEN l.stage='lost' THEN 1 END) AS lost,
                    COALESCE(SUM(CASE WHEN l.stage='won' THEN l.estimated_value END), 0) AS won_value
               FROM leads l
               JOIN users u ON u.id = l.assigned_to
              WHERE l.created_at BETWEEN :from AND DATE_ADD(:to, INTERVAL 1 DAY)
           GROUP BY u.id, u.name
           ORDER BY won_value DESC",
            $range
        );

        // -- Payment methods
        $paymentMethods = Database::all(
            "SELECT method, COUNT(*) AS count, SUM(amount) AS total
               FROM payments
              WHERE status='completed'
                AND DATE(COALESCE(paid_at, created_at)) BETWEEN :from AND :to
           GROUP BY method
           ORDER BY total DESC",
            $range
        );

        $this->view('reports/index', [
            'title'           => 'Reports',
            'from'            => $from,
            'to'              => $to,
            'revenue'         => $revenue,
            'expenses'        => $expenses,
            'grossProfit'     => $collected - $spent,
            'monthly'         => $monthly,
            'topClients'      => $topClients,
            'topLines'        => $topLines,
            'revenueMix'      => $revenueMix,
            'expenseMix'      => $expenseMix,
            'ageing'          => $ageing,
            'pipeline'        => $pipeline,
            'leadPerformance' => $leadPerformance,
            'paymentMethods'  => $paymentMethods,
        ]);
    }

    /** Line-by-line statement of income and expenditure for the period. */
    public function exportStatement(Request $request): void
    {
        $this->authorize('reports.view');

        $from = date('Y-m-d', strtotime((string) $request->query('from', date('Y-01-01'))) ?: time());
        $to   = date('Y-m-d', strtotime((string) $request->query('to', date('Y-m-d'))) ?: time());

        $rows = [];

        $payments = Database::all(
            "SELECT COALESCE(p.paid_at, p.created_at) AS dt, p.payment_number, c.name AS client,
                    d.doc_number, p.method, p.amount
               FROM payments p
               JOIN clients c ON c.id = p.client_id
          LEFT JOIN documents d ON d.id = p.document_id
              WHERE p.status='completed'
                AND DATE(COALESCE(p.paid_at, p.created_at)) BETWEEN :from AND :to
           ORDER BY dt",
            ['from' => $from, 'to' => $to]
        );

        foreach ($payments as $p) {
            $rows[] = [
                date('Y-m-d', strtotime($p['dt'])),
                'Income',
                $p['payment_number'],
                $p['client'],
                $p['doc_number'] ?? '',
                label_of($p['method']),
                number_format((float) $p['amount'], 2, '.', ''),
                '',
            ];
        }

        $expenses = Database::all(
            'SELECT e.expense_date, e.expense_number, e.vendor, e.description,
                    e.payment_method, e.amount, c.name AS category
               FROM expenses e
          LEFT JOIN categories c ON c.id = e.category_id
              WHERE e.expense_date BETWEEN :from AND :to
           ORDER BY e.expense_date',
            ['from' => $from, 'to' => $to]
        );

        foreach ($expenses as $ex) {
            $rows[] = [
                $ex['expense_date'],
                'Expense',
                $ex['expense_number'],
                $ex['vendor'] ?? '',
                $ex['category'] ?? '',
                label_of($ex['payment_method']),
                '',
                number_format((float) $ex['amount'], 2, '.', ''),
            ];
        }

        usort($rows, static fn($a, $b) => strcmp($a[0], $b[0]));

        Response::csv(
            "shanfix-statement-{$from}-to-{$to}.csv",
            ['Date', 'Type', 'Reference', 'Party', 'Related', 'Method', 'Money In', 'Money Out'],
            $rows
        );
    }
}
