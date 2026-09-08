<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\PartnerAuth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\Commission;
use App\Services\Notifier;
use App\Services\Payouts;
use App\Services\StaffNotifier;

/**
 * The partner portal.
 *
 * Every query here is scoped to PartnerAuth::id() and nothing else. A
 * partner names no record: the partner they are comes from their session,
 * so asking for somebody else's customers or commission is not refused so
 * much as impossible to express.
 *
 * What a partner may see of a customer is deliberately narrow — the
 * customer's name, what they have been invoiced, and what that earned.
 * Not the invoice itself, not the line items, not what the customer's
 * contact said on the phone. The customer is our client, not theirs.
 */
class PartnerController extends Controller
{
    private const PER_PAGE = 25;

    private function me(): array
    {
        $me = PartnerAuth::user();

        if (!$me) {
            Response::to('/partners/login');
        }

        return $me;
    }

    // -- Overview ----------------------------------------------------------

    public function home(Request $request): void
    {
        $me      = $this->me();
        $summary = Commission::summaryFor((int) $me['id']);

        $recent = Database::all(
            "SELECT cm.id, cm.amount, cm.rate, cm.status, cm.created_at,
                    d.doc_number, d.issue_date,
                    c.name AS client_name
               FROM commissions cm
               JOIN documents d ON d.id = cm.document_id
               JOIN clients   c ON c.id = cm.client_id
              WHERE cm.partner_id = :p AND cm.status <> 'void'
           ORDER BY cm.id DESC
              LIMIT 6",
            ['p' => $me['id']]
        );

        $customers = Database::all(
            "SELECT c.id, c.name, c.partner_linked_at,
                    COALESCE(SUM(CASE WHEN cm.status <> 'void' THEN cm.amount END), 0) AS earned
               FROM clients c
          LEFT JOIN commissions cm ON cm.client_id = c.id AND cm.partner_id = :p2
              WHERE c.partner_id = :p
           GROUP BY c.id, c.name, c.partner_linked_at
           ORDER BY earned DESC, c.name
              LIMIT 5",
            ['p' => $me['id'], 'p2' => $me['id']]
        );

        $this->view('partner/home', [
            'title'     => 'Your account',
            'me'        => $me,
            'summary'   => $summary,
            'recent'    => $recent,
            'customers' => $customers,
            'company'   => Settings::company(),
        ], 'partner');
    }

    // -- What they have earned ---------------------------------------------

