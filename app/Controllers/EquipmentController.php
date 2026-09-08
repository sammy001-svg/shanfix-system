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

/**
 * The machines.
 *
 * A printing and branding business runs on equipment, and the question
 * that costs money is never "what do we own" — it is "what is due for a
 * service, and what is out of action right now". So the list leads on
 * what is falling due rather than on an inventory.
 *
 * Nearly everyone may look: whether the laminator is working decides what
 * can be promised to a client today. Changing the register is for the
 * people answerable for it.
 */
class EquipmentController extends Controller
{
    public function index(Request $request): void
    {
        $this->authorize('equipment.view');

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $status = in_array($status, ['in_service', 'idle', 'under_repair', 'retired', 'disposed'], true) ? $status : '';
        $due    = $request->query('due') !== null;

        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[] = '(e.name LIKE :q OR e.asset_code LIKE :q2 OR e.make LIKE :q3 OR e.model LIKE :q4 OR e.serial_number LIKE :q5)';
            $params += ['q' => "%$search%", 'q2' => "%$search%", 'q3' => "%$search%", 'q4' => "%$search%", 'q5' => "%$search%"];
        }

        if ($status !== '') {
            $where[]     = 'e.status = :s';
            $params['s'] = $status;
        } else {
            // Disposed machines are kept for the history and are not what
            // somebody opening the register is looking for.
            $where[] = "e.status <> 'disposed'";
        }

        if ($due) {
            $where[]        = 'e.next_service_on IS NOT NULL AND e.next_service_on <= :horizon';
            $params['horizon'] = $this->horizon();
        }

        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $rows = Database::all(
            'SELECT e.*, emp.name AS assignee
               FROM equipment e
          LEFT JOIN employees emp ON emp.id = e.assigned_to' . $clause . '
           ORDER BY e.next_service_on IS NULL, e.next_service_on, e.name',
            $params
        );

