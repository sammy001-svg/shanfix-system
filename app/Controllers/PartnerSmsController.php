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

    /**
     * One client of theirs: what they hold, what they have bought, and
     * every unit that has passed between the two of them.
     *
     * What that client has actually sent is not here, and not anywhere a
     * partner can reach. A reseller supplies credit.
     */
    public function clientDetail(Request $request): void
    {
        $account = $this->account();
        $child   = $this->childAccount($request->paramInt('id'), (int) $account['id']);
        $who     = Accounts::describe($child);

        $this->render('reseller_client', [
            'title'     => $who['name'],
            'account'   => $account,
            'client'    => $child,
            'who'       => $who,
            'price'     => Accounts::unitPrice($child),
            'contact'   => Database::first(
                'SELECT name, contact_person, email, phone FROM clients WHERE id = :id',
                ['id' => $child['owner_id']]),
            // Units between the two of them, both directions.
            'ledger'    => Database::all(
                "SELECT l.* FROM bulk_ledger l
                  WHERE l.account_id = :c AND l.kind IN ('purchase','transfer_in','transfer_out','adjustment')
                  ORDER BY l.id DESC LIMIT 30",
                ['c' => $child['id']]),
            'purchases' => Database::all(
                'SELECT * FROM bulk_purchases WHERE account_id = :c ORDER BY id DESC LIMIT 20',
                ['c' => $child['id']]),
            // How much of their own sending this client does, without a
            // word of what it says.
            'usage'     => Reports::totals([(int) $child['id']], date('Y-m-d', strtotime('-29 days')), date('Y-m-d')),
            'sold'      => (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM bulk_purchases
                  WHERE account_id = :c AND seller_account_id = :p AND status = 'completed'",
                ['c' => $child['id'], 'p' => $account['id']]),
            'senders'   => Database::all(
                'SELECT sender_id, status FROM bulk_sender_ids WHERE account_id = :c ORDER BY sender_id',
                ['c' => $child['id']]),
        ]);
    }

    // =================================================================
    // How they appear to their own clients
    // =================================================================

    /**
     * A reseller's own name and contact details.
     *
     * Their clients buy units from them and pay them directly, so the
     * Buy page has to say who to pay and how. "Contact us" is no use
     * when "us" is the wrong company.
     *
     * This is not white-labelling the system: one domain, one login
     * page, and a customer always knows whose system they are in. What
     * it does is stop us standing between a reseller and their own
     * customer at the one moment money changes hands.
     */
    public function branding(Request $request): void
    {
        $account = $this->account();

        $this->render('reseller_branding', [
            'title'   => 'How my clients see me',
            'account' => $account,
            'clients' => (int) Database::scalar(
                'SELECT COUNT(*) FROM bulk_accounts WHERE parent_id = :p', ['p' => $account['id']]),
        ]);
    }

    public function saveBranding(Request $request): void
    {
        $account = $this->account();

        $email = trim((string) $request->input('brand_support_email', ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::error('That e-mail address does not look right.');
            Response::to($this->base() . '/branding');
        }

        Database::update('bulk_accounts', [
            'brand_name'             => mb_substr(trim((string) $request->input('brand_name', '')), 0, 120) ?: null,
            'brand_support_email'    => $email ?: null,
            'brand_support_phone'    => mb_substr(trim((string) $request->input('brand_support_phone', '')), 0, 30) ?: null,
            'brand_pay_instructions' => mb_substr(trim((string) $request->input('brand_pay_instructions', '')), 0, 2000) ?: null,
        ], ['id' => $account['id']]);

        Session::success('Saved. Your clients see this when they buy units from you.');
        Response::to($this->base() . '/branding');
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
