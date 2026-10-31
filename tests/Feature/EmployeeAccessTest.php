<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Employees\Services\EmployeeAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * W-HR-UX-3 — accès à l'espace collaborateur depuis la fiche : invariants métier.
 */
class EmployeeAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CompanyRole $driver;
    private CompanyRole $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Notification::fake();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $this->company = Company::create(['name' => 'Acc Co', 'slug' => 'acc-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $this->driver = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'driver', 'name' => 'Driver', 'is_system' => false, 'is_administrative' => false]);
        $this->admin = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'mgr', 'name' => 'Manager', 'is_system' => false, 'is_administrative' => true]);
    }

    private function employee(string $email = 'thomas@test.invalid'): Employee
    {
        return Employee::create(['company_id' => $this->company->id, 'first_name' => 'Thomas', 'last_name' => 'Bernard', 'email' => $email, 'status' => 'active', 'hire_date' => now()]);
    }

    public function test_grant_creates_invited_account_and_links_employee(): void
    {
        $emp = $this->employee();
        $svc = app(EmployeeAccessService::class);

        $this->assertSame('none', $svc->status($this->company, $emp)['state']);

        $res = $svc->grant($this->company, $emp, 'thomas@test.invalid', null, 1);

        $this->assertSame('invited', $res['state']); // compte créé, mot de passe non défini
        $user = User::where('email', 'thomas@test.invalid')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->password);
        $this->assertSame($user->id, $emp->fresh()->user_id);           // lien Employee↔User
        // Membership non-admin créé.
        $m = Membership::where('company_id', $this->company->id)->where('user_id', $user->id)->first();
        $this->assertNotNull($m);
        $this->assertSame($this->driver->id, $m->company_role_id);       // rôle non-administratif par défaut
    }

    public function test_grant_is_idempotent(): void
    {
        $emp = $this->employee();
        $svc = app(EmployeeAccessService::class);
        $svc->grant($this->company, $emp, 'thomas@test.invalid', null, 1);
        $svc->grant($this->company, $emp->fresh(), 'thomas@test.invalid', null, 1);

        $this->assertSame(1, User::where('email', 'thomas@test.invalid')->count());
        $this->assertSame(1, Membership::where('company_id', $this->company->id)->count());
    }

    public function test_activated_account_reports_active(): void
    {
        $emp = $this->employee();
        $svc = app(EmployeeAccessService::class);
        $svc->grant($this->company, $emp, 'thomas@test.invalid', null, 1);
        $user = User::where('email', 'thomas@test.invalid')->first();
        $user->update(['password' => bcrypt('x'), 'password_set_at' => now()]);

        $this->assertSame('active', $svc->status($this->company, $emp->fresh())['state']);
    }

    public function test_cannot_assign_administrative_role(): void
    {
        $emp = $this->employee();
        $this->expectException(ValidationException::class);
        app(EmployeeAccessService::class)->grant($this->company, $emp, 'thomas@test.invalid', $this->admin->id, 1);
    }

    public function test_cannot_relink_account_of_another_employee(): void
    {
        $svc = app(EmployeeAccessService::class);
        $a = $this->employee('a@test.invalid');
        $svc->grant($this->company, $a, 'a@test.invalid', null, 1);

        // Un 2e collaborateur tente d'utiliser le MÊME e-mail/compte → refusé.
        $b = $this->employee('b@test.invalid');
        $this->expectException(ValidationException::class);
        $svc->grant($this->company, $b, 'a@test.invalid', null, 1);
    }

    public function test_resend_only_when_invited(): void
    {
        $emp = $this->employee();
        $svc = app(EmployeeAccessService::class);
        $svc->grant($this->company, $emp, 'thomas@test.invalid', null, 1);
        $svc->resendInvitation($this->company, $emp->fresh()); // OK (invité)

        $user = User::where('email', 'thomas@test.invalid')->first();
        $user->update(['password' => bcrypt('x'), 'password_set_at' => now()]);
        $this->expectException(ValidationException::class);
        $svc->resendInvitation($this->company, $emp->fresh());  // refusé (déjà activé)
    }
}
