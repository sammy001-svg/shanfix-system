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
use App\Services\ArtworkFlow;
use App\Services\Notifier;
use App\Services\StaffNotifier;

/**
 * Design work, from a client asking for it to approved artwork going to
 * production.
 *
 *   requested → assigned → in progress → proof sent
 *             → changes requested (back round) → approved → completed
 *
 * The client's half — seeing the proof and approving it — lives in
 * PublicArtworkController, on a share link with no login.
 */
class ArtworkController extends Controller
{
    public function index(Request $request): void
    {
        $this->authorize('artwork.view');

        $status   = (string) $request->query('status', '');
        $assigned = (int) $request->query('assigned', 0);
        $mine     = $request->query('mine') === '1';

        $where  = ['1=1'];
        $params = [];

        if (isset(ArtworkFlow::STATUSES[$status])) {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }

        if ($assigned > 0) {
            $where[] = 'a.assigned_to = :assigned';
            $params['assigned'] = $assigned;
        }

        // A designer lands on their own queue rather than everybody's.
        if ($mine || (Auth::is('designer') && !Auth::can('artwork.assign') && $status === '' && $assigned === 0)) {
            $where[] = 'a.assigned_to = :me';
            $params['me'] = Auth::id();
        }

        $clause = implode(' AND ', $where);
        $total  = (int) Database::scalar("SELECT COUNT(*) FROM artwork_requests a WHERE {$clause}", $params, 0);
        $pager  = $this->paginate($total, 30);

        $requests = Database::all(
            "SELECT a.*, c.name AS client_name, u.name AS designer_name, u.avatar_color,
                    (SELECT COUNT(*) FROM artwork_files f
                      WHERE f.request_id = a.id AND f.file_type = 'proof') AS proof_count
               FROM artwork_requests a
               JOIN clients c ON c.id = a.client_id
          LEFT JOIN users u ON u.id = a.assigned_to
              WHERE {$clause}
           ORDER BY FIELD(a.priority,'urgent','high','normal','low'),
                    a.due_date IS NULL, a.due_date, a.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $summary = Database::first(
            "SELECT COUNT(CASE WHEN status = 'requested' THEN 1 END) AS unassigned,
                    COUNT(CASE WHEN status IN ('assigned','in_progress') THEN 1 END) AS in_studio,
                    COUNT(CASE WHEN status = 'proof_sent' THEN 1 END) AS awaiting_client,
                    COUNT(CASE WHEN status = 'changes_requested' THEN 1 END) AS changes
               FROM artwork_requests"
        );

        $this->view('artwork/index', [
            'title'    => 'Artwork',
            'requests' => $requests,
            'pager'    => $pager,
            'summary'  => $summary,
            'statuses' => ArtworkFlow::STATUSES,
            'filters'  => ['status' => $status, 'assigned' => $assigned, 'mine' => $mine],
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('artwork.manage');

        $this->view('artwork/form', [
            'title'    => 'New artwork request',
            'artwork'  => null,
            'clients'  => $this->clients(),
            'designers'=> $this->designers(),
            'clientId' => (int) $request->query('client_id', 0),
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('artwork.manage');

        $data = $this->validated($request);

        $id = Database::transaction(function () use ($data) {
            $id = Database::insert('artwork_requests', $data + [
                'request_number' => Numbering::next('artwork'),
                'status'         => $data['assigned_to'] ? 'assigned' : 'requested',
                'created_by'     => Auth::id(),
            ]);

            ArtworkFlow::logEvent($id, null, $data['assigned_to'] ? 'assigned' : 'requested',
                'Request raised');

            return $id;
        });

        $artwork = $this->findOrFail($id);

        // Whoever it landed on needs to know without being told in person.
        if ($artwork['assigned_to']) {
            ArtworkFlow::notifyAssigned($artwork);
        } else {
            ArtworkFlow::notifyStudio($artwork);
        }

        ActivityLog::record('artwork_created', 'artwork', $id, 'Artwork request ' . $artwork['request_number']);
        Session::success('Artwork request created.');
        Response::to('/artwork/' . $id);
    }

    public function show(Request $request): void
    {
        $this->authorize('artwork.view');

        $artwork = $this->findOrFail($request->paramInt('id'));

        $this->view('artwork/show', [
            'title'     => $artwork['request_number'],
            'artwork'   => $artwork,
            'files'     => Database::all(
                'SELECT f.*, u.name AS uploaded_by_name
                   FROM artwork_files f
              LEFT JOIN users u ON u.id = f.uploaded_by
                  WHERE f.request_id = :id
               ORDER BY f.file_type, f.version DESC, f.id DESC',
                ['id' => $artwork['id']]
            ),
            'events'    => Database::all(
                'SELECT e.*, u.name AS user_name
                   FROM artwork_events e
              LEFT JOIN users u ON u.id = e.user_id
                  WHERE e.request_id = :id
               ORDER BY e.id DESC',
                ['id' => $artwork['id']]
            ),
            'designers' => $this->designers(),
            'statuses'  => ArtworkFlow::STATUSES,
            'shareLink' => $artwork['public_token']
                ? Notifier::absoluteUrl('/review/' . $artwork['public_token'])
                : '',
        ]);
    }

    public function edit(Request $request): void
    {
        $this->authorize('artwork.manage');

        $this->view('artwork/form', [
            'title'     => 'Edit artwork request',
            'artwork'   => $this->findOrFail($request->paramInt('id')),
            'clients'   => $this->clients(),
            'designers' => $this->designers(),
            'clientId'  => 0,
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('artwork.manage');

        $artwork = $this->findOrFail($request->paramInt('id'));
        $data    = $this->validated($request);

        Database::update('artwork_requests', $data, ['id' => $artwork['id']]);

        ActivityLog::record('artwork_updated', 'artwork', (int) $artwork['id'],
            'Updated ' . $artwork['request_number']);

        Session::success('Artwork request updated.');
        Response::to('/artwork/' . $artwork['id']);
    }

    /** Put the work on a designer's desk. */
    public function assign(Request $request): void
    {
        $this->authorize('artwork.assign');

        $artwork  = $this->findOrFail($request->paramInt('id'));
        $designer = $request->int('assigned_to', 0);

        if ($designer <= 0) {
            Session::error('Choose a designer.');
            Response::to('/artwork/' . $artwork['id']);
        }

        Database::update('artwork_requests', [
            'assigned_to' => $designer,
            'status'      => in_array($artwork['status'], ['requested'], true) ? 'assigned' : $artwork['status'],
        ], ['id' => $artwork['id']]);

        ArtworkFlow::logEvent((int) $artwork['id'], $artwork['status'], 'assigned', 'Allocated to a designer');

        ArtworkFlow::notifyAssigned($this->findOrFail((int) $artwork['id']));

        Session::success('Allocated. The designer has been notified.');
        Response::to('/artwork/' . $artwork['id']);
    }

    /** Upload a reference, a working draft, or the proof for the client. */
    public function upload(Request $request): void
    {
        $this->authorize('artwork.manage');

        $artwork = $this->findOrFail($request->paramInt('id'));
        $file    = $request->file('file');
        $type    = (string) $request->input('file_type', 'proof');

        if (!in_array($type, ['reference', 'draft', 'proof', 'final'], true)) {
            throw new HttpException(422, 'That file type is not valid.');
        }

        if ($file === null) {
            Session::error('Choose a file to upload.');
            Response::to('/artwork/' . $artwork['id']);
        }

        // Only a designer sends a proof; anyone on the request can attach a
        // reference the client emailed in.
        if (in_array($type, ['proof', 'final'], true) && !Auth::can('artwork.design')) {
            throw new HttpException(403, 'Only a designer can submit a proof.');
        }

        $version = 1 + (int) Database::scalar(
            'SELECT COALESCE(MAX(version), 0) FROM artwork_files WHERE request_id = :id AND file_type = :t',
            ['id' => $artwork['id'], 't' => $type],
            0
        );

        $stored = $this->storeUpload($file, 'artwork');

        Database::insert('artwork_files', [
            'request_id'  => $artwork['id'],
            'file_type'   => $type,
            'file_path'   => $stored,
            'file_name'   => mb_substr((string) ($file['name'] ?? 'file'), 0, 200),
            'file_size'   => (int) ($file['size'] ?? 0),
            'version'     => $version,
            'status'      => $type === 'proof' ? 'pending' : 'approved',
            'notes'       => $request->input('notes') ?: null,
            'uploaded_by' => Auth::id(),
        ]);

        ArtworkFlow::logEvent((int) $artwork['id'], $artwork['status'], $artwork['status'],
            ucfirst($type) . ' v' . $version . ' uploaded');

        if ($type === 'proof' && $artwork['status'] !== 'proof_sent') {
            Database::update('artwork_requests', ['status' => 'in_progress'], ['id' => $artwork['id']]);
        }

        Session::success(
            ucfirst($type) . ' v' . $version . ' uploaded.'
            . ($type === 'proof' ? ' Send it to the client when you are ready.' : '')
        );

        Response::to('/artwork/' . $artwork['id']);
    }

    /** Send the newest proof to the client for approval. */
    public function sendProof(Request $request): void
    {
        $this->authorize('artwork.design');

        $artwork = $this->findOrFail($request->paramInt('id'));

        $result = ArtworkFlow::sendToClient($artwork, $request->array('channels') ?: ['email', 'sms']);

        if (!$result['ok']) {
            Session::error($result['error']);
            Response::to('/artwork/' . $artwork['id']);
        }

        foreach ($result['warnings'] as $warning) {
            Session::warning($warning);
        }

        Session::success($result['message']);
        Response::to('/artwork/' . $artwork['id']);
    }

    /** Record a decision the client gave by phone or in person. */
    public function decide(Request $request): void
    {
        $this->authorize('artwork.manage');

        $artwork  = $this->findOrFail($request->paramInt('id'));
        $decision = (string) $request->input('decision', '');
        $feedback = trim((string) $request->input('client_feedback', ''));

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpException(422, 'That decision is not valid.');
        }

        if ($decision === 'rejected' && $feedback === '') {
            Session::error('Record what the client wants changed, so the designer knows.');
            Response::to('/artwork/' . $artwork['id']);
        }

        ArtworkFlow::recordDecision($artwork, $decision, $feedback, 'staff', null);

        Session::success($decision === 'approved'
            ? 'Approval recorded. The team has been notified.'
            : 'Sent back to the designer with the client feedback.');

        Response::to('/artwork/' . $artwork['id']);
    }

    /** Approved artwork becomes a job on the production board. */
    public function pushToProduction(Request $request): void
    {
        $this->authorize('artwork.manage');

        $artwork = $this->findOrFail($request->paramInt('id'));

        $result = ArtworkFlow::pushToProduction($artwork);

        if (!$result['ok']) {
            Session::error($result['error']);
            Response::to('/artwork/' . $artwork['id']);
        }

        Session::success($result['message']);
        Response::to('/jobs/' . $result['job_id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('artwork.delete');

        $artwork = $this->findOrFail($request->paramInt('id'));

        if ($artwork['job_id']) {
            Session::error('This artwork is already in production and cannot be deleted.');
            Response::to('/artwork/' . $artwork['id']);
        }

        Database::delete('artwork_requests', ['id' => $artwork['id']]);

        ActivityLog::record('artwork_deleted', 'artwork', (int) $artwork['id'],
            'Deleted ' . $artwork['request_number']);

        Session::success($artwork['request_number'] . ' deleted.');
        Response::to('/artwork');
    }

    // -- internals -------------------------------------------------------

    private function validated(Request $request): array
    {
        $v = new Validator($request->all());
        $v->require('client_id', 'Client')
          ->exists('client_id', 'clients', 'client')
          ->require('title', 'Title')
          ->maxLen('title', 200, 'Title')
          ->maxLen('specs', 500, 'Specifications')
          ->in('priority', ['low', 'normal', 'high', 'urgent'], 'Priority');

        if ($request->input('assigned_to')) {
            $v->exists('assigned_to', 'users', 'designer');
        }

        if ($v->fails()) {
            $v->redirectBack('/artwork');
        }

        return [
            'client_id'   => $request->int('client_id'),
            'document_id' => $request->int('document_id') ?: null,
            'title'       => trim((string) $request->input('title')),
            'brief'       => trim((string) $request->input('brief')) ?: null,
            'specs'       => trim((string) $request->input('specs')) ?: null,
            'assigned_to' => $request->int('assigned_to') ?: null,
            'priority'    => (string) $request->input('priority', 'normal'),
            'due_date'    => $request->input('due_date') ?: null,
        ];
    }

    private function clients(): array
    {
        return Database::all(
            "SELECT id, name, client_code FROM clients WHERE status = 'active' ORDER BY name"
        );
    }

    /** Everyone who can take design work. */
    private function designers(): array
    {
        $ids = StaffNotifier::withRole(['designer', 'admin']);

        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        return Database::all(
            "SELECT id, name, role FROM users WHERE id IN ({$in}) ORDER BY name",
            $ids
        );
    }

    private function findOrFail(int $id): array
    {
        $artwork = Database::first(
            'SELECT a.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
                    c.contact_person AS client_contact,
                    u.name AS designer_name, cr.name AS created_by_name,
                    d.doc_number, j.job_number
               FROM artwork_requests a
               JOIN clients c ON c.id = a.client_id
          LEFT JOIN users u ON u.id = a.assigned_to
          LEFT JOIN users cr ON cr.id = a.created_by
          LEFT JOIN documents d ON d.id = a.document_id
          LEFT JOIN jobs j ON j.id = a.job_id
              WHERE a.id = :id',
            ['id' => $id]
        );

        if (!$artwork) {
            throw new HttpException(404, 'That artwork request does not exist.');
        }

        return $artwork;
    }
}
