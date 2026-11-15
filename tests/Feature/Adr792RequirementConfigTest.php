<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\ReadModels\DocumentComplianceReadModel;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Requirements\EffectiveRequirementResolver;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementLevel;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-792 (L2) — requirements per POSITION configured by the company, in PARALLEL MODE : HTTP API,
 * provenance, imperative lock, history, preview without write, permissions per section, tenant
 * isolation, employees without account, and NO effect on the operational resolution (ADR-790).
 */
class Adr792RequirementConfigTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $a;
    private Company $b;
    private User $owner;
    private JobRole $driverJob;
    private JobRole $officeJob;
    private Employee $driverAccountless;
    private Employee $ownerEmp;
    private array $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_leave']]);
        $this->doc = DocumentType::pluck('id', 'code')->all();

        $this->a = $this->company('A');
        $this->b = $this->company('B');
        $admin = $this->setUpCompanyRbac($this->a);
        $this->owner = User::factory()->create();
        Membership::create(['user_id' => $this->owner->id, 'company_id' => $this->a->id, 'role' => 'owner', 'company_role_id' => $admin->id]);

        $this->driverJob = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Livreur', 'level' => 'agent', 'source' => 'preset', 'preset_item_key' => 'driver']);
        $this->officeJob = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Administratif', 'level' => 'agent', 'source' => 'company']);
        $driverUser = $this->member($this->a, []);
        $this->employee($this->driverJob, $driverUser);
        $this->driverAccountless = $this->employee($this->driverJob);
        $this->employee($this->officeJob);
        $this->ownerEmp = $this->employee(null, $this->owner); // propriétaire également salarié, sans poste
    }

    private function company(string $name): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach (['workforce', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        foreach (DocumentType::where('is_system', true)->get() as $i => $t) {
            DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => $t->id, 'enabled' => true, 'order' => $i, 'required_override' => $t->code === 'id_card']);
        }

        return $c;
    }

    private function member(Company $c, array $perms, bool $admin = false): User
    {
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => $admin]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function employee(?JobRole $job, ?User $user = null): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'user_id' => $user?->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => 'active', 'hire_date' => '2026-01-01', 'job_role_id' => $job?->id]);
    }

    private function api(User $u, string $method, string $uri, array $body = [], ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id])->json($method, '/api/workforce/requirements'.$uri, $body);
    }

    private function state(string $scope, ?User $u = null): array
    {
        return $this->api($u ?? $this->owner, 'GET', '?scope='.$scope)->assertOk()->json('state');
    }

    private function row(array $state, string $kind, string $code): ?array
    {
        return collect($state[$kind === 'field' ? 'fields' : 'documents'])->firstWhere('code', $code);
    }

    private function save(string $scope, array $items, ?User $u = null)
    {
        return $this->api($u ?? $this->owner, 'POST', '', ['scope' => $scope, 'items' => $items]);
    }

    private function driverScope(): string
    {
        return 'job_role:'.$this->driverJob->id;
    }

    // 1 · 3 · 4 · 5 · 9 — configuration par poste et commune
    public function test_company_configures_requirements_per_position(): void
    {
        $this->assertSame(['fields' => [], 'documents' => []], $this->state('all'), 'société sans exigence');

        $this->save($this->driverScope(), [['kind' => 'document', 'code' => 'driving_license', 'state' => 'required'], ['kind' => 'field', 'code' => 'license_number', 'state' => 'required']])->assertOk();
        $this->save('all', [['kind' => 'document', 'code' => 'rib', 'state' => 'optional']])->assertOk();

        $driver = $this->state($this->driverScope());
        $this->assertSame('required', $this->row($driver, 'document', 'driving_license')['state']);
        $this->assertSame(['kind' => 'company', 'scope' => 'position'], array_intersect_key($this->row($driver, 'document', 'driving_license')['origin'], ['kind' => 1, 'scope' => 1]));
        $this->assertSame('optional', $this->row($driver, 'document', 'rib')['state']);
        $this->assertSame('all', $this->row($driver, 'document', 'rib')['origin']['scope'], 'commune à tous les postes');

        $office = $this->state('job_role:'.$this->officeJob->id);
        $this->assertNull($this->row($office, 'document', 'driving_license'), 'administratif : pas de permis');
        $this->assertNotNull($this->row($office, 'document', 'rib'));
    }

    // 6 · 7 · 8 — salariés sans compte, sans poste, propriétaire salarié
    public function test_people_resolution_follows_the_real_position(): void
    {
        $this->save($this->driverScope(), [['kind' => 'document', 'code' => 'driving_license', 'state' => 'required']])->assertOk();
        $resolve = fn (Employee $e) => EffectiveRequirementResolver::forCompany($this->a->fresh())->resolveFor(EffectiveRequirementResolver::subjectFromEmployee($e->load('jobRole'), null));
        $this->assertTrue($resolve($this->driverAccountless)['document:'.$this->doc['driving_license']]['required'], 'sans compte');
        $this->assertArrayNotHasKey('document:'.$this->doc['driving_license'], $resolve($this->ownerEmp), 'propriétaire sans poste : aucun poste inventé');
        $scopes = collect($this->api($this->owner, 'GET', '')->json('scopes'))->keyBy('key');
        $this->assertSame(['people' => 2, 'accountless' => 1], array_intersect_key($scopes[$this->driverScope()], ['people' => 1, 'accountless' => 1]));
    }

    // 2 · 10 · 13 · 14 — recommandation, module, preset non appliqué, module désactivé
    public function test_provenance_recommendation_module_preset_and_suspension(): void
    {
        Requirement::create(['company_id' => null, 'target_kind' => 'document', 'document_type_id' => $this->doc['diploma'], 'level' => RequirementLevel::RECOMMENDED, 'source' => 'jobdomain', 'source_ref' => 'logistique', 'applicability' => ['job_role_keys' => ['driver']]]);
        Requirement::create(['company_id' => null, 'target_kind' => 'document', 'document_type_id' => $this->doc['medical_certificate'], 'level' => RequirementLevel::DEFAULT, 'source' => 'module', 'source_ref' => 'workforce']);
        Requirement::create(['company_id' => null, 'target_kind' => 'document', 'document_type_id' => $this->doc['criminal_record'], 'level' => RequirementLevel::DEFAULT, 'source' => 'preset', 'source_ref' => 'logistique_requirements', 'source_version' => 1]);

        $s = $this->state($this->driverScope());
        $this->assertSame('recommended', $this->row($s, 'document', 'diploma')['state']);
        $this->assertSame('jobdomain', $this->row($s, 'document', 'diploma')['origin']['kind']);
        $this->assertSame('module', $this->row($s, 'document', 'medical_certificate')['origin']['kind']);
        $this->assertNotEmpty($this->row($s, 'document', 'medical_certificate')['origin']['module'], 'nom du module, pas sa clé');
        $this->assertNull($this->row($s, 'document', 'criminal_record'), 'preset non appliqué');

        // Retirer une exigence apportée par un module = dispense tracée
        $this->save($this->driverScope(), [['kind' => 'document', 'code' => 'medical_certificate', 'state' => 'removed']])->assertOk();
        $this->assertSame('waived', $this->row($this->state($this->driverScope()), 'document', 'medical_certificate')['state']);

        CompanyModuleActivationReason::where('company_id', $this->a->id)->where('module_key', 'workforce')->delete();
        $this->assertSame(1, Requirement::where('source', 'module')->count(), 'module désactivé : rien supprimé');
    }

    // 11 · 12 — impératif justifié, verrouillé
    public function test_imperative_requirement_is_locked_and_justified(): void
    {
        Requirement::create(['company_id' => null, 'target_kind' => 'document', 'document_type_id' => $this->doc['id_card'], 'level' => RequirementLevel::IMPERATIVE, 'source' => 'platform', 'legal_reference' => 'Référence de test (non réglementaire)', 'verified_at' => now()]);
        $row = $this->row($this->state('all'), 'document', 'id_card');
        $this->assertTrue($row['locked']);
        $this->assertSame('Référence de test (non réglementaire)', $row['justification']);

        $before = Requirement::count();
        $this->save('all', [['kind' => 'document', 'code' => 'id_card', 'state' => 'removed']])->assertStatus(422);
        $this->assertSame($before, Requirement::count());
    }

    // 17 · 19 — personnalisation, modification, historique, idempotence
    public function test_modification_keeps_history_and_save_is_idempotent(): void
    {
        $item = ['kind' => 'document', 'code' => 'driving_license', 'state' => 'required'];
        $this->save($this->driverScope(), [$item])->assertOk();
        $this->save($this->driverScope(), [$item])->assertOk()->assertJsonPath('changes.unchanged.0.code', 'driving_license');
        $this->assertSame(1, Requirement::count(), 'idempotent');

        $this->save($this->driverScope(), [['state' => 'optional'] + $item])->assertOk()->assertJsonPath('changes.modified.0.to', RequirementLevel::RECOMMENDED);
        $this->assertSame(1, Requirement::where('status', Requirement::STATUS_SUPERSEDED)->count(), 'ancienne version conservée');
        $current = Requirement::where('status', Requirement::STATUS_ACTIVE)->sole();
        $this->assertNotNull($current->customized_at);
        $this->assertTrue(\App\Core\Audit\CompanyAuditLog::where('action', 'requirements.updated')->exists());

        $this->save($this->driverScope(), [['state' => 'removed'] + $item])->assertOk();
        $this->assertSame(0, Requirement::where('status', Requirement::STATUS_ACTIVE)->count());
        $this->assertSame(2, Requirement::count(), 'retrait = désactivation, jamais suppression');
    }

    // 18 — aperçu sans écriture
    public function test_preview_never_writes(): void
    {
        $res = $this->api($this->owner, 'POST', '/preview', ['scope' => $this->driverScope(), 'items' => [['kind' => 'document', 'code' => 'driving_license', 'state' => 'required']]])->assertOk();
        $this->assertSame('driving_license', $res->json('changes.added.0.code'));
        $this->assertSame(['total' => 2, 'accountless' => 1], $res->json('people'));
        $this->assertSame(0, Requirement::count());
        $this->assertFalse(\App\Core\Audit\CompanyAuditLog::where('action', 'requirements.updated')->exists());
    }

    // 15 — plusieurs sociétés
    public function test_positions_and_definitions_of_another_company_are_refused(): void
    {
        $foreignJob = JobRole::withoutGlobalScopes()->create(['company_id' => $this->b->id, 'title' => 'X', 'level' => 'agent', 'source' => 'company']);
        $this->save('job_role:'.$foreignJob->id, [['kind' => 'document', 'code' => 'rib', 'state' => 'required']])->assertStatus(422);
        $foreignType = DocumentType::create(['company_id' => $this->b->id, 'code' => 'b_only', 'scope' => 'company_user', 'label' => 'B only', 'validation_rules' => [], 'is_system' => false]);
        $this->save('all', [['kind' => 'document', 'code' => 'b_only', 'state' => 'required']])->assertStatus(422);
        Requirement::create(['company_id' => $this->b->id, 'target_kind' => 'document', 'document_type_id' => $this->doc['rib'], 'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'source' => 'company']);
        $this->assertNull($this->row($this->state('all'), 'document', 'rib'), 'configuration de B jamais lue');
        $this->assertSame(0, Requirement::where('company_id', $this->a->id)->count());
    }

    // 16 — permissions par section
    public function test_permissions_per_section(): void
    {
        $plain = $this->member($this->a, ['workforce.view']);
        $this->api($plain, 'GET', '')->assertForbidden();
        $this->save('all', [['kind' => 'field', 'code' => 'phone', 'state' => 'required']], $plain)->assertForbidden();

        $docs = $this->member($this->a, ['documents.configure', 'documents.view'], true);
        $this->api($docs, 'GET', '')->assertOk()->assertJsonPath('can', ['fields' => false, 'documents' => true]);
        $this->save('all', [['kind' => 'field', 'code' => 'phone', 'state' => 'required']], $docs)->assertForbidden();
        $this->save('all', [['kind' => 'document', 'code' => 'rib', 'state' => 'required']], $docs)->assertOk();

        $fields = $this->member($this->a, ['settings.manage', 'settings.view'], true);
        $this->save('all', [['kind' => 'document', 'code' => 'diploma', 'state' => 'required']], $fields)->assertForbidden();
        $this->save('all', [['kind' => 'field', 'code' => 'phone', 'state' => 'required']], $fields)->assertOk();
    }

    // 20 — l'ancien moteur reste autoritaire et inchangé
    public function test_operational_resolution_is_untouched(): void
    {
        $snapshot = fn () => [
            DocumentResolverService::requirementMap($this->a->id, null, 'FR'),
            DocumentComplianceReadModel::slotsFor($this->a->fresh()),
            DocumentRequest::count(),
            DocumentTypeActivation::where('company_id', $this->a->id)->get(['document_type_id', 'enabled', 'required_override'])->toArray(),
        ];
        $normalize = fn ($s) => json_encode([array_map(fn ($e) => [$e['required'], $e['visible']], $s[0]), $s[1], $s[2], $s[3]]);
        $before = $normalize($snapshot());
        $this->save($this->driverScope(), [['kind' => 'document', 'code' => 'driving_license', 'state' => 'required'], ['kind' => 'document', 'code' => 'medical_certificate', 'state' => 'required']])->assertOk();
        $this->save('all', [['kind' => 'document', 'code' => 'id_card', 'state' => 'removed']])->assertOk();
        $this->assertSame($before, $normalize($snapshot()), 'fiche, conformité, demandes et activations inchangées');
    }

    public function test_legacy_mapping_is_read_only_and_never_infers_from_role_names(): void
    {
        $role = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'driver', 'name' => 'Livreur', 'doc_config' => [['code' => 'driving_license', 'visible' => true, 'required' => true]]]);
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $this->a->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $item = collect($this->api($this->owner, 'GET', '/legacy')->assertOk()->json('items'))->firstWhere('code', 'driving_license');
        $this->assertSame('needs_decision', $item['category'], 'aucun poste déduit du nom du rôle');
        $this->employee($this->driverJob, $u);
        $item = collect($this->api($this->owner, 'GET', '/legacy')->json('items'))->firstWhere('code', 'driving_license');
        $this->assertSame('proposed', $item['category'], 'proposé depuis le poste réel de la personne');
        $this->assertSame(0, Requirement::count(), 'rien converti');
    }
}