    public function commissions(Request $request): void
    {
        $me = $this->me();

        $show  = (string) $request->query('show', '');
        $show  = in_array($show, ['earned', 'paid'], true) ? $show : '';
        $where = $show !== '' ? ' AND cm.status = :s' : " AND cm.status <> 'void'";

        $params = ['p' => $me['id']];
        if ($show !== '') {
            $params['s'] = $show;
        }

        $base = "FROM commissions cm
                   JOIN documents d ON d.id = cm.document_id
                   JOIN clients   c ON c.id = cm.client_id
                  WHERE cm.partner_id = :p" . $where;

        $total = (int) Database::scalar('SELECT COUNT(*) ' . $base, $params, 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = max(1, min($pages, (int) $request->query('page', 1)));

        $rows = Database::all(
            "SELECT cm.id, cm.amount, cm.rate, cm.base_amount, cm.status,
                    cm.created_at, cm.paid_at, cm.payout_ref,
                    d.doc_number, d.issue_date,
                    c.name AS client_name "
            . $base . "
             ORDER BY cm.id DESC
                LIMIT " . self::PER_PAGE . " OFFSET " . (($page - 1) * self::PER_PAGE),
            $params
        );

        $this->view('partner/commissions', [
            'title'   => 'What you have earned',
            'me'      => $me,
            'rows'    => $rows,
            'show'    => $show,
            'summary' => Commission::summaryFor((int) $me['id']),
            // The months, so each one can be opened as a statement they can
            // invoice us against — which is what the terms say happens.
            'months'  => Commission::byMonth((int) $me['id'], 12),
            // What we have actually sent them, and where. They ask this
            // the moment a figure and their bank statement disagree.
            'payouts' => Payouts::historyFor((int) $me['id'], 12),
            'page'    => $page,
            'pages'   => $pages,
            'total'   => $total,
            'company' => Settings::company(),
        ], 'partner');
    }

    // -- Registering a customer ---------------------------------------------

    public function showRegisterClient(Request $request): void
    {
        $me = $this->me();

        $this->view('partner/client-new', [
            'title'   => 'Register a customer',
            'me'      => $me,
            'company' => Settings::company(),
        ], 'partner');
    }

    /**
     * Put a customer of theirs on our books.
     *
     * A partner may register a customer and say what they want. They may
     * not raise a quotation, an invoice or a payment against them — that
     * is ours — so this is the one place a partner writes something into
     * the trading side of the system, and it is fenced accordingly.
     *
     * The brief becomes a lead as well as a client, because a paragraph
     * about what somebody wants is worth nothing sitting in a notes field.
     * As a lead it lands in the pipeline where a person works it.
     */
    public function registerClient(Request $request): void
    {
        $me = $this->me();

        $v = new Validator($request->all());
        $v->require('name', 'Their name')
          ->maxLen('name', 180, 'Their name')
          ->in('client_type', ['individual', 'company'], 'Kind of customer')
          ->maxLen('contact_person', 140, 'Contact person')
          ->email('email', 'Email address')
          ->phone('phone', 'Phone number')
          ->phone('alt_phone', 'Alternative phone')
          ->maxLen('kra_pin', 30, 'KRA PIN')
          ->maxLen('address', 255, 'Address')
          ->maxLen('city', 80, 'Town')
          ->maxLen('industry', 120, 'Industry')
          ->require('brief', 'What they want');

        // We have to be able to reach them, or there is nothing to act on.
        if (!$request->input('email') && !$request->input('phone')) {
            $v->custom('phone', false, 'Give at least a phone number or an email address.');
        }

        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Session::flashInput($request->all());
            Response::to('/partners/clients/new');
        }

        $email = $request->input('email') ? strtolower(trim((string) $request->input('email'))) : null;
        $phone = $request->input('phone') ? trim((string) $request->input('phone')) : null;

        // Somebody already on our books is not theirs to claim. Commission
        // follows the customer, so re-tagging on a second registration
        // would let a partner take a share of business that was already
        // ours, or somebody else's, by typing in an address they guessed.
        //
        // So an existing customer is never re-tagged here. It goes to a
        // person to sort out, and the partner is told it is being looked
        // at rather than being told whose it is — the answer to "is this
        // company already your customer" is not one a form should give.
        $existing = null;

        if ($email !== null || $phone !== null) {
            $existing = Database::first(
                'SELECT id FROM clients
                  WHERE (:e IS NOT NULL AND email = :e2)
                     OR (:p IS NOT NULL AND phone = :p2)
                  LIMIT 1',
                ['e' => $email, 'e2' => $email, 'p' => $phone, 'p2' => $phone]
            );
        }

        $brief = trim((string) $request->input('brief'));
        $name  = trim((string) $request->input('name'));

        if ($existing) {
            $this->flagForStaff($me, $name, $email, $phone, $brief);

            Session::flash(
                'success',
                'Thank you. We already have a record that looks like ' . $name
                . ', so your account manager is checking it before anything is '
                . 'tagged to you. They will come back to you.'
            );
            Response::to('/partners/customers');
        }

        $clientId = Database::insert('clients', [
            'client_code'       => \App\Core\Numbering::next('client'),
            'client_type'       => (string) $request->input('client_type', 'company'),
            'name'              => $name,
            'contact_person'    => trim((string) $request->input('contact_person')) ?: null,
            'email'             => $email,
            'phone'             => $phone,
            'alt_phone'         => trim((string) $request->input('alt_phone')) ?: null,
            'kra_pin'           => $request->input('kra_pin')
                                     ? strtoupper(trim((string) $request->input('kra_pin')))
                                     : null,
            'address'           => trim((string) $request->input('address')) ?: null,
            'city'              => trim((string) $request->input('city')) ?: null,
            'industry'          => trim((string) $request->input('industry')) ?: null,
            'status'            => 'active',
            // created_by is a staff user, and a partner is not one. The
            // partner link below is what records who brought them.
            'partner_id'        => (int) $me['id'],
            'partner_linked_at' => date('Y-m-d H:i:s'),
        ]);

        // The brief, as work rather than as a paragraph nobody opens.
        $leadId = Database::insert('leads', [
            'lead_number'         => \App\Core\Numbering::next('lead'),
            'name'                => $name,
            'company'             => (string) $request->input('client_type', 'company') === 'company' ? $name : null,
            'email'               => $email,
            'phone'               => $phone,
            'source'              => 'referral',
            'partner_id'          => (int) $me['id'],
            'requirement'         => $brief,
            'stage'               => 'new',
            'converted_client_id' => $clientId,
        ]);

        ActivityLog::record(
            'partner_registered_client',
            'client',
            $clientId,
            $me['name'] . ' registered ' . $name
        );

        $this->tellStaff(
            'A partner has registered a customer',
            $me['name'] . ' registered ' . $name . '.',
            '/clients/' . $clientId,
            'client',
            $clientId
        );

        // The partner hears it from us rather than having to trust that
        // the form worked.
        Notifier::dispatch('partner_client_registered', [
            'entity_type'  => 'partner',
            'entity_id'    => (int) $me['id'],
            'contact_name' => $me['name'],
            'email'        => $me['email'],
            'phone'        => $me['phone'],
            'client_name'  => $name,
        ]);

        Notifier::processQueue(4);

        Session::flash('success', $name . ' is registered to you. We will be in touch with them.');
        Response::to('/partners/customers/' . $clientId);
    }

