<?php

namespace Tests\Feature;

use App\Core\Billing\Subscription;
use App\Core\Events\ModuleEnabled;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\PlanRegistry;
use App\Modules\Platform\Companies\Creation\PlatformCompanyCreation;
use App\Notifications\Members\AccountActivationNotification;
use App\Notifications\Members\CompanyInvitationNotification;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-834 — Platform « New company » wizard : creation and onboarding end to end, with the existing engines.
 * Scenarios 1–12 of the mission (creation, invitation, existing e-mail, single activity, dependencies, premium
 * outside the plan, Platform RBAC, initialisation, failed step, double submission, other companies untouched,
 * resumable onboarding).
 */
class Adr834PlatformCompanyCreationTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $operator;

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
        // commercial reality of the HR add-ons (paid options, sellable) — as configured in Platform
        foreach (['workforce' => 25, 'workforce_leave' => 19] as $key => $price) {
            PlatformModule::where('key', $key)->update(['addon_pricing' => ['pricing_model' => 'flat', 'pricing_metric' => 'none', 'pricing_params' => ['price_monthly' => $price]]]);
        }
        $this->operator = $this->platformUser('operator@platform.test', ['view_companies', 'create_companies', 'view_company_users']);
    }

    private function platformUser(string $email, array $permissions): PlatformUser
    {
        $user = PlatformUser::create(['first_name' => 'Op', 'last_name' => 'Erator', 'email' => $email, 'password' => 'P@ssw0rd!Strong']);
        $role = PlatformRole::create(['key' => 'r_'.Str::random(6), 'name' => 'Role '.$email]);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissions)->pluck('id')->all());
        $user->roles()->attach($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'idempotency_key' => (string) Str::uuid(),
            'company' => [
                'name' => 'Transports Fictifs '.Str::random(4),
                'market_key' => 'FR',
                'dynamic_fields' => ['siret' => '12345678901234', 'legal_name' => 'Transports Fictifs SAS', 'company_city' => 'Lyon'],
                'billing_same_as_company' => true,
            ],
            'jobdomain_key' => 'logistique',
            'module_keys' => [],
            'subscription' => ['plan_key' => 'pro', 'interval' => 'monthly'],
            'administrator' => ['first_name' => 'Alice', 'last_name' => 'Martin', 'email' => 'alice.'.Str::lower(Str::random(5)).'@client.test'],
        ], $overrides);
    }

    private function create(array $payload, ?PlatformUser $as = null)
    {
        return $this->actingAs($as ?? $this->operator, 'platform')->postJson('/api/platform/companies', $payload);
    }

    // 1 + 2 + 8 — complete creation, administrator invited with an activation link, initialisation applied
    public function test_complete_creation_with_new_administrator(): void
    {
        Notification::fake();
        $payload = $this->payload();
        $res = $this->create($payload)->assertCreated();

        $company = Company::findOrFail($res->json('company.id'));
        $this->assertSame('logistique', $company->jobdomain_key);
        $this->assertSame('FR', $company->market_key);
        $this->assertSame('pro', $company->plan_key);

        $sub = Subscription::where('company_id', $company->id)->where('is_current', 1)->firstOrFail();
        $this->assertSame('trialing', $sub->status, 'paid plan, administrator never had a trial → plan trial');
        $this->assertSame('internal', $sub->provider);
        $this->assertNotNull($sub->trial_ends_at);

        $admin = User::where('email', $payload['administrator']['email'])->firstOrFail();
        $this->assertNull($admin->password, 'never a password chosen by the operator');
        $this->assertNull($admin->password_set_at);
        $owner = Membership::where('company_id', $company->id)->where('user_id', $admin->id)->firstOrFail();
        $this->assertSame('owner', $owner->role);
        Notification::assertSentTo($admin, AccountActivationNotification::class);

        // initialisation : roles of the activity, company fields written, billing copy of the address
        $this->assertTrue(DB::table('company_roles')->where('company_id', $company->id)->exists());
        $this->assertTrue(CompanyModule::where('company_id', $company->id)->where('module_key', 'logistics_shipments')->where('is_enabled_for_company', true)->exists());
        $codes = DB::table('field_values')->join('field_definitions', 'field_definitions.id', '=', 'field_values.field_definition_id')
            ->where('field_values.model_id', $company->id)->where('field_values.model_type', $company->getMorphClass())->pluck('field_definitions.code')->all();
        $this->assertContains('siret', $codes);
        $this->assertContains('billing_city', $codes);

        $creation = PlatformCompanyCreation::where('company_id', $company->id)->firstOrFail();
        $this->assertSame($this->operator->id, $creation->platform_user_id);
        $this->assertSame('new_account', $creation->decisions['administrator']);
        $this->assertTrue(DB::table('platform_audit_logs')->where('action', 'company.created_by_platform')->where('target_id', (string) $company->id)->exists());

        // 12 (start) — created ≠ in service : the administrator has not activated the account yet
        $this->assertFalse($res->json('onboarding.in_service'));
        $this->assertSame('awaiting_activation', collect($res->json('onboarding.items'))->firstWhere('key', 'administrator')['detail']['state']);
        $this->assertFalse(PlatformUser::where('email', $payload['administrator']['email'])->exists(), 'no Platform account created or promoted');
    }

    // 3 — e-mail of an existing account (case-insensitive) : pending acceptance, never attached silently
    public function test_existing_account_is_invited_to_accept_never_attached_silently(): void
    {
        Notification::fake();
        $existing = User::create(['first_name' => 'Bob', 'last_name' => 'Existant', 'email' => 'bob@client.test', 'password' => 'Secret!123456', 'password_set_at' => now()]);
        $hash = $existing->password;

        $res = $this->create($this->payload(['administrator' => ['email' => 'BOB@Client.test', 'first_name' => 'X', 'last_name' => 'Y']]))->assertCreated();

        $this->assertSame(1, User::where('email', 'bob@client.test')->count(), 'no duplicate account');
        $existing->refresh();
        $this->assertSame($hash, $existing->password);
        $this->assertSame('Bob', $existing->first_name, 'operator input never overwrites an existing identity');
        $owner = Membership::where('company_id', $res->json('company.id'))->where('user_id', $existing->id)->firstOrFail();
        $this->assertTrue($owner->isPendingAcceptance());
        Notification::assertSentTo($existing, CompanyInvitationNotification::class);
        $this->assertSame('awaiting_acceptance', collect($res->json('onboarding.items'))->firstWhere('key', 'administrator')['detail']['state']);

        $this->actingAs($this->operator, 'platform')->getJson('/api/platform/company-creation/administrator?email=bob@client.test')
            ->assertOk()->assertJson(['exists' => true, 'active' => true]);
    }

    // 3b — an administrator who already had a trial elsewhere : no second trial without an audited override
    public function test_trial_already_used_gives_pending_payment(): void
    {
        $other = Company::create(['name' => 'Ancienne', 'slug' => 'ancienne-'.Str::random(4), 'plan_key' => 'pro', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $u = User::create(['first_name' => 'C', 'last_name' => 'D', 'email' => 'carol@client.test', 'password' => 'Secret!123456', 'password_set_at' => now()]);
        $other->memberships()->create(['user_id' => $u->id, 'role' => 'owner']);
        Subscription::create(['company_id' => $other->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active', 'provider' => 'internal', 'is_current' => 1,
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(), 'trial_ends_at' => now()->subDay()]);

        $res = $this->create($this->payload(['administrator' => ['email' => 'carol@client.test']]))->assertCreated();
        $this->assertSame('pending_payment', Subscription::where('company_id', $res->json('company.id'))->value('status'));
        $this->assertFalse($res->json('onboarding.in_service'));
    }

    // 4 — one activity, required and active
    public function test_single_active_jobdomain_is_required(): void
    {
        $before = Company::count();
        $this->create($this->payload(['jobdomain_key' => null]))->assertUnprocessable()->assertJsonValidationErrors('jobdomain_key');
        $p = $this->payload();
        $p['jobdomain_key'] = ['logistique', 'other'];
        $this->create($p)->assertUnprocessable()->assertJsonValidationErrors('jobdomain_key');
        $this->create($this->payload(['jobdomain_key' => 'does_not_exist']))->assertUnprocessable()->assertJsonValidationErrors('jobdomain_key');
        $this->assertSame($before, Company::count());
    }

    // 5 — dependencies resolved by the server, explained by the preview
    public function test_module_dependencies_are_resolved(): void
    {
        $preview = $this->actingAs($this->operator, 'platform')->getJson('/api/platform/company-creation/preview?jobdomain=logistique&plan=pro&market=FR')->assertOk();
        $leave = collect($preview->json('modules'))->firstWhere('key', 'workforce_leave');
        $this->assertSame('option', $leave['class']);
        $this->assertContains('workforce', array_column($leave['requires'], 'key'));

        $res = $this->create($this->payload(['module_keys' => ['workforce_leave']]))->assertCreated();
        $active = CompanyModule::where('company_id', $res->json('company.id'))->where('is_enabled_for_company', true)->pluck('module_key')->all();
        $this->assertContains('workforce_leave', $active);
        $this->assertContains('workforce', $active, 'dependency activated with it');
    }

    // 6 — premium module outside the plan refused, no circumvention ; paid options only during a trial
    public function test_module_outside_plan_is_refused_and_nothing_is_created(): void
    {
        $preview = $this->actingAs($this->operator, 'platform')->getJson('/api/platform/company-creation/preview?jobdomain=logistique&plan=pro&market=FR')->assertOk();
        $analytics = collect($preview->json('modules'))->firstWhere('key', 'logistics_analytics');
        $this->assertContains($analytics['class'], ['plan_required', 'unavailable']);

        $before = [Company::count(), User::count(), Subscription::count()];
        $this->create($this->payload(['module_keys' => ['logistics_analytics']]))->assertUnprocessable()->assertJsonValidationErrors('module_keys');
        $this->create($this->payload(['module_keys' => ['workforce'], 'subscription' => ['plan_key' => 'starter']]))->assertUnprocessable()->assertJsonValidationErrors('module_keys');
        $this->assertSame($before, [Company::count(), User::count(), Subscription::count()]);
    }

    // 7 — Platform RBAC : no permission, no action ; manage_companies does not imply creation ; override is explicit
    public function test_platform_permissions_are_enforced(): void
    {
        $viewer = $this->platformUser('viewer@platform.test', ['view_companies', 'manage_companies']);
        $this->actingAs($viewer, 'platform')->getJson('/api/platform/company-creation/options')->assertForbidden();
        $this->actingAs($viewer, 'platform')->getJson('/api/platform/company-creation/preview?jobdomain=logistique&plan=pro')->assertForbidden();
        $this->create($this->payload(), $viewer)->assertForbidden();

        $this->actingAs($this->operator, 'platform')->getJson('/api/platform/company-creation/options')->assertOk()->assertJson(['can_override' => false]);
        $override = ['subscription' => ['override' => ['type' => 'trial', 'trial_days' => 45, 'reason' => 'Pilote commercial validé par la direction']]];
        $this->create($this->payload($override))->assertUnprocessable()->assertJsonValidationErrors('subscription.override');

        $sales = $this->platformUser('sales@platform.test', ['view_companies', 'create_companies', 'override_company_commercial_terms']);
        $res = $this->create($this->payload($override), $sales)->assertCreated();
        $sub = Subscription::where('company_id', $res->json('company.id'))->firstOrFail();
        $this->assertSame('trialing', $sub->status);
        $this->assertSame(45, (int) round(now()->diffInDays($sub->trial_ends_at, true)));
        $log = DB::table('platform_audit_logs')->where('action', 'company.commercial_override')->where('target_id', (string) $res->json('company.id'))->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Pilote commercial', $log->metadata);
    }

    // 8 — market-conditional required fields validated server-side ; legal form of the market only
    public function test_required_identity_fields_and_legal_status_are_validated(): void
    {
        $before = Company::count();
        $p = $this->payload();
        unset($p['company']['dynamic_fields']['siret']);
        $this->create($p)->assertUnprocessable()->assertJsonValidationErrors('company.dynamic_fields.siret');
        $this->create($this->payload(['company' => ['legal_status_key' => 'not_a_form']]))->assertUnprocessable()->assertJsonValidationErrors('company.legal_status_key');
        $this->create($this->payload(['company' => ['dynamic_fields' => ['hacked_column' => 'x']]]))->assertUnprocessable();
        $this->assertSame($before, Company::count());
    }

    // 9 — a failure in a late step leaves nothing behind (single transaction)
    public function test_failure_during_creation_rolls_everything_back(): void
    {
        Event::listen(ModuleEnabled::class, function () {
            throw new \RuntimeException('simulated failure');
        });
        $before = [Company::count(), User::count(), Subscription::count(), Membership::count(), PlatformCompanyCreation::count(), DB::table('company_roles')->count()];
        $p = $this->payload(['module_keys' => ['workforce']]);
        $this->withoutExceptionHandling();
        try {
            $this->create($p);
            $this->fail('the simulated failure should have aborted the creation');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }
        $this->assertSame($before, [Company::count(), User::count(), Subscription::count(), Membership::count(), PlatformCompanyCreation::count(), DB::table('company_roles')->count()]);
        $this->assertFalse(User::where('email', $p['administrator']['email'])->exists());
    }

    // 10 — double submission of the final step : same company, never a second one
    public function test_double_submission_returns_the_same_company(): void
    {
        $p = $this->payload();
        $first = $this->create($p)->assertCreated();
        $second = $this->create($p)->assertOk()->assertJson(['replayed' => true]);
        $this->assertSame($first->json('company.id'), $second->json('company.id'));
        $this->assertSame(1, Company::where('name', $p['company']['name'])->count());
        $this->assertSame(1, User::where('email', $p['administrator']['email'])->count());
    }

    // 11 — the other companies are not touched
    public function test_other_companies_are_not_modified(): void
    {
        $other = Company::create(['name' => 'Historique', 'slug' => 'historique-'.Str::random(4), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $snapshot = fn () => [
            Company::find($other->id)->only(['name', 'slug', 'plan_key', 'jobdomain_key', 'market_key', 'status', 'legal_status_key', 'updated_at']),
            CompanyModule::where('company_id', $other->id)->orderBy('module_key')->get(['module_key', 'is_enabled_for_company'])->toArray(),
            Subscription::where('company_id', $other->id)->get()->toArray(),
            Membership::where('company_id', $other->id)->get()->toArray(),
            DB::table('company_roles')->where('company_id', $other->id)->get()->toArray(),
        ];
        $before = $snapshot();
        $this->create($this->payload(['module_keys' => ['workforce_leave']]))->assertCreated();
        $this->assertEquals($before, $snapshot());
    }

    // 12 — resumable onboarding : follow-up, re-sent invitation, in service once the administrator is active
    public function test_onboarding_can_be_followed_and_resumed(): void
    {
        Notification::fake();
        $p = $this->payload();
        $id = $this->create($p)->assertCreated()->json('company.id');

        $view = $this->platformUser('view@platform.test', ['view_companies']);
        $state = $this->actingAs($view, 'platform')->getJson("/api/platform/companies/{$id}/onboarding")->assertOk();
        $this->assertFalse($state->json('in_service'));
        $this->assertSame('done', collect($state->json('items'))->firstWhere('key', 'required_fields')['status']);
        $this->assertSame('done', collect($state->json('items'))->firstWhere('key', 'subscription')['status']);
        $this->actingAs($view, 'platform')->postJson("/api/platform/companies/{$id}/onboarding/resend-invitation")->assertForbidden();

        $this->actingAs($this->operator, 'platform')->postJson("/api/platform/companies/{$id}/onboarding/resend-invitation")->assertOk();
        $admin = User::where('email', $p['administrator']['email'])->firstOrFail();
        Notification::assertSentToTimes($admin, AccountActivationNotification::class, 2);

        $admin->forceFill(['password' => 'Chosen!Password123', 'password_set_at' => now()])->save(); // the administrator activates
        $state = $this->actingAs($view, 'platform')->getJson("/api/platform/companies/{$id}/onboarding")->assertOk();
        $this->assertTrue($state->json('in_service'));
        $this->assertNotEmpty($state->json('owner_steps'), 'the owner getting-started steps stay visible after the set-up');
    }

    // Public registration still uses the same engine (regression guard of the extraction)
    public function test_public_fields_endpoint_is_unchanged(): void
    {
        $this->getJson('/api/public/fields?jobdomain=logistique&market=FR')->assertOk()
            ->assertJsonFragment(['code' => 'siret', 'required' => true]);
    }
}
