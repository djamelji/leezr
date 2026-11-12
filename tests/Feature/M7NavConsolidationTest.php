<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Modules\CompanyModule;
use App\Core\Navigation\NavBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-766 (M7) — Consolidation de la navigation Company : UNE entrée Collaborateurs (Rôles en onglet,
 * plus d'entrée Roles dédiée) et UNE entrée Documents (registre RH en onglet, plus d'entrée Documents RH
 * dédiée). Invariants prouvés : plus de doublon de nav, aucune perte d'accès, AUCUN nouveau 403 — un
 * profil `workforce_documents.view`-seul atteint toujours ses documents via l'entrée unique.
 */
class M7NavConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private const MODULES = ['core.members', 'core.roles', 'core.documents', 'workforce', 'workforce_documents', 'workforce_signature'];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'm7', 'label' => 'M7', 'default_modules' => self::MODULES]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (self::MODULES as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'M7 Co', 'slug' => 'm7-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'm7', 'market_key' => 'FR']);
        foreach (self::MODULES as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
            CompanyModule::create(['company_id' => $this->company->id, 'module_key' => $m, 'is_enabled_for_company' => true]);
        }
        ModuleRegistry::clearCache();
    }

    private function navKeys(array $permKeys): array
    {
        $groups = NavBuilder::forCompany($this->company, $permKeys, 'management');

        return collect($groups)->flatMap(fn ($g) => array_column($g['items'], 'key'))->all();
    }

    // ── Roles consolidé dans Collaborateurs ──
    public function test_no_standalone_roles_nav_item(): void
    {
        $keys = $this->navKeys(['roles.view', 'members.view']);
        $this->assertNotContains('company-roles', $keys, 'Plus d\'entrée de nav Roles dédiée.');
        $this->assertContains('people', $keys, 'Les Rôles vivent dans l\'entrée unique Collaborateurs.');
    }

    public function test_roles_view_only_still_reaches_people(): void
    {
        // Un profil roles.view-seul garde l'accès (People via anyPermission) — pas de perte.
        $keys = $this->navKeys(['roles.view']);
        $this->assertContains('people', $keys);
    }

    // ── Documents : une seule entrée ──
    public function test_no_standalone_workforce_documents_nav_item(): void
    {
        $keys = $this->navKeys(['documents.view', 'workforce_documents.view']);
        $this->assertNotContains('workforce-documents', $keys, 'Plus d\'entrée de nav Documents RH dédiée.');
        $this->assertSame(1, collect($keys)->filter(fn ($k) => $k === 'documents')->count(), 'Une seule entrée Documents.');
    }

    public function test_documents_view_only_sees_the_single_documents_entry(): void
    {
        $keys = $this->navKeys(['documents.view']);
        $this->assertContains('documents', $keys);
    }

    public function test_workforce_documents_view_only_sees_the_single_documents_entry(): void
    {
        // INVARIANT CLÉ (pas de nouveau 403) : un profil RH-docs-seul atteint ses documents via l'entrée unique.
        $keys = $this->navKeys(['workforce_documents.view']);
        $this->assertContains('documents', $keys, 'workforce_documents.view doit voir l\'entrée Documents unifiée.');
        $this->assertNotContains('workforce-documents', $keys);
    }

    public function test_user_without_any_documents_capability_sees_no_documents_entry(): void
    {
        // Aucun élargissement : sans aucune des permissions, pas d'entrée Documents.
        $keys = $this->navKeys(['workforce.view']);
        $this->assertNotContains('documents', $keys);
    }

    // ── Pas de doublon global ──
    public function test_no_duplicate_nav_keys(): void
    {
        $keys = $this->navKeys(['documents.view', 'workforce_documents.view', 'workforce_signature.view', 'members.view', 'roles.view', 'workforce.view']);
        $this->assertSame(count($keys), count(array_unique($keys)), 'Aucune clé de nav en doublon.');
    }
}
