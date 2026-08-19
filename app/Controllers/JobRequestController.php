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
use App\Services\JobBrief;
use App\Services\Notifier;

/**
 * Job detail requests, from our side.
 *
 * A brief taken over the phone lives in one person's memory, and by the
 * time the work is delivered both sides remember it differently. This
 * asks the client to put it in writing before anything starts: staff
 * raise a request against the client and choose which of the three
 * briefs it is, the client gets a link, and what they write comes back
 * onto their profile.
 *
 * Staff can also fill it in themselves, for the client who is standing at
 * the counter or on the phone. That is recorded, because an answer typed
 * by us and an answer typed by the client are not worth the same when
 * there is an argument about it later.
 */
class JobRequestController extends Controller
{
    /** Everything outstanding, for the team to chase. */
    public function index(Request $request): void
    {
        $this->authorize('requests.view');

        $status = (string) $request->query('status', '');
        $type   = (string) $request->query('type', '');

        $where  = [];
        $params = [];

        if ($status !== '' && in_array($status, ['draft', 'sent', 'opened', 'submitted', 'actioned', 'cancelled'], true)) {
            $where[]          = 'r.status = :status';
            $params['status'] = $status;
        }

        if (JobBrief::isType($type)) {
            $where[]        = 'r.brief_type = :type';
            $params['type'] = $type;
        }

        $sql = 'SELECT r.*, c.name AS client_name, c.client_code, u.name AS raised_by
                  FROM job_requests r
                  JOIN clients c ON c.id = r.client_id
             LEFT JOIN users u ON u.id = r.created_by';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        // Waiting on the client first: those are the ones somebody has to
        // chase. Everything else is in date order.
        $sql .= " ORDER BY FIELD(r.status, 'submitted','opened','sent','draft','actioned','cancelled'),
                           r.created_at DESC";

        $this->view('requests/index', [
            'title'    => 'Job detail requests',
            'requests' => Database::all($sql, $params),
            'status'   => $status,
            'type'     => $type,
            'types'    => JobBrief::TYPES,
            'counts'   => $this->counts(),
        ]);
    }

    /** Raise one against a client. */
    public function store(Request $request): void
    {
        $this->authorize('requests.manage');

        $clientId = $request->int('client_id');
        $type     = (string) $request->input('brief_type', '');

        $client = Database::first('SELECT * FROM clients WHERE id = :id', ['id' => $clientId]);

        if (!$client) {
            throw new HttpException(404, 'That client does not exist.');
        }

        if (!JobBrief::isType($type)) {
            Session::error('Choose whether this is for design, a website or a system.');
            Response::to('/clients/' . $clientId);
        }

        $id = Database::insert('job_requests', [
            'client_id'    => $clientId,
            'reference'    => $this->reference(),
            'brief_type'   => $type,
            'status'       => 'draft',
            'public_token' => bin2hex(random_bytes(24)),   // 48 hex chars
            'title'        => trim((string) $request->input('title', '')) ?: null,
            'note'         => trim((string) $request->input('note', '')) ?: null,
            'created_by'   => Auth::id(),
        ]);

        ActivityLog::record(
            'job_request_created',
            'job_request',
            $id,
            JobBrief::TYPES[$type] . ' brief raised for ' . $client['name']
        );

        Session::success('Request raised. Send it to the client, or fill it in with them now.');
        Response::to('/requests/' . $id);
    }

    /** One request, as the team sees it. */
    public function show(Request $request): void
    {
        $this->authorize('requests.view');

        $req = $this->findOrFail($request->paramInt('id'));

        $this->view('requests/show', [
            'title'    => $req['reference'],
            'request'  => $req,
            'fields'   => JobBrief::fields($req['brief_type']),
            'answers'  => $this->answersFor((int) $req['id']),
            'files'    => Database::all(
                'SELECT * FROM job_request_files WHERE request_id = :id ORDER BY id',
                ['id' => $req['id']]
            ),
            'link'     => Notifier::absoluteUrl('/brief/' . $req['public_token']),
            'canManage' => Auth::can('requests.manage'),
        ]);
    }

