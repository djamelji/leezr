<?php

namespace App\Modules\Infrastructure\Auth\Http\Requests;

use App\Core\Auth\PasswordPolicy;
use App\Core\Markets\Market;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Set Laravel locale from market for translated validation messages
        if ($marketKey = $this->input('market_key')) {
            $locale = Market::where('key', $marketKey)->value('locale');

            if ($locale) {
                app()->setLocale(substr($locale, 0, 2)); // "fr-FR" → "fr"
            }
        }
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', PasswordPolicy::rules()],
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
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        $isFr = str_starts_with(app()->getLocale(), 'fr');

        return [
            'email.unique' => $isFr
                ? 'Un compte existe déjà avec cette adresse email. Connectez-vous ou utilisez une autre adresse.'
                : 'An account already exists with this email address. Please sign in or use a different email.',
        ];
    }
}
