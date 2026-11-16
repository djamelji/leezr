<?php

namespace App\Core\Modules\Setup;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** ADR-803 — guided-configuration progress of a module for a company (state only ; rights live in RBAC). */
class CompanyModuleSetup extends Model
{
    use BelongsToCompany;

    public const TO_CONFIGURE = 'to_configure';

    public const IN_PROGRESS = 'in_progress';

    public const READY = 'ready';

    protected $fillable = ['company_id', 'module_key', 'status', 'completed_steps', 'revision', 'access_fingerprint', 'settings_fingerprint', 'configured_at', 'configured_by'];

    protected function casts(): array
    {
        return ['completed_steps' => 'array', 'revision' => 'integer', 'configured_at' => 'datetime'];
    }
}
