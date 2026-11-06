<?php

namespace App\Modules\Workforce\Favorites\Http;

use App\Core\Workforce\ActivityPreset;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\WeekTemplate;
use App\Core\Workforce\WorkforceFavorite;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Favoris personnels (user × objet workforce) — Refonte Smart Planning M5 (ADR-728).
 * Scopés (user_id + company_id) : jamais mélangés entre tenants/memberships. Lecture/écriture planning_view.
 */
class WorkforceFavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $companyId = $request->attributes->get('company_id');
        $q = WorkforceFavorite::where('user_id', $userId)->where('company_id', $companyId);
        if ($request->filled('type')) {
            $q->where('favoritable_type', $request->query('type'));
        }

        return response()->json($q->get(['favoritable_type', 'favoritable_id']));
    }

    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'favoritable_type' => 'required|in:'.implode(',', WorkforceFavorite::TYPES),
            'favoritable_id' => 'required|integer',
        ]);
        $userId = $request->user()->id;
        $companyId = $request->attributes->get('company_id');

        // L'objet doit appartenir à la Company courante (jamais un favori inter-tenant).
        if (! $this->belongsToCompany($data['favoritable_type'], (int) $data['favoritable_id'], $companyId)) {
            return response()->json(['message' => 'Object does not belong to this company.'], 422);
        }

        $existing = WorkforceFavorite::where('user_id', $userId)->where('company_id', $companyId)
            ->where('favoritable_type', $data['favoritable_type'])->where('favoritable_id', $data['favoritable_id'])->first();
        if ($existing) {
            $existing->delete();

            return response()->json(['favorited' => false]);
        }
        WorkforceFavorite::create(['user_id' => $userId, 'company_id' => $companyId] + $data);

        return response()->json(['favorited' => true]);
    }

    private function belongsToCompany(string $type, int $id, int $companyId): bool
    {
        $model = [
            'activity_preset' => ActivityPreset::class,
            'schedule_template' => ScheduleTemplate::class,
            'week_template' => WeekTemplate::class,
        ][$type] ?? null;

        return $model && $model::withoutGlobalScopes()->where('id', $id)->where('company_id', $companyId)->exists();
    }
}
