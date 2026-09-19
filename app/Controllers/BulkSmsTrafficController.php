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
use App\Services\BulkSms\Accounts;
use App\Services\BulkSms\Alerts;
use App\Services\BulkSms\DlrStatus;
use App\Services\BulkSms\Engine;
use App\Services\BulkSms\Reports;

/**
 * What customers are sending, as the office sees it: every campaign,
 * every message and its delivery report, and the sender IDs waiting on
 * the networks.
 */
class BulkSmsTrafficController extends Controller
{
    // =================================================================
    // Campaigns
    // =================================================================

    public function campaigns(Request $request): void
    {
        $this->authorize('bulksms.view');

        $status = (string) $request->query('status', '');
        $q      = trim((string) $request->query('q', ''));
        $where  = ['1 = 1'];
        $params = [];

        if (in_array($status, ['scheduled', 'queued', 'sending', 'completed', 'failed', 'cancelled'], true)) {
            $where[] = 'c.status = :s';
            $params['s'] = $status;
        }

        if ($q !== '') {
            $where[] = '(c.name LIKE :q OR c.sender_id LIKE :q2 OR ' . Accounts::OWNER_NAME_SQL . ' LIKE :q3)';
            $params += ['q' => "%{$q}%", 'q2' => "%{$q}%", 'q3' => "%{$q}%"];
        }

        $sqlWhere = implode(' AND ', $where);
        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM bulk_campaigns c JOIN bulk_accounts a ON a.id = c.account_id WHERE {$sqlWhere}", $params);
        $pager = $this->paginate($total, 30);

        $this->view('bulksms/admin/campaigns', [
            'title'  => 'SMS campaigns',
            'rows'   => Database::all(
                'SELECT c.*, a.owner_type, ' . Accounts::OWNER_NAME_SQL . " AS owner_name
                   FROM bulk_campaigns c JOIN bulk_accounts a ON a.id = c.account_id
                  WHERE {$sqlWhere}
                  ORDER BY c.id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
                $params),
            'pager'  => $pager,
            'status' => $status,
            'q'      => $q,
        ]);
    }

    public function campaign(Request $request): void
    {
        $this->authorize('bulksms.view');

        $c = $this->findCampaign($request->paramInt('id'));
        $label   = (string) $request->query('label', '');
        $from    = substr((string) $c['created_at'], 0, 10);
        $to      = date('Y-m-d');
        $filters = ['campaign' => (int) $c['id'], 'label' => $label];
        $scope   = [(int) $c['account_id']];

        // Counted with the filter applied, so the pager matches the rows.
        $pager = $this->paginate(Reports::messages($scope, $from, $to, $filters, 1, 0)['total'], 50);
        $page  = Reports::messages($scope, $from, $to, $filters, $pager['perPage'], $pager['offset']);

        $this->view('bulksms/admin/campaign', [
            'title'    => $c['name'],
            'c'        => $c,
            'owner'    => Accounts::describe(Accounts::find((int) $c['account_id'])),
            'messages' => $page['rows'],
            'pager'    => $pager,
            'label'    => $label,
            'labels'   => $this->campaignLabels((int) $c['id']),
            'size'     => Engine::measure((string) $c['message']),
        ]);
    }

    public function cancel(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $c = $this->findCampaign($request->paramInt('id'));

        Engine::cancelCampaign((int) $c['id'], (int) $c['account_id'])
            ? Session::success('Cancelled. Anything already handed to the network has gone; the rest will not be sent.')
            : Session::error('That campaign has already finished.');

        ActivityLog::record('bulksms_cancel', 'bulk_campaign', (int) $c['id'], 'Campaign cancelled by the office');
        Response::to('/bulk-sms/campaigns/' . $c['id']);
    }

    public function retry(Request $request): void
    {
        $this->authorize('bulksms.manage');

        $c = $this->findCampaign($request->paramInt('id'));

        Engine::retryCampaign((int) $c['id'])
            ? Session::success('Back on the queue. It carries on from where it stopped — nobody already reached is texted again.')
            : Session::error('Only a failed or cancelled campaign can be retried.');

        ActivityLog::record('bulksms_retry', 'bulk_campaign', (int) $c['id'], 'Campaign retried by the office');
        Response::to('/bulk-sms/campaigns/' . $c['id']);
    }

    // =================================================================
    // Messages and delivery reports
    // =================================================================

