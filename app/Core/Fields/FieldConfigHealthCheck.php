<?php

namespace App\Core\Fields;

use App\Company\RBAC\CompanyRole;

/**
 * ADR-164: Health-check for field_config integrity.
 *
 * Detects orphaned references in role field_config entries
 * (codes that reference inactive or non-existent field definitions).
 */
class FieldConfigHealthCheck
{
    /**
     * Check field_config integrity for a company.
     *
     * @return array{healthy: bool, issues: array<int, array{role_key: string, field_code: string, issue: string}>}
     */
    public static function check(int $companyId): array
    {
        $roles = CompanyRole::where('company_id', $companyId)
            ->whereNotNull('field_config')
            ->get();

        if ($roles->isEmpty()) {
            return ['healthy' => true, 'issues' => []];
        }

        // Collect all active field codes for this company (company + platform scope)
        $activeCodes = FieldActivation::where('company_id', $companyId)
            ->where('enabled', true)
            ->with('definition')
            ->get()
            ->pluck('definition.code')
            ->filter()
            ->toArray();

        $issues = [];

        foreach ($roles as $role) {
            foreach ($role->field_config as $entry) {
                $code = $entry['code'] ?? null;

                if ($code && !in_array($code, $activeCodes)) {
                    $issues[] = [
                        'role_key' => $role->key,
                        'field_code' => $code,
                        'issue' => 'references_inactive_field',
                    ];
                }
            }
        }

        return ['healthy' => empty($issues), 'issues' => $issues];
    }
}
