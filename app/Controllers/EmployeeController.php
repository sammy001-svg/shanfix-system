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
use App\Core\Validator;

/**
 * The people who work here.
 *
 * Separate from users on purpose: a user is a login, an employee is a
 * person we employ. Half a print floor never signs in, and somebody who
 * leaves keeps their employment record long after their account is shut
 * off. The two point at each other where both exist.
 *
 * Everything here is behind hr.view rather than a manager's permission,
 * because these records carry what people earn, their ID numbers and
 * their bank accounts.
 */
class EmployeeController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): void
    {
        $this->authorize('hr.view');

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $status = in_array($status, ['active', 'on_leave', 'suspended', 'left'], true) ? $status : '';

        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[] = '(name LIKE :q OR employee_number LIKE :q2 OR job_title LIKE :q3 OR department LIKE :q4)';
            $params += ['q' => "%$search%", 'q2' => "%$search%", 'q3' => "%$search%", 'q4' => "%$search%"];
        }

        if ($status !== '') {
            $where[]           = 'status = :s';
            $params['s']       = $status;
        } else {
            // People who have left are kept for the records but are not
            // what somebody opening this page is looking for.
            $where[] = "status <> 'left'";
        }

        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) Database::scalar('SELECT COUNT(*) FROM employees' . $clause, $params, 0);
        $pager = $this->paginate($total, self::PER_PAGE);

        $rows = Database::all(
            'SELECT id, employee_number, name, job_title, department, employment_type,
                    status, started_on, basic_salary, phone
               FROM employees' . $clause . '
           ORDER BY name
              LIMIT ' . $pager['perPage'] . ' OFFSET ' . $pager['offset'],
            $params
        );

        $this->view('employees/index', [
            'title'  => 'Staff',
            'rows'   => $rows,
            'search' => $search,
            'status' => $status,
            'pager'  => $pager,
            'total'  => $total,
            'counts' => Database::all(
                'SELECT status, COUNT(*) AS n FROM employees GROUP BY status'
            ),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('hr.manage');

        $this->view('employees/form', [
            'title'    => 'Add someone',
            'employee' => null,
            'users'    => $this->availableUsers(null),
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('hr.manage');

        $data = $this->validated($request, null);

        $data['employee_number'] = Numbering::next('employee');
        $data['created_by']      = Auth::id();

        $id = Database::insert('employees', $data);

        ActivityLog::record('employee_added', 'employee', $id, 'Added ' . $data['name']);
        Session::success($data['name'] . ' has been added to the staff records.');
        Response::to('/staff/' . $id);
    }

    public function show(Request $request): void
    {
        $this->authorize('hr.view');

        $employee = $this->find($request->paramInt('id'));

        $tab = (string) $request->query('tab', 'overview');
        $tab = in_array($tab, ['overview', 'pay', 'details'], true) ? $tab : 'overview';

        $this->view('employees/show', [
            'title'    => $employee['name'],
            'employee' => $employee,
            'tab'      => $tab,
            'items'    => Database::all(
                'SELECT * FROM employee_pay_items WHERE employee_id = :e ORDER BY kind DESC, id',
                ['e' => $employee['id']]
            ),
            'payslips' => Database::all(
                'SELECT p.*, r.period, r.status AS run_status
                   FROM payslips p
                   JOIN payroll_runs r ON r.id = p.run_id
                  WHERE p.employee_id = :e
               ORDER BY p.id DESC LIMIT 24',
                ['e' => $employee['id']]
            ),
            'equipment' => Database::all(
                'SELECT id, asset_code, name, status FROM equipment WHERE assigned_to = :e ORDER BY name',
                ['e' => $employee['id']]
            ),
            'users' => $this->availableUsers((int) $employee['id']),
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('hr.manage');

        $employee = $this->find($request->paramInt('id'));
        $data     = $this->validated($request, (int) $employee['id']);

        Database::update('employees', $data, ['id' => $employee['id']]);

        ActivityLog::record('employee_updated', 'employee', (int) $employee['id'], 'Updated ' . $data['name']);
        Session::success('Saved.');
        Response::to('/staff/' . $employee['id'] . '?tab=details');
    }

    // -- What they are paid on top of, or out of, their basic ---------------

    public function addPayItem(Request $request): void
    {
        $this->authorize('hr.manage');

        $employee = $this->find($request->paramInt('id'));

        $v = new Validator($request->all());
        $v->require('name', 'What it is')
          ->maxLen('name', 120, 'What it is')
          ->in('kind', ['allowance', 'deduction'], 'Kind')
          ->numeric('amount', 'Amount')
          ->min('amount', 0, 'Amount');

        if ($v->fails()) {
            $v->redirectBack('/staff/' . $employee['id'] . '?tab=pay');
        }

        $kind = (string) $request->input('kind');

        Database::insert('employee_pay_items', [
            'employee_id' => $employee['id'],
            'kind'        => $kind,
            'name'        => trim((string) $request->input('name')),
            'amount'      => $request->decimal('amount'),
            // Only an allowance can be non-taxable; a deduction is never
            // "taxable" in the first place, so the column is left at nil
            // rather than carrying a meaningless flag.
            'taxable'     => $kind === 'allowance' ? ($request->input('taxable') !== null ? 1 : 0) : 0,
            'recurring'   => $request->input('recurring') !== null ? 1 : 0,
            'starts_on'   => $request->input('starts_on') ?: null,
            'ends_on'     => $request->input('ends_on') ?: null,
            'note'        => trim((string) $request->input('note')) ?: null,
            'created_by'  => Auth::id(),
        ]);

        ActivityLog::record(
            'employee_pay_item',
            'employee',
            (int) $employee['id'],
            'Added a ' . $kind . ' for ' . $employee['name']
        );

        Session::success('Added. It applies from the next payroll run.');
        Response::to('/staff/' . $employee['id'] . '?tab=pay');
    }

    public function removePayItem(Request $request): void
    {
        $this->authorize('hr.manage');

        $employee = $this->find($request->paramInt('id'));
        $itemId   = $request->paramInt('item');

        // Switched off rather than deleted: a payslip already issued was
        // worked out with this on it, and the record of why should survive.
        $n = Database::run(
            'UPDATE employee_pay_items SET is_active = 0
              WHERE id = :i AND employee_id = :e',
            ['i' => $itemId, 'e' => $employee['id']]
        )->rowCount();

        if ($n > 0) {
            ActivityLog::record(
                'employee_pay_item_ended',
                'employee',
                (int) $employee['id'],
                'Ended a pay item for ' . $employee['name']
            );
        }

        Session::success('Ended. It will not be on the next run.');
        Response::to('/staff/' . $employee['id'] . '?tab=pay');
    }

    // -- Plumbing ------------------------------------------------------------

    private function find(int $id): array
    {
        $row = Database::first('SELECT * FROM employees WHERE id = :id', ['id' => $id]);

        if (!$row) {
            throw new HttpException(404, 'That person is not in the staff records.');
        }

        return $row;
    }

    /**
     * Logins not already claimed by somebody else.
     *
     * One login belongs to at most one employee, so the picker must not
     * offer an account that is already spoken for — the unique key would
     * refuse it, and a database error is a poor way to learn that.
     */
    private function availableUsers(?int $employeeId): array
    {
        return Database::all(
            'SELECT u.id, u.name, u.email
               FROM users u
          LEFT JOIN employees e ON e.user_id = u.id
              WHERE u.is_active = 1
                AND (e.id IS NULL OR e.id = :me)
           ORDER BY u.name',
            ['me' => $employeeId ?? 0]
        );
    }

    private function validated(Request $request, ?int $id): array
    {
        $v = new Validator($request->all());
        $v->require('first_name', 'First name')
          ->maxLen('first_name', 60, 'First name')
          ->maxLen('middle_name', 60, 'Second name')
          ->require('last_name', 'Third name')
          ->maxLen('last_name', 60, 'Third name')
          ->maxLen('id_number', 30, 'ID number')
          ->maxLen('kra_pin', 30, 'KRA PIN')
          ->maxLen('nssf_number', 30, 'NSSF number')
          ->maxLen('shif_number', 30, 'Health insurance number')
          ->email('email', 'Email address')
          ->phone('phone', 'Phone number')
          ->phone('kin_phone', 'Next of kin phone')
          ->maxLen('job_title', 120, 'Job title')
          ->maxLen('department', 120, 'Department')
          ->in('employment_type', ['permanent', 'contract', 'casual', 'intern'], 'Employment type')
          ->in('status', ['active', 'on_leave', 'suspended', 'left'], 'Status')
          ->numeric('basic_salary', 'Basic salary')
          ->min('basic_salary', 0, 'Basic salary');

        if ($v->fails()) {
            $v->redirectBack($id ? "/staff/{$id}?tab=details" : '/staff/new');
        }

        // A login belongs to at most one person. Checked here so the
        // answer is a sentence rather than a duplicate-key error.
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;

        if ($userId !== null) {
            $taken = Database::first(
                'SELECT id, name FROM employees WHERE user_id = :u AND id <> :me',
                ['u' => $userId, 'me' => $id ?? 0]
            );

            if ($taken) {
                Session::error('That login is already attached to ' . $taken['name'] . '.');
                Response::to($id ? "/staff/{$id}?tab=details" : '/staff/new');
            }
        }

        $ended = $request->input('ended_on') ?: null;

        return [
            'user_id'     => $userId,
            'first_name'  => trim((string) $request->input('first_name')),
            'middle_name' => trim((string) $request->input('middle_name')) ?: null,
            'last_name'   => trim((string) $request->input('last_name')),
            // Written from the parts, never edited alone, so the name we
            // pay against cannot drift from the one on their ID.
            'name'        => full_name(
                (string) $request->input('first_name'),
                (string) $request->input('middle_name'),
                (string) $request->input('last_name')
            ),

            'id_number'   => trim((string) $request->input('id_number')) ?: null,
            'kra_pin'     => $request->input('kra_pin') ? strtoupper(trim((string) $request->input('kra_pin'))) : null,
            'nssf_number' => trim((string) $request->input('nssf_number')) ?: null,
            'shif_number' => trim((string) $request->input('shif_number')) ?: null,

            'date_of_birth' => $request->input('date_of_birth') ?: null,
            'gender'        => in_array($request->input('gender'), ['female', 'male', 'other'], true)
                                 ? (string) $request->input('gender') : null,

            'phone'   => trim((string) $request->input('phone')) ?: null,
            'email'   => $request->input('email') ? strtolower(trim((string) $request->input('email'))) : null,
            'address' => trim((string) $request->input('address')) ?: null,
            'town'    => trim((string) $request->input('town')) ?: null,

            'kin_name'         => trim((string) $request->input('kin_name')) ?: null,
            'kin_phone'        => trim((string) $request->input('kin_phone')) ?: null,
            'kin_relationship' => trim((string) $request->input('kin_relationship')) ?: null,

            'job_title'       => trim((string) $request->input('job_title')) ?: null,
            'department'      => trim((string) $request->input('department')) ?: null,
            'employment_type' => (string) $request->input('employment_type', 'permanent'),
            'started_on'      => $request->input('started_on') ?: null,
            'ended_on'        => $ended,
            'end_reason'      => trim((string) $request->input('end_reason')) ?: null,
            // Somebody with a last day has left, whatever the box said.
            'status'          => $ended !== null ? 'left' : (string) $request->input('status', 'active'),

            'basic_salary' => $request->decimal('basic_salary'),

            'pay_method'        => in_array($request->input('pay_method'), ['mpesa', 'bank', 'cash'], true)
                                     ? (string) $request->input('pay_method') : null,
            'pay_phone'         => trim((string) $request->input('pay_phone')) ?: null,
            'bank_name'         => trim((string) $request->input('bank_name')) ?: null,
            'bank_branch'       => trim((string) $request->input('bank_branch')) ?: null,
            'bank_account_name' => trim((string) $request->input('bank_account_name')) ?: null,
            'bank_account_no'   => trim((string) $request->input('bank_account_no')) ?: null,

            'notes' => trim((string) $request->input('notes')) ?: null,
        ];
    }
}
