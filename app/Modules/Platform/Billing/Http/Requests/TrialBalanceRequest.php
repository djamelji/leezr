<?php

namespace App\Modules\Platform\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrialBalanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ];
    }
}
