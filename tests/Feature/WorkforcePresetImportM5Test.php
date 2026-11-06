<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\ActivityPreset;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Core\Workforce\WeekTemplate;
use App\Modules\Workforce\Presets\WorkforcePresetImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Refonte Smart Planning — M5 (ADR-728) : import des presets métier JobDomain + favoris.
 */
class WorkforcePresetImportM5Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 08:00:00');
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_planning'], 'workforce_presets' => JobdomainRegistry::workforcePresetsFor('logistique')]);
        Jobdomain::create(['key' => 'autre', 'label' => 'A', 'default_modules' => ['workforce', 'workforce_planning'], 'workforce_presets' => []]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
        $this->company = Company::create(['name' => 'M5', 'slug' => 'm5-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function svc(): WorkforcePresetImportService
    {
        return app(WorkforcePresetImportService::class);
    }

    private function user(array $perms, ?int $cid = null): User
    {
        $cid ??= $this->company->id;
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $cid, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $cid, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    // --- 1. Preview READ-ONLY ---
    public function test_preview_is_readonly_and_lists_items(): void
    {
        $prev = $this->svc()->preview($this->company);
        $this->assertTrue($prev['has_presets']);
        $this->assertCount(4, $prev['schedule_templates']);
        $this->assertSame('new', $prev['schedule_templates'][0]['status']);
        $this->assertSame(0, ScheduleTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->count(), 'Preview n’écrit rien.');
    }

    // --- 2. Import complet + provenance + composition ---
    public function test_import_all_creates_objects_with_provenance(): void
    {
        $res = $this->svc()->apply($this->company);
        $this->assertSame(4, CoverageCategory::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(6, ActivityPreset::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(4, ScheduleTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(1, WeekTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        $tpl = ScheduleTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Shift journée')->first();
        $this->assertSame('jobdomain_preset', $tpl->metadata['provenance']['source']);
        $this->assertSame('shift_journee', $tpl->metadata['provenance']['code']);
        // Composition importée (activités résolues + preset_id).
        $this->assertSame(1, $tpl->metadata['composition']['schema_version']);
        $this->assertNotEmpty($tpl->metadata['composition']['activities'][0]['preset_id']);
        // Semaine type : lignes résolues vers les modèles importés.
        $wt = WeekTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->with('lines')->first();
        $this->assertGreaterThan(0, $wt->lines->count());
        $this->assertNotNull($wt->lines->first()->schedule_template_id);
    }

    // --- 3. Idempotence : rejouer = aucun doublon ---
    public function test_idempotent_reimport(): void
    {
        $r1 = $this->svc()->apply($this->company);
        $this->assertNotEmpty($r1['created']);
        $r2 = $this->svc()->apply($this->company);
        $this->assertEmpty($r2['created'], 'Rien de recréé.');
        $this->assertSame(4, ScheduleTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    // --- 4. Import PARTIEL + résolution de dépendances ---
    public function test_partial_import_resolves_dependencies(): void
    {
        $res = $this->svc()->apply($this->company, ['week_template:semaine_logistique']);
        // La semaine tire ses modèles de shift (préparation, journée, livraison) + leurs tâches + catégories.
        $this->assertSame(1, WeekTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $names = ScheduleTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('name')->all();
        $this->assertContains('Shift journée', $names);
        $this->assertNotContains('Formation', $names, 'Le modèle non requis par la semaine n’est pas importé.');
    }

    // --- 5. Provenance stable : renommage préservé, pas de recréation ---
    public function test_rename_preserved_on_reimport(): void
    {
        $this->svc()->apply($this->company);
        $tpl = ScheduleTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Shift journée')->first();
        $tpl->update(['name' => 'Mon shift perso']);
        $this->svc()->apply($this->company);
        $this->assertSame(0, ScheduleTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Shift journée')->count(), 'Pas de recréation sous l’ancien nom.');
        $this->assertSame('Mon shift perso', $tpl->fresh()->name);
    }

    // --- 6. Conflit d'homonyme Company (jamais écrasé/dédoublé) ---
    public function test_name_clash_is_conflict_not_duplicate(): void
    {
        Model::unguard();
        CoverageCategory::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'MINE', 'name' => 'Route', 'enabled' => true]);
        Model::reguard();
        $res = $this->svc()->apply($this->company);
        $this->assertTrue(collect($res['conflicts'])->contains(fn ($c) => str_contains($c, 'coverage_category:route')));
        $this->assertSame(1, CoverageCategory::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Route')->count(), 'Pas de doublon.');
    }

    // --- 7. Aucun Shift / CoverageRequirement créé ---
    public function test_import_creates_no_shift_or_coverage(): void
    {
        $this->svc()->apply($this->company);
        $this->assertSame(0, Shift::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, \App\Core\Workforce\CoverageRequirement::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    // --- 8. Aucun effet rétroactif (preset Platform modifié après import) ---
    public function test_platform_preset_change_has_no_retroactive_effect(): void
    {
        $this->svc()->apply($this->company);
        $jd = Jobdomain::where('key', 'logistique')->first();
        $jd->update(['workforce_presets' => ['schema_version' => 1, 'schedule_templates' => [], 'activity_presets' => [], 'week_templates' => [], 'coverage_categories' => []]]);
        $this->assertSame(4, ScheduleTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->count(), 'Objets Company autonomes.');
    }

    // --- 9. Import HTTP + RBAC ---
    public function test_import_http_rbac(): void
    {
        $h = ['X-Company-Id' => $this->company->id];
        $viewer = $this->user(['workforce.planning_view']);
        $this->actingAs($viewer)->getJson('/api/workforce/planning/presets/import-preview', $h)->assertOk()->assertJsonFragment(['has_presets' => true]);
        $this->actingAs($viewer)->postJson('/api/workforce/planning/presets/import', [], $h)->assertForbidden();
        $m = $this->user(['workforce.planning_view', 'workforce.planning_manage']);
        $this->actingAs($m)->postJson('/api/workforce/planning/presets/import', [], $h)->assertCreated();
    }

    // --- 10. Favoris : toggle + séparation par tenant + appartenance ---
    public function test_favorites_toggle_and_tenant_separation(): void
    {
        $this->svc()->apply($this->company);
        $tpl = ScheduleTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
        $u = $this->user(['workforce.planning_view']);
        $h = ['X-Company-Id' => $this->company->id];

        $this->actingAs($u)->postJson('/api/workforce/planning/favorites/toggle', ['favoritable_type' => 'schedule_template', 'favoritable_id' => $tpl->id], $h)->assertOk()->assertJsonFragment(['favorited' => true]);
        $this->actingAs($u)->getJson('/api/workforce/planning/favorites', $h)->assertOk()->assertJsonFragment(['favoritable_id' => $tpl->id]);
        // Toggle à nouveau → retiré.
        $this->actingAs($u)->postJson('/api/workforce/planning/favorites/toggle', ['favoritable_type' => 'schedule_template', 'favoritable_id' => $tpl->id], $h)->assertOk()->assertJsonFragment(['favorited' => false]);

        // Objet d'une autre company → refus.
        Model::unguard();
        $other = Company::create(['name' => 'O', 'slug' => 'o-m5', 'jobdomain_key' => 'autre', 'market_key' => 'FR']);
        $foreign = ScheduleTemplate::withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'F', 'start_time' => '09:00', 'end_time' => '17:00']);
        Model::reguard();
        $this->actingAs($u)->postJson('/api/workforce/planning/favorites/toggle', ['favoritable_type' => 'schedule_template', 'favoritable_id' => $foreign->id], $h)->assertStatus(422);
    }
}
