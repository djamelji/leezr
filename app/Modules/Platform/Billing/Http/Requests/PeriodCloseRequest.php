<?php

namespace App\Modules\Platform\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PeriodCloseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'start_date' => ['required', 'date', 'before_or_equal:end_date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }
}
