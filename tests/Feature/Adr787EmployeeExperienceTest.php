<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmployeeNumbering;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\ReadModels\PersonalHoursReadModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-787 — Employee Experience 2.0 : help center filtered by real access, my hours, contract lock of
 * the personal profile, directory vs HR record, automatic employee numbers, targeted role correction.
 */
class Adr787EmployeeExperienceTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const MODULES = ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave'];

    /** Driver role as found in DEV (legacy : preset + members.invite). */
    private const DRIVER = ['documents.view', 'members.invite', 'members.view', 'settings.view', 'shipments.manage_status', 'shipments.view_own', 'theme.manage', 'theme.view'];

    private Company $a;
    private Company $b;
    private CompanyRole $admin;
    private User $owner;
    private User $driver;
    private Employee $driverEmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\Market::firstOrCreate(['key' => 'FR'], ['name' => 'France', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'currency' => 'EUR', 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '', 'vat_rate_bps' => 2000]);
        Notification::fake();
        \App\Core\Fields\FieldDefinitionCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => self::MODULES]);

        $this->a = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => 'FR']);
        $this->b = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => 'FR']);
        foreach ([$this->a, $this->b] as $c) {
            $this->activateCompanyModules($c);
        }
        foreach (self::MODULES as $m) { // workforce only in A : B is a company without Workforce
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $this->a->id, 'module_key' => $m], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->admin = $this->setUpCompanyRbac($this->a);
        $this->owner = User::factory()->create();
        Membership::create(['user_id' => $this->owner->id, 'company_id' => $this->a->id, 'role' => 'owner', 'company_role_id' => $this->admin->id]);
        $this->driver = $this->member($this->a, $this->role($this->a, 'driver', self::DRIVER));
        // company-user fields active in A (as a jobdomain assignment would do)
        foreach (FieldDefinition::whereNull('company_id')->where('scope', FieldDefinition::SCOPE_COMPANY_USER)->get() as $i => $def) {
            \App\Core\Fields\FieldActivation::firstOrCreate(['company_id' => $this->a->id, 'field_definition_id' => $def->id], ['enabled' => true, 'required_override' => false, 'order' => $i]);
        }
        $this->driverEmp = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'user_id' => $this->driver->id, 'first_name' => 'Bobo', 'last_name' => 'D', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'EMP-000001']);
    }

    private function role(Company $c, string $key, array $perms, bool $admin = false): CompanyRole
    {
        $r = CompanyRole::create(['company_id' => $c->id, 'key' => $key.'-'.uniqid(), 'name' => $key, 'is_administrative' => $admin]);
        $r->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());

        return $r;
    }

    private function member(Company $c, ?CompanyRole $role): User
    {
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role?->id]);

        return $u;
    }

    private function as(User $u, string $method, string $uri, array $body = [], ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id])->json($method, '/api/'.$uri, $body);
    }

    private function seedHelp(): void
    {
        $this->seed(\Database\Seeders\HelpCenterSeeder::class);
    }

    private function topics($res): array
    {
        return collect($res->json('groups'))->flatMap(fn ($g) => array_column($g['published_topics'], 'slug'))->all();
    }

    // ── §2 Centre d'aide ──────────────────────────────────────────────────────

    public function test_driver_sees_employee_help_but_no_administration(): void
    {
        $this->seedHelp();
        $res = $this->as($this->driver, 'GET', 'help-center')->assertOk();
        $topics = $this->topics($res);
        $this->assertContains('mon-espace-salarie', $topics);
        foreach (['demarrage', 'gestion-entreprise', 'membres', 'modules', 'facturation', 'support', 'tarification'] as $admin) {
            $this->assertNotContains($admin, $topics, $admin);
        }
        $this->assertFalse($res->json('can_contact_support'));
        $this->assertStringNotContainsString('required_permissions', $res->getContent());
    }

    public function test_administration_articles_refused_by_direct_url_search_and_feedback(): void
    {
        $this->seedHelp();
        $this->as($this->driver, 'GET', 'help-center/topic/facturation')->assertNotFound();
        $this->as($this->driver, 'GET', 'help-center/article/membres/inviter-nouveau-membre')->assertNotFound();
        $this->as($this->driver, 'GET', 'help-center/article/faq/comment-annuler-mon-abonnement')->assertNotFound();
        $titles = array_column($this->as($this->driver, 'GET', 'help-center/search?q=facture')->assertOk()->json('results'), 'slug');
        $this->assertSame([], array_values(array_intersect($titles, ['comprendre-votre-facture', 'consulter-historique-de-facturation'])));
        $article = \App\Core\Documentation\DocumentationArticle::where('slug', 'inviter-nouveau-membre')->firstOrFail();
        $this->as($this->driver, 'POST', "help-center/article/{$article->id}/feedback", ['helpful' => true])->assertNotFound();
    }

    public function test_owner_sees_administration_and_support(): void
    {
        $this->seedHelp();
        $res = $this->as($this->owner, 'GET', 'help-center')->assertOk();
        $this->assertContains('facturation', $this->topics($res));
        $this->assertContains('membres', $this->topics($res));
        $this->as($this->owner, 'GET', 'help-center/article/membres/inviter-nouveau-membre')->assertOk();
    }

    public function test_company_context_is_verified_not_trusted(): void
    {
        $this->seedHelp();
        // Owner of A sending the id of B (not a member) : no restricted content.
        $res = $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->b->id])->getJson('/api/help-center')->assertOk();
        $this->assertNotContains('facturation', $this->topics($res));
    }

    public function test_employee_articles_follow_active_modules(): void
    {
        $this->seedHelp();
        $userB = $this->member($this->b, $this->role($this->b, 'plain', []));
        $this->as($userB, 'GET', 'help-center/article/mon-espace-salarie/pointer-mes-heures', [], $this->b)->assertNotFound();
        $this->as($userB, 'GET', 'help-center/article/mon-espace-salarie/mon-compte-et-mes-coordonnees', [], $this->b)->assertOk();
        $this->as($this->driver, 'GET', 'help-center/article/mon-espace-salarie/pointer-mes-heures')->assertOk();
    }

    public function test_public_visitor_keeps_public_content(): void
    {
        $this->seedHelp();
        $res = $this->getJson('/api/help-center')->assertOk();
        $this->assertContains('tarification', $this->topics($res), 'rules apply to company users only');
        $this->assertNotContains('membres', $this->topics($res));
    }

    // ── §3 Mes heures ─────────────────────────────────────────────────────────

    private function entry(Employee $e, string $date, int $worked, string $status = 'completed'): void
    {
        TimeEntry::withoutGlobalScopes()->create(['company_id' => $e->company_id, 'employee_id' => $e->id, 'date' => $date,
            'clock_in' => "{$date} 08:00:00", 'clock_out' => $status === 'completed' ? "{$date} 17:00:00" : null,
            'status' => $status, 'total_worked_minutes' => $status === 'completed' ? $worked : null, 'total_break_minutes' => 60, 'source' => 'manual']);
    }

    public function test_week_crossing_two_months_and_month_fallback(): void
    {
        $colleague = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'C', 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'EMP-000002']);
        $this->entry($this->driverEmp, '2026-06-29', 420); // lundi (juin)
        $this->entry($this->driverEmp, '2026-06-30', 400);
        $this->entry($this->driverEmp, '2026-07-01', 300); // mercredi (juillet)
        $this->entry($this->driverEmp, '2026-07-06', 480); // semaine suivante
        $this->entry($this->driverEmp, '2026-07-01', 0, 'working'); // en cours : jamais compté
        $this->entry($colleague, '2026-07-01', 999);        // autre salarié : jamais compté

        $h = PersonalHoursReadModel::forEmployee($this->a, $this->driverEmp, CarbonImmutable::parse('2026-07-01 10:00', 'Europe/Paris'));
        $this->assertSame(['2026-06-29', '2026-07-05'], [$h['week']['from'], $h['week']['to']]);
        $this->assertSame(1120, $h['week']['worked_minutes']);
        $this->assertSame('month', $h['period']['kind']);
        $this->assertSame(['2026-07-01', '2026-07-31'], [$h['period']['from'], $h['period']['to']]);
        $this->assertSame(780, $h['period']['worked_minutes']);
        $this->assertNotNull($h['in_progress']);
    }

    public function test_week_start_and_timezone_follow_the_company(): void
    {
        $this->a->forceFill(['week_start_day' => 0])->saveQuietly(); // dimanche
        // 2026-07-04 23:30 UTC = dimanche 5 juillet 01:30 à Paris
        $h = PersonalHoursReadModel::forEmployee($this->a->fresh(), $this->driverEmp, CarbonImmutable::parse('2026-07-04 23:30', 'UTC'));
        $this->assertSame('2026-07-05', $h['week']['from']);
        $this->assertSame('2026-07-11', $h['week']['to']);
    }

    public function test_payroll_period_and_validation_states(): void
    {
        Model::unguard();
        PayrollRun::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'period_start' => '2026-06-21', 'period_end' => '2026-07-20', 'status' => 'draft', 'currency' => 'EUR']);
        $this->entry($this->driverEmp, '2026-06-25', 420);
        $this->entry($this->driverEmp, '2026-07-02', 300);
        foreach ([['2026-06-22', '2026-06-28', 'approved', '2026-06-25', 420], ['2026-06-29', '2026-07-05', 'submitted', '2026-07-02', 300]] as [$from, $to, $status, $day, $min]) {
            $p = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'employee_id' => $this->driverEmp->id, 'period_start' => $from, 'period_end' => $to, 'status' => $status, 'total_worked_minutes' => $min]);
            TimesheetLine::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'timesheet_period_id' => $p->id, 'employee_id' => $this->driverEmp->id, 'date' => $day, 'worked_minutes' => $min, 'break_minutes' => 0, 'source_snapshot' => []]);
        }
        Model::reguard();

        $h = PersonalHoursReadModel::forEmployee($this->a, $this->driverEmp, CarbonImmutable::parse('2026-07-03 12:00', 'Europe/Paris'));
        $this->assertSame('payroll', $h['period']['kind']);
        $this->assertSame(['2026-06-21', '2026-07-20'], [$h['period']['from'], $h['period']['to']]);
        $this->assertSame(720, $h['period']['worked_minutes']);
        $this->assertSame(300, $h['period']['pending_minutes']);
        $this->assertSame(420, $h['period']['validated_minutes']);
    }

    public function test_hours_endpoint_is_strictly_personal(): void
    {
        $this->as($this->driver, 'GET', 'workforce/me/hours')->assertOk()->assertJsonStructure(['week' => ['worked_minutes'], 'period' => ['kind', 'pending_minutes', 'validated_minutes']]);
        $this->as($this->driver, 'GET', 'workforce/me/hours?employee_id=999')->assertOk(); // no parameter can target someone else
        $this->as($this->owner, 'GET', 'workforce/me/hours')->assertNotFound(); // no employee record : no value
        $userB = $this->member($this->b, $this->role($this->b, 'plain', []));
        $this->as($userB, 'GET', 'workforce/me/hours', [], $this->b)->assertForbidden(); // company without Workforce
        $this->as($this->driver, 'GET', 'workforce/me/hours', [], $this->b)->assertForbidden(); // not a member of B
    }

    // ── §4 Verrouillage contractuel ───────────────────────────────────────────

    private function contract(string $status, ?Employee $e = null): void
    {
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => ($e ?? $this->driverEmp)->company_id, 'employee_id' => ($e ?? $this->driverEmp)->id,
            'contract_type' => 'cdi', 'work_model_key' => 'hourly', 'start_date' => '2026-01-01', 'status' => $status, 'is_current' => true]);
    }

    private function saveProfile(User $u, array $dynamic = [], ?string $first = null, ?Company $c = null)
    {
        return $this->as($u, 'PUT', 'profile', ['first_name' => $first ?? $u->first_name, 'last_name' => $u->last_name, 'dynamic_fields' => $dynamic], $c);
    }

    private function value(User $u, string $code): ?string
    {
        $def = FieldDefinition::whereNull('company_id')->where('code', $code)->firstOrFail();

        return FieldValue::where('field_definition_id', $def->id)->where('model_type', 'user')->where('model_id', $u->id)->value('value');
    }

    public function test_without_contract_or_with_draft_or_cancelled_the_profile_stays_editable(): void
    {
        $this->saveProfile($this->driver, [], 'Robert')->assertOk();
        $this->contract(EmploymentContract::STATUS_DRAFT);
        $this->saveProfile($this->driver, [], 'Roberto')->assertOk();
        EmploymentContract::withoutGlobalScopes()->update(['status' => EmploymentContract::STATUS_CANCELLED]);
        $this->assertFalse($this->as($this->driver, 'GET', 'profile')->assertOk()->json('profile_lock.locked'));
        $this->saveProfile($this->driver, [], 'Rob')->assertOk();
    }

    public function test_established_contract_locks_identity_but_not_the_address(): void
    {
        $this->contract(EmploymentContract::STATUS_ACTIVE);
        $lock = $this->as($this->driver, 'GET', 'profile')->assertOk()->json('profile_lock');
        $this->assertTrue($lock['locked']);
        $this->assertContains('iban', $lock['fields']);
        $this->assertNotContains('personal_address_city', $lock['fields']);

        $before = $this->driver->first_name;
        $this->saveProfile($this->driver, [], 'Hacked')->assertStatus(422)->assertJsonValidationErrors('first_name');
        $this->assertSame($before, $this->driver->fresh()->first_name);
        $this->saveProfile($this->driver, ['birth_date' => '1990-01-01'])->assertStatus(422)->assertJsonValidationErrors('dynamic_fields.birth_date');
        $this->assertNull($this->value($this->driver, 'birth_date'));

        $this->saveProfile($this->driver, ['personal_address_city' => 'Lyon'])->assertOk();
        $this->assertSame('Lyon', $this->value($this->driver, 'personal_address_city'));
        $log = \App\Core\Audit\CompanyAuditLog::where('company_id', $this->a->id)->where('action', \App\Core\Audit\AuditAction::USER_PROFILE_UPDATED)->latest('id')->first();
        $this->assertContains('personal_address_city', $log->metadata['fields_changed'] ?? []);
    }

    public function test_terminated_contract_still_locks_and_resending_values_is_fine(): void
    {
        $this->contract(EmploymentContract::STATUS_TERMINATED);
        $this->saveProfile($this->driver, [])->assertOk(); // same name re-sent
        $this->saveProfile($this->driver, [], 'Other')->assertStatus(422);
    }

    public function test_lock_is_shared_across_companies_because_the_values_are(): void
    {
        $this->contract(EmploymentContract::STATUS_ACTIVE);
        Membership::create(['user_id' => $this->driver->id, 'company_id' => $this->b->id, 'role' => 'user', 'company_role_id' => $this->role($this->b, 'plain', [])->id]);
        $this->saveProfile($this->driver, [], 'FromB', $this->b)->assertStatus(422);
    }

    public function test_hr_keeps_editing_the_member_profile(): void
    {
        $this->contract(EmploymentContract::STATUS_ACTIVE);
        $hr = $this->member($this->a, $this->role($this->a, 'hr', ['members.view', 'members.manage'], true));
        $m = Membership::where('user_id', $this->driver->id)->where('company_id', $this->a->id)->firstOrFail();
        $this->as($hr, 'PUT', "company/members/{$m->id}", ['first_name' => 'Corrected', 'last_name' => 'D'])->assertOk();
        $this->assertSame('Corrected', $this->driver->fresh()->first_name);
    }

    // ── §9 Annuaire ≠ dossier RH ──────────────────────────────────────────────

    public function test_directory_reader_never_gets_colleague_private_or_hr_fields(): void
    {
        $colleague = $this->member($this->a, $this->admin);
        foreach (['birth_date' => '1985-05-05', 'personal_address_city' => 'Marseille', 'emergency_contact_name' => 'Maman', 'job_title' => 'Chef', 'phone' => '+33600000000'] as $code => $v) {
            $def = FieldDefinition::whereNull('company_id')->where('code', $code)->firstOrFail();
            FieldValue::create(['field_definition_id' => $def->id, 'model_type' => 'user', 'model_id' => $colleague->id, 'value' => $v]);
        }
        $m = Membership::where('user_id', $colleague->id)->firstOrFail();
        $body = $this->as($this->driver, 'GET', "company/members/{$m->id}")->assertOk()->getContent()
            .$this->as($this->driver, 'GET', "company/members/{$m->id}/fields")->assertOk()->getContent();
        foreach (['1985-05-05', 'Marseille', 'Maman', 'birth_date', 'emergency_contact_name'] as $private) {
            $this->assertStringNotContainsString($private, $body, $private);
        }
        $codes = array_column($this->as($this->driver, 'GET', "company/members/{$m->id}/fields")->json('dynamic_fields'), 'code');
        $this->assertEqualsCanonicalizing(array_intersect(['job_title', 'phone'], $codes), $codes);

        $manager = $this->member($this->a, $this->role($this->a, 'mm', ['members.view', 'members.manage'], true));
        $this->assertStringContainsString('Marseille', $this->as($manager, 'GET', "company/members/{$m->id}")->assertOk()->getContent());
    }

    // ── §5 Matricules ─────────────────────────────────────────────────────────

    private function createEmployee(array $attrs = [])
    {
        return $this->as($this->owner, 'POST', 'workforce/employees', array_merge(['first_name' => 'N', 'last_name' => 'E', 'hire_date' => '2026-02-01'], $attrs));
    }

    public function test_numbers_are_generated_after_existing_ones_and_never_reused(): void
    {
        Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'X', 'last_name' => 'Y', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'EMP-000005']);
        $n1 = $this->createEmployee()->assertCreated()->json('employee_number') ?? $this->createdNumber();
        $this->assertSame('EMP-000006', $n1);
        $second = EmployeeNumbering::next($this->a->id);
        $this->assertSame('EMP-000007', $second);
        Employee::withoutGlobalScopes()->where('employee_number', 'EMP-000006')->delete();
        $this->assertSame('EMP-000008', EmployeeNumbering::next($this->a->id), 'a freed number is never reused');
        $this->assertSame('EMP-000001', EmployeeNumbering::next($this->b->id), 'independent per company');
    }

    private function createdNumber(): ?string
    {
        return Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->latest('id')->value('employee_number');
    }

    public function test_manual_number_is_kept_and_skipped_by_the_sequence(): void
    {
        $this->createEmployee(['employee_number' => 'EMP-000002'])->assertCreated();
        $this->assertNotNull(Employee::withoutGlobalScopes()->where('employee_number', 'EMP-000002')->first());
        // EMP-000001 (driver) and EMP-000002 exist → next free
        $this->assertSame('EMP-000003', EmployeeNumbering::next($this->a->id));
    }

    public function test_hr_changes_the_number_uniquely_and_with_history(): void
    {
        $other = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'O', 'last_name' => 'T', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'EMP-000009']);
        $this->as($this->owner, 'PUT', "workforce/employees/{$other->id}", ['employee_number' => 'EMP-000001'])->assertStatus(422)->assertJsonValidationErrors('employee_number');
        $this->as($this->owner, 'PUT', "workforce/employees/{$other->id}", ['employee_number' => 'R-42'])->assertOk();
        $this->assertSame('R-42', $other->fresh()->employee_number);
        $this->assertTrue(\App\Core\Audit\CompanyAuditLog::where('action', 'employee.number_changed')->where('target_id', (string) $other->id)->exists());
        // the employee himself has no route to change it
        $this->as($this->driver, 'PUT', "workforce/employees/{$this->driverEmp->id}", ['employee_number' => 'MINE'])->assertForbidden();
    }

    // ── §9 Correction ciblée du rôle Driver ───────────────────────────────────

    public function test_role_permission_revocation_is_simulated_unless_applied(): void
    {
        $role = CompanyRole::whereHas('permissions', fn ($q) => $q->where('key', 'members.invite'))->where('key', 'like', 'driver-%')->firstOrFail();
        $this->artisan('roles:revoke-permission', ['role_key' => $role->key, 'permission' => 'members.invite'])->assertSuccessful();
        $this->assertTrue($role->permissions()->where('key', 'members.invite')->exists(), 'simulation writes nothing');
        $this->artisan('roles:revoke-permission', ['role_key' => $role->key, 'permission' => 'members.invite', '--apply' => true])->assertSuccessful();
        $this->assertFalse($role->permissions()->where('key', 'members.invite')->exists());
        $this->assertTrue($role->permissions()->where('key', 'members.view')->exists(), 'only the named permission is removed');
    }
}
