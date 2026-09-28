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
use App\Services\Notifier;
use App\Services\Sms;

class NotificationController extends Controller
{
    public function index(Request $request): void
    {
        $channel = (string) $request->query('channel', '');
        $status  = (string) $request->query('status', '');
        $search  = (string) $request->query('q', '');

        $where  = ['1=1'];
        $params = [];

        if (in_array($channel, ['email', 'sms'], true)) {
            $where[] = 'n.channel = :channel';
            $params['channel'] = $channel;
        }

        if (in_array($status, ['queued', 'sent', 'failed', 'cancelled'], true)) {
            $where[] = 'n.status = :status';
            $params['status'] = $status;
        }

        if ($search !== '') {
            $where[] = '(n.recipient LIKE :q OR n.subject LIKE :q2 OR n.recipient_name LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM notifications n WHERE {$clause}", $params, 0);
        $pager = $this->paginate($total, 40);

        $notifications = Database::all(
            "SELECT n.*, c.name AS client_name, u.name AS sent_by
               FROM notifications n
          LEFT JOIN clients c ON c.id = n.client_id
          LEFT JOIN users u ON u.id = n.created_by
              WHERE {$clause}
           ORDER BY n.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $summary = Database::first(
            "SELECT
                COUNT(CASE WHEN status='queued' THEN 1 END) AS queued,
                COUNT(CASE WHEN status='sent'   THEN 1 END) AS sent,
                COUNT(CASE WHEN status='failed' THEN 1 END) AS failed,
                COALESCE(SUM(CASE WHEN channel='sms' AND status='sent' THEN cost END), 0) AS sms_cost
               FROM notifications"
        );

        $this->view('notifications/index', [
            'title'         => 'Messages',
            'notifications' => $notifications,
            'pager'         => $pager,
            'summary'       => $summary,
            'filters'       => compact('channel', 'status', 'search'),
            'emailOn'       => Settings::bool('smtp_enabled'),
            'smsOn'         => Settings::bool('sms_enabled'),
            'health'        => $this->queueHealth(),
        ]);
    }

    /**
     * Why the queue is or is not moving.
     *
     * The screen's most useful job. Before this, "the queue is not
     * moving" and "cron has not run since the hosting was migrated"
     * looked identical from every page in the system — and they are the
     * two likeliest causes by a distance.
     *
     * @return array{ran:?string, stale:bool, waiting:int, oldest:?string,
     *               held:int, stuck:bool, reasons:list<string>}
     */
    private function queueHealth(): array
    {
        $ran  = (string) Settings::get('cron_last_run', '');
        $ago  = $ran !== '' ? (time() - strtotime($ran)) : null;

        // Cron is documented as running every fifteen minutes. An hour
        // without a run is not a blip.
        $stale = $ran === '' || ($ago !== null && $ago > 3600);

        $row = Database::first(
            "SELECT COUNT(*) AS waiting, MIN(created_at) AS oldest
               FROM notifications
              WHERE status = 'queued' AND attempts < :max",
            ['max' => Settings::int('notify_max_attempts', 3)]
        );

        $waiting = (int) ($row['waiting'] ?? 0);
        $oldest  = $row['oldest'] ?? null;

        $reasons = [];

        if ($ran === '') {
            $reasons[] = 'Cron has never run on this installation. Nothing '
                       . 'will be sent on its own until it does — the line to '
                       . 'add is in Settings, under Messaging.';
        } elseif ($stale) {
            $reasons[] = 'Cron last ran ' . time_ago($ran) . '. It should run '
                       . 'every fifteen minutes; while it is not running, '
                       . 'nothing goes out on its own.';
        }

        if (!Settings::bool('smtp_enabled')) {
            $reasons[] = 'Email is switched off, so nothing on the email side '
                       . 'can be sent.';
        }

        if (!Settings::bool('sms_enabled')) {
            $reasons[] = 'SMS is switched off, so nothing on the SMS side can '
                       . 'be sent.';
        }

        // Client messages waiting for the hours the office set.
        $held   = 0;
        $window = trim((string) Settings::get('notify_send_window', ''));

        if ($window !== '') {
            $held = Notifier::heldForWindow();

            if ($held > 0 && !$this->withinWindow($window)) {
                $reasons[] = $held . ' message' . ($held === 1 ? '' : 's')
                           . ' to clients ' . ($held === 1 ? 'is' : 'are')
                           . ' waiting for the sending window (' . e($window)
                           . '). Messages to colleagues are not held by it.';
            }
        }

        // Something queued for over an hour while cron is running fine is
        // a transport problem rather than a timing one.
        $stuck = $waiting > 0 && !$stale && $oldest !== null
              && strtotime((string) $oldest) < strtotime('-1 hour');

        if ($stuck && $reasons === []) {
            $reasons[] = 'Messages have been waiting over an hour although cron '
                       . 'is running. Open one and read the error on it — that '
                       . 'is usually the mail or SMS server refusing the '
                       . 'connection.';
        }

        return [
            'ran'     => $ran !== '' ? $ran : null,
            'stale'   => $stale,
            'waiting' => $waiting,
            'oldest'  => $oldest,
            'held'    => $held,
            'stuck'   => $stuck,
            'reasons' => $reasons,
        ];
    }

    /** Whether now is inside a "08:00-18:00" window. */
    private function withinWindow(string $window): bool
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $window, $m)) {
            return true;
        }

