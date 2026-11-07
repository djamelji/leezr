<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Realtime\Adapters\SseRealtimePublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-733.2 (P0-D.2) — Isolation du temps réel (SSE, pas de Reverb).
 *  - INTER-tenant : un utilisateur non-membre d'une company ne peut pas ouvrir son flux (SetCompanyContext 403).
 *  - INTRA-tenant : une envelope ciblée (user_id non nul) n'est délivrable qu'à son destinataire (deliverableTo).
 */
class RealtimeSseIsolationP0DTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private User $userOfA;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::firstOrCreate(['key' => 'logistique'], ['label' => 'L', 'default_modules' => []]);
        Market::firstOrCreate(['key' => 'FR'], ['name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $this->a = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $this->b = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $this->userOfA = User::factory()->create();
        Membership::create(['user_id' => $this->userOfA->id, 'company_id' => $this->a->id, 'role' => 'owner']);
        Model::reguard();
    }

    // INTER-TENANT : un membre de A ne peut pas ouvrir le flux SSE de B (403 avant tout stream).
    public function test_user_cannot_open_sse_stream_of_another_company(): void
    {
        $this->actingAs($this->userOfA)
            ->get('/api/realtime/stream', ['X-Company-Id' => $this->b->id])
            ->assertForbidden();
    }

    // INTER-TENANT : via le fallback query ?company_id= (EventSource) aussi → 403.
    public function test_user_cannot_open_sse_stream_of_another_company_via_query(): void
    {
        $this->actingAs($this->userOfA)
            ->get('/api/realtime/stream?company_id='.$this->b->id)
            ->assertForbidden();
    }

    // INTRA-TENANT : une envelope CIBLÉE n'est délivrable qu'à son destinataire.
    public function test_targeted_envelope_delivered_only_to_recipient(): void
    {
        // Envelope personnelle destinée à l'utilisateur 5.
        $this->assertTrue(SseRealtimePublisher::deliverableTo(['user_id' => 5], 5), 'le destinataire reçoit');
        $this->assertFalse(SseRealtimePublisher::deliverableTo(['user_id' => 5], 7), 'un autre membre NE reçoit PAS la notif personnelle');
        $this->assertFalse(SseRealtimePublisher::deliverableTo(['user_id' => '5'], 7), 'robuste au type string');

        // Événement diffusé (invalidation / broadcast company) → tous les membres.
        $this->assertTrue(SseRealtimePublisher::deliverableTo(['user_id' => null], 7), 'broadcast délivré à tous');
        $this->assertTrue(SseRealtimePublisher::deliverableTo([], 7), 'broadcast (champ absent) délivré à tous');
    }
}
