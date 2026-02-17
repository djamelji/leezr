<?php

namespace App\Modules\Core\Members\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'company_role_id' => ['nullable', 'integer', Rule::exists('company_roles', 'id')
                ->where('company_id', $this->attributes->get('company')?->id)],
        ];
    }
}
