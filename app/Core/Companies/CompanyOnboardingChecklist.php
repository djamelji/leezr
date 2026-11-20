<?php

namespace App\Core\Companies;

use App\Company\Security\MembershipInvitation;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Subscription;
use App\Core\Documents\CompanyDocument;
use App\Core\Documents\MemberDocument;
use App\Core\Fields\CompanyCreationFields;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Modules\CompanyModule;

/**
 * ADR-383 / ADR-834 — the ONE computation of a company's start-up state.
 *  - ownerSteps()  : the getting-started checklist shown to the owner (unchanged ADR-383 semantics) ;
 *  - forPlatform() : what Platform needs to bring a company it created into service — the owner's steps plus
 *    the set-up items only Platform can see (administrator activation, required identity fields, subscription
 *    state, initial decisions left to the company). « Created » is never reported as « in service ».
 */
final class CompanyOnboardingChecklist
{
    /** @return list<array{key: string, completed: bool}> */
    public static function ownerSteps(Company $company): array
    {
        $steps = [
            ['key' => 'account_created', 'completed' => true],
            ['key' => 'company_profile', 'completed' => FieldValue::where('model_type', $company->getMorphClass())
                ->where('model_id', $company->id)
                ->whereHas('definition', fn ($q) => $q->where('scope', FieldDefinition::SCOPE_COMPANY))
                ->whereNotNull('value')
                ->exists()],
        ];
        // The « payment method » step only applies when the provider can register a card online (Stripe) —
        // with provider null/internal it is impossible, so it is excluded instead of blocking the checklist.
        if (Subscription::where('company_id', $company->id)->where('is_current', true)->value('provider') === 'stripe') {
            $steps[] = ['key' => 'payment_method', 'completed' => CompanyPaymentProfile::where('company_id', $company->id)->exists()];
        }
        $steps[] = ['key' => 'invite_member', 'completed' => $company->memberships()->count() > 1];
        $steps[] = ['key' => 'activate_module', 'completed' => CompanyModule::where('company_id', $company->id)->where('is_enabled_for_company', true)->exists()];
        $steps[] = ['key' => 'first_document', 'completed' => CompanyDocument::where('company_id', $company->id)->exists()
            || MemberDocument::where('company_id', $company->id)->exists()];

        return $steps;
    }

    /**
     * @return array{in_service: bool, items: list<array{key: string, status: string, detail: array}>, owner_steps: list<array{key: string, completed: bool}>, completed_count: int, total_count: int}
     */
    public static function forPlatform(Company $company, ?array $initialization = null): array
    {
        $items = [self::administratorItem($company), self::requiredFieldsItem($company), self::subscriptionItem($company)];
        $pending = array_values(array_filter(array_map(
            fn ($kind, $p) => ($p['skipped'] ?? null) === 'explicit_decision_not_taken' ? $kind : null,
            array_keys($initialization['presets'] ?? []),
            $initialization['presets'] ?? [],
        )));
        $items[] = ['key' => 'initial_decisions', 'status' => $pending === [] ? 'done' : 'todo', 'detail' => ['pending' => $pending, 'path' => $initialization['path'] ?? null]];

        $blocking = array_filter($items, fn ($i) => in_array($i['key'], ['administrator', 'required_fields', 'subscription'], true) && $i['status'] !== 'done');

        return [
            'in_service' => $blocking === [],
            'items' => $items,
            'owner_steps' => self::ownerSteps($company),
            'completed_count' => count(array_filter($items, fn ($i) => $i['status'] === 'done')),
            'total_count' => count($items),
        ];
    }

    private static function administratorItem(Company $company): array
    {
        $owner = Membership::where('company_id', $company->id)->where('role', 'owner')->with('user:id,first_name,last_name,email,password_set_at')->oldest('id')->first();
        if (! $owner) {
            return ['key' => 'administrator', 'status' => 'todo', 'detail' => ['state' => 'missing']];
        }
        $invitation = MembershipInvitation::summary($owner);
        $state = match (true) {
            $owner->isPendingAcceptance() => MembershipInvitation::isExpired($owner) ? 'expired' : 'awaiting_acceptance',
            ! $owner->user?->password_set_at => ($invitation['delivery'] ?? null) === MembershipInvitation::DELIVERY_FAILED ? 'delivery_failed' : 'awaiting_activation',
            default => 'active',
        };

        return ['key' => 'administrator', 'status' => $state === 'active' ? 'done' : 'todo', 'detail' => [
            'state' => $state,
            'membership_id' => $owner->id,
            'name' => trim(($owner->user?->first_name ?? '').' '.($owner->user?->last_name ?? '')),
            'email' => $owner->user?->email,
            'invitation' => $invitation,
        ]];
    }

    private static function requiredFieldsItem(Company $company): array
    {
        $required = collect(CompanyCreationFields::resolve((string) $company->jobdomain_key, $company->market_key, app()->getLocale()))->where('required', true);
        $filled = FieldValue::where('model_type', $company->getMorphClass())->where('model_id', $company->id)->whereNotNull('value')
            ->whereHas('definition', fn ($q) => $q->where('scope', FieldDefinition::SCOPE_COMPANY)->whereIn('code', $required->pluck('code')))
            ->with('definition:id,code')->get()->filter(fn ($v) => trim((string) json_encode($v->value), '"') !== '')
            ->pluck('definition.code')->all();
        $missing = $required->reject(fn ($f) => in_array($f['code'], $filled, true))->values();

        return ['key' => 'required_fields', 'status' => $missing->isEmpty() ? 'done' : 'todo', 'detail' => [
            'missing' => $missing->map(fn ($f) => ['code' => $f['code'], 'label' => $f['label']])->all(),
            'legal_status_set' => $company->legal_status_key !== null,
        ]];
    }

    private static function subscriptionItem(Company $company): array
    {
        $sub = Subscription::where('company_id', $company->id)->where('is_current', 1)->first();
        $status = $sub?->status;

        return ['key' => 'subscription', 'status' => in_array($status, ['active', 'trialing'], true) ? 'done' : 'todo', 'detail' => [
            'status' => $status ?? 'none',
            'plan_key' => $sub?->plan_key ?? $company->plan_key,
            'trial_ends_at' => $sub?->trial_ends_at?->toIso8601String(),
            'current_period_end' => $sub?->current_period_end?->toIso8601String(),
        ]];
    }
}
