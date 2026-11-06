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
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\ActivityPreset;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\ScheduledActivity;
use App\Modules\Workforce\Services\ScheduleTemplateCompositionValidator;
use App\Modules\Workforce\UseCases\CreateScheduleTemplateUseCase;
use App\Modules\Workforce\UseCases\CreateShiftUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Refonte Smart Planning — M2 (ADR-725) : catalogue de TÂCHES TYPES (ActivityPreset).
 *
 * Vérifie : CRUD autorisé + RBAC (viewer vs manager), isolation tenant, validation, désactivation/
 * réactivation, copie dans une composition + matérialisation, AUCUN effet rétroactif quand le preset
 * change/est désactivé/supprimé, compat M1, TimeEntry inchangé.
 */
class WorkforceActivityPresetM2Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CoverageCategory $route;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 08:00:00');
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_planning']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
        $this->company = Company::create(['name' => 'M2', 'slug' => 'm2-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->route = CoverageCategory::create(['company_id' => $this->company->id, 'code' => 'ROUTE', 'name' => 'Route', 'color' => '#3b82f6', 'enabled' => true]);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(array $perms, ?int $companyId = null): User
    {
        $cid = $companyId ?? $this->company->id;
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $cid, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $cid, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    private function manager(?int $companyId = null): User
    {
        return $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish'], $companyId);
    }

    private function emp(): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);

        return $e->fresh();
    }

    // --- 1. RBAC : viewer lit, manager écrit ---
    public function test_rbac_viewer_reads_manager_writes(): void
    {
        $h = ['X-Company-Id' => $this->company->id];
        $payload = ['name' => 'Préparation véhicule', 'activity_type' => 'prep', 'default_duration_minutes' => 60];

        $viewer = $this->user(['workforce.planning_view']);
        $this->actingAs($viewer)->getJson('/api/workforce/planning/activity-presets', $h)->assertOk();
        $this->actingAs($viewer)->postJson('/api/workforce/planning/activity-presets', $payload, $h)->assertForbidden();

        $this->actingAs($this->manager())->postJson('/api/workforce/planning/activity-presets', $payload, $h)
            ->assertCreated()->assertJsonFragment(['name' => 'Préparation véhicule', 'activity_type' => 'prep']);
    }

    // --- 2. CRUD complet (manager) ---
    public function test_full_crud(): void
    {
        $m = $this->manager();
        $h = ['X-Company-Id' => $this->company->id];
        $id = $this->actingAs($m)->postJson('/api/workforce/planning/activity-presets', ['name' => 'Brief', 'default_duration_minutes' => 30], $h)->json('id');

        $this->actingAs($m)->putJson("/api/workforce/planning/activity-presets/{$id}", ['name' => 'Brief équipe', 'priority' => 'high'], $h)
            ->assertOk()->assertJsonFragment(['name' => 'Brief équipe', 'priority' => 'high']);
        $this->actingAs($m)->getJson('/api/workforce/planning/activity-presets', $h)->assertOk()->assertJsonFragment(['name' => 'Brief équipe']);
        $this->actingAs($m)->deleteJson("/api/workforce/planning/activity-presets/{$id}", [], $h)->assertOk();
        $this->assertDatabaseMissing('workforce_activity_presets', ['id' => $id]);
    }

    // --- 3. Isolation tenant ---
    public function test_tenant_isolation(): void
    {
        Model::unguard();
        $other = Company::create(['name' => 'OTH', 'slug' => 'oth2-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $mod) {
            CompanyModuleActivationReason::create(['company_id' => $other->id, 'module_key' => $mod, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $foreign = ActivityPreset::create(['company_id' => $other->id, 'name' => 'Secret B']);
        Model::reguard();

        $m = $this->manager();
        $h = ['X-Company-Id' => $this->company->id];
        // La liste de A ne contient pas le preset de B.
        $this->actingAs($m)->getJson('/api/workforce/planning/activity-presets', $h)->assertOk()->assertJsonMissing(['name' => 'Secret B']);
        // PUT/DELETE sur l'id de B depuis A → 404 (hors scope company).
        $this->actingAs($m)->putJson("/api/workforce/planning/activity-presets/{$foreign->id}", ['name' => 'Hack'], $h)->assertNotFound();
        $this->actingAs($m)->deleteJson("/api/workforce/planning/activity-presets/{$foreign->id}", [], $h)->assertNotFound();
    }

    // --- 4. Validation ---
    public function test_validation(): void
    {
        $m = $this->manager();
        $h = ['X-Company-Id' => $this->company->id];
        $this->actingAs($m)->postJson('/api/workforce/planning/activity-presets', ['name' => 'Dup'], $h)->assertCreated();
        // Nom dupliqué
        $this->actingAs($m)->postJson('/api/workforce/planning/activity-presets', ['name' => 'Dup'], $h)->assertStatus(422);
        // Priorité invalide
        $this->actingAs($m)->postJson('/api/workforce/planning/activity-presets', ['name' => 'P', 'priority' => 'urgent'], $h)->assertStatus(422);
        // Catégorie de couverture d'une autre company
        Model::unguard();
        $other = Company::create(['name' => 'O3', 'slug' => 'o3-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $foreignCat = CoverageCategory::create(['company_id' => $other->id, 'code' => 'X', 'name' => 'X']);
        Model::reguard();
        $this->actingAs($m)->postJson('/api/workforce/planning/activity-presets', ['name' => 'Q', 'coverage_category_id' => $foreignCat->id], $h)->assertStatus(422);
    }

    // --- 5. Désactivation / réactivation réversibles ---
    public function test_disable_then_enable(): void
    {
        $m = $this->manager();
        $h = ['X-Company-Id' => $this->company->id];
        $id = $this->actingAs($m)->postJson('/api/workforce/planning/activity-presets', ['name' => 'Toggle'], $h)->json('id');
        $this->actingAs($m)->putJson("/api/workforce/planning/activity-presets/{$id}", ['enabled' => false], $h)->assertOk()->assertJsonFragment(['enabled' => false]);
        $this->actingAs($m)->putJson("/api/workforce/planning/activity-presets/{$id}", ['enabled' => true], $h)->assertOk()->assertJsonFragment(['enabled' => true]);
    }

    // --- Helpers chaîne composition ---
    private function presetModel(array $attr = []): ActivityPreset
    {
        return ActivityPreset::create(array_merge([
            'company_id' => $this->company->id, 'name' => 'Préparation véhicule '.uniqid(),
            'activity_type' => 'prep', 'coverage_category_id' => $this->route->id, 'default_duration_minutes' => 60,
        ], $attr));
    }

    private function templateWithPreset(ActivityPreset $p)
    {
        $comp = app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize([
            'schema_version' => 1,
            'activities' => [[
                'preset_id' => $p->id, 'label' => $p->name, 'activity_type' => $p->activity_type,
                'coverage_category_id' => $p->coverage_category_id, 'offset_minutes' => 0, 'duration_minutes' => 60,
            ]],
            'breaks' => [],
        ], '09:00', '17:00');

        return app(CreateScheduleTemplateUseCase::class)->execute(
            companyId: $this->company->id, name: 'TPL '.uniqid(), startTime: '09:00', endTime: '17:00', composition: $comp,
        );
    }

    private function apply($tpl, int $empId): \App\Core\Workforce\Shift
    {
        return app(CreateShiftUseCase::class)->execute(
            companyId: $this->company->id, employeeId: $empId, date: '2026-11-03',
            startAt: '2026-11-03 09:00:00', endAt: '2026-11-03 17:00:00', timezone: 'Europe/Paris',
            scheduleTemplateId: $tpl->id, materializeComposition: true,
        );
    }

    // --- 6. Copie dans une composition + matérialisation (valeurs copiées, provenance conservée) ---
    public function test_preset_copied_into_composition_and_materialized(): void
    {
        $p = $this->presetModel();
        $tpl = $this->templateWithPreset($p);

        // Provenance conservée dans le snapshot ; valeurs métier copiées.
        $snapAct = $tpl->toSnapshot()['activities'][0];
        $this->assertSame($p->id, $snapAct['preset_id']);
        $this->assertSame('prep', $snapAct['activity_type']);
        $this->assertSame($this->route->id, $snapAct['coverage_category_id']);

        $shift = $this->apply($tpl, $this->emp()->id);
        $act = ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->first();
        $this->assertNotNull($act);
        $this->assertSame('prep', $act->activity_type);
        $this->assertSame($this->route->id, $act->coverage_category_id);
        $this->assertSame('template', $act->source);
    }

    // --- 7. Modifier le preset n'a AUCUN effet rétroactif ---
    public function test_modifying_preset_has_no_retroactive_effect(): void
    {
        $p = $this->presetModel();
        $tpl = $this->templateWithPreset($p);
        $shift = $this->apply($tpl, $this->emp()->id);
        $snapBefore = $tpl->fresh()->template_snapshot ?? $tpl->toSnapshot();

        // Changer radicalement le preset source.
        $p->update(['name' => 'TOTALEMENT AUTRE', 'activity_type' => 'CHANGED', 'coverage_category_id' => null]);

        // Composition du modèle inchangée.
        $this->assertSame('prep', $tpl->fresh()->toSnapshot()['activities'][0]['activity_type']);
        // Snapshot du shift inchangé.
        $this->assertSame('prep', $shift->fresh()->template_snapshot['activities'][0]['activity_type']);
        // ScheduledActivity inchangée.
        $act = ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->first();
        $this->assertSame('prep', $act->activity_type);
        $this->assertSame($this->route->id, $act->coverage_category_id);
    }

    // --- 8. Désactiver / supprimer le preset ne casse pas l'historique ---
    public function test_disable_or_delete_preset_keeps_history(): void
    {
        $p = $this->presetModel();
        $tpl = $this->templateWithPreset($p);
        $shift = $this->apply($tpl, $this->emp()->id);

        $p->update(['enabled' => false]);
        $this->assertSame(1, ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->count());
        $this->assertCount(1, $tpl->fresh()->toSnapshot()['activities']);

        $p->delete(); // suppression sûre (copie par valeur, pas de FK)
        $this->assertSame(1, ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->count(), 'Instance intacte après suppression du preset.');
        $this->assertCount(1, $tpl->fresh()->toSnapshot()['activities'], 'Composition intacte après suppression du preset.');
        // La provenance orpheline reste inoffensive.
        $this->assertSame($p->id, $tpl->fresh()->toSnapshot()['activities'][0]['preset_id']);
    }

    // --- 9. TimeEntry inchangé (prévisionnel ≠ pointage) ---
    public function test_timeentry_untouched(): void
    {
        $p = $this->presetModel();
        $tpl = $this->templateWithPreset($p);
        $emp = $this->emp();
        $this->apply($tpl, $emp->id);
        $this->assertSame(0, \App\Core\Workforce\TimeEntry::withoutGlobalScopes()->where('employee_id', $emp->id)->count());
    }

    // --- 10. Compat M1 : composition SANS preset reste valide (preset_id null) ---
    public function test_m1_compat_composition_without_preset(): void
    {
        $comp = app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize([
            'schema_version' => 1,
            'activities' => [['label' => 'Libre', 'offset_minutes' => 0, 'duration_minutes' => 60]],
            'breaks' => [],
        ], '09:00', '17:00');
        $this->assertNull($comp['activities'][0]['preset_id']);
    }
}
