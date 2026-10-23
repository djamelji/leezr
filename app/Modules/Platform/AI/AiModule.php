<?php

namespace App\Modules\Platform\AI;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class AiModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.ai',
            name: 'AI',
            description: 'AI providers, capability routing and usage monitoring',
            surface: 'structure',
            sortOrder: 63,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: ['platform-ai-tab', 'platform-operations-tab'],
            ),
            permissions: [
                ['key' => 'view_ai', 'label' => 'View AI'],
                ['key' => 'manage_ai', 'label' => 'Manage AI'],
            ],
            bundles: [
                [
                    'key' => 'ai.full',
                    'label' => 'Full AI Management',
                    'hint' => 'Complete AI providers and routing governance.',
                    'permissions' => ['view_ai', 'manage_ai'],
                ],
                [
                    'key' => 'ai.readonly',
                    'label' => 'AI Read-Only',
                    'hint' => 'View AI providers and usage data.',
                    'permissions' => ['view_ai'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
            visibility: 'visible',
            iconRef: 'tabler-brain',
            settingsRoute: null,
        );
    }
}
