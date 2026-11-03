<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\Signature\ReadModels\SignatureReadModel;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * PRODUCT-UX-3 / P2-A (ADR-682) — authorized, manager-scoped home priorities.
 *
 * Aggregates "what needs my attention" from the owning modules' read models,
 * built SERVER-SIDE per the viewer's authorization (RBAC + ModuleGate + manager
 * scope). Explicitly whitelisted output fields (counts + routing only) — NO
 * salary amount is ever returned, so this cannot bypass the C2/C3 confidentiality.
 */
class HomePrioritiesReadModel
{
    public static function forRequest(Request $request): array
    {
        $companyId = (int) $request->attributes->get('company_id');
        $company = Company::find($companyId);
        $today = Carbon::now();

        $priorities = [];
        $moduleActive = fn (string $m) => $company && ModuleGate::isActive($company, $m);
        $can = fn (string $p) => WorkforceAccess::can($request, $p);

        // Cockpit summary (presence + anomalies + pending timesheets), manager-scoped.
        $timeScope = WorkforceAccess::scopeIdsOrNull($request, 'time');
        $cockpit = ($can('workforce.timesheet_view'))
            ? TimeCockpitReadModel::summary($companyId, $today, $timeScope)
            : null;

        // 1. Leave requests to approve (manager-scoped).
        if ($can('workforce.leave_approve')) {
            $leaveScope = WorkforceAccess::scopeIdsOrNull($request, 'leave');
            $count = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('status', LeaveRequest::STATUS_PENDING)
                ->when($leaveScope !== null, fn ($q) => $q->whereIn('employee_id', $leaveScope))
                ->count();
            $priorities[] = self::item('leave_to_approve', $count, 'warning', 'tabler-calendar-off', [
                'name' => 'company-workforce-leave',
            ]);
        }

        // 2. Timesheets to approve (manager-scoped).
        if ($can('workforce.timesheet_approve')) {
            $count = $cockpit['pending_timesheets'] ?? TimesheetPeriod::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('status', TimesheetPeriod::STATUS_SUBMITTED)
                ->when($timeScope !== null, fn ($q) => $q->whereIn('employee_id', $timeScope))
                ->count();
            $priorities[] = self::item('timesheets_to_approve', $count, 'warning', 'tabler-file-time', [
                'name' => 'company-workforce-time', 'query' => ['tab' => 'timesheets'],
            ]);
        }

        // 3. Clock-in anomalies today (manager-scoped).
        if ($can('workforce.timesheet_view') && $cockpit) {
            $count = (int) ($cockpit['anomalies'] ?? 0) + (int) ($cockpit['incomplete'] ?? 0);
            $priorities[] = self::item('time_anomalies', $count, 'error', 'tabler-alert-triangle', [
                'name' => 'company-workforce-time',
            ]);
        }

        // 4. Signatures pending (module + manager-scoped).
        if ($moduleActive('workforce_signature') && $can('workforce_signature.view')) {
            $count = SignatureReadModel::scopedQuery($request, $companyId)
                ->where('status', 'pending')->count();
            $priorities[] = self::item('signatures_pending', $count, 'info', 'tabler-signature', [
                'name' => 'company-workforce-documents', 'query' => ['tab' => 'signatures'],
            ]);
        }

        // 5. Payroll periods to control (NO amounts — counts only). Company-wide (management).
        if ($moduleActive('workforce_payroll') && $can('workforce.payroll_prepare')) {
            $count = PayrollRun::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('status', [PayrollRun::STATUS_DRAFT, PayrollRun::STATUS_COMPUTED])
                ->count();
            $priorities[] = self::item('payroll_to_control', $count, 'info', 'tabler-receipt', [
                'name' => 'company-workforce-payroll',
            ]);
        }

        // 6. Onboarding incomplete: active employees without an active contract.
        if ($can('workforce.view')) {
            $withContract = EmploymentContract::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('status', 'active')
                ->pluck('employee_id')->unique();
            $count = Employee::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('status', Employee::STATUS_ACTIVE)
                ->whereNotIn('id', $withContract->all() ?: [-1])
                ->count();
            $priorities[] = self::item('onboarding_incomplete', $count, 'warning', 'tabler-user-plus', [
                'name' => 'company-workforce-employees',
            ]);
        }

        // Keep only actionable priorities (count > 0).
        $active = array_values(array_filter($priorities, fn ($p) => $p['count'] > 0));

        // Situation (overview) — gated by management view; never salary.
        $situation = null;
        if ($can('workforce.view')) {
            $situation = [
                'active_employees' => Employee::withoutGlobalScopes()
                    ->where('company_id', $companyId)->where('status', Employee::STATUS_ACTIVE)->count(),
                'present_today' => $cockpit
                    ? (int) ($cockpit['working'] ?? 0) + (int) ($cockpit['on_break'] ?? 0) + (int) ($cockpit['completed'] ?? 0)
                    : null,
                'not_clocked_today' => $cockpit['not_clocked'] ?? null,
            ];
        }

        return [
            'scope' => WorkforceAccess::hasViewAll($request, 'employee') ? 'all' : 'team',
            'priorities' => $active,
            'all_clear' => count($active) === 0,
            'situation' => $situation,
        ];
    }

    private static function item(string $key, int $count, string $severity, string $icon, array $to): array
    {
        return ['key' => $key, 'count' => (int) $count, 'severity' => $severity, 'icon' => $icon, 'to' => $to];
    }
}
