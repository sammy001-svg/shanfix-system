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
use App\Services\Partners;
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
        Partners::ensureSchema();

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

        // Where, and what sort. Checked against the known sets rather
        // than passed through, so a hand-typed query string cannot widen
        // the filter into something it was not meant to be.
        $country = strtoupper((string) $request->query('country', ''));

        if ($country === 'INTL') {
            // One filter people actually want and no single country code
            // can express: everybody who is not us.
            $clause[] = 'p.country <> :home';
            $params['home'] = Partners::homeCountry();
        } elseif (isset(Partners::COUNTRIES[$country])) {
            $clause[] = 'p.country = :country';
            $params['country'] = $country;
        } else {
            $country = '';
        }

        $tiers = Partners::tiers();
        $tier  = (string) $request->query('tier', '');

        if (isset($tiers[$tier])) {
            $clause[] = 'p.tier = :tier';
            $params['tier'] = $tier;
        } else {
            $tier = '';
        }

        $type = (string) $request->query('type', '');

        if (isset(Partners::TYPES[$type])) {
            $clause[] = 'p.partner_type = :type';
            $params['type'] = $type;
        } else {
            $type = '';
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

        $counts['international'] = (int) Database::scalar(
            'SELECT COUNT(*) FROM partners WHERE country <> :home',
            ['home' => Partners::homeCountry()],
            0
        );

        $this->view('partners/index', [
            'title'      => 'Partners',
            'rows'       => $rows,
            'status'     => $status,
            'mine'       => $mine,
            'counts'     => $counts,
            'country'    => $country,
            'tier'       => $tier,
            'type'       => $type,
            'tiers'      => $tiers,
            'countries'  => Partners::countryOptions(),
            'byCountry'  => Partners::byCountry(),
            'home'       => Partners::homeCountry(),

            // Two things finance wants to know before building a run,
            // not after it has held six lines and sent nobody anything.
            'missingFx'  => Partners::currenciesMissingRates(),
            'expiring'   => Partners::expiringAgreements(60),
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

            // Where they are and what that costs. Worked out here rather
            // than in the view so the view has no decisions left in it.
            'blockers'    => Partners::blockers($partner),
            'warnings'    => Partners::complianceWarnings($partner),
            'payMethods'  => Partners::payMethodsFor((string) $partner['country']),
            'currencies'  => Partners::payoutCurrencies(),
            'countries'   => Partners::countryOptions(),
            'tiers'       => Partners::tiers(),
            'earnedTier'  => Partners::earnedTier((int) $partner['id']),
            'billed12m'   => Partners::billed12m((int) $partner['id']),
            'taxLabel'    => Partners::taxLabel((string) $partner['country']),
            'whtRate'     => Partners::withholdingRate($partner),
            'whtOn'       => Partners::withholdingEnabled(),
            'fxRate'      => Partners::fxRate((string) $partner['payout_currency']),
            'isLocal'     => Partners::isLocal((string) $partner['country']),
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

        $place = $this->placeInput($request, $partner);

        Database::update('partners', $place + [
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
            'phone'           => Partners::e164(
                (string) $request->input('phone'),
                $place['country']
            ),
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

        $country = (string) ($partner['country'] ?? Partners::homeCountry());

        // Only the methods that work from where we bank to where they
        // are. M-Pesa reaches Kenyan numbers and nowhere else, and a
        // Kenyan bank file cannot address an account in Dubai — offering
        // either of those for an overseas partner would only produce a
        // payment that fails at the bank days later.
        $allowed = Partners::payMethodsFor($country);

        // "None" and "something we cannot use" are different answers and
        // must not be treated alike. Blank is a deliberate choice to have
        // no method; anything else that is not in the list is a mistake,
        // and quietly storing null for it would wipe a perfectly good
        // destination — which is exactly what happens when a partner's
        // country is changed and somebody then saves this form.
        $chosen = trim((string) $request->input('pay_method'));

        if ($chosen !== '' && !isset($allowed[$chosen])) {
            Session::error(sprintf(
                '%s cannot be used for a partner in %s. Nothing was changed.',
                Partners::payMethodLabel($chosen),
                Partners::countryName($country)
            ));
            Response::to('/partners-admin/' . $partner['id'] . '?tab=money');
        }

        $method = $chosen === '' ? null : $chosen;

        $v = new Validator($request->all());
        $v->maxLen('pay_phone', 30, 'M-Pesa number')
          ->maxLen('bank_name', 120, 'Bank')
          ->maxLen('bank_branch', 120, 'Branch')
          ->maxLen('bank_account_name', 160, 'Account name')
          ->maxLen('bank_account_no', 40, 'Account number')
          ->maxLen('bank_swift', 20, 'SWIFT/BIC')
          ->maxLen('bank_iban', 40, 'IBAN')
          ->maxLen('bank_routing', 40, 'Routing code')
          ->maxLen('bank_address', 200, 'Bank address');

        if (trim((string) $request->input('pay_email')) !== '') {
            $v->email('pay_email', 'Payment email');
        }

        if ($v->fails()) {
            $v->redirectBack('/partners-admin/' . $partner['id'] . '?tab=money');
        }

        $bankCountry = strtoupper(trim((string) $request->input('bank_country')));
        $bankCountry = isset(Partners::COUNTRIES[$bankCountry]) ? $bankCountry : null;

        $currency = strtoupper(trim((string) $request->input('payout_currency')));
        $currency = isset(Partners::payoutCurrencies()[$currency])
            ? $currency
            : (string) ($partner['payout_currency'] ?? Partners::baseCurrency());

        $details = [
            'pay_method'        => $method,
            'pay_phone'         => trim((string) $request->input('pay_phone')) ?: null,
            'pay_email'         => strtolower(trim((string) $request->input('pay_email'))) ?: null,
            'bank_name'         => trim((string) $request->input('bank_name')) ?: null,
            'bank_branch'       => trim((string) $request->input('bank_branch')) ?: null,
            'bank_account_name' => trim((string) $request->input('bank_account_name')) ?: null,
            'bank_account_no'   => trim((string) $request->input('bank_account_no')) ?: null,
            'bank_swift'        => strtoupper(trim((string) $request->input('bank_swift'))) ?: null,
            'bank_iban'         => strtoupper(preg_replace('/\s+/', '',
                                     (string) $request->input('bank_iban')) ?? '') ?: null,
            'bank_routing'      => trim((string) $request->input('bank_routing')) ?: null,
            'bank_country'      => $bankCountry,
            'bank_address'      => trim((string) $request->input('bank_address')) ?: null,
            'payout_currency'   => $currency,
        ];

        // Refuse a method with nothing behind it rather than storing a
        // half-set destination that only fails on payment day. Asked of
        // the one place that knows what each method needs, so a new way
        // of paying somebody cannot be added without its own check — and
        // only about the destination, so a missing tax number does not
        // stop somebody recording a bank account.
        $problems = Partners::destinationBlockers(array_merge($partner, $details));

        if ($method !== null && $problems !== []) {
            Session::error($problems[0] . ' — the payment would have nowhere to go.');
            Response::to('/partners-admin/' . $partner['id'] . '?tab=money');
        }

        Database::update('partners', $details, ['id' => $partner['id']]);

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
            'title'      => 'Register a partner',
            'partner'    => null,
            'managers'   => $this->salesTeam(),
            'rate'       => (float) Settings::get('partner_default_rate', 10),
            'countries'  => Partners::countryOptions(),
            'currencies' => Partners::payoutCurrencies(),
            'tiers'      => Partners::tiers(),
            'home'       => Partners::homeCountry(),
            'base'       => Partners::baseCurrency(),
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

        $place = $this->placeInput($request);

        // A rate of their own where one was typed, otherwise the rate that
        // goes with their tier. Nobody should have to remember that Gold
        // means 18%.
        $typedRate = trim((string) $request->input('default_rate'));
        $tierRate  = (float) (Partners::tier($place['tier'])['default_rate'] ?? 10.0);
        $rate      = $typedRate === '' ? $tierRate : max(0, min(100, (float) $typedRate));

        $id = Database::insert('partners', $place + [
            'partner_code'       => Numbering::next('partner'),
            'first_name'         => trim((string) $request->input('first_name')),
            'middle_name'        => trim((string) $request->input('middle_name')) ?: null,
            'last_name'          => trim((string) $request->input('last_name')),
            'name'               => $name,
            'company'            => trim((string) $request->input('company')) ?: null,
            'occupation'         => trim((string) $request->input('occupation')) ?: null,
            'email'              => $email,
            // Stored in international form, because a partner in Kampala
            // typing 0771… means +256 771…, and normalize_phone() would
            // make it a Kenyan number that reaches a stranger.
            'phone'              => Partners::e164(
                (string) $request->input('phone'),
                $place['country']
            ),
            'kra_pin'            => trim((string) $request->input('kra_pin')) ?: null,
            'id_number'          => trim((string) $request->input('id_number')) ?: null,
            'office_location'    => trim((string) $request->input('office_location')) ?: null,
            'pitch'              => trim((string) $request->input('pitch')) ?: null,
            'default_rate'       => $rate,
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
            'phone'        => Partners::e164((string) $request->input('phone'), $place['country']),
            'link'         => Notifier::absoluteUrl('/partners/start'),
            'rate'         => rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%',
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
    // -- Exchange rates ----------------------------------------------------

    /**
     * The rates partner payouts are converted at.
     *
     * A page of its own rather than a corner of settings, because this is
     * a thing finance does every month and the rest of settings is not.
     */
    public function fx(Request $request): void
    {
        $this->authorize('partners.view');

        $currencies = Partners::payoutCurrencies();
        $base       = Partners::baseCurrency();

        unset($currencies[$base]);

        $this->view('partners/fx', [
            'title'      => 'Exchange rates',
            'base'       => $base,
            'currencies' => $currencies,
            'latest'     => Partners::latestRates(),
            'missing'    => Partners::currenciesMissingRates(),
            'history'    => Database::all(
                "SELECT f.*, u.name AS set_by_name
                   FROM fx_rates f
              LEFT JOIN users u ON u.id = f.set_by
                  WHERE f.currency <> :base
               ORDER BY f.as_of DESC, f.currency
                  LIMIT 60",
                ['base' => $base]
            ),
            // Who is waiting on a rate, so entering one has a visible point.
            'waiting'    => Database::all(
                "SELECT payout_currency AS currency, COUNT(*) AS partners
                   FROM partners
                  WHERE status = 'active' AND payout_currency <> :base
               GROUP BY payout_currency
               ORDER BY partners DESC",
                ['base' => $base]
            ),
        ]);
    }

    public function saveFx(Request $request): void
    {
        $this->authorize('partners.pay');

        $currency = strtoupper(trim((string) $request->input('currency')));
        $rate     = (float) str_replace(',', '', (string) $request->input('rate'));
        $asOf     = trim((string) $request->input('as_of')) ?: date('Y-m-d');

        if (!isset(Partners::payoutCurrencies()[$currency])
            || $currency === Partners::baseCurrency()) {
            Session::error('That is not a currency we pay out in.');
            Response::to('/partners-admin/fx');
        }

        // A rate of zero or less would convert a real payout into nothing
        // or into a negative, and is far more likely to be a typo than an
        // instruction.
        if ($rate <= 0) {
            Session::error('The rate has to be more than zero.');
            Response::to('/partners-admin/fx');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            Session::error('Give the date the rate applies from as YYYY-MM-DD.');
            Response::to('/partners-admin/fx');
        }

        Partners::setFxRate(
            $currency,
            $rate,
            $asOf,
            trim((string) $request->input('source')) ?: null,
            Auth::id()
        );

        // Logged because it changes what partners are paid, which is the
        // same reason the pay details are logged.
        ActivityLog::record(
            'fx_rate_set',
            'fx_rate',
            0,
            sprintf('%s at %s %s from %s', $currency, Partners::baseCurrency(), $rate, $asOf)
        );

        Session::success(sprintf(
            'Saved. Payouts in %s are converted at %s %s from %s.',
            $currency,
            Partners::baseCurrency(),
            rtrim(rtrim(number_format($rate, 6), '0'), '.'),
            $asOf
        ));
        Response::to('/partners-admin/fx');
    }

    // -- Tiers -------------------------------------------------------------

    /**
     * The tiers, their thresholds and the benefits we publish for each.
     *
     * Editable here because the benefits are shown on the public partner
     * programme page: a promise on the website that nobody in the system
     * can change is a promise that goes stale.
     */
    public function tiers(Request $request): void
    {
        $this->authorize('partners.view');

        $tiers = Partners::tiers();

        // How many partners sit at each tier, and how many have outgrown
        // it. The second number is the point of the page.
        foreach ($tiers as $code => $tier) {
            $tiers[$code]['partners'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM partners WHERE tier = :t AND status = 'active'",
                ['t' => $code],
                0
            );
        }

        $due = [];

        foreach (Database::all("SELECT * FROM partners WHERE status = 'active'") as $partner) {
            $earned = Partners::earnedTier((int) $partner['id']);

            if ($earned === null || $earned['code'] === $partner['tier']) {
                continue;
            }

            // Only promotions. Somebody whose good year has rolled out of
            // the window is not demoted by a report.
            if ((int) $earned['sort'] > (int) ($tiers[$partner['tier']]['sort'] ?? 0)) {
                $due[] = [
                    'partner' => $partner,
                    'earned'  => $earned,
                    'billed'  => Partners::billed12m((int) $partner['id']),
                ];
            }
        }

        $this->view('partners/tiers', [
            'title' => 'Partner programme',
            'tiers' => $tiers,
            'due'   => $due,

            // The settings that decide what the programme IS. They have
            // lived in the settings table since the module was written
            // with nothing anywhere to edit them, so the only way to
            // change the commission rate or switch withholding on was to
            // open the database.
            'settings'   => [
                'partners_enabled'             => Settings::bool('partners_enabled', true),
                'partner_signup_enabled'       => Settings::bool('partner_signup_enabled', true),
                'partner_international_enabled'=> Settings::bool('partner_international_enabled', true),
                'partner_default_rate'         => Settings::float('partner_default_rate', 10),
                'partner_payout_currencies'    => (string) Settings::get('partner_payout_currencies', 'KES'),
                'partner_withholding_enabled'  => Settings::bool('partner_withholding_enabled', false),
                'partner_wht_resident'         => Settings::float('partner_wht_resident', 5),
                'partner_wht_non_resident'     => Settings::float('partner_wht_non_resident', 20),
                'partner_programme_headline'   => (string) Settings::get('partner_programme_headline', ''),
                'partner_programme_intro'      => (string) Settings::get('partner_programme_intro', ''),
                'partner_terms'                => (string) Settings::get('partner_terms', ''),
            ],

            // How many people a change to withholding would actually
            // affect, so turning it on is not a decision made blind.
            'activeCount' => (int) Database::scalar(
                "SELECT COUNT(*) FROM partners WHERE status = 'active'",
                [],
                0
            ),
            'nonResident' => (int) Database::scalar(
                "SELECT COUNT(*) FROM partners WHERE status = 'active' AND tax_residency = 'non_resident'",
                [],
                0
            ),
        ]);
    }

    /**
     * The programme's own settings.
     *
     * Its own action rather than part of saveTiers, because the two are
     * different decisions with different consequences: a tier's benefits
     * are copy on a web page, and the withholding switch changes what
     * every partner is paid.
     */
    public function saveProgramme(Request $request): void
    {
        $this->authorize('partners.manage');

        $currencies = [];

        foreach (explode(',', (string) $request->input('partner_payout_currencies')) as $code) {
            $code = strtoupper(trim($code));

            if (preg_match('/^[A-Z]{3}$/', $code)) {
                $currencies[$code] = $code;
            }
        }

        // The base currency is not optional. Dropping it would leave
        // every local partner with a currency we have said we do not pay
        // in, and hold every one of their lines.
        $base = Partners::baseCurrency();
        $currencies = [$base => $base] + $currencies;

        $wasWithholding = Settings::bool('partner_withholding_enabled', false);
        $nowWithholding = $request->input('partner_withholding_enabled') !== null;

        Settings::setMany([
            'partners_enabled'              => $request->input('partners_enabled') !== null ? '1' : '0',
            'partner_signup_enabled'        => $request->input('partner_signup_enabled') !== null ? '1' : '0',
            'partner_international_enabled' => $request->input('partner_international_enabled') !== null ? '1' : '0',
            'partner_default_rate'          => (string) max(0, min(100, (float) $request->input('partner_default_rate', 10))),
            'partner_payout_currencies'     => implode(',', array_keys($currencies)),
            'partner_withholding_enabled'   => $nowWithholding ? '1' : '0',
            'partner_wht_resident'          => (string) max(0, min(100, (float) $request->input('partner_wht_resident', 5))),
            'partner_wht_non_resident'      => (string) max(0, min(100, (float) $request->input('partner_wht_non_resident', 20))),
            'partner_programme_headline'    => trim((string) $request->input('partner_programme_headline')),
            'partner_programme_intro'       => trim((string) $request->input('partner_programme_intro')),
            'partner_terms'                 => trim((string) $request->input('partner_terms')),
        ]);

        Settings::flush();

        // Logged on its own, with the direction of the change, because
        // this is the setting that decides whether partners start being
        // paid less than they were last month.
        if ($wasWithholding !== $nowWithholding) {
            ActivityLog::record(
                'partner_withholding_' . ($nowWithholding ? 'enabled' : 'disabled'),
                'setting',
                0,
                'Withholding on partner commission was switched '
                . ($nowWithholding ? 'ON' : 'OFF')
            );
        }

        Session::success($wasWithholding !== $nowWithholding && $nowWithholding
            ? 'Saved. Withholding is now ON — the next payout run will deduct it.'
            : 'Saved.');

        Response::to('/partners-admin/tiers');
    }

    public function saveTiers(Request $request): void
    {
        $this->authorize('partners.manage');

        $input = $request->input('tiers');

        if (!is_array($input)) {
            Session::error('Nothing to save.');
            Response::to('/partners-admin/tiers');
        }

        $known = Partners::tiers();
        $saved = 0;

        foreach ($input as $code => $fields) {
            if (!isset($known[(string) $code]) || !is_array($fields)) {
                continue;
            }

            Database::update('partner_tiers', [
                'name'           => trim((string) ($fields['name'] ?? '')) ?: $known[$code]['name'],
                'min_billed_12m' => max(0, (float) str_replace(',', '', (string) ($fields['min_billed_12m'] ?? 0))),
                'default_rate'   => max(0, min(100, (float) ($fields['default_rate'] ?? 0))),
                'benefits'       => trim((string) ($fields['benefits'] ?? '')) ?: null,
                'is_public'      => !empty($fields['is_public']) ? 1 : 0,
            ], ['code' => (string) $code]);

            $saved++;
        }

        ActivityLog::record('partner_tiers_saved', 'partner_tier', 0, 'Updated ' . $saved . ' tier(s)');

        Session::success('Saved. The programme page on the website shows this straight away.');
        Response::to('/partners-admin/tiers');
    }

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

    /**
     * The fields that say where a partner is and what they are.
     *
     * Shared by create and edit rather than written out twice, because
     * the two drifting apart is how a field ends up settable on one form
     * and not the other — and the whole international story depends on
     * these being right on both.
     *
     * Everything is checked against a known set. A country that is not in
     * the list, a currency finance has not agreed to pay in, or a tier
     * that does not exist all fall back to something safe rather than
     * being written through to the database.
     *
     * @return array<string, mixed> columns ready for an insert or update
     */
    private function placeInput(Request $request, ?array $existing = null): array
    {
        $country = strtoupper(trim((string) $request->input('country')));
        $country = isset(Partners::COUNTRIES[$country]) ? $country : Partners::homeCountry();

        $type = (string) $request->input('partner_type');
        $type = isset(Partners::TYPES[$type]) ? $type : 'referral';

        $tiers = Partners::tiers();
        $tier  = (string) $request->input('tier');
        $tier  = isset($tiers[$tier]) ? $tier : (string) ($existing['tier'] ?? 'registered');

        $residency = (string) $request->input('tax_residency');
        $residency = $residency === 'non_resident' ? 'non_resident' : 'resident';

        $currency = strtoupper(trim((string) $request->input('payout_currency')));
        $currency = isset(Partners::payoutCurrencies()[$currency])
            ? $currency
            : Partners::baseCurrency();

        // Blank is not zero. A blank box means "use the rate for their
        // residency"; a typed 0 means "withhold nothing from this one",
        // which is a different instruction and is why the column is
        // nullable. See Partners::withholdingRate().
        $wht = trim((string) $request->input('withholding_rate'));
        $wht = $wht === '' ? null : max(0, min(100, (float) $wht));

        $timezone = trim((string) $request->input('timezone'));

        if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = '';
        }

        $dates = [];

        foreach (['agreement_signed_at', 'agreement_expires_at'] as $field) {
            $raw = trim((string) $request->input($field));
            // A half-typed date is worse than no date: it sorts, filters
            // and compares as though it meant something.
            $dates[$field] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
        }

        return [
            'country'              => $country,
            'city'                 => trim((string) $request->input('city')) ?: null,
            'timezone'             => $timezone ?: null,
            'territory'            => trim((string) $request->input('territory')) ?: null,
            'partner_type'         => $type,
            'tier'                 => $tier,
            'tax_residency'        => $residency,
            'tax_id'               => trim((string) $request->input('tax_id')) ?: null,
            'tax_id_label'         => Partners::taxLabel($country),
            'withholding_rate'     => $wht,
            'payout_currency'      => $currency,
            'agreement_ref'        => trim((string) $request->input('agreement_ref')) ?: null,
            'agreement_signed_at'  => $dates['agreement_signed_at'],
            'agreement_expires_at' => $dates['agreement_expires_at'],
        ];
    }

    private function find(int $id): array
    {
        Partners::ensureSchema();

        $row = Database::first('SELECT * FROM partners WHERE id = :id', ['id' => $id]);

        if (!$row) {
            throw new HttpException(404, 'That partner was not found.');
        }

        return $row;
    }
}
