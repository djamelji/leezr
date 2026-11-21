<?php

namespace App\Modules\Platform\Companies\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\PlanChangeExecutor;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\PlanChangeTimingResolver;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * ADR-837 (Leezr 4.2 M1, UXP-01) — a Platform operator changes a company's plan from the company record, after a
 * preview. SAME engine and rules as the company self-service (PlanChangeTimingResolver + PlanChangeExecutor) :
 *  - the billing interval is KEPT unless the operator explicitly chooses another one (never silently monthly) ;
 *  - the timing is the policy timing — the one the preview displayed (no forced « immediate ») ;
 *  - trials, proration, modules and limits stay with the billing engine ;
 *  - requested / executed are written to the company audit log (billing timeline) AND the platform audit log.
 */
final class ChangeCompanyPlanUseCase
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The interval the change keeps when the operator does not choose one : the current subscription's. */
    public static function currentInterval(Company $company): ?string
    {
        return self::subscription($company)?->interval ?? null;
    }

    public function execute(Company $company, string $toPlanKey, ?string $toInterval, ?string $idempotencyKey = null): PlanChangeIntent
    {
        $subscription = self::subscription($company);
        if (! $subscription) {
            throw ValidationException::withMessages(['plan_key' => ['No active subscription for this company.']]);
        }
        $toInterval ??= $subscription->interval ?? 'monthly';
        if ($subscription->plan_key === $toPlanKey && ($subscription->interval ?? 'monthly') === $toInterval) {
            throw ValidationException::withMessages(['plan_key' => ['Already on this plan and interval.']]);
        }

        $timing = PlanChangeTimingResolver::resolve($subscription, $toPlanKey, $toInterval, PlatformBillingPolicy::instance())['timing'];
        $context = [
            'from_plan' => $subscription->plan_key, 'to_plan' => $toPlanKey,
            'from_interval' => $subscription->interval ?? 'monthly', 'to_interval' => $toInterval,
            'timing' => $timing, 'source' => 'platform',
        ];
        $this->audit->logCompany($company->id, AuditAction::PLAN_CHANGE_REQUESTED, 'subscription', (string) $subscription->id, ['metadata' => $context]);
        $this->audit->logPlatform(AuditAction::PLAN_CHANGE_REQUESTED, 'company', (string) $company->id, [
            'metadata' => $context,
            'diffBefore' => ['plan_key' => $subscription->plan_key, 'interval' => $subscription->interval ?? 'monthly'],
            'diffAfter' => ['plan_key' => $toPlanKey, 'interval' => $toInterval],
        ]);

        $intent = PlanChangeExecutor::schedule(
            company: $company,
            toPlanKey: $toPlanKey,
            toInterval: $toInterval,
            timing: $timing,
            idempotencyKey: $idempotencyKey ?? "admin-plan-change-{$company->id}-{$toPlanKey}-{$toInterval}-".now()->timestamp,
        );

        if ($intent->isExecuted()) {
            $executed = $context + ['proration' => $intent->proration_snapshot];
            $this->audit->logCompany($company->id, AuditAction::PLAN_CHANGE_EXECUTED, 'plan_change_intent', (string) $intent->id, ['metadata' => $executed]);
            $this->audit->logPlatform(AuditAction::PLAN_CHANGE_EXECUTED, 'company', (string) $company->id, ['metadata' => $executed]);
        }

        return $intent;
    }

    private static function subscription(Company $company): ?Subscription
    {
        return Subscription::where('company_id', $company->id)->whereIn('status', ['active', 'trialing'])->latest()->first();
    }
}
