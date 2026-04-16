<?php

namespace App\Modules\Platform\Activity;

use App\Core\Audit\AuditAction;

/**
 * ADR-440: Builds human-readable description sentences from audit log entries.
 */
final class ActivityDescriber
{
    /**
     * Human-readable label from action constant value.
     * e.g., "billing.invoice_marked_paid" -> "Invoice marked paid"
     */
    public static function humanLabel(string $action): string
    {
        $parts = explode('.', $action, 2);
        $suffix = $parts[1] ?? $parts[0];

        return ucfirst(str_replace('_', ' ', $suffix));
    }

    /**
     * Build a human-readable description sentence.
     */
    public static function describe(
        string $action,
        string $actorName,
        ?string $companyName,
        ?string $targetType,
        ?string $targetId,
    ): string {
        $target = $targetType ? " on {$targetType}" : '';
        $company = $companyName ? " for {$companyName}" : '';
        $verb = self::humanLabel($action);

        return match (true) {
            str_starts_with($action, 'auth.login') && !str_contains($action, 'failed')
                => "{$actorName} logged in",
            str_starts_with($action, 'auth.logout')
                => "{$actorName} logged out",
            str_starts_with($action, 'auth.login_failed')
                => "Failed login attempt for {$actorName}",
            str_starts_with($action, 'platform_auth.login') && !str_contains($action, 'failed')
                => "Admin {$actorName} logged in",
            str_starts_with($action, 'platform_auth.logout')
                => "Admin {$actorName} logged out",
            str_starts_with($action, 'platform_auth.login_failed')
                => "Failed platform login attempt",

            $action === AuditAction::COMPANY_SUSPENDED => "Admin {$actorName} suspended{$company}",
            $action === AuditAction::COMPANY_REACTIVATED => "Admin {$actorName} reactivated{$company}",

            $action === AuditAction::PLAN_CHANGED => "{$actorName} changed plan{$company}",
            $action === AuditAction::PLAN_CHANGE_REQUESTED => "{$actorName} requested plan change{$company}",
            $action === AuditAction::PLAN_CHANGE_EXECUTED => "Plan change executed{$company}",
            $action === AuditAction::CANCEL_REQUESTED => "{$actorName} requested cancellation{$company}",
            $action === AuditAction::CANCEL_EXECUTED => "Subscription cancelled{$company}",

            $action === AuditAction::INVOICE_MARKED_PAID => "{$actorName} marked invoice #{$targetId} as paid{$company}",
            $action === AuditAction::INVOICE_VOIDED => "{$actorName} voided invoice #{$targetId}{$company}",
            $action === AuditAction::INVOICE_WRITTEN_OFF => "{$actorName} wrote off invoice #{$targetId}{$company}",
            $action === AuditAction::CREDIT_NOTE_ISSUED => "{$actorName} issued credit note{$company}",
            $action === AuditAction::BILLING_REFUND => "{$actorName} processed refund{$company}",

            $action === AuditAction::MEMBER_ADDED => "{$actorName} added a member{$company}",
            $action === AuditAction::MEMBER_REMOVED => "{$actorName} removed a member{$company}",
            $action === AuditAction::MEMBER_ROLE_CHANGED => "{$actorName} changed a member's role{$company}",

            $action === AuditAction::MODULE_ENABLED => "{$actorName} enabled module{$target}{$company}",
            $action === AuditAction::MODULE_DISABLED => "{$actorName} disabled module{$target}{$company}",

            $action === AuditAction::ROLE_CREATED => "{$actorName} created a role{$company}",
            $action === AuditAction::ROLE_UPDATED => "{$actorName} updated a role{$company}",
            $action === AuditAction::ROLE_DELETED => "{$actorName} deleted a role{$company}",

            $action === AuditAction::KILL_SWITCH_ACTIVATED => "{$actorName} activated realtime kill switch",
            $action === AuditAction::KILL_SWITCH_DEACTIVATED => "{$actorName} deactivated realtime kill switch",

            $action === AuditAction::DOCUMENT_REQUESTED => "{$actorName} requested a document{$company}",
            $action === AuditAction::DOCUMENT_BATCH_REQUESTED => "{$actorName} batch requested documents{$company}",

            $action === AuditAction::WEBHOOK_PAYMENT_SYNCED => "Payment synced via webhook{$company}",
            $action === AuditAction::WEBHOOK_PAYMENT_FAILED => "Payment failed via webhook{$company}",
            $action === AuditAction::WEBHOOK_DISPUTE_CREATED => "Dispute created via webhook{$company}",

            $action === AuditAction::BILLING_DRIFT_DETECTED => "Billing drift detected{$company}",
            $action === AuditAction::BILLING_AUTO_REPAIR_APPLIED => "Auto-repair applied{$company}",
            $action === AuditAction::BILLING_PERIOD_CLOSED => "{$actorName} closed billing period",

            default => "{$actorName} — {$verb}{$target}{$company}",
        };
    }
}
