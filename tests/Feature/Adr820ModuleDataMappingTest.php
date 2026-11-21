<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\EmployeeFieldStore;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\Data\CompanyModuleFieldMapping;
use App\Core\Presets\PresetService;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-820 (lot 2) — a module declares a DATA NEED ; the company binds it to ITS field (by stable id) or
 * creates the field from the assistant. Existing values are reused (never copied), a renamed label keeps
 * the binding, only the dependent operation is blocked when a value is missing, and nothing is bound
 * automatically. Tenant isolation and sensitive data are enforced.
 */
class Adr820ModuleDataMappingTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\PublishesStartingLibrary;

    private Company $c;

    private User $owner;

    private Employee $driver;

    private User $driverUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['core.members', 'core.settings', 'logistics_shipments'], 'allow_custom_fields' => true]);
        $rbac = PresetService::createDraft('rbac', ['family_key' => 'lg_rbac', 'label' => 'R', 'jobdomain_key' => 'logistique', 'content' => ['roles' => [
            ['key' => 'owner', 'name' => 'Propriétaire', 'is_administrative' => true, 'bundles' => ['members.team_management', 'roles.governance', 'modules.management', 'shipments.operations']],
            ['key' => 'driver', 'name' => 'Chauffeur', 'bundles' => ['shipments.delivery']]]]], null);
        PresetService::publish($rbac, null);
        PresetService::setRecommendations('logistique', [$this->libraryLink(), ['kind' => 'rbac', 'family_key' => 'lg_rbac']]);

        $id = $this->postJson('/api/register', ['first_name' => 'O', 'last_name' => 'W', 'email' => 'own820@example.test', 'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong', 'company_name' => 'Map 820', 'jobdomain_key' => 'logistique', 'plan_key' => 'starter', 'dynamic_fields' => ['siret' => '12345678901234']])->assertCreated()->json('company.id');
        $this->c = Company::findOrFail($id);
        $this->owner = User::where('email', 'own820@example.test')->sole();
        // Accès chauffeur confirmé (ADR-808 : droits opérationnels en attente jusqu'à confirmation).
        \App\Core\Modules\Setup\CompanyModuleSetup::where('company_id', $this->c->id)->update(['pending_access' => null]);
        $this->driverUser = User::factory()->create();
        $this->c->memberships()->create(['user_id' => $this->driverUser->id, 'role' => 'user', 'company_role_id' => CompanyRole::where('company_id', $this->c->id)->where('key', 'driver')->value('id')]);
        $this->driver = Employee::create(['company_id' => $this->c->id, 'user_id' => $this->driverUser->id, 'first_name' => 'Ali', 'last_name' => 'Route', 'status' => 'active', 'hire_date' => now()]);
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->c->id]);
    }

    private function customField(string $code, string $label, string $type = 'string'): FieldDefinition
    {
        $f = FieldDefinition::create(['company_id' => $this->c->id, 'code' => $code, 'scope' => FieldDefinition::SCOPE_COMPANY_USER, 'label' => $label, 'type' => $type, 'is_system' => false, 'created_by_platform' => false]);
        FieldActivation::create(['company_id' => $this->c->id, 'field_definition_id' => $f->id, 'enabled' => true, 'required_override' => false, 'order' => 900]);

        return $f;
    }

    private function shipment(): Shipment
    {
        return Shipment::create(['company_id' => $this->c->id, 'reference' => 'S-'.uniqid(), 'status' => 'draft', 'created_by_user_id' => $this->owner->id,
            'origin_address' => 'A', 'destination_address' => 'B']);
    }

    public function test_existing_field_is_proposed_bound_by_id_and_values_are_reused(): void
    {
        $amzl = $this->customField('amzl_id', 'ID');
        (new EmployeeFieldStore($this->c))->write($this->driver, ['amzl_id' => 'AMZ-42'], $this->owner, true);
        $values = FieldValue::count();

        $state = $this->as($this->owner)->getJson('/api/modules/logistics_shipments/data-needs')->assertOk()->json('needs.0');
        $this->assertSame('driver_identifier', $state['key']);
        $this->assertNull($state['mapping'], 'nothing bound automatically');
        $this->assertSame('amzl_id', $state['candidates'][0]['code'], 'the company field is proposed first');
        $this->assertNotContains('iban', array_column($state['candidates'], 'code'), 'sensitive field never proposed');
        $this->assertContains('company_phone', array_column(FieldDefinition::where('scope', 'company')->get()->toArray(), 'code'));
        $this->assertNotContains('company_phone', array_column($state['candidates'], 'code'), 'company-level field is not an individual datum');

        // Assignment works while the need is not bound (the module stays usable).
        $s = $this->shipment();
        $this->as($this->owner)->postJson("/api/shipments/{$s->id}/assign", ['user_id' => $this->owner->id])->assertOk();

        $bound = $this->as($this->owner)->putJson('/api/modules/logistics_shipments/data-needs/driver_identifier', ['field_definition_id' => $amzl->id])->assertOk()->json('needs.0');
        $this->assertSame('amzl_id', $bound['mapping']['code']);
        $this->assertSame(['population' => 1, 'filled' => 1], array_intersect_key($bound['coverage'], ['population' => 1, 'filled' => 1]));
        $this->assertSame($values, FieldValue::count(), 'no value copied');

        // Renamed label : the binding (stable id) and the value survive.
        $amzl->update(['label' => 'Identifiant transporteur']);
        $this->as($this->owner)->postJson("/api/shipments/{$s->id}/assign", ['user_id' => $this->driverUser->id])->assertOk();
        $this->assertSame('AMZ-42', $this->as($this->owner)->getJson("/api/shipments/{$s->id}")->assertOk()->json('shipment.driver_identifier') ?? $this->as($this->owner)->getJson("/api/shipments/{$s->id}")->json('driver_identifier'));
    }

    public function test_missing_value_blocks_only_the_dependent_operation_and_field_can_be_created(): void
    {
        $created = $this->as($this->owner)->postJson('/api/modules/logistics_shipments/data-needs/driver_identifier/field', ['label' => 'Matricule tournée'])->assertCreated()->json('needs.0');
        $field = FieldDefinition::where('company_id', $this->c->id)->where('code', 'matricule_tournee')->sole();
        $this->assertSame('string', $field->type);
        $this->assertSame($field->id, $created['mapping']['field_definition_id']);
        $this->assertFalse((bool) FieldActivation::where('company_id', $this->c->id)->where('field_definition_id', $field->id)->value('required_override'), 'never made mandatory');
        $this->assertSame(1, $created['coverage']['missing_count']);
        $this->assertSame('Ali Route', $created['coverage']['missing'][0]['name']);

        $s = $this->shipment();
        $this->as($this->owner)->postJson("/api/shipments/{$s->id}/assign", ['user_id' => $this->driverUser->id])->assertStatus(422)->assertJsonValidationErrors('assigned_to_user_id');
        $this->as($this->owner)->getJson('/api/shipments')->assertOk(); // the rest of the module keeps working
        (new EmployeeFieldStore($this->c))->write($this->driver, ['matricule_tournee' => 'T-7'], $this->owner, true);
        $this->as($this->owner)->postJson("/api/shipments/{$s->id}/assign", ['user_id' => $this->driverUser->id])->assertOk();
    }

    /** ADR-822 — the assignment picker shows each person's identifier, read from the bound field (never copied). */
    public function test_assignees_expose_the_bound_identifier_only_once_bound(): void
    {
        $free = $this->as($this->owner)->getJson('/api/shipments/assignees')->assertOk()->json();
        $this->assertFalse($free['identifier_required'], 'unbound need : nobody is blocked');
        $this->assertContains($this->driverUser->id, array_column($free['people'], 'user_id'));

        $amzl = $this->customField('amzl_id', 'ID transporteur');
        $this->as($this->owner)->putJson('/api/modules/logistics_shipments/data-needs/driver_identifier', ['field_definition_id' => $amzl->id])->assertOk();
        $row = fn () => collect($this->as($this->owner)->getJson('/api/shipments/assignees')->assertOk()->json('people'))->firstWhere('user_id', $this->driverUser->id);
        $this->assertNull($row()['driver_identifier'], 'missing value reported, not invented');
        (new EmployeeFieldStore($this->c))->write($this->driver, ['amzl_id' => 'AMZ-7'], $this->owner, true);
        $this->assertSame('AMZ-7', $row()['driver_identifier']);
        $this->assertTrue($this->as($this->owner)->getJson('/api/shipments/assignees')->json('identifier_required'));
    }

    public function test_incompatible_foreign_and_unauthorised_bindings_are_refused(): void
    {
        $date = $this->customField('start_day', 'Début', 'date');
        $this->as($this->owner)->putJson('/api/modules/logistics_shipments/data-needs/driver_identifier', ['field_definition_id' => $date->id])->assertStatus(422);
        $iban = FieldDefinition::whereNull('company_id')->where('code', 'iban')->sole();
        $this->as($this->owner)->putJson('/api/modules/logistics_shipments/data-needs/driver_identifier', ['field_definition_id' => $iban->id])->assertStatus(422);

        $other = Company::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'starter']);
        $foreign = FieldDefinition::create(['company_id' => $other->id, 'code' => 'x_id', 'scope' => FieldDefinition::SCOPE_COMPANY_USER, 'label' => 'X', 'type' => 'string', 'is_system' => false, 'created_by_platform' => false]);
        $this->as($this->owner)->putJson('/api/modules/logistics_shipments/data-needs/driver_identifier', ['field_definition_id' => $foreign->id])->assertStatus(422);
        $this->as($this->driverUser)->getJson('/api/modules/logistics_shipments/data-needs')->assertForbidden();
        $this->assertSame(0, CompanyModuleFieldMapping::count());
    }
}
