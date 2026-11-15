<?php

namespace Tests\Feature;

use App\Company\Fields\ReadModels\CompanyLegalIdentity;
use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-785 — Company legal identity : APE/NAF entered once in the profile, read by every document
 * consumer through CompanyLegalIdentity ; explicit, non-destructive completion of existing companies.
 */
class Adr785LegalIdentityTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\Market::firstOrCreate(['key' => 'FR'], ['name' => 'France', 'locale' => 'fr', 'timezone' => 'Europe/Paris', 'currency' => 'EUR', 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '', 'vat_rate_bps' => 2000]);
        \App\Core\Fields\FieldDefinitionCatalog::sync();
        $this->company = $this->makeCompany('Legal Co');
        $admin = $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $admin->id]);
    }

    private function makeCompany(string $name, array $attrs = []): Company
    {
        $c = Company::create(array_merge(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => 'FR'], $attrs));
        \App\Core\Jobdomains\JobdomainGate::assignToCompany($c, 'logistique');
        $this->activateCompanyModules($c);
        $this->artisan('company:complete-legal-identity', ['--company' => $c->id])->assertSuccessful();

        return $c;
    }

    private function def(string $code): FieldDefinition
    {
        return FieldDefinition::whereNull('company_id')->where('code', $code)->firstOrFail();
    }

    private function value(Company $c, string $code): ?string
    {
        return FieldValue::where('field_definition_id', $this->def($code)->id)->where('model_type', 'company')->where('model_id', $c->id)->value('value');
    }

    private function member(array $perms): User
    {
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => false]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function saveProfile(User $u, array $fields, ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->company)->id])
            ->putJson('/api/company', ['name' => ($c ?? $this->company)->name, 'dynamic_fields' => $fields]);
    }

    // ── Profile field ────────────────────────────────────────────

    public function test_naf_is_an_active_profile_field_for_a_french_company(): void
    {
        $this->assertTrue(FieldActivation::where('company_id', $this->company->id)->where('field_definition_id', $this->def('naf_code')->id)->where('enabled', true)->exists());
        $codes = collect($this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/company')->assertOk()->json('dynamic_fields'))->pluck('code');
        $this->assertContains('naf_code', $codes);
    }

    public function test_authorized_user_saves_a_valid_naf(): void
    {
        $this->saveProfile($this->owner, ['naf_code' => '62.01Z'])->assertOk();
        $this->assertSame('62.01Z', $this->value($this->company, 'naf_code'));
        $this->assertSame('6201Z', CompanyLegalIdentity::for($this->company->fresh())['naf_code'], 'normalized for documents');

        $this->saveProfile($this->owner, ['naf_code' => '4941A'])->assertOk();
        $this->assertSame('4941A', CompanyLegalIdentity::for($this->company->fresh())['naf_code']);
    }

    public function test_invalid_naf_is_refused_with_a_field_error(): void
    {
        foreach (['XX', '62011', '6201ZZ', 'ABCDE'] as $bad) {
            $this->saveProfile($this->owner, ['naf_code' => $bad])->assertStatus(422)->assertJsonValidationErrors('dynamic_fields.naf_code');
        }
        $this->assertNull($this->value($this->company, 'naf_code'));
    }

    public function test_member_without_settings_manage_cannot_edit_the_identity(): void
    {
        $viewer = $this->member(['workforce.view']);
        $this->saveProfile($viewer, ['naf_code' => '6201Z'])->assertForbidden();
        $this->assertNull($this->value($this->company, 'naf_code'));
    }

    public function test_a_user_cannot_edit_another_company_identity(): void
    {
        $other = $this->makeCompany('Other Co');
        $this->saveProfile($this->owner, ['naf_code' => '6201Z'], $other)->assertForbidden();
        $this->assertNull($this->value($other, 'naf_code'));
    }

    public function test_identity_never_leaks_between_companies(): void
    {
        $other = $this->makeCompany('Other Co', ['naf_code' => '7010Z']);
        $this->saveProfile($this->owner, ['naf_code' => '4941A'])->assertOk();
        $this->assertSame('7010Z', CompanyLegalIdentity::for($other->fresh())['naf_code']);
        $this->assertSame('4941A', CompanyLegalIdentity::for($this->company->fresh())['naf_code']);
    }

    // ── Historical compatibility ─────────────────────────────────

    public function test_historical_column_is_used_only_when_the_profile_is_empty(): void
    {
        $this->company->forceFill(['naf_code' => '70.10z'])->saveQuietly();
        $this->assertSame('7010Z', CompanyLegalIdentity::for($this->company->fresh())['naf_code']);
        $this->saveProfile($this->owner, ['naf_code' => '4941A'])->assertOk();
        $this->assertSame('4941A', CompanyLegalIdentity::for($this->company->fresh())['naf_code']);
    }

    public function test_absent_naf_stays_absent_never_inferred_from_the_jobdomain(): void
    {
        $this->assertNull(CompanyLegalIdentity::for($this->company->fresh())['naf_code']);
    }

    public function test_completion_command_reuses_valid_historical_values_without_overwriting(): void
    {
        $c = Company::create(['name' => 'Legacy', 'slug' => 'legacy-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => 'FR',
            'naf_code' => '62.01z', 'siret' => '73282932000074', 'address_city' => 'Paris']);
        $this->activateCompanyModules($c);
        FieldValue::create(['field_definition_id' => $this->def('company_city')->id, 'model_type' => 'company', 'model_id' => $c->id, 'value' => 'Lyon']);

        $this->artisan('company:complete-legal-identity', ['--company' => $c->id, '--dry-run' => true])->assertSuccessful();
        $this->assertNull($this->value($c, 'naf_code'), 'dry-run writes nothing');

        $this->artisan('company:complete-legal-identity', ['--company' => $c->id])->assertSuccessful();
        $this->assertSame('6201Z', $this->value($c, 'naf_code'));
        $this->assertSame('Lyon', $this->value($c, 'company_city'), 'profile value never overwritten');
        $this->assertSame('62.01z', $c->fresh()->naf_code, 'historical column kept as is');

        $this->artisan('company:complete-legal-identity', ['--company' => $c->id])->assertSuccessful();
        $this->assertSame(1, FieldValue::where('field_definition_id', $this->def('naf_code')->id)->where('model_id', $c->id)->count(), 'idempotent');
    }

    public function test_completion_command_skips_invalid_historical_values(): void
    {
        $c = Company::create(['name' => 'Bad', 'slug' => 'bad-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => 'FR',
            'naf_code' => 'XX', 'siret' => '12345678901234']);
        $this->activateCompanyModules($c);
        $this->artisan('company:complete-legal-identity', ['--company' => $c->id])->assertSuccessful();
        $this->assertNull($this->value($c, 'naf_code'));
        $this->assertNull($this->value($c, 'siret'));
    }

    public function test_completion_command_respects_a_field_disabled_by_the_company(): void
    {
        FieldActivation::where('company_id', $this->company->id)->where('field_definition_id', $this->def('naf_code')->id)->update(['enabled' => false]);
        $this->artisan('company:complete-legal-identity', ['--company' => $this->company->id])->assertSuccessful();
        $this->assertFalse((bool) FieldActivation::where('company_id', $this->company->id)->where('field_definition_id', $this->def('naf_code')->id)->value('enabled'));
    }

    // ── Actionable refusals ──────────────────────────────────────

    public function test_contract_refusal_points_to_the_missing_profile_fields(): void
    {
        $refusal = CompanyLegalIdentity::contractRefusal($this->company->id);
        $this->assertSame('employer_identity_incomplete', $refusal['code']);
        $this->assertSame('company-profile-tab', $refusal['fix']['name']);
        $this->assertSame('company_address,company_postal_code,company_city', $refusal['fix']['query']['missing']);
        $this->assertStringNotContainsString('_', $refusal['message'], 'no technical code shown');
    }

    public function test_complete_profile_identity_lifts_the_contract_refusal(): void
    {
        $this->saveProfile($this->owner, ['company_address' => '1 rue du Test', 'company_postal_code' => '75001', 'company_city' => 'Paris'])->assertOk();
        $this->assertNull(CompanyLegalIdentity::contractRefusal($this->company->id));
    }

    public function test_dsn_issue_keys_map_to_profile_fields(): void
    {
        $link = CompanyLegalIdentity::profileLink(['siret', 'naf_code', 'address_street', 'address_city']);
        $this->assertSame('siret,naf_code,company_address,company_city', $link['query']['missing']);
    }
}