        $now   = (int) date('H') * 60 + (int) date('i');
        $start = (int) $m[1] * 60 + (int) $m[2];
        $end   = (int) $m[3] * 60 + (int) $m[4];

        return $start <= $end
            ? ($now >= $start && $now <= $end)
            : ($now >= $start || $now <= $end);
    }

    /**
     * Put everything that failed back in the queue.
     *
     * After a mail server has been down for an afternoon there can be
     * two hundred of these, and retrying them one at a time is not a
     * thing anybody does — so they stay failed, and the client never
     * gets their invoice.
     */
    public function retryFailed(Request $request): void
    {
        $this->authorize('settings.manage');

        $count = (int) Database::scalar(
            "SELECT COUNT(*) FROM notifications WHERE status = 'failed'", [], 0
        );

        if ($count === 0) {
            Session::info('Nothing has failed.');
            Response::back('/notifications');
        }

        Database::run(
            "UPDATE notifications
                SET status = 'queued', attempts = 0, last_error = NULL
              WHERE status = 'failed'"
        );

        ActivityLog::record('notifications_retry_all', 'notification', null,
            'Put ' . $count . ' failed message(s) back in the queue');

        // Worked straight away rather than left for cron: somebody who
        // presses this has just fixed the mail server and wants to know
        // whether it took.
        $result = Notifier::processQueue();

        Session::success(
            $count . ' message(s) requeued. '
            . $result['sent'] . ' sent, ' . $result['failed'] . ' failed'
            . ($result['stopped'] !== null ? ' — ' . $result['stopped'] : '.')
        );

        Response::back('/notifications');
    }

    public function show(Request $request): void
    {
        $notification = $this->findOrFail($request->paramInt('id'));

        $this->view('notifications/show', [
            'title'        => 'Message #' . $notification['id'],
            'notification' => $notification,
        ]);
    }

    /**
     * Send a document to its client. Confirms what actually went out
     * rather than silently queueing.
     */
    public function sendDocument(Request $request): void
    {
        $this->authorize('documents.manage');

        $id = $request->paramInt('id');

        $doc = Database::first(
            'SELECT d.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
                    c.contact_person AS client_contact
               FROM documents d JOIN clients c ON c.id = d.client_id
              WHERE d.id = :id',
            ['id' => $id]
        );

        if (!$doc) {
            throw new HttpException(404, 'That document does not exist.');
        }

        // Sending is the other way an unapproved price reaches a client.
        if (\App\Services\DocumentApproval::isPending($doc)) {
            Session::error(\App\Services\DocumentApproval::heldMessage($doc));
            Response::back('/' . \App\Services\DocumentApproval::pathFor((string) $doc['doc_type']) . '/' . $id);
        }

        if ($doc['status'] === 'draft') {
            Session::error('This document is still a draft. Mark it as sent first, so the client sees a finalised copy.');
            Response::back('/invoices/' . $id);
        }

        $event = match ($doc['doc_type']) {
            'quotation' => 'quotation_sent',
            'invoice'   => 'invoice_sent',
            default     => 'payment_received',
        };

        $context = Notifier::documentContext($doc);

        // An explicit "send" overrides the per-event toggles, but only on the
        // channels the operator actually ticked.
        $channels = array_values(array_intersect(
            $request->array('channels') ?: ['email'],
            ['email', 'sms']
        ));

        if ($channels === []) {
            Session::error('Choose at least one way to send it.');
            Response::back('/invoices/' . $id);
        }

        // Remember where the queue ended, so the rows this send creates can
        // be picked out from anything already waiting.
        $queueMark = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM notifications', [], 0);

        $result  = Notifier::dispatch($event, $context, true, $channels);
        $skipped = $result['skipped'];

        if ($result['queued'] === 0) {
            foreach ($skipped as $reason) {
                Session::error($reason);
            }
            if ($skipped === []) {
                Session::error('Nothing could be queued for sending.');
            }
            Response::back('/invoices/' . $id);
        }

        // Send exactly what this button queued, and nothing else.
        //
        // Working the front of the queue instead would mean that whenever a
        // backlog exists — cron paused for an hour is enough — pressing Send
        // on an invoice processes ten unrelated older messages and leaves the
        // invoice sitting there, while the page reports on messages the
        // operator never asked about.
        $mine = Database::all(
            "SELECT id FROM notifications
              WHERE id > :mark AND entity_type = 'document' AND entity_id = :doc
           ORDER BY id ASC",
            ['mark' => $queueMark, 'doc' => $id]
        );

        $sendResult = ['processed' => 0, 'sent' => 0, 'failed' => 0];

        foreach ($mine as $row) {
            $one = Notifier::processQueue(1, (int) $row['id']);

            $sendResult['processed'] += $one['processed'];
            $sendResult['sent']      += $one['sent'];
            $sendResult['failed']    += $one['failed'];
        }

        $path = match ($doc['doc_type']) {
            'quotation' => '/quotations/',
            'invoice'   => '/invoices/',
            default     => '/receipts/',
        };

        if ($sendResult['sent'] > 0) {
            Database::update('documents', [
                'sent_at' => $doc['sent_at'] ?: date('Y-m-d H:i:s'),
                'status'  => $doc['status'] === 'draft' ? 'sent' : $doc['status'],
            ], ['id' => $id]);

            ActivityLog::record('document_sent', 'document', $id, $doc['doc_number'] . ' sent to ' . $doc['client_name']);
            Session::success($sendResult['sent'] . ' message(s) sent to ' . $doc['client_name'] . '.');
        }

        if ($sendResult['failed'] > 0) {
            $lastError = Database::scalar(
                "SELECT last_error FROM notifications
                  WHERE entity_type='document' AND entity_id = :id
               ORDER BY id DESC LIMIT 1",
                ['id' => $id]
            );
            Session::error('Some messages failed: ' . ($lastError ?: 'see the message log.'));
        }

        foreach ($skipped as $reason) {
            Session::warning($reason);
        }

        Response::to($path . $id);
    }

    /** Tell a client their job is ready for collection. */
    public function sendJobReady(Request $request): void
    {
        $this->authorize('jobs.manage');

        $id = $request->paramInt('id');

        $job = Database::first(
            'SELECT j.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
                    c.contact_person AS client_contact, d.doc_number
               FROM jobs j
               JOIN clients c ON c.id = j.client_id
          LEFT JOIN documents d ON d.id = j.document_id
              WHERE j.id = :id',
            ['id' => $id]
        );

        if (!$job) {
            throw new HttpException(404, 'That job card does not exist.');
        }

        $result = Notifier::dispatch('job_ready', Notifier::jobContext($job), true);
        $send   = Notifier::processQueue(10);

        if ($send['sent'] > 0) {
            ActivityLog::record('job_ready_notified', 'job', $id, 'Told ' . $job['client_name'] . ' that ' . $job['job_number'] . ' is ready');

            Database::insert('job_stages', [
                'job_id'     => $id,
                'from_stage' => $job['stage'],
                'to_stage'   => $job['stage'],
                'notes'      => 'Client notified that the job is ready for collection',
                'user_id'    => Auth::id(),
            ]);

            Session::success($send['sent'] . ' message(s) sent to ' . $job['client_name'] . '.');
        }

        if ($send['failed'] > 0) {
            Session::error('Some messages could not be sent — check the message log.');
        }

        foreach ($result['skipped'] as $reason) {
            Session::warning($reason);
        }

        Response::to('/jobs/' . $id);
    }

    public function retry(Request $request): void
    {
        $this->authorize('settings.manage');

        $notification = $this->findOrFail($request->paramInt('id'));

        if ($notification['status'] === 'sent') {
            Session::info('That message was already sent.');
            Response::back('/notifications');
        }

        Database::update('notifications', [
            'status'     => 'queued',
            'attempts'   => 0,
            'last_error' => null,
        ], ['id' => $notification['id']]);

        $result = Notifier::processQueue(1, (int) $notification['id']);

        if ($result['sent'] > 0) {
            Session::success('Message sent.');
        } else {
            $error = Database::scalar('SELECT last_error FROM notifications WHERE id = :id', ['id' => $notification['id']]);
            Session::error('Still failing: ' . ($error ?: 'unknown error'));
        }

        Response::back('/notifications');
    }

    public function cancel(Request $request): void
    {
        $this->authorize('settings.manage');

        $notification = $this->findOrFail($request->paramInt('id'));

        if ($notification['status'] === 'sent') {
            Session::error('That message has already gone out and cannot be cancelled.');
            Response::back('/notifications');
        }

        Database::update('notifications', ['status' => 'cancelled'], ['id' => $notification['id']]);

        Session::success('Message cancelled.');
        Response::back('/notifications');
    }

    /** Run the queue by hand, for when cron is not set up yet. */
    public function runQueue(Request $request): void
    {
        $this->authorize('settings.manage');

        $result = Notifier::processQueue(50);

        if ($result['processed'] === 0) {
            Session::info('Nothing waiting in the queue.');
        } else {
            Session::success(
                'Processed ' . $result['processed'] . ' message(s): '
                . $result['sent'] . ' sent, ' . $result['failed'] . ' failed.'
            );
        }

        Response::back('/notifications');
    }

    /** Send a real test message to the signed-in user. */
    public function sendTest(Request $request): void
    {
        $this->authorize('settings.manage');

        $channel = (string) $request->input('channel', 'email');
        $me      = Auth::user();

        // Free check — confirms the credentials without spending a unit.
        if ($channel === 'sms_balance') {
            $result = (new Sms())->balance();

            if ($result['ok']) {
                Session::success($result['message']);
            } else {
                Session::error('Shanfix Bulk SMS rejected the check: ' . $result['error']);
            }

            Response::to('/settings?tab=messaging');
        }

        if ($channel === 'sms') {
            $phone = trim((string) $request->input('to', '')) ?: (string) ($me['phone'] ?? '');

            if (normalize_phone($phone) === null) {
                Session::error('Enter a valid phone number to send the test to.');
                Response::to('/settings?tab=messaging');
            }

            $result = (new Sms())->send(
                $phone,
                'Test message from ' . Settings::get('company_name', 'Shanfix') . '. Your SMS setup is working.'
            );

            if ($result['ok']) {
                Session::success('Test SMS sent to ' . $phone . '. Check the handset.');
            } else {
                Session::error('Test SMS failed: ' . $result['error']);
            }

            Response::to('/settings?tab=messaging');
        }

        $to = trim((string) $request->input('to', '')) ?: (string) ($me['email'] ?? '');

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Session::error('Enter a valid email address to send the test to.');
            Response::to('/settings?tab=messaging');
        }

        $company = Settings::company();

        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto">'
              . '<div style="background:#0C2B4A;color:#fff;padding:20px 24px;border-radius:8px 8px 0 0">'
              . '<strong style="font-size:18px">' . e($company['name']) . '</strong></div>'
              . '<div style="background:#14874E;height:4px"></div>'
              . '<div style="border:1px solid #DDE4EC;border-top:0;padding:24px;border-radius:0 0 8px 8px">'
              . '<p style="margin:0 0 12px;font-size:15px"><strong>Your email settings are working.</strong></p>'
              . '<p style="margin:0;color:#5A6B7D;font-size:14px;line-height:1.6">'
              . 'This test was sent from the Shanfix Business Management System on '
              . e(date('d M Y \a\t H:i')) . '. Quotations, invoices and payment '
              . 'confirmations will reach your clients from this address.</p>'
              . '</div></div>';

        $result = (new \App\Services\Mailer())->send(
            $to,
            $me['name'] ?? '',
            'Test email from ' . $company['name'],
            $html
        );

        if ($result['ok']) {
            Session::success('Test email sent to ' . $to . '. Check the inbox (and the spam folder).');
        } else {
            Session::error('Test email failed: ' . $result['error']);
        }

        Response::to('/settings?tab=messaging');
    }

    private function findOrFail(int $id): array
    {
        $notification = Database::first(
            'SELECT n.*, c.name AS client_name, u.name AS sent_by
               FROM notifications n
          LEFT JOIN clients c ON c.id = n.client_id
          LEFT JOIN users u ON u.id = n.created_by
              WHERE n.id = :id',
            ['id' => $id]
        );

        if (!$notification) {
            throw new HttpException(404, 'That message does not exist.');
        }

        return $notification;
    }
}
