<?php

namespace App\Core\Notifications;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Platform\Models\PlatformUser;

/**
 * Declarative registry of all notification topics.
 * Single source of truth for what notification topics exist.
 * The DB table (notification_topics) stores metadata; this class seeds them.
 */
class NotificationTopicRegistry
{
    private static array $topics = [];

    /**
     * Category → required company permission mapping.
     * null = universal (all users see it).
     * Single source of truth for permission gating — ADR-382.
     */
    public const CATEGORY_PERMISSIONS = [
        'billing' => 'billing.manage',
        'documents' => 'documents.manage',
        'members' => 'members.view',
        'modules' => 'modules.manage',
        'security' => null,
        'support' => 'support.view',
        'system' => null,
    ];

    /**
     * Category → required platform permission mapping — ADR-382.
     * null = universal (all platform admins receive it).
     */
    public const PLATFORM_CATEGORY_PERMISSIONS = [
        'billing' => 'view_billing',
        'support' => 'manage_support',
        'security' => null,
        'system' => null,
    ];

    /** Categories where in_app channel is forced-on (cannot be disabled). */
    public const LOCKED_CATEGORIES = ['security'];

    /** Commercial bundle metadata per category (company). */
    public const BUNDLE_META = [
        'billing' => ['icon' => 'tabler-receipt-2', 'color' => 'warning'],
        'documents' => ['icon' => 'tabler-file-certificate', 'color' => 'info'],
        'members' => ['icon' => 'tabler-users', 'color' => 'info'],
        'modules' => ['icon' => 'tabler-puzzle', 'color' => 'primary'],
        'security' => ['icon' => 'tabler-shield-lock', 'color' => 'error'],
        'support' => ['icon' => 'tabler-headset', 'color' => 'success'],
    ];

    /** Bundle metadata per category (platform). */
    public const PLATFORM_BUNDLE_META = [
        'billing' => ['icon' => 'tabler-credit-card', 'color' => 'warning'],
        'support' => ['icon' => 'tabler-headset', 'color' => 'success'],
        'security' => ['icon' => 'tabler-shield-lock', 'color' => 'error'],
        'system' => ['icon' => 'tabler-settings-cog', 'color' => 'primary'],
    ];

    public static function permissionForCategory(string $category): ?string
    {
        return static::CATEGORY_PERMISSIONS[$category] ?? null;
    }

    /**
     * Return company-scoped categories that a user has permission to access.
     * Owner sees all.
     */
    public static function categoriesForUser(User $user, Company $company): array
    {
        // Use DB (NotificationTopic model) — the in-memory registry is only populated during seeding
        $allCategories = NotificationTopic::active()
            ->forScope('company')
            ->distinct()
            ->pluck('category');

        if ($user->isOwnerOf($company)) {
            return $allCategories->values()->all();
        }

        return $allCategories->filter(function ($cat) use ($user, $company) {
            $perm = static::CATEGORY_PERMISSIONS[$cat] ?? null;

            return $perm === null || $user->hasCompanyPermission($company, $perm);
        })->values()->all();
    }

    /**
     * Return platform-scoped categories that a platform admin has permission to access.
     * Super admin sees all. — ADR-382.
     */
    public static function platformCategoriesForAdmin(PlatformUser $admin): array
    {
        $allCategories = NotificationTopic::active()
            ->forScope('platform')
            ->distinct()
            ->pluck('category');

        if ($admin->isSuperAdmin()) {
            return $allCategories->values()->all();
        }

        return $allCategories->filter(function ($cat) use ($admin) {
            $perm = static::PLATFORM_CATEGORY_PERMISSIONS[$cat] ?? null;

            return $perm === null || $admin->hasPermission($perm);
        })->values()->all();
    }

    public static function register(array $topics): void
    {
        foreach ($topics as $key => $definition) {
            static::$topics[$key] = $definition;
        }
    }

    public static function all(): array
    {
        return static::$topics;
    }

    public static function forScope(string $scope): array
    {
        return array_filter(static::$topics, fn ($t) => $t['scope'] === $scope || $t['scope'] === 'both');
    }

