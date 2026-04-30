<?php

namespace App\Core\Support;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Traits\BelongsToCompany;
use App\Platform\Models\PlatformUser;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'uuid',
        'company_id',
        'created_by_user_id',
        'assigned_to_platform_user_id',
        'subject',
        'status',
        'priority',
        'category',
        'last_message_at',
        'first_response_at',
        'resolved_at',
        'closed_by_platform_user_id',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected $appends = ['sla_status'];

    /**
     * SLA targets in hours per priority level.
     */
    public const SLA_TARGETS = [
        'urgent' => ['first_response' => 2, 'resolution' => 8],
        'high' => ['first_response' => 4, 'resolution' => 24],
        'normal' => ['first_response' => 8, 'resolution' => 48],
        'low' => ['first_response' => 24, 'resolution' => 72],
    ];

    /**
     * Accessor: computed SLA status for this ticket.
     *
     * Returns an object with:
     * - response: { status, deadline, elapsed_hours, target_hours }
     * - resolution: { status, deadline, elapsed_hours, target_hours }
     *
     * Status values: on_track, warning (>75% elapsed), breached (exceeded)
     */
    public function getSlaStatusAttribute(): array
    {
        $targets = self::SLA_TARGETS[$this->priority] ?? self::SLA_TARGETS['normal'];
        $now = Carbon::now();

        return [
            'response' => $this->computeSlaMetric(
                $this->created_at,
                $this->first_response_at,
                $targets['first_response'],
                $now,
            ),
            'resolution' => $this->computeSlaMetric(
                $this->created_at,
                $this->resolved_at,
                $targets['resolution'],
                $now,
            ),
        ];
    }

    /**
     * Compute a single SLA metric (response or resolution).
     */
    private function computeSlaMetric(
        ?Carbon $start,
        ?Carbon $completed,
        int $targetHours,
        Carbon $now,
    ): array {
        if (! $start) {
            return [
                'status' => 'on_track',
                'deadline' => null,
                'elapsed_hours' => 0,
                'target_hours' => $targetHours,
                'remaining_hours' => $targetHours,
            ];
        }

        $deadline = $start->copy()->addHours($targetHours);

        // If the metric is already completed (responded / resolved)
        if ($completed) {
            $elapsedHours = round(abs($start->diffInMinutes($completed)) / 60, 1);
            $breached = $completed->greaterThan($deadline);

            return [
                'status' => $breached ? 'breached' : 'on_track',
                'deadline' => $deadline->toIso8601String(),
                'elapsed_hours' => $elapsedHours,
                'target_hours' => $targetHours,
                'remaining_hours' => 0,
                'completed' => true,
            ];
        }

        // Still open — compute based on current time
        $elapsedHours = round(abs($start->diffInMinutes($now)) / 60, 1);
        $remainingHours = round(max(0, ($targetHours * 60 - abs($start->diffInMinutes($now))) / 60), 1);
        $percentElapsed = ($elapsedHours / $targetHours) * 100;

        if ($percentElapsed >= 100) {
            $status = 'breached';
        } elseif ($percentElapsed >= 75) {
            $status = 'warning';
        } else {
            $status = 'on_track';
        }

        return [
            'status' => $status,
            'deadline' => $deadline->toIso8601String(),
            'elapsed_hours' => $elapsedHours,
            'target_hours' => $targetHours,
            'remaining_hours' => $remainingHours,
            'completed' => false,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            $ticket->uuid ??= (string) Str::uuid();
            $ticket->status ??= 'open';
            $ticket->priority ??= 'normal';
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'assigned_to_platform_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'closed_by_platform_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    public function latestMessage()
    {
        return $this->hasOne(SupportMessage::class, 'ticket_id')->latestOfMany();
    }
}
