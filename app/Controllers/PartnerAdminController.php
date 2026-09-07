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
use App\Services\Commission;
use App\Services\Notifier;

/**
 * Partners, from our side of the desk.
 *
 * Deciding applications, setting what somebody earns, and paying it out.
 * Everything a partner sees of themselves is derived from what happens
 * here, so this is where the money decisions live.
 */
class PartnerAdminController extends Controller
{
    public function index(Request $request): void
    {
        $this->authorize('partners.view');

        $status = (string) $request->query('status', '');
        $status = in_array($status, ['pending', 'active', 'suspended', 'rejected'], true) ? $status : '';

        // Sales look after named partners, so "mine" is a real filter for
        // them rather than a convenience.
        $mine   = $request->query('mine') !== null;
        $clause = [];
        $params = [];

        if ($status !== '') {
            $clause[] = 'p.status = :s';
            $params['s'] = $status;
        }

        if ($mine) {
            $clause[] = 'p.account_manager_id = :me';
            $params['me'] = Auth::id();
        }

        $where = $clause ? ' WHERE ' . implode(' AND ', $clause) : '';

        $rows = Database::all(
            "SELECT p.*,
                    u.name AS manager_name,
                    (SELECT COUNT(*) FROM clients c WHERE c.partner_id = p.id) AS customers,
                    (SELECT COALESCE(SUM(cm.amount), 0) FROM commissions cm
                      WHERE cm.partner_id = p.id AND cm.status = 'earned')     AS due,
                    (SELECT COALESCE(SUM(cm.amount), 0) FROM commissions cm
                      WHERE cm.partner_id = p.id AND cm.status = 'paid')       AS paid
               FROM partners p
          LEFT JOIN users u ON u.id = p.account_manager_id" . $where . "
           ORDER BY FIELD(p.status, 'pending') DESC, p.created_at DESC",
            $params
        );

        $counts = [];
        foreach (['pending', 'active', 'suspended', 'rejected'] as $s) {
            $counts[$s] = (int) Database::scalar(
                'SELECT COUNT(*) FROM partners WHERE status = :s',
                ['s' => $s],
                0
            );
        }
        $counts['all'] = array_sum($counts);

        $counts['mine'] = (int) Database::scalar(
            'SELECT COUNT(*) FROM partners WHERE account_manager_id = :me',
            ['me' => Auth::id()],
            0
        );

        $this->view('partners/index', [
            'title'  => 'Partners',
            'rows'   => $rows,
            'status' => $status,
            'mine'   => $mine,
            'counts' => $counts,
        ]);
    }