    /**
     * Raise a possible duplicate with the people who can settle it.
     *
     * Deliberately a lead and not a client: nothing is tagged to anybody
     * until somebody has looked.
     */
    private function flagForStaff(array $me, string $name, ?string $email, ?string $phone, string $brief): void
    {
        $leadId = Database::insert('leads', [
            'lead_number' => \App\Core\Numbering::next('lead'),
            'name'        => $name,
            'email'       => $email,
            'phone'       => $phone,
            'source'      => 'referral',
            'partner_id'  => (int) $me['id'],
            'requirement' => "[Already on our books — check who this belongs to before tagging]\n\n" . $brief,
            'stage'       => 'new',
        ]);

        ActivityLog::record(
            'partner_client_duplicate',
            'lead',
            $leadId,
            $me['name'] . ' tried to register ' . $name . ', who looks like an existing customer'
        );

        $this->tellStaff(
            'A partner registered a customer we may already have',
            $me['name'] . ' registered ' . $name . ', who matches a customer already on our books. '
            . 'Nothing has been tagged to them.',
            '/leads/' . $leadId,
            'lead',
            $leadId
        );
    }

    // -- Their customers ----------------------------------------------------

    public function customers(Request $request): void
    {
        $me = $this->me();

        $rows = Database::all(
            "SELECT c.id, c.name, c.city, c.status, c.partner_linked_at,
                    COALESCE(SUM(CASE WHEN cm.status <> 'void' THEN cm.amount END), 0) AS earned,
                    COUNT(DISTINCT CASE WHEN cm.status <> 'void' THEN cm.document_id END) AS invoices
               FROM clients c
          LEFT JOIN commissions cm ON cm.client_id = c.id AND cm.partner_id = :p2
              WHERE c.partner_id = :p
           GROUP BY c.id, c.name, c.city, c.status, c.partner_linked_at
           ORDER BY earned DESC, c.name",
            ['p' => $me['id'], 'p2' => $me['id']]
        );

        $this->view('partner/customers', [
            'title'   => 'Your customers',
            'me'      => $me,
            'rows'    => $rows,
            'company' => Settings::company(),
        ], 'partner');
    }

