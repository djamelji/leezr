<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmploymentContract extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_employment_contracts';

    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_TERMINATED = 'terminated';

    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_TERMINATED,
    ];

    const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_ACTIVE],
        self::STATUS_ACTIVE => [self::STATUS_SUSPENDED, self::STATUS_TERMINATED],
        self::STATUS_SUSPENDED => [self::STATUS_ACTIVE, self::STATUS_TERMINATED],
        self::STATUS_TERMINATED => [],
    ];

    const CONTRACT_TYPES = ['cdi', 'cdd', 'stage', 'alternance', 'freelance'];

    const WORK_MODELS = ['horaire', 'forfait_jours', 'forfait_heures'];

    protected $fillable = [
        'company_id',
        'convention_collective_id',
        'employee_id',
        'contract_type',
        'work_model_key',
        'weekly_hours',
        'start_date',
        'end_date',
        'probation_end_date',
        'status',
        'is_current',
        'custom_compliance_rules',
        'metadata',
    ];

    protected $casts = [
        'weekly_hours' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'probation_end_date' => 'date',
        'is_current' => 'boolean',
        'custom_compliance_rules' => 'array',
        'metadata' => 'array',
    ];

    // ── Relationships ──

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function conventionCollective(): BelongsTo
    {
        return $this->belongsTo(ConventionCollective::class, 'convention_collective_id');
    }

    public function compensationPlans(): HasMany
    {
        return $this->hasMany(CompensationPlan::class, 'contract_id');
    }

    public function currentCompensation(): HasOne
    {
        return $this->hasOne(CompensationPlan::class, 'contract_id')
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', now()))
            ->orderByDesc('effective_from')
            ->limit(1);
    }

    // ── State Machine ──

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? []);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException("Cannot transition Contract from {$this->status} to {$newStatus}");
        }

        $this->status = $newStatus;
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }
}
