<?php

namespace App\Core\Audit;

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
            'actor_type' => $options['actorType'] ?? $actor['type'],
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'severity' => $options['severity'] ?? 'info',
            'diff_before' => $options['diffBefore'] ?? null,
            'diff_after' => $options['diffAfter'] ?? null,
            'correlation_id' => $options['correlationId'] ?? null,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'metadata' => $options['metadata'] ?? null,
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
            return ['id' => null, 'type' => 'system'];
        }

        $roles = $user->relationLoaded('roles')
            ? $user->roles
            : $user->roles()->get();

        $type = $roles->contains('key', 'super_admin')
            ? 'super_admin'
            : ($roles->first()?->key ?? 'admin');

        return ['id' => $user->id, 'type' => $type];
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
            'user_agent' => $this->request->userAgent(),
            'metadata' => $options['metadata'] ?? null,
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
            Notification::route('mail', $email)
                ->notify(new BillingCriticalAlert($log));
        } catch (\Throwable $e) {
            Log::warning('[audit] critical alert dispatch failed', [
                'audit_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
