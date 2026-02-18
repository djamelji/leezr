<?php

namespace App\Modules\Platform\Users\ReadModels;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Platform\Models\PlatformUser;

class UserProfileReadModel
{
    public static function get(PlatformUser $user): array
    {
        return [
            'base_fields' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->display_name,
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
