<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldRuleMigrationPlanner;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-860 (Leezr 5.0 L7) — a field required by an active module is required from EVERYONE ; the function-based scope
 * (role tag, explicit position requirement) is simulated read only : who would stay required, who would be released.
 */
class Adr860ModuleWideObligationTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulation_separates_the_function_from_everyone_and_changes_nothing(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $company = Company::create(['name' => 'Fleet Co', 'slug' => 'fleet-'.uniqid(), 'jobdomain_key' => 'logistique']);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'logistics_fleet', 'is_enabled_for_company' => true]);
        $def = FieldDefinition::firstOrCreate(['code' => 'license_l7', 'company_id' => null], ['label' => 'Permis', 'scope' => FieldDefinition::SCOPE_COMPANY_USER, 'type' => 'string',
            'validation_rules' => ['required_by_modules' => ['logistics_fleet'], 'tags' => ['driving']]]);
        FieldActivation::create(['company_id' => $company->id, 'field_definition_id' => $def->id, 'enabled' => true, 'required_override' => false, 'order' => 1]);
        $driverRole = CompanyRole::create(['company_id' => $company->id, 'key' => 'driver', 'name' => 'Driver', 'is_administrative' => false, 'required_tags' => ['driving']]);
        $officeRole = CompanyRole::create(['company_id' => $company->id, 'key' => 'office', 'name' => 'Office', 'is_administrative' => false]);
        foreach ([[$driverRole, 'driver'], [$officeRole, 'office'], [$officeRole, 'office2']] as [$role, $name]) {
            $u = User::factory()->create();
            $company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);
            Employee::create(['company_id' => $company->id, 'user_id' => $u->id, 'employee_number' => uniqid(), 'first_name' => $name, 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01']);
        }
        Employee::create(['company_id' => $company->id, 'employee_number' => uniqid(), 'first_name' => 'Sans', 'last_name' => 'Compte', 'status' => 'active', 'hire_date' => '2026-01-01']);
        $rules = $def->fresh()->validation_rules;

        $row = collect(FieldRuleMigrationPlanner::moduleWideObligations($company))->firstWhere('code', 'license_l7');
        $this->assertSame(4, $row['everyone']);     // module-wide today : everyone
        $this->assertSame(1, $row['by_function']);  // the driving role only
        $this->assertSame(3, $row['released']);
        $this->assertSame($rules, $def->fresh()->validation_rules, 'simulation only : the catalogue is untouched');
    }
}
