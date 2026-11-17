<?php

namespace App\Modules\Workforce\Onboarding\Http;

use App\Modules\Workforce\Onboarding\HiringFileReadModel;
use App\Modules\Workforce\Onboarding\OnboardingInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-819 (Company 2.0) — the hiring file as one page : detail + item states + history + participants + revision. */
class HiringFileController
{
    public function show(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $s = new OnboardingInvitationService($company, $request->user());
        $inv = $s->find($id);
        $canSensitive = $request->user()->hasCompanyPermission($company, 'workforce.sensitive_read') || $request->user()->hasCompanyPermission($company, 'members.sensitive_read');
        $detail = HiringFileReadModel::withStates($s->detail($inv, $canSensitive));
        $row = collect($s->index())->firstWhere('id', $inv->id) ?? [];
        $employee = \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $company->id)->with('jobRole:id,title')->find($inv->employee_id);

        return response()->json($detail + HiringFileReadModel::trail($inv) + [
            'revision' => HiringFileReadModel::revision($inv),
            'bucket' => HiringFileReadModel::bucket($row + ['status' => $detail['status']]),
            'finalized' => (bool) ($row['finalized'] ?? false),
            'job_role' => $employee?->jobRole?->title,
            'hire_date' => $employee?->hire_date?->toDateString(),
            'employee_id' => $inv->employee_id,
        ]);
    }
}
