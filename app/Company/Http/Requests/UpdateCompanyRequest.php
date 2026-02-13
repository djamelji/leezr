<?php

namespace App\Company\Http\Requests;

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
            FieldValidationService::rules(FieldDefinition::SCOPE_COMPANY, $company?->id),
        );
    }
}