    /**
     * The catalogue a partner sells from.
     *
     * Services and products together, with pictures, because this is the
     * page somebody opens in front of a customer. What each one earns them
     * is worked out here rather than in the view: the fallback to their own
     * rate is a rule, and it is the same rule the commission ledger uses.
     */
    /**
     * One customer of theirs, and everything we have done for them.
     *
     * Read-only, and scoped by partner_id in the first query rather than
     * checked afterwards: a partner asking for a customer who is not
     * theirs gets a 404, not somebody else's trading history.
     *
     * Drafts are left out. A quotation still being written is not
     * something the partner should be telling their customer about, and a
     * figure that changes before it is sent would only cause an argument.
     */
    public function client(Request $request): void
    {
        $me = $this->me();
        $id = $request->paramInt('id');

        $client = Database::first(
            'SELECT * FROM clients WHERE id = :id AND partner_id = :p',
            ['id' => $id, 'p' => $me['id']]
        );

        if (!$client) {
            throw new HttpException(404, 'That is not one of your customers.');
        }

        $documents = Database::all(
            "SELECT id, doc_type, doc_number, issue_date, due_date, status,
                    total, amount_paid, balance
               FROM documents
              WHERE client_id = :c
                AND doc_type IN ('quotation', 'invoice')
                AND status <> 'draft'
           ORDER BY issue_date DESC, id DESC",
            ['c' => $client['id']]
        );

        $invoiced = 0.0;
        $paid     = 0.0;
        $owing    = 0.0;

        foreach ($documents as $doc) {
            if ($doc['doc_type'] !== 'invoice' || $doc['status'] === 'cancelled') {
                continue;
            }

            $invoiced += (float) $doc['total'];
            $paid     += (float) $doc['amount_paid'];
            $owing    += (float) $doc['balance'];
        }

        // What this one customer has earned them, split the way the money
        // actually behaves: earned when the customer paid, paid when it
        // reached the partner.
        $commission = Database::first(
            "SELECT COALESCE(SUM(amount), 0)                                        AS total,
                    COALESCE(SUM(CASE WHEN status = 'earned' THEN amount END), 0)    AS due,
                    COALESCE(SUM(CASE WHEN status = 'paid'   THEN amount END), 0)    AS paid,
                    COUNT(*)                                                         AS entries
               FROM commissions
              WHERE client_id = :c AND partner_id = :p AND status <> 'void'",
            ['c' => $client['id'], 'p' => $me['id']]
        ) ?: ['total' => 0, 'due' => 0, 'paid' => 0, 'entries' => 0];

        // What they told us the customer wanted, when they registered them.
        $briefs = Database::all(
            "SELECT lead_number, requirement, stage, created_at
               FROM leads
              WHERE converted_client_id = :c AND partner_id = :p
                AND requirement IS NOT NULL AND requirement <> ''
           ORDER BY id DESC",
            ['c' => $client['id'], 'p' => $me['id']]
        );

        $this->view('partner/client', [
            'title'      => $client['name'],
            'me'         => $me,
            'client'     => $client,
            'documents'  => $documents,
            'invoiced'   => $invoiced,
            'paid'       => $paid,
            'owing'      => $owing,
            'commission' => $commission,
            'briefs'     => $briefs,
            'company'    => Settings::company(),
        ], 'partner');
    }

    public function services(Request $request): void
    {
        $me     = $this->me();
        $search = trim((string) $request->query('q', ''));

        $like   = '%' . $search . '%';
        $params = $search !== '' ? ['q' => $like, 'q2' => $like] : [];
        $where  = $search !== '' ? ' AND (name LIKE :q OR description LIKE :q2)' : '';

        $services = Database::all(
            "SELECT id, code, name, description, pricing_type, price, unit_label,
                    lead_time, commission_rate
               FROM services
              WHERE is_active = 1" . $where . "
           ORDER BY name",
            $params
        );

        // Inventory carries no rate of its own — only services can — so a
        // product always pays the partner's own rate. Selected with the
        // same shape as a service so one card can draw either.
        $products = Database::all(
            "SELECT id, sku AS code, name, description, selling_price AS price,
                    unit AS unit_label
               FROM inventory_items
              WHERE is_active = 1" . $where . "
           ORDER BY name",
            $params
        );

        $default = (float) $me['default_rate'];

        // Whatever this partner has negotiated. Without it the catalogue
        // shows them the generic rate and the ledger pays them a different
        // one, which is the worst of both.
        $overrides = Commission::overridesFor((int) $me['id']);

        // One query per kind for the pictures, rather than one per row.
        $serviceImages = \App\Services\ImageLibrary::primaryFor(
            'service',
            array_map(static fn(array $r): int => (int) $r['id'], $services)
        );
        $productImages = \App\Services\ImageLibrary::primaryFor(
            'product',
            array_map(static fn(array $r): int => (int) $r['id'], $products)
        );

        foreach ($services as $i => $row) {
            $rate = Commission::rateFor(
                $overrides,
                'service',
                (int) $row['id'],
                $row['commission_rate'] === null ? null : (float) $row['commission_rate'],
                $default
            );

            $services[$i]['kind']           = 'service';
            $services[$i]['effective_rate'] = $rate;
            $services[$i]['your_cut']       = (float) $row['price'] > 0.009
                ? round((float) $row['price'] * $rate / 100, 2)
                : null;
            $services[$i]['image']          = $serviceImages[(int) $row['id']] ?? null;
        }

        foreach ($products as $i => $row) {
            // 'inventory' here, not 'product': that is the word a line on an
            // invoice uses, and it is what a rate is stored against. The
            // display word stops at the view.
            $rate = Commission::rateFor($overrides, 'inventory', (int) $row['id'], null, $default);

            $products[$i]['kind']           = 'product';
            $products[$i]['pricing_type']   = 'fixed';
            $products[$i]['lead_time']      = null;
            $products[$i]['effective_rate'] = $rate;
            $products[$i]['your_cut']       = (float) $row['price'] > 0.009
                ? round((float) $row['price'] * $rate / 100, 2)
                : null;
            $products[$i]['image']          = $productImages[(int) $row['id']] ?? null;
        }

        $this->view('partner/services', [
            'title'    => 'What we do',
            'me'       => $me,
            'services' => $services,
            'products' => $products,
            'search'   => $search,
            'company'  => Settings::company(),
        ], 'partner');
    }

