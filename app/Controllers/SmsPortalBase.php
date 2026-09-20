<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\BulkSms\Accounts;
use App\Services\BulkSms\Engine;
use App\Services\BulkSms\Present;
use App\Services\BulkSms\Reports;
use App\Services\BulkSms\Topup;
use App\Services\BulkSms\Wallet;

/**
 * Bulk SMS for whoever is signed in — shared by the client portal and
 * the partner portal, because sending your own messages is the same
 * job whichever of the two you are.
 *
 * Everything is scoped to the account behind the session rather than to
 * anything in the request. No account id appears in any address, so
 * nobody can act on an account that is not theirs; the two subclasses
 * differ only in whose account it is, where the pages live, and which
 * shell they are drawn in.
 *
 * A partner's reselling — their clients, their prices, their purchase
 * approvals — is not here. That is PartnerSmsController's own.
 */
abstract class SmsPortalBase extends Controller
{
    /** What an uploaded recipient list may be. */
    private const LIST_TYPES = ['csv', 'xlsx', 'txt'];

    // =================================================================
    // Overview and sending
    // =================================================================

    public function home(Request $request): void
    {
        $account = $this->account();
        [$from, $to] = Reports::range(null, null, 30);

        $this->render('index', [
            'title'     => 'Bulk SMS',
            'account'   => $account,
            'balance'   => (float) $account['sms_units'],
            'senders'   => $this->senderList($account),
            'totals'    => Reports::totals([(int) $account['id']], $from, $to),
            'daily'     => Reports::daily([(int) $account['id']], date('Y-m-d', strtotime('-13 days')), date('Y-m-d')),
            'campaigns' => Database::all(
                'SELECT * FROM bulk_campaigns WHERE account_id = :a ORDER BY id DESC LIMIT 5',
                ['a' => $account['id']]),
            'templates' => Database::all(
                'SELECT * FROM bulk_templates WHERE account_id = :a ORDER BY title LIMIT 20',
                ['a' => $account['id']]),
            'groups'    => $this->groups($account),
            'price'     => Accounts::unitPrice($account),
        ]);
    }

    /** A message to a handful of numbers, sent while they wait. */
    public function send(Request $request): void
    {
        $account = $this->account();

        $numbers = Engine::splitNumbers((string) $request->input('recipients', ''));
        $message = (string) $request->input('message', '');
        $sender  = (string) $request->input('sender_id', '');

        if ($numbers === []) {
            Session::error('Add at least one phone number.');
            Response::to($this->base() . '');
        }

        // More than this is a campaign: it takes longer than a page load,
        // and the queue is what survives a browser being closed.
        if (count($numbers) > 50) {
            Session::error('That is a lot of numbers for a quick send. Make it a campaign instead — it can take a list of any size.');
            Response::to($this->base() . '/campaigns/new');
        }

        $result = Engine::sendNow((int) $account['id'], $numbers, $message, $sender, [
            'source'     => $this->source(),
            'actor_type' => $this->actorType(),
            'actor_id'   => $this->actorId(),
        ]);

        if (!$result['ok'] && ($result['sent'] ?? 0) === 0) {
            Session::error($result['error'] ?? 'Nothing could be sent.');
            Response::to($this->base() . '');
        }

        $parts = [];
        $parts[] = $result['sent'] . ' message' . ($result['sent'] === 1 ? '' : 's') . ' sent';

        if (($result['failed'] ?? 0) > 0) {
            $parts[] = $result['failed'] . ' could not be sent';
        }

        if (!empty($result['invalid'])) {
            $parts[] = count($result['invalid']) . ' number' . (count($result['invalid']) === 1 ? '' : 's')
                     . ' were not valid (' . implode(', ', array_slice($result['invalid'], 0, 3)) . ')';
        }

        $parts[] = Present::units($result['units']) . ' used, ' . Present::units($result['balance']) . ' left';

        Session::success(implode(' · ', $parts));
        Response::to($this->base() . '/reports');
    }

