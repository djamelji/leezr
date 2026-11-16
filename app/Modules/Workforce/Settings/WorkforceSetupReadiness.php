<?php

namespace App\Modules\Workforce\Settings;

use App\Core\Models\Company;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Modules\Setup\ModuleReadiness;
use App\Core\Workforce\Services\WorkforceRuleResolver;

/**
 * ADR-803 — Workforce is ready when : the company DECIDED who does what (access step confirmed, even if the
 * answer is « owner only »), CONFIRMED its applicable collective agreement (or that none applies, ADR-799),
 * and a weekly reference duration is effectively resolved (company, agreement or market). Verified from the
 * existing sources (setup progress, companies.convention_confirmed_at, WorkforceRuleResolver).
 */
final class WorkforceSetupReadiness implements ModuleReadiness
{
    public function checks(Company $company, CompanyModuleSetup $setup): array
    {
        $weekly = app(WorkforceRuleResolver::class)->resolveWorkTimeRules($company)['weekly_hours_reference']['value'] ?? null;

        return [
            ['key' => 'access_decided', 'ok' => in_array('access', $setup->completed_steps ?? [], true)],
            ['key' => 'framework_confirmed', 'ok' => $company->convention_confirmed_at !== null],
            ['key' => 'weekly_reference', 'ok' => $weekly !== null],
        ];
    }

    /**
     * ADR-804 — EFFECTIVE mandatory settings : resolved values of the working-time, break and organisation rules,
     * the week start, the applicable agreement and whether it is confirmed. A write that changes nothing (same
     * value, same agreement re-confirmed) leaves the fingerprint unchanged → no false « needs review ».
     */
    public function settingsFingerprint(Company $company): ?string
    {
        $r = app(WorkforceRuleResolver::class);
        $values = fn (array $rules) => collect($rules)->map(fn ($x) => $x['value'] ?? null)->sortKeys()->all();

        return sha1(json_encode([
            'worktime' => $values($r->resolveWorkTimeRules($company)),
            'breaks' => $values($r->resolveBreakRules($company)),
            'organization' => $values($r->resolveOrganizationRules($company)),
            'week_start' => $company->week_start_day,
            'convention' => $company->default_convention_collective_id,
            'convention_confirmed' => $company->convention_confirmed_at !== null,
        ]));
    }
}
