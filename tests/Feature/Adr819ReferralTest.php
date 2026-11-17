<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation;
use App\Modules\Workforce\Referral\Models\Referral;
use App\Modules\Workforce\Referral\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

/**
 * ADR-819 (Company 2.0) — Workforce Parrainage : recommend or share a link, candidate consent, HR review,
 * conversion into the EXISTING hiring file without re-typing ; confidentiality (referrer never sees the HR
 * process, HR sees nothing before consent), single-use expiring links, limits, tenant isolation, retention.
 */
class Adr819ReferralTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private const MODULES = ['workforce', 'workforce_referral'];

    private Company $a;
    private Company $b;
    private User $hr;
    private User $employee;
    private JobRole $driver;
    private JobRole $closed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => self::MODULES]);
        $this->a = $this->company('a');
        $this->b = $this->company('b');
        $this->hr = $this->member(['workforce.view', 'workforce.manage', 'workforce_referral.review', 'workforce_referral.manage', 'workforce_referral.refer']);
        $this->employee = $this->member(['workforce_referral.refer']);
        Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'user_id' => $this->employee->id, 'first_name' => 'Sam', 'last_name' => 'Parrain', 'status' => 'active', 'hire_date' => '2024-01-01']);
        Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'user_id' => $this->hr->id, 'first_name' => 'Rh', 'last_name' => 'Salariée', 'status' => 'active', 'hire_date' => '2020-01-01']);
        $this->driver = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Livreur', 'level' => 'agent', 'source' => 'company', 'description' => 'Tournées en ville.']);
        $this->closed = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Comptable', 'level' => 'agent', 'source' => 'company']);
        $this->api($this->hr, 'PUT', '/workforce/referrals/settings', ['mode' => 'both', 'job_role_ids' => [$this->driver->id], 'notify_reviewers' => true, 'retention_days' => 90, 'link_days' => 14])->assertOk();
    }

    private function company(string $n): Company
    {
        $c = Company::create(['name' => "Soc {$n}", 'slug' => 'adr819r-'.$n, 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach (self::MODULES as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        foreach (DocumentType::where('is_system', true)->get() as $i => $t) {
            DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => $t->id, 'enabled' => true, 'order' => $i, 'required_override' => $t->code === 'id_card']);
        }

        return $c;
    }

    private function member(array $perms, ?Company $c = null): User
    {
        $c ??= $this->a;
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => false]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function api(User $u, string $method, string $uri, array $body = [], ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id])->json($method, '/api'.$uri, $body);
    }

    private function public(string $uri, array $body)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Accept' => 'application/json'])->json('POST', '/api/public/referral'.$uri, $body);
    }

    private function token(string $url): string
    {
        return substr($url, strpos($url, '#t=') + 3);
    }

    public function test_recommendation_consent_review_and_conversion_without_retyping(): void
    {
        $overview = $this->api($this->employee, 'GET', '/workforce/referrals/mine')->assertOk();
        $this->assertSame([['id' => $this->driver->id, 'title' => 'Livreur', 'description' => 'Tournées en ville.', 'department' => null]], $overview->json('opportunities'));

        $res = $this->api($this->employee, 'POST', '/workforce/referrals/recommend', ['job_role_id' => $this->driver->id, 'first_name' => 'Léa', 'last_name' => 'Proche', 'email' => 'lea@example.test', 'note' => 'Très fiable.'])->assertCreated();
        $token = $this->token($res->json('url'));
        $this->assertSame('awaiting_candidate', $res->json('referral.status'));

        // Before consent HR sees a count only — never the person.
        $queue = $this->api($this->hr, 'GET', '/workforce/referrals')->assertOk();
        $this->assertSame([], $queue->json('items'));
        $this->assertSame(1, $queue->json('awaiting_consent'));

        // Candidate : sees the opportunity, must consent, single use.
        $view = $this->public('/view', ['token' => $token])->assertOk();
        $this->assertSame('Livreur', $view->json('position'));
        $this->assertSame('Léa', $view->json('prefill.first_name'));
        $this->public('/apply', ['token' => $token, 'first_name' => 'Léa', 'last_name' => 'Proche', 'email' => 'lea@example.test'])->assertStatus(422); // no consent
        $this->public('/apply', ['token' => $token, 'first_name' => 'Léa', 'last_name' => 'Proche', 'email' => 'lea@example.test', 'consent' => true, 'website' => 'spam'])->assertStatus(422); // honeypot
        $this->public('/apply', ['token' => $token, 'first_name' => 'Léa', 'last_name' => 'Proche', 'email' => 'lea@example.test', 'phone' => '0600000000', 'message' => 'Disponible.', 'consent' => true])->assertOk();
        $this->public('/view', ['token' => $token])->assertNotFound(); // used

        // HR : review, duplicate check, accept, convert into ONE hiring file prefilled.
        $id = $this->api($this->hr, 'GET', '/workforce/referrals')->json('items.0.id');
        $detail = $this->api($this->hr, 'GET', "/workforce/referrals/{$id}")->assertOk();
        $this->assertSame('in_review', $detail->json('status'));
        $this->assertSame('Très fiable.', $detail->json('referrer_note'));
        $this->assertNotNull($detail->json('consent_at'));
        $this->api($this->hr, 'POST', "/workforce/referrals/{$id}/convert", ['hire_date' => '2027-01-04'])->assertStatus(422); // accept first
        $this->api($this->hr, 'POST', "/workforce/referrals/{$id}/decision", ['decision' => 'accept'])->assertOk();
        $file = $this->api($this->hr, 'POST', "/workforce/referrals/{$id}/convert", ['hire_date' => '2027-01-04'])->assertOk()->json('hiring_file_id');
        $inv = OnboardingInvitation::find($file);
        $emp = Employee::withoutGlobalScopes()->find($inv->employee_id);
        $this->assertSame(['Léa', 'Proche', 'lea@example.test', $this->driver->id], [$emp->first_name, $emp->last_name, $inv->candidate_email, $emp->job_role_id]);
        $this->assertTrue($emp->isPreparatory());
        $this->assertNull($emp->user_id, 'no account created');
        $this->api($this->hr, 'POST', "/workforce/referrals/{$id}/convert", ['hire_date' => '2027-01-04'])->assertStatus(422); // once

        // Referrer : coarse status only, never the decision nor the application.
        $mine = collect($this->api($this->employee, 'GET', '/workforce/referrals/mine')->json('mine'))->firstWhere('id', $id);
        $this->assertSame('closed', $mine['status']);
        $this->assertArrayNotHasKey('candidate_message', $mine);
        $this->assertArrayNotHasKey('review_note', $mine);
        $this->api($this->employee, 'GET', "/workforce/referrals/{$id}")->assertForbidden();
        $this->api($this->employee, 'GET', "/workforce/onboarding-files/{$file}/file")->assertForbidden();
    }

    public function test_shared_link_info_request_and_decline(): void
    {
        $url = $this->api($this->employee, 'POST', '/workforce/referrals/share', ['job_role_id' => $this->driver->id])->assertCreated()->json('url');
        $view = $this->public('/view', ['token' => $this->token($url)])->assertOk();
        $this->assertNull($view->json('prefill'), 'a shared link carries no personal data');
        $this->public('/apply', ['token' => $this->token($url), 'first_name' => 'Tom', 'last_name' => 'Ami', 'email' => 'tom@example.test', 'consent' => true])->assertOk();
        $id = Referral::where('email', 'tom@example.test')->value('id');

        $this->api($this->hr, 'POST', "/workforce/referrals/{$id}/decision", ['decision' => 'request_info'])->assertStatus(422); // message required
        $info = $this->api($this->hr, 'POST', "/workforce/referrals/{$id}/decision", ['decision' => 'request_info', 'note' => 'Votre permis ?'])->assertOk();
        $view = $this->public('/view', ['token' => $this->token($info->json('url'))])->assertOk();
        $this->assertSame('info', $view->json('mode'));
        $this->assertSame('Votre permis ?', $view->json('info_request'));
        $this->public('/apply', ['token' => $this->token($info->json('url')), 'first_name' => 'Tom', 'last_name' => 'Ami', 'email' => 'tom@example.test', 'message' => 'Permis C.', 'consent' => true])->assertOk();
        $this->assertSame(Referral::IN_REVIEW, Referral::find($id)->status);

        $this->api($this->hr, 'POST', "/workforce/referrals/{$id}/decision", ['decision' => 'decline', 'note' => 'Profil non adapté'])->assertOk();
        $this->assertNotNull(Referral::find($id)->purge_after);
        $this->assertSame('closed', collect($this->api($this->employee, 'GET', '/workforce/referrals/mine')->json('mine'))->firstWhere('id', $id)['status']);
    }

    public function test_guards_limits_tenant_and_retention(): void
    {
        // Position not open / mode disabled.
        $this->api($this->employee, 'POST', '/workforce/referrals/share', ['job_role_id' => $this->closed->id])->assertStatus(422);
        $this->api($this->hr, 'PUT', '/workforce/referrals/settings', ['mode' => 'recommend', 'job_role_ids' => [$this->driver->id]])->assertOk();
        $this->api($this->employee, 'POST', '/workforce/referrals/share', ['job_role_id' => $this->driver->id])->assertStatus(422);
        $this->api($this->hr, 'PUT', '/workforce/referrals/settings', ['mode' => 'both', 'job_role_ids' => [$this->driver->id]])->assertOk();

        // Only an EMPLOYEE refers (a member without employee record cannot).
        $noRecord = $this->member(['workforce_referral.refer']);
        $this->api($noRecord, 'POST', '/workforce/referrals/share', ['job_role_id' => $this->driver->id])->assertStatus(422);

        // Daily limit against bulk sending.
        for ($i = 0; $i < ReferralService::DAILY_LIMIT; $i++) {
            $this->api($this->employee, 'POST', '/workforce/referrals/share', ['job_role_id' => $this->driver->id])->assertCreated();
        }
        $this->api($this->employee, 'POST', '/workforce/referrals/share', ['job_role_id' => $this->driver->id])->assertStatus(422);

        // Rights : an employee cannot review nor configure ; another company sees nothing.
        $this->api($this->employee, 'GET', '/workforce/referrals')->assertForbidden();
        $this->api($this->employee, 'PUT', '/workforce/referrals/settings', ['mode' => 'both', 'job_role_ids' => []])->assertForbidden();
        $otherHr = $this->member(['workforce_referral.review'], $this->b);
        $anyId = Referral::value('id');
        $this->api($otherHr, 'GET', "/workforce/referrals/{$anyId}", [], $this->b)->assertNotFound();
        $this->assertSame(0, $this->api($otherHr, 'GET', '/workforce/referrals', [], $this->b)->json('awaiting_consent'));

        // Expired link : same answer as an unknown one.
        $url = $this->api($this->hr, 'POST', '/workforce/referrals/share', ['job_role_id' => $this->driver->id])->json('url');
        Referral::latest('id')->first()->forceFill(['link_expires_at' => now()->subDay()])->save();
        $this->public('/view', ['token' => $this->token($url)])->assertNotFound();
        $this->public('/view', ['token' => str_repeat('a', 64)])->assertNotFound();

        // Retention : anonymised after the purge date, history kept.
        $r = Referral::first();
        $r->forceFill(['status' => Referral::DECLINED, 'first_name' => 'X', 'email' => 'x@example.test', 'purge_after' => now()->subMinute()])->save();
        $this->artisan('referrals:purge')->assertSuccessful();
        $r->refresh();
        $this->assertNotNull($r->anonymized_at);
        $this->assertNull($r->email);
        $this->assertSame(Referral::DECLINED, $r->status);
    }

    public function test_module_inactive_closes_every_route(): void
    {
        \App\Core\Modules\CompanyModule::where('company_id', $this->a->id)->where('module_key', 'workforce_referral')->update(['is_enabled_for_company' => false]);
        CompanyModuleActivationReason::where('company_id', $this->a->id)->where('module_key', 'workforce_referral')->delete();
        \Illuminate\Support\Facades\Cache::flush();
        $this->api($this->employee, 'GET', '/workforce/referrals/mine')->assertForbidden();
    }
}
