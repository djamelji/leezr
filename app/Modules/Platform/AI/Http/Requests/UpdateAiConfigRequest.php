<?php

namespace App\Modules\Platform\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Permission handled by middleware
    }

    public function rules(): array
    {
        return [
            'driver' => 'nullable|string|in:null,ollama,openai,anthropic',
            'timeout' => 'nullable|integer|min:5|max:300',
        ];
    }
}
