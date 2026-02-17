<?php

namespace App\Modules\Logistics\Shipments\Http\Requests;

use App\Core\Models\Shipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeShipmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Shipment::STATUSES)],
        ];
    }
}
