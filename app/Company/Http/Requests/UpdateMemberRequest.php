<?php

namespace App\Company\Http\Requests;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValidationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $fixedRules = [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'company_role_id' => ['sometimes', 'nullable', 'integer', Rule::exists('company_roles', 'id')
                ->where('company_id', $this->attributes->get('company')?->id)],
        ];

        $company = $this->attributes->get('company');

        return array_merge(
            $fixedRules,
            FieldValidationService::rules(FieldDefinition::SCOPE_COMPANY_USER, $company?->id),
        );
    }
}
