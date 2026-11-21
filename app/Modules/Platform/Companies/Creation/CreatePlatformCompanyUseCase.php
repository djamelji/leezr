<?php

namespace App\Modules\Platform\Companies\Creation;

use App\Company\Security\MembershipInvitation;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\Subscription;
use App\Core\Companies\CompanyOnboardingChecklist;
use App\Core\Companies\CompanyProvisioner;
use App\Core\Fields\CompanyCreationFields;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\LegalStatus;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-834 — creation of a company FROM PLATFORM, end to end, with the existing engines only :
 * CompanyProvisioner (identity, single activity, initial configuration, fields), ModuleActivationEngine
 * (commercial policy, plan rights, dependencies), Subscription (no payment at creation), MembershipInvitation
 * (secure activation of the administrator), AuditLogger.
 *
 * Guarantees : everything is validated BEFORE any write ; one transaction (a refused step leaves nothing) ;
 * idempotency key → a double submission returns the same company ; no other company is read for writing.
 */
final class CreatePlatformCompanyUseCase
{
    public const OVERRIDE_PERMISSION = 'override_company_commercial_terms';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array  $data  validated payload of CreatePlatformCompanyRequest
     * @return array{company: Company, creation: PlatformCompanyCreation, replayed: bool}
     */
    public function execute(array $data, PlatformUser $actor): array
    {
        if ($existing = $this->replay($data['idempotency_key'])) {
            return $existing;
        }

        $this->assertIdentity($data);
        $plan = $this->planDecision($data, $actor);
        $modules = $this->moduleDecision($data['jobdomain_key'], $plan['plan_key'], $data['module_keys'] ?? [], $plan['status']);
        $email = mb_strtolower(trim($data['administrator']['email']));
        $existingUser = User::whereRaw('LOWER(email) = ?', [$email])->first();

        try {
            [$company, $creation, $membership] = DB::transaction(function () use ($data, $actor, $plan, $modules, $email, $existingUser) {
                // first write : the idempotency key (unique) — a concurrent duplicate waits here, then fails cleanly
                $creation = PlatformCompanyCreation::create(['idempotency_key' => $data['idempotency_key'], 'platform_user_id' => $actor->id]);

                $company = CompanyProvisioner::create(trim($data['company']['name']), $data['company']['market_key'], $data['jobdomain_key'], $plan['plan_key']);

                $user = $existingUser ?? User::create([
                    'first_name' => trim($data['administrator']['first_name']),
                    'last_name' => trim($data['administrator']['last_name']),
                    'email' => $email,
                    'password' => null, // never a password chosen by the operator : activation link only
                ]);
                $membership = $company->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
                if ($existingUser) {
                    // a pre-existing account is never attached silently : pending until its owner accepts
                    MembershipInvitation::requireAcceptance($company, $membership, null);
                }

                $init = CompanyProvisioner::initializeActivity($company, $data['jobdomain_key'], $user->id, [
                    'planning' => (bool) ($data['initialization']['planning'] ?? false),
                    'organization' => (bool) ($data['initialization']['organization'] ?? false),
                ]);
                CompanyProvisioner::applyIdentity($company, $data['company']['legal_status_key'] ?? null, $this->dynamicFields($data), (bool) ($data['company']['billing_same_as_company'] ?? true));

                // subscription BEFORE modules : an add-on activated during a trial is never invoiced at creation
                $subscription = Subscription::create([
                    'company_id' => $company->id,
                    'plan_key' => $plan['plan_key'],
                    'interval' => $plan['interval'],
                    'status' => $plan['status'],
                    'provider' => 'internal',
                    'is_current' => 1,
                    'current_period_start' => now(),
                    'current_period_end' => $plan['period_end'],
                    'trial_ends_at' => $plan['status'] === 'trialing' ? $plan['period_end'] : null,
                ]);

                foreach ($modules['requested'] as $key) {
                    $result = ModuleActivationEngine::enable($company, $key);
                    if (! ($result['success'] ?? false)) {
                        throw ValidationException::withMessages(['module_keys' => [__('platformCompanyCreation.errors.moduleRefused', ['module' => $modules['names'][$key] ?? $key, 'reason' => $result['data']['message'] ?? ''])]]);
                    }
                }

                $presets = array_map(fn ($p) => is_array($p) ? array_intersect_key($p, ['skipped' => 1, 'applied' => 1]) : [], (array) ($init['summary']['presets'] ?? []));
                $creation->update([
                    'company_id' => $company->id,
                    'decisions' => [
                        'plan_key' => $plan['plan_key'], 'interval' => $plan['interval'], 'subscription_status' => $subscription->status,
                        'override' => $plan['override'], 'modules' => $modules['requested'],
                        'administrator' => $existingUser ? 'existing_account' : 'new_account',
                    ],
                    'initialization' => ['path' => $init['path'], 'presets' => $presets],
                ]);

                $this->audit->logPlatform('company.created_by_platform', 'company', (string) $company->id, ['metadata' => [
                    'jobdomain' => $company->jobdomain_key, 'market' => $company->market_key, 'plan' => $plan['plan_key'],
                    'subscription_status' => $subscription->status, 'modules' => $modules['requested'],
                    'administrator' => $existingUser ? 'existing_account' : 'new_account', 'initialization' => $init['path'],
                ]]);
                if ($plan['override']) {
                    $this->audit->logPlatform('company.commercial_override', 'company', (string) $company->id, ['severity' => 'warning', 'metadata' => $plan['override']]);
                }

                return [$company, $creation, $membership];
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $this->replay($data['idempotency_key'])) {
                return $existing;
            }
            throw $e;
        }

        // after commit : the e-mail never rolls the creation back, its outcome is recorded (M7 LOT 3)
        MembershipInvitation::deliver($company, $membership->fresh(), null);

        return ['company' => $company->fresh(), 'creation' => $creation->fresh(), 'replayed' => false];
    }

    private function replay(string $key): ?array
    {
        $creation = PlatformCompanyCreation::where('idempotency_key', $key)->whereNotNull('company_id')->first();

        return $creation ? ['company' => $creation->company, 'creation' => $creation, 'replayed' => true] : null;
    }

    /**
     * Plan & subscription at creation — never a payment. Free plan → active ; paid plan → trial of the plan when
     * the administrator never had one, otherwise awaiting payment. Any other term = audited commercial override.
     */
    private function planDecision(array $data, PlatformUser $actor): array
    {
        $planKey = $data['subscription']['plan_key'];
        $def = PlanRegistry::definitions()[$planKey];
        $interval = $data['subscription']['interval'] ?? 'monthly';
        $isFree = ($def['price_monthly'] ?? 0) <= 0 && ($def['price_yearly'] ?? 0) <= 0;
        $override = $data['subscription']['override'] ?? null;
        $trialDays = (int) ($def['trial_days'] ?? 0);
        $email = mb_strtolower(trim($data['administrator']['email']));
        $usedTrial = ($u = User::whereRaw('LOWER(email) = ?', [$email])->first())
            && Subscription::whereIn('company_id', $u->companies()->pluck('companies.id'))->whereNotNull('trial_ends_at')->exists();

        if ($override) {
            if (! $actor->hasPermission(self::OVERRIDE_PERMISSION)) {
                throw ValidationException::withMessages(['subscription.override' => [__('platformCompanyCreation.errors.overrideForbidden')]]);
            }
            if ($isFree) {
                throw ValidationException::withMessages(['subscription.override' => [__('platformCompanyCreation.errors.overrideFreePlan')]]);
            }
            $override = array_filter([
                'type' => $override['type'], 'reason' => trim($override['reason']),
                'trial_days' => $override['type'] === 'trial' ? (int) $override['trial_days'] : null,
                'complimentary_months' => $override['type'] === 'complimentary' ? (int) $override['complimentary_months'] : null,
                'plan_trial_days' => $trialDays, 'trial_already_used' => $usedTrial, 'by' => $actor->id,
            ], fn ($v) => $v !== null);

            return match ($override['type']) {
                'trial' => ['plan_key' => $planKey, 'interval' => $interval, 'status' => 'trialing', 'period_end' => now()->addDays($override['trial_days']), 'override' => $override],
                'complimentary' => ['plan_key' => $planKey, 'interval' => $interval, 'status' => 'active', 'period_end' => now()->addMonths($override['complimentary_months']), 'override' => $override],
            };
        }

        return match (true) {
            $isFree => ['plan_key' => $planKey, 'interval' => $interval, 'status' => 'active', 'period_end' => $interval === 'yearly' ? now()->addYear() : now()->addMonth(), 'override' => null],
            $trialDays > 0 && ! $usedTrial => ['plan_key' => $planKey, 'interval' => $interval, 'status' => 'trialing', 'period_end' => now()->addDays($trialDays), 'override' => null],
            default => ['plan_key' => $planKey, 'interval' => $interval, 'status' => 'pending_payment', 'period_end' => $interval === 'yearly' ? now()->addYear() : now()->addMonth(), 'override' => null],
        };
    }

    /** Same classification as the wizard ; anything not selectable for this activity + plan is refused. */
    private function moduleDecision(string $jobdomainKey, string $planKey, array $requested, string $subscriptionStatus): array
    {
        $catalog = collect(PlatformCompanyCreationCatalog::modules($jobdomainKey, $planKey))->keyBy('key');
        $names = $catalog->pluck('name', 'key')->all();
        $requested = array_values(array_unique($requested));
        $errors = [];
        $paid = false;
        foreach ($requested as $key) {
            $row = $catalog->get($key);
            if (! $row) {
                $errors[] = __('platformCompanyCreation.errors.moduleIncompatible', ['module' => $key]);

                continue;
            }
            if ($row['class'] === 'base') {
                continue; // activated anyway by the activity
            }
            if (! in_array($row['class'], PlatformCompanyCreationCatalog::SELECTABLE, true)) {
                $errors[] = $row['class'] === 'plan_required'
                    ? __('platformCompanyCreation.errors.modulePlanRequired', ['module' => $row['name'], 'plan' => $row['required_plan']['name'] ?? ''])
                    : __('platformCompanyCreation.errors.moduleUnavailable', ['module' => $row['name']]);

                continue;
            }
            foreach (array_merge([$key], array_column($row['requires'], 'key')) as $k) {
                $dep = $catalog->get($k);
                if (! $dep || ! in_array($dep['class'], [...PlatformCompanyCreationCatalog::SELECTABLE, 'base'], true)) {
                    $errors[] = __('platformCompanyCreation.errors.dependencyUnavailable', ['module' => $row['name'], 'dependency' => $names[$k] ?? $k]);
                }
                $paid = $paid || ($dep['class'] ?? null) === 'option';
            }
        }
        if ($paid && $subscriptionStatus !== 'trialing') {
            $errors[] = __('platformCompanyCreation.errors.paidOptionsNeedTrial');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['module_keys' => array_values(array_unique($errors))]);
        }

        return ['requested' => array_values(array_filter($requested, fn ($k) => $catalog->get($k)['class'] !== 'base')), 'names' => $names];
    }

