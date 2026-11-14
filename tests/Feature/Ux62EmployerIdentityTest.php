<?php

namespace Tests\Feature;

use App\Company\Fields\ReadModels\CompanyLegalIdentity;
use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-783 (UX6.2) — the employer identity of a generated contract comes from the Company PROFILE
 * (dynamic fields), legacy columns only as fallback ; nothing invented ; a contract is refused with an
 * actionable error when the employer address is missing ; an already generated document never changes.
 */
class Ux62EmployerIdentityTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const MODULES = ['workforce', 'workforce_documents', 'workforce_contract', 'workforce_signature'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => self::MODULES]);
    }

    private function company(string $slug, array $attrs = []): array
    {
        $company = Company::create(array_merge(['name' => ucfirst($slug), 'slug' => $slug, 'jobdomain_key' => 'logistique', 'plan_key' => 'pro'], $attrs));
        $this->activateCompanyModules($company);
        foreach (self::MODULES as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => $m], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $adminRole = $this->setUpCompanyRbac($company);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);
        DocumentTemplate::create([
            'company_id' => $company->id, 'code' => 'contrat-cdi', 'name' => 'Contrat CDI', 'subject_type' => 'contract',
            'body_html' => '<p>{{ company.name }} — {{ company.address }} — {{ company.city }} — {{ company.siret }}</p><p>{{ employee.full_name }}</p>',
            'variables_schema' => [], 'version' => 1, 'is_active' => true,
        ]);

        return [$company, $owner];
    }

    private function profile(Company $company, array $values): void
    {
        foreach ($values as $code => $value) {
            $def = FieldDefinition::whereNull('company_id')->where('code', $code)->firstOrFail();
            FieldValue::updateOrCreate(['field_definition_id' => $def->id, 'model_type' => 'company', 'model_id' => $company->id], ['value' => $value]);
        }
    }

    private function employeeWithContract(Company $company, User $owner): Employee
    {
        $e = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'status' => 'active',
            'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ]);
        $this->actingAs($owner)->withHeaders(['X-Company-Id' => $company->id])
            ->postJson("/api/workforce/employees/{$e->id}/contracts", ['contract_type' => 'cdi', 'work_model_key' => 'horaire', 'start_date' => '2026-02-01', 'weekly_hours' => 35])
            ->assertCreated();

        return $e;
    }

    private function generate(Company $company, User $owner, Employee $e)
    {
        return $this->actingAs($owner)->withHeaders(['X-Company-Id' => $company->id])
            ->postJson('/api/workforce/contract/generate', ['template_code' => 'contrat-cdi', 'employee_id' => $e->id]);
    }

    public function test_profile_is_the_source_of_the_employer_identity_in_the_contract(): void
    {
        [$c, $owner] = $this->company('alpha');
        $this->profile($c, ['company_address' => '15 rue de la Logistique', 'company_postal_code' => '69003', 'company_city' => 'Lyon', 'siret' => '84726451300012']);
        $e = $this->employeeWithContract($c, $owner);

        $id = $this->generate($c, $owner, $e)->assertCreated()->json('id');
        $snap = GeneratedDocument::withoutGlobalScopes()->findOrFail($id)->generation_snapshot;
        $this->assertSame('15 rue de la Logistique', $snap['company.address']);
        $this->assertSame('69003 Lyon', $snap['company.city']);
        $this->assertSame('84726451300012', $snap['company.siret']);
        Storage::disk('local')->assertExists(GeneratedDocument::withoutGlobalScopes()->findOrFail($id)->file_path); // real PDF
    }

    public function test_incomplete_profile_refuses_the_contract_with_an_actionable_error(): void
    {
        [$c, $owner] = $this->company('beta');
        $this->profile($c, ['company_address' => '2 avenue Sans Ville']); // no postal code, no city
        $e = $this->employeeWithContract($c, $owner);

        $this->generate($c, $owner, $e)->assertStatus(422)
            ->assertJsonPath('code', 'employer_identity_incomplete')
            ->assertJsonPath('missing', ['postal_code', 'city'])
            ->assertJsonPath('fix.name', 'company-profile-tab');
        $this->assertSame(0, GeneratedDocument::withoutGlobalScopes()->where('company_id', $c->id)->count(), 'nothing emitted');
    }

    public function test_historical_columns_are_used_only_as_fallback_and_nothing_is_invented(): void
    {
        [$c] = $this->company('gamma', ['address_street' => '9 quai Ancien', 'address_postal_code' => '33000', 'address_city' => 'Bordeaux', 'siret' => '11122233300011']);
        $this->profile($c, ['company_city' => 'Mérignac']); // profile wins where filled

        $id = CompanyLegalIdentity::for($c);
        $this->assertSame('9 quai Ancien', $id['address']);
        $this->assertSame('33000', $id['postal_code']);
        $this->assertSame('Mérignac', $id['city']);
        $this->assertSame('11122233300011', $id['siret']);

        [$empty] = $this->company('delta');
        $none = CompanyLegalIdentity::for($empty);
        $this->assertNull($none['address']);
        $this->assertNull($none['city']);
        $this->assertSame('', CompanyLegalIdentity::documentLines($none)['street'], 'no placeholder');
        $this->assertSame(['address', 'postal_code', 'city'], CompanyLegalIdentity::missingForContract($none));
    }

    public function test_identity_never_crosses_companies(): void
    {
        [$a] = $this->company('alpha2');
        [$b] = $this->company('beta2');
        $this->profile($a, ['company_address' => '1 rue A', 'company_postal_code' => '75001', 'company_city' => 'Paris', 'siret' => '99999999999999']);

        $idB = CompanyLegalIdentity::for($b);
        $this->assertNull($idB['address']);
        $this->assertNull($idB['siret']);
    }

    public function test_an_existing_contract_is_never_rewritten_when_the_profile_changes(): void
    {
        [$c, $owner] = $this->company('epsilon');
        $this->profile($c, ['company_address' => '1 rue Avant', 'company_postal_code' => '13001', 'company_city' => 'Marseille']);
        $e = $this->employeeWithContract($c, $owner);
        $id = $this->generate($c, $owner, $e)->assertCreated()->json('id');
        $before = GeneratedDocument::withoutGlobalScopes()->findOrFail($id);
        $hash = hash('sha256', Storage::disk('local')->get($before->file_path));

        $this->profile($c, ['company_address' => '99 rue Après', 'company_city' => 'Nice']);

        $after = GeneratedDocument::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('1 rue Avant', $after->generation_snapshot['company.address']);
        $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($after->file_path)));
    }

    public function test_employer_identity_screen_reads_the_same_source(): void
    {
        [$c, $owner] = $this->company('zeta');
        $this->profile($c, ['company_address' => '5 place Haute', 'company_postal_code' => '31000', 'company_city' => 'Toulouse']);

        $this->actingAs($owner)->withHeaders(['X-Company-Id' => $c->id])->getJson('/api/workforce/settings')->assertOk()
            ->assertJsonPath('context.employer_identity.address', '5 place Haute')
            ->assertJsonPath('context.employer_identity.city', '31000 Toulouse')
            ->assertJsonPath('context.employer_identity.missing', []);
    }
}