    public function messages(Request $request): void
    {
        $this->authorize('bulksms.view');

        [$from, $to] = Reports::range($request->query('from'), $request->query('to'), 7);
        $accountId = $request->int('account') ?: null;
        $accounts  = $accountId !== null ? [$accountId] : null;

        $filters = [
            'q'      => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'label'  => (string) $request->query('label', ''),
            'sender' => trim((string) $request->query('sender', '')),
        ];

        if ($request->query('export') === 'csv') {
            $this->export($accounts, $from, $to, $filters);
        }

        $count = Reports::messages($accounts, $from, $to, $filters, 1, 0)['total'];
        $pager = $this->paginate($count, 50);
        $page  = Reports::messages($accounts, $from, $to, $filters, $pager['perPage'], $pager['offset']);

        $this->view('bulksms/admin/messages', [
            'title'    => 'SMS delivery reports',
            'rows'     => $page['rows'],
            'pager'    => $pager,
            'from'     => $from,
            'to'       => $to,
            'filters'  => $filters,
            'account'  => $accountId !== null ? Accounts::find($accountId) : null,
            'totals'   => Reports::totals($accounts, $from, $to),
            'byStatus' => Reports::byCarrierStatus($accounts, $from, $to),
        ]);
    }

    /** The same filter, as a spreadsheet. Capped, so it cannot run the server out of memory. */
    private function export(?array $accounts, string $from, string $to, array $filters): never
    {
        $page = Reports::messages($accounts, $from, $to, $filters, 50000, 0);

        $rows = array_map(static fn(array $m): array => [
            $m['created_at'], $m['sender_id'], $m['recipient'], $m['message'],
            $m['units_charged'], $m['status'], $m['label'], $m['failed_reason'],
            $m['campaign_name'], $m['gateway_msg_id'], $m['delivered_at'],
        ], $page['rows']);

        ActivityLog::record('bulksms_export', null, null, 'Exported ' . count($rows) . ' SMS messages');

        Response::csv('sms-messages-' . $from . '-to-' . $to . '.csv',
            ['Created', 'Sender', 'Recipient', 'Message', 'Units', 'Status', 'Carrier status',
             'Reason', 'Campaign', 'Gateway ID', 'Delivered'],
            $rows);
    }

    // =================================================================
    // Sender IDs
    // =================================================================

