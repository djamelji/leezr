<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\EmployeeFieldStore;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Fields\MembershipFieldTransfer;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/** ADR-796 — prerequisites A (membership → HR record), B (ambiguous historical values), C (collection traceability). */
class Adr796PrerequisitesTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $a;
    private Company $b;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_leave']]);
        $this->a = $this->company('A');
        $this->b = $this->company('B');
        $this->setUpCompanyRbac($this->a);
        $this->hr = $this->member($this->a, ['members.view', 'members.manage', 'workforce.view', 'workforce.manage', 'members.sensitive_read', 'workforce.sensitive_read']);
    }

    private function company(string $n): Company
    {
        $c = Company::create(['name' => $n, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach (['workforce', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        foreach (FieldDefinition::whereNull('company_id')->whereIn('code', ['job_title', 'work_mode', 'iban', 'birth_date', 'phone'])->get() as $i => $d) {
            FieldActivation::create(['company_id' => $c->id, 'field_definition_id' => $d->id, 'enabled' => true, 'required_override' => false, 'order' => $i]);
        }

        return $c;
    }

    private function member(Company $c, array $perms, ?User $u = null): User
    {
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        $u ??= User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function def(string $code): int
    {
        return (int) FieldDefinition::whereNull('company_id')->where('code', $code)->value('id');
    }

    // A — reprise appartenance → fiche RH : déterministe, idempotente, sans écrasement, conflits, historique, contrat
    public function test_membership_values_are_taken_over_by_the_hr_record_without_overwrite(): void
    {
        $person = $this->member($this->a, []);
        $m = Membership::where('company_id', $this->a->id)->where('user_id', $person->id)->first();
        FieldValue::create(['field_definition_id' => $this->def('job_title'), 'model_type' => 'membership', 'model_id' => $m->id, 'value' => 'Comptable']);
        FieldValue::create(['field_definition_id' => $this->def('work_mode'), 'model_type' => 'membership', 'model_id' => $m->id, 'value' => 'remote']);

        // Fiche RH créée par le parcours existant (rattachement) → reprise automatique.
        $this->actingAs($this->hr)->withHeaders(['X-Company-Id' => $this->a->id])
            ->postJson('/api/workforce/employees/from-member', ['user_id' => $person->id, 'hire_date' => '2026-02-01'])->assertCreated();
        $emp = Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->where('user_id', $person->id)->firstOrFail();
        $this->assertSame('Comptable', FieldValue::where('model_type', 'employee')->where('model_id', $emp->id)->where('field_definition_id', $this->def('job_title'))->value('value'));
        $this->assertTrue(FieldValue::where('model_type', 'membership')->where('model_id', $m->id)->exists(), 'jamais supprimée');
        $this->assertSame(2, DB::table('field_value_changes')->where('source', 'membership_transfer')->count());

        $this->assertSame(['job_title' => 'already_same', 'work_mode' => 'already_same'], MembershipFieldTransfer::apply($this->a, $emp, null), 'idempotent');
        FieldValue::where('model_type', 'employee')->where('field_definition_id', $this->def('work_mode'))->update(['value' => json_encode('on_site')]);
        $this->assertSame('conflict', MembershipFieldTransfer::apply($this->a, $emp, null)['work_mode'], 'conflit détecté, rien écrasé');
        $this->assertSame('on_site', FieldValue::where('model_type', 'employee')->where('field_definition_id', $this->def('work_mode'))->value('value'));

        // Fiche existante sans valeur + contrat établi : jamais repris d'office, mais jamais invisible.
        $other = $this->member($this->a, []);
        $m2 = Membership::where('company_id', $this->a->id)->where('user_id', $other->id)->first();
        $emp2 = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'user_id' => $other->id, 'first_name' => 'O', 'last_name' => 'T', 'status' => 'active', 'hire_date' => '2026-01-01']);
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'employee_id' => $emp2->id, 'contract_type' => 'cdi', 'work_model_key' => 'hourly', 'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);
        FieldValue::create(['field_definition_id' => $this->def('job_title'), 'model_type' => 'membership', 'model_id' => $m2->id, 'value' => 'Magasinier']);
        $cell = (new EmployeeFieldStore($this->a))->cellsFor($emp2, null)['job_title'];
        $this->assertSame(['Magasinier', 'membership', 'needs_review'], [$cell['value'], $cell['source'], $cell['status']], 'visible, à reprendre');
        $this->assertSame(['job_title' => 'contract_protected'], MembershipFieldTransfer::apply($this->a, $emp2, null));
        $this->assertSame([], MembershipFieldTransfer::apply($this->b, $emp2, null), 'limité à la société concernée');
    }

    // B + C — valeur ancienne ambiguë : jamais attribuée ; partage explicite par la personne ; traçabilité
    public function test_ambiguous_historical_value_is_shared_only_by_the_person_and_traced(): void
    {
        $person = $this->member($this->a, []);
        $this->member($this->b, [], $person);
        FieldValue::create(['field_definition_id' => $this->def('iban'), 'model_type' => 'user', 'model_id' => $person->id, 'value' => 'FR7630006000011234567890189']);
        $this->assertTrue((new EmployeeFieldStore($this->a))->cellsFor(null, $person)['iban']['not_disclosed']);

        $profile = $this->actingAs($person)->withHeaders(['X-Company-Id' => $this->a->id])->getJson('/api/profile')->assertOk();
        $this->assertSame(['iban'], $profile->json('undisclosed_codes'));
        $this->actingAs($person)->withHeaders(['X-Company-Id' => $this->a->id])->postJson('/api/profile/disclosures', ['codes' => ['iban', 'birth_date']])
            ->assertOk()->assertJsonPath('shared', ['iban']);
        $this->assertFalse((new EmployeeFieldStore($this->a))->cellsFor(null, $person)['iban']['not_disclosed'], 'A : communiqué par la personne');
        $this->assertTrue((new EmployeeFieldStore($this->b))->cellsFor(null, $person)['iban']['not_disclosed'], 'B : toujours non');
        $row = DB::table('person_field_disclosures')->where('user_id', $person->id)->first();
        $this->assertSame(['self_confirmed', 'shared_by_person', null], [$row->source, $row->purpose, $row->legal_basis], 'aucune base juridique déduite');

        // Demande de retrait : tracée pour revue RH, jamais appliquée automatiquement.
        $this->actingAs($person)->withHeaders(['X-Company-Id' => $this->a->id])->postJson('/api/profile/disclosures/withdrawal', ['code' => 'iban', 'reason' => 'changement de banque'])->assertOk();
        $this->assertNotNull(DB::table('person_field_disclosures')->where('user_id', $person->id)->value('withdrawal_requested_at'));
        $this->assertFalse((new EmployeeFieldStore($this->a))->cellsFor(null, $person)['iban']['not_disclosed']);
        $this->actingAs($person)->withHeaders(['X-Company-Id' => $this->b->id])->postJson('/api/profile/disclosures/withdrawal', ['code' => 'iban'])->assertStatus(422);

        // Écriture par la gestion des membres : finalité tracée par défaut, base juridique jamais inventée.
        $this->actingAs($this->hr)->withHeaders(['X-Company-Id' => $this->a->id])->putJson('/api/company/members/'.Membership::where('company_id', $this->a->id)->where('user_id', $person->id)->value('id'), ['dynamic_fields' => ['phone' => '0611111111']])->assertOk();
        $phone = DB::table('person_field_disclosures')->where('field_definition_id', $this->def('phone'))->first();
        $this->assertSame(['members', 'hr_record', null], [$phone->source, $phone->purpose, $phone->legal_basis]);
    }
}
