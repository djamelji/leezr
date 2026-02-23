<?php

namespace App\Core\Settings\Http;

use App\Core\Settings\WorldSettingsPayload;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PublicWorldController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(
            WorldSettingsPayload::fromSettings()->toArray()
        );
    }
}
