<?php

namespace App\Core\Auth\Requests;

use App\Core\Auth\PasswordPolicy;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', PasswordPolicy::rules()],
            'company_name' => ['required', 'string', 'max:255'],
            'jobdomain_key' => ['required', 'string', 'exists:jobdomains,key'],
            'plan_key' => ['sometimes', 'nullable', 'string', Rule::in(PlanRegistry::keys())],
            'market_key' => ['sometimes', 'string', 'exists:markets,key'],
            'billing_interval' => ['sometimes', 'string', Rule::in(['monthly', 'yearly'])],
            'legal_status_key' => ['sometimes', 'nullable', 'string', 'max:50'],
            'dynamic_fields' => ['sometimes', 'array'],
            'dynamic_fields.*' => ['sometimes', 'nullable', 'string', 'max:500'],
            'addon_keys' => ['sometimes', 'array'],
            'addon_keys.*' => ['string', 'max:100'],
            'billing_same_as_company' => ['sometimes', 'boolean'],
        ];
    }
}
