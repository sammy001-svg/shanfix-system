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
        $v->require('name', 'Your name')
          ->maxLen('name', 140, 'Your name')
          ->maxLen('company', 180, 'Your business')
          ->phone('phone', 'Phone number', true)
          ->maxLen('kra_pin', 30, 'KRA PIN');

        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Session::flashInput($request->all());
            Response::to('/partners/account');
        }

        // Deliberately not the email address or the rate. The address is
        // what they sign in with and what an approval was sent to, and the
        // rate is what we agreed to pay — neither is theirs to change from
        // in here.
        Database::update('partners', [
            'name'    => trim((string) $request->input('name')),
            'company' => trim((string) $request->input('company')) ?: null,
            'phone'   => trim((string) $request->input('phone')),
            'kra_pin' => strtoupper(trim((string) $request->input('kra_pin'))) ?: null,
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

        $staff = Database::all(
            "SELECT id FROM users WHERE status = 'active' AND role IN ('admin','manager','sales')"
        );

        if ($staff) {
            StaffNotifier::notify(
                array_map(static fn(array $u): int => (int) $u['id'], $staff),
                [
                    'event'       => 'partner_referral',
                    'title'       => 'A partner has introduced somebody',
                    'body'        => $me['name'] . ' introduced '
                                   . trim((string) $request->input('name')) . '.',
                    'link'        => '/leads/' . $leadId,
                    'entity_type' => 'lead',
                    'entity_id'   => $leadId,
                ],
                ['email' => true, 'sms' => false]
            );
        }

        Session::flash('success', 'Thank you. We have it, and we will follow it up.');
        Response::to('/partners/refer');
    }
}
