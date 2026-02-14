<?php

namespace App\Company\Fields\ReadModels;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Models\Company;
use App\Core\Models\User;

class CompanyUserProfileReadModel
{
    public static function get(User $user, Company $company): array
    {
        return [
            'base_fields' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->display_name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'status' => $user->status,
            ],
            'dynamic_fields' => FieldResolverService::resolve(
                model: $user,
                scope: FieldDefinition::SCOPE_COMPANY_USER,
                companyId: $company->id,
            ),
        ];
    }
}
