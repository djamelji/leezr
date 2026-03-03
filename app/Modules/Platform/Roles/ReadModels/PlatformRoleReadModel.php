<?php

namespace App\Modules\Platform\Roles\ReadModels;

use App\Platform\Models\PlatformRole;

class PlatformRoleReadModel
{
    public static function catalog(): array
    {
        return PlatformRole::withCount('users')
            ->with('permissions')
            ->orderBy('key')
            ->get()
            ->toArray();
    }
}
