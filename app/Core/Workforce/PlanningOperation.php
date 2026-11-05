<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Commande de planning RÉVERSIBLE (Smart Planning Lot 4B.3-B, ADR-722).
 * Historique distinct de l'audit permanent ; alimente annuler/rétablir (commandes inverses serveur).
 */
class PlanningOperation extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_planning_operations';

    const STATUS_ACTIVE = 'active';
    const STATUS_UNDONE = 'undone';

    // Types « création » : annuler = supprimer les brouillons créés ; rétablir = réexécuter l'intention.
    const CREATED_TYPES = ['create_shift', 'duplicate', 'copy', 'copy_week', 'bulk_template'];
    const TYPE_MOVE = 'move_shift';

    protected $fillable = [
        'company_id', 'user_id', 'type', 'created_shift_ids', 'move_payload', 'intent', 'status', 'undone_at', 'redone_at',
    ];

    protected $casts = [
        'created_shift_ids' => 'array',
        'move_payload' => 'array',
        'intent' => 'array',
        'undone_at' => 'datetime',
        'redone_at' => 'datetime',
    ];

    public function isCreateType(): bool
    {
        return in_array($this->type, self::CREATED_TYPES, true);
    }
}
