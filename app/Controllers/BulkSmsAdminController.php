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
use App\Core\Settings;
use App\Services\BulkSms\Accounts;
use App\Services\BulkSms\Onfon;
use App\Services\BulkSms\Purchases;
use App\Services\BulkSms\Reports;
use App\Services\BulkSms\Wallet;

/**
 * The office side of Bulk SMS: the house balance, every customer and
 * partner account, what they have bought, and the gateway itself.
 *
 * The old platform's admin area, rebuilt. What a customer sends is in
 * BulkSmsTrafficController; this is the money and the accounts.
 */
class BulkSmsAdminController extends Controller
{
    // =================================================================
    // Overview
    // =================================================================

    public function index(Request $request): void
    {
        $this->authorize('bulksms.view');

        $house = Accounts::house();
        [$from, $to] = Reports::range(null, null, 30);

        $this->view('bulksms/admin/index', [
            'title'     => 'SMS platform',
            'house'     => $house,
            'today'     => Reports::totals(null, date('Y-m-d'), date('Y-m-d')),
            'month'     => Reports::totals(null, $from, $to),
            'daily'     => Reports::daily(null, date('Y-m-d', strtotime('-13 days')), date('Y-m-d')),
            'from'      => $from,
            'to'        => $to,
            'held'      => (float) Database::scalar("SELECT COALESCE(SUM(sms_units),0) FROM bulk_accounts WHERE owner_type <> 'house'"),
            'accounts'  => [
                'client'  => (int) Database::scalar("SELECT COUNT(*) FROM bulk_accounts WHERE owner_type = 'client'"),
                'partner' => (int) Database::scalar("SELECT COUNT(*) FROM bulk_accounts WHERE owner_type = 'partner'"),
            ],
            'waiting'   => [
                'senders'   => (int) Database::scalar("SELECT COUNT(*) FROM bulk_sender_ids WHERE status = 'pending'"),
                'purchases' => (int) Database::scalar(
                    "SELECT COUNT(*) FROM bulk_purchases WHERE status = 'pending' AND seller_account_id = :h",
                    ['h' => $house['id']]),
            ],
            'sending'   => Database::all(
                'SELECT c.*, ' . Accounts::OWNER_NAME_SQL . ' AS owner_name
                   FROM bulk_campaigns c JOIN bulk_accounts a ON a.id = c.account_id
                  WHERE c.status IN (\'queued\',\'sending\',\'scheduled\')
                  ORDER BY FIELD(c.status, \'sending\',\'queued\',\'scheduled\'), c.scheduled_at, c.id LIMIT 10'
            ),
            'top'       => Database::all(
                'SELECT a.id, a.owner_type, ' . Accounts::OWNER_NAME_SQL . " AS owner_name,
                        COUNT(m.id) AS messages, COALESCE(SUM(m.units_charged),0) AS units
                   FROM bulk_messages m JOIN bulk_accounts a ON a.id = m.account_id
                  WHERE m.created_at >= :from
                  GROUP BY a.id ORDER BY units DESC LIMIT 8",
                ['from' => $from . ' 00:00:00']
            ),
            'configured' => Onfon::isConfigured(),
        ]);
    }

    /** Set the house balance to what Onfon says we hold. */
    public function sync(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $live = Onfon::balance();

        if ($live === null) {
            Session::error('Could not reach Onfon for the balance. Check the gateway settings.');
            Response::to('/bulk-sms');
        }

        $before = (float) Accounts::house()['sms_units'];
        Wallet::syncHouse($live, ['actor_type' => 'staff', 'actor_id' => Auth::id()]);

        ActivityLog::record('bulksms_sync', 'bulk_account', (int) Accounts::house()['id'],
            sprintf('House balance synced to Onfon: %s → %s', number_format($before, 2), number_format($live, 2)));

        Session::success('The house balance now matches Onfon: ' . number_format($live, 2) . ' units.');
        Response::to('/bulk-sms');
    }

    // =================================================================
    // Accounts
    // =================================================================

    public function accounts(Request $request): void
    {
        $this->authorize('bulksms.view');

        $type = (string) $request->query('type', '');
        $q    = trim((string) $request->query('q', ''));

        $where  = ["a.owner_type <> 'house'"];
        $params = [];

        if (in_array($type, ['client', 'partner'], true)) {
            $where[] = 'a.owner_type = :t';
            $params['t'] = $type;
        }

        if ($q !== '') {
            $where[] = '(' . Accounts::OWNER_NAME_SQL . ' LIKE :q OR a.api_client_id LIKE :q2)';
            $params['q']  = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }

        $sqlWhere = implode(' AND ', $where);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM bulk_accounts a WHERE {$sqlWhere}", $params);
        $pager = $this->paginate($total, 30);

        $rows = Database::all(
            'SELECT a.*, ' . Accounts::OWNER_NAME_SQL . " AS owner_name,
                    p.owner_type AS parent_type,
                    (SELECT COALESCE(NULLIF(pp.company,''), pp.name) FROM partners pp WHERE p.owner_type = 'partner' AND pp.id = p.owner_id) AS parent_name,
                    (SELECT MAX(m.created_at) FROM bulk_messages m WHERE m.account_id = a.id) AS last_sent
               FROM bulk_accounts a
               LEFT JOIN bulk_accounts p ON p.id = a.parent_id
              WHERE {$sqlWhere}
              ORDER BY a.sms_units DESC, a.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $this->view('bulksms/admin/accounts', [
            'title' => 'SMS accounts',
            'rows'  => $rows,
            'pager' => $pager,
            'type'  => $type,
            'q'     => $q,
        ]);
    }

    /**
     * Open an account for a client or partner who does not have one.
     *
     * Most accounts open themselves the first time the customer or partner
     * uses SMS. This is for the office setting one up in advance — to
     * give free units, or to register a sender ID before they sign in.
     */
    public function open(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $type = (string) $request->input('owner_type');
        $id   = $request->int('owner_id');

        try {
            $account = match ($type) {
                'client'  => Accounts::forClient($id),
                'partner' => Accounts::forPartner($id),
                default   => throw new HttpException(422, 'Choose a client or a partner.'),
            };
        } catch (\RuntimeException $e) {
            Session::error($e->getMessage());
            Response::back('/bulk-sms/accounts');
        }

        Response::to('/bulk-sms/accounts/' . $account['id']);
    }

    public function show(Request $request): void
    {
        $this->authorize('bulksms.view');

        $account = $this->account($request->paramInt('id'));
        $who     = Accounts::describe($account);
        $parent  = $account['parent_id'] ? Accounts::find((int) $account['parent_id']) : null;
        [$from, $to] = Reports::range(null, null, 30);

        $this->view('bulksms/admin/account', [
            'title'     => $who['name'] . ' · SMS',
            'account'   => $account,
            'who'       => $who,
            'parent'    => $parent,
            'parentWho' => $parent ? Accounts::describe($parent) : null,
            'price'     => Accounts::unitPrice($account),
            'totals'    => Reports::totals([(int) $account['id']], $from, $to),
            'children'  => $account['owner_type'] === 'partner'
                ? Database::all(
                    'SELECT a.*, ' . Accounts::OWNER_NAME_SQL . ' AS owner_name FROM bulk_accounts a
                      WHERE a.parent_id = :p ORDER BY a.sms_units DESC', ['p' => $account['id']])
                : [],
            'senders'   => Database::all(
                'SELECT * FROM bulk_sender_ids WHERE account_id = :a ORDER BY status = \'approved\' DESC, sender_id',
                ['a' => $account['id']]),
            'ledger'    => Database::all(
                'SELECT l.*, u.name AS staff_name FROM bulk_ledger l
                   LEFT JOIN users u ON l.actor_type = \'staff\' AND u.id = l.actor_id
                  WHERE l.account_id = :a ORDER BY l.id DESC LIMIT 25',
                ['a' => $account['id']]),
            'purchases' => Database::all(
                'SELECT * FROM bulk_purchases WHERE account_id = :a ORDER BY id DESC LIMIT 10',
                ['a' => $account['id']]),
            'campaigns' => Database::all(
                'SELECT * FROM bulk_campaigns WHERE account_id = :a ORDER BY id DESC LIMIT 10',
                ['a' => $account['id']]),
            'partners'  => $account['owner_type'] === 'client'
                ? Database::all(
                    "SELECT a.id, COALESCE(NULLIF(p.company,''), p.name) AS name FROM bulk_accounts a
                       JOIN partners p ON p.id = a.owner_id WHERE a.owner_type = 'partner' ORDER BY name")
                : [],
            'newKey'    => Session::get('bulksms_new_key_' . $account['id']),
        ]);

        // Shown once, then gone.
        Session::forget('bulksms_new_key_' . $account['id']);
    }

    /**
     * Give units to an account, or take them back.
     *
     * Units always come from somewhere and go somewhere: giving moves them
     * out of the house, taking back returns them to the house. The office
     * cannot conjure units into an account any more than a customer can.
     */
    public function units(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $account = $this->account($request->paramInt('id'));
        $units   = round($request->decimal('units'), 4);
        $action  = $request->input('action') === 'take' ? 'take' : 'give';
        $note    = trim((string) $request->input('note', '')) ?: ($action === 'give' ? 'Given by the office' : 'Taken back by the office');
        $house   = Accounts::house();

        if ($units <= 0) {
            Session::error('Enter how many units.');
            Response::to('/bulk-sms/accounts/' . $account['id']);
        }

        $meta = ['actor_type' => 'staff', 'actor_id' => Auth::id(), 'note' => mb_substr($note, 0, 255)];

        $ok = $action === 'give'
            ? Wallet::move((int) $house['id'], (int) $account['id'], $units, 'transfer_out', 'transfer_in', $meta)
            : Wallet::move((int) $account['id'], (int) $house['id'], $units, 'transfer_out', 'transfer_in', $meta);

        if (!$ok) {
            Session::error($action === 'give'
                ? 'The house only holds ' . number_format((float) Accounts::house()['sms_units'], 2) . ' units. Sync with Onfon or top up the gateway first.'
                : 'This account only holds ' . number_format(Wallet::balance((int) $account['id']), 2) . ' units.');
            Response::to('/bulk-sms/accounts/' . $account['id']);
        }

        ActivityLog::record('bulksms_units', 'bulk_account', (int) $account['id'],
            ($action === 'give' ? 'Gave ' : 'Took back ') . number_format($units, 2) . ' units — ' . $note);

        Session::success(($action === 'give' ? 'Gave ' : 'Took back ') . number_format($units, 2) . ' units.');
        Response::to('/bulk-sms/accounts/' . $account['id']);
    }

    /** Price, warning threshold, and — for a partner — their resale price. */
    public function pricing(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $account = $this->account($request->paramInt('id'));

        $num = static function (mixed $v): ?float {
            $v = trim((string) $v);
            return $v === '' ? null : max(0, round((float) $v, 4));
        };

        $data = [
            'unit_price'            => $num($request->input('unit_price')),
            'low_balance_threshold' => $num($request->input('low_balance_threshold')),
        ];

        if ($account['owner_type'] === 'partner') {
            $data['resale_unit_price'] = $num($request->input('resale_unit_price'));
        }

        Database::update('bulk_accounts', $data, ['id' => $account['id']]);

        ActivityLog::record('bulksms_pricing', 'bulk_account', (int) $account['id'], 'SMS pricing updated');
        Session::success('Saved.');
        Response::to('/bulk-sms/accounts/' . $account['id']);
    }

    public function status(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $account = $this->account($request->paramInt('id'));
        $new     = $account['status'] === 'active' ? 'suspended' : 'active';

        Database::update('bulk_accounts', ['status' => $new], ['id' => $account['id']]);

        ActivityLog::record('bulksms_status', 'bulk_account', (int) $account['id'], 'SMS account ' . $new);
        Session::success($new === 'suspended'
            ? 'Suspended. Nothing will send from this account, including the API, until it is reactivated.'
            : 'Reactivated.');
        Response::to('/bulk-sms/accounts/' . $account['id']);
    }

    /**
     * Move a client to a different supplier — the house, or a partner.
     *
     * Only the supplier of future purchases changes. Units the client
     * already holds are theirs, and purchases in flight complete with the
     * seller they were made with.
     */
    public function supplier(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $account = $this->account($request->paramInt('id'));

        if ($account['owner_type'] !== 'client') {
            throw new HttpException(422, 'Only a client account can change supplier.');
        }

        $parentId = $request->int('parent_id');
        $parent   = Accounts::find($parentId);

        if ($parent === null || !in_array($parent['owner_type'], ['house', 'partner'], true)) {
            Session::error('Choose the house or a partner.');
            Response::to('/bulk-sms/accounts/' . $account['id']);
        }

        Database::update('bulk_accounts', ['parent_id' => $parent['id']], ['id' => $account['id']]);

        ActivityLog::record('bulksms_supplier', 'bulk_account', (int) $account['id'],
            'Now buys SMS from ' . Accounts::describe($parent)['name']);
        Session::success('This client now buys units from ' . Accounts::describe($parent)['name'] . '.');
        Response::to('/bulk-sms/accounts/' . $account['id']);
    }

    public function apiKey(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $account = $this->account($request->paramInt('id'));
        $issued  = Accounts::issueApiKey((int) $account['id']);

        Session::put('bulksms_new_key_' . $account['id'], $issued);
        ActivityLog::record('bulksms_api_key', 'bulk_account', (int) $account['id'], 'API key issued');

        Session::success('A new API key has been issued. Copy it now — it will not be shown again.');
        Response::to('/bulk-sms/accounts/' . $account['id'] . '#api');
    }

    public function revokeKey(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $account = $this->account($request->paramInt('id'));
        Accounts::revokeApiKey((int) $account['id']);

        ActivityLog::record('bulksms_api_revoke', 'bulk_account', (int) $account['id'], 'API key revoked');
        Session::success('The API key is revoked. Anything using it will be refused from now on.');
        Response::to('/bulk-sms/accounts/' . $account['id'] . '#api');
    }

    // =================================================================
    // Purchases
    // =================================================================

    public function purchases(Request $request): void
    {
        $this->authorize('bulksms.view');

        $status = (string) $request->query('status', '');
        $where  = ['1 = 1'];
        $params = [];

        if (in_array($status, ['pending', 'completed', 'failed', 'refunded'], true)) {
            $where[] = 'p.status = :s';
            $params['s'] = $status;
        }

        $sqlWhere = implode(' AND ', $where);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM bulk_purchases p WHERE {$sqlWhere}", $params);
        $pager = $this->paginate($total, 30);

        $rows = Database::all(
            'SELECT p.*, ' . Accounts::OWNER_NAME_SQL . " AS buyer_name, a.owner_type AS buyer_type,
                    s.owner_type AS seller_type,
                    (SELECT COALESCE(NULLIF(pp.company,''), pp.name) FROM partners pp
                      WHERE s.owner_type = 'partner' AND pp.id = s.owner_id) AS seller_name
               FROM bulk_purchases p
               JOIN bulk_accounts a ON a.id = p.account_id
               JOIN bulk_accounts s ON s.id = p.seller_account_id
              WHERE {$sqlWhere}
              ORDER BY p.status = 'pending' DESC, p.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $house = Accounts::house();

        $this->view('bulksms/admin/purchases', [
            'title'   => 'SMS purchases',
            'rows'    => $rows,
            'pager'   => $pager,
            'status'  => $status,
            'house'   => $house,
            'revenue' => (float) Database::scalar(
                "SELECT COALESCE(SUM(amount),0) FROM bulk_purchases
                  WHERE status = 'completed' AND seller_account_id = :h AND completed_at >= :m",
                ['h' => $house['id'], 'm' => date('Y-m-01 00:00:00')]),
            'plans'   => Database::all(
                'SELECT * FROM bulk_plans WHERE owner_account_id = :h AND is_active = 1 ORDER BY sort_order, units',
                ['h' => $house['id']]),
            'accountsList' => Database::all(
                'SELECT a.id, a.owner_type, ' . Accounts::OWNER_NAME_SQL . " AS owner_name
                   FROM bulk_accounts a WHERE a.owner_type <> 'house' AND a.parent_id = :h
                  ORDER BY owner_name", ['h' => $house['id']]),
        ]);
    }

    /**
     * Record a sale the office took: a bank transfer, cash at the counter,
     * or free units. Completed on the spot unless told otherwise.
     */
    public function recordPurchase(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $accountId = $request->int('account_id');
        $account   = Accounts::find($accountId);

        if ($account === null || $account['owner_type'] === 'house') {
            Session::error('Choose the account the units are for.');
            Response::to('/bulk-sms/purchases');
        }

        $method = (string) $request->input('method', 'bank');

        if (!in_array($method, ['bank', 'cash', 'manual_mpesa', 'complimentary'], true)) {
            $method = 'bank';
        }

        $result = Purchases::create($accountId, [
            'plan_id'         => $request->int('plan_id') ?: null,
            'units'           => $request->decimal('units'),
            'amount'          => $request->decimal('amount') ?: null,
            'method'          => $method,
            'transaction_ref' => (string) $request->input('transaction_ref', ''),
            'actor_type'      => 'staff',
            'actor_id'        => Auth::id(),
        ]);

        if (!$result['ok']) {
            Session::error($result['error']);
            Response::to('/bulk-sms/purchases');
        }

        $done = Purchases::complete($result['id'], ['type' => 'staff', 'id' => Auth::id()]);

        ActivityLog::record('bulksms_purchase', 'bulk_purchase', $result['id'], sprintf(
            'Recorded %s units for %s (%s)', number_format($result['units'], 2),
            money($result['amount']), Purchases::METHODS[$method]));

        $done['ok']
            ? Session::success(number_format($result['units'], 2) . ' units added.')
            : Session::warning('Recorded, but not handed over yet: ' . $done['error']);

        Response::to('/bulk-sms/purchases');
    }

    public function completePurchase(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $id = $request->paramInt('id');
        $p  = Database::first('SELECT * FROM bulk_purchases WHERE id = :id', ['id' => $id])
            ?? throw new HttpException(404, 'Purchase not found.');

        // The office completes sales the house made. A partner's sale to
        // their own client is the partner's to approve — they are the one
        // who was paid.
        if ((int) $p['seller_account_id'] !== (int) Accounts::house()['id']) {
            Session::error('That sale was made by a partner, who approves it from their portal.');
            Response::to('/bulk-sms/purchases');
        }

        $result = Purchases::complete($id, ['type' => 'staff', 'id' => Auth::id()],
            trim((string) $request->input('transaction_ref', '')) ?: null);

        ActivityLog::record('bulksms_purchase_complete', 'bulk_purchase', $id, 'Purchase completed by hand');

        $result['ok']
            ? Session::success('Completed. The units are in the account.')
            : Session::error($result['error'] ?? 'Could not complete it.');

        Response::to('/bulk-sms/purchases');
    }

    public function failPurchase(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $id = $request->paramInt('id');
        $reason = trim((string) $request->input('reason', '')) ?: 'Payment not received';

        Purchases::fail($id, $reason, ['type' => 'staff', 'id' => Auth::id()])
            ? Session::success('Marked as not paid.')
            : Session::error('Only a pending purchase can be refused.');

        ActivityLog::record('bulksms_purchase_fail', 'bulk_purchase', $id, $reason);
        Response::to('/bulk-sms/purchases');
    }

    // =================================================================
    // Plans
    // =================================================================

    public function plans(Request $request): void
    {
        $this->authorize('bulksms.view');

        $this->view('bulksms/admin/plans', [
            'title' => 'SMS price plans',
            'plans' => Database::all(
                'SELECT p.*, (SELECT COUNT(*) FROM bulk_purchases b WHERE b.plan_id = p.id AND b.status = \'completed\') AS sold
                   FROM bulk_plans p WHERE p.owner_account_id = :h ORDER BY p.sort_order, p.units',
                ['h' => Accounts::house()['id']]),
            'unitPrice' => (float) Settings::get('bulk_sms_unit_price', '1.00'),
        ]);
    }

    public function savePlan(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $id    = $request->paramInt('id');
        $name  = trim((string) $request->input('name', ''));
        $units = $request->int('units');
        $price = round($request->decimal('price'), 2);

        if ($name === '' || $units <= 0 || $price <= 0) {
            Session::error('A plan needs a name, a number of units and a price.');
            Response::to('/bulk-sms/plans');
        }

        $data = [
            'name'       => mb_substr($name, 0, 80),
            'units'      => $units,
            'price'      => $price,
            'is_popular' => $request->bool('is_popular') ? 1 : 0,
            'sort_order' => $request->int('sort_order'),
        ];

        $house = (int) Accounts::house()['id'];

        if ($id > 0) {
            Database::run(
                'UPDATE bulk_plans SET name = :n, units = :u, price = :p, is_popular = :pop, sort_order = :s
                  WHERE id = :id AND owner_account_id = :h',
                ['n' => $data['name'], 'u' => $units, 'p' => $price, 'pop' => $data['is_popular'],
                 's' => $data['sort_order'], 'id' => $id, 'h' => $house]
            );
        } else {
            $id = Database::insert('bulk_plans', $data + ['owner_account_id' => $house]);
        }

        ActivityLog::record('bulksms_plan', 'bulk_plan', $id, 'Plan saved: ' . $name);
        Session::success('Plan saved.');
        Response::to('/bulk-sms/plans');
    }

    public function togglePlan(Request $request): void
    {
        $this->authorize('bulksms.manage');

        Database::run(
            'UPDATE bulk_plans SET is_active = 1 - is_active WHERE id = :id AND owner_account_id = :h',
            ['id' => $request->paramInt('id'), 'h' => Accounts::house()['id']]
        );

        Session::success('Updated.');
        Response::to('/bulk-sms/plans');
    }

    public function deletePlan(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $id = $request->paramInt('id');

        // A plan somebody has bought is part of their purchase history;
        // it is switched off rather than removed.
        $used = (int) Database::scalar('SELECT COUNT(*) FROM bulk_purchases WHERE plan_id = :id', ['id' => $id]);

        if ($used > 0) {
            Database::run('UPDATE bulk_plans SET is_active = 0 WHERE id = :id', ['id' => $id]);
            Session::info('That plan has been bought before, so it has been switched off rather than deleted.');
        } else {
            Database::run('DELETE FROM bulk_plans WHERE id = :id AND owner_account_id = :h',
                ['id' => $id, 'h' => Accounts::house()['id']]);
            Session::success('Plan deleted.');
        }

        Response::to('/bulk-sms/plans');
    }

    // =================================================================
    // Gateway settings
    // =================================================================

    public function settings(Request $request): void
    {
        $this->authorize('bulksms.settings');

        $this->view('bulksms/admin/settings', [
            'title'      => 'SMS gateway',
            'values'     => [
                'onfon_client_id'      => Settings::get('onfon_client_id', ''),
                'onfon_batch_delay_ms' => Settings::get('onfon_batch_delay_ms', '100'),
                'onfon_pinned_ips'     => Settings::get('onfon_pinned_ips', Onfon::DEFAULT_PINNED),
                'bulk_sms_unit_price'  => Settings::get('bulk_sms_unit_price', '1.00'),
                'bulk_sms_low_balance' => Settings::get('bulk_sms_low_balance', '0'),
                'bulk_sms_max_workers' => Settings::get('bulk_sms_max_workers', '5'),
                'bulk_sms_dlr_token'   => Settings::get('bulk_sms_dlr_token', ''),
                'bulk_sms_enabled'     => Settings::bool('bulk_sms_enabled', true),
                'bulk_sms_cors_origins' => Settings::get('bulk_sms_cors_origins', ''),
            ],
            'mpesa'        => \App\Services\BulkSms\Topup::readiness(),
            'mpesaOn'      => Settings::bool('bulk_sms_mpesa', true),
            'hasApiKey'    => Settings::get('onfon_api_key', '') !== '',
            'hasAccessKey' => Settings::get('onfon_access_key', '') !== '',
            'dlrUrl'       => \App\Services\Notifier::absoluteUrl('/webhooks/sms-dlr'),
            'check'        => Session::get('bulksms_check'),
        ]);

        Session::forget('bulksms_check');
    }

    public function saveSettings(Request $request): void
    {
        $this->authorize('bulksms.settings');

        $pairs = [
            'onfon_client_id'      => trim((string) $request->input('onfon_client_id', '')),
            'onfon_batch_delay_ms' => (string) max(0, min(5000, $request->int('onfon_batch_delay_ms'))),
            'onfon_pinned_ips'     => implode(',', array_filter(array_map('trim',
                explode(',', (string) $request->input('onfon_pinned_ips', ''))),
                static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false)),
            'bulk_sms_unit_price'  => (string) max(0.01, round($request->decimal('bulk_sms_unit_price'), 4)),
            'bulk_sms_low_balance' => (string) max(0, $request->int('bulk_sms_low_balance')),
            'bulk_sms_max_workers' => (string) max(1, min(50, $request->int('bulk_sms_max_workers'))),
            'bulk_sms_dlr_token'   => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('bulk_sms_dlr_token', '')),
            'bulk_sms_enabled'     => $request->bool('bulk_sms_enabled') ? '1' : '0',
            'bulk_sms_mpesa'       => $request->bool('bulk_sms_mpesa') ? '1' : '0',
            // Which websites may call the API from a browser. Empty
            // means any: the key is what authorises a call, not the
            // page it came from.
            'bulk_sms_cors_origins' => implode(',', array_filter(array_map('trim',
                explode(',', (string) $request->input('bulk_sms_cors_origins', ''))),
                static fn(string $o): bool => filter_var($o, FILTER_VALIDATE_URL) !== false)),
        ];

        // Secrets are only replaced when something is typed: an empty box
        // means "leave it as it is", because the saved value is never sent
        // back to the browser to be re-submitted.
        foreach (['onfon_api_key', 'onfon_access_key'] as $secret) {
            $value = trim((string) $request->input($secret, ''));

            if ($value !== '') {
                $pairs[$secret] = $value;
            }
        }

        Settings::setMany($pairs);

        ActivityLog::record('bulksms_settings', null, null, 'SMS gateway settings saved');
        Session::success('Saved.');
        Response::to('/bulk-sms/settings');
    }

    /** Ask Onfon for the balance: proves the keys and the connection. */
    public function check(Request $request): void
    {
        $this->authorize('bulksms.settings');

        $started = microtime(true);
        $balance = Onfon::balance();
        $ms = (int) round(1000 * (microtime(true) - $started));

        Session::put('bulksms_check', $balance === null
            ? ['ok' => false, 'message' => 'Onfon did not answer with a balance after ' . $ms . ' ms. Check the client id and API key, and that this server can reach api.onfonmedia.co.ke.']
            : ['ok' => true, 'message' => 'Connected in ' . $ms . ' ms. Onfon says you hold ' . number_format($balance, 2) . ' units.']);

        Response::to('/bulk-sms/settings');
    }

    // =================================================================

    private function account(int $id): array
    {
        $account = Accounts::find($id);

        if ($account === null || $account['owner_type'] === 'house') {
            throw new HttpException(404, 'SMS account not found.');
        }

        return $account;
    }
}
