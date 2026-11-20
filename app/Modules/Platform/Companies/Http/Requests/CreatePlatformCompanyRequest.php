<?php

namespace App\Modules\Platform\Companies\Http\Requests;

use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-834 — shape of the Platform creation wizard submission (business rules: CreatePlatformCompanyUseCase). */
class CreatePlatformCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `platform.permission:create_companies`
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'company' => ['required', 'array'],
            'company.name' => ['required', 'string', 'max:255'],
            'company.market_key' => ['required', 'string', Rule::exists('markets', 'key')->where('is_active', true)],
            'company.legal_status_key' => ['nullable', 'string', 'max:50'],
            'company.dynamic_fields' => ['sometimes', 'array'],
            'company.dynamic_fields.*' => ['nullable', 'string', 'max:500'],
            'company.billing_same_as_company' => ['sometimes', 'boolean'],
            // ONE activity per company : a scalar key, never a list
            'jobdomain_key' => ['required', 'string', 'max:100'],
            'module_keys' => ['sometimes', 'array'],
            'module_keys.*' => ['string', 'max:100', 'distinct'],
            'subscription' => ['required', 'array'],
            'subscription.plan_key' => ['required', 'string', Rule::in(PlanRegistry::keys())],
            'subscription.interval' => ['sometimes', 'string', Rule::in(['monthly', 'yearly'])],
            'subscription.override' => ['nullable', 'array'],
            'subscription.override.type' => ['required_with:subscription.override', 'string', Rule::in(['trial', 'complimentary'])],
            'subscription.override.reason' => ['required_with:subscription.override', 'string', 'min:10', 'max:500'],
            'subscription.override.trial_days' => ['required_if:subscription.override.type,trial', 'nullable', 'integer', 'min:1', 'max:90'],
            'subscription.override.complimentary_months' => ['required_if:subscription.override.type,complimentary', 'nullable', 'integer', 'min:1', 'max:12'],
            'administrator' => ['required', 'array'],
            'administrator.first_name' => ['required', 'string', 'max:255'],
            'administrator.last_name' => ['required', 'string', 'max:255'],
            'administrator.email' => ['required', 'string', 'email', 'max:255'],
            'initialization' => ['sometimes', 'array'],
            'initialization.planning' => ['sometimes', 'boolean'],
            'initialization.organization' => ['sometimes', 'boolean'],
        ];
    }
}
