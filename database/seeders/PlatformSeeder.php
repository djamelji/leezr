<?php

namespace Database\Seeders;

use App\Core\Settings\MaintenanceSettingsPayload;
use App\Core\Settings\SessionSettingsPayload;
use App\Core\Theme\ThemePayload;
use App\Core\Typography\TypographyPayload;
use App\Platform\Models\PlatformFontFamily;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformSetting;
use App\Platform\Models\PlatformUser;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Modules\ModuleRegistry;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        // Sync module registry → platform_modules (must run before permissions)
        ModuleRegistry::sync();

        // ADR-167a: Jobdomain is a structural invariant — seed catalog
        JobdomainRegistry::sync();

        // ADR-169 Phase 3: Sync document type catalog
        \App\Core\Documents\DocumentTypeCatalog::sync();

        // Create permissions from catalog (module-driven, single source of truth)
        PlatformPermissionCatalog::sync();

        // Create super_admin role (structural — no pivot sync needed, bypass is in PlatformUser::hasPermission)
        $superAdmin = PlatformRole::updateOrCreate(
            ['key' => 'super_admin'],
            ['name' => 'Super Admin'],
        );

        // Create admin role (full platform access — super_admin bypasses checks entirely)
        $admin = PlatformRole::updateOrCreate(
            ['key' => 'admin'],
            ['name' => 'Admin'],
        );

        // Admin gets all platform permissions (super_admin doesn't need pivot — hasPermission bypasses)
        $admin->permissions()->sync(PlatformPermission::pluck('id')->toArray());

        // Create platform admin user.
        // SÉCURITÉ : aucun mot de passe prédéfini hors local/testing. En dev public/prod,
        // le mot de passe vient de l'env (LEEZR_PLATFORM_ADMIN_PASSWORD) à la première création,
        // sinon un aléatoire fort (reset via "mot de passe oublié"). Jamais réécrit au redéploiement.
        $isDevEnv = app()->environment(['local', 'testing']);
        $adminExists = PlatformUser::where('email', 'admin@leezr.com')->exists();
        $adminAttrs = ['first_name' => 'Djamel', 'last_name' => 'Ji'];
        if ($isDevEnv) {
            $adminAttrs['password'] = 'password';
        } elseif (!$adminExists) {
            $adminAttrs['password'] = env('LEEZR_PLATFORM_ADMIN_PASSWORD') ?: Str::password(32);
        }
        $user = PlatformUser::updateOrCreate(
            ['email' => 'admin@leezr.com'],
            $adminAttrs,
        );

        // Attach role if not already attached
        if (!$user->hasRole('super_admin')) {
            $user->roles()->attach($superAdmin->id);
        }

        // Comptes de test (dev@/prod@) : créés uniquement en local/testing
        // (ou si LEEZR_SEED_TEST_ADMINS activé). Jamais de compte à mot de passe connu en production.
        if ($isDevEnv || env('LEEZR_SEED_TEST_ADMINS', false)) {
            $devAdmin = PlatformUser::updateOrCreate(
                ['email' => 'dev@leezr.com'],
                ['first_name' => 'Dev', 'last_name' => 'Admin', 'password' => 'password'],
            );
            if (!$devAdmin->hasRole('admin')) {
                $devAdmin->roles()->attach($admin->id);
            }

            $prodAdmin = PlatformUser::updateOrCreate(
                ['email' => 'prod@leezr.com'],
                ['first_name' => 'Prod', 'last_name' => 'Admin', 'password' => 'password'],
            );
            if (!$prodAdmin->hasRole('admin')) {
                $prodAdmin->roles()->attach($admin->id);
            }
        }

        // Seed platform settings singleton (idempotent — only creates if missing)
        if (PlatformSetting::query()->count() === 0) {
            PlatformSetting::create([
                'theme' => ThemePayload::defaults()->toArray(),
                'session' => SessionSettingsPayload::defaults()->toArray(),
                'typography' => [
                    'active_source' => 'google',
                    'active_family_id' => null,
                    'google_fonts_enabled' => true,
                    'google_active_family' => 'Poppins',
                    'google_weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900],
                    'headings_family_id' => null,
                    'body_family_id' => null,
                ],
                'maintenance' => MaintenanceSettingsPayload::defaults()->toArray(),
            ]);
        }

        // Seed email SMTP + IMAP credentials (idempotent — always update to latest)
        $instance = PlatformSetting::instance();
        $currentEmail = $instance->email ?? [];

        // SÉCURITÉ : aucun secret email en dur. Les identifiants SMTP/IMAP viennent
        // de l'environnement (jamais committés). Seedé uniquement si un host est fourni.
        $smtpHost = env('LEEZR_SMTP_HOST', '');
        if (empty($currentEmail['smtp_host']) && $smtpHost !== '') {
            $instance->update([
                'email' => array_merge($currentEmail, [
                    'smtp_host' => $smtpHost,
                    'smtp_port' => (int) env('LEEZR_SMTP_PORT', 587),
                    'smtp_encryption' => env('LEEZR_SMTP_ENCRYPTION', 'tls'),
                    'smtp_username' => env('LEEZR_SMTP_USERNAME', 'admin@leezr.com'),
                    'smtp_password' => env('LEEZR_SMTP_PASSWORD', ''),
                    'imap_host' => env('LEEZR_IMAP_HOST', $smtpHost),
                    'imap_port' => (int) env('LEEZR_IMAP_PORT', 993),
                    'imap_encryption' => env('LEEZR_IMAP_ENCRYPTION', 'ssl'),
                    'imap_username' => env('LEEZR_IMAP_USERNAME', 'admin@leezr.com'),
                    'imap_password' => env('LEEZR_IMAP_PASSWORD', ''),
                    'imap_folder' => 'INBOX',
                    'from_email' => env('LEEZR_MAIL_FROM', 'admin@leezr.com'),
                    'from_name' => 'Leezr',
                ]),
            ]);
        }

        // Seed default font families (idempotent)
        PlatformFontFamily::firstOrCreate(
            ['slug' => 'public-sans'],
            ['name' => 'Public Sans', 'source' => 'google', 'is_enabled' => true],
        );
        PlatformFontFamily::firstOrCreate(
            ['slug' => 'poppins'],
            ['name' => 'Poppins', 'source' => 'google', 'is_enabled' => true],
        );
    }
}
