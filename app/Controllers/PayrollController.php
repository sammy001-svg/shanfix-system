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
use App\Services\Payouts;
use App\Services\Payroll;

/**
 * Paying the people who work here.
 *
 * Shaped like the partner payout deliberately, because it is the same
 * problem: a run is worked out, checked, approved by somebody other than
 * whoever prepared it, and only then paid — and what is recorded is what
 * was calculated at the time, not a recipe to recompute later.
 *
 * Nothing here knows a tax rate. Every band and percentage is a setting,
 * and until somebody has confirmed them against KRA the screen says so on
 * every page.
 */
class PayrollController extends Controller
{
    public function index(Request $request): void
    {
        $this->authorize('payroll.view');

        $rates  = Payroll::rates();
        $period = (string) $request->query('period', '');

        if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $period) !== 1) {
            // Last month by default: this month is not finished, and a run
            // against a month still being worked is not a run.
            $period = date('Y-m', (int) strtotime('first day of last month'));
        }

        $this->view('payroll/index', [
            'title'   => 'Payroll',
            'runs'    => Database::all(
                'SELECT r.*, u.name AS created_name
                   FROM payroll_runs r
              LEFT JOIN users u ON u.id = r.created_by
               ORDER BY r.id DESC LIMIT 24'
            ),
            'period'   => $period,
            'existing' => $this->runFor($period),
            'payable'  => Payroll::payableFor($period),
            'rates'    => $rates,
        ]);
    }

    /**
     * Work out a month.
     *
     * Every payslip is calculated against one reading of the rates, taken
     * once here, so a run cannot be half on yesterday's figures and half
     * on today's if somebody edits them while it builds.
     */
    public function build(Request $request): void
    {
        $this->authorize('payroll.run');

        $period = trim((string) $request->input('period'));

        if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $period) !== 1) {
            Session::error('Pick a month to run.');
            Response::to('/payroll');
        }

        if ($this->runFor($period)) {
            Session::warning('There is already a run for ' . $period . '. Delete it first if you want to work it out again.');
            Response::to('/payroll');
        }

        $people = Payroll::payableFor($period);

        if (!$people) {
            Session::warning('Nobody was employed in ' . $period . '.');
            Response::to('/payroll?period=' . $period);
        }

        $rates = Payroll::rates();

        $runId = Database::transaction(static function () use ($period, $people, $rates): int {
            $runId = Database::insert('payroll_runs', [
                'period'     => $period,
                'status'     => 'draft',
                'created_by' => Auth::id(),
            ]);

            $totals = [
                'gross' => 0.0, 'paye' => 0.0, 'nssf' => 0.0, 'shif' => 0.0,
                'housing' => 0.0, 'other' => 0.0, 'net' => 0.0,
                'er_nssf' => 0.0, 'er_housing' => 0.0,
            ];

            foreach ($people as $person) {
                $items = Payroll::itemsFor((int) $person['id'], $period);
                $slip  = Payroll::calculate($person, $items, $rates);
                $where = Payouts::destinationFor($person + ['status' => 'active']);

                $payslipId = Database::insert('payslips', [
                    'run_id'          => $runId,
                    'employee_id'     => (int) $person['id'],
                    'employee_name'   => $person['name'],
                    'employee_number' => $person['employee_number'],
                    'job_title'       => $person['job_title'],
                    'kra_pin'         => $person['kra_pin'],

                    'basic'            => $slip['basic'],
                    'allowances'       => $slip['allowances'],
                    'gross'            => $slip['gross'],
                    'nssf'             => $slip['nssf'],
                    'shif'             => $slip['shif'],
                    'housing_levy'     => $slip['housing_levy'],
                    'taxable_pay'      => $slip['taxable_pay'],
                    'paye_gross'       => $slip['paye_gross'],
                    'personal_relief'  => $slip['personal_relief'],
                    'insurance_relief' => $slip['insurance_relief'],
                    'paye'             => $slip['paye'],
                    'other_deductions' => $slip['other_deductions'],
                    'total_deductions' => $slip['total_deductions'],
                    'net_pay'          => $slip['net_pay'],
                    'employer_nssf'    => $slip['employer_nssf'],
                    'employer_housing' => $slip['employer_housing'],

                    // Cash is a real answer on a print floor, so it is not
                    // treated as a missing destination.
                    'pay_method'   => $person['pay_method'] ?: null,
                    'destination'  => $person['pay_method'] === 'cash' ? 'Cash' : $where['destination'],
                    'account_name' => $where['name'],
                ]);

                foreach ($slip['lines'] as $line) {
                    Database::insert('payslip_lines', $line + ['payslip_id' => $payslipId]);
                }

                $totals['gross']      += $slip['gross'];
                $totals['paye']       += $slip['paye'];
                $totals['nssf']       += $slip['nssf'];
                $totals['shif']       += $slip['shif'];
                $totals['housing']    += $slip['housing_levy'];
                $totals['other']      += $slip['other_deductions'];
                $totals['net']        += $slip['net_pay'];
                $totals['er_nssf']    += $slip['employer_nssf'];
                $totals['er_housing'] += $slip['employer_housing'];
            }

            Database::update('payroll_runs', [
                'employee_count'         => count($people),
                'total_gross'            => round($totals['gross'], 2),
                'total_paye'             => round($totals['paye'], 2),
                'total_nssf'             => round($totals['nssf'], 2),
                'total_shif'             => round($totals['shif'], 2),
                'total_housing'          => round($totals['housing'], 2),
                'total_other'            => round($totals['other'], 2),
                'total_net'              => round($totals['net'], 2),
                'total_employer_nssf'    => round($totals['er_nssf'], 2),
                'total_employer_housing' => round($totals['er_housing'], 2),
            ], ['id' => $runId]);

            return $runId;
        });

        ActivityLog::record('payroll_built', 'payroll_run', $runId, 'Worked out payroll for ' . $period);

        Session::success('Worked out ' . count($people) . ' payslip' . (count($people) === 1 ? '' : 's') . '. Check them before approving.');
        Response::to('/payroll/' . $runId);
    }

    public function show(Request $request): void
    {
        $this->authorize('payroll.view');

        $run = $this->findRun($request->paramInt('id'));

        $this->view('payroll/show', [
            'title'    => 'Payroll — ' . $run['period'],
            'run'      => $run,
            'payslips' => Database::all(
                'SELECT * FROM payslips WHERE run_id = :r ORDER BY employee_name',
                ['r' => $run['id']]
            ),
            'rates' => Payroll::rates(),
        ]);
    }

    /** One payslip, in full, for printing or handing over. */
    public function payslip(Request $request): void
    {
        $this->authorize('payroll.view');

        $slip = Database::first(
            'SELECT p.*, r.period, r.status AS run_status
               FROM payslips p
               JOIN payroll_runs r ON r.id = p.run_id
              WHERE p.id = :id',
            ['id' => $request->paramInt('id')]
        );

        if (!$slip) {
            throw new HttpException(404, 'That payslip does not exist.');
        }

        $this->view('payroll/payslip', [
            'title'   => 'Payslip — ' . $slip['employee_name'],
            'slip'    => $slip,
            'lines'   => Database::all(
                'SELECT * FROM payslip_lines WHERE payslip_id = :p ORDER BY sort_order, id',
                ['p' => $slip['id']]
            ),
            'company' => Settings::company(),
        ], 'print');
    }

    /**
     * Sign it off.
     *
     * A different authority from working it out, and deliberately so: the
     * person who decides what everybody is paid should not be the only
     * person who has looked at it.
     */
    public function approve(Request $request): void
    {
        $this->authorize('payroll.approve');

        $run = $this->findRun($request->paramInt('id'));

        if ($run['status'] !== 'draft') {
            Session::error('That run has already been approved.');
            Response::to('/payroll/' . $run['id']);
        }

        if (!Settings::bool('payroll_rates_confirmed', false)) {
            Session::error(
                'The tax rates have not been confirmed yet. Check them against KRA '
                . 'on the rates page and confirm them before approving a payroll.'
            );
            Response::to('/payroll/rates');
        }

        Database::update('payroll_runs', [
            'status'      => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => date('Y-m-d H:i:s'),
        ], ['id' => $run['id']]);

        ActivityLog::record(
            'payroll_approved',
            'payroll_run',
            (int) $run['id'],
            'Approved ' . money($run['total_net']) . ' of net pay for ' . $run['period']
        );

        Session::success('Approved. Download the payment file and pay it.');
        Response::to('/payroll/' . $run['id']);
    }

    /** Say the money has gone. */
    public function markPaid(Request $request): void
    {
        $this->authorize('payroll.pay');

        $run = $this->findRun($request->paramInt('id'));
        $ref = trim((string) $request->input('ref'));

        if ($run['status'] !== 'approved') {
            Session::error('That run is not approved yet.');
            Response::to('/payroll/' . $run['id']);
        }

        if ($ref === '') {
            Session::error('Put the payment reference in, so this can be traced later.');
            Response::to('/payroll/' . $run['id']);
        }

        Database::run(
            "UPDATE payslips SET status = 'paid', ref = :r, paid_at = NOW()
              WHERE run_id = :run AND status = 'pending'",
            ['r' => $ref, 'run' => $run['id']]
        );

        Database::update('payroll_runs', [
            'status'  => 'paid',
            'paid_at' => date('Y-m-d H:i:s'),
        ], ['id' => $run['id']]);

        ActivityLog::record(
            'payroll_paid',
            'payroll_run',
            (int) $run['id'],
            'Paid ' . money($run['total_net']) . ' for ' . $run['period'] . ' (' . $ref . ')'
        );

        Session::success('Recorded as paid against ' . $ref . '.');
        Response::to('/payroll/' . $run['id']);
    }

    /** The file the bank gets, for whichever method. */
    public function export(Request $request): void
    {
        $this->authorize('payroll.pay');

        $run  = $this->findRun($request->paramInt('id'));
        $kind = (string) $request->param('kind');

        if (!in_array($kind, ['mpesa', 'bank'], true)) {
            throw new HttpException(404, 'There is no such payment file.');
        }

        $slips = Database::all(
            "SELECT p.*, e.bank_name, e.bank_branch
               FROM payslips p
               JOIN employees e ON e.id = p.employee_id
              WHERE p.run_id = :r AND p.pay_method = :m AND p.status = 'pending'
           ORDER BY p.employee_name",
            ['r' => $run['id'], 'm' => $kind]
        );

        if (!$slips) {
            Session::warning('There is nobody left to pay by ' . ($kind === 'mpesa' ? 'M-Pesa' : 'bank transfer') . '.');
            Response::to('/payroll/' . $run['id']);
        }

        $narration = 'Salary ' . $run['period'];
        $rows      = [];

        if ($kind === 'mpesa') {
            $headers = ['Phone', 'Amount', 'Name', 'Reference', 'Narration'];

            foreach ($slips as $s) {
                $rows[] = [
                    Payouts::msisdn((string) $s['destination']),
                    number_format((float) $s['net_pay'], 2, '.', ''),
                    $s['employee_name'],
                    $s['employee_number'],
                    $narration,
                ];
            }
        } else {
            $headers = ['Account Name', 'Account Number', 'Bank', 'Branch', 'Amount', 'Reference', 'Narration'];

            foreach ($slips as $s) {
                $rows[] = [
                    $s['account_name'] ?: $s['employee_name'],
                    (string) $s['destination'],
                    (string) $s['bank_name'],
                    (string) $s['bank_branch'],
                    number_format((float) $s['net_pay'], 2, '.', ''),
                    $s['employee_number'],
                    $narration,
                ];
            }
        }

        ActivityLog::record('payroll_exported', 'payroll_run', (int) $run['id'], 'Downloaded the ' . $kind . ' salary file');

        Response::csv('salaries-' . $run['period'] . '-' . $kind . '.csv', $headers, $rows);
    }

    /** Throw a draft away and start again. */
    public function destroy(Request $request): void
    {
        $this->authorize('payroll.run');

        $run = $this->findRun($request->paramInt('id'));

        if ($run['status'] !== 'draft') {
            Session::error('Only a draft can be thrown away. An approved run is a record.');
            Response::to('/payroll/' . $run['id']);
        }

        Database::run('DELETE FROM payroll_runs WHERE id = :id', ['id' => $run['id']]);

        ActivityLog::record('payroll_discarded', 'payroll_run', (int) $run['id'], 'Discarded the draft for ' . $run['period']);

        Session::success('Thrown away. Work it out again when you are ready.');
        Response::to('/payroll');
    }

    // -- The law, as settings -----------------------------------------------

    public function rates(Request $request): void
    {
        $this->authorize('payroll.view');

        $this->view('payroll/rates', [
            'title' => 'Tax rates and bands',
            'rates' => Payroll::rates(),
            'raw'   => [
                'bands'   => (string) Settings::get('payroll_paye_bands', '[]'),
                'pre_tax' => (string) Settings::get('payroll_pre_tax_deductions', '[]'),
            ],
        ]);
    }

    public function saveRates(Request $request): void
    {
        // Changing a tax rate changes what everybody is paid, so it sits
        // with whoever can approve a payroll rather than whoever prepares
        // one.
        $this->authorize('payroll.approve');

        $bands = json_decode((string) $request->input('payroll_paye_bands'), true);

        if (!is_array($bands) || !$bands) {
            Session::error('The bands must be a list. Nothing has been changed.');
            Response::to('/payroll/rates');
        }

        foreach ($bands as $band) {
            if (!is_array($band) || !array_key_exists('rate', $band) || !array_key_exists('upto', $band)) {
                Session::error('Every band needs a rate and an upper limit ("upto": null for the top one). Nothing has been changed.');
                Response::to('/payroll/rates');
            }
        }

        $preTax = json_decode((string) $request->input('payroll_pre_tax_deductions'), true);

        if (!is_array($preTax)) {
            Session::error('The pre-tax list must be a list. Nothing has been changed.');
            Response::to('/payroll/rates');
        }

        Settings::set('payroll_paye_bands', json_encode(array_values($bands)));
        Settings::set('payroll_pre_tax_deductions', json_encode(array_values($preTax)));

        foreach ([
            'payroll_personal_relief', 'payroll_insurance_relief_rate', 'payroll_insurance_relief_cap',
            'payroll_nssf_rate', 'payroll_nssf_lel', 'payroll_nssf_uel',
            'payroll_shif_rate', 'payroll_shif_min',
            'payroll_housing_rate', 'payroll_housing_employer_rate',
            'payroll_round_dp',
        ] as $key) {
            if ($request->input($key) !== null) {
                Settings::set($key, (string) max(0, (float) $request->input($key)));
            }
        }

        foreach (['payroll_nssf_enabled', 'payroll_shif_enabled', 'payroll_housing_enabled'] as $key) {
            Settings::set($key, $request->input($key) !== null ? '1' : '0');
        }

        // The one that matters most: somebody has looked at these against
        // KRA and says they are right. Until it is set, approving a
        // payroll is refused.
        Settings::set('payroll_rates_confirmed', $request->input('payroll_rates_confirmed') !== null ? '1' : '0');

        ActivityLog::record('payroll_rates_saved', 'settings', 0, 'Changed the payroll tax rates');

        Session::success('Saved. These apply to the next run worked out, not to one already calculated.');
        Response::to('/payroll/rates');
    }

    // -- Plumbing ------------------------------------------------------------

    private function runFor(string $period): ?array
    {
        return Database::first(
            'SELECT * FROM payroll_runs WHERE period = :p ORDER BY id DESC LIMIT 1',
            ['p' => $period]
        );
    }

    private function findRun(int $id): array
    {
        $run = Database::first(
            'SELECT r.*, u.name AS created_name, a.name AS approved_name
               FROM payroll_runs r
          LEFT JOIN users u ON u.id = r.created_by
          LEFT JOIN users a ON a.id = r.approved_by
              WHERE r.id = :id',
            ['id' => $id]
        );

        if (!$run) {
            throw new HttpException(404, 'That payroll run does not exist.');
        }

        return $run;
    }
}
