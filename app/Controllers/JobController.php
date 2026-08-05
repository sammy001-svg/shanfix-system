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
use App\Services\Notifier;

/**
 * Production job cards — the shop-floor workflow between "invoice raised"
 * and "client has their goods".
 */
class JobController extends Controller
{
    /**
     * Ordered pipeline. `board` controls whether the stage gets a kanban
     * column; on_hold and cancelled are states, not steps.
     */
    public const STAGES = [
        'pending'    => ['label' => 'Queued',        'board' => true,  'icon' => 'inbox'],
        'artwork'    => ['label' => 'Artwork',       'board' => true,  'icon' => 'edit'],
        'proof_sent' => ['label' => 'Proof Sent',    'board' => true,  'icon' => 'send'],
        'approved'   => ['label' => 'Approved',      'board' => true,  'icon' => 'check-circle'],
        'production' => ['label' => 'In Production', 'board' => true,  'icon' => 'printer'],
        'finishing'  => ['label' => 'Finishing',     'board' => true,  'icon' => 'sliders'],
        'ready'      => ['label' => 'Ready',         'board' => true,  'icon' => 'package'],
        'delivered'  => ['label' => 'Delivered',     'board' => true,  'icon' => 'check'],
        'on_hold'    => ['label' => 'On Hold',       'board' => false, 'icon' => 'alert-triangle'],
        'cancelled'  => ['label' => 'Cancelled',     'board' => false, 'icon' => 'x-circle'],
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** Stages where the job is finished and should stop appearing as active work. */
    private const CLOSED = ['delivered', 'cancelled'];

    // -- Board / list --------------------------------------------------

    public function index(Request $request): void
    {
        $view     = (string) $request->query('view', 'board');
        $search   = (string) $request->query('q', '');
        $assigned = (string) $request->query('assigned', '');
        $priority = (string) $request->query('priority', '');
        $showDone = $request->query('done') === '1';

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(j.job_number LIKE :q OR j.title LIKE :q2 OR c.name LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }

        if ($assigned === 'me') {
            $where[] = 'j.assigned_to = :me';
            $params['me'] = Auth::id();
        } elseif ($assigned === 'none') {
            $where[] = 'j.assigned_to IS NULL';
        } elseif ($assigned !== '' && ctype_digit($assigned)) {
            $where[] = 'j.assigned_to = :assigned';
            $params['assigned'] = (int) $assigned;
        }

        if (in_array($priority, self::PRIORITIES, true)) {
            $where[] = 'j.priority = :priority';
            $params['priority'] = $priority;
        }

        // The board hides finished work by default so it stays usable.
        if (!$showDone) {
            $where[] = "j.stage NOT IN ('delivered','cancelled')";
        }

        $clause = implode(' AND ', $where);

        $jobs = Database::all(
            "SELECT j.*, c.name AS client_name, c.phone AS client_phone,
                    u.name AS assignee_name, u.avatar_color,
                    d.doc_number, d.balance AS invoice_balance, d.status AS invoice_status,
                    (SELECT COUNT(*) FROM job_items ji WHERE ji.job_id = j.id) AS item_count,
                    (SELECT COUNT(*) FROM job_items ji WHERE ji.job_id = j.id AND ji.is_done = 1) AS item_done,
                    (SELECT COUNT(*) FROM job_files jf
                      WHERE jf.job_id = j.id AND jf.status = 'pending' AND jf.file_type = 'proof') AS proofs_waiting
               FROM jobs j
               JOIN clients c ON c.id = j.client_id
          LEFT JOIN users u ON u.id = j.assigned_to
          LEFT JOIN documents d ON d.id = j.document_id
              WHERE {$clause}
           ORDER BY FIELD(j.priority,'urgent','high','normal','low'),
                    j.due_date IS NULL, j.due_date ASC, j.id DESC",
            $params
        );

        $board       = [];
        $stageTotals = [];
        foreach (self::STAGES as $key => $meta) {
            if ($meta['board']) {
                $board[$key]       = [];
                $stageTotals[$key] = 0;
            }
        }
        $offBoard = [];

        foreach ($jobs as $job) {
            if (isset($board[$job['stage']])) {
                $board[$job['stage']][] = $job;
                $stageTotals[$job['stage']]++;
            } else {
                $offBoard[] = $job;
            }
        }

        $summary = Database::first(
            "SELECT
                COUNT(CASE WHEN stage NOT IN ('delivered','cancelled') THEN 1 END) AS active,
                COUNT(CASE WHEN stage NOT IN ('delivered','cancelled')
                            AND due_date IS NOT NULL AND due_date < NOW() THEN 1 END) AS overdue,
                COUNT(CASE WHEN stage NOT IN ('delivered','cancelled')
                            AND DATE(due_date) = CURDATE() THEN 1 END) AS due_today,
                COUNT(CASE WHEN stage = 'ready' THEN 1 END) AS ready,
                COUNT(CASE WHEN stage = 'on_hold' THEN 1 END) AS on_hold
               FROM jobs"
        );

        $this->view('jobs/index', [
            'title'       => 'Production',
            'view'        => $view === 'list' ? 'list' : 'board',
            'jobs'        => $jobs,
            'board'       => $board,
            'stageTotals' => $stageTotals,
            'offBoard'    => $offBoard,
            'stages'      => self::STAGES,
            'priorities'  => self::PRIORITIES,
            'summary'     => $summary,
            'users'       => $this->productionUsers(),
            'showDone'    => $showDone,
            'filters'     => compact('search', 'assigned', 'priority'),
        ]);
    }

    // -- Create --------------------------------------------------------

    public function create(Request $request): void
    {
        $this->authorize('jobs.manage');

        $documentId = (int) $request->query('document_id', 0);
        $document   = null;
        $items      = [];

        if ($documentId > 0) {
            $document = Database::first(
                'SELECT d.*, c.name AS client_name
                   FROM documents d JOIN clients c ON c.id = d.client_id
                  WHERE d.id = :id',
                ['id' => $documentId]
            );

            if ($document) {
                $items = Database::all(
                    'SELECT description, quantity, unit FROM document_items
                      WHERE document_id = :id ORDER BY sort_order, id',
                    ['id' => $documentId]
                );
            }
        }

        $this->view('jobs/form', [
            'title'      => 'New Job Card',
            'job'        => null,
            'document'   => $document,
            'items'      => $items,
            'clients'    => Database::all("SELECT id, name, client_code FROM clients WHERE status='active' ORDER BY name"),
            'users'      => $this->productionUsers(),
            'stages'     => self::STAGES,
            'priorities' => self::PRIORITIES,
            'nextNumber' => Numbering::peek('job'),
            'leadDays'   => Settings::int('job_default_lead_days', 3),
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('jobs.manage');

        [$data, $items] = $this->validated($request, null);

        $data['job_number'] = Numbering::next('job');
        $data['created_by'] = Auth::id();

        $id = Database::transaction(function () use ($data, $items) {
            $jobId = Database::insert('jobs', $data);

            foreach ($items as $i => $item) {
                Database::insert('job_items', [
                    'job_id'      => $jobId,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'],
                    'specs'       => $item['specs'],
                    'sort_order'  => $i,
                ]);
            }

            Database::insert('job_stages', [
                'job_id'   => $jobId,
                'to_stage' => $data['stage'],
                'notes'    => 'Job card opened',
                'user_id'  => Auth::id(),
            ]);

            return $jobId;
        });

        ActivityLog::record('job_created', 'job', $id, 'Opened job ' . $data['job_number'] . ' — ' . $data['title']);
        Session::success('Job card ' . $data['job_number'] . ' opened.');
        Response::to('/jobs/' . $id);
    }

    /** One-click: raise a job card straight from an invoice or quotation. */
    public function createFromDocument(Request $request): void
    {
        $this->authorize('jobs.manage');

        $documentId = $request->paramInt('id');

        $document = Database::first(
            'SELECT d.*, c.name AS client_name FROM documents d
               JOIN clients c ON c.id = d.client_id WHERE d.id = :id',
            ['id' => $documentId]
        );

        if (!$document) {
            throw new HttpException(404, 'That document does not exist.');
        }

        $existing = Database::first(
            "SELECT id, job_number FROM jobs
              WHERE document_id = :id AND stage <> 'cancelled' LIMIT 1",
            ['id' => $documentId]
        );

        if ($existing) {
            Session::info('A job card already exists for this document (' . $existing['job_number'] . ').');
            Response::to('/jobs/' . $existing['id']);
        }

        $docItems = Database::all(
            'SELECT description, quantity, unit FROM document_items
              WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $documentId]
        );

        if (!$docItems) {
            Session::error('That document has no line items to produce.');
            Response::back('/invoices/' . $documentId);
        }

        $leadDays = Settings::int('job_default_lead_days', 3);

        $jobId = Database::transaction(function () use ($document, $docItems, $leadDays) {
            $jobNumber = Numbering::next('job');

            $jobId = Database::insert('jobs', [
                'job_number'  => $jobNumber,
                'client_id'   => $document['client_id'],
                'document_id' => $document['id'],
                'title'       => $document['title'] ?: ('Job for ' . $document['doc_number']),
                'stage'       => 'pending',
                'priority'    => 'normal',
                'due_date'    => date('Y-m-d H:i:s', strtotime("+{$leadDays} days 17:00")),
                'created_by'  => Auth::id(),
            ]);

            foreach ($docItems as $i => $item) {
                Database::insert('job_items', [
                    'job_id'      => $jobId,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'],
                    'sort_order'  => $i,
                ]);
            }

            Database::insert('job_stages', [
                'job_id'   => $jobId,
                'to_stage' => 'pending',
                'notes'    => 'Raised from ' . $document['doc_number'],
                'user_id'  => Auth::id(),
            ]);

            return $jobId;
        });

        ActivityLog::record('job_created', 'job', $jobId, 'Job raised from ' . $document['doc_number']);
        Session::success('Job card opened from ' . $document['doc_number'] . '. Assign it and set the deadline.');
        Response::to('/jobs/' . $jobId);
    }

    // -- Detail --------------------------------------------------------

    public function show(Request $request): void
    {
        $job = $this->findOrFail($request->paramInt('id'));

        $items = Database::all(
            'SELECT ji.*, u.name AS done_by_name
               FROM job_items ji
          LEFT JOIN users u ON u.id = ji.done_by
              WHERE ji.job_id = :id ORDER BY ji.sort_order, ji.id',
            ['id' => $job['id']]
        );

        $history = Database::all(
            'SELECT s.*, u.name AS user_name, u.avatar_color
               FROM job_stages s
          LEFT JOIN users u ON u.id = s.user_id
              WHERE s.job_id = :id ORDER BY s.id DESC',
            ['id' => $job['id']]
        );

        $files = Database::all(
            'SELECT f.*, up.name AS uploaded_by_name, ap.name AS approved_by_name
               FROM job_files f
          LEFT JOIN users up ON up.id = f.uploaded_by
          LEFT JOIN users ap ON ap.id = f.approved_by
              WHERE f.job_id = :id
           ORDER BY f.file_type, f.version DESC, f.id DESC',
            ['id' => $job['id']]
        );

        $deliveryNotes = Database::all(
            'SELECT id, dn_number, delivery_date, status, received_by
               FROM delivery_notes WHERE job_id = :id ORDER BY id DESC',
            ['id' => $job['id']]
        );

        // Costing is finance-only; everyone else sees the job without margins.
        $costing = null;
        if (Auth::can('jobs.cost')) {
            $totals = Database::first(
                'SELECT COALESCE(SUM(amount), 0)     AS gross_spent,
                        COALESCE(SUM(vat_amount), 0) AS input_vat,
                        COUNT(*)                     AS entries
                   FROM expenses WHERE job_id = :id',
                ['id' => $job['id']]
            );

            // Margin is measured excluding VAT on both sides. VAT charged to the
            // client is owed to KRA, and VAT paid on materials is reclaimable —
            // neither belongs in the profit on the job.
            $revenue = (float) ($job['doc_total'] ?? 0) - (float) ($job['doc_vat'] ?? 0);
            $spent   = (float) $totals['gross_spent'] - (float) $totals['input_vat'];

            $costing = [
                'entries'     => (int) $totals['entries'],
                'revenue'     => $revenue,
                'spent'       => $spent,
                'gross_spent' => (float) $totals['gross_spent'],
                'input_vat'   => (float) $totals['input_vat'],
                'margin'      => $revenue - $spent,
                'margin_pct'  => $revenue > 0 ? (($revenue - $spent) / $revenue) * 100 : 0,
            ];

            $costing['expenses'] = Database::all(
                'SELECT e.id, e.expense_number, e.description, e.amount, e.vat_amount,
                        e.expense_date, c.name AS category
                   FROM expenses e
              LEFT JOIN categories c ON c.id = e.category_id
                  WHERE e.job_id = :id ORDER BY e.expense_date DESC',
                ['id' => $job['id']]
            );
        }

        $this->view('jobs/show', [
            'title'         => $job['job_number'],
            'messagingOn'   => Settings::bool('smtp_enabled') || Settings::bool('sms_enabled'),
            'job'           => $job,
            'items'         => $items,
            'history'       => $history,
            'files'         => $files,
            'deliveryNotes' => $deliveryNotes,
            'costing'       => $costing,
            'stages'        => self::STAGES,
            'priorities'    => self::PRIORITIES,
            'users'         => $this->productionUsers(),
        ]);
    }

    public function edit(Request $request): void
    {
        $this->authorize('jobs.manage');

        $job = $this->findOrFail($request->paramInt('id'));

        $items = Database::all(
            'SELECT * FROM job_items WHERE job_id = :id ORDER BY sort_order, id',
            ['id' => $job['id']]
        );

        $this->view('jobs/form', [
            'title'      => 'Edit ' . $job['job_number'],
            'job'        => $job,
            'document'   => null,
            'items'      => $items,
            'clients'    => Database::all("SELECT id, name, client_code FROM clients WHERE status='active' OR id = " . (int) $job['client_id'] . " ORDER BY name"),
            'users'      => $this->productionUsers(),
            'stages'     => self::STAGES,
            'priorities' => self::PRIORITIES,
            'nextNumber' => $job['job_number'],
            'leadDays'   => Settings::int('job_default_lead_days', 3),
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('jobs.manage');

        $job = $this->findOrFail($request->paramInt('id'));
        [$data, $items] = $this->validated($request, (int) $job['id']);

        // Stage moves go through moveStage() so history is never bypassed.
        unset($data['stage']);

        Database::transaction(function () use ($job, $data, $items) {
            Database::update('jobs', $data, ['id' => $job['id']]);

            // Preserve completion ticks across an edit.
            $done = Database::all(
                'SELECT description, is_done, done_at, done_by FROM job_items WHERE job_id = :id AND is_done = 1',
                ['id' => $job['id']]
            );
            $doneBy = [];
            foreach ($done as $d) {
                $doneBy[$d['description']] = $d;
            }

            Database::delete('job_items', ['job_id' => $job['id']]);

            foreach ($items as $i => $item) {
                $prev = $doneBy[$item['description']] ?? null;

                Database::insert('job_items', [
                    'job_id'      => $job['id'],
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'],
                    'specs'       => $item['specs'],
                    'is_done'     => $prev ? 1 : 0,
                    'done_at'     => $prev['done_at'] ?? null,
                    'done_by'     => $prev['done_by'] ?? null,
                    'sort_order'  => $i,
                ]);
            }
        });

        ActivityLog::record('job_updated', 'job', (int) $job['id'], 'Updated ' . $job['job_number']);
        Session::success('Job card updated.');
        Response::to('/jobs/' . $job['id']);
    }

    // -- Workflow ------------------------------------------------------

    public function moveStage(Request $request): void
    {
        $this->authorize('jobs.manage');

        $job   = $this->findOrFail($request->paramInt('id'));
        $stage = (string) $request->input('stage', '');

        if (!isset(self::STAGES[$stage])) {
            throw new HttpException(422, 'That production stage is not valid.');
        }

        if ($stage === $job['stage']) {
            Response::back('/jobs/' . $job['id']);
        }

        $holdReason = trim((string) $request->input('hold_reason', ''));

        if ($stage === 'on_hold' && $holdReason === '') {
            Session::error('Say why the job is on hold so the next person knows.');
            Response::back('/jobs/' . $job['id']);
        }

        // Don't let unapproved artwork reach the press.
        if (in_array($stage, ['production', 'finishing'], true)) {
            $pendingProof = (int) Database::scalar(
                "SELECT COUNT(*) FROM job_files
                  WHERE job_id = :id AND file_type = 'proof' AND status = 'pending'",
                ['id' => $job['id']],
                0
            );

            if ($pendingProof > 0 && !$request->bool('override_proof')) {
                Session::warning(
                    'This job has ' . $pendingProof . ' proof(s) still awaiting client approval. '
                    . 'Record the approval first, or tick "proceed anyway" if the client approved verbally.'
                );
                Response::back('/jobs/' . $job['id']);
            }
        }

        $update = [
            'stage'       => $stage,
            'hold_reason' => $stage === 'on_hold' ? $holdReason : null,
        ];

        // Stamp the milestones as they are first reached.
        if ($stage === 'production' && !$job['started_at']) {
            $update['started_at'] = date('Y-m-d H:i:s');
        }
        if ($stage === 'ready' && !$job['completed_at']) {
            $update['completed_at'] = date('Y-m-d H:i:s');
        }
        if ($stage === 'delivered') {
            $update['delivered_at'] = date('Y-m-d H:i:s');
            if (!$job['completed_at']) {
                $update['completed_at'] = date('Y-m-d H:i:s');
            }
        }

        Database::transaction(function () use ($job, $stage, $update, $request, $holdReason) {
            Database::update('jobs', $update, ['id' => $job['id']]);

            Database::insert('job_stages', [
                'job_id'     => $job['id'],
                'from_stage' => $job['stage'],
                'to_stage'   => $stage,
                'notes'      => $request->input('stage_note') ?: ($stage === 'on_hold' ? $holdReason : null),
                'user_id'    => Auth::id(),
            ]);
        });

        ActivityLog::record(
            'job_stage_changed',
            'job',
            (int) $job['id'],
            $job['job_number'] . ': ' . $job['stage'] . ' → ' . $stage
        );

        $message = 'Job moved to ' . self::STAGES[$stage]['label'] . '.';

        if ($stage === 'ready') {
            $message .= ' Raise a delivery note when it goes out.';
        }

        // Tell the client, on the stages they actually care about. These
        // respect the per-event switches in Settings, so an operator who
        // does not want a text on every stage can turn them off.
        $clientEvent = match ($stage) {
            'proof_sent'  => 'proof_ready',
            'production'  => 'job_in_production',
            'ready'       => 'job_ready',
            default       => null,
        };

        if ($clientEvent !== null) {
            // $job is the row as it was before the move, so tell the context
            // about the stage we have just landed on.
            $context = Notifier::jobContext(['stage' => $stage] + $job);
            $queued  = Notifier::dispatch($clientEvent, $context)['queued'];

            if ($queued > 0) {
                Notifier::processQueue(10);
                $message .= ' The client has been notified.';
            }
        }

        Session::success($message);
        Response::to('/jobs/' . $job['id']);
    }

    public function assign(Request $request): void
    {
        $this->authorize('jobs.assign');

        $job    = $this->findOrFail($request->paramInt('id'));
        $userId = $request->int('assigned_to') ?: null;
        $name   = 'nobody';

        if ($userId !== null) {
            $assignee = Database::first(
                'SELECT name FROM users WHERE id = :id AND is_active = 1',
                ['id' => $userId]
            );

            if (!$assignee) {
                Session::error('That team member no longer exists.');
                Response::back('/jobs/' . $job['id']);
            }

            $name = $assignee['name'];
        }

        Database::update('jobs', ['assigned_to' => $userId], ['id' => $job['id']]);

        Database::insert('job_stages', [
            'job_id'     => $job['id'],
            'from_stage' => $job['stage'],
            'to_stage'   => $job['stage'],
            'notes'      => 'Assigned to ' . $name,
            'user_id'    => Auth::id(),
        ]);

        ActivityLog::record('job_assigned', 'job', (int) $job['id'], $job['job_number'] . ' assigned to ' . $name);
        Session::success($userId ? 'Job assigned to ' . $name . '.' : 'Job unassigned.');
        Response::to('/jobs/' . $job['id']);
    }

    /** Tick a production item off the checklist. */
    public function toggleItem(Request $request): void
    {
        $this->authorize('jobs.manage');

        $itemId = $request->paramInt('itemId');

        $item = Database::first(
            'SELECT ji.*, j.job_number FROM job_items ji
               JOIN jobs j ON j.id = ji.job_id WHERE ji.id = :id',
            ['id' => $itemId]
        );

        if (!$item) {
            throw new HttpException(404, 'That job item does not exist.');
        }

        $nowDone = (int) $item['is_done'] === 0;

        Database::update('job_items', [
            'is_done' => $nowDone ? 1 : 0,
            'done_at' => $nowDone ? date('Y-m-d H:i:s') : null,
            'done_by' => $nowDone ? Auth::id() : null,
        ], ['id' => $itemId]);

        Response::back('/jobs/' . $item['job_id']);
    }

    public function addNote(Request $request): void
    {
        $this->authorize('jobs.manage');

        $job  = $this->findOrFail($request->paramInt('id'));
        $note = trim((string) $request->input('notes', ''));

        if ($note === '') {
            Session::error('Write something first.');
            Response::back('/jobs/' . $job['id']);
        }

        Database::insert('job_stages', [
            'job_id'     => $job['id'],
            'from_stage' => $job['stage'],
            'to_stage'   => $job['stage'],
            'notes'      => mb_substr($note, 0, 500),
            'user_id'    => Auth::id(),
        ]);

        Session::success('Note added to the job.');
        Response::to('/jobs/' . $job['id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('jobs.delete');

        $job = $this->findOrFail($request->paramInt('id'));

        // Anything that reached the floor is cancelled, not erased.
        if (!in_array($job['stage'], ['pending', 'cancelled'], true)) {
            Database::transaction(function () use ($job) {
                Database::update('jobs', ['stage' => 'cancelled'], ['id' => $job['id']]);
                Database::insert('job_stages', [
                    'job_id'     => $job['id'],
                    'from_stage' => $job['stage'],
                    'to_stage'   => 'cancelled',
                    'notes'      => 'Job cancelled',
                    'user_id'    => Auth::id(),
                ]);
            });

            ActivityLog::record('job_cancelled', 'job', (int) $job['id'], 'Cancelled ' . $job['job_number']);
            Session::warning($job['job_number'] . ' has production history, so it was cancelled rather than deleted.');
            Response::to('/jobs/' . $job['id']);
        }

        Database::delete('jobs', ['id' => $job['id']]);
        ActivityLog::record('job_deleted', 'job', (int) $job['id'], 'Deleted ' . $job['job_number']);
        Session::success($job['job_number'] . ' deleted.');
        Response::to('/jobs');
    }

    /** Printable job card for the shop floor. */
    public function printCard(Request $request): void
    {
        $job = $this->findOrFail($request->paramInt('id'));

        $items = Database::all(
            'SELECT * FROM job_items WHERE job_id = :id ORDER BY sort_order, id',
            ['id' => $job['id']]
        );

        $this->view('jobs/print', [
            'title'     => $job['job_number'],
            'job'       => $job,
            'items'     => $items,
            'stages'    => self::STAGES,
            'company'   => Settings::company(),
            'autoPrint' => $request->query('auto') === '1',
        ], 'print');
    }

    // -- Internals -----------------------------------------------------

    /** @return array{0: array<string,mixed>, 1: array<int,array<string,mixed>>} */
    private function validated(Request $request, ?int $jobId): array
    {
        $back = $jobId ? "/jobs/{$jobId}/edit" : '/jobs/create';

        $v = new Validator($request->all());
        $v->require('client_id', 'Client')
          ->exists('client_id', 'clients', 'client')
          ->require('title', 'Job title')
          ->maxLen('title', 200, 'Job title')
          ->in('priority', self::PRIORITIES, 'Priority')
          ->in('stage', array_keys(self::STAGES), 'Stage');

        if ($request->input('assigned_to')) {
            $v->exists('assigned_to', 'users', 'team member');
        }

        $dueRaw = (string) $request->input('due_date', '');
        if ($dueRaw !== '' && !strtotime($dueRaw)) {
            $v->custom('due_date', false, 'Enter a valid deadline.');
        }

        // Items
        $items = [];
        foreach ($request->array('items') as $row) {
            if (!is_array($row)) {
                continue;
            }

            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                continue;
            }

            $quantity = (float) str_replace(',', '', (string) ($row['quantity'] ?? 1));

            if ($quantity <= 0) {
                $v->custom('items', false, '"' . str_excerpt($description, 30) . '" needs a quantity greater than zero.');
                break;
            }

            $items[] = [
                'description' => mb_substr($description, 0, 500),
                'quantity'    => $quantity,
                'unit'        => mb_substr(trim((string) ($row['unit'] ?? '')), 0, 30) ?: null,
                'specs'       => mb_substr(trim((string) ($row['specs'] ?? '')), 0, 500) ?: null,
            ];
        }

        if ($items === []) {
            $v->custom('items', false, 'List at least one thing to produce.');
        }

        if ($v->fails()) {
            $v->redirectBack($back);
        }

        $data = [
            'client_id'        => $request->int('client_id'),
            'document_id'      => $request->int('document_id') ?: null,
            'title'            => (string) $request->input('title'),
            'description'      => $request->input('description') ?: null,
            'stage'            => (string) $request->input('stage', 'pending'),
            'priority'         => (string) $request->input('priority', 'normal'),
            'assigned_to'      => $request->int('assigned_to') ?: null,
            'due_date'         => $dueRaw !== '' ? date('Y-m-d H:i:s', strtotime($dueRaw)) : null,
            'production_notes' => $request->input('production_notes') ?: null,
        ];

        return [$data, $items];
    }

    private function findOrFail(int $id): array
    {
        $job = Database::first(
            'SELECT j.*,
                    c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
                    c.address AS client_address, c.city AS client_city, c.contact_person AS client_contact,
                    u.name AS assignee_name, u.avatar_color,
                    cr.name AS created_by_name,
                    d.doc_number, d.doc_type, d.total AS doc_total, d.vat_amount AS doc_vat,
                    d.balance AS invoice_balance, d.status AS invoice_status
               FROM jobs j
               JOIN clients c ON c.id = j.client_id
          LEFT JOIN users u ON u.id = j.assigned_to
          LEFT JOIN users cr ON cr.id = j.created_by
          LEFT JOIN documents d ON d.id = j.document_id
              WHERE j.id = :id',
            ['id' => $id]
        );

        if (!$job) {
            throw new HttpException(404, 'That job card does not exist.');
        }

        return $job;
    }

    private function productionUsers(): array
    {
        return Database::all(
            "SELECT id, name, role, avatar_color FROM users
              WHERE is_active = 1 AND role IN ('admin','manager','production','sales','staff')
           ORDER BY FIELD(role,'production','manager','admin','sales','staff'), name"
        );
    }
}
