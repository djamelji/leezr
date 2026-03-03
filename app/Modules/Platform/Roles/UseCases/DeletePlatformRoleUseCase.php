<?php

namespace App\Modules\Platform\Roles\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Platform\Models\PlatformRole;
use Illuminate\Validation\ValidationException;

class DeletePlatformRoleUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(int $id): void
    {
        $role = PlatformRole::withCount('users')->findOrFail($id);

        if ($role->key === 'super_admin') {
            throw ValidationException::withMessages([
                'role' => ['Cannot delete the super_admin role — it is structural.'],
            ]);
        }

        if ($role->users_count > 0) {
            throw ValidationException::withMessages([
                'role' => ["Cannot delete role '{$role->name}' — {$role->users_count} user(s) attached."],
            ]);
        }

        $roleName = $role->name;
        $roleId = $role->id;
        $role->delete();

        $this->audit->logPlatform(
            AuditAction::ROLE_DELETED, 'platform_role', (string) $roleId,
            ['diffBefore' => ['key' => $role->key, 'name' => $roleName]],
        );
    }
}
