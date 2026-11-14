<?php

namespace App\Modules\Core\Dashboard\Http;

use App\Core\Home\HomeContext;
use App\Core\Home\HomePrioritiesBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-776 — GET /api/dashboard/priorities : what needs the viewer's intervention, all active modules. */
class HomePrioritiesController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(HomePrioritiesBuilder::build(HomeContext::fromRequest($request)));
    }
}
