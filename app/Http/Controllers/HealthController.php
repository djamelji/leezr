<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'app_version' => config('app.version', '0.0.0'),
            'build_version' => config('app.build_version', 'dev'),
            'commit_hash' => config('app.commit_hash'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