    public static function sync(): void
    {
        foreach (static::$topics as $key => $definition) {
            NotificationTopic::updateOrCreate(
                ['key' => $key],
                [
                    'label' => $definition['label'],
                    'description' => $definition['description'] ?? null,
                    'category' => $definition['category'],
                    'icon' => $definition['icon'],
                    'scope' => $definition['scope'],
                    'severity' => $definition['severity'] ?? 'info',
                    'default_channels' => $definition['default_channels'],
                    'is_active' => $definition['is_active'] ?? true,
                    'sort_order' => $definition['sort_order'] ?? 0,
                ],
            );
        }
    }

    public static function boot(): void
    {
        static::register([
            // ─── Billing topics ──────────────────────────────────────
            'billing.payment_failed' => [
                'label' => 'Payment Failed',
                'category' => 'billing',
                'scope' => 'company',
                'icon' => 'tabler-credit-card-off',
                'severity' => 'error',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 1,
                'description' => 'Notification when a payment attempt fails',
            ],
            'billing.invoice_created' => [
                'label' => 'Invoice Created',
                'category' => 'billing',
                'scope' => 'company',
                'icon' => 'tabler-file-invoice',
                'severity' => 'info',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 2,
                'description' => 'Notification when a new invoice is generated',
            ],
            'billing.payment_received' => [
                'label' => 'Payment Received',
                'category' => 'billing',
                'scope' => 'company',
                'icon' => 'tabler-cash',
                'severity' => 'success',
                'default_channels' => ['in_app'],
                'sort_order' => 3,
                'description' => 'Notification when a payment is successfully received',
            ],
            'billing.plan_changed' => [
                'label' => 'Plan Changed',
                'category' => 'billing',
                'scope' => 'company',
                'icon' => 'tabler-switch-horizontal',
                'severity' => 'info',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 4,
                'description' => 'Notification when the subscription plan is changed',
            ],
            'billing.trial_expiring' => [
                'label' => 'Trial Expiring',
                'category' => 'billing',
                'scope' => 'company',
                'icon' => 'tabler-clock-exclamation',
                'severity' => 'warning',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 5,
                'description' => 'Notification when the trial period is about to expire',
            ],
            'billing.trial_started' => [
                'label' => 'Trial Started',
                'category' => 'billing',
                'scope' => 'company',
                'icon' => 'tabler-clock-play',
                'severity' => 'success',
                'default_channels' => ['in_app'],
                'sort_order' => 6,
                'description' => 'Notification when a trial period begins',
            ],
            'billing.trial_converted' => [
                'label' => 'Trial Converted',
                'category' => 'billing',
                'scope' => 'company',
                'icon' => 'tabler-clock-check',
                'severity' => 'success',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 7,
                'description' => 'Notification when a trial converts to a paid subscription',
            ],
            'billing.payment_method_expiring' => [
                'label' => 'Payment Method Expiring',
                'category' => 'billing',
                'scope' => 'company',
                'icon' => 'tabler-credit-card-refund',
                'severity' => 'warning',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 8,
                'description' => 'Notification when a payment method is about to expire',
            ],
            'billing.account_suspended' => [
                'label' => 'Account Suspended',
                'category' => 'billing',
                'scope' => 'company',
                'icon' => 'tabler-ban',
                'severity' => 'error',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 9,
                'description' => 'Notification when the account is suspended due to billing issues',
            ],
            'billing.addon_activated' => [
                'label' => 'Add-on Activated',
                'category' => 'billing',
                'scope' => 'company',
                'icon' => 'tabler-puzzle',
                'severity' => 'success',
                'default_channels' => ['in_app'],
                'sort_order' => 10,
                'description' => 'Notification when an add-on module is activated',
            ],

            // ─── Members topics ──────────────────────────────────────
            'members.invited' => [
                'label' => 'Member Invited',
                'category' => 'members',
                'scope' => 'company',
                'icon' => 'tabler-user-plus',
                'severity' => 'info',
                'default_channels' => ['in_app'],
                'sort_order' => 11,
                'description' => 'Notification when a new member is invited to the company',
            ],
            'members.joined' => [
                'label' => 'Member Joined',
                'category' => 'members',
                'scope' => 'company',
                'icon' => 'tabler-user-check',
                'severity' => 'success',
                'default_channels' => ['in_app'],
                'sort_order' => 12,
                'description' => 'Notification when an invited member joins the company',
            ],
            'members.removed' => [
                'label' => 'Member Removed',
                'category' => 'members',
                'scope' => 'company',
                'icon' => 'tabler-user-minus',
                'severity' => 'warning',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 13,
                'description' => 'Notification when a member is removed from the company',
            ],
            'members.role_changed' => [
                'label' => 'Role Changed',
                'category' => 'members',
                'scope' => 'company',
                'icon' => 'tabler-user-cog',
                'severity' => 'info',
                'default_channels' => ['in_app'],
                'sort_order' => 14,
                'description' => 'Notification when a member\'s role is changed',
            ],

            // ─── Modules topics ──────────────────────────────────────
            'modules.activated' => [
                'label' => 'Module Activated',
                'category' => 'modules',
                'scope' => 'company',
                'icon' => 'tabler-plug-connected',
                'severity' => 'success',
                'default_channels' => ['in_app'],
                'sort_order' => 15,
                'description' => 'Notification when a module is activated for the company',
            ],
            'modules.deactivated' => [
                'label' => 'Module Deactivated',
                'category' => 'modules',
                'scope' => 'company',
                'icon' => 'tabler-plug-connected-x',
                'severity' => 'warning',
                'default_channels' => ['in_app'],
                'sort_order' => 16,
                'description' => 'Notification when a module is deactivated for the company',
            ],

            // ─── Documents topics (ADR-385) ──────────────────────────
            'documents.expiring_soon' => [
                'label' => 'Document Expiring Soon',
                'category' => 'documents',
                'scope' => 'company',
                'icon' => 'tabler-file-alert',
                'severity' => 'warning',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 17,
                'description' => 'Notification when a document is about to expire (within 30 days)',
            ],
            'documents.expired' => [
                'label' => 'Document Expired',
                'category' => 'documents',
                'scope' => 'company',
                'icon' => 'tabler-file-x',
                'severity' => 'error',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 18,
                'description' => 'Notification when a document has expired and needs renewal',
            ],
            'documents.submitted' => [
                'label' => 'Document Submitted',
                'category' => 'documents',
                'scope' => 'company',
                'icon' => 'tabler-file-upload',
                'severity' => 'info',
                'default_channels' => ['in_app'],
                'sort_order' => 19,
                'description' => 'Notification when a member submits a document for review',
            ],
            'documents.request_new' => [
                'label' => 'Document Requested',
                'category' => 'documents',
                'scope' => 'company',
                'icon' => 'tabler-file-plus',
                'severity' => 'info',
                'default_channels' => ['in_app'],
                'sort_order' => 20,
                'description' => 'Notification when a document is requested from a member',
            ],

            'documents.reviewed' => [
                'label' => 'Document Reviewed',
                'category' => 'documents',
                'scope' => 'company',
                'icon' => 'tabler-file-check',
                'severity' => 'info',
                'default_channels' => ['in_app'],
                'sort_order' => 21,
                'description' => 'Notification when a submitted document has been approved or rejected',
            ],
            'documents.request_cancelled' => [
                'label' => 'Document Request Cancelled',
                'category' => 'documents',
                'scope' => 'company',
                'icon' => 'tabler-file-x',
                'severity' => 'warning',
                'default_channels' => ['in_app'],
                'sort_order' => 22,
                'description' => 'Notification when a pending document request is cancelled',
            ],
            'documents.ai_analyzed' => [
                'label' => 'Document AI Analysis Complete',
                'category' => 'documents',
                'scope' => 'company',
                'icon' => 'tabler-robot',
                'severity' => 'info',
                'default_channels' => ['in_app'],
                'sort_order' => 23,
                'description' => 'Notification when AI analysis of a member document is complete (no auto-reject)',
            ],

            // ─── Security topics ─────────────────────────────────────
            'security.alert' => [
                'label' => 'Security Alert',
                'category' => 'security',
                'scope' => 'both',
                'icon' => 'tabler-shield-exclamation',
                'severity' => 'error',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 17,
                'description' => 'Notification when a security alert is raised',
            ],

            // ─── Platform-scoped topics (admin SaaS) ───────────────────
            'platform.new_subscription' => [
                'label' => 'New Subscription',
                'category' => 'billing',
                'scope' => 'platform',
                'icon' => 'tabler-user-plus',
                'severity' => 'success',
                'default_channels' => ['in_app'],
                'sort_order' => 20,
                'description' => 'Notification when a company subscribes to a plan',
            ],
            'platform.plan_changed' => [
                'label' => 'Plan Changed (Admin)',
                'category' => 'billing',
                'scope' => 'platform',
                'icon' => 'tabler-switch-horizontal',
                'severity' => 'info',
                'default_channels' => ['in_app'],
                'sort_order' => 21,
                'description' => 'Notification when a company changes plan (upgrade/downgrade)',
            ],
            'platform.cancellation_requested' => [
                'label' => 'Cancellation Requested',
                'category' => 'billing',
                'scope' => 'platform',
                'icon' => 'tabler-x',
                'severity' => 'warning',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 22,
                'description' => 'Notification when a company requests cancellation',
            ],
            'platform.payment_failed_alert' => [
                'label' => 'Payment Failed (Admin)',
                'category' => 'billing',
                'scope' => 'platform',
                'icon' => 'tabler-credit-card-off',
                'severity' => 'error',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 23,
                'description' => 'Notification when a company payment fails (admin visibility)',
            ],
            'platform.new_company_registered' => [
                'label' => 'New Company Registered',
                'category' => 'system',
                'scope' => 'platform',
                'icon' => 'tabler-building-plus',
                'severity' => 'success',
                'default_channels' => ['in_app'],
                'sort_order' => 24,
                'description' => 'Notification when a new company registers on the platform',
            ],
            'platform.trial_expired' => [
                'label' => 'Trial Expired',
                'category' => 'billing',
                'scope' => 'platform',
                'icon' => 'tabler-clock-x',
                'severity' => 'warning',
                'default_channels' => ['in_app'],
                'sort_order' => 25,
                'description' => 'Notification when a company trial period expires without conversion',
            ],
            'platform.account_suspended' => [
                'label' => 'Account Suspended (Admin)',
                'category' => 'billing',
                'scope' => 'platform',
                'icon' => 'tabler-ban',
                'severity' => 'error',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 26,
                'description' => 'Notification when a company account is suspended',
            ],

            // ─── Scheduler topics ──────────────────────────────────────
            'platform.scheduler_task_failed' => [
                'label' => 'Scheduler Task Failed',
                'description' => 'A scheduled automation task has failed',
                'category' => 'system',
                'icon' => 'tabler-alert-triangle',
                'severity' => 'critical',
                'default_channels' => ['in_app'],
                'scope' => 'platform',
                'is_active' => true,
                'sort_order' => 900,
            ],

            // ─── Support topics ─────────────────────────────────────
            'support.ticket_created' => [
                'label' => 'New Support Ticket',
                'category' => 'support',
                'scope' => 'platform',
                'icon' => 'tabler-ticket',
                'severity' => 'info',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 30,
                'description' => 'Notification when a company creates a new support ticket',
            ],
            'support.ticket_replied' => [
                'label' => 'Support Ticket Reply',
                'category' => 'support',
                'scope' => 'both',
                'icon' => 'tabler-message-reply',
                'severity' => 'info',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 31,
                'description' => 'Notification when a reply is posted on a support ticket',
            ],
            'support.ticket_resolved' => [
                'label' => 'Support Ticket Resolved',
                'category' => 'support',
                'scope' => 'company',
                'icon' => 'tabler-circle-check',
                'severity' => 'success',
                'default_channels' => ['in_app', 'email'],
                'sort_order' => 32,
                'description' => 'Notification when a support ticket is marked as resolved',
            ],
            'support.ticket_assigned' => [
                'label' => 'Support Ticket Assigned',
                'category' => 'support',
                'scope' => 'platform',
                'icon' => 'tabler-user-check',
                'severity' => 'info',
                'default_channels' => ['in_app'],
                'sort_order' => 33,
                'description' => 'Notification when a support ticket is assigned to an admin',
            ],
        ]);
    }
}
