<?php

namespace Tests\Feature;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\ConventionCollective;
use App\Core\Workforce\Dsn\DsnCompanyData;
use App\Core\Workforce\Dsn\DsnCompanyResolver;
use App\Core\Workforce\Dsn\DsnCtpMapping;
use App\Core\Workforce\Dsn\DsnEmployeeData;
use App\Core\Workforce\Dsn\DsnEmployeeResolver;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DsnFoundationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Core\Markets\Market::create([
            'key' => 'FR',
            'name' => 'France',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'dial_code' => '+33',
            'flag_code' => 'fr',
            'flag_svg' => '🇫🇷',
            'vat_rate_bps' => 2000,
        ]);

        $this->user = User::factory()->create();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'DSN Test Company',
            'slug' => 'dsn-test',
            'market_key' => 'FR',
            'currency' => 'EUR',
            'jobdomain_key' => 'tech',
            'siret' => '73282932000074',
            'naf_code' => '6201Z',
            'address_street' => '15 rue de la Paix',
            'address_complement' => 'Bâtiment A',
            'address_postal_code' => '75002',
            'address_city' => 'Paris',
            'address_insee_code' => '75102',
            'address_country_code' => 'FR',
            'average_headcount' => 25,
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'employee_number' => 'EMP-DSN-001',
            'email' => 'marie.dupont@test.com',
            'hire_date' => '2024-01-15',
            'status' => 'active',
        ]);

        // Sync field definitions so DSN codes exist
        \App\Core\Fields\FieldDefinitionCatalog::sync();
    }

    // ─── DsnCtpMapping ─────────────────────────────────────

    public function test_ctp_mapping_returns_correct_code_for_known_contribution(): void
    {
        $result = DsnCtpMapping::get('urssaf_maladie');

        $this->assertNotNull($result);
        $this->assertSame('100', $result['ctp_code']);
        $this->assertSame('RG CAS GENERAL', $result['ctp_label']);
        $this->assertSame('S21.G00.22', $result['dsn_bloc']);
    }

    public function test_ctp_mapping_returns_null_for_unknown_code(): void
    {
        $this->assertNull(DsnCtpMapping::get('unknown_code'));
        $this->assertNull(DsnCtpMapping::ctpCode('unknown_code'));
        $this->assertNull(DsnCtpMapping::dsnBloc('unknown_code'));
    }

    public function test_ctp_mapping_csg_crds_have_no_ctp_code(): void
    {
        $this->assertNull(DsnCtpMapping::ctpCode('csg_deductible'));
        $this->assertNull(DsnCtpMapping::ctpCode('csg_non_deductible'));
        $this->assertNull(DsnCtpMapping::ctpCode('crds'));

        $this->assertTrue(DsnCtpMapping::isCsgCrds('csg_deductible'));
        $this->assertTrue(DsnCtpMapping::isCsgCrds('csg_non_deductible'));
        $this->assertTrue(DsnCtpMapping::isCsgCrds('crds'));

        $this->assertFalse(DsnCtpMapping::hasCtp('csg_deductible'));
    }

    public function test_ctp_mapping_csg_crds_go_to_bloc_78(): void
    {
        $this->assertSame('S21.G00.78', DsnCtpMapping::dsnBloc('csg_deductible'));
        $this->assertSame('S21.G00.78', DsnCtpMapping::dsnBloc('crds'));
    }

    public function test_ctp_mapping_all_contribution_codes_are_mapped(): void
    {
        $expectedCodes = [
            'urssaf_maladie', 'urssaf_vieillesse_deplaf', 'urssaf_vieillesse_plaf',
            'allocations_familiales', 'csa', 'at_mp', 'fnal',
            'retraite_t1', 'retraite_t2', 'ceg_t1', 'ceg_t2', 'cet',
            'chomage', 'ags',
            'formation_pro', 'taxe_apprentissage', 'dialogue_social',
            'csg_deductible', 'csg_non_deductible', 'crds',
        ];

        foreach ($expectedCodes as $code) {
            $this->assertNotNull(
                DsnCtpMapping::get($code),
                "Missing CTP mapping for contribution code: {$code}"
            );
        }
    }

    public function test_ctp_mapping_all_ctp_codes_returns_unique_codes(): void
    {
        $codes = DsnCtpMapping::allCtpCodes();

        $this->assertContains('100', $codes);
        $this->assertContains('260', $codes);
        $this->assertContains('262', $codes);
        $this->assertContains('332', $codes);
        $this->assertContains('772', $codes);
        $this->assertContains('937', $codes);
        $this->assertContains('058', $codes);
        $this->assertContains('027', $codes);
        $this->assertCount(8, $codes);
    }

    public function test_ctp_mapping_leezr_codes_for_ctp_100(): void
    {
        $codes = DsnCtpMapping::leezrCodesForCtp('100');

        $this->assertContains('urssaf_maladie', $codes);
        $this->assertContains('urssaf_vieillesse_deplaf', $codes);
        $this->assertContains('urssaf_vieillesse_plaf', $codes);
        $this->assertContains('allocations_familiales', $codes);
        $this->assertContains('at_mp', $codes);
        $this->assertContains('csa', $codes);
        $this->assertCount(6, $codes);
    }

    // ─── NIR Validation ────────────────────────────────────

    public function test_nir_validation_valid_standard(): void
    {
        // Valid NIR: 2 85 12 75 108 042 29
        // Base: 2851275108042 % 97 = 68 → key = 97-68 = 29
        $this->assertTrue(DsnEmployeeResolver::validateNir('285127510804229'));
    }

    public function test_nir_validation_valid_with_spaces(): void
    {
        $this->assertTrue(DsnEmployeeResolver::validateNir('2 85 12 75 108 042 29'));
    }

    public function test_nir_validation_valid_corsica_2a(): void
    {
        // Corsica 2A: replace 2A with 19 for modulo calc
        // Base: 1 85 01 2A 033 005 → 1850119033005 % 97 = 58 → key = 97-58 = 39
        $nir = '1850119033005';
        $expected = 97 - (intval(str_replace('2A', '19', '185012A033005')) % 97);
        $fullNir = '185012A033005' . str_pad((string) $expected, 2, '0', STR_PAD_LEFT);
        $this->assertTrue(DsnEmployeeResolver::validateNir($fullNir));
    }

    public function test_nir_validation_invalid_too_short(): void
    {
        $this->assertFalse(DsnEmployeeResolver::validateNir('12345'));
    }

    public function test_nir_validation_invalid_wrong_key(): void
    {
        // Valid base but wrong key
        $this->assertFalse(DsnEmployeeResolver::validateNir('285127510804299'));
    }

    public function test_nir_validation_invalid_non_numeric(): void
    {
        $this->assertFalse(DsnEmployeeResolver::validateNir('ABCDEFGHIJKLMNO'));
    }

    // ─── DsnEmployeeResolver ───────────────────────────────

    public function test_employee_resolver_returns_data_with_field_values(): void
    {
        $this->seedEmployeeFieldValues($this->user->id, [
            'social_security_number' => '285127510804229',
            'gender' => 'F',
            'birth_date' => '1985-12-15',
            'birth_city' => 'Paris',
            'birth_department' => '75',
            'birth_country' => 'France',
            'nationality' => 'Française',
            'personal_address_street' => '10 avenue des Champs-Élysées',
            'personal_address_postal_code' => '75008',
            'personal_address_city' => 'Paris',
            'personal_address_country_code' => 'FR',
        ]);

        $data = DsnEmployeeResolver::resolve($this->employee);

        $this->assertInstanceOf(DsnEmployeeData::class, $data);
        $this->assertSame($this->employee->id, $data->employeeId);
        $this->assertSame('Marie', $data->firstName);
        $this->assertSame('Dupont', $data->lastName);
        $this->assertSame('285127510804229', $data->nir);
        $this->assertSame('F', $data->gender);
        $this->assertSame('02', $data->dsnGenderCode());
        $this->assertSame('1985-12-15', $data->birthDate);
        $this->assertSame('Paris', $data->birthCity);
        $this->assertSame('75', $data->birthDepartment);
        $this->assertSame('France', $data->birthCountry);
        $this->assertTrue($data->isDsnReady());
        $this->assertEmpty($data->missingMandatoryFields());
    }

    public function test_employee_resolver_returns_missing_fields_when_no_values(): void
    {
        $data = DsnEmployeeResolver::resolve($this->employee);

        $this->assertFalse($data->isDsnReady());

        $missing = $data->missingMandatoryFields();
        $this->assertContains('nir', $missing);
        $this->assertContains('gender', $missing);
        $this->assertContains('birth_date', $missing);
        $this->assertContains('birth_country', $missing);
        $this->assertContains('personal_address_street', $missing);
    }

    public function test_employee_resolver_throws_when_no_user(): void
    {
        $noUserEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => null,
            'first_name' => 'Jean',
            'last_name' => 'NoUser',
            'employee_number' => 'EMP-NO-USER',
            'email' => 'jean@test.com',
            'hire_date' => '2024-06-01',
            'status' => 'active',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('has no linked user');
        DsnEmployeeResolver::resolve($noUserEmployee);
    }

    public function test_employee_resolver_batch_resolves_multiple(): void
    {
        $user2 = User::factory()->create();
        $employee2 = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $user2->id,
            'first_name' => 'Pierre',
            'last_name' => 'Martin',
            'employee_number' => 'EMP-DSN-002',
            'email' => 'pierre@test.com',
            'hire_date' => '2024-03-01',
            'status' => 'active',
        ]);

        $this->seedEmployeeFieldValues($this->user->id, [
            'social_security_number' => '285127510804229',
            'gender' => 'F',
        ]);
        $this->seedEmployeeFieldValues($user2->id, [
            'social_security_number' => '185037512300606',
            'gender' => 'M',
        ]);

        $employees = collect([$this->employee, $employee2]);
        $results = DsnEmployeeResolver::resolveBatch($employees);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey($this->employee->id, $results);
        $this->assertArrayHasKey($employee2->id, $results);
        $this->assertSame('F', $results[$this->employee->id]->gender);
        $this->assertSame('M', $results[$employee2->id]->gender);
    }

    public function test_employee_resolver_batch_skips_employees_without_user(): void
    {
        $noUserEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => null,
            'first_name' => 'Fantôme',
            'last_name' => 'SansUser',
            'employee_number' => 'EMP-GHOST',
            'email' => 'ghost@test.com',
            'hire_date' => '2024-06-01',
            'status' => 'active',
        ]);

        $employees = collect([$this->employee, $noUserEmployee]);
        $results = DsnEmployeeResolver::resolveBatch($employees);

        $this->assertCount(1, $results);
        $this->assertArrayHasKey($this->employee->id, $results);
        $this->assertArrayNotHasKey($noUserEmployee->id, $results);
    }

    public function test_employee_data_gender_code_conversion(): void
    {
        $data = new DsnEmployeeData(
            employeeId: 1, employeeNumber: 'E1', firstName: 'Test', lastName: 'User',
            email: null, hireDate: null, nir: null, gender: 'M',
            birthDate: null, birthCity: null, birthDepartment: null, birthCountry: null,
            nationality: null, addressStreet: null, addressPostalCode: null,
            addressCity: null, addressCountryCode: null,
        );
        $this->assertSame('01', $data->dsnGenderCode());

        $dataF = new DsnEmployeeData(
            employeeId: 2, employeeNumber: 'E2', firstName: 'Test', lastName: 'User',
            email: null, hireDate: null, nir: null, gender: 'F',
            birthDate: null, birthCity: null, birthDepartment: null, birthCountry: null,
            nationality: null, addressStreet: null, addressPostalCode: null,
            addressCity: null, addressCountryCode: null,
        );
        $this->assertSame('02', $dataF->dsnGenderCode());

        $dataNull = new DsnEmployeeData(
            employeeId: 3, employeeNumber: 'E3', firstName: 'Test', lastName: 'User',
            email: null, hireDate: null, nir: null, gender: null,
            birthDate: null, birthCity: null, birthDepartment: null, birthCountry: null,
            nationality: null, addressStreet: null, addressPostalCode: null,
            addressCity: null, addressCountryCode: null,
        );
        $this->assertNull($dataNull->dsnGenderCode());
    }

    // ─── DsnCompanyResolver ────────────────────────────────

    public function test_company_resolver_returns_complete_data(): void
    {
        $data = DsnCompanyResolver::resolve($this->company);

        $this->assertInstanceOf(DsnCompanyData::class, $data);
        $this->assertSame($this->company->id, $data->companyId);
        $this->assertSame('DSN Test Company', $data->name);
        $this->assertSame('73282932000074', $data->siret);
        $this->assertSame('732829320', $data->siren);
        $this->assertSame('00074', $data->nic);
        $this->assertSame('6201Z', $data->nafCode);
        $this->assertSame('15 rue de la Paix', $data->addressStreet);
        $this->assertSame('Bâtiment A', $data->addressComplement);
        $this->assertSame('75002', $data->addressPostalCode);
        $this->assertSame('Paris', $data->addressCity);
        $this->assertSame('75102', $data->addressInseeCode);
        $this->assertSame('FR', $data->addressCountryCode);
        $this->assertSame(25, $data->averageHeadcount);
        $this->assertTrue($data->isDsnReady());
    }

    public function test_company_resolver_detects_missing_mandatory_fields(): void
    {
        $incompleteCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Incomplete Corp',
            'slug' => 'incomplete',
            'market_key' => 'FR',
            'currency' => 'EUR',
            'jobdomain_key' => 'tech',
            // No siret, no naf_code, no address
        ]);

        $data = DsnCompanyResolver::resolve($incompleteCompany);

        $this->assertFalse($data->isDsnReady());
        $missing = $data->missingMandatoryFields();
        $this->assertContains('siret', $missing);
        $this->assertContains('naf_code', $missing);
        $this->assertContains('address_street', $missing);
        $this->assertContains('address_postal_code', $missing);
        $this->assertContains('address_city', $missing);
    }

    public function test_company_resolver_includes_idcc_from_convention_collective(): void
    {
        $cc = ConventionCollective::withoutGlobalScopes()->create([
            'idcc' => '3248',
            'short_name' => 'Métallurgie',
            'full_name' => 'Convention collective nationale de la métallurgie',
            'market_key' => 'FR',
            'is_active' => true,
        ]);

        $this->company->update(['default_convention_collective_id' => $cc->id]);

        $data = DsnCompanyResolver::resolve($this->company->fresh());

        $this->assertSame('3248', $data->idcc);
    }

    public function test_company_resolver_idcc_null_when_no_cc(): void
    {
        $data = DsnCompanyResolver::resolve($this->company);
        $this->assertNull($data->idcc);
    }

    // ─── SIRET Validation ──────────────────────────────────

    public function test_siret_validation_valid(): void
    {
        // Known valid SIRET: 73282932000074
        $this->assertTrue(DsnCompanyResolver::validateSiret('73282932000074'));
    }

    public function test_siret_validation_invalid_length(): void
    {
        $this->assertFalse(DsnCompanyResolver::validateSiret('1234'));
        $this->assertFalse(DsnCompanyResolver::validateSiret('123456789012345'));
    }

    public function test_siret_validation_invalid_non_numeric(): void
    {
        $this->assertFalse(DsnCompanyResolver::validateSiret('ABCDEFGHIJKLMN'));
    }

    public function test_siret_validation_invalid_luhn(): void
    {
        // Valid format but wrong Luhn checksum
        $this->assertFalse(DsnCompanyResolver::validateSiret('12345678901234'));
    }

    // ─── NAF Validation ────────────────────────────────────

    public function test_naf_validation_valid(): void
    {
        $this->assertTrue(DsnCompanyResolver::validateNafCode('6201Z'));
        $this->assertTrue(DsnCompanyResolver::validateNafCode('4941A'));
        $this->assertTrue(DsnCompanyResolver::validateNafCode('0111z')); // lowercase accepted
    }

    public function test_naf_validation_invalid(): void
    {
        $this->assertFalse(DsnCompanyResolver::validateNafCode('62Z'));      // too short
        $this->assertFalse(DsnCompanyResolver::validateNafCode('62011'));    // no letter
        $this->assertFalse(DsnCompanyResolver::validateNafCode('ABCDZ'));    // not 4 digits
    }

    // ─── Multi-tenant isolation ────────────────────────────

    public function test_employee_resolver_does_not_leak_across_companies(): void
    {
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Other Corp',
            'slug' => 'other',
            'market_key' => 'FR',
            'currency' => 'EUR',
            'jobdomain_key' => 'tech',
        ]);

        $otherUser = User::factory()->create();
        $otherEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'user_id' => $otherUser->id,
            'first_name' => 'Other',
            'last_name' => 'Person',
            'employee_number' => 'EMP-OTHER',
            'email' => 'other@test.com',
            'hire_date' => '2024-01-01',
            'status' => 'active',
        ]);

        // Seed field values for OTHER user
        $this->seedEmployeeFieldValues($otherUser->id, [
            'social_security_number' => '185037512300606',
        ]);

        // Resolve OUR employee — should NOT get the other user's NIR
        $data = DsnEmployeeResolver::resolve($this->employee);
        $this->assertNull($data->nir);

        // Resolve OTHER employee — should get their NIR
        $otherData = DsnEmployeeResolver::resolve($otherEmployee);
        $this->assertSame('185037512300606', $otherData->nir);
    }

    // ─── Company address migration ─────────────────────────

    public function test_company_address_fields_are_persisted(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Address Test',
            'slug' => 'addr-test',
            'market_key' => 'FR',
            'currency' => 'EUR',
            'jobdomain_key' => 'tech',
            'address_street' => '42 boulevard Haussmann',
            'address_postal_code' => '75009',
            'address_city' => 'Paris',
            'address_insee_code' => '75109',
            'address_country_code' => 'FR',
            'average_headcount' => 150,
        ]);

        $fresh = Company::withoutGlobalScopes()->find($company->id);
        $this->assertSame('42 boulevard Haussmann', $fresh->address_street);
        $this->assertSame('75009', $fresh->address_postal_code);
        $this->assertSame('Paris', $fresh->address_city);
        $this->assertSame('75109', $fresh->address_insee_code);
        $this->assertSame('FR', $fresh->address_country_code);
        $this->assertSame(150, $fresh->average_headcount);
    }

    // ─── Helper ────────────────────────────────────────────

    private function seedEmployeeFieldValues(int $userId, array $values): void
    {
        foreach ($values as $code => $value) {
            $def = FieldDefinition::whereNull('company_id')
                ->where('code', $code)
                ->first();

            if (! $def) {
                $this->fail("FieldDefinition not found for code: {$code}. Did you run FieldDefinitionCatalog::sync()?");
            }

            FieldValue::create([
                'field_definition_id' => $def->id,
                'model_type' => 'App\\Core\\Models\\User',
                'model_id' => $userId,
                'value' => $value,
            ]);
        }
    }
}
