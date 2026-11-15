<?php

namespace App\Modules\Core\Settings\Http\Requests;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValidationService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $fixedRules = [
            'name' => ['required', 'string', 'max:255'],
        ];

        $company = $this->attributes->get('company');

        return array_merge(
            $fixedRules,
            FieldValidationService::rules(FieldDefinition::SCOPE_COMPANY, $company?->id, marketKey: $company?->market_key),
        );
    }

    /** ADR-785 — field errors name the field as the user sees it (« Code APE (NAF) »), never a technical key. */
    public function attributes(): array
    {
        $locale = app()->getLocale();

        return FieldDefinition::whereNull('company_id')->where('scope', FieldDefinition::SCOPE_COMPANY)->get()
            ->mapWithKeys(fn (FieldDefinition $d) => ['dynamic_fields.'.$d->code => $d->resolvedLabel($locale)])
            ->all();
    }

    public function messages(): array
    {
        return [
            'dynamic_fields.naf_code.regex' => __('company.naf_format'),
            'dynamic_fields.*.regex' => __('company.field_format'),
        ];
    }
}
