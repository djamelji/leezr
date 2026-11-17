<?php

namespace App\Modules\Workforce\Referral\Http;

use App\Modules\Workforce\Referral\Models\Referral;
use App\Modules\Workforce\Referral\Models\ReferralSettings;
use App\Modules\Workforce\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** ADR-819 — Workforce Parrainage, company side (referrer, reviewer, settings). Rights are enforced by route. */
class ReferralController
{
    private function service(Request $request): ReferralService
    {
        return new ReferralService($request->attributes->get('company'), $request->user());
    }

    private function referral(Request $request, int $id): Referral
    {
        return Referral::where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
    }

    // ── Referrer ──
    public function overview(Request $request): JsonResponse
    {
        $s = $this->service($request);
        $settings = $s->settings();

        return response()->json(['opportunities' => $s->opportunities(), 'mine' => $s->mine(), 'mode' => $settings->exists ? $settings->mode : null,
            'reward_note' => $settings->reward_note, 'daily_limit' => ReferralService::DAILY_LIMIT]);
    }

    public function recommend(Request $request): JsonResponse
    {
        $data = $request->validate(['job_role_id' => 'required|integer', 'first_name' => 'required|string|max:100', 'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:191|required_without:phone', 'phone' => 'nullable|string|max:40', 'note' => 'nullable|string|max:1000']);

        return response()->json($this->service($request)->recommend($data), 201);
    }

    public function share(Request $request): JsonResponse
    {
        $data = $request->validate(['job_role_id' => 'required|integer']);

        return response()->json($this->service($request)->shareLink((int) $data['job_role_id']), 201);
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        $this->service($request)->withdraw($this->referral($request, $id));

        return response()->json(['ok' => true]);
    }

    // ── Reviewer ──
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service($request)->queue());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->service($request)->detail($this->referral($request, $id)));
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:accept,decline,request_info', 'note' => 'nullable|string|max:2000']);

        return response()->json($this->service($request)->decide($this->referral($request, $id), $data['decision'], $data['note'] ?? null));
    }

    public function convert(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['hire_date' => 'required|date']);

        return response()->json($this->service($request)->convert($this->referral($request, $id), $data['hire_date']));
    }

    // ── Settings ──
    public function settings(Request $request): JsonResponse
    {
        $s = $this->service($request)->settings();

        return response()->json(['mode' => $s->mode, 'job_role_ids' => $s->job_role_ids ?? [], 'notify_reviewers' => $s->notify_reviewers,
            'reward_note' => $s->reward_note, 'retention_days' => $s->retention_days, 'link_days' => $s->link_days, 'configured' => $s->exists]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(ReferralSettings::MODES)], 'job_role_ids' => 'present|array|max:200', 'job_role_ids.*' => 'integer',
            'notify_reviewers' => 'boolean', 'reward_note' => 'nullable|string|max:1000', 'retention_days' => 'integer|min:30|max:730', 'link_days' => 'integer|min:1|max:60']);
        $this->service($request)->saveSettings($data);

        return $this->settings($request);
    }
}
