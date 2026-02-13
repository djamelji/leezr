<?php

namespace App\Company\Fields\ReadModels;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Models\Company;

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
            ),
        ];
    }
}
