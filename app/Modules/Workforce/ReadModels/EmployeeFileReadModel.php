<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Documents\GeneratedDocument;
use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveBalanceCache;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use Carbon\Carbon;

/**
 * ADR-640 (W-C) — read-model COMPOSITE de la fiche collaborateur : agrège des SYNTHÈSES légères (pas
 * l'historique complet) à partir des sources existantes. Tenant-scoped, conditionné par les modules
 * actifs, et permission-aware (la rémunération n'est incluse que si autorisée). Les détails volumineux
 * (planning, pointages, congés, documents) restent chargés à la demande via les endpoints existants.
 */
class EmployeeFileReadModel
{
    public static function overview(Company $company, Employee $employee, bool $canSeeCompensation): array
    {
        $employee->loadMissing(['department:id,name', 'jobRole:id,title', 'manager:id,first_name,last_name', 'user:id,name,email']);

        $hasPlanning = ModuleGate::isActive($company, 'workforce_planning');
        $hasLeave = ModuleGate::isActive($company, 'workforce_leave');
        $hasDocuments = ModuleGate::isActive($company, 'workforce_documents');
        $hasWorkforce = ModuleGate::isActive($company, 'workforce');

        return [
            'identity' => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'employee_number' => $employee->employee_number,
                'status' => $employee->status,
                'hire_date' => $employee->hire_date?->toDateString(),
            ],
            'position' => [
                'department' => $employee->department?->name,
                'job_role' => $employee->jobRole?->title,
                'manager' => $employee->manager ? trim($employee->manager->first_name.' '.$employee->manager->last_name) : null,
                'establishment' => null, // W-Q : entité établissement non encore livrée
            ],
            'contract' => static::contractSummary($company, $employee, $canSeeCompensation),
            'planning' => $hasPlanning ? static::nextShift($company, $employee) : null,
            'time' => $hasWorkforce ? static::lastClock($company, $employee) : null,
            'leave' => $hasLeave ? static::leaveSummary($company, $employee) : null,
            'documents' => $hasDocuments ? ['count' => GeneratedDocument::withoutGlobalScopes()->where('company_id', $company->id)->where('subject_type', 'employee')->where('subject_id', $employee->id)->count()] : null,
            // W-HR-UX-1 — état d'INTÉGRATION du collaborateur (checklist guidée sur la fiche). Source unique
            // de vérité : évite à la RH de deviner « quelle est la prochaine étape ».
            'onboarding' => static::onboardingState($company, $employee, $hasDocuments),
            'alerts' => static::alerts($company, $employee, $hasLeave),
            'available_sections' => array_values(array_filter([
                'profile', 'position', 'contract',
                $hasPlanning ? 'planning' : null,
                $hasWorkforce ? 'time' : null,
                $hasLeave ? 'leave' : null,
                $hasDocuments ? 'documents' : null,
                $hasLeave ? 'requests' : null,
                'history',
            ])),
        ];
    }

    /**
     * W-HR-UX-3 — états d'intégration FIABLES (vérité métier). L'accès n'est « fait » que si le compte
     * est ACTIVÉ (mot de passe défini), jamais sur une simple invitation. La signature n'est « faite »
     * que sur un document réellement SIGNÉ, jamais sur un simple document généré.
     */
    private static function onboardingState(Company $company, Employee $employee, bool $hasDocuments): array
    {
        $user = $employee->user_id ? \App\Core\Models\User::find($employee->user_id) : null;
        $accountState = 'none';
        if ($user) {
            $accountState = $user->password_set_at ? 'active' : 'invited';
        }

        $signedContract = GeneratedDocument::withoutGlobalScopes()->where('company_id', $company->id)
            ->where('subject_type', 'employee')->where('subject_id', $employee->id)
            ->where('signature_status', GeneratedDocument::SIGNATURE_SIGNED)->exists();

        return [
            'info' => true,
            'contract' => static::contractSummary($company, $employee, false) !== null,
            'signed_contract' => $signedContract,               // SIGNÉ (pas seulement généré)
            'account' => $accountState === 'active',             // ACTIVÉ (pas seulement invité)
            'account_state' => $accountState,                    // none | invited | active
            'documents_module' => $hasDocuments,
            'signature_module' => ModuleGate::isActive($company, 'workforce_signature'),
        ];
    }

    private static function contractSummary(Company $company, Employee $employee, bool $canSeeCompensation): ?array
    {
        $c = $employee->currentContract()->with($canSeeCompensation ? ['compensationPlans' => fn ($q) => $q->activeAt(), 'conventionCollective:id,short_name'] : ['conventionCollective:id,short_name'])->first();
        if (! $c) {
            return null;
        }
        $out = [
            'contract_type' => $c->contract_type,
            'work_model_key' => $c->work_model_key,
            'weekly_hours' => $c->weekly_hours,
            'start_date' => $c->start_date?->toDateString(),
            'status' => $c->status,
            'convention' => $c->conventionCollective?->short_name,
        ];
        if ($canSeeCompensation) {
            $plan = $c->compensationPlans->first();
            // Montant exposé en centimes (le front formate en devise) — jamais de centimes bruts à l'écran.
            $out['compensation'] = $plan ? ['base_salary_cents' => (int) $plan->base_salary_cents, 'currency' => $plan->currency, 'pay_frequency' => $plan->pay_frequency] : null;
        }

        return $out;
    }

    private static function nextShift(Company $company, Employee $employee): ?array
    {
        $s = Shift::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->where('status', '!=', 'cancelled')->whereDate('date', '>=', Carbon::today())
            ->orderBy('start_at')->first(['id', 'date', 'start_at', 'end_at', 'status']);

        return $s ? ['date' => $s->date?->toDateString(), 'start_at' => $s->start_at?->toIso8601String(), 'end_at' => $s->end_at?->toIso8601String(), 'status' => $s->status] : null;
    }

    private static function lastClock(Company $company, Employee $employee): ?array
    {
        $t = TimeEntry::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->orderByDesc('date')->orderByDesc('clock_in')->first(['id', 'date', 'clock_in', 'status']);

        return $t ? ['date' => $t->date?->toDateString(), 'clock_in' => $t->clock_in?->toIso8601String(), 'status' => $t->status] : null;
    }

    private static function leaveSummary(Company $company, Employee $employee): array
    {
        $year = (int) Carbon::today()->year;
        $balances = LeaveBalanceCache::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->where('period_year', $year)->with('leaveType:id,name,code')
            ->get()->map(fn ($b) => ['type' => $b->leaveType?->name ?? $b->leaveType?->code, 'available_hundredths' => (int) $b->cached_available])->all();
        $pending = LeaveRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_PENDING)->count();

        return ['balances' => $balances, 'pending_requests' => $pending, 'year' => $year];
    }

    private static function alerts(Company $company, Employee $employee, bool $hasLeave): array
    {
        $alerts = [];
        $current = $employee->currentContract;
        if (! $current) {
            $alerts[] = ['level' => 'warning', 'key' => 'no_active_contract'];
        } elseif ($current->status === 'draft') {
            $alerts[] = ['level' => 'info', 'key' => 'contract_to_activate'];
        }
        if ($hasLeave) {
            $pending = LeaveRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_id', $employee->id)->where('status', LeaveRequest::STATUS_PENDING)->count();
            if ($pending > 0) {
                $alerts[] = ['level' => 'info', 'key' => 'pending_leave', 'count' => $pending];
            }
        }

        return $alerts;
    }
}
