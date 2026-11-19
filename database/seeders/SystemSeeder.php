<?php

namespace Database\Seeders;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Core\System\SystemSync;
use App\Platform\Models\PlatformPermission;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * System seeder — synchronisation of the SYSTEM DEFINITIONS (ADR-832), safe on any environment.
 * Idempotent: a second run changes nothing.
 *
 * Installs missing definitions and aligns what the code owns (permissions, module manifests, legal references) ;
 * configuration administered in Platform (plans, markets, JobDomains, fields, topics, role grants, payment
 * choices) is created once and then follows the code ONLY while unchanged since the code last wrote it —
 * otherwise it is preserved and reported as a conflict (`SystemSync`). Published presets and Company data are
 * never written. Deliberate alignment of an administered value: `php artisan system:sync --adopt=...`.
 * Does NOT seed demo data (users, companies, shipments).
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $owner = ! SystemSync::active(); // `db:seed --class=SystemSeeder` (deploy) or `system:sync` (owner = command)
        if ($owner) {
            SystemSync::begin();
        }

        // ─── Platform RBAC (permissions, roles, super_admin) ─────
        $this->call(PlatformSeeder::class);

        // ─── Plan catalog ──────────────────────────────────────────
        PlanRegistry::sync();

        // ─── Module catalog ──────────────────────────────────────
        ModuleRegistry::sync();

        // ─── Notification topics (ADR-715) — référentiel idempotent, requis pour l'in-app ──
        $this->call(NotificationTopicSeeder::class);

        // ─── Module commercial config (sellable add-ons + pricing) ──────
        // Audit 13: product reference data — MUST run in prod too (was dev-only,
        // which left Workforce/logistics add-ons unsellable/unactivatable on PROD).
        $this->call(ModuleProductConfigSeeder::class);

        // ─── Jobdomain catalog ───────────────────────────────────
        JobdomainRegistry::sync();

        // ─── Company permission catalog ──────────────────────────
        CompanyPermissionCatalog::sync();

        // ─── Field definitions catalog ─────────────────────────
        FieldDefinitionCatalog::sync();

        // ─── Module contributions (ADR-794) — catalogue + PROPOSALS only, never imposed ──
        \App\Core\Modules\Contributions\ModuleContributionCatalog::sync();

        // ─── Market catalog (ADR-104) ──────────────────────
        MarketRegistry::sync();

        // ─── Payment modules (ADR-124) ─────────────────────
        $this->call(PaymentModuleSeeder::class);

        // ─── Workforce system data (ADR-479→536) ──────────────
        $this->call(MarketRuleSetSeeder::class);
        $this->call(ConventionCollectiveSeeder::class);
        $this->call(WorkforcePayrollRuleSeeder::class);
        $this->call(PayslipDraftTemplateSeeder::class);
        $this->call(PayslipOfficialFrTemplateSeeder::class);
        $this->call(ContractTemplateSeeder::class); // ADR-647 (W-CONTRACT-1) : modèle de contrat démo

        // ─── Cleanup stale permissions ───────────────────────────
        $this->cleanupStalePermissions();

        if ($owner) {
            self::printReport($this->command);
            SystemSync::end();
        }
    }

    public static function printReport($command): void
    {
        if (! $command) {
            return;
        }
        $summary = SystemSync::summary();
        $command->info('System sync: '.($summary === [] ? 'no change' : json_encode($summary)));
        foreach (SystemSync::report() as $e) {
            if (! in_array($e['type'], ['conflict', 'structural_conflict', 'removed', 'evolved', 'adopted'], true)) {
                continue;
            }
            $line = sprintf('  %-19s %s:%s %s', $e['type'], $e['subject'], $e['key'], $e['field'] ?? implode(',', (array) ($e['keys'] ?? [])));
            str_contains($e['type'], 'conflict') ? $command->warn($line.(isset($e['adopt']) ? '  (--adopt='.$e['adopt'].')' : '')) : $command->line($line);
        }
    }

    private function cleanupStalePermissions(): void
    {
        // Platform permissions
        $platformKeys = PlatformPermissionCatalog::keys();
        $stalePlatform = PlatformPermission::whereNotIn('key', $platformKeys)->get();

        if ($stalePlatform->isNotEmpty()) {
            $keys = $stalePlatform->pluck('key')->toArray();
            Log::warning('SystemSeeder: removing stale platform permissions', ['keys' => $keys]);
            SystemSync::note('removed', 'platform_permissions', '*', ['keys' => $keys]);
            PlatformPermission::whereNotIn('key', $platformKeys)->delete();
        }

        // Company permissions
        $companyKeys = CompanyPermissionCatalog::keys();
        $staleCompany = CompanyPermission::whereNotIn('key', $companyKeys)->get();

        if ($staleCompany->isNotEmpty()) {
            $keys = $staleCompany->pluck('key')->toArray();
            Log::warning('SystemSeeder: removing stale company permissions', ['keys' => $keys]);
            SystemSync::note('removed', 'company_permissions', '*', ['keys' => $keys]);
            CompanyPermission::whereNotIn('key', $companyKeys)->delete();
        }
    }
}
