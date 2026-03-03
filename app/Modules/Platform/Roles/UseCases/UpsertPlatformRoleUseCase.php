<?php

namespace App\Modules\Platform\Roles\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Platform\Models\PlatformRole;
use Illuminate\Validation\ValidationException;

class UpsertPlatformRoleUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(?int $id, array $validated): PlatformRole
    {
        if ($id === null) {
            return $this->create($validated);
        }

        return $this->update($id, $validated);
    }

    private function create(array $validated): PlatformRole
    {
        $role = PlatformRole::create([
            'key' => $validated['key'],
            'name' => $validated['name'],
        ]);

        if (isset($validated['permissions'])) {
            $role->permissions()->sync($validated['permissions']);
        }

        $this->audit->logPlatform(
            AuditAction::ROLE_CREATED, 'platform_role', (string) $role->id,
            ['diffAfter' => $role->only('key', 'name')],
        );

        return $role->loadCount('users')->load('permissions');
    }

    private function update(int $id, array $validated): PlatformRole
    {
        $role = PlatformRole::findOrFail($id);

        if ($role->key === 'super_admin' && array_key_exists('permissions', $validated)) {
            throw ValidationException::withMessages([
                'permissions' => ['Cannot modify super_admin permissions — they are structural.'],
            ]);
        }

        $before = $role->only('key', 'name');

        $fields = array_intersect_key($validated, array_flip(['key', 'name']));
        if (! empty($fields)) {
            $role->update($fields);
        }

        if (array_key_exists('permissions', $validated)) {
            $role->permissions()->sync($validated['permissions']);
        }

        $this->audit->logPlatform(
            AuditAction::ROLE_UPDATED, 'platform_role', (string) $role->id,
            ['diffBefore' => $before, 'diffAfter' => $role->only('key', 'name')],
        );

        return $role->loadCount('users')->load('permissions');
    }
}
