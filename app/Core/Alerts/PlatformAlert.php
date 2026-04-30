<?php

namespace App\Core\Alerts;

use App\Core\Models\Company;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-438: Centralized platform alert — cross-system aggregation.
 *
 * Sources: billing, security, ai, infra, support, business
 * Severities: critical, warning, info
 * Statuses: active, acknowledged, resolved, dismissed
 */
class PlatformAlert extends Model
{
    protected $fillable = [
        'source', 'type', 'severity', 'status', 'company_id',
        'title', 'description', 'metadata', 'target_type', 'target_id',
        'fingerprint', 'acknowledged_by', 'acknowledged_at', 'resolved_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected $appends = ['incident_timeline'];

    // ── Relations ────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'acknowledged_by');
    }

    // ── Scopes ───────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', 'critical');
    }

    public function scopeBySource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    // ── Fingerprint ──────────────────────────────────────

    /**
     * Generate a dedup fingerprint for an alert.
     * Prevents duplicate alerts for the same source+type+target.
     */
    public static function fingerprint(string $source, string $type, ?string $targetType, mixed $targetId): string
    {
        return hash('sha256', "{$source}:{$type}:{$targetType}:{$targetId}");
    }

    // ── Actions ──────────────────────────────────────────

    public function acknowledge(int $userId): void
    {
        $this->update([
            'status' => 'acknowledged',
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
        ]);
    }

    public function resolve(): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    public function dismiss(): void
    {
        $this->update([
            'status' => 'dismissed',
        ]);
    }

    // ── Incident Timeline (ADR-469) ────────────────────

    /**
     * Build a chronological incident timeline from model timestamps
     * and escalation metadata.
     */
    public function getIncidentTimelineAttribute(): array
    {
        $timeline = [];

        $timeline[] = ['event' => 'created', 'at' => $this->created_at?->toIso8601String()];

        // Escalation events from metadata
        $meta = $this->metadata ?? [];
        if (isset($meta['last_escalated_at'])) {
            $timeline[] = [
                'event' => 'escalated',
                'at' => $meta['last_escalated_at'],
                'count' => $meta['escalation_count'] ?? 1,
            ];
        }

        if ($this->acknowledged_at) {
            $timeline[] = ['event' => 'acknowledged', 'at' => $this->acknowledged_at->toIso8601String()];
        }

        if ($this->resolved_at) {
            $timeline[] = ['event' => 'resolved', 'at' => $this->resolved_at->toIso8601String()];
        }

        usort($timeline, fn ($a, $b) => strcmp($a['at'] ?? '', $b['at'] ?? ''));

        return $timeline;
    }
}