    // -- A month, in a form they can invoice against -------------------------

    /**
     * One month's commission, set out as a statement.
     *
     * The terms a partner agrees to say they are paid monthly against an
     * invoice from them. That means they need something to invoice
     * against — a month, an amount, and the entries behind it, on a page
     * they can print or save. Without it the figure in the portal is
     * something they have to transcribe and we have to take on trust.
     *
     * Scoped to their own id like everything else here: the period comes
     * from the URL, the partner never does.
     */
    public function statement(Request $request): void
    {
        $me     = $this->me();
        $period = (string) $request->param('period');

        if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $period) !== 1) {
            throw new HttpException(404, 'That is not a month.');
        }

        $rows = Database::all(
            "SELECT cm.amount, cm.rate, cm.base_amount, cm.status,
                    cm.created_at, cm.paid_at, cm.payout_ref,
                    d.doc_number, d.issue_date,
                    c.name AS client_name
               FROM commissions cm
               JOIN documents d ON d.id = cm.document_id
               JOIN clients   c ON c.id = cm.client_id
              WHERE cm.partner_id = :p AND cm.period = :period AND cm.status <> 'void'
           ORDER BY cm.id",
            ['p' => $me['id'], 'period' => $period]
        );

        if (!$rows) {
            throw new HttpException(404, 'Nothing was earned in that month.');
        }

        $total = 0.0;
        $paid  = 0.0;

        foreach ($rows as $row) {
            $total += (float) $row['amount'];

            if ($row['status'] === 'paid') {
                $paid += (float) $row['amount'];
            }
        }

        $this->view('partner/statement', [
            'title'   => 'Commission for ' . $period,
            'me'      => $me,
            'period'  => $period,
            'rows'    => $rows,
            'total'   => round($total, 2),
            'paid'    => round($paid, 2),
            'due'     => round($total - $paid, 2),
            'company' => Settings::company(),
        ], 'print');
    }

    // -- What is coming ------------------------------------------------------

    /**
     * Their customers' recurring services, and when each falls due.
     *
     * A renewal is future commission with a date on it. Staff can already
     * see this on the partner's page; the partner could not, which made
     * the most useful thing about a recurring customer invisible to the
     * person who introduced them.
     */
    public function upcoming(Request $request): void
    {
        $me = $this->me();

        $rows = Database::all(
            "SELECT sub.id, sub.name, sub.amount, sub.billing_cycle, sub.status,
                    sub.next_renewal_date,
                    c.name AS client_name,
                    sv.commission_rate
               FROM subscriptions sub
               JOIN clients  c  ON c.id = sub.client_id
          LEFT JOIN services sv ON sv.id = sub.service_id
              WHERE c.partner_id = :p AND sub.status = 'active'
                AND sub.next_renewal_date IS NOT NULL
           ORDER BY sub.next_renewal_date",
            ['p' => $me['id']]
        );

        $default  = (float) $me['default_rate'];
        $today    = strtotime(date('Y-m-d'));
        $expected = 0.0;

        foreach ($rows as $i => $row) {
            $rate = $row['commission_rate'] === null ? $default : (float) $row['commission_rate'];
            $cut  = round((float) $row['amount'] * $rate / 100, 2);

            $rows[$i]['effective_rate'] = $rate;
            $rows[$i]['expected']       = $cut;
            $rows[$i]['days_away']      = (int) floor(
                (strtotime((string) $row['next_renewal_date']) - $today) / 86400
            );

            // Only what falls inside the next year, so one very distant
            // renewal does not read as money arriving shortly.
            if ($rows[$i]['days_away'] <= 365) {
                $expected += $cut;
            }
        }

        $this->view('partner/upcoming', [
            'title'    => 'What is coming',
            'me'       => $me,
            'rows'     => $rows,
            'expected' => round($expected, 2),
            'company'  => Settings::company(),
        ], 'partner');
    }

    // -- Their own details ---------------------------------------------------

    /**
     * The partner's own account.
     *
     * This exists because the commission run flags a partner with no KRA
     * PIN as unpayable, and until now the only person who could supply one
     * was a member of staff typing it in off a phone call. The person who
     * actually knows it had no way to say so.
     */
    public function account(Request $request): void
    {
        $me = $this->me();

        $this->view('partner/account', [
            'title'   => 'Your details',
            'me'      => $me,
            'company' => Settings::company(),
        ], 'partner');
    }

    public function updateAccount(Request $request): void
    {
        $me = $this->me();

        $v = new Validator($request->all());
        $v->require('first_name', 'First name')
          ->maxLen('first_name', 60, 'First name')
          ->maxLen('middle_name', 60, 'Second name')
          ->require('last_name', 'Third name')
          ->maxLen('last_name', 60, 'Third name')
          ->maxLen('occupation', 120, 'What you do')
          ->maxLen('office_location', 200, 'Where you work from')
          ->maxLen('company', 180, 'Your business')
          ->phone('phone', 'Phone number', true)
          ->maxLen('kra_pin', 30, 'KRA PIN');

        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Session::flashInput($request->all());
            Response::to('/partners/account');
        }

        // Deliberately not the email address, the rate, or where their
        // money goes. The address is what they sign in with and what an
        // approval was sent to; the rate is what we agreed to pay; and the
        // payment details are finance's to set, so that somebody who talked
        // their way into this account cannot redirect the money.
        //
        // The display name is written from the three parts rather than
        // taken as its own field, so the name we pay against cannot drift
        // from the one that has to match their ID.
        Database::update('partners', [
            'first_name'      => trim((string) $request->input('first_name')),
            'middle_name'     => trim((string) $request->input('middle_name')) ?: null,
            'last_name'       => trim((string) $request->input('last_name')),
            'name'            => full_name(
                (string) $request->input('first_name'),
                (string) $request->input('middle_name'),
                (string) $request->input('last_name')
            ),
            'company'         => trim((string) $request->input('company')) ?: null,
            'occupation'      => trim((string) $request->input('occupation')) ?: null,
            'office_location' => trim((string) $request->input('office_location')) ?: null,
            'phone'           => trim((string) $request->input('phone')),
            'kra_pin'         => strtoupper(trim((string) $request->input('kra_pin'))) ?: null,
        ], ['id' => $me['id']]);

        ActivityLog::record(
            'partner_self_updated',
            'partner',
            (int) $me['id'],
            $me['name'] . ' updated their own details'
        );

        Session::flash('success', 'Saved.');
        Response::to('/partners/account');
    }

    public function changePassword(Request $request): void
    {
        $me = $this->me();

        $current = (string) $request->input('current_password');
        $new     = (string) $request->input('new_password');
        $confirm = (string) $request->input('new_password_confirm');

        if (empty($me['password_hash']) || !password_verify($current, (string) $me['password_hash'])) {
            Session::flashErrors(['current_password' => 'That is not your current password.']);
            Response::to('/partners/account');
        }

        if (strlen($new) < 8) {
            Session::flashErrors(['new_password' => 'Use at least 8 characters.']);
            Response::to('/partners/account');
        }

        if ($new !== $confirm) {
            Session::flashErrors(['new_password_confirm' => 'The two passwords are not the same.']);
            Response::to('/partners/account');
        }

        if ($new === $current) {
            Session::flashErrors(['new_password' => 'The new password must be different from the old one.']);
            Response::to('/partners/account');
        }

        Database::update('partners', [
            'password_hash'   => password_hash($new, PASSWORD_DEFAULT),
            'failed_attempts' => 0,
            'locked_until'    => null,
        ], ['id' => $me['id']]);

        // A changed password should end every other session, which a fresh
        // id does: anything holding the old one is no longer signed in.
        \App\Core\Session::regenerate();

        ActivityLog::record(
            'partner_password_changed',
            'partner',
            (int) $me['id'],
            $me['name'] . ' changed their own password'
        );

        Session::flash('success', 'Your password has been changed.');
        Response::to('/partners/account');
    }

    // -- Introducing somebody ------------------------------------------------

    public function showRefer(Request $request): void
    {
        $me = $this->me();

        $mine = Database::all(
            "SELECT id, lead_number, name, company, stage, created_at, converted_client_id
               FROM leads
              WHERE partner_id = :p
           ORDER BY id DESC
              LIMIT 20",
            ['p' => $me['id']]
        );

        $this->view('partner/refer', [
            'title'   => 'Introduce a customer',
            'me'      => $me,
            'mine'    => $mine,
            'company' => Settings::company(),
        ], 'partner');
    }

    public function refer(Request $request): void
    {
        $me = $this->me();

        $v = new Validator($request->all());
        $v->require('name', 'Their name')
          ->maxLen('name', 180, 'Their name')
          ->maxLen('company', 180, 'Their business')
          ->email('email', 'Email address')
          ->phone('phone', 'Phone number')
          ->require('requirement', 'What they need');

        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Session::flashInput($request->all());
            Response::to('/partners/refer');
        }

        $leadId = Database::insert('leads', [
            'lead_number'  => \App\Core\Numbering::next('lead'),
            'name'         => trim((string) $request->input('name')),
            'company'      => trim((string) $request->input('company')) ?: null,
            'email'        => trim((string) $request->input('email')) ?: null,
            'phone'        => trim((string) $request->input('phone')) ?: null,
            'source'       => 'referral',
            'partner_id'   => (int) $me['id'],
            'requirement'  => trim((string) $request->input('requirement')),
            'stage'        => 'new',
        ]);

        ActivityLog::record(
            'partner_referral',
            'lead',
            $leadId,
            $me['name'] . ' introduced ' . trim((string) $request->input('name'))
        );

        // users has no 'status' column — it has is_active — so this threw
        // on every referral, after the lead had already been written: the
        // partner saw an error page having done nothing wrong, and nobody
        // was told an introduction had come in.
        //
        $this->tellStaff(
            'A partner has introduced somebody',
            $me['name'] . ' introduced ' . trim((string) $request->input('name')) . '.',
            '/leads/' . $leadId,
            'lead',
            $leadId,
            'partner_referral'
        );

        Session::flash('success', 'Thank you. We have it, and we will follow it up.');
        Response::to('/partners/refer');
    }

    /**
     * Tell whoever handles partner work that something has come in.
     *
     * One place, because the query behind it was wrong in two: it read
     * users.status, and users has is_active instead, so it threw after the
     * row had already been written. The partner saw an error page having
     * done nothing wrong, and nobody was told at all.
     *
     * Both places a role can live are checked, because Auth counts the
     * primary role even when the join table has missed it, and somebody
     * who is only a manager in one of them still handles this.
     */
    private function tellStaff(
        string $title,
        string $body,
        string $link,
        string $entityType,
        int $entityId,
        string $event = 'partner_client'
    ): void {
        $staff = Database::all(
            "SELECT DISTINCT u.id
               FROM users u
          LEFT JOIN user_roles ur ON ur.user_id = u.id
              WHERE u.is_active = 1
                AND (u.role IN ('admin','manager','sales')
                     OR ur.role IN ('admin','manager','sales'))"
        );

        if (!$staff) {
            return;
        }

        StaffNotifier::notify(
            array_map(static fn(array $u): int => (int) $u['id'], $staff),
            [
                'event'       => $event,
                'title'       => $title,
                'body'        => $body,
                'link'        => $link,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
            ],
            ['email' => true, 'sms' => false]
        );
    }
}
