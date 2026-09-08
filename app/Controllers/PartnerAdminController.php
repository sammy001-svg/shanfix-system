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
use App\Services\Payouts;

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

        // Nine full-width cards down one page put an approval decision, a
        // payment, an edit form and four tables at the same weight, and
        // made anybody looking for one of them scroll past the other
        // eight. Tabs in the URL rather than in JavaScript, so each one is
        // a link somebody can send.
        $tab = (string) $request->query('tab', '');
        $tab = in_array($tab, ['money', 'customers', 'entries', 'details'], true) ? $tab : 'overview';

        $this->view('partners/show', [
            'title'       => $partner['name'],
            'tab'         => $tab,
            'partner'     => $partner,
            'summary'     => Commission::summaryFor((int) $partner['id']),
            'customers'   => $customers,
            'commissions' => $commissions,
            'reselling'   => $reselling,
            'renewals'    => $renewals,
            'months'      => Commission::byMonth((int) $partner['id']),
            'rateCount'   => (int) Database::scalar(
                'SELECT COUNT(*) FROM partner_rates WHERE partner_id = :p',
                ['p' => $partner['id']],
                0
            ),
            'managers'    => $this->salesTeam(),
            'payouts'     => Payouts::historyFor((int) $partner['id']),
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
        $v->require('first_name', 'First name')
          ->maxLen('first_name', 60, 'First name')
          ->maxLen('middle_name', 60, 'Second name')
          ->require('last_name', 'Third name')
          ->maxLen('last_name', 60, 'Third name')
          ->maxLen('occupation', 120, 'Occupation')
          ->maxLen('id_number', 30, 'ID number')
          ->maxLen('office_location', 200, 'Office location')
          ->maxLen('company', 180, 'Business')
          ->email('email', 'Email address', true)
          ->phone('phone', 'Phone number', true)
          ->maxLen('kra_pin', 30, 'KRA PIN')
          ->numeric('default_rate', 'Commission rate');

        if ($v->fails()) {
            $v->redirectBack('/partners-admin/' . $partner['id'] . '?tab=details');
        }

        Database::update('partners', [
            'first_name'      => trim((string) $request->input('first_name')),
            'middle_name'     => trim((string) $request->input('middle_name')) ?: null,
            'last_name'       => trim((string) $request->input('last_name')),
            // Written from the parts, never edited on its own, so the two
            // spellings of a name cannot drift apart.
            'name'            => full_name(
                (string) $request->input('first_name'),
                (string) $request->input('middle_name'),
                (string) $request->input('last_name')
            ),
            'company'         => trim((string) $request->input('company')) ?: null,
            'occupation'      => trim((string) $request->input('occupation')) ?: null,
            'email'           => strtolower(trim((string) $request->input('email'))),
            'phone'           => trim((string) $request->input('phone')),
            'kra_pin'         => trim((string) $request->input('kra_pin')) ?: null,
            'id_number'       => trim((string) $request->input('id_number')) ?: null,
            'office_location' => trim((string) $request->input('office_location')) ?: null,
            'default_rate'    => max(0, min(100, (float) $request->input('default_rate'))),
            'notes'           => trim((string) $request->input('notes')) ?: null,
        ], ['id' => $partner['id']]);

        ActivityLog::record('partner_updated', 'partner', (int) $partner['id'], 'Updated ' . $partner['name']);

        // The rate only bites on commission not yet earned. What has
        // already been earned keeps the rate it was earned at — the row
        // carries its own copy for exactly this reason.
        Session::success('Saved. The new rate applies to commission earned from now on.');
        Response::to('/partners-admin/' . $partner['id']);
    }

    /**
     * Where this partner's money goes.
     *
     * Deliberately not part of the form above. Editing a partner is
     * 'partners.manage', which managers have; changing the account their
     * commission lands in is 'partners.pay', which is finance and admin.
     * Redirecting somebody's money is not the same kind of act as
     * correcting their phone number, and should not ride on the same
     * permission.
     *
     * Not editable by the partner either, for the same reason: whoever
     * talks their way into a partner login must not be able to move the
     * destination. They can see what is on file and tell us if it is wrong.
     */
    public function payDetails(Request $request): void
    {
        $this->authorize('partners.pay');

        $partner = $this->find($request->paramInt('id'));

        $method = (string) $request->input('pay_method');
        $method = in_array($method, ['mpesa', 'bank'], true) ? $method : null;

        $v = new Validator($request->all());
        $v->maxLen('pay_phone', 30, 'M-Pesa number')
          ->maxLen('bank_name', 120, 'Bank')
          ->maxLen('bank_branch', 120, 'Branch')
          ->maxLen('bank_account_name', 160, 'Account name')
          ->maxLen('bank_account_no', 40, 'Account number');

        if ($v->fails()) {
            $v->redirectBack('/partners-admin/' . $partner['id'] . '?tab=money');
        }

        $phone   = trim((string) $request->input('pay_phone'));
        $account = trim((string) $request->input('bank_account_no'));

        // Refuse a method with nothing behind it rather than storing a
        // half-set destination that only fails on payment day.
        if ($method === 'mpesa' && $phone === '' && trim((string) $partner['phone']) === '') {
            Session::error('Give an M-Pesa number, or the payment will have nowhere to go.');
            Response::to('/partners-admin/' . $partner['id'] . '?tab=money');
        }

        if ($method === 'bank' && $account === '') {
            Session::error('Give the account number, or the payment will have nowhere to go.');
            Response::to('/partners-admin/' . $partner['id'] . '?tab=money');
        }

        Database::update('partners', [
            'pay_method'        => $method,
            'pay_phone'         => $phone ?: null,
            'bank_name'         => trim((string) $request->input('bank_name')) ?: null,
            'bank_branch'       => trim((string) $request->input('bank_branch')) ?: null,
            'bank_account_name' => trim((string) $request->input('bank_account_name')) ?: null,
            'bank_account_no'   => $account ?: null,
        ], ['id' => $partner['id']]);

        // Worth a line in the log on its own: this is the setting that
        // decides who receives the money.
        ActivityLog::record(
            'partner_pay_details',
            'partner',
            (int) $partner['id'],
            'Changed how ' . $partner['name'] . ' is paid'
            . ($method === null ? ' (no method set)' : ' (' . $method . ')')
        );

        Session::success('Saved. It applies to the next payment we make them.');
        Response::to('/partners-admin/' . $partner['id'] . '?tab=money');
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

        // Routed through a payout run rather than flipping the rows here.
        // Two reasons: it leaves a record of where the money went, and it
        // cannot touch commission already committed to an open run, which
        // a bare UPDATE could not see and would have paid a second time.
        $done = Payouts::payDirect(
            (int) $partner['id'],
            $byMonth ? $period : null,
            $ref,
            Auth::id()
        );

        $due = $done['paid'];

        if ($due <= 0.009) {
            Session::warning($byMonth
                ? 'There is nothing outstanding for ' . $period . '.'
                : 'There is nothing outstanding to pay.');
            Response::to('/partners-admin/' . $partner['id']);
        }

        $n = $done['months'];

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
            . ($byMonth ? ' for ' . $period : '')
            . ', across ' . $n . ' month' . ($n === 1 ? '' : 's') . '.'
        );
        Response::to('/partners-admin/' . $partner['id']);
    }

    // -- What this partner earns on each thing --------------------------------

    /**
     * Every service and product, with what this partner earns on it.
     *
     * Partners are not all on the same terms — one brings volume, another
     * brings customers nobody else reaches — so a rate has to be settable
     * for this partner on this thing, not only globally per service.
     *
     * @return array{services: list<array<string,mixed>>, products: list<array<string,mixed>>}
     */
    private function rateRows(array $partner, string $search, bool $onlySet): array
    {
        $overrides = Commission::overridesFor((int) $partner['id']);
        $default   = (float) $partner['default_rate'];

        $like   = '%' . $search . '%';
        $params = $search !== '' ? ['q' => $like, 'q2' => $like] : [];
        $where  = $search !== '' ? ' AND (name LIKE :q OR description LIKE :q2)' : '';

        $services = Database::all(
            "SELECT id, name, price, pricing_type, commission_rate
               FROM services WHERE is_active = 1" . $where . " ORDER BY name",
            $params
        );

        $products = Database::all(
            "SELECT id, name, selling_price AS price
               FROM inventory_items WHERE is_active = 1" . $where . " ORDER BY name",
            $params
        );

        foreach ($services as $i => $row) {
            $key = 'service:' . (int) $row['id'];

            $services[$i]['item_type'] = 'service';
            $services[$i]['override']  = $overrides[$key] ?? null;
            $services[$i]['effective'] = Commission::rateFor(
                $overrides,
                'service',
                (int) $row['id'],
                $row['commission_rate'] === null ? null : (float) $row['commission_rate'],
                $default
            );
            $services[$i]['source'] = isset($overrides[$key])
                ? 'this partner'
                : ($row['commission_rate'] === null ? 'their default' : 'the service');
        }

        foreach ($products as $i => $row) {
            $key = 'inventory:' . (int) $row['id'];

            $products[$i]['item_type']       = 'inventory';
            $products[$i]['commission_rate'] = null;   // only services carry one
            $products[$i]['override']        = $overrides[$key] ?? null;
            $products[$i]['effective']       = $overrides[$key] ?? $default;
            $products[$i]['source']          = isset($overrides[$key]) ? 'this partner' : 'their default';
        }

        // "Only the ones I have set" is what makes this usable for a
        // business with hundreds of products: the exceptions are the point,
        // and the rest are just the default written out.
        if ($onlySet) {
            $keep = static fn(array $r): bool => $r['override'] !== null;

            $services = array_values(array_filter($services, $keep));
            $products = array_values(array_filter($products, $keep));
        }

        return ['services' => $services, 'products' => $products];
    }

    public function rates(Request $request): void
    {
        $this->authorize('partners.view');

        $partner = $this->find($request->paramInt('id'));
        $search  = trim((string) $request->query('q', ''));
        $onlySet = $request->query('set') !== null;

        $rows = $this->rateRows($partner, $search, $onlySet);

        $this->view('partners/rates', [
            'title'    => 'Rates — ' . $partner['name'],
            'partner'  => $partner,
            'services' => $rows['services'],
            'products' => $rows['products'],
            'search'   => $search,
            'onlySet'  => $onlySet,
            'setCount' => (int) Database::scalar(
                'SELECT COUNT(*) FROM partner_rates WHERE partner_id = :p',
                ['p' => $partner['id']],
                0
            ),
        ]);
    }

    /**
     * Save the rates on this page.
     *
     * Only what is on the page is touched: with a search or a filter
     * applied, the rows nobody could see must not be wiped by saving the
     * ones they could.
     *
     * An empty box is not zero. Empty means "no rate of your own here,
     * fall through to the next rule", so the row is deleted; zero means
     * "you earn nothing on this", and is stored.
     */
    public function saveRates(Request $request): void
    {
        $this->authorize('partners.manage');

        $partner = $this->find($request->paramInt('id'));
        $posted  = $request->array('rate');
        $changed = 0;

        foreach ($posted as $key => $value) {
            if (!preg_match('/^(service|inventory):([0-9]+)$/', (string) $key, $m)) {
                continue;
            }

            [$whole, $type, $ref] = $m;
            $ref   = (int) $ref;
            $value = trim((string) $value);

            if ($value === '') {
                $changed += Database::run(
                    'DELETE FROM partner_rates
                      WHERE partner_id = :p AND item_type = :t AND ref_id = :r',
                    ['p' => $partner['id'], 't' => $type, 'r' => $ref]
                )->rowCount();

                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            $rate = max(0, min(100, (float) $value));

            // One row per partner per thing, so an existing rate is moved
            // rather than added to.
            Database::run(
                'INSERT INTO partner_rates (partner_id, item_type, ref_id, rate, set_by)
                 VALUES (:p, :t, :r, :rate, :u)
                 ON DUPLICATE KEY UPDATE rate = VALUES(rate), set_by = VALUES(set_by)',
                [
                    'p'    => $partner['id'],
                    't'    => $type,
                    'r'    => $ref,
                    'rate' => $rate,
                    'u'    => Auth::id(),
                ]
            );

            $changed++;
        }

        ActivityLog::record(
            'partner_rates_set',
            'partner',
            (int) $partner['id'],
            'Set commission rates for ' . $partner['name']
        );

        // Deliberately no resync. A rate is what the next commission is
        // earned at, never a repricing of what is already earned — the
        // ledger keeps each entry at the rate in force when the customer
        // paid, and moving that after the fact would change what we have
        // already agreed we owe.
        Session::success('Saved. It applies to commission earned from now on.');

        Response::to('/partners-admin/' . $partner['id'] . '/rates');
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

        // The public application requires the lot, because a stranger's
        // word is all we have. Here somebody has already been met and
        // vetted, so only the name is compulsory and the rest can be
        // filled in as it arrives — they cannot be paid until it is.
        $v = new Validator($request->all());
        $v->require('first_name', 'First name')
          ->maxLen('first_name', 60, 'First name')
          ->maxLen('middle_name', 60, 'Second name')
          ->require('last_name', 'Third name')
          ->maxLen('last_name', 60, 'Third name')
          ->maxLen('occupation', 120, 'Occupation')
          ->maxLen('id_number', 30, 'ID number')
          ->maxLen('office_location', 200, 'Office location')
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

        $name = full_name(
            (string) $request->input('first_name'),
            (string) $request->input('middle_name'),
            (string) $request->input('last_name')
        );

        $id = Database::insert('partners', [
            'partner_code'       => Numbering::next('partner'),
            'first_name'         => trim((string) $request->input('first_name')),
            'middle_name'        => trim((string) $request->input('middle_name')) ?: null,
            'last_name'          => trim((string) $request->input('last_name')),
            'name'               => $name,
            'company'            => trim((string) $request->input('company')) ?: null,
            'occupation'         => trim((string) $request->input('occupation')) ?: null,
            'email'              => $email,
            'phone'              => trim((string) $request->input('phone')),
            'kra_pin'            => trim((string) $request->input('kra_pin')) ?: null,
            'id_number'          => trim((string) $request->input('id_number')) ?: null,
            'office_location'    => trim((string) $request->input('office_location')) ?: null,
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
            'contact_name' => $name,
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
