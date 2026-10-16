<?php

namespace App\Modules\Platform\Automations;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class AutomationsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.automations',
            name: 'Automations',
            description: 'Centralized automation center — manage and monitor all scheduled tasks.',
            surface: 'governance',
            sortOrder: 97,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: ['platform-automations', 'platform-operations-tab'],
            ),
            permissions: [
                ['key' => 'manage_automations', 'label' => 'Manage Automations'],
                ['key' => 'run_sensitive_automations', 'label' => 'Run Sensitive Automations (mass financial)'],
            ],
            bundles: [
                [
                    'key' => 'automations.admin',
                    'label' => 'Automation Administration',
                    'hint' => 'Monitor and control all scheduled automation tasks.',
                    'permissions' => ['manage_automations', 'run_sensitive_automations'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
        );
    }
}
