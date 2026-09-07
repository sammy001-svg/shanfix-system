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

        $where  = $status !== '' ? ' WHERE p.status = :s' : '';
        $params = $status !== '' ? ['s' => $status] : [];

        $rows = Database::all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM clients c WHERE c.partner_id = p.id) AS customers,
                    (SELECT COALESCE(SUM(cm.amount), 0) FROM commissions cm
                      WHERE cm.partner_id = p.id AND cm.status = 'earned')     AS due,
                    (SELECT COALESCE(SUM(cm.amount), 0) FROM commissions cm
                      WHERE cm.partner_id = p.id AND cm.status = 'paid')       AS paid
               FROM partners p" . $where . "
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

        $this->view('partners/index', [
            'title'  => 'Partners',
            'rows'   => $rows,
            'status' => $status,
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

        $this->view('partners/show', [
            'title'       => $partner['name'],
            'partner'     => $partner,
            'summary'     => Commission::summaryFor((int) $partner['id']),
            'customers'   => $customers,
            'commissions' => $commissions,
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

        $due = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM commissions
              WHERE partner_id = :p AND status = 'earned'",
            ['p' => $partner['id']],
            0
        );

        if ($due <= 0.009) {
            Session::warning('There is nothing outstanding to pay.');
            Response::to('/partners-admin/' . $partner['id']);
        }

        $n = Database::run(
            "UPDATE commissions
                SET status = 'paid', paid_at = NOW(), payout_ref = :r
              WHERE partner_id = :p AND status = 'earned'",
            ['p' => $partner['id'], 'r' => $ref]
        )->rowCount();

        ActivityLog::record(
            'partner_paid',
            'partner',
            (int) $partner['id'],
            'Paid ' . money($due) . ' to ' . $partner['name'] . ' (' . $ref . ')'
        );

        Notifier::dispatch('partner_paid', [
            'entity_type'  => 'partner',
            'entity_id'    => (int) $partner['id'],
            'contact_name' => $partner['name'],
            'email'        => $partner['email'],
            'phone'        => $partner['phone'],
            'amount'       => money($due),
            'payment_ref'  => $ref,
        ], true);

        Notifier::processQueue(4);

        Session::success('Marked ' . money($due) . ' as paid across ' . $n . ' entries.');
        Response::to('/partners-admin/' . $partner['id']);
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
