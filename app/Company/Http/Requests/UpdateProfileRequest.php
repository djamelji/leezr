<?php

namespace App\Company\Http\Requests;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValidationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $fixedRules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($this->user()->id),
            ],
        ];

        $company = $this->attributes->get('company');

        return array_merge(
            $fixedRules,
            FieldValidationService::rules(FieldDefinition::SCOPE_COMPANY_USER, $company?->id),
        );
    }
}
