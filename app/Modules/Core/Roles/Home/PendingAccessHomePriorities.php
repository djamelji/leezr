<?php

namespace App\Modules\Core\Roles\Home;

use App\Core\Home\HomeContext;
use App\Core\Home\HomePriority;
use App\Core\Home\HomePriorityProvider;
use App\Core\Modules\Setup\PendingModuleAccess;

/** ADR-809 — « Accès à confirmer » on the home of whoever can decide (roles.manage) : roles with pending rights. */
class PendingAccessHomePriorities implements HomePriorityProvider
{
    public function priorities(HomeContext $home): array
    {
        if (! $home->can('roles.manage')) {
            return [];
        }
        $roles = count(PendingModuleAccess::summary($home->company, null)['roles']);

        return [HomePriority::make('access_to_confirm', $roles, 'warning', 'tabler-shield-check', ['name' => 'company-people-roles'], 'team')];
    }
}
