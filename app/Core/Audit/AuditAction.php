<?php

namespace App\Core\Audit;

/**
 * ADR-130: Closed registry of audit action identifiers.
 *
 * Every audit log entry MUST use one of these constants.
 * Adding new actions requires updating this file.
 */
final class AuditAction
{
    // ─── Auth ─────────────────────────────────────────────
    public const LOGIN = 'auth.login';
    public const LOGIN_FAILED = 'auth.login_failed';
    public const LOGOUT = 'auth.logout';
    public const REGISTER = 'auth.register';
    public const PLATFORM_LOGIN = 'platform_auth.login';
    public const PLATFORM_LOGIN_FAILED = 'platform_auth.login_failed';
    public const PLATFORM_LOGOUT = 'platform_auth.logout';

    // ─── Roles ──────────────────────────────────────────
    public const ROLE_CREATED = 'role.created';
    public const ROLE_UPDATED = 'role.updated';
    public const ROLE_DELETED = 'role.deleted';

    // ─── Modules ────────────────────────────────────────
    public const MODULE_ENABLED = 'module.enabled';
    public const MODULE_DISABLED = 'module.disabled';
    public const MODULE_SETTINGS_UPDATED = 'module.settings_updated';

    // ─── Members ────────────────────────────────────────
    public const MEMBER_ADDED = 'member.added';
    public const MEMBER_PROFILE_UPDATED = 'member.profile_updated';
    public const MEMBER_ROLE_CHANGED = 'member.role_changed';
    public const MEMBER_REMOVED = 'member.removed';

    // ─── User profile (self-service) ─────────────────────
    public const USER_PROFILE_UPDATED = 'user.profile_updated';
    public const USER_PASSWORD_CHANGED = 'user.password_changed';
    public const USER_AVATAR_UPDATED = 'user.avatar_updated';

    // ─── Platform users ──────────────────────────────────
    public const PLATFORM_USER_CREATED = 'platform_user.created';
    public const PLATFORM_USER_UPDATED = 'platform_user.updated';
    public const PLATFORM_USER_PASSWORD_SET = 'platform_user.password_set';
    public const PLATFORM_USER_DELETED = 'platform_user.deleted';

    // ─── Plan / Jobdomain ───────────────────────────────
    public const PLAN_CHANGED = 'plan.changed';
    public const JOBDOMAIN_CHANGED = 'jobdomain.changed';

    // ─── Company lifecycle ──────────────────────────────
    public const COMPANY_SUSPENDED = 'company.suspended';
    public const COMPANY_REACTIVATED = 'company.reactivated';
    public const COMPANY_SETTINGS_UPDATED = 'company.settings_updated';

    // ─── Platform settings ───────────────────────────────
    public const FIELD_CREATED = 'field.created';
    public const FIELD_UPDATED = 'field.updated';
    public const FIELD_DELETED = 'field.deleted';

    // ─── Realtime governance ────────────────────────────
    public const KILL_SWITCH_ACTIVATED = 'realtime.kill_switch_activated';
    public const KILL_SWITCH_DEACTIVATED = 'realtime.kill_switch_deactivated';
    public const KILL_SWITCH_AUTO_ACTIVATED = 'realtime.kill_switch_auto_activated';
    public const CHANNELS_FLUSHED = 'realtime.channels_flushed';

    // ─── Security ───────────────────────────────────────
    public const SECURITY_ALERT_ACKNOWLEDGED = 'security.alert_acknowledged';
    public const SECURITY_ALERT_RESOLVED = 'security.alert_resolved';
}
