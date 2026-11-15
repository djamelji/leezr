<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetApplication;
use App\Core\Presets\PresetService;
use App\Core\Requirements\EffectiveRequirementResolver;
use App\Core\Requirements\Requirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Organization\OrganizationPreset;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-793 (L3) — versioned presets (RBAC, Organization, Requirements, Planning) : shared immutable
 * lifecycle, version diff, read-only preview, explicit idempotent apply, provenance, customisation kept,
 * no silent RBAC elevation, no operational effect, JobDomain recommendations, isolation, authorisation.
 */
class Adr793PresetsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private PlatformUser $admin;
    private Company $a;
    private Company $b;
    private JobRole $driverJob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_planning']]);
        $this->admin = PlatformUser::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa-'.uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $this->admin->roles()->attach(PlatformRole::where('key', 'super_admin')->firstOrFail());
        $this->a = $this->company('A');
        $this->b = $this->company('B');
        $this->driverJob = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Livreur', 'level' => 'agent', 'source' => 'preset', 'preset_item_key' => 'driver']);
    }

    private function company(string $name): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => 'direct']);
        }
        $this->setUpCompanyRbac($c);

        return $c;
    }

    private function api()
    {
        return $this->actingAs($this->admin, 'platform');
    }

    private function requirementsContent(array $extra = []): array
    {
        return ['items' => array_merge([
            ['kind' => 'document', 'code' => 'driving_license', 'level' => 'configurable_required', 'job_role_keys' => ['driver']],
            ['kind' => 'field', 'code' => 'license_number', 'level' => 'default', 'job_role_keys' => ['driver']],
            ['kind' => 'document', 'code' => 'id_card', 'level' => 'recommended'],
        ], $extra)];
    }

    private function draft(string $kind, array $content, string $family = 'livraison_req', array $more = []): int
    {
        return $this->api()->postJson('/api/platform/presets', ['kind' => $kind, 'family_key' => $family, 'label' => 'Livraison', 'content' => $content] + $more)->assertCreated()->json('id');
    }

    private function publish(string $kind, int $id): void
    {
        $this->api()->postJson("/api/platform/presets/{$kind}/{$id}/publish")->assertOk()->assertJsonPath('status', 'published');
    }

    // 1 · 2 · 3 · 4 — cycle de version, immuabilité, nouvelle version, comparaison
    public function test_version_lifecycle_immutability_and_diff(): void
    {
        $v1 = $this->draft('requirements', $this->requirementsContent());
        $this->publish('requirements', $v1);
        $this->assertSame(3, Requirement::whereNull('company_id')->where('source', 'preset')->where('source_version', 1)->count(), 'modèles publiés dans le registre');

        $this->api()->putJson("/api/platform/presets/{$v1}", ['label' => 'Changed'])->assertStatus(422);
        try {
            Preset::find($v1)->forceFill(['content' => ['items' => []]])->save();
            $this->fail('version publiée modifiée');
        } catch (\DomainException) {
            $this->assertSame('Livraison', Preset::find($v1)->label);
        }

        $v2 = $this->api()->postJson("/api/platform/presets/{$v1}/new-version")->assertCreated()->json();
        $this->assertSame([2, 'draft', 'livraison_req_v2'], [$v2['version'], $v2['status'], $v2['key']]);
        $content = $this->requirementsContent([['kind' => 'document', 'code' => 'medical_certificate', 'level' => 'default', 'job_role_keys' => ['driver']]]);
        $content['items'][0]['level'] = 'default';
        unset($content['items'][2]);
        $this->api()->putJson("/api/platform/presets/{$v2['id']}", ['content' => ['items' => array_values($content['items'])]])->assertOk();
        $this->publish('requirements', $v2['id']);
        $this->assertSame('archived', Preset::find($v1)->status, 'une seule version publiée par famille ; v1 conservée');

        $diff = $this->api()->getJson("/api/platform/presets/requirements/{$v1}/diff/{$v2['id']}")->assertOk()->json();
        $this->assertSame(['document:medical_certificate@driver'], $diff['added']);
        $this->assertSame(['document:id_card@all'], $diff['removed']);
        $this->assertSame(['document:driving_license@driver'], $diff['changed']);
        $this->assertSame(1, Requirement::whereNull('company_id')->where('source', 'preset')->where('source_version', 1)->where('status', 'superseded')->count() >= 1 ? 1 : 0, 'modèles v1 conservés (remplacés)');
    }

    // 11 — références invalides
    public function test_invalid_references_are_refused(): void
    {
        foreach ([
            ['requirements', ['items' => [['kind' => 'document', 'code' => 'ghost_doc', 'level' => 'default']]]],
            ['requirements', ['items' => [['kind' => 'document', 'code' => 'id_card', 'level' => 'imperative']]]],
            ['requirements', ['items' => [['kind' => 'document', 'code' => 'id_card', 'level' => 'default', 'job_role_keys' => ['Bad Key']]]]],
            ['rbac', ['roles' => [['key' => 'x', 'name' => 'X', 'bundles' => ['ghost.bundle']]]]],
            ['planning', ['schedule_templates' => [['code' => 's', 'name' => 'S', 'composition' => ['activities' => [['preset_code' => 'ghost']]]]]]],
        ] as [$kind, $content]) {
            $this->api()->postJson('/api/platform/presets', ['kind' => $kind, 'family_key' => 'bad_'.$kind, 'label' => 'B', 'content' => $content])->assertStatus(422);
        }
        $this->assertSame(0, Preset::count());
    }

    // 5 · 6 · 7 · 9 · 10 · 17 · 18 — aperçu, application idempotente, personnalisation, aucun effet opérationnel
    public function test_requirements_preset_preview_apply_customisation_and_no_operational_effect(): void
    {
        $v1 = $this->draft('requirements', $this->requirementsContent());
        $this->publish('requirements', $v1);
        $driverNoAccount = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'D', 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01', 'job_role_id' => $this->driverJob->id]);
        $noPosition = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'N', 'last_name' => 'P', 'status' => 'active', 'hire_date' => '2026-01-01']);
        $resolve = fn (Employee $e) => EffectiveRequirementResolver::forCompany($this->a->fresh())->resolveFor(EffectiveRequirementResolver::subjectFromEmployee($e->load('jobRole'), null));
        $permit = 'document:'.DocumentType::where('code', 'driving_license')->value('id');

        $this->assertArrayNotHasKey($permit, $resolve($driverNoAccount), 'preset publié non appliqué : sans effet');
        $operational = fn () => json_encode([array_map(fn ($e) => [$e['required'], $e['visible']], DocumentResolverService::requirementMap($this->a->id, null, 'FR')), DocumentRequest::count()]);
        $before = $operational();
        $countBefore = Requirement::where('company_id', $this->a->id)->count();

        $preview = $this->api()->getJson("/api/platform/presets/requirements/{$v1}/companies/{$this->a->id}/preview")->assertOk()->json();
        $this->assertSame(['new', 'new', 'new'], array_column($preview['items'], 'status'));
        $this->assertSame($countBefore, Requirement::where('company_id', $this->a->id)->count(), 'aperçu sans écriture');

        $this->api()->postJson("/api/platform/presets/requirements/{$v1}/companies/{$this->a->id}/apply")->assertOk();
        $this->api()->postJson("/api/platform/presets/requirements/{$v1}/companies/{$this->a->id}/apply")->assertOk()->assertJsonPath('created', []);
        $this->assertSame(3, Requirement::where('company_id', $this->a->id)->where('source', 'preset')->count(), 'idempotent');
        $this->assertSame(2, PresetApplication::where('company_id', $this->a->id)->count(), 'chaque application tracée');
        $this->assertNotNull(Requirement::where('company_id', $this->a->id)->where('source', 'preset')->first()->derived_from_id, 'provenance : modèle d\'origine');

        $this->assertTrue($resolve($driverNoAccount)[$permit]['required'], 'livreur sans compte concerné');
        $this->assertArrayNotHasKey($permit, $resolve($noPosition), 'salarié sans poste : aucun poste inventé');
        $this->assertSame($before, $operational(), 'aucun effet sur la décision opérationnelle ni sur les demandes');

        // Personnalisation puis nouvelle version : jamais écrasée, mise à jour proposée seulement.
        Requirement::where('company_id', $this->a->id)->where('target_kind', 'field')->update(['customized_at' => now()]);
        $v2 = Preset::find($v1)->nextVersion();
        $c = $v2->content;
        $c['items'][0]['level'] = 'default';
        $c['items'][1]['level'] = 'configurable_required';
        $v2->forceFill(['content' => $c])->save();
        PresetService::publish($v2, null);
        $statuses = collect(PresetService::preview($this->a, $v2->fresh())['items'])->pluck('status', 'key');
        $this->assertSame('requires_confirmation', $statuses['document:driving_license@driver']);
        $this->assertSame('customized', $statuses['field:license_number@driver']);
        PresetService::apply($this->a, $v2->fresh(), null, 'platform', null);
        $this->assertSame(1, (int) Requirement::where('company_id', $this->a->id)->where('target_kind', 'document')->where('status', 'active')->where('source', 'preset')
            ->where('document_type_id', DocumentType::where('code', 'driving_license')->value('id'))->value('source_version'), 'jamais appliquée sans confirmation');
        PresetService::apply($this->a, $v2->fresh(), ['document:driving_license@driver', 'field:license_number@driver'], 'platform', null);
        $this->assertSame(2, (int) Requirement::where('company_id', $this->a->id)->where('status', 'active')->where('document_type_id', DocumentType::where('code', 'driving_license')->value('id'))->value('source_version'), 'mise à jour confirmée');
        $field = Requirement::where('company_id', $this->a->id)->where('status', 'active')->where('target_kind', 'field')->sole();
        $this->assertSame([1, 'default'], [$field->source_version, $field->level], 'personnalisation préservée même sélectionnée');
    }

    // 8 — RBAC sans élévation silencieuse
    public function test_rbac_preset_never_elevates_silently(): void
    {
        $existing = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'driver', 'name' => 'Livreur', 'is_administrative' => false]);
        $existing->permissions()->sync(CompanyPermission::whereIn('key', ['shipments.view_own'])->pluck('id')->all());
        $id = $this->draft('rbac', ['roles' => [
            ['key' => 'driver', 'name' => 'Livreur', 'bundles' => ['shipments.delivery', 'documents.access']],
            ['key' => 'planner', 'name' => 'Planificateur', 'bundles' => ['shipments.delivery']],
            ['key' => 'docs_admin', 'name' => 'Gestion documentaire', 'is_administrative' => true, 'bundles' => ['documents.management']],
        ]], 'livraison_rbac');
        $this->publish('rbac', $id);

        $items = collect($this->api()->getJson("/api/platform/presets/rbac/{$id}/companies/{$this->a->id}/preview")->json('items'))->keyBy('key');
        $this->assertSame('customized', $items['role:driver']['status']);
        $this->assertSame('new', $items['role:planner']['status']);
        $this->assertSame('requires_confirmation', $items['role:docs_admin']['status']);

        $before = $existing->permissions()->pluck('key')->sort()->values()->all();
        $this->api()->postJson("/api/platform/presets/rbac/{$id}/companies/{$this->a->id}/apply")->assertOk()->assertJsonPath('created', ['role:planner']);
        $this->assertSame($before, $existing->fresh()->permissions()->pluck('key')->sort()->values()->all(), 'rôle existant jamais modifié');
        $this->assertFalse(CompanyRole::where('company_id', $this->a->id)->where('key', 'docs_admin')->exists(), 'rôle sensible jamais créé par défaut');
        $this->api()->postJson("/api/platform/presets/rbac/{$id}/companies/{$this->a->id}/apply", ['selected' => ['role:docs_admin', 'role:driver']])->assertOk();
        $this->assertTrue(CompanyRole::where('company_id', $this->a->id)->where('key', 'docs_admin')->exists(), 'créé sur confirmation explicite');
        $this->assertSame($before, $existing->fresh()->permissions()->pluck('key')->sort()->values()->all(), 'même sélectionné, un rôle existant n\'est pas modifié');
    }

    // Organisation et Planning : moteurs existants pilotés avec une version explicite
    public function test_organization_and_planning_adapters_reuse_existing_engines(): void
    {
        $org = OrganizationPreset::create(['key' => 'org_liv_v1', 'family_key' => 'org_liv', 'label' => 'Org', 'jobdomain_key' => 'logistique', 'version' => 1, 'status' => 'draft',
            'structure' => ['departments' => [['key' => 'ops', 'name' => 'Exploitation', 'parent_key' => null]], 'job_roles' => [['key' => 'driver', 'title' => 'Livreur', 'department_key' => 'ops', 'level' => 'agent']]]]);
        $this->publish('organization', $org->id);
        $prev = collect($this->api()->getJson("/api/platform/presets/organization/{$org->id}/companies/{$this->b->id}/preview")->json('items'))->pluck('status', 'key');
        $this->assertSame('new', $prev['job_role:driver']);
        $this->api()->postJson("/api/platform/presets/organization/{$org->id}/companies/{$this->b->id}/apply")->assertOk();
        $this->assertTrue(JobRole::withoutGlobalScopes()->where('company_id', $this->b->id)->where('preset_item_key', 'driver')->exists());
        $this->assertSame('identical', collect(PresetService::preview($this->b, $org->fresh())['items'])->firstWhere('key', 'job_role:driver')['status']);

        $plan = $this->draft('planning', ['schema_version' => 1, 'coverage_categories' => [['code' => 'route', 'name' => 'Route', 'color' => '#4b6bfb']],
            'activity_presets' => [['code' => 'brief', 'name' => 'Brief', 'activity_type' => 'brief', 'coverage_category_code' => 'route', 'default_duration_minutes' => 30, 'priority' => 'normal']]], 'livraison_planning');
        $this->publish('planning', $plan);
        $this->assertSame(['new', 'new'], array_column($this->api()->getJson("/api/platform/presets/planning/{$plan}/companies/{$this->b->id}/preview")->json('items'), 'status'));
        $this->api()->postJson("/api/platform/presets/planning/{$plan}/companies/{$this->b->id}/apply")->assertOk();
        $this->assertSame(['identical', 'identical'], array_column(PresetService::preview($this->b, Preset::find($plan))['items'], 'status'), 'idempotent');
    }

    // 12 · 13 · 14 · 15 · 16 — JobDomain, modules, isolation, autorisation, historique
    public function test_jobdomain_recommendations_modules_isolation_and_authorisation(): void
    {
        $req = $this->draft('requirements', $this->requirementsContent());
        $this->publish('requirements', $req);
        $rbac = $this->draft('rbac', ['roles' => [['key' => 'planner', 'name' => 'P', 'bundles' => ['shipments.delivery']]]], 'livraison_rbac');
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'requirements', 'family_key' => 'livraison_req'], ['kind' => 'rbac', 'family_key' => 'livraison_rbac']]])->assertOk();
        $recs = $this->api()->getJson('/api/platform/jobdomains/logistique/preset-recommendations')->json('recommendations');
        $this->assertSame([['requirements', 1], ['rbac', null]], array_map(fn ($r) => [$r['kind'], $r['published_version']], $recs), 'plusieurs presets recommandés ; brouillon non publié signalé');
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'requirements', 'family_key' => 'ghost']]])->assertStatus(422);
        $this->assertSame(0, Requirement::whereNotNull('company_id')->count(), 'recommander n\'applique rien');

        $mod = $this->draft('requirements', $this->requirementsContent(), 'needs_module', ['requires_modules' => ['logistics_fleet']]);
        $this->publish('requirements', $mod);
        $this->assertSame(['logistics_fleet'], PresetService::preview($this->a, Preset::find($mod))['missing_modules']);
        $this->api()->postJson("/api/platform/presets/requirements/{$mod}/companies/{$this->a->id}/apply")->assertStatus(422);
        $this->api()->postJson("/api/platform/presets/requirements/{$rbac}/companies/{$this->a->id}/apply")->assertStatus(404); // famille rbac, id inexistant pour requirements
        $this->api()->postJson("/api/platform/presets/rbac/{$rbac}/companies/{$this->a->id}/apply")->assertStatus(422); // brouillon : jamais appliqué

        $this->api()->postJson("/api/platform/presets/requirements/{$req}/companies/{$this->a->id}/apply")->assertOk();
        $this->assertSame(0, Requirement::where('company_id', $this->b->id)->count(), 'autre société intacte');
        $this->assertSame(0, PresetApplication::where('company_id', $this->b->id)->count());

        $weak = PlatformUser::create(['first_name' => 'W', 'last_name' => 'K', 'email' => 'w-'.uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $this->actingAs($weak, 'platform')->getJson('/api/platform/presets')->assertForbidden();

        $this->assertNotEmpty(\App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->value('default_roles'), 'configuration historique du JobDomain intacte');
    }

    public function test_company_user_cannot_reach_platform_presets(): void
    {
        $companyUser = User::factory()->create();
        Membership::create(['user_id' => $companyUser->id, 'company_id' => $this->a->id, 'role' => 'owner']);
        $res = $this->actingAs($companyUser)->getJson('/api/platform/presets');
        $this->assertContains($res->status(), [401, 403], $res->getContent());
        $this->actingAs($companyUser)->postJson('/api/platform/presets', ['kind' => 'rbac', 'family_key' => 'x', 'label' => 'x', 'content' => []])->assertStatus(401);
    }
}
