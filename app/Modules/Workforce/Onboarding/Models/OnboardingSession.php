<?php

namespace App\Modules\Workforce\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;

/** ADR-796 — short-lived candidate session (HttpOnly cookie) obtained by exchanging the link token. Hash only. */
class OnboardingSession extends Model
{
    protected $fillable = ['onboarding_invitation_id', 'session_hash', 'expires_at', 'verified_at'];

    protected $hidden = ['session_hash'];

    protected $casts = ['expires_at' => 'datetime', 'verified_at' => 'datetime'];
}
