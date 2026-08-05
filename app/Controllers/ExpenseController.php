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
use App\Core\Validator;

class ExpenseController extends Controller
{
    private const METHODS = ['cash', 'mpesa', 'bank', 'cheque', 'card', 'other'];

    public function index(Request $request): void
    {
        $search   = (string) $request->query('q', '');
        $category = (int) $request->query('category', 0);
        $from     = (string) $request->query('from', date('Y-m-01'));
        $to       = (string) $request->query('to', date('Y-m-t'));

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(e.description LIKE :q OR e.vendor LIKE :q2 OR e.expense_number LIKE :q3 OR e.reference LIKE :q4)';
            $params['q'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $search . '%';
        }

        if ($category > 0) {
            $where[] = 'e.category_id = :cat';
            $params['cat'] = $category;
        }

        if ($from !== '' && strtotime($from)) {
            $where[] = 'e.expense_date >= :from';
            $params['from'] = date('Y-m-d', strtotime($from));
        }

        if ($to !== '' && strtotime($to)) {
            $where[] = 'e.expense_date <= :to';
            $params['to'] = date('Y-m-d', strtotime($to));
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM expenses e WHERE {$clause}", $params, 0);
        $pager = $this->paginate($total, 30);

        $expenses = Database::all(
            "SELECT e.*, c.name AS category_name, u.name AS recorded_by_name, cl.name AS client_name
               FROM expenses e
          LEFT JOIN categories c ON c.id = e.category_id
          LEFT JOIN users u ON u.id = e.recorded_by
          LEFT JOIN clients cl ON cl.id = e.client_id
              WHERE {$clause}
           ORDER BY e.expense_date DESC, e.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $summary = Database::first(
            "SELECT COALESCE(SUM(amount), 0) AS period_total,
                    COALESCE(SUM(vat_amount), 0) AS period_vat,
                    COUNT(*) AS period_count
               FROM expenses e WHERE {$clause}",
            $params
        );

        $monthTotal = (float) Database::scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM expenses
              WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())',
            [],
            0
        );

        // Category breakdown for the filtered period.
        $byCategory = Database::all(
            "SELECT COALESCE(c.name, 'Uncategorised') AS name, SUM(e.amount) AS total
               FROM expenses e
          LEFT JOIN categories c ON c.id = e.category_id
              WHERE {$clause}
           GROUP BY c.id, c.name
           ORDER BY total DESC
              LIMIT 10",
            $params
        );

        $this->view('expenses/index', [
            'title'      => 'Expenses',
            'expenses'   => $expenses,
            'pager'      => $pager,
            'summary'    => $summary,
            'monthTotal' => $monthTotal,
            'byCategory' => $byCategory,
            'categories' => $this->categories(),
            'methods'    => self::METHODS,
            'filters'    => compact('search', 'category', 'from', 'to'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('expenses.manage');

        $this->view('expenses/form', [
            'title'      => 'Record Expense',
            'expense'    => null,
            'categories' => $this->categories(),
            'clients'    => Database::all("SELECT id, name FROM clients WHERE status='active' ORDER BY name"),
            'jobs'       => $this->openJobs(),
            'preJobId'   => (int) $request->query('job_id', 0),
            'preClientId'=> (int) $request->query('client_id', 0),
            'methods'    => self::METHODS,
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('expenses.manage');

        $data = $this->validated($request);
        $data['expense_number'] = Numbering::next('expense');
        $data['recorded_by']    = Auth::id();

        $receipt = $this->storeUpload($request->file('receipt_file'), 'receipts');
        if ($receipt) {
            $data['receipt_file'] = $receipt;
        }

        $id = Database::insert('expenses', $data);

        ActivityLog::record(
            'expense_recorded',
            'expense',
            $id,
            'Recorded expense of ' . money($data['amount']) . ' — ' . str_excerpt($data['description'], 60)
        );

        Session::success('Expense ' . $data['expense_number'] . ' recorded (' . money($data['amount']) . ').');
        Response::to('/expenses');
    }

    public function edit(Request $request): void
    {
        $this->authorize('expenses.manage');

        $expense = $this->findOrFail($request->paramInt('id'));

        $this->view('expenses/form', [
            'title'      => 'Edit ' . $expense['expense_number'],
            'expense'    => $expense,
            'categories' => $this->categories(),
            'clients'    => Database::all("SELECT id, name FROM clients WHERE status='active' ORDER BY name"),
            'jobs'       => $this->openJobs((int) $expense['job_id']),
            'preJobId'   => (int) $expense['job_id'],
            'preClientId'=> (int) $expense['client_id'],
            'methods'    => self::METHODS,
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('expenses.manage');

        $expense = $this->findOrFail($request->paramInt('id'));
        $data    = $this->validated($request);

        $receipt = $this->storeUpload($request->file('receipt_file'), 'receipts');
        if ($receipt) {
            $this->deleteUpload($expense['receipt_file']);
            $data['receipt_file'] = $receipt;
        }

        Database::update('expenses', $data, ['id' => $expense['id']]);

        ActivityLog::record('expense_updated', 'expense', (int) $expense['id'], 'Updated ' . $expense['expense_number']);
        Session::success('Expense updated.');
        Response::to('/expenses');
    }

    public function destroy(Request $request): void
    {
        $this->authorize('expenses.manage');

        $expense = $this->findOrFail($request->paramInt('id'));

        $this->deleteUpload($expense['receipt_file']);
        Database::delete('expenses', ['id' => $expense['id']]);

        ActivityLog::record('expense_deleted', 'expense', (int) $expense['id'], 'Deleted ' . $expense['expense_number']);
        Session::success('Expense deleted.');
        Response::to('/expenses');
    }

    public function export(Request $request): void
    {
        $this->authorize('expenses.view');

        $from = (string) $request->query('from', date('Y-01-01'));
        $to   = (string) $request->query('to', date('Y-m-d'));

        $rows = Database::all(
            'SELECT e.expense_number, e.expense_date, c.name AS category, e.vendor, e.description,
                    e.amount, e.vat_amount, e.payment_method, e.reference, u.name AS recorded_by
               FROM expenses e
          LEFT JOIN categories c ON c.id = e.category_id
          LEFT JOIN users u ON u.id = e.recorded_by
              WHERE e.expense_date BETWEEN :from AND :to
           ORDER BY e.expense_date DESC',
            ['from' => date('Y-m-d', strtotime($from)), 'to' => date('Y-m-d', strtotime($to))]
        );

        $out = array_map(static fn($r) => [
            $r['expense_number'], $r['expense_date'], $r['category'] ?? '', $r['vendor'] ?? '',
            $r['description'], $r['amount'], $r['vat_amount'], $r['payment_method'],
            $r['reference'] ?? '', $r['recorded_by'] ?? '',
        ], $rows);

        Response::csv(
            'shanfix-expenses-' . date('Y-m-d') . '.csv',
            ['Number', 'Date', 'Category', 'Vendor', 'Description', 'Amount', 'VAT', 'Method', 'Reference', 'Recorded by'],
            $out
        );
    }

    // -- Internals -----------------------------------------------------

    private function validated(Request $request): array
    {
        $v = new Validator($request->all());
        $v->require('description', 'Description')
          ->maxLen('description', 500, 'Description')
          ->numeric('amount', 'Amount', true)
          ->min('amount', 0.01, 'Amount')
          ->numeric('vat_amount', 'VAT amount')
          ->min('vat_amount', 0, 'VAT amount')
          ->date('expense_date', 'Expense date', true)
          ->in('payment_method', self::METHODS, 'Payment method')
          ->maxLen('vendor', 180, 'Vendor')
          ->maxLen('reference', 120, 'Reference');

        if ($request->input('category_id')) {
            $v->exists('category_id', 'categories', 'category');
        }

        if ($request->decimal('vat_amount') > $request->decimal('amount')) {
            $v->custom('vat_amount', false, 'VAT cannot be more than the total amount.');
        }

        if ($v->fails()) {
            $v->redirectBack('/expenses/create');
        }

        return [
            'category_id'    => $request->int('category_id') ?: null,
            'vendor'         => $request->input('vendor') ?: null,
            'description'    => (string) $request->input('description'),
            'amount'         => $request->decimal('amount'),
            'vat_amount'     => $request->decimal('vat_amount'),
            'payment_method' => (string) $request->input('payment_method', 'cash'),
            'reference'      => $request->input('reference') ?: null,
            'expense_date'   => (string) $request->input('expense_date', date('Y-m-d')),
            'is_billable'    => $request->bool('is_billable') ? 1 : 0,
            'client_id'      => $request->int('client_id') ?: null,
            'job_id'         => $request->int('job_id') ?: null,
        ];
    }

    private function findOrFail(int $id): array
    {
        $expense = Database::first('SELECT * FROM expenses WHERE id = :id', ['id' => $id]);

        if (!$expense) {
            throw new HttpException(404, 'That expense does not exist.');
        }

        return $expense;
    }

    private function categories(): array
    {
        return Database::all("SELECT id, name FROM categories WHERE type = 'expense' ORDER BY name");
    }

    /**
     * Jobs a cost can be booked against — open ones, plus whichever job this
     * expense is already attached to so an edit never loses the link.
     */
    private function openJobs(int $includeId = 0): array
    {
        return Database::all(
            "SELECT j.id, j.job_number, j.title, c.name AS client_name
               FROM jobs j JOIN clients c ON c.id = j.client_id
              WHERE j.stage NOT IN ('delivered','cancelled') OR j.id = :include
           ORDER BY j.created_at DESC
              LIMIT 200",
            ['include' => $includeId]
        );
    }
}