    // =================================================================
    // Campaigns
    // =================================================================

    public function campaigns(Request $request): void
    {
        $account = $this->account();
        $total   = (int) Database::scalar('SELECT COUNT(*) FROM bulk_campaigns WHERE account_id = :a', ['a' => $account['id']]);
        $pager   = $this->paginate($total, 20);

        $this->render('campaigns', [
            'title'   => 'SMS campaigns',
            'account' => $account,
            'rows'    => Database::all(
                "SELECT * FROM bulk_campaigns WHERE account_id = :a
                  ORDER BY id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
                ['a' => $account['id']]),
            'pager'   => $pager,
        ]);
    }

    public function newCampaign(Request $request): void
    {
        $account = $this->account();

        $this->render('campaign_new', [
            'title'     => 'New SMS campaign',
            'account'   => $account,
            'balance'   => (float) $account['sms_units'],
            'senders'   => $this->senderList($account),
            'groups'    => $this->groups($account),
            'templates' => Database::all('SELECT * FROM bulk_templates WHERE account_id = :a ORDER BY title', ['a' => $account['id']]),
        ]);
    }

    public function createCampaign(Request $request): void
    {
        $account = $this->account();

        $data = [
            'name'         => (string) $request->input('name', ''),
            'sender_id'    => (string) $request->input('sender_id', ''),
            'message'      => (string) $request->input('message', ''),
            'group_id'     => $request->int('group_id') ?: null,
            'recipients'   => (string) $request->input('recipients', ''),
            'scheduled_at' => trim((string) $request->input('scheduled_at', '')),
            'source'       => $this->source(),
            'actor_type'   => $this->actorType(),
            'actor_id'     => $this->actorId(),
        ];

        $audience = (string) $request->input('audience', 'numbers');

        if ($audience !== 'group') {
            $data['group_id'] = null;
        }

        if ($audience !== 'numbers') {
            $data['recipients'] = '';
        }

        if ($audience === 'file') {
            $file = $request->file('list');

            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                Session::flashInput($request->all());
                Session::error('Choose the file with the numbers in it.');
                Response::to($this->base() . '/campaigns/new');
            }

            $data['file_path'] = $this->storeList($file);
        }

        $result = Engine::queueCampaign((int) $account['id'], $data);

        if (!$result['ok']) {
            Session::flashInput($request->all());
            Session::error($result['error']);
            Response::to($this->base() . '/campaigns/new');
        }

        Session::success(!empty($result['scheduled'])
            ? 'Scheduled. It will go out on its own — you do not need to stay signed in.'
            : 'Sending now. This page shows how far it has got.');

