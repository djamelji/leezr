<?php

namespace App\Core\Workforce;

use Illuminate\Database\Eloquent\Model;

/**
 * Favori personnel (user × objet workforce, scopé Company) — Refonte Smart Planning M5 (ADR-728).
 */
class WorkforceFavorite extends Model
{
    protected $table = 'workforce_favorites';

    public const TYPES = ['activity_preset', 'schedule_template', 'week_template'];

    protected $fillable = ['user_id', 'company_id', 'favoritable_type', 'favoritable_id'];

    protected $casts = [
        'user_id' => 'integer',
        'company_id' => 'integer',
        'favoritable_id' => 'integer',
    ];
}
