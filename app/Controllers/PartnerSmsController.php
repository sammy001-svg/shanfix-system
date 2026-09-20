<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\PartnerAuth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\BulkSms\Accounts;
use App\Services\BulkSms\Purchases;
use App\Services\BulkSms\Reports;
use App\Services\BulkSms\Wallet;

/**
 * Bulk SMS in the partner portal.
 *
 * A partner is a reseller: they buy units from us in bulk and sell them
 * on to their own clients at their own price. Everything they do with
 * their own messages is SmsPortalBase, shared with the client portal;
 * what is here is the reselling — their clients, what they charge, the
 * payments they have to confirm, and moving units across.
 *
 * The line that matters: a partner may hand units to a client of theirs
 * and set what that client pays, but may not read a word of what that
 * client sends. Their business is the credit, not the correspondence —
 * and a client would be appalled to learn otherwise.
 */
class PartnerSmsController extends SmsPortalBase
{
    // =================================================================
    // What the shared pages need
    // =================================================================

    protected function account(): array
    {
        if (!Settings::bool('bulk_sms_enabled', true)) {
            throw new HttpException(404, 'Not found.');
        }

        $me = PartnerAuth::user();

        if (!$me) {
            Response::to('/partners/login');
        }

        return Accounts::forPartner((int) $me['id']) ?? throw new HttpException(404, 'No SMS account.');
    }

    protected function base(): string
    {
        return '/partners/sms';
    }

    protected function layout(): string
    {
        return 'partner';
    }

    protected function actorType(): string
    {
        return 'partner';
    }

    protected function actorId(): ?int
    {
        $me = PartnerAuth::user();

        return $me ? (int) $me['id'] : null;
    }

    protected function source(): string
    {
        return 'partner';
    }

    protected function defaultPhone(array $account): string
    {
        return (string) (Database::scalar(
            'SELECT phone FROM partners WHERE id = :id',
            ['id' => $account['owner_id']]
        ) ?? '');
    }

    // =================================================================
    // Reselling
    // =================================================================

