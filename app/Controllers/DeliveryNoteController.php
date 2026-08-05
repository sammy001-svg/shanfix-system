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
 * Delivery notes — the signed proof that the client received the goods.
 */
class DeliveryNoteController extends Controller
{
    public function index(Request $request): void
    {
        $search = (string) $request->query('q', '');
        $status = (string) $request->query('status', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(dn.dn_number LIKE :q OR c.name LIKE :q2 OR dn.received_by LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }

        if (in_array($status, ['draft', 'dispatched', 'delivered'], true)) {
            $where[] = 'dn.status = :status';
            $params['status'] = $status;
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM delivery_notes dn JOIN clients c ON c.id = dn.client_id WHERE {$clause}",
            $params,
            0
        );
        $pager = $this->paginate($total, 30);

        $notes = Database::all(
            "SELECT dn.*, c.name AS client_name, j.job_number, u.name AS created_by_name,
                    (SELECT COUNT(*) FROM delivery_note_items i WHERE i.delivery_note_id = dn.id) AS item_count
               FROM delivery_notes dn
               JOIN clients c ON c.id = dn.client_id
          LEFT JOIN jobs j ON j.id = dn.job_id
          LEFT JOIN users u ON u.id = dn.created_by
              WHERE {$clause}
           ORDER BY dn.delivery_date DESC, dn.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $summary = Database::first(
            "SELECT COUNT(CASE WHEN status='draft' THEN 1 END) AS draft,
                    COUNT(CASE WHEN status='dispatched' THEN 1 END) AS dispatched,
                    COUNT(CASE WHEN status='delivered' THEN 1 END) AS delivered
               FROM delivery_notes"
        );

        $this->view('delivery/index', [
            'title'   => 'Delivery Notes',
            'notes'   => $notes,
            'pager'   => $pager,
            'summary' => $summary,
            'filters' => compact('search', 'status'),
        ]);
    }

    /** Raise a delivery note pre-filled from a job card. */
    public function createFromJob(Request $request): void
    {
        $this->authorize('delivery.manage');

        $jobId = $request->paramInt('id');

        $job = Database::first(
            'SELECT j.*, c.name AS client_name, c.address AS client_address,
                    c.city AS client_city, c.contact_person AS client_contact
               FROM jobs j JOIN clients c ON c.id = j.client_id
              WHERE j.id = :id',
            ['id' => $jobId]
        );

        if (!$job) {
            throw new HttpException(404, 'That job card does not exist.');
        }

        $items = Database::all(
            'SELECT description, quantity, unit FROM job_items
              WHERE job_id = :id ORDER BY sort_order, id',
            ['id' => $jobId]
        );

        if (!$items) {
            Session::error('That job has nothing to deliver.');
            Response::back('/jobs/' . $jobId);
        }

        $address = trim(($job['client_address'] ?? '') . ($job['client_city'] ? ', ' . $job['client_city'] : ''), ', ');

        $dnId = Database::transaction(function () use ($job, $items, $address) {
            $dnId = Database::insert('delivery_notes', [
                'dn_number'        => Numbering::next('delivery_note'),
                'job_id'           => $job['id'],
                'client_id'        => $job['client_id'],
                'document_id'      => $job['document_id'],
                'delivery_date'    => date('Y-m-d'),
                'delivered_to'     => $job['client_contact'] ?: null,
                'delivery_address' => $address !== '' ? $address : null,
                'status'           => 'draft',
                'created_by'       => Auth::id(),
            ]);

            foreach ($items as $i => $item) {
                Database::insert('delivery_note_items', [
                    'delivery_note_id' => $dnId,
                    'description'      => $item['description'],
                    'quantity'         => $item['quantity'],
                    'unit'             => $item['unit'],
                    'sort_order'       => $i,
                ]);
            }

            return $dnId;
        });

        ActivityLog::record('delivery_note_created', 'delivery_note', $dnId, 'Delivery note raised for ' . $job['job_number']);
        Session::success('Delivery note raised. Add the driver details and print it.');
        Response::to('/delivery-notes/' . $dnId);
    }

    public function show(Request $request): void
    {
        $note  = $this->findOrFail($request->paramInt('id'));
        $items = $this->items((int) $note['id']);

        $this->view('delivery/show', [
            'title' => $note['dn_number'],
            'note'  => $note,
            'items' => $items,
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('delivery.manage');

        $note = $this->findOrFail($request->paramInt('id'));

        $v = new Validator($request->all());
        $v->date('delivery_date', 'Delivery date', true)
          ->maxLen('delivered_to', 160, 'Delivered to')
          ->maxLen('delivered_by', 160, 'Delivered by')
          ->maxLen('vehicle_reg', 40, 'Vehicle registration')
          ->maxLen('delivery_address', 255, 'Delivery address')
          ->maxLen('received_by', 160, 'Received by')
          ->in('status', ['draft', 'dispatched', 'delivered'], 'Status');

        if ($v->fails()) {
            $v->redirectBack('/delivery-notes/' . $note['id']);
        }

        $status = (string) $request->input('status', 'draft');

        // Marking it delivered requires knowing who signed for it.
        if ($status === 'delivered' && trim((string) $request->input('received_by', '')) === '') {
            Session::error('Record who received the goods before marking it delivered.');
            Response::to('/delivery-notes/' . $note['id']);
        }

        $data = [
            'delivery_date'    => (string) $request->input('delivery_date'),
            'delivered_to'     => $request->input('delivered_to') ?: null,
            'delivery_address' => $request->input('delivery_address') ?: null,
            'delivered_by'     => $request->input('delivered_by') ?: null,
            'vehicle_reg'      => $request->input('vehicle_reg') ?: null,
            'received_by'      => $request->input('received_by') ?: null,
            'notes'            => $request->input('notes') ?: null,
            'status'           => $status,
        ];

        if ($status === 'delivered' && !$note['received_at']) {
            $data['received_at'] = date('Y-m-d H:i:s');
        }

        Database::transaction(function () use ($note, $data, $status) {
            Database::update('delivery_notes', $data, ['id' => $note['id']]);

            // Delivering the goods closes the job it came from.
            if ($status === 'delivered' && $note['job_id']) {
                $job = Database::first('SELECT stage FROM jobs WHERE id = :id', ['id' => $note['job_id']]);

                if ($job && !in_array($job['stage'], ['delivered', 'cancelled'], true)) {
                    Database::update('jobs', [
                        'stage'        => 'delivered',
                        'delivered_at' => date('Y-m-d H:i:s'),
                        'completed_at' => date('Y-m-d H:i:s'),
                    ], ['id' => $note['job_id']]);

                    Database::insert('job_stages', [
                        'job_id'     => $note['job_id'],
                        'from_stage' => $job['stage'],
                        'to_stage'   => 'delivered',
                        'notes'      => 'Delivered on ' . $note['dn_number'] . ', received by ' . $data['received_by'],
                        'user_id'    => Auth::id(),
                    ]);
                }
            }
        });

        ActivityLog::record('delivery_note_updated', 'delivery_note', (int) $note['id'], $note['dn_number'] . ' set to ' . $status);

        $message = $status === 'delivered'
            ? 'Delivery confirmed. The job card has been closed.'
            : 'Delivery note updated.';

        // Only on a real transition — re-saving a dispatched note should not
        // text the client the same thing twice.
        if ($status !== $note['status']) {
            $clientEvent = match ($status) {
                'dispatched' => 'delivery_dispatched',
                'delivered'  => 'delivery_confirmed',
                default      => null,
            };

            if ($clientEvent !== null) {
                $context = Notifier::deliveryContext(array_merge($note, $data));
                $queued  = Notifier::dispatch($clientEvent, $context)['queued'];

                if ($queued > 0) {
                    Notifier::processQueue(10);
                    $message .= ' The client has been notified.';
                }
            }
        }

        Session::success($message);

        Response::to('/delivery-notes/' . $note['id']);
    }

    public function print(Request $request): void
    {
        $note  = $this->findOrFail($request->paramInt('id'));
        $items = $this->items((int) $note['id']);

        $this->view('delivery/print', [
            'title'     => $note['dn_number'],
            'note'      => $note,
            'items'     => $items,
            'company'   => Settings::company(),
            'autoPrint' => $request->query('auto') === '1',
        ], 'print');
    }

    public function destroy(Request $request): void
    {
        $this->authorize('delivery.manage');

        $note = $this->findOrFail($request->paramInt('id'));

        if ($note['status'] === 'delivered' && !Auth::is('admin', 'manager')) {
            Session::error('Confirmed deliveries are the record that goods were received and cannot be deleted.');
            Response::to('/delivery-notes/' . $note['id']);
        }

        Database::delete('delivery_notes', ['id' => $note['id']]);

        ActivityLog::record('delivery_note_deleted', 'delivery_note', (int) $note['id'], 'Deleted ' . $note['dn_number']);
        Session::success($note['dn_number'] . ' deleted.');
        Response::to('/delivery-notes');
    }

    // -- Internals -----------------------------------------------------

    private function findOrFail(int $id): array
    {
        $note = Database::first(
            'SELECT dn.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
                    c.kra_pin AS client_kra_pin, c.contact_person AS client_contact,
                    j.job_number, j.title AS job_title, d.doc_number,
                    u.name AS created_by_name
               FROM delivery_notes dn
               JOIN clients c ON c.id = dn.client_id
          LEFT JOIN jobs j ON j.id = dn.job_id
          LEFT JOIN documents d ON d.id = dn.document_id
          LEFT JOIN users u ON u.id = dn.created_by
              WHERE dn.id = :id',
            ['id' => $id]
        );

        if (!$note) {
            throw new HttpException(404, 'That delivery note does not exist.');
        }

        return $note;
    }

    private function items(int $noteId): array
    {
        return Database::all(
            'SELECT * FROM delivery_note_items WHERE delivery_note_id = :id ORDER BY sort_order, id',
            ['id' => $noteId]
        );
    }
}
