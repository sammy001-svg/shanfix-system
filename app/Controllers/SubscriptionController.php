<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\Renewals;

/**
 * Recurring services: the websites, hosting and retainers a client keeps
 * paying for. One-off work stays with quotations and invoices.
 */
class SubscriptionController extends Controller
{
    public function index(Request $request): void
    {
        $search = (string) $request->query('q', '');
        $status = (string) $request->query('status', 'active');
        $type   = (string) $request->query('type', '');
        $when   = (string) $request->query('due', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(s.name LIKE :q1 OR s.url LIKE :q2 OR c.name LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }

        if (in_array($status, ['active', 'paused', 'cancelled'], true)) {
            $where[] = 's.status = :st';
            $params['st'] = $status;
        }

        if ($type !== '' && isset(Renewals::TYPES[$type])) {
            $where[] = 's.service_type = :ty';
            $params['ty'] = $type;
        }

        // "Due soon" is the reason most people open this page.
        if ($when === 'soon') {
            $where[] = 's.next_renewal_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
        } elseif ($when === 'overdue') {
            $where[] = 's.next_renewal_date < CURDATE()';
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM subscriptions s JOIN clients c ON c.id = s.client_id WHERE {$clause}",
            $params,
            0
        );

        $pager = $this->paginate($total, 25);

        $subs = Database::all(
            "SELECT s.*, c.name AS client_name, c.email AS client_email,
                    (SELECT COALESCE(SUM(d.balance), 0)
                       FROM subscription_renewals r
                       JOIN documents d ON d.id = r.document_id
                      WHERE r.subscription_id = s.id
                        AND d.status NOT IN ('cancelled','paid','draft')) AS due_balance
               FROM subscriptions s
               JOIN clients c ON c.id = s.client_id
              WHERE {$clause}
           ORDER BY s.next_renewal_date ASC, s.name ASC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $summary = Database::first(
            "SELECT
                COUNT(*) AS live,
                COALESCE(SUM(CASE WHEN next_renewal_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                                  THEN 1 ELSE 0 END), 0) AS due_soon,
                COALESCE(SUM(CASE WHEN next_renewal_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue,
                COALESCE(SUM(amount), 0) AS cycle_value
               FROM subscriptions WHERE status = 'active'"
        );

        $this->view('subscriptions/index', [
            'title'   => 'Recurring Services',
            'subs'    => $subs,
            'summary' => $summary,
            'pager'   => $pager,
            'types'   => Renewals::TYPES,
            'filters' => ['search' => $search, 'status' => $status, 'type' => $type, 'due' => $when],
        ]);
    }

    public function create(Request $request): void
    {
        $this->form(null, (int) $request->query('client', 0));
    }

    public function edit(Request $request): void
    {
        $this->form($this->findOrFail($request->paramInt('id')), 0);
    }

    private function form(?array $sub, int $presetClient): void
    {
        $this->view('subscriptions/form', [
            'title'    => $sub ? 'Edit ' . $sub['name'] : 'New recurring service',
            'sub'      => $sub,
            'clients'  => Database::all("SELECT id, name FROM clients WHERE status <> 'inactive' ORDER BY name"),
            'services' => Database::all('SELECT id, name, price FROM services WHERE is_active = 1 ORDER BY name'),
            'types'    => Renewals::TYPES,
            'cycles'   => Renewals::CYCLES,
            'preset'   => $presetClient,
        ]);
    }

    public function store(Request $request): void
    {
        $data = $this->validated($request, null);

        $id = Database::insert('subscriptions', $data + [
            'created_by' => Auth::id(),
        ]);

        ActivityLog::record('subscription_created', 'subscription', $id,
            'Registered ' . $data['name'] . ' renewing ' . $data['billing_cycle']);

        Session::success($data['name'] . ' is now being tracked for renewal.');
        Response::to('/subscriptions/' . $id);
    }

    public function update(Request $request): void
    {
        $sub  = $this->findOrFail($request->paramInt('id'));
        $data = $this->validated($request, (int) $sub['id']);

        Database::update('subscriptions', $data, ['id' => $sub['id']]);

        ActivityLog::record('subscription_updated', 'subscription', (int) $sub['id'], 'Updated ' . $data['name']);
        Session::success('Recurring service updated.');
        Response::to('/subscriptions/' . $sub['id']);
    }

    /**
     * Shared validation. Returns a row ready to write.
     */
    private function validated(Request $request, ?int $existingId): array
    {
        $v = new Validator($request->all());
        $v->require('client_id', 'Client')
          ->require('name', 'Service name')
          ->maxLen('name', 180, 'Service name')
          ->numeric('amount', 'Renewal amount')
          ->min('amount', 0, 'Renewal amount')
          ->require('start_date', 'Start date')
          ->require('next_renewal_date', 'Next renewal date')
          ->in('billing_cycle', array_keys(Renewals::CYCLES), 'Billing cycle')
          ->in('service_type', array_keys(Renewals::TYPES), 'Service type')
          ->in('status', ['active', 'paused', 'cancelled'], 'Status');

        $url = trim((string) $request->input('url', ''));

        // A bare "example.co.ke" is what people type; without a scheme the
        // browser treats it as a relative path and the button goes nowhere.
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            $v->custom('url', false, 'That does not look like a valid web address.');
        }

        $cycle = (string) $request->input('billing_cycle', 'annual');
        $days  = (int) $request->input('cycle_days', 365);

        if ($cycle === 'custom' && $days < 1) {
            $v->custom('cycle_days', false, 'Tell us how many days the custom cycle runs for.');
        }

        if ($v->fails()) {
            $v->redirectBack($existingId ? '/subscriptions/' . $existingId . '/edit' : '/subscriptions/create');
        }

        return [
            'client_id'         => (int) $request->input('client_id'),
            'service_id'        => $request->input('service_id') ? (int) $request->input('service_id') : null,
            'name'              => (string) $request->input('name'),
            'url'               => $url !== '' ? $url : null,
            'service_type'      => (string) $request->input('service_type', 'website'),
            'amount'            => (float) $request->input('amount', 0),
            'currency'          => \App\Core\Settings::currency(),
            'billing_cycle'     => $cycle,
            'cycle_days'        => max(1, $days),
            'start_date'        => (string) $request->input('start_date'),
            'next_renewal_date' => (string) $request->input('next_renewal_date'),
            'status'            => (string) $request->input('status', 'active'),
            'auto_invoice'      => $request->bool('auto_invoice') ? 1 : 0,
            'reminder_days'     => trim((string) $request->input('reminder_days', '')),
            'notes'             => $request->input('notes') ?: null,
        ];
    }

    public function show(Request $request): void
    {
        $sub = $this->findOrFail($request->paramInt('id'));

        $client = Database::first('SELECT * FROM clients WHERE id = :id', ['id' => $sub['client_id']]);

        $renewals = Database::all(
            "SELECT r.*, d.doc_number, d.status AS doc_status, d.total, d.balance
               FROM subscription_renewals r
          LEFT JOIN documents d ON d.id = r.document_id
              WHERE r.subscription_id = :s
           ORDER BY r.period_start DESC",
            ['s' => $sub['id']]
        );

        $this->view('subscriptions/show', [
            'title'    => $sub['name'],
            'sub'      => $sub,
            'client'   => $client,
            'renewals' => $renewals,
            'types'    => Renewals::TYPES,
            'cycles'   => Renewals::CYCLES,
            'period'   => Renewals::periodFor($sub),
        ]);
    }

    /**
     * Raise the invoice for the current period on demand, rather than
     * waiting for the nightly sweep.
     */
    public function invoiceNow(Request $request): void
    {
        $sub = $this->findOrFail($request->paramInt('id'));

        if ($sub['status'] !== 'active') {
            Session::error('Only an active service can be invoiced.');
            Response::to('/subscriptions/' . $sub['id']);
        }

        $result = Renewals::invoicePeriod($sub, Auth::id());
        $doc    = $result['document'];

        if (!$result['created']) {
            Session::warning('This period was already invoiced as ' . $doc['doc_number'] . '.');
            Response::to('/invoices/' . $doc['id']);
        }

        ActivityLog::record('subscription_invoiced', 'subscription', (int) $sub['id'],
            'Raised ' . $doc['doc_number'] . ' for ' . $sub['name']);

        Session::success('Invoice ' . $doc['doc_number'] . ' raised. Send it from here when you are ready.');
        Response::to('/invoices/' . $doc['id']);
    }

    public function destroy(Request $request): void
    {
        $sub = $this->findOrFail($request->paramInt('id'));

        // Renewals already invoiced are financial history. Cancelling stops
        // the reminders without erasing what was billed.
        $invoiced = (int) Database::scalar(
            'SELECT COUNT(*) FROM subscription_renewals WHERE subscription_id = :s AND document_id IS NOT NULL',
            ['s' => $sub['id']],
            0
        );

        if ($invoiced > 0) {
            Database::update('subscriptions', ['status' => 'cancelled'], ['id' => $sub['id']]);
            ActivityLog::record('subscription_cancelled', 'subscription', (int) $sub['id'], 'Cancelled ' . $sub['name']);
            Session::warning($sub['name'] . ' has invoices against it, so it was cancelled rather than deleted. Its billing history stays intact.');
            Response::to('/subscriptions');
        }

        Database::delete('subscriptions', ['id' => $sub['id']]);
        ActivityLog::record('subscription_deleted', 'subscription', (int) $sub['id'], 'Deleted ' . $sub['name']);
        Session::success($sub['name'] . ' removed.');
        Response::to('/subscriptions');
    }

    private function findOrFail(int $id): array
    {
        $sub = Database::first('SELECT * FROM subscriptions WHERE id = :id', ['id' => $id]);

        if (!$sub) {
            throw new HttpException(404, 'That recurring service does not exist.');
        }

        return $sub;
    }
}