    public function senders(Request $request): void
    {
        $this->authorize('bulksms.view');

        $status = (string) $request->query('status', 'pending');

        if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $this->view('bulksms/admin/senders', [
            'title'  => 'Sender IDs',
            'status' => $status,
            'rows'   => Database::all(
                'SELECT s.*, a.owner_type, ' . Accounts::OWNER_NAME_SQL . ' AS owner_name, u.name AS decided_name
                   FROM bulk_sender_ids s
                   JOIN bulk_accounts a ON a.id = s.account_id
                   LEFT JOIN users u ON u.id = s.decided_by
                  WHERE ' . ($status === 'all' ? '1 = 1' : 's.status = :s') . '
                  ORDER BY s.created_at DESC LIMIT 300',
                $status === 'all' ? [] : ['s' => $status]),
            'counts' => array_column(Database::all(
                'SELECT status, COUNT(*) AS n FROM bulk_sender_ids GROUP BY status'), 'n', 'status'),
            'accountsList' => Database::all(
                'SELECT a.id, a.owner_type, ' . Accounts::OWNER_NAME_SQL . " AS owner_name
                   FROM bulk_accounts a WHERE a.owner_type <> 'house' ORDER BY owner_name"),
        ]);
    }

    /**
     * Add a sender ID that is already registered with the networks —
     * approved straight away. For one the office registered on a
     * customer's behalf, or one carried over from somewhere else.
     */
    public function addSender(Request $request): void
    {
        $this->authorize('bulksms.approve');

        $accountId = $request->int('account_id');
        $sender    = trim((string) $request->input('sender_id', ''));

        if (Accounts::find($accountId) === null) {
            Session::error('Choose the account.');
            Response::to('/bulk-sms/sender-ids');
        }

        if (!self::validSender($sender)) {
            Session::error('A sender ID is 3 to 11 letters, numbers or spaces.');
            Response::to('/bulk-sms/sender-ids');
        }

        $exists = Database::scalar(
            'SELECT id FROM bulk_sender_ids WHERE account_id = :a AND BINARY sender_id = :s',
            ['a' => $accountId, 's' => $sender]);

        if ($exists) {
            Database::run(
                "UPDATE bulk_sender_ids SET status = 'approved', reject_reason = NULL, decided_by = :u, decided_at = NOW() WHERE id = :id",
                ['u' => Auth::id(), 'id' => $exists]);
        } else {
            Database::insert('bulk_sender_ids', [
                'account_id' => $accountId,
                'sender_id'  => $sender,
                'purpose'    => trim((string) $request->input('purpose', '')) ?: 'Added by the office',
                'status'     => 'approved',
                'decided_by' => Auth::id(),
                'decided_at' => date('Y-m-d H:i:s'),
            ]);
        }

        ActivityLog::record('bulksms_sender_add', 'bulk_account', $accountId, 'Sender ID ' . $sender . ' added');
        Session::success('Sender ID ' . $sender . ' is ready to use on that account.');
        Response::to('/bulk-sms/sender-ids?status=approved');
    }

    public function decideSender(Request $request): void
    {
        $this->authorize('bulksms.approve');

        $id  = $request->paramInt('id');
        $row = Database::first('SELECT * FROM bulk_sender_ids WHERE id = :id', ['id' => $id])
            ?? throw new HttpException(404, 'Sender ID not found.');

        $approve = $request->input('decision') === 'approve';
        $reason  = trim((string) $request->input('reason', ''));

        if (!$approve && $reason === '') {
            Session::error('Say why it was not approved — the customer is told the reason.');
            Response::to('/bulk-sms/sender-ids');
        }

        Database::run(
            'UPDATE bulk_sender_ids SET status = :s, reject_reason = :r, decided_by = :u, decided_at = NOW() WHERE id = :id',
            ['s' => $approve ? 'approved' : 'rejected', 'r' => $approve ? null : mb_substr($reason, 0, 255),
             'u' => Auth::id(), 'id' => $id]
        );

        Alerts::senderDecided($id);

        ActivityLog::record($approve ? 'bulksms_sender_approve' : 'bulksms_sender_reject', 'bulk_account',
            (int) $row['account_id'], 'Sender ID ' . $row['sender_id'] . ($approve ? ' approved' : ' rejected: ' . $reason));

        Session::success('Sender ID ' . $row['sender_id'] . ($approve ? ' approved.' : ' rejected.') . ' The customer has been told.');
        Response::to('/bulk-sms/sender-ids');
    }

    public function deleteSender(Request $request): void
    {
        $this->authorize('bulksms.approve');

        $id  = $request->paramInt('id');
        $row = Database::first('SELECT * FROM bulk_sender_ids WHERE id = :id', ['id' => $id]);

        if ($row !== null) {
            Database::run('DELETE FROM bulk_sender_ids WHERE id = :id', ['id' => $id]);

            foreach (['application_letter', 'registration_cert'] as $f) {
                $this->deleteUpload($row[$f]);
            }

            ActivityLog::record('bulksms_sender_delete', 'bulk_account', (int) $row['account_id'],
                'Sender ID ' . $row['sender_id'] . ' removed');
        }

        Session::success('Removed.');
        Response::to('/bulk-sms/sender-ids?status=all');
    }

    /** The application letter or certificate, for checking before approval. */
    public function senderFile(Request $request): void
    {
        $this->authorize('bulksms.view');

        $row = Database::first('SELECT * FROM bulk_sender_ids WHERE id = :id', ['id' => $request->paramInt('id')])
            ?? throw new HttpException(404, 'Not found.');

        $which = $request->param('which') === 'certificate' ? 'registration_cert' : 'application_letter';
        $path  = $row[$which] ? realpath(STORAGE_PATH . '/' . $row[$which]) : false;
        $root  = realpath(STORAGE_PATH);

        if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) {
            throw new HttpException(404, 'That document was not uploaded.');
        }

        Response::download($path, $row['sender_id'] . '-' . ($which === 'registration_cert' ? 'certificate' : 'letter')
            . '.' . pathinfo($path, PATHINFO_EXTENSION));
    }

    // =================================================================

    /** What the networks accept: 3–11 letters, digits and spaces. */
    public static function validSender(string $sender): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{1,9}[A-Za-z0-9]$/', $sender) === 1;
    }

    private function findCampaign(int $id): array
    {
        return Database::first('SELECT * FROM bulk_campaigns WHERE id = :id', ['id' => $id])
            ?? throw new HttpException(404, 'Campaign not found.');
    }

    /** @return array<string,int> */
    private function campaignLabels(int $campaignId): array
    {
        $rows = Database::all(
            'SELECT ' . Reports::LABEL_SQL . ' AS label, COUNT(*) AS n FROM bulk_messages m
              WHERE m.campaign_id = :c GROUP BY label ORDER BY n DESC',
            ['c' => $campaignId]
        );

        return array_column($rows, 'n', 'label');
    }
}