    public function show(Request $request): void
    {
        $this->authorize('partners.view');

        $partner = $this->find($request->paramInt('id'));

        $customers = Database::all(
            "SELECT c.id, c.name, c.partner_linked_at,
                    COALESCE(SUM(CASE WHEN cm.status <> 'void' THEN cm.amount END), 0) AS earned
               FROM clients c
          LEFT JOIN commissions cm ON cm.client_id = c.id AND cm.partner_id = :p2
              WHERE c.partner_id = :p
           GROUP BY c.id, c.name, c.partner_linked_at
           ORDER BY earned DESC, c.name",
            ['p' => $partner['id'], 'p2' => $partner['id']]
        );

        $commissions = Database::all(
            "SELECT cm.*, d.doc_number, c.name AS client_name
               FROM commissions cm
               JOIN documents d ON d.id = cm.document_id
               JOIN clients   c ON c.id = cm.client_id
              WHERE cm.partner_id = :p AND cm.status <> 'void'
           ORDER BY cm.id DESC
              LIMIT 50",
            ['p' => $partner['id']]
        );

        // What they are actually reselling: the services that have reached
        // an invoice for one of their customers, with what it has been
        // worth. This is the answer to "what do they sell for us", and it
        // comes from the invoices rather than from anybody's opinion.
        $reselling = Database::all(
            "SELECT s.id, s.name, s.commission_rate,
                    COUNT(DISTINCT d.id)     AS invoices,
                    COUNT(DISTINCT c.id)     AS customers,
                    SUM(di.line_total)       AS billed,
                    MAX(d.issue_date)        AS last_sold
               FROM document_items di
               JOIN documents d ON d.id = di.document_id
                                AND d.doc_type = 'invoice'
                                AND d.status <> 'draft'
               JOIN clients   c ON c.id = d.client_id
               JOIN services  s ON s.id = di.ref_id
              WHERE di.item_type = 'service' AND c.partner_id = :p
           GROUP BY s.id, s.name, s.commission_rate
           ORDER BY billed DESC",
            ['p' => $partner['id']]
        );

        // The recurring side, and when each one falls due. A renewal is
        // future commission with a date on it, which is the most useful
        // thing on this page for whoever looks after the relationship.
        $renewals = Database::all(
            "SELECT sub.id, sub.name, sub.amount, sub.billing_cycle, sub.status,
                    sub.next_renewal_date, sub.service_id,
                    c.id AS client_id, c.name AS client_name,
                    sv.commission_rate
               FROM subscriptions sub
               JOIN clients  c  ON c.id = sub.client_id
          LEFT JOIN services sv ON sv.id = sub.service_id
              WHERE c.partner_id = :p
           ORDER BY FIELD(sub.status, 'active') DESC, sub.next_renewal_date IS NULL, sub.next_renewal_date",
            ['p' => $partner['id']]
        );

        // What each renewal is worth to them, by the same rule the ledger
        // uses, so the forecast and the eventual payment agree.
        $default = (float) $partner['default_rate'];
        $today   = strtotime(date('Y-m-d'));

        foreach ($renewals as $i => $r) {
            $rate = $r['commission_rate'] === null ? $default : (float) $r['commission_rate'];

            $renewals[$i]['effective_rate'] = $rate;
            $renewals[$i]['expected']       = round((float) $r['amount'] * $rate / 100, 2);
            $renewals[$i]['days_away']      = $r['next_renewal_date']
                ? (int) floor((strtotime((string) $r['next_renewal_date']) - $today) / 86400)
                : null;
        }

        $this->view('partners/show', [
            'title'       => $partner['name'],
            'partner'     => $partner,
            'summary'     => Commission::summaryFor((int) $partner['id']),
            'customers'   => $customers,
            'commissions' => $commissions,
            'reselling'   => $reselling,
            'renewals'    => $renewals,
            'months'      => Commission::byMonth((int) $partner['id']),
            'managers'    => $this->salesTeam(),
        ]);
    }

    /**
     * Say yes or no to an application.
     *
     * Approving assigns a code and opens the door; it does not set a
     * password. They set that themselves from the code we send, so nobody
     * here ever knows it.
     */
    public function decide(Request $request): void
    {
        $this->authorize('partners.manage');

        $partner  = $this->find($request->paramInt('id'));
        $decision = (string) $request->input('decision');

        if (!in_array($decision, ['approve', 'reject', 'suspend', 'restore'], true)) {
            throw new HttpException(422, 'Unknown decision.');
        }

        $status = match ($decision) {
            'approve', 'restore' => 'active',
            'reject'             => 'rejected',
            'suspend'            => 'suspended',
        };

        $update = [
            'status'        => $status,
            'decided_by'    => Auth::id(),
            'decided_at'    => date('Y-m-d H:i:s'),
            'decision_note' => trim((string) $request->input('note')) ?: null,
        ];

        // A code is assigned the first time they are approved and never
        // reused, so a partner keeps the same reference for good.
        if ($status === 'active' && empty($partner['partner_code'])) {
            $update['partner_code'] = Numbering::next('partner');
        }

        if ($decision === 'approve') {
            $rate = $request->input('default_rate');

            if ($rate !== null && $rate !== '') {
                $update['default_rate'] = max(0, min(100, (float) $rate));
            }
        }

        Database::update('partners', $update, ['id' => $partner['id']]);

        ActivityLog::record(
            'partner_' . $decision,
            'partner',
            (int) $partner['id'],
            $partner['name'] . ' — ' . $decision
        );

        // Suspending or refusing changes what is owed on work not yet paid
        // for, so the ledger is rebuilt rather than left to disagree.
        if (in_array($decision, ['reject', 'restore'], true)) {
            foreach (Database::all('SELECT id FROM clients WHERE partner_id = :p', ['p' => $partner['id']]) as $c) {
                Commission::resyncClient((int) $c['id']);
            }
        }

        if ($decision === 'approve') {
            Notifier::dispatch('partner_approved', [
                'entity_type'  => 'partner',
                'entity_id'    => (int) $partner['id'],
                'contact_name' => $partner['name'],
                'email'        => $partner['email'],
                'phone'        => $partner['phone'],
                'link'         => Notifier::absoluteUrl('/partners/start'),
                'rate'         => rtrim(rtrim(number_format((float) ($update['default_rate'] ?? $partner['default_rate']), 2), '0'), '.') . '%',
            ], true);

            Notifier::processQueue(4);
        }

        Session::success(match ($decision) {
            'approve' => $partner['name'] . ' is now a partner. They have been sent a link to set a password.',
            'reject'  => 'Application turned down.',
            'suspend' => $partner['name'] . ' can no longer sign in.',
            'restore' => $partner['name'] . ' can sign in again.',
        });

        Response::to('/partners-admin/' . $partner['id']);
    }

