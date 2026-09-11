<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Settings;

/**
 * HR Overview Dashboard.
 *
 * Brings together Staff headcount, Payroll status, and Equipment service health
 * into a single high-level operations hub.
 */
class HrDashboardController extends Controller
{
    public function index(Request $request): void
    {
        // Viewable if user has permission to view staff, payroll, or equipment
        if (!Auth::can('hr.view') && !Auth::can('payroll.view') && !Auth::can('equipment.view')) {
            $this->authorize('hr.view');
        }

        $warnDays = (int) Settings::get('equipment_service_warn_days', 14);
        $horizon  = date('Y-m-d', strtotime('+' . $warnDays . ' days'));

        // 1. Staff Headcount & Breakdown
        $staffCounts = Database::first(
            "SELECT
                COUNT(CASE WHEN status = 'active' THEN 1 END)    AS active_count,
                COUNT(CASE WHEN status = 'on_leave' THEN 1 END)  AS on_leave_count,
                COUNT(CASE WHEN status = 'suspended' THEN 1 END) AS suspended_count,
                COUNT(CASE WHEN status = 'left' THEN 1 END)      AS left_count,
                COUNT(*)                                         AS total_all
               FROM employees"
        );

        $departmentBreakdown = Database::all(
            "SELECT COALESCE(department, 'Unassigned') AS dept, COUNT(*) AS cnt
               FROM employees
              WHERE status <> 'left'
           GROUP BY department
           ORDER BY cnt DESC"
        );

        $typeBreakdown = Database::all(
            "SELECT employment_type, COUNT(*) AS cnt
               FROM employees
              WHERE status <> 'left'
           GROUP BY employment_type
           ORDER BY cnt DESC"
        );

        // 2. Upcoming Work Anniversaries (This Month)
        $currentMonth = date('m');
        $anniversaries = Database::all(
            "SELECT id, name, job_title, started_on,
                    (YEAR(CURDATE()) - YEAR(started_on)) AS years_served
               FROM employees
              WHERE status <> 'left'
                AND started_on IS NOT NULL
                AND MONTH(started_on) = :m
           ORDER BY DAY(started_on) ASC
              LIMIT 10",
            ['m' => $currentMonth]
        );

        // 3. Payroll Status (Current & Recent Runs)
        $latestPayrollRun = Database::first(
            "SELECT r.*, u.name AS created_by_name
               FROM payroll_runs r
          LEFT JOIN users u ON u.id = r.created_by
           ORDER BY r.id DESC LIMIT 1"
        );

        $currentPeriod = date('Y-m');
        $currentRun = Database::first(
            "SELECT * FROM payroll_runs WHERE period = :p",
            ['p' => $currentPeriod]
        );

        // 4. Equipment Service Health
        $equipmentStats = Database::first(
            "SELECT
                COUNT(CASE WHEN status <> 'disposed' THEN 1 END) AS total_active,
                COUNT(CASE WHEN status = 'under_repair' THEN 1 END) AS under_repair,
                COUNT(CASE WHEN status <> 'disposed' AND next_service_on IS NOT NULL AND next_service_on <= :h THEN 1 END) AS due_service
               FROM equipment",
            ['h' => $horizon]
        );

        $equipmentDue = Database::all(
            "SELECT e.*, emp.name AS assignee
               FROM equipment e
          LEFT JOIN employees emp ON emp.id = e.assigned_to
              WHERE e.status <> 'disposed'
                AND e.next_service_on IS NOT NULL
                AND e.next_service_on <= :h
           ORDER BY e.next_service_on ASC
              LIMIT 8",
            ['h' => $horizon]
        );

        // 5. Recent HR-related Audit Log
        $recentLogs = Database::all(
            "SELECT a.*, u.name AS user_name
               FROM activity_logs a
          LEFT JOIN users u ON u.id = a.user_id
              WHERE a.entity_type IN ('employee', 'payroll_run', 'equipment')
           ORDER BY a.id DESC LIMIT 8"
        );

        $this->view('hr/dashboard', [
            'title'               => 'HR Overview',
            'staffCounts'         => $staffCounts,
            'departmentBreakdown' => $departmentBreakdown,
            'typeBreakdown'       => $typeBreakdown,
            'anniversaries'       => $anniversaries,
            'latestPayrollRun'    => $latestPayrollRun,
            'currentRun'          => $currentRun,
            'currentPeriod'       => $currentPeriod,
            'equipmentStats'      => $equipmentStats,
            'equipmentDue'        => $equipmentDue,
            'recentLogs'          => $recentLogs,
            'warnDays'            => $warnDays,
        ]);
    }
}
