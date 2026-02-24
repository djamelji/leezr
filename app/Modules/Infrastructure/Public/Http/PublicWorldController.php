<?php

namespace App\Modules\Infrastructure\Public\Http;

use App\Core\Settings\WorldSettingsPayload;
use Illuminate\Http\JsonResponse;

class PublicWorldController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(
            WorldSettingsPayload::fromSettings()->toArray()
        );
    }
}
