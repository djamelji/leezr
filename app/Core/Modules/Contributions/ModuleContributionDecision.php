<?php

namespace App\Core\Modules\Contributions;

use Illuminate\Database\Eloquent\Model;

/** ADR-794 (L4) — a Company decision on a module proposal (append-only journal, always filtered by company). */
class ModuleContributionDecision extends Model
{
    public const DECISIONS = ['adopted', 'updated', 'declined', 'kept_customization', 'replaced_customization'];

    protected $fillable = ['company_id', 'module_key', 'ref', 'version', 'decision', 'requirement_id', 'decided_by'];

    protected $casts = ['version' => 'integer'];
}
