<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\Modernization\CompanyJobdomainCompatibility;
use App\Core\Jobdomains\Modernization\VersionedCompanyInitializer;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetApplication;
use App\Core\Requirements\Requirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-797 (L7) — JobDomain as business context + recommendations of versioned presets. Legacy configuration
 * frozen (read-only) but still read by company creation ; diagnostic, legacy → draft presets, pinned
 * recommendations, prepared (non-operational) versioned initialisation, read-only company compatibility.
 */
class Adr797JobdomainModernizationTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private PlatformUser $admin;
    private Jobdomain $jd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        $this->admin = $this->platformUser(['super_admin']);
        $this->jd = Jobdomain::where('key', 'logistique')->firstOrFail();
    }

    private function platformUser(array $roleKeys = [], array $permissions = []): PlatformUser
    {
        $u = PlatformUser::create(['first_name' => 'P', 'last_name' => 'U', 'email' => 'pu-'.uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        foreach ($roleKeys as $k) {
            $u->roles()->attach(PlatformRole::where('key', $k)->firstOrFail());
        }
        if ($permissions) {
            $role = PlatformRole::create(['key' => 'r'.uniqid(), 'name' => 'Custom']);
            $role->permissions()->sync(PlatformPermission::whereIn('key', $permissions)->pluck('id')->all());
            $u->roles()->attach($role);
        }

        return $u;
    }

    private function api(?PlatformUser $u = null)
    {
        return $this->actingAs($u ?? $this->admin, 'platform');
    }

    private function publishedPreset(string $kind, array $content, string $family, ?string $jobdomain = 'logistique'): Preset
    {
        $id = $this->api()->postJson('/api/platform/presets', ['kind' => $kind, 'family_key' => $family, 'label' => ucfirst($kind), 'content' => $content, 'jobdomain_key' => $jobdomain])->assertCreated()->json('id');
        $this->api()->postJson("/api/platform/presets/{$kind}/{$id}/publish")->assertOk();

        return Preset::find($id);
    }

    private function company(string $name, string $jobdomain = 'logistique'): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => $jobdomain, 'market_key' => 'FR', 'plan_key' => 'pro']);

        return $c;
    }

    /** Fingerprint of everything a company owns that L7 must never change. */
    private function fingerprint(Company $c): string
    {
        return json_encode([
            CompanyRole::where('company_id', $c->id)->with('permissions:id,key')->orderBy('key')->get()->map(fn ($r) => [$r->key, $r->name, $r->permissions->pluck('key')->sort()->values()]),
            DB::table('company_modules')->where('company_id', $c->id)->orderBy('module_key')->get(['module_key', 'is_enabled_for_company']),
            DB::table('company_module_activation_reasons')->where('company_id', $c->id)->orderBy('module_key')->get(['module_key', 'reason']),
            DB::table('field_activations')->where('company_id', $c->id)->orderBy('field_definition_id')->get(['field_definition_id', 'enabled', 'required_override']),
            DB::table('document_type_activations')->where('company_id', $c->id)->orderBy('document_type_id')->get(['document_type_id', 'enabled', 'required_override']),
            collect(DocumentResolverService::requirementMap($c->id, null, 'FR'))->mapWithKeys(fn ($e) => [$e['type']->code => [$e['required'], $e['visible'], $e['mandatory']]])->sortKeys(),
            Requirement::where('company_id', $c->id)->count(), PresetApplication::where('company_id', $c->id)->count(),
            JobRole::withoutGlobalScopes()->where('company_id', $c->id)->count(), Employee::withoutGlobalScopes()->where('company_id', $c->id)->orderBy('id')->get(['id', 'job_role_id', 'status', 'user_id']),
        ]);
    }

    // 1, 2, 3, 4 — diagnostic : JobDomain avec rôles, documents, champs et planning historiques ; JobDomain vierge.
    public function test_profile_and_legacy_footprint(): void
    {
        $p = $this->api()->getJson("/api/platform/jobdomains/{$this->jd->id}/profile")->assertOk()->json();
        $this->assertSame('logistique', $p['identity']['key']);
        $this->assertFalse($p['legacy']['editable'], 'ancienne configuration en lecture seule');
        $fam = $p['compatibility']['families'];
        $this->assertSame(count($this->jd->default_roles), $fam['rbac']['counts']['legacy_only'], 'rôles historiques sans équivalent versionné');
        $this->assertGreaterThan(0, $fam['requirements']['counts']['legacy_only']);
        $this->assertNull($fam['rbac']['preset']);
        $this->assertNotEmpty($p['compatibility']['role_prescriptions']['items'], 'prescriptions par rôle : décision humaine');
        $this->assertSame('human_decision', $p['compatibility']['role_prescriptions']['class']);
        $this->assertContains('company_creation', array_column($p['compatibility']['legacy_engines'], 'engine'));

        $empty = Jobdomain::create(['key' => 'vierge', 'label' => 'Vierge', 'is_active' => true]);
        $e = $this->api()->getJson("/api/platform/jobdomains/{$empty->id}/profile")->assertOk()->json('compatibility.families');
        $this->assertSame([0, 0, 0], [$e['rbac']['legacy_count'], $e['requirements']['legacy_count'], $e['planning']['legacy_count']]);
        $this->api()->postJson("/api/platform/jobdomains/{$empty->id}/legacy-drafts", ['kind' => 'rbac'])->assertStatus(422);
    }

    // 5, 6, 7, 21 — brouillons depuis l'historique ; association ; versions ; nouvelle version sans réapplication ; pas de doublon.
    public function test_legacy_drafts_recommendations_and_versions(): void
    {
        $a = $this->company('A');
        JobdomainGate::assignToCompany($a, 'logistique');
        $before = $this->fingerprint($a);

        $rbac = $this->api()->postJson("/api/platform/jobdomains/{$this->jd->id}/legacy-drafts", ['kind' => 'rbac'])->assertCreated()->json();
        $this->assertSame('draft', $rbac['preset']['status']);
        $this->assertNotEmpty($rbac['not_carried'], 'prescriptions par rôle signalées, non transposées');
        $again = $this->api()->postJson("/api/platform/jobdomains/{$this->jd->id}/legacy-drafts", ['kind' => 'rbac'])->assertCreated()->json('preset.id');
        $this->assertSame($rbac['preset']['id'], $again, 'un seul brouillon ouvert par famille');
        $req = $this->api()->postJson("/api/platform/jobdomains/{$this->jd->id}/legacy-drafts", ['kind' => 'requirements'])->assertCreated()->json('preset');
        $plan = $this->api()->postJson("/api/platform/jobdomains/{$this->jd->id}/legacy-drafts", ['kind' => 'planning'])->assertCreated()->json('preset');
        $docItems = collect($req['content']['items'])->where('kind', 'document');
        $this->assertSame($docItems->count(), $docItems->unique('code')->count(), 'aucune obligation documentaire dupliquée');
        $this->assertNotContains('imperative', collect($req['content']['items'])->pluck('level')->all());

        // Un brouillon ne peut pas être recommandé avec une version épinglée.
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'rbac', 'family_key' => 'logistique_rbac', 'pinned_version' => 1]]])->assertStatus(422);
        foreach ([['rbac', $rbac['preset']['id']], ['requirements', $req['id']], ['planning', $plan['id']]] as [$k, $id]) {
            $this->api()->postJson("/api/platform/presets/{$k}/{$id}/publish")->assertOk();
        }
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [
            ['kind' => 'rbac', 'family_key' => 'logistique_rbac', 'pinned_version' => 1], ['kind' => 'requirements', 'family_key' => 'logistique_requirements'],
            ['kind' => 'planning', 'family_key' => 'logistique_planning']]])->assertOk();

        $fam = $this->api()->getJson("/api/platform/jobdomains/{$this->jd->id}/profile")->json('compatibility.families');
        $this->assertSame(count($this->jd->default_roles), $fam['rbac']['counts']['certain'], 'correspondance certaine après brouillon fidèle');
        $this->assertSame(0, $fam['requirements']['counts']['legacy_only']);
        $this->assertSame(0, $fam['planning']['counts']['legacy_only']);

        // Nouvelle version publiée : la version épinglée reste recommandée ; rien n'est réappliqué.
        $v2 = $this->api()->postJson("/api/platform/presets/{$rbac['preset']['id']}/new-version")->assertSuccessful()->json('id');
        $this->api()->postJson("/api/platform/presets/rbac/{$v2}/publish")->assertOk();
        $rec = collect($this->api()->getJson('/api/platform/jobdomains/logistique/preset-recommendations')->json('recommendations'))->firstWhere('kind', 'rbac');
        $this->assertSame([1, 2, 2], [$rec['effective_version'], $rec['published_version'], $rec['newer_published']]);
        $detail = $this->api()->getJson("/api/platform/presets/rbac/{$v2}")->assertOk()->json();
        $this->assertCount(2, $detail['versions']);
        $this->assertSame('logistique', $detail['recommended_by'][0]['jobdomain_key']);
        $this->assertSame($before, $this->fingerprint($a), 'société existante inchangée');
        $this->assertSame(0, PresetApplication::count(), 'une recommandation ne vaut jamais application');
    }

    // 8, 9, 19, 20 — société existante inchangée ; création historique inchangée ; pas de réconciliation ; conformité inchangée.
    public function test_existing_and_historical_creation_unchanged(): void
    {
        $a = $this->company('A');
        JobdomainGate::assignToCompany($a, 'logistique');
        $legacyA = $this->fingerprint($a);
        $snapshotA = json_encode(collect(json_decode($legacyA, true))->except([])->all());

        // Toute la mécanique L7 en place (brouillons publiés et recommandés).
        foreach (['rbac', 'requirements', 'planning'] as $k) {
            $id = $this->api()->postJson("/api/platform/jobdomains/{$this->jd->id}/legacy-drafts", ['kind' => $k])->json('preset.id');
            $this->api()->postJson("/api/platform/presets/{$k}/{$id}/publish")->assertOk();
        }
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'rbac', 'family_key' => 'logistique_rbac']]])->assertOk();
        $this->api()->getJson("/api/platform/companies/{$a->id}/jobdomain-compatibility")->assertOk();
        $this->assertSame($snapshotA, $this->fingerprint($a), 'aucun droit, module, obligation, poste modifiés');

        // Création historique d'une nouvelle société : même résultat qu'avant L7 (roles, modules, activations).
        $b = $this->company('B');
        JobdomainGate::assignToCompany($b, 'logistique');
        $strip = fn (string $fp) => json_encode(array_slice(json_decode($fp, true), 0, 6));
        $fa = array_slice(json_decode($legacyA, true), 0, 6);
        $fb = array_slice(json_decode($this->fingerprint($b), true), 0, 6);
        foreach ($fa as $i => $part) {
            $this->assertSame(json_encode($part), json_encode($fb[$i]), "partie {$i} : la création historique reste la seule et ne lit pas les presets");
        }
        $this->assertSame(0, PresetApplication::count());

        // Ancienne configuration gelée ; modules recommandés et identité éditables.
        $this->api()->putJson("/api/platform/jobdomains/{$this->jd->id}", ['default_roles' => []])->assertStatus(422)->assertJsonPath('code', 'legacy_configuration_read_only');
        $this->api()->putJson("/api/platform/jobdomains/{$this->jd->id}", ['default_documents' => [['code' => 'id_card']]])->assertStatus(422);
        $this->api()->putJson("/api/platform/jobdomains/logistique/overlays/FR", ['override_fields' => [['code' => 'siret']]])->assertStatus(422);
        $this->api()->putJson("/api/platform/jobdomains/{$this->jd->id}", ['sector' => 'Transport & logistique', 'terminology' => ['employee' => 'livreur']])->assertOk();
        $this->assertSame('livreur', $this->jd->fresh()->terminology['employee']);
        $this->assertSame($snapshotA, $this->fingerprint($a));
    }

    // 10, 23 — initialisation versionnée préparée : plan et parité sans écriture ; refusée tant que non opérationnelle ;
    // une fois activée (test), additive, sans module ni rôle sensible implicite.
    public function test_versioned_initialisation_is_prepared_not_operational(): void
    {
        $rbac = $this->publishedPreset('rbac', ['roles' => [['key' => 'livreur', 'name' => 'Livreur', 'bundles' => ['shipments.delivery']],
            ['key' => 'admin_x', 'name' => 'Admin', 'bundles' => ['members.team_management'], 'is_administrative' => true]]], 'logi_rbac');
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'rbac', 'family_key' => 'logi_rbac']]])->assertOk();
        $counts = fn () => [CompanyRole::count(), DB::table('company_modules')->count(), Company::count(), PresetApplication::count()];
        $before = $counts();
        $res = $this->api()->getJson("/api/platform/jobdomains/{$this->jd->id}/initialization-plan?market=FR")->assertOk()->json();
        $this->assertFalse($res['plan']['operational']);
        $this->assertFalse($res['parity']['parity_proven'], 'jamais de parité déclarée automatiquement');
        $this->assertNull($res['parity']['technical_parity'], 'entrée différente de l’historique : écarts de configuration, pas de verdict technique');
        $this->assertGreaterThan(0, $res['parity']['configuration']);
        $this->assertSame(['livreur', 'admin_x'], array_map(fn ($i) => substr($i['key'], 5), $res['plan']['presets'][0]['items']));
        $this->assertSame(['automatic', 'explicit_decision'], array_column($res['plan']['presets'][0]['items'], 'decision'));
        $this->assertSame($before, $counts(), 'aperçu et mesure de parité sans écriture (transaction annulée)');

        $c = $this->company('N');
        try {
            VersionedCompanyInitializer::initializeNew($c, 'logistique', [], null);
            $this->fail('non opérationnel');
        } catch (ValidationException) {
        }
        config(['jobdomains.versioned_init.enabled' => true]);
        $out = VersionedCompanyInitializer::initializeNew($c, 'logistique', [], null);
        $this->assertSame(['role:livreur'], $out['presets']['rbac']['created'], 'rôle administratif jamais créé sans sélection explicite');
        $classes = collect(VersionedCompanyInitializer::classifyModules((array) $this->jd->default_modules, 'logistique', $c->plan_key))->keyBy('key');
        foreach ($out['modules']['activated'] as $m) {
            $this->assertTrue(! $classes->has($m) || $classes[$m]['activated_at_creation'], "{$m} : seulement des modules inclus (aucun achat)");
        }
        $existing = $this->company('Existante');
        JobdomainGate::assignToCompany($existing, 'logistique');
        $this->expectException(ValidationException::class); // jamais une société existante
        VersionedCompanyInitializer::initializeNew($existing, 'logistique', [], null);
    }

    // 11, 12, 13 — permissions Platform réelles ; utilisateur non superadmin ; aucun accès Company.
    public function test_platform_permissions(): void
    {
        $jdManager = $this->platformUser([], ['manage_jobdomains']);
        $this->api($jdManager)->getJson("/api/platform/jobdomains/{$this->jd->id}/profile")->assertOk();
        $this->api($jdManager)->postJson("/api/platform/jobdomains/{$this->jd->id}/legacy-drafts", ['kind' => 'planning'])->assertCreated();

        $other = $this->platformUser([], ['view_companies']);
        foreach (["/api/platform/jobdomains/{$this->jd->id}/profile", "/api/platform/jobdomains/{$this->jd->id}/initialization-plan", '/api/platform/presets'] as $uri) {
            $this->api($other)->getJson($uri)->assertForbidden();
        }
        $this->api($other)->postJson("/api/platform/jobdomains/{$this->jd->id}/legacy-drafts", ['kind' => 'rbac'])->assertForbidden();

        $companyUser = User::factory()->create();
        $c = $this->company('C');
        Membership::create(['user_id' => $companyUser->id, 'company_id' => $c->id, 'role' => 'owner']);
        $this->app['auth']->forgetGuards(); // session Company seule (le garde Platform ne porte personne)
        $this->actingAs($companyUser, 'web')->getJson("/api/platform/jobdomains/{$this->jd->id}/profile")->assertUnauthorized();
        $this->actingAs($companyUser, 'web')->getJson("/api/platform/companies/{$c->id}/jobdomain-compatibility")->assertUnauthorized();
    }

    // 14, 15, 16, 17, 18, 20 — rapport de compatibilité : lecture seule, isolé par société ; modules actifs / inactifs /
    // ambigus ; exigences par poste ; salarié sans compte ; compte multi-sociétés ; poste jamais déduit d'un rôle.
    public function test_company_compatibility_report_is_read_only_and_isolated(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        JobdomainGate::assignToCompany($a, 'logistique');
        $this->setUpCompanyRbac($a);
        DB::table('company_modules')->insert(['company_id' => $a->id, 'module_key' => 'logistics_fleet', 'is_enabled_for_company' => true, 'created_at' => now(), 'updated_at' => now()]);
        $job = JobRole::withoutGlobalScopes()->create(['company_id' => $a->id, 'title' => 'Livreur', 'level' => 'agent']);
        $multi = User::factory()->create();
        Membership::create(['user_id' => $multi->id, 'company_id' => $a->id, 'role' => 'user', 'company_role_id' => CompanyRole::where('company_id', $a->id)->where('key', 'driver')->value('id')]);
        Membership::create(['user_id' => $multi->id, 'company_id' => $b->id, 'role' => 'user']);
        Employee::withoutGlobalScopes()->create(['company_id' => $a->id, 'user_id' => $multi->id, 'first_name' => 'M', 'last_name' => 'C', 'status' => 'active', 'hire_date' => '2026-01-01', 'job_role_id' => $job->id]);
        Employee::withoutGlobalScopes()->create(['company_id' => $a->id, 'first_name' => 'S', 'last_name' => 'C', 'status' => 'active', 'hire_date' => '2026-01-01']);
        Employee::withoutGlobalScopes()->create(['company_id' => $b->id, 'first_name' => 'B', 'last_name' => 'B', 'status' => 'active', 'hire_date' => '2026-01-01']);
        Requirement::create(['company_id' => $a->id, 'source' => 'company', 'target_kind' => 'document', 'document_type_id' => \App\Core\Documents\DocumentType::where('code', 'medical_certificate')->value('id'),
            'job_role_id' => $job->id, 'level' => 'configurable_required', 'status' => 'active']);
        $before = [$this->fingerprint($a), $this->fingerprint($b)];

        $r = $this->api()->getJson("/api/platform/companies/{$a->id}/jobdomain-compatibility")->assertOk()->json();
        $this->assertSame(['logistics_fleet'], $r['modules']['ambiguous']);
        $this->assertGreaterThan(0, $r['modules']['active']);
        $this->assertSame(['records' => 2, 'without_position' => 1, 'without_account' => 1, 'preparatory' => 0, 'multi_company_accounts' => 1],
            array_diff_key($r['people'], ['position_role_pairs' => 1]));
        $this->assertSame([['pair' => 'Livreur × Driver', 'count' => 1]], $r['people']['position_role_pairs'], 'couple soumis, jamais corrigé');
        $this->assertSame('DocumentResolverService (historique)', $r['requirements']['authority']);
        $this->assertGreaterThan(0, $r['requirements']['parallel_required'], 'exigence par poste vue par la résolution parallèle');
        $this->assertTrue($r['guarantees']['read_only']);
        $this->assertSame($before, [$this->fingerprint($a), $this->fingerprint($b)], 'lecture seule, aucune société modifiée');
        $this->assertSame(1, CompanyJobdomainCompatibility::report($b)['people']['records'], 'isolation : seules les fiches de B');
        $this->api()->getJson('/api/platform/companies/999999/jobdomain-compatibility')->assertNotFound();
    }

    // 22, 24 — régression du dossier candidat (champs personnels toujours proposés) ; commande de nettoyage protégée.
    public function test_candidate_file_and_fixture_guard_unaffected(): void
    {
        $a = $this->company('A');
        JobdomainGate::assignToCompany($a, 'logistique');
        $items = (new \App\Modules\Workforce\Onboarding\OnboardingPlanner($a))->propose(null);
        $this->assertNotEmpty(array_filter($items, fn ($i) => $i['kind'] === 'field'), 'planificateur L6 inchangé');
        $real = Employee::withoutGlobalScopes()->create(['company_id' => $a->id, 'first_name' => 'Réel', 'last_name' => 'X', 'status' => 'inactive', 'hire_date' => '2026-01-01']);
        $this->artisan('onboarding:cleanup-fixtures', ['employee' => [$real->id], '--apply' => true])->assertFailed();
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($real->id), 'jamais de suppression hors fixture autorisée');
    }
}
