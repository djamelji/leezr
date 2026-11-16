<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\Modernization\CreationParity;
use App\Core\Jobdomains\Modernization\VersionedCompanyInitializer;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetApplication;
use App\Core\Presets\PresetService;
use App\Core\Requirements\CompanyRequirementResolver;
use App\Core\Requirements\EffectiveRequirementResolver;
use App\Core\Requirements\Requirement;
use App\Core\Workforce\Employee;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-798 — specialised preset editing (drafts only, server validation, optimistic concurrency, audit),
 * preset lifecycle invariants (no broken recommendation), company-level requirements (company / legal
 * representative subjects), module classification at creation, measured creation parity on fictive
 * companies, granular Platform permissions. No existing company is ever modified.
 */
class Adr798PresetEditorsCompanyRequirementsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        $this->admin = $this->platformUser(['super_admin']);
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

    private function create(string $kind, array $content, string $family): array
    {
        $res = $this->api()->postJson('/api/platform/presets', ['kind' => $kind, 'family_key' => $family, 'label' => $family, 'content' => $content, 'jobdomain_key' => 'logistique']);
        $this->assertSame(201, $res->status(), $res->getContent());

        return $res->json();
    }

    private function company(string $name): Company
    {
        return Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'starter']);
    }

    private function fingerprint(Company $c): string
    {
        return json_encode(CreationParity::fingerprint($c) + [
            'employees' => Employee::withoutGlobalScopes()->where('company_id', $c->id)->orderBy('id')->get(['id', 'job_role_id', 'status', 'user_id']),
            'field_values' => DB::table('field_values')->count(), 'requests' => DocumentRequest::where('company_id', $c->id)->count(),
            'compliance' => collect(DocumentResolverService::requirementMap($c->id, null, 'FR'))->map(fn ($e) => [$e['required'], $e['visible']]),
        ]);
    }

    // 1, 2, 10, 13 — éditeur RBAC sur brouillon ; version publiée immuable ; édition concurrente ; audit ; aucune société touchée.
    public function test_rbac_draft_editing_concurrency_and_immutability(): void
    {
        $existing = $this->company('Existante');
        JobdomainGate::assignToCompany($existing, 'logistique');
        $before = $this->fingerprint($existing);
        $p = $this->create('rbac', ['roles' => [['key' => 'livreur', 'name' => 'Livreur', 'bundles' => ['shipments.delivery']]]], 'edit_rbac');
        $this->assertSame(0, $p['revision']);

        $roles = [['key' => 'livreur', 'name' => 'Livreur confirmé', 'bundles' => ['shipments.delivery'], 'archetype' => 'field_worker'],
            ['key' => 'chef', 'name' => 'Chef d’équipe', 'bundles' => ['members.team_management'], 'is_administrative' => true]];
        $saved = $this->api()->putJson("/api/platform/presets/{$p['id']}", ['content' => ['roles' => $roles], 'revision' => 0])->assertOk()->json();
        $this->assertSame([1, 'Livreur confirmé', 'field_worker'], [$saved['revision'], $saved['content']['roles'][0]['name'], $saved['content']['roles'][0]['archetype']]);
        // Éditeur resté sur la révision 0 : conflit, rien d'écrasé.
        $this->api()->putJson("/api/platform/presets/{$p['id']}", ['content' => ['roles' => [$roles[0]]], 'revision' => 0])->assertStatus(409)->assertJsonPath('code', 'stale_revision');
        $this->assertCount(2, Preset::find($p['id'])->content['roles']);
        // Validation serveur : lot inconnu, clé dupliquée.
        $this->api()->putJson("/api/platform/presets/{$p['id']}", ['content' => ['roles' => [['key' => 'x', 'name' => 'X', 'bundles' => ['ghost.bundle']]]], 'revision' => 1])->assertStatus(422);
        $this->api()->putJson("/api/platform/presets/{$p['id']}", ['content' => ['roles' => [$roles[0], $roles[0]]], 'revision' => 1])->assertStatus(422);
        $this->assertTrue(DB::table('platform_audit_logs')->where('action', 'preset.draft_updated')->exists());

        $this->api()->postJson("/api/platform/presets/rbac/{$p['id']}/publish")->assertOk();
        $this->api()->putJson("/api/platform/presets/{$p['id']}", ['content' => ['roles' => [$roles[0]]], 'revision' => 1])->assertStatus(422); // publiée : immuable
        $v2 = $this->api()->postJson("/api/platform/presets/{$p['id']}/new-version")->assertCreated()->json();
        $this->assertSame(['draft', 2], [$v2['status'], $v2['version']]);
        $this->assertSame(['added' => [], 'removed' => [], 'changed' => []], array_intersect_key($this->api()->getJson("/api/platform/presets/rbac/{$p['id']}/diff/{$v2['id']}")->json(), array_flip(['added', 'removed', 'changed'])));
        $this->assertSame($before, $this->fingerprint($existing), 'éditer n’a modifié aucune société');
    }

    // 3, 11, 12, 13, 14, 15, 25 — exigences : entreprise / collaborateur / représentant légal, provenance, jamais impératif,
    // aperçu sans écriture, aucune demande DMS ; la question « quoi et pourquoi » pour une société.
    public function test_requirements_editor_company_and_person_subjects(): void
    {
        $content = ['items' => [
            ['subject' => 'company', 'kind' => 'document', 'code' => 'kbis', 'level' => 'configurable_required', 'origin' => 'manual'],
            ['subject' => 'company', 'kind' => 'field', 'code' => 'siret', 'level' => 'default'],
            ['kind' => 'document', 'code' => 'id_card', 'level' => 'configurable_required'],
            ['kind' => 'document', 'code' => 'driving_license', 'level' => 'recommended', 'job_role_keys' => ['driver'], 'stage' => 'hire'],
            ['subject' => 'legal_representative', 'kind' => 'document', 'code' => 'id_card', 'level' => 'configurable_required'],
        ]];
        $bad = fn (array $item) => $this->api()->postJson('/api/platform/presets', ['kind' => 'requirements', 'family_key' => 'bad_'.uniqid(), 'label' => 'B', 'content' => ['items' => [$item]]])->assertStatus(422);
        $bad(['subject' => 'company', 'kind' => 'document', 'code' => 'id_card', 'level' => 'default']);          // pièce de personne demandée à l'entreprise
        $bad(['kind' => 'document', 'code' => 'kbis', 'level' => 'default']);                                      // Kbis demandé à un salarié
        $bad(['kind' => 'document', 'code' => 'id_card', 'level' => 'imperative']);                                // jamais impératif
        $bad(['subject' => 'company', 'kind' => 'document', 'code' => 'kbis', 'level' => 'default', 'job_role_keys' => ['driver']]);
        $bad(['subject' => 'robot', 'kind' => 'document', 'code' => 'kbis', 'level' => 'default']);
        $dup = $content;
        $dup['items'][] = $content['items'][2];
        $this->api()->postJson('/api/platform/presets', ['kind' => 'requirements', 'family_key' => 'dup', 'label' => 'D', 'content' => $dup])->assertStatus(422);

        $p = $this->create('requirements', $content, 'logi_req');
        $this->assertSame('manual', Preset::find($p['id'])->content['items'][0]['origin'], 'provenance conservée');
        $this->api()->postJson("/api/platform/presets/requirements/{$p['id']}/publish")->assertOk();
        $this->assertSame(['company' => 2, 'legal_representative' => 1, 'person' => 2],
            Requirement::whereNull('company_id')->where('source_ref', 'logi_req')->get()->countBy('subject_scope')->sortKeys()->all(), 'modèles par sujet');

        $c = $this->company('A');
        JobdomainGate::assignToCompany($c, 'logistique');
        $requestsBefore = DocumentRequest::count();
        $preview = $this->api()->getJson("/api/platform/presets/requirements/{$p['id']}/companies/{$c->id}/preview")->assertOk()->json('items');
        $this->assertContains('company · document:kbis', array_column($preview, 'label'));
        $this->assertSame(0, PresetApplication::count(), 'aperçu sans écriture');

        PresetService::apply($c, Preset::find($p['id']), null, 'platform', null);
        $this->assertSame(2, Requirement::where('company_id', $c->id)->where('subject_scope', 'company')->count());
        $persons = collect(EffectiveRequirementResolver::forCompany($c)->resolveFor(['user_id' => null, 'employee_id' => 1, 'job_role_id' => null]))->keys();
        $this->assertFalse($persons->contains(fn ($k) => str_contains($k, (string) DocumentType::where('code', 'kbis')->value('id')) && str_starts_with($k, 'document:')), 'le Kbis n’est jamais exigé d’un salarié');

        $answer = CompanyRequirementResolver::resolve($c);
        $kbis = collect($answer['items'])->firstWhere('code', 'kbis');
        $this->assertSame(['company', true, 'missing'], [$kbis['subject'], $kbis['required'], $kbis['state']]);
        $this->assertEqualsCanonicalizing(['historical_activation', 'registry'], array_column($kbis['sources'], 'type'), 'pourquoi : activation historique + preset');
        $this->assertSame('asked_to_representative', collect($answer['items'])->firstWhere('subject', 'legal_representative')['state']);
        $this->assertSame($requestsBefore, DocumentRequest::count(), 'aucune demande DMS');
        $this->api()->getJson("/api/platform/companies/{$c->id}/company-requirements")->assertOk()->assertJsonPath('authority', 'historical_activations');
    }

    // 4 — éditeur planning : format du moteur existant, références contrôlées, aperçu d'import en lecture.
    public function test_planning_editor_validates_engine_format(): void
    {
        $content = ['coverage_categories' => [['code' => 'route', 'name' => 'Route']],
            'activity_presets' => [['code' => 'brief', 'name' => 'Brief', 'coverage_category_code' => 'route', 'default_duration_minutes' => 30]],
            'schedule_templates' => [['code' => 'matin', 'name' => 'Matin', 'start_time' => '06:00', 'end_time' => '13:00', 'coverage_category_code' => 'route',
                'composition' => ['activities' => [['preset_code' => 'brief', 'offset_minutes' => 0, 'duration_minutes' => 30]]]]],
            'week_templates' => [['code' => 'semaine', 'name' => 'Semaine', 'strategy' => 'needs', 'lines' => [['kind' => 'needs', 'day_index' => 0, 'headcount' => 2, 'schedule_template_code' => 'matin']]]]];
        $p = $this->create('planning', $content, 'logi_plan');
        $content['schedule_templates'][] = ['code' => 'soir', 'name' => 'Soir', 'start_time' => '14:00', 'end_time' => '21:00'];
        $this->api()->putJson("/api/platform/presets/{$p['id']}", ['content' => $content, 'revision' => 0])->assertOk();
        $broken = $content;
        $broken['schedule_templates'][0]['composition']['activities'][0]['preset_code'] = 'inconnue';
        $this->api()->putJson("/api/platform/presets/{$p['id']}", ['content' => $broken, 'revision' => 1])->assertStatus(422);
        $this->api()->postJson("/api/platform/presets/planning/{$p['id']}/publish")->assertOk();
        $c = $this->company('P');
        $items = $this->api()->getJson("/api/platform/presets/planning/{$p['id']}/companies/{$c->id}/preview")->assertOk()->json('items');
        $this->assertCount(5, $items);
        $this->assertSame(0, DB::table('workforce_schedule_templates')->where('company_id', $c->id)->count(), 'aperçu sans import');
    }

    // 5, 6, 7, 8, 9 — cycle de vie : publication, nouvelle version, archivage refusé si recommandation cassée, version épinglée,
    // remplacement possible, applications historiques conservées ; éditeur Organisation : même règle, rien d'autre ne change.
    public function test_lifecycle_never_breaks_a_recommendation(): void
    {
        $p = $this->create('rbac', ['roles' => [['key' => 'agent', 'name' => 'A', 'bundles' => ['shipments.delivery']]]], 'life');
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'rbac', 'family_key' => 'life']]])->assertStatus(422); // aucune version publiée
        $this->api()->postJson("/api/platform/presets/rbac/{$p['id']}/publish")->assertOk();
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'rbac', 'family_key' => 'life']]])->assertOk();
        $c = $this->company('Hist');
        PresetService::apply($c, Preset::find($p['id']), null, 'platform', null);

        $this->api()->postJson("/api/platform/presets/{$p['id']}/archive")->assertStatus(422)->assertJsonPath('code', 'recommendation_would_break')->assertJsonPath('latest_for', ['logistique']);
        $v2 = $this->api()->postJson("/api/platform/presets/{$p['id']}/new-version")->json('id');
        $this->api()->postJson("/api/platform/presets/rbac/{$v2}/publish")->assertStatus(409)->assertJsonPath('code', 'shared_preset'); // ADR-803 : famille suivie par un domaine
        $this->api()->postJson("/api/platform/presets/rbac/{$v2}/publish", ['mode' => 'shared'])->assertOk(); // remplacement explicite : v1 archivée par la publication
        $this->assertSame('archived', Preset::find($p['id'])->status);
        $this->api()->postJson("/api/platform/presets/{$v2}/archive")->assertStatus(422); // dernière publiée, recommandée sans épinglage

        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'rbac', 'family_key' => 'life', 'pinned_version' => 2]]])->assertOk();
        $v3 = $this->api()->postJson("/api/platform/presets/{$v2}/new-version")->json('id');
        $this->api()->postJson("/api/platform/presets/rbac/{$v3}/publish")->assertOk();
        $this->api()->postJson("/api/platform/presets/{$v2}/archive")->assertStatus(422)->assertJsonPath('pinned_by', ['logistique']); // épinglée
        $this->api()->postJson("/api/platform/presets/{$v3}/archive")->assertOk(); // non épinglée et non requise : archivable
        $this->assertSame(1, PresetApplication::where('company_id', $c->id)->where('preset_version', 1)->count(), 'application historique conservée');
        $this->assertSame(1, (int) PresetApplication::where('company_id', $c->id)->max('preset_version'), 'aucune société basculée de version');

        // Organisation : éditeur existant inchangé, même invariant d'archivage.
        $org = \App\Modules\Workforce\Organization\OrganizationPreset::create(['key' => 'org_x_v1', 'family_key' => 'org_x', 'version' => 1, 'status' => 'published', 'published_at' => now(),
            'jobdomain_key' => 'logistique', 'label' => 'Org', 'structure' => ['job_roles' => [], 'departments' => []]]);
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'organization', 'family_key' => 'org_x']]])->assertOk();
        $this->api()->postJson("/api/platform/organization-presets/{$org->id}/archive")->assertStatus(422);
        $this->api()->getJson("/api/platform/organization-presets/{$org->id}")->assertOk();
    }

    // 16, 17, 18 — modules classés ; jamais d'achat implicite ; aucun rôle sensible sans confirmation explicite.
    public function test_module_classification_and_no_implicit_purchase_or_elevation(): void
    {
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['core.members', 'logistics_shipments', 'logistics_fleet']]);
        $classes = collect(VersionedCompanyInitializer::classifyModules(['core.members', 'logistics_shipments', 'logistics_fleet', 'ghost'], 'logistique', 'starter'))->pluck('class', 'key')->all();
        $this->assertSame(['core.members' => 'core', 'logistics_shipments' => 'included', 'logistics_fleet' => 'paid_optional', 'ghost' => 'unavailable'], $classes);
        $this->assertSame('incompatible', collect(VersionedCompanyInitializer::classifyModules(['logistics_fleet'], 'beta-services', 'business'))->first()['class'] ?? 'incompatible');

        $this->create('rbac', ['roles' => [['key' => 'livreur', 'name' => 'Livreur', 'bundles' => ['shipments.delivery']],
            ['key' => 'owner', 'name' => 'Propriétaire', 'bundles' => ['members.team_management'], 'is_administrative' => true],
            ['key' => 'chef', 'name' => 'Chef', 'bundles' => ['members.team_management'], 'is_administrative' => true]]], 'logi_rbac');
        $this->api()->postJson('/api/platform/presets/rbac/'.Preset::where('family_key', 'logi_rbac')->value('id').'/publish')->assertOk();
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'rbac', 'family_key' => 'logi_rbac']]])->assertOk();

        config(['jobdomains.versioned_init.enabled' => true]);
        $c = $this->company('Nouvelle');
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'owner']);
        $subsBefore = DB::table('company_addon_subscriptions')->count();
        $out = VersionedCompanyInitializer::initializeNew($c, 'logistique', ['rbac' => ['owner']], null);
        $this->assertNotContains('logistics_fleet', $out['modules']['activated'], 'module payant jamais activé');
        $this->assertContains('logistics_shipments', $out['modules']['activated']);
        $this->assertSame($subsBefore, DB::table('company_addon_subscriptions')->count(), 'aucun abonnement');
        $this->assertEqualsCanonicalizing(['livreur', 'owner'], CompanyRole::where('company_id', $c->id)->pluck('key')->all(), '« chef » sensible non confirmé : non créé');
        $this->assertSame('owner', CompanyRole::find(Membership::where('company_id', $c->id)->value('company_role_id'))->key);
    }

    // 19, 20, 21, 22, 23, 24 — création historique et versionnée sur sociétés FICTIVES ; comparaison mesurée ; transaction annulée ;
    // société existante, obligations et données RH inchangées ; inscription derrière le drapeau.
    public function test_creation_parity_on_fictive_companies_and_registration_flag(): void
    {
        $existing = $this->company('Existante');
        JobdomainGate::assignToCompany($existing, 'logistique');
        $before = $this->fingerprint($existing);
        $companies = Company::count();

        $r = CreationParity::measure('logistique', 'FR', 'starter', true);
        $this->assertSame(0, $r['bugs'], 'parité technique : '.json_encode(array_filter($r['differences'], fn ($d) => $d['class'] === 'bug')));
        $this->assertTrue($r['technical_parity']);
        $this->assertFalse($r['parity_proven'], 'différences volontaires : décision produit requise');
        $this->assertGreaterThan(0, $r['voluntary']);
        $this->assertSame(['settings', 'roles', 'owner_role', 'modules', 'module_reasons', 'field_activations', 'document_activations', 'dashboards', 'requirements_registry', 'planning'], $r['dimensions']);
        $this->assertSame($companies, Company::count(), 'aucune société fictive ne subsiste');
        $this->assertSame(0, Preset::count(), 'presets de simulation annulés');
        $this->assertSame($before, $this->fingerprint($existing), 'société existante inchangée');

        // Inscription : drapeau désactivé → chemin historique ; activé → initialisation versionnée (presets requis).
        $register = fn (string $email) => $this->postJson('/api/register', ['first_name' => 'R', 'last_name' => 'U', 'email' => $email, 'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong', 'company_name' => 'Reg '.$email, 'jobdomain_key' => 'logistique'])->assertCreated()->json('company.id');
        $legacyId = $register('legacy@example.test');
        $this->assertSame(0, PresetApplication::where('company_id', $legacyId)->count());
        $this->app['auth']->forgetGuards();
        foreach (['rbac', 'requirements'] as $kind) {
            $id = $this->api()->postJson('/api/platform/jobdomains/'.Jobdomain::where('key', 'logistique')->value('id').'/legacy-drafts', ['kind' => $kind])->json('preset.id');
            $this->api()->postJson("/api/platform/presets/{$kind}/{$id}/publish")->assertOk();
        }
        $this->api()->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => [['kind' => 'rbac', 'family_key' => 'logistique_rbac'], ['kind' => 'requirements', 'family_key' => 'logistique_requirements']]])->assertOk();
        config(['jobdomains.versioned_init.enabled' => true]);
        $this->app['auth']->forgetGuards();
        $newId = $register('versioned@example.test');
        $this->assertSame(2, PresetApplication::where('company_id', $newId)->count(), 'création versionnée tracée');
        $this->assertTrue(CompanyRole::where('company_id', $newId)->where('key', 'owner')->exists(), 'rôle owner confirmé par le créateur');
        $this->assertSame($before, $this->fingerprint($existing));
    }

    // 26, 27 — permissions Platform fines ; aucun accès Company ; isolation.
    public function test_granular_platform_permissions(): void
    {
        $p = $this->create('rbac', ['roles' => [['key' => 'agent', 'name' => 'A', 'bundles' => ['shipments.delivery']]]], 'perm');
        $c = $this->company('C');
        $reader = $this->platformUser([], ['presets.view']);
        $editor = $this->platformUser([], ['presets.view', 'presets.edit']);
        $publisher = $this->platformUser([], ['presets.view', 'presets.publish']);
        $previewer = $this->platformUser([], ['presets.preview_company']);
        $none = $this->platformUser([], ['view_companies']);

        $this->api($reader)->getJson('/api/platform/presets')->assertOk();
        $this->api($reader)->getJson('/api/platform/presets/meta')->assertOk();
        $this->api($reader)->putJson("/api/platform/presets/{$p['id']}", ['label' => 'X'])->assertForbidden();
        $this->api($reader)->postJson("/api/platform/presets/rbac/{$p['id']}/publish")->assertForbidden();
        $this->api($editor)->putJson("/api/platform/presets/{$p['id']}", ['label' => 'Éditée', 'revision' => 0])->assertOk();
        $this->api($editor)->postJson("/api/platform/presets/rbac/{$p['id']}/publish")->assertForbidden();
        $this->api($publisher)->putJson("/api/platform/presets/{$p['id']}", ['label' => 'Y'])->assertForbidden();
        $this->api($publisher)->postJson("/api/platform/presets/rbac/{$p['id']}/publish")->assertOk();
        $this->api($publisher)->putJson('/api/platform/jobdomains/logistique/preset-recommendations', ['links' => []])->assertForbidden(); // recommander = droit complet
        $this->api($publisher)->postJson("/api/platform/presets/rbac/{$p['id']}/companies/{$c->id}/apply")->assertForbidden(); // appliquer = droit complet
        $this->api($previewer)->getJson("/api/platform/presets/rbac/{$p['id']}/companies/{$c->id}/preview")->assertOk();
        $this->api($previewer)->getJson("/api/platform/companies/{$c->id}/company-requirements")->assertOk();
        $this->api($previewer)->getJson('/api/platform/presets')->assertForbidden();
        foreach (['/api/platform/presets', '/api/platform/presets/meta', "/api/platform/companies/{$c->id}/company-requirements"] as $uri) {
            $this->api($none)->getJson($uri)->assertForbidden();
        }
        $this->api()->getJson('/api/platform/companies/999999/company-requirements')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $user = User::factory()->create();
        Membership::create(['user_id' => $user->id, 'company_id' => $c->id, 'role' => 'owner']);
        $this->actingAs($user, 'web')->getJson('/api/platform/presets/meta')->assertUnauthorized();
        $this->assertTrue(PlatformPermission::where('key', 'presets.publish')->exists(), 'droits déclarés au catalogue Platform');
    }

    // 29 — pas de N+1 : le nombre de requêtes ne croît pas avec le contenu.
    public function test_no_n_plus_one_on_company_requirements_and_meta(): void
    {
        $c = $this->company('Q');
        JobdomainGate::assignToCompany($c, 'logistique');
        DB::enableQueryLog();
        CompanyRequirementResolver::resolve($c);
        $few = count(DB::getQueryLog());
        foreach (['kbis', 'insurance_certificate', 'transport_license'] as $code) {
            Requirement::create(['company_id' => $c->id, 'subject_scope' => 'company', 'target_kind' => 'document', 'document_type_id' => DocumentType::where('code', $code)->value('id'),
                'level' => 'recommended', 'source' => 'company', 'status' => 'active']);
        }
        DB::flushQueryLog();
        CompanyRequirementResolver::resolve($c);
        $this->assertSame($few, count(DB::getQueryLog()), 'requêtes constantes');
        DB::flushQueryLog();
        $this->api()->getJson('/api/platform/presets/meta')->assertOk();
        $this->assertLessThan(40, count(DB::getQueryLog()));
    }
}
