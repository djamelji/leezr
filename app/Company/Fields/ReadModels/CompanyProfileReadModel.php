<?php

namespace App\Company\Fields\ReadModels;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldResolverService;
use App\Core\Models\Company;
use App\Core\Storage\StorageQuotaService;

class CompanyProfileReadModel
{
    public static function get(Company $company): array
    {
        return [
            'base_fields' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'status' => $company->status,
            ],
            'dynamic_fields' => FieldResolverService::resolve(
                model: $company,
                scope: FieldDefinition::SCOPE_COMPANY,
                companyId: $company->id,
                marketKey: $company->market_key,
                locale: FieldResolverService::requestLocale(),
            ),
            'jobdomain_mandatory_fields' => static::mandatoryFieldsByJobdomain($company),
            'storage' => StorageQuotaService::usage($company),
            'member_quota' => [
                'current' => $company->memberships()->count(),
                'limit' => CompanyEntitlements::memberLimit($company),
            ],
        ];
    }

    /**
     * ADR-168b: Return fields that are mandatory for this company's jobdomain.
     */
    private static function mandatoryFieldsByJobdomain(Company $company): array
    {
        if (!$company->jobdomain_key) {
            return [];
        }

        $result = [];

        $locale = FieldResolverService::requestLocale();

        foreach (FieldDefinitionCatalog::all() as $field) {
            $requiredByJobdomains = $field['validation_rules']['required_by_jobdomains'] ?? [];
            if (in_array($company->jobdomain_key, $requiredByJobdomains)) {
                $translations = $field['translations'] ?? [];
                $label = $translations[$locale] ?? $translations['en'] ?? $field['label'];

                $result[] = [
                    'code' => $field['code'],
                    'label' => $label,
                ];
            }
        }

        return $result;
    }
}
