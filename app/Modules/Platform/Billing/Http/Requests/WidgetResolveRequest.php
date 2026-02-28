<?php

namespace App\Modules\Platform\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WidgetResolveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'period' => ['nullable', 'in:7d,30d,90d'],
        ];
    }
}
