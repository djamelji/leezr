<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\Modernization\JobdomainPresetWorkspace;
use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Presets\JobdomainPresetLink;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetApplication;
use App\Core\Workforce\ConventionCollective;
use App\Core\Workforce\ConventionCollectiveRule;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\Settings\WorkingTimeSetupService;
use App\Modules\Workforce\UseCases\CreateShiftUseCase;
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
 * ADR-799 — the JobDomain configures the TRADE (identity, modules, presets, relevant collective agreements) ;
 * the COMPANY configures how it organises working time (assistant) within the verified legal / conventional
 * framework it CONFIRMS ; the single Workforce engine (ShiftRuleValidator) controls planning. No legal value
 * invented, no existing company changed, nothing re-applied.
 */
class Adr799WorkingTimeFrameworkTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        $this->seed(\Database\Seeders\MarketRuleSetSeeder::class);
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_planning']]); // module inclus par le métier (entitlement)
        $this->admin = PlatformUser::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa-'.uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $this->admin->roles()->attach(PlatformRole::where('key', 'super_admin')->firstOrFail());
    }

    private function company(string $name, string $market = 'FR'): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => $market, 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => 'direct']);
        }
        $this->setUpCompanyRbac($c);

        return $c;
    }

    private function user(Company $c, array $perms): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function cc(string $idcc, string $name, string $market = 'FR', bool $verified = false): ConventionCollective
    {
        return ConventionCollective::create(['idcc' => $idcc, 'short_name' => $name, 'full_name' => $name, 'market_key' => $market, 'is_active' => true, 'verified_at' => $verified ? now() : null]);
    }

    private function employee(Company $c, ?array $contract = null): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => 'active', 'hire_date' => '2026-01-01']);
        $contract ??= ['weekly_hours' => 35];
        if ($contract) {
            EmploymentContract::withoutGlobalScopes()->create($contract + ['company_id' => $c->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'hourly',
                'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);
        }

        return $e;
    }

    private function shift(Company $c, Employee $e, string $date, string $start, string $end, ?string $reason = null): Shift
    {
        return app(CreateShiftUseCase::class)->execute(companyId: $c->id, employeeId: $e->id, date: $date, startAt: $start, endAt: $end,
            timezone: 'Europe/Paris', skipOverstaffing: true, derogationReason: $reason);
    }

    private function wt(Company $c): WorkingTimeSetupService
    {
        return new WorkingTimeSetupService($c);
    }

    // 1, 6, 15, 17 — même métier, organisations différentes (4j/7, 5j/7) ; contrat individuel différent ; isolation ;
    // aucune valeur légale inventée ; le JobDomain n'est plus une couche de règles.
    public function test_two_companies_same_jobdomain_organise_differently(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $this->wt($a)->save(['work_days_per_week' => 4, 'fixed_work_days' => [1, 2, 3, 4], 'shift_target_hours' => 9], null);
        $this->wt($b)->save(['work_days_per_week' => 5], null);
        $r = app(WorkforceRuleResolver::class);
        $this->assertSame([4, 5], [$r->resolveOrganizationRules($a)['work_days_per_week']['value'], $r->resolveOrganizationRules($b)['work_days_per_week']['value']]);
        $this->assertSame([1, 2, 3, 4], $r->resolveOrganizationRules($a)['fixed_work_days']['value']);

        $this->employee($a, ['weekly_hours' => 28]);
        $d = $this->wt($a)->describe();
        $this->assertSame(1, $d['contracts']['different_weekly_hours'], 'le contrat individuel prévaut pour son titulaire');
        $rhythm = collect($d['sections']['rhythm'])->keyBy('key');
        $this->assertSame('configured', $rhythm['work_days_per_week']['status'], 'enregistré, non présenté comme contrôlé');
        $this->assertNull(collect($d['sections']['rest'])->firstWhere('key', 'max_consecutive_work_days')['legal'], 'aucune valeur légale inventée');
        foreach ($r->resolveWorkTimeRules($a) as $rule) {
            $this->assertNotContains('jobdomain', array_column($rule['chain'], 'layer'), 'le JobDomain ne fixe aucune règle de travail');
        }
        $this->expectException(ValidationException::class);
        $this->wt($a)->save(['work_days_per_week' => 9], null);
    }

    // 2, 3, 4, 5 — même métier sur deux marchés ; deux conventions pertinentes ; suggestion non confirmée sans effet ;
    // confirmation par la société → la convention est lue par le moteur.
    public function test_convention_suggested_then_confirmed(): void
    {
        $transport = $this->cc('0016', 'Transports routiers', 'FR', true);
        $other = $this->cc('1501', 'Restauration rapide', 'FR');
        $uk = $this->cc('UK-0001', 'UK agreement', 'GB');
        ConventionCollectiveRule::create(['convention_collective_id' => $transport->id, 'domain' => 'workforce', 'rule_type' => 'working_time', 'rule_key' => 'daily_rest_min_hours',
            'value' => ['amount' => 12], 'effective_from' => '2020-01-01', 'override_mode' => 'minimum', 'source' => 'convention', 'reference' => 'Fixture de test (non juridique)', 'verified_at' => now()]);
        $this->actingAs($this->admin, 'platform')->putJson('/api/platform/jobdomains/'.Jobdomain::where('key', 'logistique')->value('id'), ['convention_refs' => [
            ['market_key' => 'FR', 'convention_collective_id' => $transport->id], ['market_key' => 'FR', 'convention_collective_id' => $other->id], ['market_key' => 'GB', 'convention_collective_id' => $uk->id]]])->assertOk();
        $this->actingAs($this->admin, 'platform')->putJson('/api/platform/jobdomains/'.Jobdomain::where('key', 'logistique')->value('id'), ['convention_refs' => [
            ['market_key' => 'FR', 'convention_collective_id' => $uk->id]]])->assertStatus(422); // convention d'un autre marché

        $fr = $this->company('FR');
        $gb = $this->company('GB', 'GB');
        $this->assertEqualsCanonicalizing([$transport->id, $other->id], array_column($this->wt($fr)->framework()['suggested'], 'id'));
        $this->assertSame([$uk->id], array_column($this->wt($gb)->framework()['suggested'], 'id'));
        $this->assertSame('unavailable', $this->wt($gb)->framework()['legal_check'], 'marché sans règle légale vérifiée : contrôle indisponible');

        $r = app(WorkforceRuleResolver::class);
        $this->assertFalse($this->wt($fr)->framework()['confirmed']);
        $this->assertSame(11.0, (float) $r->resolveWorkTimeRules($fr)['daily_rest_min_hours']['value'], 'suggestion non confirmée : sans effet');
        $this->assertNull($fr->fresh()->default_convention_collective_id, 'aucun lien juridique déduit du métier');

        $manager = $this->user($fr, ['workforce.view', 'workforce.manage']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($manager, 'web')->withHeaders(['X-Company-Id' => $fr->id])->postJson('/api/workforce/working-time-setup/convention', ['convention_collective_id' => $transport->id])
            ->assertOk()->assertJsonPath('confirmed', true);
        $this->assertSame(12.0, (float) $r->resolveWorkTimeRules($fr->fresh())['daily_rest_min_hours']['value'], 'convention confirmée : appliquée par le moteur');
        $this->actingAs($manager, 'web')->withHeaders(['X-Company-Id' => $fr->id])->postJson('/api/workforce/working-time-setup/convention', ['convention_collective_id' => $uk->id])->assertNotFound();
        $this->assertTrue(DB::table('company_audit_logs')->where('action', 'workforce.convention_confirmed')->exists());
    }

    // 7, 8, 9, 10, 11, 12, 13 — repos compatible / incompatible ; dérogation motivée ; dérogation refusée ; nuit ;
    // shift à cheval sur minuit ; jours consécutifs à cheval sur deux semaines — un seul moteur.
    public function test_rules_are_controlled_by_the_single_engine(): void
    {
        $c = $this->company('C');
        $s = $this->wt($c);
        $s->save(['daily_rest_min_hours' => 12], null); // renforcement : accepté
        try {
            $s->save(['daily_rest_min_hours' => 9], null);
            $this->fail('moins favorable que le minimum légal vérifié');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('daily_rest_min_hours', $e->errors());
        }
        $this->assertSame(12.0, (float) app(WorkforceRuleResolver::class)->resolveWorkTimeRules($c)['daily_rest_min_hours']['value'], 'refus : rien d’enregistré');

        $s->save(['night_work_start' => '21:00', 'night_work_end' => '06:00', 'max_consecutive_work_days' => 4, 'consecutive_days_behavior' => 'warn'], null);
        $e = $this->employee($c);
        // Nuit + minuit : rattaché à sa date de début, compté une fois.
        $night = $this->shift($c, $e, '2026-12-03', '2026-12-03 22:00:00', '2026-12-04 05:00:00');
        $this->assertTrue($night->metadata['night_work']);
        // Repos légal (12 h renforcé) : reprise à 15 h le 4 → 10 h de repos → refusé, même avec un motif.
        try {
            $this->shift($c, $e, '2026-12-04', '2026-12-04 15:00:00', '2026-12-04 20:00:00', 'urgence');
            $this->fail('une règle légale ne se dérogue pas');
        } catch (\DomainException) {
        }
        // Jours consécutifs à cheval sur deux semaines : jeu. 3 → lun. 7 = 5 jours > 4 → avertissement.
        foreach (['2026-12-04', '2026-12-05', '2026-12-06'] as $d) {
            $this->shift($c, $e, $d, "{$d} 18:00:00", "{$d} 22:00:00");
        }
        $monday = $this->shift($c, $e, '2026-12-07', '2026-12-07 18:00:00', '2026-12-07 22:00:00');
        $this->assertSame(5, $monday->metadata['rule_warnings'][0]['observed']);

        // Comportement « dérogation motivée » : sans motif refusé, avec motif accepté et tracé.
        $s->save(['consecutive_days_behavior' => 'approve'], null);
        try {
            $this->shift($c, $e, '2026-12-08', '2026-12-08 18:00:00', '2026-12-08 22:00:00');
            $this->fail('motif requis');
        } catch (\DomainException) {
        }
        $ok = $this->shift($c, $e, '2026-12-08', '2026-12-08 18:00:00', '2026-12-08 22:00:00', 'Inventaire annuel');
        $this->assertSame('Inventaire annuel', $ok->metadata['rule_derogation']['reason']);
        $this->assertTrue(DB::table('company_audit_logs')->where('action', 'planning.rule_derogation')->exists());
        $s->save(['consecutive_days_behavior' => 'block'], null);
        $this->expectException(\DomainException::class);
        $this->shift($c, $e, '2026-12-09', '2026-12-09 18:00:00', '2026-12-09 22:00:00', 'motif ignoré : bloquant');
    }

    // Une société existante sans réglage : aucune nouvelle contrainte (rien d'activé silencieusement).
    public function test_existing_company_gets_no_new_constraint(): void
    {
        $c = $this->company('Existante');
        $e = $this->employee($c);
        foreach (range(1, 9) as $i) {
            $d = sprintf('2026-12-%02d', $i);
            $shift = $this->shift($c, $e, $d, "{$d} 08:00:00", "{$d} 12:00:00");
        }
        $this->assertArrayNotHasKey('rule_warnings', $shift->metadata, '9 jours consécutifs : aucun contrôle tant que la société n’a rien réglé');
        $this->assertSame(0, DB::table('company_policies')->where('company_id', $c->id)->count());
    }

    // 14, 16, 18 — preset partagé entre domaines ; publication depuis l'onglet = association, sans réapplication ;
    // aucun droit RBAC élargi dans une société.
    public function test_jobdomain_tabs_publish_without_reapplying(): void
    {
        $other = Jobdomain::create(['key' => 'messagerie', 'label' => 'Messagerie', 'is_active' => true]);
        $logi = Jobdomain::where('key', 'logistique')->firstOrFail();
        $c = $this->company('Société');
        JobdomainGate::assignToCompany($c, 'logistique');
        $roles = CompanyRole::where('company_id', $c->id)->with('permissions:id,key')->get()->map(fn ($r) => [$r->key, $r->permissions->pluck('key')->sort()->values()])->toJson();

        $draft = JobdomainPresetWorkspace::draft($logi, 'rbac', null); // depuis l'ancienne configuration
        $this->assertSame('draft', $draft['status']);
        $state = JobdomainPresetWorkspace::publish($logi, 'rbac', $draft['id'], null);
        $this->assertSame('published', $state['published']['status']);
        $this->assertNull($state['draft']);
        $this->assertSame(0, PresetApplication::count(), 'publier n’applique rien');
        $this->assertSame($roles, CompanyRole::where('company_id', $c->id)->with('permissions:id,key')->get()->map(fn ($r) => [$r->key, $r->permissions->pluck('key')->sort()->values()])->toJson(), 'aucun droit élargi');

        // Partage : un autre domaine recommande la même famille ; l'onglet le signale.
        JobdomainPresetLink::create(['jobdomain_key' => 'messagerie', 'kind' => 'rbac', 'family_key' => $draft['family_key'], 'sort_order' => 0]);
        $this->assertSame(['messagerie'], JobdomainPresetWorkspace::state($logi)['rbac']['shared_with']);
        $next = JobdomainPresetWorkspace::draft($logi, 'rbac', null);
        $this->assertSame(2, $next['version'], 'nouvelle version, l’ancienne reste en historique');
        JobdomainPresetWorkspace::publish($logi, 'rbac', $next['id'], null);
        $this->assertSame(2, collect(\App\Core\Presets\PresetService::recommendationsFor('messagerie'))->first()['effective_version'], 'le domaine partageant suit la version publiée');
        $this->assertCount(2, JobdomainPresetWorkspace::state($logi)['rbac']['history']);
        $this->assertSame(0, PresetApplication::count());
    }

    // 19 — permissions : Platform (droit complet pour publier depuis un onglet), Company (lecture / gestion), séparation.
    public function test_permissions(): void
    {
        $logi = Jobdomain::where('key', 'logistique')->firstOrFail();
        $editor = PlatformUser::create(['first_name' => 'E', 'last_name' => 'D', 'email' => 'ed-'.uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $role = PlatformRole::create(['key' => 'ed'.uniqid(), 'name' => 'Ed']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', ['presets.view', 'presets.edit'])->pluck('id')->all());
        $editor->roles()->attach($role);
        $this->actingAs($editor, 'platform')->postJson("/api/platform/jobdomains/{$logi->id}/presets/rbac/draft")->assertForbidden();
        $this->actingAs($this->admin, 'platform')->postJson("/api/platform/jobdomains/{$logi->id}/presets/rbac/draft")->assertOk()->assertJsonPath('draft.status', 'draft');
        $this->actingAs($this->admin, 'platform')->postJson("/api/platform/jobdomains/{$logi->id}/presets/organization/draft")->assertNotFound();

        $c = $this->company('P');
        $viewer = $this->user($c, ['workforce.view']);
        $manager = $this->user($c, ['workforce.view', 'workforce.manage']);
        $this->app['auth']->forgetGuards();
        $res = $this->actingAs($viewer, 'web')->withHeaders(['X-Company-Id' => $c->id])->getJson('/api/workforce/working-time-setup');
        $this->assertSame(200, $res->status(), $res->getContent());
        $res->assertJsonStructure(['framework', 'sections', 'contracts', 'not_supported']);
        $this->actingAs($viewer, 'web')->withHeaders(['X-Company-Id' => $c->id])->putJson('/api/workforce/working-time-setup', ['values' => ['work_days_per_week' => 5]])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $res = $this->actingAs($manager, 'web')->withHeaders(['X-Company-Id' => $c->id])->putJson('/api/workforce/working-time-setup', ['values' => ['work_days_per_week' => 5]]);
        $this->assertSame(200, $res->status(), $res->getContent());
        $this->actingAs($manager, 'web')->withHeaders(['X-Company-Id' => $c->id])->putJson('/api/workforce/working-time-setup', ['values' => ['unknown_key' => 1]])->assertStatus(422);
        $this->actingAs($manager, 'web')->getJson("/api/platform/jobdomains/{$logi->id}/profile")->assertUnauthorized();
        $other = $this->company('Autre');
        $this->assertSame(0, DB::table('company_policies')->where('company_id', $other->id)->count(), 'isolation');
    }
}
