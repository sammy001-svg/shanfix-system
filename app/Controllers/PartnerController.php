<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Controller;
use App\Core\Database;
use App\Core\PartnerAuth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\Commission;
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

    // -- What we do, and what it pays ---------------------------------------

    public function services(Request $request): void
    {
        $me     = $this->me();
        $search = trim((string) $request->query('q', ''));

        $like   = '%' . $search . '%';
        $params = $search !== '' ? ['q' => $like, 'q2' => $like] : [];
        $where  = $search !== '' ? ' AND (name LIKE :q OR description LIKE :q2)' : '';

        $rows = Database::all(
            "SELECT id, code, name, description, pricing_type, price, unit_label,
                    lead_time, commission_rate
               FROM services
              WHERE is_active = 1" . $where . "
           ORDER BY name",
            $params
        );

        // What each one is worth to them, worked out here rather than in
        // the view: the fallback to their own rate is a rule, not a
        // display detail, and it is the same rule the ledger uses.
        $default = (float) $me['default_rate'];

        foreach ($rows as $i => $row) {
            $rate = $row['commission_rate'] === null ? $default : (float) $row['commission_rate'];

            $rows[$i]['effective_rate'] = $rate;
            $rows[$i]['your_cut']       = (float) $row['price'] > 0.009
                ? round((float) $row['price'] * $rate / 100, 2)
                : null;
        }

        $this->view('partner/services', [
            'title'   => 'What we do',
            'me'      => $me,
            'rows'    => $rows,
            'search'  => $search,
            'company' => Settings::company(),
        ], 'partner');
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
