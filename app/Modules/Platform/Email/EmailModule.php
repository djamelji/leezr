<?php

namespace App\Modules\Platform\Email;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

/**
 * Email Hub — complete SaaS email management.
 *
 * Inbox (threads + compose + reply), logs, configurable templates,
 * orchestration rules, inbound webhook, SMTP configuration.
 */
class EmailModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.email',
            name: 'Email Hub',
            description: 'Email Hub — inbox, threads, compose, templates, orchestration, SMTP.',
            surface: 'governance',
            sortOrder: 59,
            capabilities: new Capabilities(
                navItems: [
                    // ADR-598 LOT F: 'platform-messaging' is a collapsible parent (no permission) that
                    // shows if any child (Email / Notifications) is authorized.
                    ['key' => 'platform-messaging', 'title' => 'Messaging', 'icon' => 'tabler-mail', 'group' => 'cockpit', 'sort' => 5],
                    ['key' => 'messaging-email', 'title' => 'Email', 'to' => ['name' => 'platform-messaging-tab', 'params' => ['tab' => 'inbox']], 'icon' => 'tabler-inbox', 'permission' => 'manage_email', 'parent' => 'platform-messaging', 'sort' => 10],
                ],
                routeNames: ['platform-email-tab', 'platform-messaging-tab'],
            ),
            permissions: [
                ['key' => 'manage_email', 'label' => 'Manage Emails'],
            ],
            bundles: [
                [
                    'key' => 'email.full',
                    'label' => 'Full Email Management',
                    'hint' => 'Manage email logs, templates and SMTP settings.',
                    'permissions' => ['manage_email'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
        );
    }
}
