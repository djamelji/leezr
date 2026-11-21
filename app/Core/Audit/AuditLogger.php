<?php

namespace App\Core\Audit;

use App\Core\Notifications\NotificationDispatcher;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use App\Core\Realtime\TopicRegistry;
use App\Notifications\BillingCriticalAlert;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * ADR-130: Central audit logging service.
 *
 * DB write FIRST, then realtime publish (DB = source of truth).
 * Registered as a singleton in AppServiceProvider.
 */
class AuditLogger
{
    public function __construct(
        private readonly Request $request,
        private readonly RealtimePublisher $publisher,
    ) {}

    /**
     * Log a platform-level audit event.
     */
    public function logPlatform(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $options = [],
    ): PlatformAuditLog {
        $actor = $this->resolvePlatformActor();

        $log = PlatformAuditLog::create([
            'actor_id' => $options['actorId'] ?? $actor['id'],
            'actor_type' => self::platformActorType($options['actorType'] ?? $actor['type']),
            'actor_role' => array_key_exists('actorType', $options) ? null : $actor['role'],
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'severity' => $options['severity'] ?? 'info',
            'diff_before' => $options['diffBefore'] ?? null,
            'diff_after' => $options['diffAfter'] ?? null,
            'correlation_id' => $options['correlationId'] ?? null,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->userAgent(),
            'metadata' => $this->withClientFlags($options['metadata'] ?? null),
            'created_at' => now(),
        ]);

        Log::info('[audit] platform', [
            'action' => $action,
            'actor_id' => $log->actor_id,
            'target' => "{$targetType}:{$targetId}",
        ]);

        // Publish realtime event (after DB write)
        try {
            $this->publisher->publish(
                EventEnvelope::audit('audit.logged', null, [
                    'audit_id' => $log->id,
                    'action' => $action,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'severity' => $log->severity,
                ])
            );
        } catch (\Throwable $e) {
            Log::warning('[audit] realtime publish failed', [
                'audit_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Dispatch critical alert notification if enabled (ADR-140)
        $this->dispatchCriticalAlertIfNeeded($log);

        return $log;
    }

    /**
     * Resolve the platform actor identity from the authenticated user.
     */
    private function resolvePlatformActor(): array
    {
        $user = $this->request->user('platform');

        if (! $user instanceof PlatformUser) {
            return ['id' => null, 'type' => 'system', 'role' => null];
        }

        $roles = $user->relationLoaded('roles')
            ? $user->roles
            : $user->roles()->get();

        // ADR-847 : the CATEGORY is « admin » ; the role key (any length a role may have) goes to actor_role
        $role = $roles->contains('key', 'super_admin') ? 'super_admin' : $roles->first()?->key;

        return ['id' => $user->id, 'type' => 'admin', 'role' => $role];
    }

    /** ADR-847 : platform actor categories (actor_type, varchar 20). An unknown value is a programming error : loud, never truncated. */
    public const PLATFORM_ACTOR_TYPES = ['admin', 'system', 'webhook'];

    private static function platformActorType(string $type): string
    {
        if (! in_array($type, self::PLATFORM_ACTOR_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown platform audit actor type « $type » (expected: ".implode(', ', self::PLATFORM_ACTOR_TYPES).')');
        }

        return $type;
    }

    /**
     * Log a company-scoped audit event.
     */
    public function logCompany(
        int $companyId,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $options = [],
    ): CompanyAuditLog {
        $log = CompanyAuditLog::create([
            'company_id' => $companyId,
            'actor_id' => $options['actorId'] ?? $this->request->user()?->id,
            'actor_type' => $options['actorType'] ?? 'user',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'severity' => $options['severity'] ?? 'info',
            'diff_before' => $options['diffBefore'] ?? null,
            'diff_after' => $options['diffAfter'] ?? null,
            'correlation_id' => $options['correlationId'] ?? null,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->userAgent(),
            'metadata' => $this->withClientFlags($options['metadata'] ?? null),
            'created_at' => now(),
        ]);

        Log::info('[audit] company', [
            'company_id' => $companyId,
            'action' => $action,
            'actor_id' => $log->actor_id,
            'target' => "{$targetType}:{$targetId}",
        ]);

        // Publish realtime event (after DB write)
        try {
            $this->publisher->publish(
                EventEnvelope::audit('audit.logged', $companyId, [
                    'audit_id' => $log->id,
                    'action' => $action,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'severity' => $log->severity,
                ])
            );
        } catch (\Throwable $e) {
            // Realtime publish failure must not break the request
            Log::warning('[audit] realtime publish failed', [
                'audit_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    /**
     * Dispatch a critical alert notification if billing alerting is enabled.
     * Graceful: never breaks the audit flow on dispatch failure.
     */
    private function dispatchCriticalAlertIfNeeded(PlatformAuditLog $log): void
    {
        if ($log->severity !== 'critical' || ! config('billing.alerting.enabled')) {
            return;
        }

        $email = config('billing.alerting.email');

        if (! $email) {
            return;
        }

        try {
            // Platform-level alert: route to configured email + in-app via NotificationDispatcher
            // Also dispatch mail via on-demand notification for the configured alert email
            Notification::route('mail', $email)
                ->notify(new BillingCriticalAlert($log));

            // In-app notification for platform admins who are actual users
            $platformAdmins = PlatformUser::whereHas('roles', fn ($q) => $q->where('key', 'super_admin'))
                ->get();

            if ($platformAdmins->isNotEmpty()) {
                NotificationDispatcher::send(
                    topicKey: 'security.alert',
                    recipients: $platformAdmins,
                    payload: ['audit_id' => $log->id, 'action' => $log->action, 'severity' => $log->severity],
                    company: null,
                    mailNotification: null,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[audit] critical alert dispatch failed', [
                'audit_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ADR-847 — the user agent is CLIENT-supplied (any length) and stored in a 500-character column : longer values
     * would make the audited action itself fail (login included). Kept to the column size and FLAGGED in metadata
     * (`user_agent_truncated`), never cut silently. Identifiers (actor, role, target) are never truncated.
     */
    public const USER_AGENT_MAX = 500;

    private function userAgent(): ?string
    {
        $ua = $this->request->userAgent();

        return $ua === null ? null : mb_substr($ua, 0, self::USER_AGENT_MAX);
    }

    private function withClientFlags(?array $metadata): ?array
    {
        $ua = $this->request->userAgent();
        if ($ua !== null && mb_strlen($ua) > self::USER_AGENT_MAX) {
            return ($metadata ?? []) + ['user_agent_truncated' => true, 'user_agent_length' => mb_strlen($ua)];
        }

        return $metadata;
    }
}
