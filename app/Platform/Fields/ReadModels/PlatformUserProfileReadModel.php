<?php

namespace App\Platform\Fields\ReadModels;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Platform\Models\PlatformUser;

class PlatformUserProfileReadModel
{
    public static function get(PlatformUser $user): array
    {
        return [
            'base_fields' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $user->roles,
            ],
            'dynamic_fields' => FieldResolverService::resolve(
                model: $user,
                scope: FieldDefinition::SCOPE_PLATFORM_USER,
            ),
        ];
    }
}
