<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\DsnDeclarationReadModel;
use App\Modules\Workforce\ReadModels\GeneratedDocumentReadModel;
use App\Modules\Workforce\ReadModels\LeaveRequestReadModel;
use App\Modules\Workforce\ReadModels\PayrollRunReadModel;
use App\Modules\Workforce\ReadModels\ShiftReadModel;
use App\Modules\Workforce\ReadModels\TimesheetPeriodReadModel;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkforceDashboardController extends Controller
{
    public function statistics(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $now = Carbon::now();
        $currentMonthStart = $now->copy()->startOfMonth()->toDateString();
        $currentMonthEnd = $now->copy()->endOfMonth()->toDateString();
        $currentYear = $now->year;

        // Employee overview
        $totalEmployees = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->count();

        $activeEmployees = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->count();

        $activeContracts = EmploymentContract::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->count();

        // Module statistics
        $timesheetStats = TimesheetPeriodReadModel::statistics(
            companyId: $companyId,
            periodStart: $currentMonthStart,
            periodEnd: $currentMonthEnd,
        );

        $shiftStats = ShiftReadModel::statistics(
            companyId: $companyId,
            from: $now->copy()->startOfWeek(),
            to: $now->copy()->endOfWeek(),
        );

        $leaveStats = LeaveRequestReadModel::statistics(
            companyId: $companyId,
            year: $currentYear,
        );

        $payrollStats = PayrollRunReadModel::statistics($companyId);
        $dsnStats = DsnDeclarationReadModel::statistics($companyId);
        $documentStats = GeneratedDocumentReadModel::statistics($companyId);

        // Pending counts for badges
        $pendingLeaves = 0;
        if (isset($leaveStats['pending'])) {
            $pendingLeaves = $leaveStats['pending']['count'] ?? 0;
        }

        $draftPayrolls = 0;
        if (isset($payrollStats['by_status']['draft'])) {
            $draftPayrolls = $payrollStats['by_status']['draft']['count'] ?? 0;
        }

        $pendingDsn = 0;
        foreach (['draft', 'validated', 'exported'] as $status) {
            if (isset($dsnStats['by_status'][$status])) {
                $pendingDsn += $dsnStats['by_status'][$status];
            }
        }

        return response()->json([
            'employees' => [
                'total' => $totalEmployees,
                'active' => $activeEmployees,
                'active_contracts' => $activeContracts,
            ],
            'timesheets' => $timesheetStats,
            'shifts' => $shiftStats,
            'leave' => $leaveStats,
            'payroll' => $payrollStats,
            'dsn' => $dsnStats,
            'documents' => $documentStats,
            'badges' => [
                'pending_leaves' => $pendingLeaves,
                'draft_payrolls' => $draftPayrolls,
                'pending_dsn' => $pendingDsn,
                'timesheet_completion' => $timesheetStats['completion_rate'] ?? 0,
            ],
        ]);
    }
}
