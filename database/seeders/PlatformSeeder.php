<?php

namespace Database\Seeders;

use App\Core\Settings\SessionSettingsPayload;
use App\Core\Theme\ThemePayload;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformSetting;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        // Create permissions from catalog (module-driven, single source of truth)
        PlatformPermissionCatalog::sync();

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

        $modulePermission = PlatformPermission::where('key', 'manage_modules')->first();
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

        // Platform admin — staging test
        $devAdmin = PlatformUser::updateOrCreate(
            ['email' => 'dev@leezr.com'],
            [
                'first_name' => 'Dev',
                'last_name' => 'Admin',
                'password' => 'password',
            ],
        );

        if (!$devAdmin->hasRole('admin')) {
            $devAdmin->roles()->attach($admin->id);
        }

        // Platform admin — production test
        $prodAdmin = PlatformUser::updateOrCreate(
            ['email' => 'prod@leezr.com'],
            [
                'first_name' => 'Prod',
                'last_name' => 'Admin',
                'password' => 'password',
            ],
        );

        if (!$prodAdmin->hasRole('admin')) {
            $prodAdmin->roles()->attach($admin->id);
        }

        // Seed platform settings singleton (idempotent — only creates if missing)
        if (PlatformSetting::query()->count() === 0) {
            PlatformSetting::create([
                'theme' => ThemePayload::defaults()->toArray(),
                'session' => SessionSettingsPayload::defaults()->toArray(),
            ]);
        }
    }
}
