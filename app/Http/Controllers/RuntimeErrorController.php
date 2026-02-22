<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RuntimeErrorController
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'string', 'max:500'],
            'user_agent' => ['nullable', 'string', 'max:500'],
            'timestamp' => ['nullable', 'string', 'max:50'],
            'build_version' => ['nullable', 'string', 'max:50'],
        ]);

        Log::channel('runtime')->warning('Client runtime error', [
            'type' => $validated['type'] ?? 'js_error',
            'message' => $validated['message'],
            'stack' => $validated['stack'] ?? null,
            'url' => $validated['url'] ?? $request->header('Referer'),
            'user_agent' => $validated['user_agent'] ?? $request->userAgent(),
            'build_version' => $validated['build_version'] ?? null,
            'ip' => $request->ip(),
        ]);

        return response()->noContent();
    }
}
