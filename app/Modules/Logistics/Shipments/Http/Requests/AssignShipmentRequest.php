<?php

namespace App\Modules\Logistics\Shipments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
