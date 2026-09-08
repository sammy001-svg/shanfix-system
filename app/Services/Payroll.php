<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * Working out what somebody is actually paid.
 *
 * Nothing in this file knows a tax rate. Every band, percentage, floor
 * and ceiling is read from Settings, and the reason is not tidiness:
 * Kenyan payroll law changed three times in three years — NHIF became
 * SHIF, the housing levy arrived, NSSF moved to two tiers on a rising
 * ceiling, and the Tax Laws (Amendment) Act 2024 turned SHIF and the levy
 * from reliefs against tax into deductions from taxable pay. A rate
 * written into code would have gone wrong within a year, silently, on
 * somebody's wages.
 *
 * The order of operations matters as much as the rates:
 *
 *   gross            = basic + every allowance
 *   statutory        = worked out on GROSS (NSSF on pensionable pay,
 *                      SHIF and the levy on gross)
 *   taxable gross    = basic + only the TAXABLE allowances
 *   taxable pay      = taxable gross less whichever statutory deductions
 *                      the law currently allows before PAYE
 *   PAYE             = the bands on taxable pay, less personal relief
 *   net              = gross less everything withheld
 *
 * A non-taxable allowance is therefore paid without being taxed, which is
 * the whole point of one, and getting that backwards would underpay PAYE
 * on every payslip.
 */
class Payroll
{
    /**
     * Every figure the calculation uses, read once.
     *
     * Returned as one array so a payslip can be worked out against a
     * single consistent set rather than re-reading Settings per employee
     * halfway through a run.
     */
    public static function rates(): array
    {
        $bands = json_decode((string) Settings::get('payroll_paye_bands', '[]'), true);

        if (!is_array($bands) || !$bands) {
            // Without bands there is no tax. Better a visible nil than an
            // invented rate, and the screen warns while rates are
            // unconfirmed anyway.
            $bands = [];
        }

        $preTax = json_decode((string) Settings::get('payroll_pre_tax_deductions', '[]'), true);

        return [
            'confirmed'        => Settings::bool('payroll_rates_confirmed', false),
            'bands'            => $bands,
            'personal_relief'  => (float) Settings::get('payroll_personal_relief', 0),
            'insurance_rate'   => (float) Settings::get('payroll_insurance_relief_rate', 0),
            'insurance_cap'    => (float) Settings::get('payroll_insurance_relief_cap', 0),

            'nssf_on'          => Settings::bool('payroll_nssf_enabled', false),
            'nssf_rate'        => (float) Settings::get('payroll_nssf_rate', 0),
            'nssf_lel'         => (float) Settings::get('payroll_nssf_lel', 0),
            'nssf_uel'         => (float) Settings::get('payroll_nssf_uel', 0),

            'shif_on'          => Settings::bool('payroll_shif_enabled', false),
            'shif_rate'        => (float) Settings::get('payroll_shif_rate', 0),
            'shif_min'         => (float) Settings::get('payroll_shif_min', 0),

            'housing_on'       => Settings::bool('payroll_housing_enabled', false),
            'housing_rate'     => (float) Settings::get('payroll_housing_rate', 0),
            'housing_employer' => (float) Settings::get('payroll_housing_employer_rate', 0),

            'pre_tax'          => is_array($preTax) ? $preTax : [],
            'dp'               => (int) Settings::get('payroll_round_dp', 0),
        ];
    }

    /**
     * PAYE on a taxable figure, from the bands, before any relief.
     *
     * Each band names the ceiling it runs up to and the rate on the slice
     * below it; the last has no ceiling. Worked out slice by slice rather
     * than by finding "the band somebody is in", because the whole of a
     * salary is not taxed at the top rate — only the part above the
     * previous ceiling is.
     */
    public static function payeOn(float $taxable, array $bands): float
    {
        if ($taxable <= 0 || !$bands) {
            return 0.0;
        }

        $tax   = 0.0;
        $floor = 0.0;

        foreach ($bands as $band) {
            $rate = (float) ($band['rate'] ?? 0);
            $upto = $band['upto'] ?? null;

            // The top band: everything left, at its rate.
            if ($upto === null) {
                $tax += max(0.0, $taxable - $floor) * $rate / 100;
                break;
            }

            $upto  = (float) $upto;
            $slice = min($taxable, $upto) - $floor;

            if ($slice > 0) {
                $tax += $slice * $rate / 100;
            }

            $floor = $upto;

            if ($taxable <= $upto) {
                break;
            }
        }

        return $tax;
    }

