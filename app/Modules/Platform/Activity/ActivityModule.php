<?php

namespace App\Modules\Platform\Activity;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

/**
 * ADR-440: Activity Feed module — platform-wide event journal.
 *
 * Reuses the audit permission gate (view_audit_logs) since it reads from the same
 * audit tables. Activity is the "human-readable feed" view of audit data.
 */
class ActivityModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.activity',
            name: 'Activity Feed',
            description: 'Platform-wide activity feed — chronological journal of all events across companies and platform.',
            surface: 'governance',
            sortOrder: 98,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: ['platform-activity', 'platform-dashboard-tab'],
            ),
            permissions: [],
            bundles: [],
            scope: 'admin',
            type: 'platform',
            requires: ['platform.audit'],
        );
    }
}
