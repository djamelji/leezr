<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\Services\DocumentVariableResolver;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Workforce\Dsn\DsnEmployeeResolver;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-795.1 — one canonical write path and one canonical read for HR data, whatever the screen
 * (employee/person file, member management, own profile, document suggestion) ; multi-company
 * confidentiality of personal data ; established contract enforced server-side ; DSN reading fixed.
 */
class Adr7951HrDataConsistencyTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const CODES = ['job_title', 'contract_type', 'birth_date', 'gender', 'birth_country', 'social_security_number', 'iban', 'phone',
        'personal_address_street', 'personal_address_postal_code', 'personal_address_city'];

    private Company $a;
    private Company $b;
    private User $hrA;        // RH complet de A (workforce.manage + sensible + membres)
    private User $membersA;   // gestion des membres SANS droit RH ni sensible
    private User $person;     // salarié de A avec compte
    private Employee $empA;
    private Membership $personMembershipA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_leave']]);
        $this->a = $this->company('A');
        $this->b = $this->company('B');
        $this->setUpCompanyRbac($this->a);
        $this->hrA = $this->member($this->a, ['members.view', 'members.manage', 'members.sensitive_read', 'workforce.view', 'workforce.manage', 'workforce.sensitive_read']);
        $this->membersA = $this->member($this->a, ['members.view', 'members.manage']);
        $this->person = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Doe']);
        $this->personMembershipA = $this->join($this->a, $this->person);
        $this->empA = $this->employee($this->a, $this->person);
    }

    private function company(string $name): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach (['workforce', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        foreach (FieldDefinition::whereNull('company_id')->whereIn('code', self::CODES)->get() as $i => $d) {
            FieldActivation::create(['company_id' => $c->id, 'field_definition_id' => $d->id, 'enabled' => true, 'required_override' => false, 'order' => $i]);
        }

        return $c;
    }

    private function member(Company $c, array $perms): User
    {
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function join(Company $c, User $u): Membership
    {
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'p-'.uniqid(), 'name' => 'P']);

        return Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role->id]);
    }

    private function employee(Company $c, ?User $u = null): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'user_id' => $u?->id, 'first_name' => $u?->first_name ?? 'E', 'last_name' => $u?->last_name ?? uniqid(), 'status' => 'active', 'hire_date' => '2026-01-01']);
    }

    private function as(User $u, string $method, string $uri, array $body = [], ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id])->json($method, '/api'.$uri, $body);
    }

    private function memberPut(User $actor, Membership $m, array $fields, array $extra = [], ?Company $c = null)
    {
        return $this->as($actor, 'PUT', "/company/members/{$m->id}", ['dynamic_fields' => $fields] + $extra, $c);
    }

    private function selfPut(User $u, array $fields, ?Company $c = null)
    {
        return $this->as($u, 'PUT', '/profile', ['first_name' => $u->first_name, 'last_name' => $u->last_name, 'dynamic_fields' => $fields], $c);
    }

    private function memberField(User $viewer, Membership $m, string $code, ?Company $c = null): ?array
    {
        return collect($this->as($viewer, 'GET', "/company/members/{$m->id}", [], $c)->assertOk()->json('dynamic_fields'))->firstWhere('code', $code);
    }

    private function selfField(User $u, string $code, ?Company $c = null): ?array
    {
        return collect($this->as($u, 'GET', '/profile', [], $c)->assertOk()->json('dynamic_fields'))->firstWhere('code', $code);
    }

    private function hrField(User $viewer, Employee $e, string $code, ?Company $c = null): ?array
    {
        return collect($this->as($viewer, 'GET', "/workforce/employees/{$e->id}/fields", [], $c)->assertOk()->json('fields'))->firstWhere('code', $code);
    }

    private function def(string $code): int
    {
        return (int) FieldDefinition::whereNull('company_id')->where('code', $code)->value('id');
    }

    private function userValues(): int
    {
        return FieldValue::where('model_type', 'user')->count();
    }

    // 1, 2, 3, 5, 15 — fiche RH, fiche personne / gestion des membres, lectures identiques, aucune écriture implicite sur le compte
    public function test_every_screen_writes_employment_data_to_the_hr_record_and_reads_the_same_value(): void
    {
        $this->as($this->hrA, 'PUT', "/workforce/employees/{$this->empA->id}/fields", ['fields' => ['job_title' => 'Cariste']])->assertOk();
        $this->assertSame('Cariste', $this->memberField($this->hrA, $this->personMembershipA, 'job_title')['value'], 'gestion des membres lit la fiche RH');
        $this->assertSame('Cariste', $this->selfField($this->person, 'job_title')['value'], 'le profil personnel aussi');

        $this->memberPut($this->hrA, $this->personMembershipA, ['job_title' => 'Chef cariste'])->assertOk();
        $this->assertSame('Chef cariste', $this->hrField($this->hrA, $this->empA, 'job_title')['value']);
        $this->assertSame(0, $this->userValues(), 'aucune donnée d’emploi écrite sur le compte');

        // Un gestionnaire des membres sans droit RH ne modifie pas une donnée RH (mêmes droits partout).
        $this->memberPut($this->membersA, $this->personMembershipA, ['job_title' => 'Directeur'])->assertStatus(422)->assertJsonValidationErrors('dynamic_fields.job_title');
        // Renvoyer le formulaire tel qu'affiché : aucune écriture.
        $before = DB::table('field_value_changes')->count();
        $this->memberPut($this->membersA, $this->personMembershipA, ['job_title' => 'Chef cariste'])->assertOk();
        $this->assertSame($before, DB::table('field_value_changes')->count());
        $this->assertSame(2, DB::table('field_value_changes')->where('field_definition_id', $this->def('job_title'))->count(), 'historique des deux écrans');
    }

    // 3 bis — membre SANS fiche RH : donnée propre à la société sur son appartenance, jamais sur le compte
    public function test_member_without_hr_record_keeps_company_data_on_the_membership(): void
    {
        $member = User::factory()->create();
        $mA = $this->join($this->a, $member);
        $mB = $this->join($this->b, $member);
        $managerB = $this->member($this->b, ['members.view', 'members.manage']);
        $this->memberPut($this->membersA, $mA, ['job_title' => 'Comptable externe'])->assertOk(); // members.manage suffit (pas de fiche RH)
        $this->assertSame('Comptable externe', FieldValue::where('model_type', 'membership')->where('model_id', $mA->id)->value('value'));
        $this->assertSame(0, $this->userValues(), 'jamais sur le compte');
        $this->assertSame('Comptable externe', $this->memberField($this->hrA, $mA, 'job_title')['value']);
        $this->assertNull($this->memberField($managerB, $mB, 'job_title', $this->b)['value'], 'propre à A');
        $this->selfPut($member, ['job_title' => 'Associé'])->assertStatus(422); // la personne ne modifie pas la donnée de l'entreprise
    }

    // 4 — profil personnel : données personnelles oui, données RH non
    public function test_own_profile_writes_personal_data_and_never_the_company_hr_data(): void
    {
        $this->selfPut($this->person, ['phone' => '0612345678', 'personal_address_city' => 'Lyon'])->assertOk();
        $this->assertSame('+33612345678', FieldValue::where('model_type', 'user')->where('field_definition_id', $this->def('phone'))->value('value'));
        $this->assertTrue(DB::table('person_field_disclosures')->where(['user_id' => $this->person->id, 'company_id' => $this->a->id, 'field_definition_id' => $this->def('phone')])->exists());
        $this->assertSame('Lyon', $this->memberField($this->hrA, $this->personMembershipA, 'personal_address_city')['value']);
        $this->selfPut($this->person, ['job_title' => 'PDG'])->assertStatus(422)->assertJsonValidationErrors('dynamic_fields.job_title');
        $this->assertFalse(FieldValue::where('field_definition_id', $this->def('job_title'))->exists());
    }

    // 6, 7, 8, 9, 14, 19 — sans / avec compte, deux employeurs, données différentes, valeur historique ambiguë, cross-tenant
    public function test_multi_company_isolation_and_disclosure_of_personal_data(): void
    {
        $membershipB = $this->join($this->b, $this->person);
        $empB = $this->employee($this->b, $this->person);
        $hrB = $this->member($this->b, ['members.view', 'members.manage', 'members.sensitive_read', 'workforce.view', 'workforce.manage', 'workforce.sensitive_read']);

        $this->memberPut($this->hrA, $this->personMembershipA, ['job_title' => 'Livreur A', 'social_security_number' => '190017512345642'])->assertOk();
        $this->memberPut($hrB, $membershipB, ['job_title' => 'Préparateur B'], [], $this->b)->assertOk();
        $this->assertSame('Livreur A', $this->memberField($this->hrA, $this->personMembershipA, 'job_title')['value']);
        $this->assertSame('Préparateur B', $this->memberField($hrB, $membershipB, 'job_title', $this->b)['value']);

        $nirInB = $this->memberField($hrB, $membershipB, 'social_security_number', $this->b);
        $this->assertNull($nirInB['value'], 'NIR collecté par A : jamais lu par B');
        $this->assertTrue($nirInB['not_disclosed']);
        $this->assertNull($this->hrField($hrB, $empB, 'social_security_number', $this->b)['value']);
        $this->memberPut($hrB, $membershipB, ['social_security_number' => '299017512345678'], [], $this->b)->assertStatus(422); // jamais écrasé par B
        $this->assertSame('190017512345642', $this->selfField($this->person, 'social_security_number')['value'], 'la personne voit ses données');

        // Valeur historique (avant registre) d'une personne à deux employeurs : ambiguë → masquée partout.
        FieldValue::create(['field_definition_id' => $this->def('iban'), 'model_type' => 'user', 'model_id' => $this->person->id, 'value' => 'FR7630006000011234567890189']);
        $this->assertTrue($this->memberField($this->hrA, $this->personMembershipA, 'iban')['not_disclosed']);
        $this->assertTrue($this->memberField($hrB, $membershipB, 'iban', $this->b)['not_disclosed']);
        // …mais visible pour une personne à un seul employeur.
        $solo = User::factory()->create();
        $soloMembership = $this->join($this->a, $solo);
        FieldValue::create(['field_definition_id' => $this->def('iban'), 'model_type' => 'user', 'model_id' => $solo->id, 'value' => 'FR7611111111111111111111111']);
        $this->assertSame('FR7611111111111111111111111', $this->memberField($this->hrA, $soloMembership, 'iban')['value']);

        // Salarié sans compte : fiche RH seule.
        $accountless = $this->employee($this->a);
        $this->as($this->hrA, 'PUT', "/workforce/employees/{$accountless->id}/fields", ['fields' => ['birth_date' => '1990-05-05']])->assertOk();
        $this->assertSame('employee', $this->hrField($this->hrA, $accountless, 'birth_date')['source']);

        // Cross-tenant : membre / salarié de B avec l'en-tête de A.
        $this->memberPut($this->hrA, $membershipB, ['job_title' => 'pirate'])->assertNotFound();
        $this->as($this->hrA, 'GET', "/workforce/employees/{$empB->id}/fields")->assertNotFound();
        $this->assertSame('Préparateur B', $this->memberField($hrB, $membershipB, 'job_title', $this->b)['value']);
    }

    // 10 — champ sensible sans permission : masqué et jamais réécrit par un formulaire
    public function test_sensitive_field_without_permission_is_masked_and_never_overwritten(): void
    {
        $this->memberPut($this->hrA, $this->personMembershipA, ['iban' => 'FR7630006000011234567890189'])->assertOk();
        $shown = $this->memberField($this->membersA, $this->personMembershipA, 'iban');
        $this->assertNotSame('FR7630006000011234567890189', $shown['value']);
        $this->memberPut($this->membersA, $this->personMembershipA, ['iban' => $shown['value']])->assertOk(); // formulaire renvoyé tel quel
        $this->memberPut($this->membersA, $this->personMembershipA, ['iban' => 'FR7699999999999999999999999'])->assertOk();
        $this->assertSame('FR7630006000011234567890189', FieldValue::where('model_type', 'user')->where('field_definition_id', $this->def('iban'))->value('value'), 'jamais réécrit sans droit sensible');
        $this->as($this->membersA, 'PUT', "/workforce/employees/{$this->empA->id}/fields", ['fields' => ['job_title' => 'x']])->assertForbidden();
    }

    // 11, 12, 13 — contrat établi : contrôle serveur, première saisie protégée, modification RH autorisée
    public function test_established_contract_is_enforced_by_the_server_on_every_screen(): void
    {
        $contract = EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'employee_id' => $this->empA->id,
            'contract_type' => 'cdi', 'work_model_key' => 'hourly', 'start_date' => '2026-01-01', 'status' => EmploymentContract::STATUS_ACTIVE, 'is_current' => true])->fresh();

        $this->selfPut($this->person, ['birth_date' => '1990-01-01'])->assertStatus(422)->assertJsonValidationErrors('dynamic_fields.birth_date'); // première saisie protégée
        $this->selfPut($this->person, ['phone' => '0611111111'])->assertOk(); // coordonnées : libres
        $this->memberPut($this->membersA, $this->personMembershipA, ['birth_date' => '1990-01-01'], ['confirm_contractual' => true])->assertStatus(422); // le drapeau navigateur ne suffit pas
        $this->memberPut($this->hrA, $this->personMembershipA, ['birth_date' => '1990-01-01'])->assertStatus(422)->assertJsonValidationErrors('confirm_contractual'); // RH sans confirmation : signal d'interface
        $this->as($this->hrA, 'PUT', "/workforce/employees/{$this->empA->id}/fields", ['fields' => ['job_title' => 'Chef'], 'confirm_contractual' => false])->assertStatus(422);
        $this->assertFalse(FieldValue::where('field_definition_id', $this->def('birth_date'))->exists());

        $this->memberPut($this->hrA, $this->personMembershipA, ['birth_date' => '1990-01-01'], ['confirm_contractual' => true])->assertOk();
        $this->as($this->hrA, 'PUT', "/workforce/employees/{$this->empA->id}/fields", ['fields' => ['job_title' => 'Chef'], 'confirm_contractual' => true])->assertOk();
        $this->assertSame('1990-01-01', $this->selfField($this->person, 'birth_date')['value']);
        $this->assertSame($contract->getAttributes(), $contract->fresh()->getAttributes(), 'contrat jamais modifié');
    }

    // 16, 17, 18 — DSN : avec compte, sans compte, champ manquant
    public function test_dsn_reads_canonical_values_with_or_without_account_and_blocks_missing_fields(): void
    {
        $identity = ['social_security_number' => '190017512345642', 'gender' => 'M', 'birth_date' => '1990-01-01', 'birth_country' => 'FR',
            'personal_address_street' => '1 rue A', 'personal_address_postal_code' => '75001', 'personal_address_city' => 'Paris'];
        $this->memberPut($this->hrA, $this->personMembershipA, $identity)->assertOk();
        $withAccount = DsnEmployeeResolver::resolve($this->empA->fresh());
        $this->assertSame('190017512345642', $withAccount->nir);
        $this->assertTrue($withAccount->isDsnReady());

        $accountless = $this->employee($this->a);
        $this->as($this->hrA, 'PUT', "/workforce/employees/{$accountless->id}/fields", ['fields' => array_diff_key($identity, ['social_security_number' => 1])])->assertOk();
        $data = DsnEmployeeResolver::resolve($accountless->fresh());
        $this->assertSame('Paris', $data->addressCity, 'sans compte : fiche RH');
        $this->assertSame(['nir'], $data->missingMandatoryFields(), 'champ manquant → bloquant explicite');
        $this->assertFalse($data->isDsnReady());

        // Une autre société ne lit jamais l'identité collectée par A (non communiquée).
        $this->join($this->b, $this->person);
        $empB = $this->employee($this->b, $this->person);
        $this->assertNull(DsnEmployeeResolver::resolve($empB)->nir);
    }

    // 20 + documents — obligations opérationnelles inchangées ; variables de documents canoniques
    public function test_operational_requirements_unchanged_and_document_variables_canonical(): void
    {
        $before = json_encode([DocumentResolverService::requirementMap($this->a->id, null, 'FR'), DocumentRequest::count()]);
        $this->as($this->hrA, 'PUT', "/workforce/employees/{$this->empA->id}/fields", ['fields' => ['job_title' => 'Cariste', 'contract_type' => 'CDI']])->assertOk();
        $this->memberPut($this->hrA, $this->personMembershipA, ['phone' => '0622222222'])->assertOk();
        $this->assertSame($before, json_encode([DocumentResolverService::requirementMap($this->a->id, null, 'FR'), DocumentRequest::count()]));

        $vars = DocumentVariableResolver::resolve('employee', $this->empA->fresh(), $this->a);
        $this->assertSame('Cariste', $vars['field.job_title']);
        $this->assertSame('+33622222222', $vars['field.phone']);
        $this->assertArrayNotHasKey('field.job_title', DocumentVariableResolver::resolve('employee', $this->employee($this->b, $this->person), $this->b), 'jamais la donnée de A pour B');
    }
}
