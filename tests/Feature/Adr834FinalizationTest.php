<?php

namespace Tests\Feature;

use App\Core\Billing\Subscription;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Notifications\Billing\TrialStarted;
use App\Notifications\Members\AccountActivationNotification;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-834 finalisation — business decisions and reliability :
 *  - `create_companies` alone : create + record & start of service of ITS OWN companies only, never
 *    view_companies / manage_companies on anything ;
 *  - paid plan without trial : pending_payment, business operations refused by the existing billing control ;
 *  - no « trial started » e-mail from Platform ; active non-public activity allowed, inactive never ;
 *  - invitation → activation → first sign-in → owner rights and checklist.
 */
class Adr834FinalizationTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function operator(array $permissions, string $email = null): PlatformUser
    {
        $user = PlatformUser::create(['first_name' => 'Op', 'last_name' => 'Erator', 'email' => $email ?? Str::lower(Str::random(8)).'@platform.test', 'password' => 'P@ssw0rd!Strong']);
        $role = PlatformRole::create(['key' => 'r_'.Str::random(6), 'name' => 'Role '.Str::random(4)]);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissions)->pluck('id')->all());
        $user->roles()->attach($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'idempotency_key' => (string) Str::uuid(),
            'company' => ['name' => 'Fictive '.Str::random(5), 'market_key' => 'FR', 'dynamic_fields' => ['siret' => '12345678901234']],
            'jobdomain_key' => 'logistique',
            'subscription' => ['plan_key' => 'pro'],
            'administrator' => ['first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'ada.'.Str::lower(Str::random(6)).'@client.test'],
        ], $overrides);
    }

    // Accès limité pour create_companies + refus des API de gestion non autorisées
    public function test_create_companies_alone_sees_only_its_own_companies_and_manages_nothing(): void
    {
        $creator = $this->operator(['create_companies']);
        $other = Company::create(['name' => 'Historique', 'slug' => 'historique-'.Str::random(4), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $id = $this->actingAs($creator, 'platform')->postJson('/api/platform/companies', $this->payload())->assertCreated()->json('company.id');

        // its own company : record + start of service + re-send of the invitation
        $this->getJson("/api/platform/companies/{$id}")->assertOk();
        $this->getJson("/api/platform/companies/{$id}/onboarding")->assertOk()->assertJsonPath('stage', 'administrator_invited');
        $this->postJson("/api/platform/companies/{$id}/onboarding/resend-invitation")->assertOk();
        $this->getJson('/api/platform/company-creation/companies')->assertOk()->assertJsonCount(1, 'companies')->assertJsonPath('companies.0.id', $id);

        // any other company : nothing
        $this->getJson("/api/platform/companies/{$other->id}")->assertForbidden();
        $this->getJson("/api/platform/companies/{$other->id}/onboarding")->assertForbidden();
        $this->postJson("/api/platform/companies/{$other->id}/onboarding/resend-invitation")->assertForbidden();

        // never the global list nor the management APIs, even on its own company
        $this->getJson('/api/platform/companies')->assertForbidden();
        foreach (['billing', 'members', 'activity', 'plan-preview', 'payment-methods'] as $read) {
            $this->getJson("/api/platform/companies/{$id}/{$read}")->assertForbidden();
        }
        $this->putJson("/api/platform/companies/{$id}", ['name' => 'Renamed'])->assertForbidden();
        $this->putJson("/api/platform/companies/{$id}/suspend")->assertForbidden();
        $this->putJson("/api/platform/companies/{$id}/plan", ['plan_key' => 'business'])->assertForbidden();
        $this->putJson("/api/platform/companies/{$id}/modules/workforce/enable")->assertForbidden();
        $this->postJson("/api/platform/companies/{$id}/wallet", ['type' => 'credit', 'amount' => 10])->assertForbidden();
        $this->putJson("/api/platform/companies/{$id}/subscription/extend-trial", ['days' => 30])->assertForbidden();
        $this->assertSame('active', Company::find($id)->status);
        $this->assertSame('Historique', $other->fresh()->name);

        // another creator does not see it either
        $this->actingAs($this->operator(['create_companies']), 'platform')->getJson("/api/platform/companies/{$id}")->assertForbidden();
    }

    // Abonnement payant en attente de paiement : aucun accès payant avant le règlement (contrôle Billing existant)
    public function test_paid_plan_without_trial_awaits_payment_and_business_access_is_restricted(): void
    {
        $operator = $this->operator(['create_companies']);
        DB::table('plans')->where('key', 'pro')->update(['trial_days' => 0]);
        PlanRegistry::clearCache();

        $res = $this->actingAs($operator, 'platform')->postJson('/api/platform/companies', $this->payload())->assertCreated();
        $company = Company::findOrFail($res->json('company.id'));
        $sub = Subscription::where('company_id', $company->id)->where('is_current', 1)->firstOrFail();
        $this->assertSame('pending_payment', $sub->status);
        $this->assertFalse($company->hasBusinessAccess(), 'billing engine restricts business operations until payment');
        $this->assertSame('administrator_invited', $res->json('onboarding.stage'));
        $this->assertSame('todo', collect($res->json('onboarding.items'))->firstWhere('key', 'subscription')['status']);

        // a paid option is refused (would be invoiced) ; nothing paid is active
        $this->postJson('/api/platform/companies', $this->payload(['module_keys' => ['workforce']]))->assertUnprocessable();
        $paid = CompanyModule::where('company_id', $company->id)->where('is_enabled_for_company', true)
            ->whereIn('module_key', DB::table('platform_modules')->whereNotNull('addon_pricing')->pluck('key'))->count();
        $this->assertSame(0, $paid);

        // the owner can read, but business mutations answer 402 (EnsureBusinessAccess)
        $owner = Membership::where('company_id', $company->id)->where('role', 'owner')->firstOrFail()->user;
        $this->actingAs($owner, 'web')->withHeader('X-Company-Id', (string) $company->id)
            ->postJson('/api/shipments', [])->assertStatus(402)->assertJsonPath('restricted_access', true);
        $this->actingAs($owner, 'web')->withHeader('X-Company-Id', (string) $company->id)->getJson('/api/dashboard/onboarding')->assertOk();

        // administrator active, payment still due : « awaiting payment », never « operational »
        $owner->forceFill(['password' => 'Chosen!Password123', 'password_set_at' => now()])->save();
        $this->actingAs($operator, 'platform')->getJson("/api/platform/companies/{$company->id}/onboarding")->assertOk()
            ->assertJsonPath('stage', 'awaiting_payment')->assertJsonPath('in_service', false);
    }

    // Non-régression du blocage de l'étape « Abonnement » : une dérogation n'existe pas sur un plan gratuit — le serveur
    // la refuse toujours (l'assistant ne l'envoie plus) ; sur un plan payant, sans dérogation, la création passe.
    public function test_override_on_free_plan_is_refused_and_plans_without_override_create(): void
    {
        $sales = $this->operator(['create_companies', 'override_company_commercial_terms']);
        $override = ['type' => 'trial', 'trial_days' => 30, 'reason' => 'Pilote commercial validé'];
        $this->actingAs($sales, 'platform')->postJson('/api/platform/companies', $this->payload(['subscription' => ['plan_key' => 'starter', 'override' => $override]]))
            ->assertUnprocessable()->assertJsonValidationErrors('subscription.override');

        foreach (['starter' => 'active', 'pro' => 'trialing', 'business' => 'trialing'] as $plan => $status) {
            $id = $this->postJson('/api/platform/companies', $this->payload(['subscription' => ['plan_key' => $plan, 'override' => null]]))->assertCreated()->json('company.id');
            $this->assertSame($status, Subscription::where('company_id', $id)->value('status'), $plan);
        }
    }

    // JobDomain actif non public : proposé par Platform ; inactif : jamais
    public function test_active_non_public_jobdomain_is_allowed_and_inactive_never(): void
    {
        $operator = $this->operator(['create_companies']);
        $logistique = Jobdomain::where('key', 'logistique')->firstOrFail();
        $hidden = $logistique->replicate(['id']);
        $hidden->forceFill(['key' => 'logistique_pilote', 'label' => 'Logistique pilote', 'is_active' => true, 'signup_available' => false])->save();
        $inactive = $logistique->replicate(['id']);
        $inactive->forceFill(['key' => 'logistique_archive', 'label' => 'Logistique archivée', 'is_active' => false, 'signup_available' => false])->save();

        $options = collect($this->actingAs($operator, 'platform')->getJson('/api/platform/company-creation/options')->assertOk()->json('jobdomains'))->keyBy('key');
        $this->assertFalse($options['logistique_pilote']['offered_for_signup']);
        $this->assertFalse($options->has('logistique_archive'), 'an inactive activity is never proposed');

        $this->postJson('/api/platform/companies', $this->payload(['jobdomain_key' => 'logistique_pilote']))->assertCreated();
        $before = Company::count();
        $this->postJson('/api/platform/companies', $this->payload(['jobdomain_key' => 'logistique_archive']))->assertUnprocessable()->assertJsonValidationErrors('jobdomain_key');
        $this->assertSame($before, Company::count());
        // the public sign-up still refuses the hidden activity
        $this->getJson('/api/public/addons?jobdomain=logistique_pilote&plan=pro')->assertUnprocessable();
    }

    // Invitation et activation : e-mail d'activation (jamais « essai démarré »), mot de passe défini par l'administrateur,
    // première connexion, droits de propriétaire, checklist — société « opérationnelle »
    public function test_invitation_activation_first_sign_in_and_checklist(): void
    {
        Notification::fake();
        $operator = $this->operator(['create_companies']);
        $payload = $this->payload();
        $id = $this->actingAs($operator, 'platform')->postJson('/api/platform/companies', $payload)->assertCreated()->json('company.id');
        $admin = User::where('email', $payload['administrator']['email'])->firstOrFail();

        Notification::assertNotSentTo($admin, TrialStarted::class);
        $token = null;
        Notification::assertSentTo($admin, AccountActivationNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->assertNotNull($token);

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $chosen = 'Chosen-'.Str::random(10).'!9aA';
        $this->postJson('/api/reset-password', ['token' => $token, 'email' => $admin->email, 'password' => $chosen, 'password_confirmation' => $chosen])->assertOk();
        $this->assertNotNull($admin->fresh()->password_set_at);

        // first sign-in with the password the administrator chose (the browser recipe covers the real cookie session)
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => $chosen])->assertOk();
        $this->actingAs($admin->fresh(), 'web');
        $companies = $this->getJson('/api/my-companies')->assertOk()->json();
        $this->assertContains($id, collect($companies['companies'] ?? $companies)->pluck('id')->all());
        $this->assertTrue($admin->fresh()->isOwnerOf(Company::find($id)));
        $steps = $this->withHeader('X-Company-Id', (string) $id)->getJson('/api/dashboard/onboarding')->assertOk()->json('steps');
        $this->assertSame('account_created', $steps[0]['key']);

        $this->actingAs($operator, 'platform')->getJson("/api/platform/companies/{$id}/onboarding")->assertOk()
            ->assertJsonPath('in_service', true)->assertJsonPath('stage', 'operational');
    }
}
