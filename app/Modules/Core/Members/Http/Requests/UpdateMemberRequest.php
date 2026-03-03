<?php

namespace App\Modules\Core\Members\Http\Requests;

use App\Company\RBAC\CompanyRole;
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
            'company_role_id' => ['sometimes', 'integer', Rule::exists('company_roles', 'id')
                ->where('company_id', $this->attributes->get('company')?->id)],
        ];

        $company = $this->attributes->get('company');

        // ADR-164: Resolve roleKey for role-aware validation
        $roleKey = null;
        if ($company) {
            if ($this->has('company_role_id') && $this->company_role_id) {
                $role = CompanyRole::where('company_id', $company->id)->find($this->company_role_id);
                $roleKey = $role?->key;
            } else {
                $membershipId = $this->route('id');
                $membership = $company->memberships()->with('companyRole')->find($membershipId);
                $roleKey = $membership?->companyRole?->key;
            }
        }

        return array_merge(
            $fixedRules,
            FieldValidationService::rules(FieldDefinition::SCOPE_COMPANY_USER, $company?->id, $roleKey, $company?->market_key),
        );
    }
}