        Response::to($this->base() . '/campaigns/' . $result['id']);
    }

    public function campaign(Request $request): void
    {
        $account = $this->account();
        $c       = $this->findCampaign($request->paramInt('id'), (int) $account['id']);
        $label   = (string) $request->query('label', '');

        $filters = ['campaign' => (int) $c['id'], 'label' => $label];
        $scope   = [(int) $account['id']];
        $from    = substr((string) $c['created_at'], 0, 10);
        $to      = date('Y-m-d');

        $pager = $this->paginate(Reports::messages($scope, $from, $to, $filters, 1, 0)['total'], 50);
        $page  = Reports::messages($scope, $from, $to, $filters, $pager['perPage'], $pager['offset']);

        // A campaign that is still going is polled by the page, so the
        // progress bar moves without anybody pressing refresh.
        if ($request->wantsJson()) {
            Response::json([
                'status'  => $c['status'],
                'sent'    => (int) $c['sent_count'],
                'failed'  => (int) $c['failed_count'],
                'total'   => max((int) $c['total_count'], (int) $c['sent_count'] + (int) $c['failed_count']),
                'running' => in_array($c['status'], ['queued', 'sending', 'scheduled'], true),
            ]);
        }

        $this->render('campaign', [
            'title'    => $c['name'],
            'account'  => $account,
            'c'        => $c,
            'messages' => $page['rows'],
            'pager'    => $pager,
            'label'    => $label,
            'labels'   => $this->labels((int) $c['id']),
            'size'     => Engine::measure((string) $c['message']),
        ]);
    }

    public function cancelCampaign(Request $request): void
    {
        $account = $this->account();
        $c       = $this->findCampaign($request->paramInt('id'), (int) $account['id']);

        Engine::cancelCampaign((int) $c['id'], (int) $account['id'])
            ? Session::success('Stopped. Messages already handed to the network have gone; the rest will not be sent.')
            : Session::error('That campaign has already finished.');

        Response::to($this->base() . '/campaigns/' . $c['id']);
    }

    // =================================================================
    // Contacts and groups
    // =================================================================

    public function contacts(Request $request): void
    {
        $account = $this->account();
        $groupId = $request->int('group') ?: null;
        $q       = trim((string) $request->query('q', ''));

        $where  = ['account_id = :a'];
        $params = ['a' => $account['id']];

        if ($groupId !== null) {
            $where[] = 'group_id = :g';
            $params['g'] = $groupId;
        }

        if ($q !== '') {
            $where[] = '(name LIKE :q OR phone LIKE :q2)';
            $params += ['q' => "%{$q}%", 'q2' => "%{$q}%"];
        }

        $sqlWhere = implode(' AND ', $where);
        $pager = $this->paginate((int) Database::scalar("SELECT COUNT(*) FROM bulk_contacts WHERE {$sqlWhere}", $params), 50);

        $this->render('contacts', [
            'title'   => 'SMS contacts',
            'account' => $account,
            'rows'    => Database::all(
                "SELECT c.*, g.name AS group_name FROM bulk_contacts c
                   LEFT JOIN bulk_contact_groups g ON g.id = c.group_id
                  WHERE " . str_replace(['account_id', 'group_id', 'name LIKE', 'phone LIKE'],
                                        ['c.account_id', 'c.group_id', 'c.name LIKE', 'c.phone LIKE'], $sqlWhere) . "
                  ORDER BY c.id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
                $params),
            'pager'   => $pager,
            'groups'  => $this->groups($account),
            'groupId' => $groupId,
            'q'       => $q,
        ]);
    }

    public function saveContact(Request $request): void
    {
        $account = $this->account();
        $id      = $request->int('id');
        $phone   = Engine::normalizePhone((string) $request->input('phone', ''));

        if ($phone === null) {
            Session::error('That is not a phone number we can send to.');
            Response::back($this->base() . '/contacts');
        }

        $groupId = $request->int('group_id') ?: null;

        if ($groupId !== null && !$this->ownsGroup($groupId, (int) $account['id'])) {
            throw new HttpException(403, 'That group is not yours.');
        }

        $data = [
            'name'     => mb_substr(trim((string) $request->input('name', '')), 0, 120) ?: null,
            'phone'    => $phone,
            'email'    => mb_substr(trim((string) $request->input('email', '')), 0, 120) ?: null,
            'group_id' => $groupId,
        ];

        if ($id > 0) {
            Database::run(
                'UPDATE bulk_contacts SET name = :n, phone = :p, email = :e, group_id = :g
                  WHERE id = :id AND account_id = :a',
                ['n' => $data['name'], 'p' => $phone, 'e' => $data['email'], 'g' => $groupId,
                 'id' => $id, 'a' => $account['id']]
            );
            Session::success('Contact updated.');
        } else {
            $already = Database::scalar(
                'SELECT id FROM bulk_contacts WHERE account_id = :a AND phone = :p AND (group_id <=> :g)',
                ['a' => $account['id'], 'p' => $phone, 'g' => $groupId]
            );

            if ($already) {
                Session::info('That number is already in this list.');
                Response::back($this->base() . '/contacts');
            }

            Database::insert('bulk_contacts', $data + ['account_id' => $account['id']]);
            Session::success('Contact added.');
        }

        Response::back($this->base() . '/contacts');
    }

    public function deleteContact(Request $request): void
    {
        $account = $this->account();

        Database::run('DELETE FROM bulk_contacts WHERE id = :id AND account_id = :a',
            ['id' => $request->paramInt('id'), 'a' => $account['id']]);

        Session::success('Contact removed.');
        Response::back($this->base() . '/contacts');
    }

    public function saveGroup(Request $request): void
    {
        $account = $this->account();
        $name    = trim((string) $request->input('name', ''));

        if ($name === '') {
            Session::error('Give the list a name.');
            Response::back($this->base() . '/contacts');
        }

        $id = $request->int('id');

        if ($id > 0) {
            Database::run('UPDATE bulk_contact_groups SET name = :n, description = :d WHERE id = :id AND account_id = :a',
                ['n' => mb_substr($name, 0, 120), 'd' => mb_substr(trim((string) $request->input('description', '')), 0, 255),
                 'id' => $id, 'a' => $account['id']]);
        } else {
            Database::insert('bulk_contact_groups', [
                'account_id'  => $account['id'],
                'name'        => mb_substr($name, 0, 120),
                'description' => mb_substr(trim((string) $request->input('description', '')), 0, 255) ?: null,
            ]);
        }

        Session::success('Saved.');
        Response::back($this->base() . '/contacts');
    }

    /**
     * Delete a list. The contacts in it are kept and simply lose their
     * list — deleting somebody's whole address book because they tidied
     * up a group would be a cruel surprise.
     */
    public function deleteGroup(Request $request): void
    {
        $account = $this->account();
        $id      = $request->paramInt('id');

        if (!$this->ownsGroup($id, (int) $account['id'])) {
            throw new HttpException(403, 'That group is not yours.');
        }

        Database::run('DELETE FROM bulk_contact_groups WHERE id = :id AND account_id = :a',
            ['id' => $id, 'a' => $account['id']]);

        Session::success('List deleted. The contacts in it are still in your address book.');
        Response::to($this->base() . '/contacts');
    }

    /** A spreadsheet or CSV of numbers, straight into a list. */
    public function importContacts(Request $request): void
    {
        $account = $this->account();
        $file    = $request->file('list');

        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Session::error('Choose a file to import.');
            Response::to($this->base() . '/contacts');
        }

        $groupId = $request->int('group_id') ?: null;
        $newName = trim((string) $request->input('new_group', ''));

        if ($newName !== '') {
            $groupId = Database::insert('bulk_contact_groups', [
                'account_id' => $account['id'],
                'name'       => mb_substr($newName, 0, 120),
            ]);
        } elseif ($groupId !== null && !$this->ownsGroup($groupId, (int) $account['id'])) {
            throw new HttpException(403, 'That group is not yours.');
        }

        $path = $this->storeList($file);

        try {
            $result = $this->readContacts($path, (int) $account['id'], $groupId);
        } finally {
            @unlink($path);
            @unlink((string) preg_replace('/\.xlsx$/i', '.csv', $path));
        }

        Session::success(sprintf('%d contact%s imported%s%s.',
            $result['added'], $result['added'] === 1 ? '' : 's',
            $result['skipped'] > 0 ? ', ' . $result['skipped'] . ' already there' : '',
            $result['bad'] > 0 ? ', ' . $result['bad'] . ' not valid numbers' : ''));

        Response::to($this->base() . '/contacts' . ($groupId ? '?group=' . $groupId : ''));
    }

    // =================================================================
    // Templates
    // =================================================================

    public function saveTemplate(Request $request): void
    {
        $account = $this->account();
        $title   = trim((string) $request->input('title', ''));
        $message = trim((string) $request->input('message', ''));
        $id      = $request->int('id');

        if ($title === '' || $message === '') {
            Session::error('A saved message needs a name and some words.');
            Response::back($this->base() . '');
        }

        if ($id > 0) {
            Database::run('UPDATE bulk_templates SET title = :t, message = :m WHERE id = :id AND account_id = :a',
                ['t' => mb_substr($title, 0, 120), 'm' => $message, 'id' => $id, 'a' => $account['id']]);
        } else {
            Database::insert('bulk_templates', [
                'account_id' => $account['id'],
                'title'      => mb_substr($title, 0, 120),
                'message'    => $message,
            ]);
        }

        Session::success('Saved.');
        Response::back($this->base() . '');
    }

    public function deleteTemplate(Request $request): void
    {
        $account = $this->account();

        Database::run('DELETE FROM bulk_templates WHERE id = :id AND account_id = :a',
            ['id' => $request->paramInt('id'), 'a' => $account['id']]);

        Session::success('Deleted.');
        Response::back($this->base() . '');
    }

    // =================================================================
    // Sender IDs
    // =================================================================

    public function senders(Request $request): void
    {
        $account = $this->account();

        $this->render('senders', [
            'title'   => 'Sender IDs',
            'account' => $account,
            'rows'    => Database::all(
                'SELECT * FROM bulk_sender_ids WHERE account_id = :a ORDER BY id DESC',
                ['a' => $account['id']]),
        ]);
    }

    public function requestSender(Request $request): void
    {
        $account = $this->account();
        $sender  = trim((string) $request->input('sender_id', ''));
        $purpose = trim((string) $request->input('purpose', ''));

        if (!BulkSmsTrafficController::validSender($sender)) {
            Session::error('A sender ID is 3 to 11 letters or numbers — for example SHANFIX.');
            Response::to($this->base() . '/senders');
        }

        if ($purpose === '') {
            Session::error('Say what the messages will be about. The networks ask us for this.');
            Response::to($this->base() . '/senders');
        }

        $already = Database::first(
            'SELECT id, status FROM bulk_sender_ids WHERE account_id = :a AND BINARY sender_id = :s',
            ['a' => $account['id'], 's' => $sender]
        );

        if ($already) {
            Session::info($already['status'] === 'approved'
                ? 'You already have that sender ID.'
                : 'That request is already with us.');
            Response::to($this->base() . '/senders');
        }

        Database::insert('bulk_sender_ids', [
            'account_id'         => $account['id'],
            'sender_id'          => $sender,
            'purpose'            => mb_substr($purpose, 0, 1000),
            'status'             => 'pending',
            'application_letter' => $this->storeUpload($request->file('letter'), 'bulk-sms/senders'),
            'registration_cert'  => $this->storeUpload($request->file('certificate'), 'bulk-sms/senders'),
        ]);

        // The people who can actually register it with the networks. Told
        // in the system and by e-mail: nobody watches a queue that only
        // fills up when somebody happens to look.
        \App\Services\StaffNotifier::notify(
            \App\Services\StaffNotifier::withRole(['admin', 'manager']),
            [
                'event'       => 'bulk_sender_requested',
                'title'       => 'Sender ID requested: ' . $sender,
                'body'        => Accounts::describe($account)['name'] . ' has asked for the sender ID '
                               . $sender . '. Register it with Onfon, then approve it.',
                'link'        => '/bulk-sms/sender-ids',
                'entity_type' => 'bulk_account',
                'entity_id'   => (int) $account['id'],
            ],
            ['email' => true, 'sms' => false]
        );

        Session::success('Asked for. We register it with the networks and let you know — usually a few working days.');
        Response::to($this->base() . '/senders');
    }

    // =================================================================
    // Buying units
    // =================================================================

    public function buy(Request $request): void
    {
        $account = $this->account();
        $seller  = Accounts::find((int) $account['parent_id']);

        $this->render('buy', [
            'title'    => 'Buy SMS units',
            'account'  => $account,
            'balance'  => (float) $account['sms_units'],
            'price'    => Accounts::unitPrice($account),
            'plans'    => Database::all(
                'SELECT * FROM bulk_plans WHERE owner_account_id = :o AND is_active = 1 ORDER BY sort_order, units',
                ['o' => $account['parent_id']]),
            'seller'   => $seller,
            'sellerName' => $seller ? Accounts::describe($seller)['name'] : 'Shanfix',
            'byMpesa'  => Topup::available() && $seller !== null && $seller['owner_type'] === 'house',
            'phone'    => $this->defaultPhone($account),
            'history'  => Database::all(
                'SELECT * FROM bulk_purchases WHERE account_id = :a ORDER BY id DESC LIMIT 20',
                ['a' => $account['id']]),
        ]);
    }

    /** Start an M-Pesa prompt, or lodge a request with their partner. */
    public function startPurchase(Request $request): void
    {
        $account = $this->account();
        $seller  = Accounts::find((int) $account['parent_id']);

        $what = [
            'plan_id'    => $request->int('plan_id') ?: null,
            'units'      => $request->decimal('units'),
            'actor_type' => $this->actorType(),
            'actor_id'   => $this->actorId(),
        ];

        // A partner's client pays the partner, not us. We record what they
        // are asking for and the partner confirms the money themselves.
        if ($seller !== null && $seller['owner_type'] === 'partner') {
            $result = \App\Services\BulkSms\Purchases::create((int) $account['id'], $what + [
                'method'          => 'manual_mpesa',
                'transaction_ref' => (string) $request->input('transaction_ref', ''),
            ]);

            if (!$result['ok']) {
                Session::error($result['error']);
                Response::to($this->base() . '/buy');
            }

            Session::success('Sent to ' . Accounts::describe($seller)['name']
                . '. They add the units once they have confirmed your payment.');
            Response::to($this->base() . '/buy');
        }

        $result = Topup::request((int) $account['id'], (string) $request->input('phone', ''), $what, $this->source());

        if (!$result['ok']) {
            Session::error($result['error']);
            Response::to($this->base() . '/buy');
        }

        if ($request->wantsJson()) {
            Response::json($result);
        }

        Session::info($result['error'] ?? 'Check your phone and enter your M-Pesa PIN.');
        Response::to($this->base() . '/buy?waiting=' . $result['stk_id']);
    }

    /** Polled by the waiting screen. */
    public function purchaseStatus(Request $request): void
    {
        $account = $this->account();
        $status  = Topup::status($request->paramInt('id'), (int) $account['id']);

        if ($status === null) {
            throw new HttpException(404, 'No such payment.');
        }

        Response::json($status);
    }

    // =================================================================
    // Reports and the API
    // =================================================================

    public function reports(Request $request): void
    {
        $account = $this->account();
        [$from, $to] = Reports::range($request->query('from'), $request->query('to'), 30);

        $filters = [
            'q'      => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'label'  => (string) $request->query('label', ''),
        ];

        $scope = [(int) $account['id']];

        if ($request->query('export') === 'csv') {
            $page = Reports::messages($scope, $from, $to, $filters, 20000, 0);

            Response::csv('my-sms-' . $from . '-to-' . $to . '.csv',
                ['Sent', 'From', 'To', 'Message', 'Units', 'Outcome', 'Reason'],
                array_map(static fn(array $m): array => [
                    $m['created_at'], $m['sender_id'], $m['recipient'], $m['message'],
                    $m['units_charged'], Present::label((string) $m['label'])[1], $m['failed_reason'],
                ], $page['rows']));
        }

        $pager = $this->paginate(Reports::messages($scope, $from, $to, $filters, 1, 0)['total'], 50);
        $page  = Reports::messages($scope, $from, $to, $filters, $pager['perPage'], $pager['offset']);

        $this->render('reports', [
            'title'    => 'SMS delivery reports',
            'account'  => $account,
            'rows'     => $page['rows'],
            'pager'    => $pager,
            'from'     => $from,
            'to'       => $to,
            'filters'  => $filters,
            'totals'   => Reports::totals($scope, $from, $to),
            'byStatus' => Reports::byCarrierStatus($scope, $from, $to),
        ]);
    }

    public function api(Request $request): void
    {
        $account = $this->account();

        $this->render('api', [
            'title'   => 'SMS API',
            'account' => $account,
            'senders' => $this->senderList($account),
            'base'    => \App\Services\Notifier::absoluteUrl('/api/v1'),
            'newKey'  => Session::get($this->keyStash()),
        ]);

        Session::forget($this->keyStash());
    }

    public function issueKey(Request $request): void
    {
        $account = $this->account();
        $issued  = Accounts::issueApiKey((int) $account['id']);

        Session::put($this->keyStash(), $issued);
        Session::success('Here is your new key. Copy it now — for your own safety we keep only a fingerprint of it, so it cannot be shown again.');

        Response::to($this->base() . '/api');
    }

    public function revokeKey(Request $request): void
    {
        $account = $this->account();
        Accounts::revokeApiKey((int) $account['id']);

        Session::success('Revoked. Anything still using that key will be refused.');
        Response::to($this->base() . '/api');
    }

    // =================================================================
    // Shared
    // =================================================================

    // -----------------------------------------------------------------
    // What each portal supplies
    // -----------------------------------------------------------------

    /** The signed-in owner's SMS account, opened on first visit. */
    abstract protected function account(): array;

    /** Where this section lives: /portal/sms, or /partners/sms. */
    abstract protected function base(): string;

    /** Which shell the pages are drawn in. */
    abstract protected function layout(): string;

    /** Recorded against everything sent from here. */
    abstract protected function actorType(): string;
    abstract protected function actorId(): ?int;
    abstract protected function source(): string;

    /** The number an M-Pesa prompt should go to by default. */
    abstract protected function defaultPhone(array $account): string;

    /** Where a newly issued API key waits to be shown once. */
    private function keyStash(): string
    {
        return 'sms_key_' . $this->source();
    }

    /**
     * Draw one of the shared SMS pages.
     *
     * The same templates serve both portals — a partner sending their own
     * messages is doing what a client does — so each page is told where it
     * lives rather than there being two copies to drift apart.
     */
    protected function render(string $view, array $data): void
    {
        $this->view('portal/sms/' . $view, $data + ['base' => $this->base()], $this->layout());
    }

    private function senderList(array $account): array
    {
        return Database::all(
            "SELECT sender_id FROM bulk_sender_ids
              WHERE account_id = :a AND status = 'approved' ORDER BY is_default DESC, sender_id",
            ['a' => $account['id']]
        );
    }

    private function groups(array $account): array
    {
        return Database::all(
            'SELECT g.*, (SELECT COUNT(*) FROM bulk_contacts c WHERE c.group_id = g.id) AS people
               FROM bulk_contact_groups g WHERE g.account_id = :a ORDER BY g.name',
            ['a' => $account['id']]
        );
    }

    private function ownsGroup(int $groupId, int $accountId): bool
    {
        return (bool) Database::scalar(
            'SELECT 1 FROM bulk_contact_groups WHERE id = :g AND account_id = :a',
            ['g' => $groupId, 'a' => $accountId]
        );
    }

    private function findCampaign(int $id, int $accountId): array
    {
        return Database::first(
            'SELECT * FROM bulk_campaigns WHERE id = :id AND account_id = :a',
            ['id' => $id, 'a' => $accountId]
        ) ?? throw new HttpException(404, 'Campaign not found.');
    }

    /** @return array<string,int> */
    private function labels(int $campaignId): array
    {
        return array_column(Database::all(
            'SELECT ' . Reports::LABEL_SQL . ' AS label, COUNT(*) AS n FROM bulk_messages m
              WHERE m.campaign_id = :c GROUP BY label ORDER BY n DESC',
            ['c' => $campaignId]
        ), 'n', 'label');
    }

    /**
     * Keep an uploaded recipient list.
     *
     * Not Controller::storeUpload(), which is for documents and refuses a
     * .csv. These are read by the worker and deleted the moment they have
     * been: a list of phone numbers is not something to keep lying about.
     */
    private function storeList(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'The file could not be uploaded. Please try again.');
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if (!in_array($ext, self::LIST_TYPES, true)) {
            throw new HttpException(422, 'Save the list as a CSV or an Excel .xlsx file and try again.');
        }

        // 40 MB: about a million rows of "name,phone".
        if (($file['size'] ?? 0) > 40 * 1024 * 1024) {
            throw new HttpException(422, 'That file is very large. Split it and send it as two campaigns.');
        }

        $dir = STORAGE_PATH . '/uploads/bulk-sms/lists';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not store the list.');
        }

        $path = $dir . '/' . bin2hex(random_bytes(12)) . '.' . ($ext === 'txt' ? 'csv' : $ext);

        if (!@move_uploaded_file($file['tmp_name'], $path) && !@rename($file['tmp_name'], $path)) {
            throw new \RuntimeException('Could not store the list.');
        }

        return $path;
    }

    /**
     * Read a contact file into the address book.
     *
     * @return array{added:int, skipped:int, bad:int}
     */
    private function readContacts(string $path, int $accountId, ?int $groupId): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx') {
            $csv = (string) preg_replace('/\.xlsx$/i', '.csv', $path);
            \App\Services\BulkSms\XlsxReader::toCsv($path, $csv);
            $path = $csv;
        }

        $fh = fopen($path, 'r');

        if ($fh === false) {
            throw new HttpException(422, 'That file could not be read.');
        }

        $headers = array_map(static fn($h): string => strtolower(trim((string) $h)), fgetcsv($fh) ?: []);
        $phoneAt = -1;
        $nameAt  = -1;

        foreach ($headers as $i => $h) {
            if ($phoneAt === -1 && (str_contains($h, 'phone') || str_contains($h, 'mobile') || in_array($h, ['number', 'contact', 'msisdn'], true))) {
                $phoneAt = $i;
            }
            if ($nameAt === -1 && (str_contains($h, 'name') || $h === 'client')) {
                $nameAt = $i;
            }
        }

        // No header row: a bare list of numbers, one per line, is the most
        // common thing people actually have.
        $bare = $phoneAt === -1;

        if ($bare) {
            rewind($fh);
            $phoneAt = 0;
        }

        $added = 0;
        $skipped = 0;
        $bad = 0;
        $seen = [];

        // What is already in this list, so a re-import adds only the new.
        foreach (Database::all(
            'SELECT phone FROM bulk_contacts WHERE account_id = :a AND (group_id <=> :g)',
            ['a' => $accountId, 'g' => $groupId]
        ) as $row) {
            $seen[$row['phone']] = true;
        }

        while (($cells = fgetcsv($fh)) !== false) {
            $phone = Engine::normalizePhone((string) ($cells[$phoneAt] ?? ''));

            if ($phone === null) {
                if (trim(implode('', $cells)) !== '') {
                    $bad++;
                }
                continue;
            }

            if (isset($seen[$phone])) {
                $skipped++;
                continue;
            }

            $seen[$phone] = true;

            // Everything else on the row is kept, so {placeholders} in a
            // message can use it later.
            $meta = [];
            foreach ($headers as $i => $h) {
                if ($h !== '' && $i !== $phoneAt && isset($cells[$i]) && trim((string) $cells[$i]) !== '') {
                    $meta[$h] = trim((string) $cells[$i]);
                }
            }

            Database::insert('bulk_contacts', [
                'account_id' => $accountId,
                'group_id'   => $groupId,
                'name'       => $nameAt >= 0 ? mb_substr(trim((string) ($cells[$nameAt] ?? '')), 0, 120) ?: null : null,
                'phone'      => $phone,
                'metadata'   => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);

            $added++;
        }

        fclose($fh);

        return ['added' => $added, 'skipped' => $skipped, 'bad' => $bad];
    }
}