    /** Change what a partner earns, or their details. */
    public function update(Request $request): void
    {
        $this->authorize('partners.manage');

        $partner = $this->find($request->paramInt('id'));

        $v = new Validator($request->all());
        $v->require('name', 'Name')
          ->maxLen('name', 140, 'Name')
          ->maxLen('company', 180, 'Business')
          ->email('email', 'Email address', true)
          ->phone('phone', 'Phone number', true)
          ->maxLen('kra_pin', 30, 'KRA PIN')
          ->numeric('default_rate', 'Commission rate');

        if ($v->fails()) {
            $v->redirectBack('/partners-admin/' . $partner['id']);
        }

        Database::update('partners', [
            'name'         => trim((string) $request->input('name')),
            'company'      => trim((string) $request->input('company')) ?: null,
            'email'        => strtolower(trim((string) $request->input('email'))),
            'phone'        => trim((string) $request->input('phone')),
            'kra_pin'      => trim((string) $request->input('kra_pin')) ?: null,
            'default_rate' => max(0, min(100, (float) $request->input('default_rate'))),
            'notes'        => trim((string) $request->input('notes')) ?: null,
        ], ['id' => $partner['id']]);

        ActivityLog::record('partner_updated', 'partner', (int) $partner['id'], 'Updated ' . $partner['name']);

        // The rate only bites on commission not yet earned. What has
        // already been earned keeps the rate it was earned at — the row
        // carries its own copy for exactly this reason.
        Session::success('Saved. The new rate applies to commission earned from now on.');
        Response::to('/partners-admin/' . $partner['id']);
    }

    /**
     * Mark commission as paid out.
     *
     * Everything currently owed, in one go, against one reference. Paying
     * a partner in dribs is a conversation, not a workflow.
     */
    public function payout(Request $request): void
    {
        $this->authorize('partners.pay');

        $partner = $this->find($request->paramInt('id'));

        $ref = trim((string) $request->input('payout_ref'));

        if ($ref === '') {
            Session::error('Put the payment reference in, so this can be traced later.');
            Response::to('/partners-admin/' . $partner['id']);
        }

        // Commission is paid monthly, so a payout is a month unless
        // somebody deliberately settles everything outstanding. Paying one
        // month at a time is what lets both sides reconcile against the
        // same figure afterwards.
        $period = trim((string) $request->input('period'));
        $byMonth = $period !== '' && preg_match('/^[0-9]{4}-[0-9]{2}$/', $period) === 1;

        $where  = "partner_id = :p AND status = 'earned'" . ($byMonth ? ' AND period = :period' : '');
        $params = ['p' => $partner['id']];

        if ($byMonth) {
            $params['period'] = $period;
        }

        $due = (float) Database::scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE ' . $where,
            $params,
            0
        );