    /** Their clients: who holds what, and who is waiting on them. */
    public function clients(Request $request): void
    {
        $account = $this->account();

        $this->render('reseller_clients', [
            'title'    => 'My SMS clients',
            'account'  => $account,
            'clients'  => Database::all(
                "SELECT a.*, c.name AS client_name, c.phone, c.email,
                        (SELECT COUNT(*) FROM bulk_messages m
                          WHERE m.account_id = a.id AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS sent_30,
                        (SELECT MAX(m.created_at) FROM bulk_messages m WHERE m.account_id = a.id) AS last_sent
                   FROM bulk_accounts a
                   JOIN clients c ON c.id = a.owner_id
                  WHERE a.parent_id = :p AND a.owner_type = 'client'
                  ORDER BY c.name",
                ['p' => $account['id']]),
            'waiting'  => $this->pendingSales((int) $account['id']),
            'price'    => Accounts::unitPrice($account),
            // Their own clients who have no SMS account yet, so they can
            // start one for them.
            'without'  => Database::all(
                "SELECT c.id, c.name FROM clients c
                  WHERE c.partner_id = :partner
                    AND NOT EXISTS (SELECT 1 FROM bulk_accounts a
                                     WHERE a.owner_type = 'client' AND a.owner_id = c.id)
                  ORDER BY c.name",
                ['partner' => $account['owner_id']]),
        ]);
    }

    /** Open an SMS account for one of their own clients. */
    public function openClient(Request $request): void
    {
        $account  = $this->account();
        $clientId = $request->int('client_id');

        $mine = Database::scalar(
            'SELECT 1 FROM clients WHERE id = :c AND partner_id = :p',
            ['c' => $clientId, 'p' => $account['owner_id']]
        );

        if (!$mine) {
            throw new HttpException(403, 'That is not one of your clients.');
        }

        $opened = Accounts::forClient($clientId);

        // forClient() sets the supplier from the client's partner link, so
        // this should already be theirs. Asserted rather than assumed:
        // getting it wrong would have their client buying from us.
        if ((int) $opened['parent_id'] !== (int) $account['id']) {
            Database::update('bulk_accounts', ['parent_id' => $account['id']], ['id' => $opened['id']]);
        }

        Session::success('SMS is open for that client. Give them units when they have paid you.');
        Response::to($this->base() . '/clients');
    }

    /** Move units from the partner's balance to one of their clients. */
    public function giveUnits(Request $request): void
    {
        $account = $this->account();
        $child   = $this->childAccount($request->paramInt('id'), (int) $account['id']);
        $units   = round($request->decimal('units'), 4);

        if ($units <= 0) {
            Session::error('Enter how many units to give.');
            Response::to($this->base() . '/clients');
        }

        $moved = Wallet::move(
            (int) $account['id'],
            (int) $child['id'],
            $units,
            'transfer_out',
            'transfer_in',
            [
                'actor_type' => 'partner',
                'actor_id'   => $this->actorId(),
                'note'       => mb_substr(trim((string) $request->input('note', '')) ?: 'Given by their reseller', 0, 255),
            ]
        );

        $moved
            ? Session::success('Sent ' . number_format($units, 2) . ' units across.')
            : Session::error('You only have ' . number_format((float) $account['sms_units'], 2) . ' units. Top up first.');

        Response::to($this->base() . '/clients');
    }

    /** What one of their clients pays per unit. */
    public function clientPrice(Request $request): void
    {
        $account = $this->account();
        $child   = $this->childAccount($request->paramInt('id'), (int) $account['id']);

        $price = trim((string) $request->input('unit_price', ''));

        Database::update('bulk_accounts',
            ['unit_price' => $price === '' ? null : max(0, round((float) $price, 4))],
            ['id' => $child['id']]);

        Session::success($price === ''
            ? 'That client now pays your standard price.'
            : 'Saved.');

        Response::to($this->base() . '/clients');
    }

    /** Stop or restart one of their clients' sending. */
    public function clientStatus(Request $request): void
    {
        $account = $this->account();
        $child   = $this->childAccount($request->paramInt('id'), (int) $account['id']);
        $new     = $child['status'] === 'active' ? 'suspended' : 'active';

        Database::update('bulk_accounts', ['status' => $new], ['id' => $child['id']]);

        Session::success($new === 'suspended'
            ? 'Paused. They cannot send until you turn it back on.'
            : 'They can send again.');

        Response::to($this->base() . '/clients');
    }

    /**
     * Their clients' payments, waiting to be confirmed.
     *
     * A client of theirs pays them directly — M-Pesa to their own till —
     * so only the partner can say whether the money arrived. Approving
     * moves units out of the partner's own balance.
     */
    public function sales(Request $request): void
    {
        $account = $this->account();

        $this->render('reseller_sales', [
            'title'   => 'Payments from my clients',
            'account' => $account,
            'waiting' => $this->pendingSales((int) $account['id']),
            'history' => Database::all(
                "SELECT p.*, c.name AS client_name
                   FROM bulk_purchases p
                   JOIN bulk_accounts a ON a.id = p.account_id
                   JOIN clients c ON c.id = a.owner_id
                  WHERE p.seller_account_id = :s AND p.status <> 'pending'
                  ORDER BY p.id DESC LIMIT 30",
                ['s' => $account['id']]),
            'earned'  => (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM bulk_purchases
                  WHERE seller_account_id = :s AND status = 'completed'
                    AND completed_at >= :month",
                ['s' => $account['id'], 'month' => date('Y-m-01 00:00:00')]),
        ]);
    }

    public function approveSale(Request $request): void
    {
        $account = $this->account();
        $sale    = $this->ownSale($request->paramInt('id'), (int) $account['id']);

        $result = Purchases::complete((int) $sale['id'], ['type' => 'partner', 'id' => $this->actorId()],
            trim((string) $request->input('transaction_ref', '')) ?: null);

        $result['ok']
            ? Session::success('Units sent to them.')
            : Session::error($result['error'] ?? 'That could not be completed.');

        Response::to($this->base() . '/sales');
    }

    public function declineSale(Request $request): void
    {
        $account = $this->account();
        $sale    = $this->ownSale($request->paramInt('id'), (int) $account['id']);

        Purchases::fail((int) $sale['id'],
            trim((string) $request->input('reason', '')) ?: 'Payment not received',
            ['type' => 'partner', 'id' => $this->actorId()]);

        Session::success('Marked as not paid. No units moved.');
        Response::to($this->base() . '/sales');
    }

    // =================================================================
    // Their prices
    // =================================================================

    public function pricing(Request $request): void
    {
        $account = $this->account();

        $this->render('reseller_pricing', [
            'title'    => 'What I charge',
            'account'  => $account,
            'plans'    => Database::all(
                'SELECT p.*, (SELECT COUNT(*) FROM bulk_purchases b WHERE b.plan_id = p.id AND b.status = \'completed\') AS sold
                   FROM bulk_plans p WHERE p.owner_account_id = :o ORDER BY p.sort_order, p.units',
                ['o' => $account['id']]),
            'cost'     => Accounts::unitPrice($account),
            'housePlans' => Database::all(
                'SELECT * FROM bulk_plans WHERE owner_account_id = :h AND is_active = 1 ORDER BY sort_order, units',
                ['h' => Accounts::house()['id']]),
        ]);
    }

    /** Their standard price to their own clients. */
    public function saveResale(Request $request): void
    {
        $account = $this->account();
        $price   = trim((string) $request->input('resale_unit_price', ''));

        Database::update('bulk_accounts',
            ['resale_unit_price' => $price === '' ? null : max(0, round((float) $price, 4))],
            ['id' => $account['id']]);

        Session::success('Saved. New clients and anyone without their own price pay this.');
        Response::to($this->base() . '/pricing');
    }

    public function savePlan(Request $request): void
    {
        $account = $this->account();
        $id      = $request->paramInt('id');
        $name    = trim((string) $request->input('name', ''));
        $units   = $request->int('units');
        $price   = round($request->decimal('price'), 2);

        if ($name === '' || $units <= 0 || $price <= 0) {
            Session::error('A bundle needs a name, a number of units and a price.');
            Response::to($this->base() . '/pricing');
        }

        if ($id > 0) {
            Database::run(
                'UPDATE bulk_plans SET name = :n, units = :u, price = :p, is_popular = :pop, sort_order = :s
                  WHERE id = :id AND owner_account_id = :o',
                ['n' => mb_substr($name, 0, 80), 'u' => $units, 'p' => $price,
                 'pop' => $request->bool('is_popular') ? 1 : 0, 's' => $request->int('sort_order'),
                 'id' => $id, 'o' => $account['id']]
            );
        } else {
            Database::insert('bulk_plans', [
                'owner_account_id' => $account['id'],
                'name'             => mb_substr($name, 0, 80),
                'units'            => $units,
                'price'            => $price,
                'is_popular'       => $request->bool('is_popular') ? 1 : 0,
                'sort_order'       => $request->int('sort_order'),
            ]);
        }

        Session::success('Saved.');
        Response::to($this->base() . '/pricing');
    }

    public function togglePlan(Request $request): void
    {
        $account = $this->account();

        Database::run('UPDATE bulk_plans SET is_active = 1 - is_active WHERE id = :id AND owner_account_id = :o',
            ['id' => $request->paramInt('id'), 'o' => $account['id']]);

        Session::success('Updated.');
        Response::to($this->base() . '/pricing');
    }

    public function deletePlan(Request $request): void
    {
        $account = $this->account();
        $id      = $request->paramInt('id');

        $used = (int) Database::scalar('SELECT COUNT(*) FROM bulk_purchases WHERE plan_id = :id', ['id' => $id]);

        if ($used > 0) {
            Database::run('UPDATE bulk_plans SET is_active = 0 WHERE id = :id AND owner_account_id = :o',
                ['id' => $id, 'o' => $account['id']]);
            Session::info('Somebody has bought that bundle, so it has been taken off sale rather than deleted.');
        } else {
            Database::run('DELETE FROM bulk_plans WHERE id = :id AND owner_account_id = :o',
                ['id' => $id, 'o' => $account['id']]);
            Session::success('Deleted.');
        }

        Response::to($this->base() . '/pricing');
    }

    // =================================================================

    /** @return list<array<string,mixed>> */
    private function pendingSales(int $accountId): array
    {
        return Database::all(
            "SELECT p.*, c.name AS client_name, c.phone AS client_phone, a.id AS client_account_id
               FROM bulk_purchases p
               JOIN bulk_accounts a ON a.id = p.account_id
               JOIN clients c ON c.id = a.owner_id
              WHERE p.seller_account_id = :s AND p.status = 'pending'
              ORDER BY p.id",
            ['s' => $accountId]
        );
    }

    private function childAccount(int $id, int $parentId): array
    {
        $row = Accounts::find($id);

        if ($row === null || (int) $row['parent_id'] !== $parentId) {
            throw new HttpException(403, 'That account is not one of yours.');
        }

        return $row;
    }

    private function ownSale(int $id, int $sellerId): array
    {
        $row = Database::first(
            'SELECT * FROM bulk_purchases WHERE id = :id AND seller_account_id = :s',
            ['id' => $id, 's' => $sellerId]
        );

        if ($row === null) {
            throw new HttpException(404, 'No such payment.');
        }

        return $row;
    }
}
