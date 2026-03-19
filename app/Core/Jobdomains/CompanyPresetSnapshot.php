<?php

namespace App\Core\Jobdomains;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-375: Records a snapshot of company roles + permissions
 * at registration or reconciliation time.
 */
class CompanyPresetSnapshot extends Model
{
    protected $fillable = ['company_id', 'jobdomain_key', 'trigger', 'roles_snapshot'];

    protected function casts(): array
    {
        return [
            'roles_snapshot' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Take a snapshot of all system roles + their permissions for a company.
     */
    public static function capture(Company $company, string $trigger): self
    {
        $roles = $company->roles()
            ->where('is_system', true)
            ->with('permissions:id,key')
            ->get();

        $snapshot = $roles->map(fn ($role) => [
            'role_key' => $role->key,
            'role_id' => $role->id,
            'is_administrative' => $role->is_administrative,
            'archetype' => $role->archetype,
            'permissions' => $role->permissions->pluck('key')->sort()->values()->all(),
        ])->all();

        return self::create([
            'company_id' => $company->id,
            'jobdomain_key' => $company->jobdomain_key,
            'trigger' => $trigger,
            'roles_snapshot' => $snapshot,
        ]);
    }
}
