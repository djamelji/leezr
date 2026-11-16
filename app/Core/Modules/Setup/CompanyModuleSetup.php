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

    protected $fillable = ['company_id', 'module_key', 'status', 'completed_steps', 'revision', 'access_fingerprint', 'settings_fingerprint', 'pending_access', 'configured_at', 'configured_by'];

    protected static function booted(): void
    {
        // ADR-808 : the per-request cache of pending (not yet effective) permissions follows every write.
        static::saved(fn () => PendingModuleAccess::flush());
        static::deleted(fn () => PendingModuleAccess::flush());
    }

    protected function casts(): array
    {
        return ['completed_steps' => 'array', 'revision' => 'integer', 'configured_at' => 'datetime', 'pending_access' => 'array'];
    }
}