    /** Email and text the client the link. */
    public function send(Request $request): void
    {
        $this->authorize('requests.manage');

        $req = $this->findOrFail($request->paramInt('id'));

        if (in_array($req['status'], ['cancelled'], true)) {
            Session::error('This request was cancelled. Raise a new one.');
            Response::to('/requests/' . $req['id']);
        }

        $channels = array_values(array_intersect(
            $request->array('channels') ?: ['email'],
            ['email', 'sms']
        ));

        if ($channels === []) {
            Session::error('Choose at least one way to send it.');
            Response::to('/requests/' . $req['id']);
        }

        // Anything already queued is left alone; only what this button
        // creates is pushed out, so a backlog is not flushed by accident.
        $mark = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM notifications', [], 0);

        $result = Notifier::dispatch('job_request', $this->context($req), true, $channels);

        if ($result['queued'] === 0) {
            foreach ($result['skipped'] as $reason) {
                Session::error($reason);
            }

            if ($result['skipped'] === []) {
                Session::error('Nothing could be queued for sending.');
            }

            Response::to('/requests/' . $req['id']);
        }

        $mine = Database::all(
            "SELECT id FROM notifications
              WHERE id > :mark AND entity_type = 'job_request' AND entity_id = :id
           ORDER BY id ASC",
            ['mark' => $mark, 'id' => $req['id']]
        );

        $sent = 0;

        foreach ($mine as $row) {
            $one   = Notifier::processQueue(1, (int) $row['id']);
            $sent += $one['sent'];
        }

        if ($req['status'] === 'draft') {
            Database::update('job_requests', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')], ['id' => $req['id']]);
        }

        ActivityLog::record('job_request_sent', 'job_request', (int) $req['id'], 'Sent ' . $req['reference'] . ' to the client');

        Session::success($sent > 0
            ? 'Sent. The client has the link.'
            : 'Queued, but nothing went out yet — check the message log.');

        Response::to('/requests/' . $req['id']);
    }

    /** The form, for a colleague filling it in with the client. */
    public function fill(Request $request): void
    {
        $this->authorize('requests.manage');

        $req = $this->findOrFail($request->paramInt('id'));

        $this->view('requests/fill', [
            'title'   => 'Fill in ' . $req['reference'],
            'request' => $req,
            'fields'  => JobBrief::fields($req['brief_type']),
            'answers' => $this->answersFor((int) $req['id']),
        ]);
    }

    /** Save what a colleague typed. */
    public function saveFill(Request $request): void
    {
        $this->authorize('requests.manage');

        $req = $this->findOrFail($request->paramInt('id'));

        $this->saveAnswers((int) $req['id'], $req['brief_type'], $request);

        $already = $req['submitted_at'] !== null;

        Database::update('job_requests', [
            'status'          => $already ? $req['status'] : 'submitted',
            'submitted_at'    => $req['submitted_at'] ?? date('Y-m-d H:i:s'),
            'filled_by_staff' => Auth::id(),
        ], ['id' => $req['id']]);

        ActivityLog::record(
            'job_request_filled',
            'job_request',
            (int) $req['id'],
            ($already ? 'Edited' : 'Filled in') . ' ' . $req['reference'] . ' on the client\'s behalf'
        );

        Session::success($already ? 'Answers updated.' : 'Brief recorded.');
        Response::to('/requests/' . $req['id']);
    }

    /** Mark it dealt with, or drop it. */
    public function status(Request $request): void
    {
        $this->authorize('requests.manage');

        $req    = $this->findOrFail($request->paramInt('id'));
        $status = (string) $request->input('status', '');

        if (!in_array($status, ['actioned', 'cancelled', 'sent'], true)) {
            Session::error('That is not a status this can be set to.');
            Response::to('/requests/' . $req['id']);
        }

        Database::update('job_requests', ['status' => $status], ['id' => $req['id']]);
        ActivityLog::record('job_request_status', 'job_request', (int) $req['id'], $req['reference'] . ' marked ' . $status);

        Session::success('Updated.');
        Response::to('/requests/' . $req['id']);
    }

    /** Send one of the client's attachments to the browser. */
    public function download(Request $request): void
    {
        $this->authorize('requests.view');

        $req  = $this->findOrFail($request->paramInt('id'));
        $file = Database::first(
            'SELECT * FROM job_request_files WHERE id = :f AND request_id = :r',
            ['f' => $request->paramInt('fileId'), 'r' => $req['id']]
        );

        if (!$file) {
            throw new HttpException(404, 'That file is not part of this request.');
        }

        $path = STORAGE_PATH . '/' . $file['stored_name'];

        if (!is_file($path)) {
            throw new HttpException(404, 'That file is no longer on the server.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // The client named this file; it is shown back but never trusted as
        // a path, and the header is quoted so a comma cannot split it.
        $safe = str_replace(['"', "\r", "\n"], '', $file['original_name']);

        header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        exit;
    }

    // -- Shared with the public side --------------------------------------

    /**
     * Write the posted answers for one request.
     *
     * Used by both the client's own submission and a colleague filling it
     * in, so the two can never drift apart in what they accept or how they
     * store it.
     */
    public static function saveAnswers(int $requestId, string $type, Request $request): void
    {
        $fields = JobBrief::fields($type);
        $posted = (array) $request->input('answers', []);

        foreach ($fields as $i => $field) {
            $key   = $field['key'];
            $value = $posted[$key] ?? null;

            // A checkbox group arrives as an array and is stored as one
            // line, so the answer reads back the way it was asked.
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('trim', $value), static fn($v) => $v !== ''));
            }

            $value = trim((string) $value);

            Database::run(
                'INSERT INTO job_request_answers (request_id, field_key, field_label, answer, sort_order)
                      VALUES (:r, :k, :l, :a, :s)
                 ON DUPLICATE KEY UPDATE answer = VALUES(answer),
                                         field_label = VALUES(field_label),
                                         sort_order = VALUES(sort_order)',
                [
                    'r' => $requestId,
                    'k' => $key,
                    'l' => $field['label'],
                    'a' => $value === '' ? null : $value,
                    's' => $i,
                ]
            );
        }
    }

