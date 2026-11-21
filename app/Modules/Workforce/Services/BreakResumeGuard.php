<?php

namespace App\Modules\Workforce\Services;

use App\Core\Models\Company;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use App\Core\Workforce\TimeEntry;
use Illuminate\Http\Request;

/**
 * ADR-848 (Leezr 4.2 M9) — break rules for the self-service clock and the EARLY-RESUME policy, moved verbatim
 * from EmployeeSelfController (server-enforced, driven by the rules engine — never a Vue constant).
 */
class BreakResumeGuard
{
    /** Resolved break rules, flattened by the rules engine (never Vue constants). */
    public static function rules(int $companyId): array
    {
        $company = Company::withoutGlobalScopes()->find($companyId);

        return $company ? app(WorkforceRuleResolver::class)->breakRulesForClient($company) : [];
    }

    /**
     * Refusal payload when the break may not end yet, null otherwise. 'block' always refuses ; 'approve' refuses
     * for self-service (needs a manager with workforce.time_manage) ; 'warn' / 'allow' proceed (the UI warns).
     */
    public static function refusal(Request $request, TimeEntry $entry, int $companyId): ?array
    {
        $rules = self::rules($companyId);
        $minMinutes = $rules['min_duration_minutes'] ?? null;
        $policy = $rules['early_resume_policy'] ?? 'warn';
        $openBreak = $entry->breaks()->whereNull('end_at')->latest('start_at')->first();
        $elapsedMin = $openBreak?->start_at ? $openBreak->start_at->diffInMinutes(now()) : null;

        if ($minMinutes === null || $elapsedMin === null || $elapsedMin >= $minMinutes) {
            return null;
        }
        $canApprove = $request->user()?->hasCompanyPermission($request->attributes->get('company'), 'workforce.time_manage') ?? false;
        if ($policy === 'block' || ($policy === 'approve' && ! $canApprove)) {
            return [
                'message' => "Minimum break not reached ({$elapsedMin}/{$minMinutes} min).",
                'code' => $policy === 'approve' ? 'break_needs_approval' : 'break_min_not_reached',
                'elapsed_minutes' => $elapsedMin,
                'min_minutes' => $minMinutes,
                'policy' => $policy,
            ];
        }

        return null;
    }
}