        if ($due <= 0.009) {
            Session::warning($byMonth
                ? 'There is nothing outstanding for ' . $period . '.'
                : 'There is nothing outstanding to pay.');
            Response::to('/partners-admin/' . $partner['id']);
        }

        $n = Database::run(
            "UPDATE commissions
                SET status = 'paid', paid_at = NOW(), payout_ref = :r
              WHERE " . $where,
            $params + ['r' => $ref]
        )->rowCount();

        ActivityLog::record(
            'partner_paid',
            'partner',
            (int) $partner['id'],
            'Paid ' . money($due) . ' to ' . $partner['name']
            . ($byMonth ? ' for ' . $period : '') . ' (' . $ref . ')'
        );

        Notifier::dispatch('partner_paid', [
            'entity_type'  => 'partner',
            'entity_id'    => (int) $partner['id'],
            'contact_name' => $partner['name'],
            'email'        => $partner['email'],
            'phone'        => $partner['phone'],
            'amount'       => money($due),
            'payment_ref'  => $ref,
            'period'       => $byMonth ? $period : '',
        ], true);

        Notifier::processQueue(4);

        Session::success(
            'Marked ' . money($due) . ' as paid'
            . ($byMonth ? ' for ' . $period : '') . ', across ' . $n . ' entries.'
        );
        Response::to('/partners-admin/' . $partner['id']);
    }

    // -- Registering one ourselves ------------------------------------------

    /**
     * Some partners are signed up over a table, not through the website.
     *
     * Admin only: bringing a partner into existence commits us to paying
     * them, which is not a relationship job.
     */
    public function create(Request $request): void
    {
        $this->authorize('partners.create');

        $this->view('partners/form', [
            'title'    => 'Register a partner',
            'partner'  => null,
            'managers' => $this->salesTeam(),
            'rate'     => (float) Settings::get('partner_default_rate', 10),
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('partners.create');

        $v = new Validator($request->all());
        $v->require('name', 'Name')
          ->maxLen('name', 140, 'Name')
          ->maxLen('company', 180, 'Business')
          ->email('email', 'Email address', true)
          ->phone('phone', 'Phone number', true)
          ->maxLen('kra_pin', 30, 'KRA PIN')
          ->unique('email', 'partners', 'email', 'Email address')
          ->numeric('default_rate', 'Commission rate');

        if ($v->fails()) {
            $v->redirectBack('/partners-admin/new');
        }

        $email   = strtolower(trim((string) $request->input('email')));
        $manager = $this->managerInput($request);

        $id = Database::insert('partners', [
            'partner_code'       => Numbering::next('partner'),
            'name'               => trim((string) $request->input('name')),
            'company'            => trim((string) $request->input('company')) ?: null,
            'email'              => $email,
            'phone'              => trim((string) $request->input('phone')),
            'kra_pin'            => trim((string) $request->input('kra_pin')) ?: null,
            'pitch'              => trim((string) $request->input('pitch')) ?: null,
            'default_rate'       => max(0, min(100, (float) $request->input('default_rate', 10))),
            // Registered by us, so there is nothing to decide — they are a
            // partner from the moment the form is saved.
            'status'             => 'active',
            'account_manager_id' => $manager,
            'assigned_at'        => $manager === null ? null : date('Y-m-d H:i:s'),
            'decided_by'         => Auth::id(),
            'decided_at'         => date('Y-m-d H:i:s'),
            'notes'              => trim((string) $request->input('notes')) ?: null,
        ]);

        ActivityLog::record('partner_registered', 'partner', $id, 'Registered partner ' . $email);

        // They still set their own password from a code, exactly as an
        // applicant does. Nobody here ever knows it.
        Notifier::dispatch('partner_approved', [
            'entity_type'  => 'partner',
            'entity_id'    => $id,
            'contact_name' => trim((string) $request->input('name')),
            'email'        => $email,
            'phone'        => trim((string) $request->input('phone')),
            'link'         => Notifier::absoluteUrl('/partners/start'),
            'rate'         => rtrim(rtrim(number_format((float) $request->input('default_rate', 10), 2), '0'), '.') . '%',
        ], true);

        Notifier::processQueue(4);

        Session::success('Registered. They have been sent a link to set their own password.');
        Response::to('/partners-admin/' . $id);
    }

    // -- Who looks after them -------------------------------------------------

    /**
     * Put a partner in somebody's care.
     *
     * The account manager is the contact point between the partner and us.
     * It is a relationship, not an authority: being assigned does not let
     * somebody approve, pay, or create anything.
     */
    public function assign(Request $request): void
    {
        $this->authorize('partners.assign');

        $partner = $this->find($request->paramInt('id'));
        $manager = $this->managerInput($request);

        Database::update('partners', [
            'account_manager_id' => $manager,
            'assigned_at'        => $manager === null ? null : date('Y-m-d H:i:s'),
        ], ['id' => $partner['id']]);

        $name = $manager
            ? (string) Database::scalar('SELECT name FROM users WHERE id = :u', ['u' => $manager], '')
            : null;

        ActivityLog::record(
            'partner_assigned',
            'partner',
            (int) $partner['id'],
            $name
                ? $partner['name'] . ' is now looked after by ' . $name
                : 'Unassigned ' . $partner['name']
        );

        Session::success($name
            ? $partner['name'] . ' is now looked after by ' . $name . '.'
            : $partner['name'] . ' is no longer assigned to anybody.');

        Response::to('/partners-admin/' . $partner['id']);
    }

    // -- The monthly run -------------------------------------------------------

    /**
     * Everything owed for one month, across every partner.
     *
     * Commission is paid monthly, so this is the screen the payment run is
     * actually done from: one month, everybody, one pass.
     */
    public function run(Request $request): void
    {
        $this->authorize('partners.view');

        $periods = Commission::periods();
        $period  = (string) $request->query('period', '');

        if ($period === '' || preg_match('/^[0-9]{4}-[0-9]{2}$/', $period) !== 1) {
            // The newest month that has anything in it. Falling back to
            // last month rather than this one: this month is still being
            // earned, and a run against a moving total is not a run.
            $period = $periods[0] ?? date('Y-m', strtotime('first day of last month'));
        }

        $rows = Commission::runFor($period);

        $this->view('partners/run', [
            'title'   => 'Commission run',
            'period'  => $period,
            'periods' => $periods,
            'rows'    => $rows,
            'due'     => array_sum(array_map(static fn(array $r): float => (float) $r['due'], $rows)),
            'paid'    => array_sum(array_map(static fn(array $r): float => (float) $r['paid'], $rows)),
        ]);
    }

    /** Everyone who may be an account manager. */
    private function salesTeam(): array
    {
        return Database::all(
            "SELECT id, name, role FROM users
              WHERE is_active = 1 AND role IN ('admin', 'manager', 'sales')
           ORDER BY name"
        );
    }

    /** The chosen account manager, checked against who may actually be one. */
    private function managerInput(Request $request): ?int
    {
        $raw = trim((string) $request->input('account_manager_id'));

        if ($raw === '') {
            return null;
        }

        $id = (int) $raw;

        // Never take the id on trust. A partner assigned to somebody who is
        // not on the sales team, or to a disabled account, has no contact
        // point at all while looking as though it has one.
        $ok = (int) Database::scalar(
            "SELECT COUNT(*) FROM users
              WHERE id = :u AND is_active = 1 AND role IN ('admin', 'manager', 'sales')",
            ['u' => $id],
            0
        );

        return $ok ? $id : null;
    }

    private function find(int $id): array
    {
        $row = Database::first('SELECT * FROM partners WHERE id = :id', ['id' => $id]);

        if (!$row) {
            throw new HttpException(404, 'That partner was not found.');
        }

        return $row;
    }
}