    /**
     * Which required questions were left blank.
     *
     * @return array<int,string> the labels, for telling somebody what is missing
     */
    public static function missingRequired(string $type, Request $request): array
    {
        $posted  = (array) $request->input('answers', []);
        $missing = [];

        foreach (JobBrief::fields($type) as $field) {
            if (empty($field['required'])) {
                continue;
            }

            $v = $posted[$field['key']] ?? null;
            $v = is_array($v) ? implode('', $v) : (string) $v;

            if (trim($v) === '') {
                $missing[] = $field['label'];
            }
        }

        return $missing;
    }

    // -- Internals ---------------------------------------------------------

    /**
     * What the templates get to work with.
     *
     * The client's address goes in here rather than being looked up
     * downstream, because dispatch() only knows what it is handed.
     */
    private function context(array $req): array
    {
        $token = $req['public_token'];

        return [
            'entity_type'  => 'job_request',
            'entity_id'    => (int) $req['id'],
            'client_id'    => (int) $req['client_id'],
            'client_name'  => $req['client_name'] ?? '',
            'contact_name' => $this->firstName($req['contact_person'] ?: ($req['client_name'] ?? '')),
            'email'        => $req['client_email'] ?? '',
            'phone'        => $req['client_phone'] ?? '',
            'company_name' => Settings::get('company_name', 'Shanfix Technology'),
            'company'      => Settings::get('company_name', 'Shanfix Technology'),
            'company_phone'=> Settings::get('company_phone', ''),
            'reference'    => $req['reference'],
            'brief'        => strtolower(JobBrief::TYPES[$req['brief_type']]),
            'link'         => Notifier::absoluteUrl('/brief/' . $token),

            // The full link alone pushes a routine text over one billable
            // part, so the SMS template gets a short one.
            'short_link'   => Notifier::absoluteUrl('/b/' . substr($token, 0, Notifier::SHORT_TOKEN_LENGTH)),
        ];
    }

    /** @return array<string,string> field_key => answer */
    private function answersFor(int $id): array
    {
        $rows = Database::all(
            'SELECT field_key, answer FROM job_request_answers WHERE request_id = :id ORDER BY sort_order, id',
            ['id' => $id]
        );

        return array_column($rows, 'answer', 'field_key');
    }

    private function findOrFail(int $id): array
    {
        $req = Database::first(
            'SELECT r.*, c.name AS client_name, c.client_code, c.email AS client_email,
                    c.phone AS client_phone, c.contact_person,
                    u.name AS raised_by, f.name AS filled_by_name
               FROM job_requests r
               JOIN clients c ON c.id = r.client_id
          LEFT JOIN users u ON u.id = r.created_by
          LEFT JOIN users f ON f.id = r.filled_by_staff
              WHERE r.id = :id',
            ['id' => $id]
        );

        if (!$req) {
            throw new HttpException(404, 'That request does not exist.');
        }

        return $req;
    }

    /** Greeting a client by their first name, not their full one. */
    private function firstName(string $name): string
    {
        $first = trim(strtok(trim($name), " ") ?: "");

        return $first !== "" ? $first : $name;
    }

    /** JDR-2026-0001. */
    private function reference(): string
    {
        $prefix = Settings::get('job_request_prefix', 'JDR');
        $year   = date('Y');

        $seq = (int) Database::scalar(
            'SELECT COUNT(*) + 1 FROM job_requests WHERE YEAR(created_at) = :y',
            ['y' => $year],
            1
        );

        // A deleted request would otherwise let a number repeat, and the
        // column is unique, so step past anything already taken.
        do {
            $ref = sprintf('%s-%s-%04d', $prefix, $year, $seq);
            $taken = Database::scalar(
                'SELECT id FROM job_requests WHERE reference = :r',
                ['r' => $ref]
            );
            $seq++;
        } while ($taken);

        return $ref;
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $rows = Database::all('SELECT status, COUNT(*) AS n FROM job_requests GROUP BY status');

        return array_map('intval', array_column($rows, 'n', 'status'));
    }
}