    /** Market-conditional identity : legal status of the market, required fields, known codes only, formats. */
    private function assertIdentity(array $data): void
    {
        $market = $data['company']['market_key'];
        $errors = [];
        if (! Jobdomain::where('key', $data['jobdomain_key'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['jobdomain_key' => [__('platformCompanyCreation.errors.jobdomainInactive')]]);
        }
        $legal = $data['company']['legal_status_key'] ?? null;
        if ($legal && ! LegalStatus::where('market_key', $market)->where('key', $legal)->exists()) {
            $errors['company.legal_status_key'] = [__('platformCompanyCreation.errors.legalStatusMarket')];
        }
        // ADR-835 : the SAME rule as the public sign-up (required, formats, defaults, JobDomain customisation)
        $checked = CompanyCreationFields::validate($data['jobdomain_key'], $market, $data['company']['dynamic_fields'] ?? [], app()->getLocale());
        foreach ($checked['errors'] as $code => $message) {
            $errors["company.dynamic_fields.$code"] = [$message];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Values to store : trimmed, defaults applied (ADR-835) — validated beforehand by assertIdentity. */
    private function dynamicFields(array $data): array
    {
        return CompanyCreationFields::validate($data['jobdomain_key'], $data['company']['market_key'], $data['company']['dynamic_fields'] ?? [], app()->getLocale())['values'];
    }

    /** Onboarding state of a company (created ≠ in service) — Platform view. */
    public static function onboarding(Company $company): array
    {
        $creation = PlatformCompanyCreation::where('company_id', $company->id)->with('creator:id,first_name,last_name')->first();

        return CompanyOnboardingChecklist::forPlatform($company, $creation?->initialization) + [
            'creation' => $creation ? [
                'created_at' => $creation->created_at?->toIso8601String(),
                'created_by' => trim(($creation->creator?->first_name ?? '').' '.($creation->creator?->last_name ?? '')) ?: null,
                'decisions' => $creation->decisions,
                'initialization' => $creation->initialization,
            ] : null,
        ];
    }
}
