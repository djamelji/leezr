<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Registration\RegistrationFunnelEvent;
use App\Modules\Platform\Dashboard\OnboardingFunnelService;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-849 (Leezr 4.2 M10) — measured public registration funnel : real steps per journey, idempotent, no personal
 * data, completion written by the server, activation and first sign-in derived from existing state, abandonment =
 * inactivity window (never a click or a missing datum), Platform creations excluded, view_usage_monitoring.
 */
class Adr849RegistrationFunnelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
    }

    private function track(string $funnel, string $step, array $extra = [])
    {
        return $this->postJson('/api/register/funnel-track', ['funnel_id' => $funnel, 'step' => $step] + $extra);
    }

    private function event(string $funnel, string $step, string $at, ?int $companyId = null): void
    {
        RegistrationFunnelEvent::create(['funnel_id' => $funnel, 'session_id' => $funnel, 'step' => $step, 'company_id' => $companyId])
            ->forceFill(['created_at' => Carbon::parse($at), 'updated_at' => Carbon::parse($at)])->save();
    }

    public function test_tracking_is_idempotent_bounded_and_keeps_no_personal_data(): void
    {
        $id = (string) Str::uuid();
        $this->track($id, 'started', ['source' => 'https://www.search.example/q?email=jane%40doe.test&token=abc'])->assertOk()->assertJsonPath('duplicate', false);
        $this->track($id, 'started', ['source' => 'https://other.example/'])->assertOk()->assertJsonPath('duplicate', true);
        $this->track($id, 'plan', ['email' => 'jane@doe.test', 'password' => 'secret', 'company_name' => 'Doe SA'])->assertOk();

        $this->assertSame(2, RegistrationFunnelEvent::where('funnel_id', $id)->count(), 'one row per journey and step');
        $this->assertSame(['source' => 'www.search.example'], RegistrationFunnelEvent::where('funnel_id', $id)->where('step', 'started')->value('metadata'), 'host only, never path or query');
        $stored = json_encode(RegistrationFunnelEvent::all()->toArray());
        foreach (['jane', 'secret', 'Doe SA', 'token'] as $leak) {
            $this->assertStringNotContainsString($leak, $stored);
        }

        $this->track('not-a-uuid', 'started')->assertUnprocessable();
        $this->track($id, 'completed')->assertUnprocessable(); // completion is written by the server only
        $this->track($id, 'payment_info')->assertUnprocessable(); // legacy vocabulary, not a real step
    }

    public function test_public_registration_completes_the_same_journey(): void
    {
        $id = (string) Str::uuid();
        foreach (['started', 'industry', 'plan', 'addons', 'company', 'account'] as $step) {
            $this->track($id, $step)->assertOk();
        }
        $companyId = $this->postJson('/api/register', ['first_name' => 'R', 'last_name' => 'U', 'email' => 'funnel@example.test', 'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong', 'company_name' => 'Funnel SA', 'jobdomain_key' => 'logistique', 'dynamic_fields' => ['siret' => '12345678901234'],
            'funnel_id' => $id])->assertCreated()->json('company.id');

        $done = RegistrationFunnelEvent::where('funnel_id', $id)->where('step', 'completed')->firstOrFail();
        $this->assertSame($companyId, $done->company_id);
        $stats = app(OnboardingFunnelService::class)->analytics(30);
        $this->assertSame(1, $stats['steps']['started']);
        $this->assertSame(1, $stats['steps']['account']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(0, $stats['abandoned']);
    }

    public function test_outcomes_activation_first_login_in_progress_and_abandonment(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $company = Company::create(['name' => 'Activée', 'slug' => 'activee-849', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        DB::table('subscriptions')->insert(['company_id' => $company->id, 'plan_key' => 'starter', 'interval' => 'monthly', 'status' => 'active', 'is_current' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->event('a', 'started', '2026-11-18 10:00');
        $this->event('a', 'account', '2026-11-18 10:10');
        $this->event('a', 'completed', '2026-11-18 10:11', $company->id);
        DB::table('company_audit_logs')->insert(['id' => (string) Str::ulid(), 'company_id' => $company->id, 'actor_id' => $owner->id, 'actor_type' => 'user', 'action' => 'auth.login', 'severity' => 'info', 'created_at' => '2026-11-19 09:00']);

        $this->event('b', 'started', '2026-11-20 09:00'); // 3 h ago : in progress, never an abandonment yet
        $this->event('b', 'plan', '2026-11-20 10:00');

        $this->event('c', 'started', '2026-11-18 08:00'); // inactive for 50 h : abandoned at « plan »
        $this->event('c', 'industry', '2026-11-18 08:05');
        $this->event('c', 'plan', '2026-11-18 08:06');

        $this->event('old', 'started', '2026-09-01 08:00'); // outside the period

        $stats = app(OnboardingFunnelService::class)->analytics(30);
        $this->assertSame(3, $stats['steps']['started']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['activated']);
        $this->assertSame(1, $stats['first_login']);
        $this->assertSame(1, $stats['in_progress']);
        $this->assertSame(1, $stats['abandoned']);
        $this->assertSame(['plan' => 1], $stats['abandoned_at_step']);
        $this->assertSame(24, $stats['abandon_after_hours']);

        config(['registration_funnel.abandon_after_hours' => 72]); // a longer window : nothing abandoned yet
        $this->assertSame(0, app(OnboardingFunnelService::class)->analytics(30)['abandoned']);
        Carbon::setTestNow();
    }

    public function test_only_the_public_registration_writes_the_funnel_and_platform_needs_the_permission(): void
    {
        $writers = collect(\Illuminate\Support\Facades\File::allFiles(app_path()))
            ->filter(fn ($f) => preg_match('/RegistrationFunnelEvent::(create|firstOrCreate|insert|updateOrCreate)/', $f->getContents()))
            ->map(fn ($f) => $f->getFilename())->values()->all();
        sort($writers);
        $this->assertSame(['FunnelTrackController.php', 'RegisterCompanyUseCase.php'], $writers, 'Platform company creation never writes the public funnel');

        $mk = function (array $perms) {
            $u = PlatformUser::create(['first_name' => 'Op', 'last_name' => 'F', 'email' => Str::lower(Str::random(8)).'@platform.test', 'password' => 'P@ssw0rd!Strong']);
            $r = PlatformRole::create(['key' => 'r'.Str::lower(Str::random(6)), 'name' => 'R '.Str::random(4)]);
            $r->permissions()->sync(PlatformPermission::whereIn('key', $perms)->pluck('id')->all());
            $u->roles()->attach($r);

            return $u;
        };
        $this->actingAs($mk(['view_companies']), 'platform')->getJson('/api/platform/onboarding/funnel')->assertForbidden();
        $this->actingAs($mk(['view_usage_monitoring']), 'platform')->getJson('/api/platform/onboarding/funnel?days=500')->assertOk()->assertJsonPath('period_days', 90);
    }
}
