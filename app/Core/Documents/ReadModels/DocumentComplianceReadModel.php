<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentLifecycleService;
use App\Core\Documents\DocumentMandatoryContext;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;
use App\Core\Models\Membership;

/**
 * ADR-387: Document compliance dashboard — lifecycle-based.
 *
 * Aggregates lifecycle_status across all required activated document types
 * for every company member (user-scope) and the company itself (company-scope).
 *
 * Returns: summary KPIs, breakdown by role, breakdown by document type.
 *
 * Query budget: 5 queries (types, activations, company_docs, memberships, member_docs).
 */
class DocumentComplianceReadModel
{
    public static function forCompany(Company $company): array
    {
        $companyId = $company->id;
        $marketKey = $company->market_key;

        // Q1: All non-archived document types (system + company custom)
        $types = DocumentType::where(function ($q) use ($companyId) {
            $q->where('is_system', true)
                ->orWhere('company_id', $companyId);
        })->whereNull('archived_at')->get()->keyBy('id');

        if ($types->isEmpty()) {
            return self::emptyResult();
        }

        // Market filter
        if ($marketKey) {
            $types = $types->filter(function ($type) use ($marketKey) {
                $markets = $type->validation_rules['applicable_markets'] ?? null;

                return $markets === null || in_array($marketKey, $markets);
            });
        }

        // Q2: Enabled activations
        $activations = DocumentTypeActivation::whereIn('document_type_id', $types->keys())
            ->where('company_id', $companyId)
            ->where('enabled', true)
            ->get()
            ->keyBy('document_type_id');

        if ($activations->isEmpty()) {
            return self::emptyResult();
        }

        // Mandatory context (cached per request)
        $context = DocumentMandatoryContext::load($companyId);

        // Filter to required types only (mandatory OR required_override)
        $requiredTypeIds = $activations->filter(function ($activation) use ($types, $context) {
            $type = $types->get($activation->document_type_id);
            if (! $type) {
                return false;
            }
            $mandatory = DocumentMandatoryContext::isMandatory($type, $context);

            return $mandatory || $activation->required_override;
        })->keys();

        if ($requiredTypeIds->isEmpty()) {
            return self::emptyResult();
        }

        // Separate by scope
        $companyTypeIds = $requiredTypeIds->filter(
            fn ($id) => $types->get($id)?->scope === DocumentType::SCOPE_COMPANY,
        );
        $userTypeIds = $requiredTypeIds->filter(
            fn ($id) => $types->get($id)?->scope === DocumentType::SCOPE_COMPANY_USER,
        );

        // ── Company-scope slots ──
        $companyDocs = CompanyDocument::where('company_id', $companyId)
            ->whereIn('document_type_id', $companyTypeIds)
            ->get()
            ->keyBy('document_type_id');

        $allSlots = [];

        foreach ($companyTypeIds as $typeId) {
            $type = $types->get($typeId);
            $doc = $companyDocs->get($typeId);

            $allSlots[] = [
                'type_id' => $typeId,
                'code' => $type->code,
                'label' => $type->label,
                'scope' => DocumentType::SCOPE_COMPANY,
                'role_key' => null,
                'role_name' => null,
                'user_id' => null,
                'lifecycle_status' => DocumentLifecycleService::computeFromDate(
                    $doc !== null,
                    $doc?->expires_at,
                ),
            ];
        }

        // ── User-scope slots ──
        $memberships = Membership::where('company_id', $companyId)
            ->with('companyRole')
            ->get();

        if ($memberships->isNotEmpty() && $userTypeIds->isNotEmpty()) {
            // Q5: Batch member documents
            $memberDocs = MemberDocument::where('company_id', $companyId)
                ->whereIn('document_type_id', $userTypeIds)
                ->get()
                ->groupBy('user_id');

            foreach ($memberships as $membership) {
                $roleKey = $membership->companyRole?->key;
                $roleName = $membership->companyRole?->name;
                $userDocsByType = ($memberDocs->get($membership->user_id) ?? collect())
                    ->keyBy('document_type_id');

                foreach ($userTypeIds as $typeId) {
                    $type = $types->get($typeId);
                    $doc = $userDocsByType->get($typeId);

                    $allSlots[] = [
                        'type_id' => $typeId,
                        'code' => $type->code,
                        'label' => $type->label,
                        'scope' => DocumentType::SCOPE_COMPANY_USER,
                        'role_key' => $roleKey,
                        'role_name' => $roleName,
                        'user_id' => $membership->user_id,
                        'lifecycle_status' => DocumentLifecycleService::computeFromDate(
                            $doc !== null,
                            $doc?->expires_at,
                        ),
                    ];
                }
            }
        }

        // ── Aggregate ──
        return self::aggregate($allSlots);
    }

    private static function aggregate(array $slots): array
    {
        $total = count($slots);
        $counts = ['valid' => 0, 'missing' => 0, 'expiring_soon' => 0, 'expired' => 0];

        foreach ($slots as $slot) {
            $counts[$slot['lifecycle_status']]++;
        }

        $rate = $total > 0
            ? round(($counts['valid'] + $counts['expiring_soon']) / $total * 100, 1)
            : 0;

        // ── By role (user-scope slots only) ──
        $byRole = [];
        foreach ($slots as $slot) {
            if ($slot['scope'] !== DocumentType::SCOPE_COMPANY_USER) {
                continue;
            }
            $key = $slot['role_key'] ?? '_none';

            if (! isset($byRole[$key])) {
                $byRole[$key] = [
                    'role_key' => $slot['role_key'],
                    'role_name' => $slot['role_name'],
                    'members' => [],
                    'total' => 0,
                    'valid' => 0,
                    'missing' => 0,
                    'expiring_soon' => 0,
                    'expired' => 0,
                ];
            }

            $byRole[$key]['members'][$slot['user_id']] = true;
            $byRole[$key]['total']++;
            $byRole[$key][$slot['lifecycle_status']]++;
        }

        foreach ($byRole as &$r) {
            $r['member_count'] = count($r['members']);
            unset($r['members']);
            $r['rate'] = $r['total'] > 0
                ? round(($r['valid'] + $r['expiring_soon']) / $r['total'] * 100, 1)
                : 0;
        }

        // ── By type ──
        $byType = [];
        foreach ($slots as $slot) {
            $code = $slot['code'];

            if (! isset($byType[$code])) {
                $byType[$code] = [
                    'code' => $code,
                    'label' => $slot['label'],
                    'scope' => $slot['scope'],
                    'total' => 0,
                    'valid' => 0,
                    'missing' => 0,
                    'expiring_soon' => 0,
                    'expired' => 0,
                ];
            }

            $byType[$code]['total']++;
            $byType[$code][$slot['lifecycle_status']]++;
        }

        foreach ($byType as &$t) {
            $t['rate'] = $t['total'] > 0
                ? round(($t['valid'] + $t['expiring_soon']) / $t['total'] * 100, 1)
                : 0;
        }

        return [
            'summary' => [
                'total' => $total,
                'valid' => $counts['valid'],
                'missing' => $counts['missing'],
                'expiring_soon' => $counts['expiring_soon'],
                'expired' => $counts['expired'],
                'rate' => $rate,
            ],
            'by_role' => array_values($byRole),
            'by_type' => array_values($byType),
        ];
    }

    private static function emptyResult(): array
    {
        return [
            'summary' => [
                'total' => 0,
                'valid' => 0,
                'missing' => 0,
                'expiring_soon' => 0,
                'expired' => 0,
                'rate' => 0,
            ],
            'by_role' => [],
            'by_type' => [],
        ];
    }
}
