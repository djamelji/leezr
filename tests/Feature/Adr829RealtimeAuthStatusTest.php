<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-829 (D-20) — the SSE endpoints never answer an EventSource with an HTML login redirect :
 * 401 not authenticated, 403 authenticated without access to the company (other tenant). Any /api/* route
 * answers JSON whatever the Accept header. Non-API routes keep their SPA redirect.
 */
class Adr829RealtimeAuthStatusTest extends TestCase
{
    use RefreshDatabase;

    private const SSE = ['Accept' => 'text/event-stream'];

    private function company(string $name): Company
    {
        Model::unguard();
        Jobdomain::firstOrCreate(['key' => 'logistique'], ['label' => 'L', 'default_modules' => []]);
        Market::firstOrCreate(['key' => 'FR'], ['name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $company = Company::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        Model::reguard();

        return $company;
    }

    public function test_unauthenticated_sse_gets_401_json_not_a_login_redirect(): void
    {
        $company = $this->company('Anon');
        foreach (['/api/realtime/stream?company_id='.$company->id, '/api/platform/realtime/stream'] as $url) {
            $r = $this->get($url, self::SSE);
            $r->assertStatus(401);
            $this->assertFalse($r->isRedirect(), $url);
            $this->assertStringContainsString('application/json', (string) $r->headers->get('Content-Type'), $url);
            $r->assertJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_an_authenticated_user_gets_403_on_another_tenant_stream(): void
    {
        $mine = $this->company('Mine');
        $other = $this->company('Other');
        $user = User::factory()->create();
        Membership::create(['user_id' => $user->id, 'company_id' => $mine->id, 'role' => 'owner']);

        $this->actingAs($user)->get('/api/realtime/stream?company_id='.$other->id, self::SSE)->assertStatus(403);
    }

    public function test_api_routes_answer_json_whatever_the_accept_header(): void
    {
        $this->get('/api/nav', ['Accept' => 'text/html', 'X-Company-Id' => '1'])->assertStatus(401);
        $this->get('/api/does-not-exist', ['Accept' => 'text/html'])->assertStatus(404)->assertHeader('Content-Type', 'application/json');
        $this->get('/api/platform/me/notifications', ['Accept' => 'text/html'])->assertStatus(401);
    }

    /** Degraded mode : SSE off (REALTIME_DRIVER != sse) → 204 at once, nothing tracked, no worker held. */
    public function test_sse_disabled_answers_204_without_holding_a_worker(): void
    {
        config(['realtime.driver' => 'null']);
        $company = $this->company('Off');
        $user = User::factory()->create();
        Membership::create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'owner']);
        $this->actingAs($user)->get('/api/realtime/stream?company_id='.$company->id, self::SSE)->assertNoContent();
    }
}
