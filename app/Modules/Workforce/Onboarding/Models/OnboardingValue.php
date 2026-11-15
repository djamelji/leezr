<?php

namespace App\Modules\Workforce\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;

/** ADR-796 — a value PROVIDED by the candidate, staged until HR decides (never HR data by itself). */
class OnboardingValue extends Model
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const KEPT_EXISTING = 'kept_existing';

    protected $fillable = ['onboarding_invitation_id', 'field_definition_id', 'value', 'status', 'decided_at', 'decided_by'];

    protected $casts = ['value' => 'array', 'decided_at' => 'datetime'];
}
