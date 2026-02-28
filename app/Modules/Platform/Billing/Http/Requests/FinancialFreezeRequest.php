<?php

namespace App\Modules\Platform\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinancialFreezeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'frozen' => ['required', 'boolean'],
        ];
    }
}