        $this->view('equipment/index', [
            'title'    => 'Equipment',
            'rows'     => $rows,
            'search'   => $search,
            'status'   => $status,
            'due'      => $due,
            'dueCount' => (int) Database::scalar(
                "SELECT COUNT(*) FROM equipment
                  WHERE status <> 'disposed' AND next_service_on IS NOT NULL AND next_service_on <= :h",
                ['h' => $this->horizon()],
                0
            ),
            'repairCount' => (int) Database::scalar(
                "SELECT COUNT(*) FROM equipment WHERE status = 'under_repair'", [], 0
            ),
            'warnDays' => (int) Settings::get('equipment_service_warn_days', 14),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('equipment.manage');

        $this->view('equipment/form', [
            'title'     => 'Add a machine',
            'equipment' => null,
            'people'    => $this->people(),
            'suppliers' => $this->suppliers(),
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('equipment.manage');

        $data = $this->validated($request, null);

        $data['asset_code'] = Numbering::next('equipment');
        $data['created_by'] = Auth::id();

        $id = Database::insert('equipment', $data);

        ActivityLog::record('equipment_added', 'equipment', $id, 'Added ' . $data['name']);
        Session::success($data['name'] . ' is on the register.');
        Response::to('/equipment/' . $id);
    }

    public function show(Request $request): void
    {
        $this->authorize('equipment.view');

        $item = $this->find($request->paramInt('id'));

        $this->view('equipment/show', [
            'title'     => $item['name'],
            'equipment' => $item,
            'history'   => Database::all(
                'SELECT s.*, emp.name AS employee_name, sup.name AS supplier_name
                   FROM equipment_service s
              LEFT JOIN employees emp ON emp.id = s.done_by_employee
              LEFT JOIN suppliers sup ON sup.id = s.done_by_supplier
                  WHERE s.equipment_id = :e
               ORDER BY s.serviced_on DESC, s.id DESC',
                ['e' => $item['id']]
            ),
            'people'    => $this->people(),
            'suppliers' => $this->suppliers(),
            'spent'     => (float) Database::scalar(
                'SELECT COALESCE(SUM(cost), 0) FROM equipment_service WHERE equipment_id = :e',
                ['e' => $item['id']],
                0
            ),
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('equipment.manage');

        $item = $this->find($request->paramInt('id'));
        $data = $this->validated($request, (int) $item['id']);

        Database::update('equipment', $data, ['id' => $item['id']]);

        ActivityLog::record('equipment_updated', 'equipment', (int) $item['id'], 'Updated ' . $data['name']);
        Session::success('Saved.');
        Response::to('/equipment/' . $item['id']);
    }

    /**
     * Record what was done to a machine.
     *
     * Recording it also moves the machine's own next-service date, because
     * the whole reason for keeping a history is to know when the next one
     * is due — and a history that has to be read to work that out gets
     * read by nobody.
     */
    public function logService(Request $request): void
    {
        $this->authorize('equipment.manage');

        $item = $this->find($request->paramInt('id'));

        $v = new Validator($request->all());
        $v->require('serviced_on', 'The date')
          ->require('summary', 'What was done')
          ->maxLen('summary', 255, 'What was done')
          ->in('kind', ['service', 'repair', 'inspection', 'part'], 'Kind')
          ->numeric('cost', 'Cost')
          ->min('cost', 0, 'Cost');

        if ($v->fails()) {
            $v->redirectBack('/equipment/' . $item['id']);
        }

        $servicedOn = (string) $request->input('serviced_on');
        $next       = $request->input('next_service_on') ?: null;

        // Where the engineer did not name a date, work one out from the
        // interval if the machine is on a schedule.
        if ($next === null && (int) $item['service_interval_days'] > 0) {
            $next = date('Y-m-d', (int) strtotime($servicedOn . ' +' . (int) $item['service_interval_days'] . ' days'));
        }

        Database::insert('equipment_service', [
            'equipment_id'     => $item['id'],
            'serviced_on'      => $servicedOn,
            'kind'             => (string) $request->input('kind', 'service'),
            'summary'          => trim((string) $request->input('summary')),
            'detail'           => trim((string) $request->input('detail')) ?: null,
            'done_by_employee' => $request->input('done_by_employee') ? (int) $request->input('done_by_employee') : null,
            'done_by_supplier' => $request->input('done_by_supplier') ? (int) $request->input('done_by_supplier') : null,
            'done_by_name'     => trim((string) $request->input('done_by_name')) ?: null,
            'cost'             => $request->decimal('cost'),
            'next_service_on'  => $next,
            'created_by'       => Auth::id(),
        ]);

        // Only move the machine's dates if this is the newest thing done
        // to it: somebody catching up on last year's paperwork must not
        // drag the next service backwards.
        if ($item['last_serviced_on'] === null || $servicedOn >= $item['last_serviced_on']) {
            Database::update('equipment', [
                'last_serviced_on' => $servicedOn,
                'next_service_on'  => $next,
            ], ['id' => $item['id']]);
        }

        ActivityLog::record(
            'equipment_serviced',
            'equipment',
            (int) $item['id'],
            trim((string) $request->input('summary')) . ' — ' . $item['name']
        );

        Session::success('Recorded.' . ($next ? ' Next one due ' . fdate($next) . '.' : ''));
        Response::to('/equipment/' . $item['id']);
    }

    // -- Plumbing ------------------------------------------------------------

    /** How far ahead counts as "due". */
    private function horizon(): string
    {
        $days = max(0, (int) Settings::get('equipment_service_warn_days', 14));

        return date('Y-m-d', (int) strtotime('+' . $days . ' days'));
    }

    private function find(int $id): array
    {
        $row = Database::first(
            'SELECT e.*, emp.name AS assignee, sup.name AS supplier_name
               FROM equipment e
          LEFT JOIN employees emp ON emp.id = e.assigned_to
          LEFT JOIN suppliers sup ON sup.id = e.supplier_id
              WHERE e.id = :id',
            ['id' => $id]
        );

        if (!$row) {
            throw new HttpException(404, 'That machine is not on the register.');
        }

        return $row;
    }

    private function people(): array
    {
        return Database::all(
            "SELECT id, name FROM employees WHERE status <> 'left' ORDER BY name"
        );
    }

    private function suppliers(): array
    {
        return Database::all('SELECT id, name FROM suppliers ORDER BY name');
    }

    private function validated(Request $request, ?int $id): array
    {
        $v = new Validator($request->all());
        $v->require('name', 'What it is')
          ->maxLen('name', 180, 'What it is')
          ->maxLen('category', 80, 'Category')
          ->maxLen('make', 120, 'Make')
          ->maxLen('model', 120, 'Model')
          ->maxLen('serial_number', 120, 'Serial number')
          ->maxLen('location', 180, 'Where it lives')
          ->in('status', ['in_service', 'idle', 'under_repair', 'retired', 'disposed'], 'Status')
          ->numeric('purchase_cost', 'What it cost')
          ->min('purchase_cost', 0, 'What it cost');

        if ($v->fails()) {
            $v->redirectBack($id ? "/equipment/{$id}" : '/equipment/new');
        }

        $interval    = (int) $request->input('service_interval_days', 0);
        $lastService = $request->input('last_serviced_on') ?: null;

        // Keep the next date in step with the interval, so changing "every
        // 90 days" to "every 30" does not leave yesterday's answer showing.
        $next = $request->input('next_service_on') ?: null;

        if ($next === null && $interval > 0 && $lastService !== null) {
            $next = date('Y-m-d', (int) strtotime($lastService . ' +' . $interval . ' days'));
        }

        return [
            'name'          => trim((string) $request->input('name')),
            'category'      => trim((string) $request->input('category')) ?: null,
            'make'          => trim((string) $request->input('make')) ?: null,
            'model'         => trim((string) $request->input('model')) ?: null,
            'serial_number' => trim((string) $request->input('serial_number')) ?: null,
            'location'      => trim((string) $request->input('location')) ?: null,

            'assigned_to' => $request->input('assigned_to') ? (int) $request->input('assigned_to') : null,
            'supplier_id' => $request->input('supplier_id') ? (int) $request->input('supplier_id') : null,

            'purchased_on'   => $request->input('purchased_on') ?: null,
            'purchase_cost'  => $request->decimal('purchase_cost'),
            'warranty_until' => $request->input('warranty_until') ?: null,

            'status' => (string) $request->input('status', 'in_service'),

            'service_interval_days' => $interval > 0 ? $interval : null,
            'last_serviced_on'      => $lastService,
            'next_service_on'       => $next,

            'notes' => trim((string) $request->input('notes')) ?: null,
        ];
    }
}
