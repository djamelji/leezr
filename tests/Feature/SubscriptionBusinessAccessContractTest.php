<?php

namespace Tests\Feature;

use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-C (ADR-734) — Commercial-state → business-access contract.
 *
 * Formalizes which subscription states cut business access and which keep it,
 * so the grace semantics are guarded explicitly:
 *   - active / trialing (live)                → access
 *   - past_due                                → access (dunning grace, NOT cut on first incident)
 *   - pending / pending_payment               → not cut by THIS gate (onboarding flow governs)
 *   - expired / suspended / cancelled         → cut
 *   - trialing with elapsed trial window      → cut (request-time)
 */
class SubscriptionBusinessAccessContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->company = Company::create([
            'name' => 'Access Contract Co',
            'slug' => 'access-contract-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
    }

    private function sub(string $status, array $extra = []): Subscription
    {
        return Subscription::create(array_merge([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => $status,
            'provider' => 'stripe',
        ], $extra));
    }

    public function test_access_preserving_states_do_not_restrict(): void
    {
        foreach (['active', 'trialing', 'past_due', 'pending', 'pending_payment'] as $status) {
            $this->assertFalse(
                $this->sub($status)->restrictsBusinessAccess(),
                "status '{$status}' must NOT cut business access"
            );
        }
    }

    public function test_grace_past_due_keeps_access_on_first_incident(): void
    {
        // The whole point of dunning grace: a payment incident moves the sub to
        // past_due but business access is retained until final suspension.
        $this->assertFalse(
            $this->sub('past_due')->restrictsBusinessAccess(),
            'past_due retains access during the grace/retry window'
        );
    }

    public function test_terminal_states_restrict_access(): void
    {
        foreach (['expired', 'suspended', 'cancelled'] as $status) {
            $this->assertTrue(
                $this->sub($status)->restrictsBusinessAccess(),
                "status '{$status}' must cut business access"
            );
        }
    }

    public function test_elapsed_trial_window_restricts_even_if_status_trialing(): void
    {
        $this->assertTrue(
            $this->sub('trialing', ['trial_ends_at' => now()->subDay()])->restrictsBusinessAccess(),
            'an elapsed trial window cuts access at request time'
        );

        $this->assertFalse(
            $this->sub('trialing', ['trial_ends_at' => now()->addWeek()])->restrictsBusinessAccess(),
            'a live trial retains access'
        );
    }
}
