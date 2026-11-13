<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-770 (M7 LOT 4) — an E2E run may only delete what IT created. Regression for the incident
 * where a cleanup by shared prefix removed accounts left by earlier sessions.
 */
class M7Lot4E2eTeardownOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $this->company = Company::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
    }

    private function ledger(string $runId, array $records, ?string $startedAt = null, ?int $companyId = null): string
    {
        $file = sys_get_temp_dir().'/ledger-'.uniqid().'.json';
        file_put_contents($file, json_encode(['run_id' => $runId, 'company_id' => $companyId ?? $this->company->id, 'started_at' => $startedAt ?? now()->subMinute()->toIso8601String(), 'records' => $records]));

        return $file;
    }

    private function user(string $email): User
    {
        return User::factory()->create(['email' => $email]);
    }

    private function member(User $u): Membership
    {
        return Membership::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'user_id' => $u->id, 'role' => 'user']);
    }

    private function employee(string $lastName): Employee
    {
        return Employee::create(['company_id' => $this->company->id, 'employee_number' => 'E'.uniqid(), 'first_name' => 'L', 'last_name' => $lastName, 'status' => 'active', 'hire_date' => '2026-01-01']);
    }

    public function test_only_ledger_rows_carrying_the_run_marker_are_deleted(): void
    {
        $run = 'rabc123def456';
        $mine = $this->user("e2e.people.{$run}@leezr.test");
        $mineMembership = $this->member($mine);
        $myEmployee = $this->employee("Sanscompte{$run}");

        // Data of ANOTHER session : same historical prefix, not in the ledger.
        $older = $this->user('e2e.people.8281596@leezr.test');
        $olderMembership = $this->member($older);
        $otherEmployee = $this->employee('Sanscompte8307319');

        // Rows the ledger names WITHOUT proof of ownership : a real account, and another run's account.
        $real = $this->user('owner.real@leezr.test');
        $otherRun = $this->user('e2e.people.rzzz999yyy888@leezr.test');

        $file = $this->ledger($run, [
            ['type' => 'user', 'id' => $mine->id], ['type' => 'membership', 'id' => $mineMembership->id], ['type' => 'employee', 'id' => $myEmployee->id],
            ['type' => 'user', 'id' => $real->id], ['type' => 'user', 'id' => $otherRun->id], ['type' => 'employee', 'id' => $otherEmployee->id],
            ['type' => 'membership', 'id' => $olderMembership->id], ['type' => 'user', 'id' => 999999],
        ]);

        $this->artisan('e2e:teardown-run', ['ledger' => $file])->assertExitCode(0);

        $this->assertNull(User::find($mine->id));
        $this->assertNull(Membership::withoutGlobalScopes()->find($mineMembership->id));
        $this->assertNull(Employee::withoutGlobalScopes()->find($myEmployee->id));

        foreach ([$older, $real, $otherRun] as $kept) {
            $this->assertNotNull(User::find($kept->id), "{$kept->email} must survive");
        }
        $this->assertNotNull(Membership::withoutGlobalScopes()->find($olderMembership->id));
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($otherEmployee->id));
    }

    public function test_rows_older_than_the_run_or_still_attached_elsewhere_are_kept(): void
    {
        $run = 'rold000old0001';
        $before = $this->user("e2e.people.{$run}@leezr.test");
        $before->forceFill(['created_at' => now()->subDay()])->save();

        $attached = $this->user("e2e.member.{$run}@leezr.test");
        $this->member($attached); // membership NOT recorded by the run

        $file = $this->ledger($run, [['type' => 'user', 'id' => $before->id], ['type' => 'user', 'id' => $attached->id]]);
        $this->artisan('e2e:teardown-run', ['ledger' => $file])->assertExitCode(0);

        $this->assertNotNull(User::find($before->id), 'created before the run started');
        $this->assertNotNull(User::find($attached->id), 'still member of a company through a row the run did not record');
    }

    public function test_teardown_is_confined_to_the_company_the_run_declared(): void
    {
        $run = 'rtenant0tenant1';
        $other = Company::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'jobdomain_key' => 'logistique']);
        // A row carrying the run marker, but in ANOTHER company than the one the ledger declares.
        $elsewhere = Employee::create(['company_id' => $other->id, 'employee_number' => 'E'.uniqid(), 'first_name' => 'L', 'last_name' => "Sanscompte{$run}", 'status' => 'active', 'hire_date' => '2026-01-01']);
        $mine = $this->employee("Sanscompte{$run}");

        $this->artisan('e2e:teardown-run', ['ledger' => $this->ledger($run, [['type' => 'employee', 'id' => $elsewhere->id], ['type' => 'employee', 'id' => $mine->id]])])->assertExitCode(0);

        $this->assertNull(Employee::withoutGlobalScopes()->find($mine->id));
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($elsewhere->id), 'another company is out of reach');

        // Without a declared company, no company row is touched at all.
        $kept = $this->employee("Sanscompte{$run}");
        $this->artisan('e2e:teardown-run', ['ledger' => $this->ledger($run, [['type' => 'employee', 'id' => $kept->id]], null, 0)])->assertExitCode(0);
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($kept->id));
    }

    public function test_dry_run_deletes_nothing_and_a_malformed_ledger_or_production_is_refused(): void
    {
        $run = 'rdry111dry2222';
        $mine = $this->user("e2e.people.{$run}@leezr.test");

        $this->artisan('e2e:teardown-run', ['ledger' => $this->ledger($run, [['type' => 'user', 'id' => $mine->id]]), '--dry-run' => true])->assertExitCode(0);
        $this->assertNotNull(User::find($mine->id));

        // No ledger, or a "run id" that is only a shared prefix : refused.
        $this->artisan('e2e:teardown-run', ['ledger' => '/nonexistent.json'])->assertExitCode(1);
        $this->artisan('e2e:teardown-run', ['ledger' => $this->ledger('e2e', [['type' => 'user', 'id' => $mine->id]])])->assertExitCode(1);

        $this->app['env'] = 'production';
        $this->artisan('e2e:teardown-run', ['ledger' => $this->ledger($run, [['type' => 'user', 'id' => $mine->id]])])->assertExitCode(1);
        $this->app['env'] = 'testing';
        $this->assertNotNull(User::find($mine->id));
    }
}
