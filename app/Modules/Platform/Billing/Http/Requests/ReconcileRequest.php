<?php

namespace App\Modules\Platform\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }
}
