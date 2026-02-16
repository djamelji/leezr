<?php

namespace App\Company\RBAC;

use App\Core\Models\Company;
use App\Core\Models\Membership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CompanyRole extends Model
{
    protected $fillable = ['company_id', 'key', 'name', 'is_system', 'is_administrative'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_administrative' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(CompanyPermission::class, 'company_role_permission');
    }

    public function hasPermission(string $key): bool
    {
        if ($this->relationLoaded('permissions')) {
            return $this->permissions->contains('key', $key);
        }

        return $this->permissions()->where('key', $key)->exists();
    }

    /**
     * Sync permissions with structural validation.
     * Non-administrative roles cannot receive admin permissions.
     *
     * @param  array<int>  $permissionIds
     * @throws ValidationException if admin permissions assigned to non-admin role
     */
    public function syncPermissionsSafe(array $permissionIds): void
    {
        if (!$this->is_administrative && !empty($permissionIds)) {
            $adminPermissions = CompanyPermission::whereIn('id', $permissionIds)
                ->where('is_admin', true)
                ->pluck('key')
                ->toArray();

            if (!empty($adminPermissions)) {
                throw ValidationException::withMessages([
                    'permissions' => 'Non-administrative role cannot receive admin permissions: ' . implode(', ', $adminPermissions),
                ]);
            }
        }

        $this->permissions()->sync($permissionIds);
    }
}