    /**
     * NSSF, both tiers.
     *
     * Tier I is the rate on everything up to the lower limit; tier II the
     * same rate on the slice between the lower and upper limits. Nothing
     * above the upper limit attracts it. The employer pays the same again,
     * which is our cost and not the employee's deduction.
     *
     * @return array{employee: float, employer: float}
     */
    public static function nssfOn(float $pensionable, array $r): array
    {
        if (!$r['nssf_on'] || $pensionable <= 0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $tier1 = min($pensionable, $r['nssf_lel']);
        $tier2 = max(0.0, min($pensionable, $r['nssf_uel']) - $r['nssf_lel']);

        $employee = ($tier1 + $tier2) * $r['nssf_rate'] / 100;

        return ['employee' => $employee, 'employer' => $employee];
    }

    /** Health insurance: a percentage of gross, with a floor and no ceiling. */
    public static function shifOn(float $gross, array $r): float
    {
        if (!$r['shif_on'] || $gross <= 0) {
            return 0.0;
        }

        return max($r['shif_min'], $gross * $r['shif_rate'] / 100);
    }

    /**
     * The housing levy, employee and employer.
     *
     * @return array{employee: float, employer: float}
     */
    public static function housingOn(float $gross, array $r): array
    {
        if (!$r['housing_on'] || $gross <= 0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        return [
            'employee' => $gross * $r['housing_rate'] / 100,
            'employer' => $gross * $r['housing_employer'] / 100,
        ];
    }

    /**
     * One person's pay for one month.
     *
     * @param  array $employee  a row from employees
     * @param  array $items     their active allowances and deductions
     * @param  array $r         rates(), passed in so a whole run uses one set
     * @return array everything a payslip records, plus the lines behind it
     */
    public static function calculate(array $employee, array $items, array $r): array
    {
        $dp    = $r['dp'];
        $round = static fn(float $n): float => round($n, $dp);

        $basic = (float) ($employee['basic_salary'] ?? 0);

        $allowances      = 0.0;
        $taxableAllow    = 0.0;
        $otherDeductions = 0.0;
        $insurance       = 0.0;
        $lines           = [];
        $sort            = 0;

        foreach ($items as $item) {
            $amount = (float) $item['amount'];

            if ($amount <= 0) {
                continue;
            }

            if ($item['kind'] === 'allowance') {
                $allowances += $amount;

                if ((int) $item['taxable'] === 1) {
                    $taxableAllow += $amount;
                }

                $lines[] = [
                    'kind'       => 'allowance',
                    'name'       => $item['name'],
                    'amount'     => $round($amount),
                    'taxable'    => (int) $item['taxable'],
                    'sort_order' => $sort++,
                ];

                continue;
            }

            // An insurance premium is a deduction like any other, and also
            // earns relief against the tax. Recognised by name because
            // there is no separate kind for it and adding one would make
            // every other deduction need a category it does not have.
            if (stripos((string) $item['name'], 'insurance') !== false) {
                $insurance += $amount;
            }

            $otherDeductions += $amount;

            $lines[] = [
                'kind'       => 'deduction',
                'name'       => $item['name'],
                'amount'     => $round($amount),
                'taxable'    => 0,
                'sort_order' => $sort++,
            ];
        }

        $gross = $basic + $allowances;

        // Statutory contributions are worked out on gross pay, whatever
        // part of it is taxable.
        $nssf    = self::nssfOn($gross, $r);
        $shif    = self::shifOn($gross, $r);
        $housing = self::housingOn($gross, $r);

        // Which of them come off before PAYE is the setting most likely to
        // be wrong, and the one that moves everybody's tax when it is.
        $preTax = 0.0;

        if (in_array('nssf', $r['pre_tax'], true))    { $preTax += $nssf['employee']; }
        if (in_array('shif', $r['pre_tax'], true))    { $preTax += $shif; }
        if (in_array('housing', $r['pre_tax'], true)) { $preTax += $housing['employee']; }

        // Only the taxable part of pay is taxed: a non-taxable allowance
        // is paid without ever entering this figure.
        $taxableGross = $basic + $taxableAllow;
        $taxablePay   = max(0.0, $taxableGross - $preTax);

        $payeGross = self::payeOn($taxablePay, $r['bands']);

        $insuranceRelief = $insurance > 0
            ? min($r['insurance_cap'], $insurance * $r['insurance_rate'] / 100)
            : 0.0;

        // Relief cannot turn into a refund: the worst it does is take the
        // tax to nil.
        $paye = max(0.0, $payeGross - $r['personal_relief'] - $insuranceRelief);

        $totalDeductions = $nssf['employee'] + $shif + $housing['employee'] + $paye + $otherDeductions;
        $net             = $gross - $totalDeductions;

        foreach ([
            ['NSSF',          $nssf['employee']],
            ['SHIF',          $shif],
            ['Housing levy',  $housing['employee']],
            ['PAYE',          $paye],
        ] as [$name, $amount]) {
            if ($amount > 0.004) {
                $lines[] = [
                    'kind'       => 'statutory',
                    'name'       => $name,
                    'amount'     => $round($amount),
                    'taxable'    => 0,
                    'sort_order' => $sort++,
                ];
            }
        }

        return [
            'basic'            => $round($basic),
            'allowances'       => $round($allowances),
            'gross'            => $round($gross),

            'nssf'             => $round($nssf['employee']),
            'shif'             => $round($shif),
            'housing_levy'     => $round($housing['employee']),

            'taxable_pay'      => $round($taxablePay),
            'paye_gross'       => $round($payeGross),
            'personal_relief'  => $round(min($r['personal_relief'], $payeGross)),
            'insurance_relief' => $round($insuranceRelief),
            'paye'             => $round($paye),

            'other_deductions' => $round($otherDeductions),
            'total_deductions' => $round($totalDeductions),
            'net_pay'          => $round($net),

            'employer_nssf'    => $round($nssf['employer']),
            'employer_housing' => $round($housing['employer']),

            'lines'            => $lines,
        ];
    }

    /**
     * The allowances and deductions in force for somebody in a month.
     *
     * A one-off runs in its own month and then stops; a recurring item
     * runs until somebody ends it. Both are bounded by their own dates so
     * a run for an earlier month does not pick up something added since.
     */
    public static function itemsFor(int $employeeId, string $period): array
    {
        $first = $period . '-01';
        $last  = date('Y-m-t', (int) strtotime($first));

        return Database::all(
            'SELECT kind, name, amount, taxable
               FROM employee_pay_items
              WHERE employee_id = :e
                AND is_active = 1
                AND (starts_on IS NULL OR starts_on <= :last)
                AND (ends_on   IS NULL OR ends_on   >= :first)
           ORDER BY kind DESC, id',
            ['e' => $employeeId, 'last' => $last, 'first' => $first]
        );
    }

    /** Everyone who should be on a run for this month. */
    public static function payableFor(string $period): array
    {
        $first = $period . '-01';
        $last  = date('Y-m-t', (int) strtotime($first));

        return Database::all(
            "SELECT * FROM employees
              WHERE status IN ('active', 'on_leave')
                AND (started_on IS NULL OR started_on <= :last)
                AND (ended_on   IS NULL OR ended_on   >= :first)
           ORDER BY name",
            ['last' => $last, 'first' => $first]
        );
    }
}
