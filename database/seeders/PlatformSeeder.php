<?php

namespace Database\Seeders;

use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PermissionCatalog;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        // Create permissions from catalog (single source of truth)
        $permissions = collect(PermissionCatalog::all())
            ->map(fn ($p) => PlatformPermission::updateOrCreate(
                ['key' => $p['key']],
                ['label' => $p['label']],
            ));

        // Create super_admin role (structural — no pivot sync needed, bypass is in PlatformUser::hasPermission)
        $superAdmin = PlatformRole::updateOrCreate(
            ['key' => 'super_admin'],
            ['name' => 'Super Admin'],
        );

        // Create admin role (modules only)
        $admin = PlatformRole::updateOrCreate(
            ['key' => 'admin'],
            ['name' => 'Admin'],
        );

        $modulePermission = $permissions->firstWhere('key', 'manage_modules');
        if ($modulePermission) {
            $admin->permissions()->sync([$modulePermission->id]);
        }

        // Create platform admin user
        $user = PlatformUser::updateOrCreate(
            ['email' => 'admin@leezr.com'],
            [
                'first_name' => 'Djamel',
                'last_name' => 'Ji',
                'password' => 'password',
            ],
        );

        // Attach role if not already attached
        if (!$user->hasRole('super_admin')) {
            $user->roles()->attach($superAdmin->id);
        }
    }
}
