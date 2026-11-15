<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Requirements\EffectiveRequirementResolver;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementLevel;
use App\Core\Requirements\RequirementParityComparator;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-791 (L1) — requirement registry + parallel resolver : invariants, priority rules, provenance,
 * applicability by position (never the RBAC role), employees without account, modules lifecycle,
 * preset templates and versions, isolation, no side effect, comparator classification, performance.
 */
class Adr791RequirementRegistryTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $a;
    private Company $b;
    private JobRole $driverJob;
    private JobRole $officeJob;
    private Employee $driverWithAccount;
    private Employee $driverAccountless;
    private Employee $officeEmp;
    private User $driverUser;
    private array $doc = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        $this->doc = DocumentType::pluck('id', 'code')->all();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_leave', 'workforce_documents']]);

        $this->a = $this->company('A');
        $this->b = $this->company('B');
        $this->setUpCompanyRbac($this->a);
        $this->driverJob = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Livreur', 'level' => 'agent', 'source' => 'preset', 'preset_item_key' => 'driver']);
        $this->officeJob = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Gestionnaire administratif', 'level' => 'agent', 'source' => 'preset', 'preset_item_key' => 'admin_officer']);

        $this->driverUser = User::factory()->create();
        Membership::create(['user_id' => $this->driverUser->id, 'company_id' => $this->a->id, 'role' => 'user']);
        $this->driverWithAccount = $this->employee($this->driverJob, $this->driverUser);
        $this->driverAccountless = $this->employee($this->driverJob);
        $this->officeEmp = $this->employee($this->officeJob);
    }

    private function company(string $name): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);

        return $c;
    }

    private function employee(JobRole $job, ?User $user = null): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'user_id' => $user?->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => 'active', 'hire_date' => '2026-01-01', 'job_role_id' => $job->id]);
    }

    private function req(array $attrs): Requirement
    {
        return Requirement::create(array_merge(['target_kind' => 'document', 'level' => RequirementLevel::DEFAULT, 'source' => 'company', 'company_id' => $this->a->id], $attrs));
    }

    private function activateModule(string $key, bool $on = true): void
    {
        $on
            ? CompanyModuleActivationReason::firstOrCreate(['company_id' => $this->a->id, 'module_key' => $key], ['reason' => CompanyModuleActivationReason::REASON_DIRECT])
            : CompanyModuleActivationReason::where('company_id', $this->a->id)->where('module_key', $key)->delete();
    }

    private function resolve(Employee $e, ?Company $c = null): array
    {
        return EffectiveRequirementResolver::forCompany(($c ?? $this->a)->fresh())->resolveFor(EffectiveRequirementResolver::subjectFromEmployee($e->load('jobRole'), null));
    }

    private function on(array $resolved, string $code): ?array
    {
        return $resolved['document:'.$this->doc[$code]] ?? null;
    }

    // ── Invariants (7) ────────────────────────────────────────────────────────

    public function test_invariants_target_and_imperative_authority(): void
    {
        foreach ([
            ['document_type_id' => $this->doc['id_card'], 'field_definition_id' => 1],                       // deux cibles
            ['target_kind' => 'field', 'document_type_id' => $this->doc['id_card']],                          // genre incohérent
            ['document_type_id' => $this->doc['id_card'], 'level' => RequirementLevel::IMPERATIVE, 'source' => 'platform', 'company_id' => null], // sans autorité
            ['document_type_id' => $this->doc['id_card'], 'level' => RequirementLevel::IMPERATIVE, 'source' => 'jobdomain', 'company_id' => null, 'legal_reference' => 'x', 'verified_at' => now()], // JobDomain ≠ autorité légale
            ['document_type_id' => $this->doc['id_card'], 'effect' => 'waive', 'source' => 'module', 'company_id' => null, 'level' => null], // dispense hors Company
            ['document_type_id' => $this->doc['id_card'], 'source' => 'company', 'company_id' => null],       // Company sans société
        ] as $i => $bad) {
            try {
                $this->req($bad);
                $this->fail("cas {$i} accepté");
            } catch (\DomainException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, Requirement::count());
    }

    // ── Sources et priorités (1, 2, 3, 5, 6, 14) ──────────────────────────────

    public function test_company_jobdomain_and_conflict_resolution_keep_provenance(): void
    {
        $this->req(['document_type_id' => $this->doc['id_card'], 'level' => RequirementLevel::CONFIGURABLE_REQUIRED]);
        $this->req(['document_type_id' => $this->doc['id_card'], 'company_id' => null, 'source' => 'jobdomain', 'source_ref' => 'logistique', 'level' => RequirementLevel::RECOMMENDED]);
        $this->req(['document_type_id' => $this->doc['diploma'], 'company_id' => null, 'source' => 'jobdomain', 'source_ref' => 'logistique', 'level' => RequirementLevel::RECOMMENDED]);
        $this->req(['document_type_id' => $this->doc['rib'], 'company_id' => null, 'source' => 'jobdomain', 'source_ref' => 'other_domain', 'level' => RequirementLevel::DEFAULT]);

        $r = $this->resolve($this->officeEmp);
        $id = $this->on($r, 'id_card');
        $this->assertTrue($id['required']);
        $this->assertSame(RequirementLevel::CONFIGURABLE_REQUIRED, $id['level']);
        $this->assertSame('company', $id['retained']['source']);
        $this->assertEqualsCanonicalizing(['retained', 'merged'], array_column($id['contributions'], 'decision'), 'une exigence, deux sources conservées');
        $this->assertFalse($this->on($r, 'diploma')['required'], 'une recommandation n\'oblige pas');
        $this->assertNull($this->on($r, 'rib'), 'JobDomain d\'une autre activité ignoré');
    }

    public function test_imperative_wins_over_company_waiver(): void
    {
        $this->req(['document_type_id' => $this->doc['id_card'], 'company_id' => null, 'source' => 'platform', 'level' => RequirementLevel::IMPERATIVE, 'legal_reference' => 'Code du travail L.0000 (test)', 'verified_at' => now()]);
        $this->req(['document_type_id' => $this->doc['id_card'], 'effect' => 'waive', 'level' => null, 'reason' => 'dispense']);
        $id = $this->on($this->resolve($this->officeEmp), 'id_card');
        $this->assertTrue($id['required']);
        $this->assertFalse($id['customizable']);
        $this->assertContains('overridden_by_imperative', array_column($id['contributions'], 'decision'));
    }

    public function test_company_waives_a_recommendation_or_default(): void
    {
        $this->req(['document_type_id' => $this->doc['diploma'], 'company_id' => null, 'source' => 'jobdomain', 'source_ref' => 'logistique', 'level' => RequirementLevel::DEFAULT]);
        $this->req(['document_type_id' => $this->doc['diploma'], 'effect' => 'waive', 'level' => null]);
        $d = $this->on($this->resolve($this->officeEmp), 'diploma');
        $this->assertFalse($d['required']);
        $this->assertTrue($d['waived']);
        $this->assertContains('waived_by_company', array_column($d['contributions'], 'decision'));
    }

    public function test_same_document_from_two_modules_is_one_requirement(): void
    {
        $this->activateModule('workforce');
        $this->activateModule('workforce_leave');
        foreach (['workforce', 'workforce_leave'] as $m) {
            $this->req(['document_type_id' => $this->doc['medical_certificate'], 'company_id' => null, 'source' => 'module', 'source_ref' => $m]);
        }
        $r = $this->resolve($this->officeEmp);
        $this->assertCount(1, array_filter(array_keys($r), fn ($k) => $k === 'document:'.$this->doc['medical_certificate']));
        $this->assertCount(2, array_filter($this->on($r, 'medical_certificate')['contributions'], fn ($c) => $c['status'] === 'applicable'));
    }

    // ── Postes, comptes (8, 9, 10) ────────────────────────────────────────────

    public function test_position_not_rbac_role_decides_and_accountless_is_resolved(): void
    {
        $this->req(['document_type_id' => $this->doc['driving_license'], 'applicability' => ['job_role_keys' => ['driver']], 'level' => RequirementLevel::CONFIGURABLE_REQUIRED]);
        // Le rôle RBAC du livreur masque le permis : sans effet sur la résolution cible.
        $role = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'r', 'name' => 'R', 'doc_config' => [['code' => 'driving_license', 'visible' => false]]]);
        Membership::where('user_id', $this->driverUser->id)->update(['company_role_id' => $role->id]);

        $this->assertTrue($this->on($this->resolve($this->driverWithAccount), 'driving_license')['required'], 'livreur avec compte');
        $this->assertTrue($this->on($this->resolve($this->driverAccountless), 'driving_license')['required'], 'livreur sans compte');
        $this->assertNull($this->on($this->resolve($this->officeEmp), 'driving_license'), 'administratif : pas de permis');
        $noJob = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'N', 'last_name' => 'J', 'status' => 'active', 'hire_date' => '2026-01-01']);
        $this->assertNull($this->on($this->resolve($noJob), 'driving_license'), 'aucun poste inventé');
    }

    // ── Modules (3, 11, 12, 17) ───────────────────────────────────────────────

    public function test_module_contribution_suspension_reactivation_and_new_version(): void
    {
        $this->activateModule('workforce');
        $v1 = $this->req(['document_type_id' => $this->doc['medical_certificate'], 'company_id' => null, 'source' => 'module', 'source_ref' => 'workforce', 'source_version' => 1, 'applicability' => ['job_role_keys' => ['driver']]]);
        $this->assertTrue($this->on($this->resolve($this->driverAccountless), 'medical_certificate')['required']);

        $this->activateModule('workforce', false);
        $m = $this->on($this->resolve($this->driverAccountless), 'medical_certificate');
        $this->assertNull($m, 'module suspendu : plus applicable');
        $this->assertSame(1, Requirement::count(), 'rien supprimé');

        $this->activateModule('workforce');
        $this->assertTrue($this->on($this->resolve($this->driverAccountless), 'medical_certificate')['required'], 'réactivé : restauré tel quel');

        $v1->update(['status' => Requirement::STATUS_SUPERSEDED]);
        $this->req(['document_type_id' => $this->doc['medical_certificate'], 'company_id' => null, 'source' => 'module', 'source_ref' => 'workforce', 'source_version' => 2, 'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'applicability' => ['job_role_keys' => ['driver']]]);
        $m = $this->on($this->resolve($this->driverAccountless), 'medical_certificate');
        $this->assertSame(2, $m['retained']['source_version']);
        $this->assertContains('superseded', array_column($m['contributions'], 'status'));
    }

    // ── Presets (4, 16, H) ────────────────────────────────────────────────────

    public function test_preset_templates_customisation_and_updates(): void
    {
        $tpl1 = $this->req(['document_type_id' => $this->doc['rib'], 'company_id' => null, 'source' => 'preset', 'source_ref' => 'logistique_requirements', 'source_version' => 1]);
        $this->assertNull($this->on($this->resolve($this->officeEmp), 'rib'), 'modèle non appliqué : aucun effet');

        $copy = $this->req(['document_type_id' => $this->doc['rib'], 'source' => 'preset', 'source_ref' => 'logistique_requirements', 'source_version' => 1, 'derived_from_id' => $tpl1->id]);
        $this->assertTrue($this->on($this->resolve($this->officeEmp), 'rib')['required']);
        $this->assertNull($this->on($this->resolve($this->officeEmp), 'rib')['update_available']);

        $this->req(['document_type_id' => $this->doc['rib'], 'company_id' => null, 'source' => 'preset', 'source_ref' => 'logistique_requirements', 'source_version' => 2, 'level' => RequirementLevel::CONFIGURABLE_REQUIRED]);
        $u = $this->on($this->resolve($this->officeEmp), 'rib')['update_available'];
        $this->assertSame(['from_version' => 1, 'to_version' => 2, 'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'requires_confirmation' => true], $u);
        $this->assertSame(RequirementLevel::DEFAULT, $this->on($this->resolve($this->officeEmp), 'rib')['level'], 'jamais appliquée d\'office');

        $copy->update(['customized_at' => now()]);
        $this->assertNull($this->on($this->resolve($this->officeEmp), 'rib')['update_available'], 'copie personnalisée : jamais écrasée');
    }

    // ── Isolation, effets de bord (13) ────────────────────────────────────────

    public function test_other_company_rows_and_people_are_never_read(): void
    {
        Requirement::create(['company_id' => $this->b->id, 'target_kind' => 'document', 'document_type_id' => $this->doc['criminal_record'], 'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'source' => 'company']);
        $this->assertNull($this->on($this->resolve($this->officeEmp), 'criminal_record'));
        $resolver = EffectiveRequirementResolver::forCompany($this->a->fresh(), Requirement::all());
        $this->assertNull($this->on($resolver->resolveFor(EffectiveRequirementResolver::subjectFromEmployee($this->officeEmp->load('jobRole'), null)), 'criminal_record'), 'même avec des lignes fournies');
        $bEmp = Employee::withoutGlobalScopes()->create(['company_id' => $this->b->id, 'first_name' => 'B', 'last_name' => 'B', 'status' => 'active', 'hire_date' => '2026-01-01']);
        $this->assertNotContains('employee:'.$bEmp->id, array_column(EffectiveRequirementResolver::forCompany($this->a)->subjects(), 'subject'));
    }

    public function test_resolution_and_comparison_have_no_side_effect(): void
    {
        $this->req(['document_type_id' => $this->doc['id_card']]);
        $counts = fn () => [Requirement::count(), DocumentRequest::count(), DB::table('notification_events')->count(), DB::table('workforce_planning_notification_outbox')->count(), DocumentTypeActivation::count(), DB::table('field_activations')->count()];
        $before = $counts();
        foreach (EffectiveRequirementResolver::forCompany($this->a)->subjects() as $s) {
            EffectiveRequirementResolver::forCompany($this->a)->resolveFor($s);
        }
        RequirementParityComparator::compare($this->a);
        $this->assertSame($before, $counts());
    }

    // ── Historique contradictoire et comparateur (15) ─────────────────────────

    public function test_comparator_classifies_contradictory_history_as_product_arbitration(): void
    {
        // La Company exige le permis à tous ; le rôle RBAC du compte le masque (cas ADR-790, société 1).
        DocumentTypeActivation::create(['company_id' => $this->a->id, 'document_type_id' => $this->doc['driving_license'], 'enabled' => true, 'required_override' => true, 'order' => 1]);
        $role = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'boss', 'name' => 'Boss', 'doc_config' => [['code' => 'driving_license', 'visible' => false]]]);
        Membership::where('user_id', $this->driverUser->id)->update(['company_role_id' => $role->id]);

        $report = RequirementParityComparator::compare($this->a->fresh());
        $this->assertSame(0, $report['by_category'][RequirementParityComparator::BUG]);
        $this->assertGreaterThanOrEqual(1, $report['by_category'][RequirementParityComparator::ARBITRATION]);
        $this->assertSame(1, $report['accountless'] >= 1 ? 1 : 0);
        $this->assertNotEmpty($report['not_representable'], 'règles portées par le rôle signalées');
    }

    // ── Performance (18) ──────────────────────────────────────────────────────

    public function test_resolution_is_batched_on_a_large_population(): void
    {
        foreach (range(1, 300) as $i) {
            $this->employee($i % 2 ? $this->driverJob : $this->officeJob);
        }
        $this->req(['document_type_id' => $this->doc['driving_license'], 'applicability' => ['job_role_keys' => ['driver']]]);
        $this->req(['document_type_id' => $this->doc['id_card']]);

        DB::enableQueryLog();
        $t = microtime(true);
        $resolver = EffectiveRequirementResolver::forCompany($this->a->fresh());
        $required = 0;
        foreach ($resolver->subjects() as $s) {
            $required += count(array_filter($resolver->resolveFor($s), fn ($r) => $r['required']));
        }
        $queries = count(DB::getQueryLog());
        $ms = (microtime(true) - $t) * 1000;
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $queries, "requêtes constantes ({$queries})");
        $this->assertSame(303 + 152, $required); // 303 pièces d'identité + 152 permis (livreurs)
        $this->assertLessThan(5000, $ms);
    }

    // ── Réversibilité de la migration ─────────────────────────────────────────

    public function test_migration_is_reversible_without_touching_other_tables(): void
    {
        $migration = require database_path('migrations/2026_11_22_000001_create_requirements_table.php');
        $before = DocumentTypeActivation::count();
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('requirements'));
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('requirements'));
        $this->assertSame($before, DocumentTypeActivation::count());
    }
}
