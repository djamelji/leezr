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
                    [
                        'key' => 'platform-messaging',
                        'title' => 'Messaging',
                        'to' => ['name' => 'platform-email-tab', 'params' => ['tab' => 'inbox']],
                        'icon' => 'tabler-mail',
                        'group' => 'operations',
                        'sort' => 60,
                    ],
                ],
                routeNames: ['platform-email-tab'],
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
