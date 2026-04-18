<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailContactController
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->query('q', '');
        $contacts = EmailContact::query()
            ->when($query, fn ($q) => $q->where('email', 'LIKE', "%{$query}%")->orWhere('name', 'LIKE', "%{$query}%"))
            ->orderByDesc('frequency')
            ->limit(10)
            ->get(['id', 'email', 'name', 'frequency']);

        return response()->json($contacts);
    }
}
