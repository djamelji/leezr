<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\Employee;
use App\Core\Workforce\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-HR-UX-4 §6/§10 — self-service "mon planning" : le salarié ne voit QUE ses propres créneaux
 * PUBLIÉS (jamais les brouillons RH, jamais ceux d'un autre collaborateur, jamais d'une autre company).
 */
class EmployeeSelfShiftsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_planning']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_planning', 'name' => 'Planning', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Shift Co', 'slug' => 'shift-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
    }

    private function employeeWithUser(string $first): array
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => false]);
        Membership::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $emp = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'first_name' => $first, 'last_name' => 'T', 'status' => Employee::STATUS_ACTIVE,
            'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ]);

        return [$user, $emp];
    }

    private function shift(Employee $emp, string $date, string $status): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $emp->id,
            'date' => $date, 'start_at' => $date.' 09:00:00', 'end_at' => $date.' 17:00:00',
            'timezone' => 'Europe/Paris', 'status' => $status,
        ]);
    }

    public function test_employee_sees_only_own_published_upcoming_shifts(): void
    {
        [$user, $emp] = $this->employeeWithUser('Thomas');
        $tomorrow = now()->addDay()->toDateString();

        $published = $this->shift($emp, $tomorrow, Shift::STATUS_PUBLISHED);
        $this->shift($emp, $tomorrow, Shift::STATUS_DRAFT);       // brouillon RH → invisible
        $this->shift($emp, $tomorrow, Shift::STATUS_CANCELLED);   // annulé → invisible

        $res = $this->actingAs($user)->getJson('/api/workforce/me/shifts', ['X-Company-Id' => $this->company->id]);
        $res->assertOk();

        $ids = collect($res->json('shifts'))->pluck('id')->all();
        $this->assertSame([$published->id], $ids, 'Seul le créneau PUBLIÉ doit être visible.');
    }

    public function test_employee_cannot_see_another_employees_shifts(): void
    {
        [$user, $me] = $this->employeeWithUser('Thomas');
        [, $other] = $this->employeeWithUser('Sophie');
        $tomorrow = now()->addDay()->toDateString();

        $mine = $this->shift($me, $tomorrow, Shift::STATUS_PUBLISHED);
        $this->shift($other, $tomorrow, Shift::STATUS_PUBLISHED); // créneau d'un AUTRE salarié

        $res = $this->actingAs($user)->getJson('/api/workforce/me/shifts', ['X-Company-Id' => $this->company->id]);
        $res->assertOk();

        $ids = collect($res->json('shifts'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids, 'Le salarié ne voit jamais le planning d\'un autre.');
    }

    public function test_past_shifts_excluded_from_window(): void
    {
        [$user, $emp] = $this->employeeWithUser('Thomas');
        $this->shift($emp, now()->subDays(3)->toDateString(), Shift::STATUS_PUBLISHED); // passé
        $future = $this->shift($emp, now()->addDays(2)->toDateString(), Shift::STATUS_PUBLISHED);

        $res = $this->actingAs($user)->getJson('/api/workforce/me/shifts', ['X-Company-Id' => $this->company->id]);
        $res->assertOk();

        $ids = collect($res->json('shifts'))->pluck('id')->all();
        $this->assertSame([$future->id], $ids);
    }
}
