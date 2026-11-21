<?php

namespace Tests\Feature;

use App\Core\Audit\PlatformAuditLog;
use App\Core\Fields\CompanyCreationFields;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\JobdomainCompanyField;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Core\System\SystemSync;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-835 — company creation fields configurable without code : global catalog inherited, customised per
 * JobDomain within the legal and technical limits ; ONE rule for the public sign-up and the Platform wizard ;
 * never overwritten by SystemSync ; permissions and audit.
 */
class Adr835CompanyCreationFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Jobdomain $logistique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        ModuleRegistry::sync();
        PlanRegistry::sync();
        JobdomainRegistry::sync();
        FieldDefinitionCatalog::sync();
        PlatformPermissionCatalog::sync();
        $this->logistique = Jobdomain::where('key', 'logistique')->sole();
    }

    private function operator(array $permissions): PlatformUser
    {
        $user = PlatformUser::create(['first_name' => 'Op', 'last_name' => 'Erator', 'email' => Str::lower(Str::random(8)).'@platform.test', 'password' => 'P@ssw0rd!Strong']);
        $role = PlatformRole::create(['key' => 'r_'.Str::random(6), 'name' => 'Role '.Str::random(4)]);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissions)->pluck('id')->all());
        $user->roles()->attach($role);

        return $user;
    }

    private function def(string $code): FieldDefinition
    {
        return FieldDefinition::whereNull('company_id')->where('code', $code)->sole();
    }

    private function customise(PlatformUser $op, string $code, array $body)
    {
        return $this->actingAs($op, 'platform')->putJson("/api/platform/jobdomains/{$this->logistique->id}/company-fields/{$this->def($code)->id}",
            array_replace(['visible' => null, 'required' => null, 'markets' => null, 'default_value' => null], $body));
    }

    private function field(string $code, string $market = 'FR'): ?array
    {
        return collect(CompanyCreationFields::resolve('logistique', $market))->firstWhere('code', $code);
    }

    private function register(array $fields)
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');

        return $this->postJson('/api/register', ['first_name' => 'R', 'last_name' => 'U', 'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => 'P@ssw0rd!Strong', 'password_confirmation' => 'P@ssw0rd!Strong', 'company_name' => 'Reg '.Str::random(5),
            'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'starter', 'dynamic_fields' => $fields]);
    }

    private function platformCreate(PlatformUser $op, array $fields)
    {
        return $this->actingAs($op, 'platform')->postJson('/api/platform/companies', [
            'idempotency_key' => (string) Str::uuid(),
            'company' => ['name' => 'Fictive '.Str::random(5), 'market_key' => 'FR', 'dynamic_fields' => $fields],
            'jobdomain_key' => 'logistique',
            'subscription' => ['plan_key' => 'starter'],
            'administrator' => ['first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'ada.'.Str::lower(Str::random(6)).'@client.test'],
        ]);
    }

    private function storedValue(int $companyId, string $code): ?string
    {
        $raw = DB::table('field_values')->where('model_type', (new Company)->getMorphClass())->where('model_id', $companyId)
            ->where('field_definition_id', $this->def($code)->id)->value('value');

        return $raw === null ? null : (string) json_decode($raw, true); // FieldValue.value is stored as JSON
    }

    public function test_fields_inherit_the_global_catalog_by_default(): void
    {
        $this->assertTrue($this->field('siret')['required']);
        $this->assertFalse($this->field('vat_number')['required']);
        $this->assertNull($this->field('siret', 'GB'), 'SIRET is a French field (catalog markets)');

        $op = $this->operator(['manage_jobdomains']);
        $rows = collect($this->actingAs($op, 'platform')->getJson("/api/platform/jobdomains/{$this->logistique->id}/company-fields")->assertOk()->json('fields'))->keyBy('code');
        $this->assertNull($rows['siret']['custom']);
        $this->assertSame(['visible' => true, 'required' => true, 'markets' => ['FR'], 'default' => null], $rows['siret']['inherited']);
    }

    public function test_customisation_applies_identically_to_public_sign_up_and_platform_creation(): void
    {
        $op = $this->operator(['manage_jobdomains', 'create_companies']);

        // SIRET required by default : refused on both paths
        $this->register([])->assertStatus(422)->assertJsonValidationErrors('dynamic_fields.siret');
        $this->platformCreate($op, [])->assertStatus(422)->assertJsonValidationErrors('company.dynamic_fields.siret');

        // made optional for this JobDomain → accepted on both paths, same public field API
        $this->customise($op, 'siret', ['required' => false])->assertOk();
        $this->assertFalse(collect($this->getJson('/api/public/fields?jobdomain=logistique&market=FR')->json('fields'))->firstWhere('code', 'siret')['required']);
        $this->register([])->assertCreated();
        $this->platformCreate($op, [])->assertCreated();

        // hidden → not asked, and refused if sent, on both paths
        $this->customise($op, 'vat_number', ['visible' => false])->assertOk();
        $this->assertNull($this->field('vat_number'));
        $this->register(['vat_number' => 'FR123'])->assertStatus(422)->assertJsonValidationErrors('dynamic_fields.vat_number');
        $this->platformCreate($op, ['vat_number' => 'FR123'])->assertStatus(422)->assertJsonValidationErrors('company.dynamic_fields.vat_number');
    }

    public function test_default_value_is_proposed_and_stored_when_left_empty(): void
    {
        $op = $this->operator(['manage_jobdomains', 'create_companies']);
        $this->customise($op, 'company_city', ['default_value' => 'Paris'])->assertOk();
        $this->assertSame('Paris', $this->field('company_city')['default']);

        $id = $this->register(['siret' => '12345678901234'])->assertCreated()->json('company.id');
        $this->assertSame('Paris', $this->storedValue($id, 'company_city'));
        $id = $this->platformCreate($op, ['siret' => '12345678901234', 'company_city' => 'Lyon'])->assertCreated()->json('company.id');
        $this->assertSame('Lyon', $this->storedValue($id, 'company_city'), 'a typed value wins over the default');
    }

    public function test_technical_limits_are_enforced(): void
    {
        $op = $this->operator(['manage_jobdomains']);
        $this->customise($op, 'siret', ['markets' => ['GB']])->assertStatus(422)->assertJsonValidationErrors('markets');
        $this->customise($op, 'vat_number', ['visible' => false, 'required' => true])->assertStatus(422)->assertJsonValidationErrors('visible');
        $this->customise($op, 'naf_code', ['default_value' => 'ABC'])->assertStatus(422)->assertJsonValidationErrors('default_value');
        $this->customise($op, 'siret', ['default_value' => str_repeat('1', 15)])->assertStatus(422)->assertJsonValidationErrors('default_value');
        $this->assertSame(0, JobdomainCompanyField::count());
    }

    public function test_legal_floor_set_in_the_global_catalog_cannot_be_lowered_by_a_jobdomain(): void
    {
        $catalogOp = $this->operator(['manage_field_definitions']);
        $siret = $this->def('siret');
        $rules = $siret->validation_rules;
        $this->actingAs($catalogOp, 'platform')->putJson("/api/platform/field-definitions/{$siret->id}", ['validation_rules' => $rules + ['legal_required_markets' => ['GB']]])
            ->assertStatus(422)->assertJsonValidationErrors('validation_rules.legal_required_markets');
        $this->putJson("/api/platform/field-definitions/{$siret->id}", ['validation_rules' => ['required' => false] + $rules + ['legal_required_markets' => ['FR']]])
            ->assertStatus(422)->assertJsonValidationErrors('validation_rules.required');
        $this->putJson("/api/platform/field-definitions/{$siret->id}", ['validation_rules' => $rules + ['legal_required_markets' => ['FR']]])->assertOk();

        $op = $this->operator(['manage_jobdomains']);
        $this->customise($op, 'siret', ['required' => false])->assertStatus(422)->assertJsonValidationErrors('required');
        $this->customise($op, 'siret', ['visible' => false])->assertStatus(422)->assertJsonValidationErrors('visible');

        // even a customisation stored before the law was declared cannot lower it
        JobdomainCompanyField::create(['jobdomain_id' => $this->logistique->id, 'field_definition_id' => $siret->id, 'visible' => false, 'required' => false]);
        $this->assertTrue($this->field('siret')['required']);
    }

    public function test_a_field_shown_outside_the_jobdomain_list_is_activated_and_stored(): void
    {
        $extra = FieldDefinition::create(['company_id' => null, 'code' => 'fleet_size_835', 'scope' => FieldDefinition::SCOPE_COMPANY, 'label' => 'Taille de flotte',
            'type' => FieldDefinition::TYPE_STRING, 'validation_rules' => ['max' => 10], 'is_system' => false, 'created_by_platform' => true, 'default_order' => 500]);
        $this->assertNull($this->field('fleet_size_835'), 'not in the JobDomain field list : not asked');

        $op = $this->operator(['manage_jobdomains', 'create_companies']);
        $this->actingAs($op, 'platform')->putJson("/api/platform/jobdomains/{$this->logistique->id}/company-fields/{$extra->id}",
            ['visible' => true, 'required' => true, 'markets' => null, 'default_value' => null])->assertOk();
        $this->assertTrue($this->field('fleet_size_835')['required']);

        $id = $this->platformCreate($op, ['siret' => '12345678901234', 'fleet_size_835' => '12'])->assertCreated()->json('company.id');
        $this->assertSame('12', $this->storedValue($id, 'fleet_size_835'));
    }

    public function test_reset_audit_and_systemsync_never_overwrite_platform_decisions(): void
    {
        $op = $this->operator(['manage_jobdomains', 'manage_field_definitions']);
        $this->customise($op, 'siret', ['required' => false])->assertOk();
        $log = PlatformAuditLog::where('action', 'jobdomain.company_field_updated')->latest('id')->sole();
        $this->assertSame(['jobdomain' => 'logistique', 'field' => 'siret'], $log->metadata);
        $this->assertFalse($log->diff_after['required']);

        // global catalog edited in Platform
        $city = $this->def('company_city');
        $this->actingAs($op, 'platform')->putJson("/api/platform/field-definitions/{$city->id}", ['validation_rules' => $city->validation_rules + ['default' => 'Lille']])->assertOk();

        // a deployment re-runs the system sync : nothing decided in Platform is overwritten
        SystemSync::begin();
        JobdomainRegistry::sync();
        FieldDefinitionCatalog::sync();
        SystemSync::end();
        $this->assertFalse($this->field('siret')['required'], 'JobDomain customisation kept');
        $this->assertSame('Lille', $this->field('company_city')['default'], 'global catalog edit kept');

        // back to inheritance : follows the catalog again, audited
        $this->deleteJson("/api/platform/jobdomains/{$this->logistique->id}/company-fields/{$this->def('siret')->id}")->assertOk();
        $this->assertTrue($this->field('siret')['required']);
        $this->assertSame(1, PlatformAuditLog::where('action', 'jobdomain.company_field_reset')->count());
    }

    public function test_permissions(): void
    {
        $nobody = $this->operator(['view_companies']);
        $siret = $this->def('siret');
        $this->actingAs($nobody, 'platform')->getJson("/api/platform/jobdomains/{$this->logistique->id}/company-fields")->assertForbidden();
        $this->putJson("/api/platform/jobdomains/{$this->logistique->id}/company-fields/{$siret->id}", ['visible' => null, 'required' => false, 'markets' => null, 'default_value' => null])->assertForbidden();
        $this->putJson("/api/platform/field-definitions/{$siret->id}", ['validation_rules' => ['required' => false]])->assertForbidden();
        $this->assertSame(0, JobdomainCompanyField::count());
        $this->assertTrue($this->field('siret')['required']);
    }
}
