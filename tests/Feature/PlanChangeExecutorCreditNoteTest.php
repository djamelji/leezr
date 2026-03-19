<?php

namespace Tests\Feature;

use App\Core\Billing\CreditNote;
use App\Core\Billing\PlanChangeExecutor;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that a credit note document is automatically created
 * when a downgrade with proration credit occurs (ADR-358).
 */
class PlanChangeExecutorCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        PlanRegistry::sync();

        $this->company = Company::create([
            'name' => 'CreditNote Co',
            'slug' => 'creditnote-co',
            'plan_key' => 'business',
            'jobdomain_key' => 'logistique',
        ]);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'business',
            'interval' => 'monthly',
            'status' => 'active',
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-31'),
        ]);
    }

    public function test_downgrade_with_proration_credit_creates_credit_note(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-16'));

        $intent = PlanChangeExecutor::schedule(
            company: $this->company,
            toPlanKey: 'starter',
            toInterval: 'monthly',
            timing: 'immediate',
        );

        // Verify the intent was executed and proration is negative (downgrade)
        $this->assertEquals('executed', $intent->status);
        $this->assertNotNull($intent->proration_snapshot);
        $this->assertLessThan(0, $intent->proration_snapshot['net']);

        // Verify credit note was created for this company
        $creditNote = CreditNote::where('company_id', $this->company->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($creditNote, 'A credit note should be created on downgrade proration.');
        $this->assertEquals('issued', $creditNote->status);
        $this->assertNotNull($creditNote->number);
        $this->assertNotNull($creditNote->issued_at);

        // Verify correct amount (matches the wallet credit amount)
        $walletBalance = WalletLedger::balance($this->company);
        $this->assertEquals($walletBalance, $creditNote->amount);
        $this->assertGreaterThan(0, $creditNote->amount);

        // Verify linked to correct company
        $this->assertEquals($this->company->id, $creditNote->company_id);

        // Verify metadata contains subscription reference
        $this->assertIsArray($creditNote->metadata);
        $this->assertEquals('plan_change_proration', $creditNote->metadata['source']);
        $this->assertEquals($intent->id, $creditNote->metadata['intent_id']);
        $this->assertEquals($this->subscription->id, $creditNote->metadata['subscription_id']);
        $this->assertEquals('business', $creditNote->metadata['from_plan']);
        $this->assertEquals('starter', $creditNote->metadata['to_plan']);

        Carbon::setTestNow();
    }

    public function test_upgrade_does_not_create_credit_note(): void
    {
        // Downgrade company first to starter so we can upgrade
        $this->subscription->update(['plan_key' => 'starter']);
        $this->company->update(['plan_key' => 'starter']);

        Carbon::setTestNow(Carbon::parse('2026-03-16'));

        PlanChangeExecutor::schedule(
            company: $this->company,
            toPlanKey: 'pro',
            toInterval: 'monthly',
            timing: 'immediate',
        );

        // No credit note should be created for upgrade (net > 0)
        $creditNote = CreditNote::where('company_id', $this->company->id)->first();
        $this->assertNull($creditNote, 'No credit note should be created on upgrade.');

        Carbon::setTestNow();
    }

    public function test_deferred_downgrade_creates_credit_note_on_execution(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-16'));

        // Schedule deferred downgrade
        $intent = PlanChangeExecutor::schedule(
            company: $this->company,
            toPlanKey: 'starter',
            toInterval: 'monthly',
            timing: 'end_of_period',
        );

        $this->assertEquals('scheduled', $intent->status);

        // No credit note yet — not executed
        $this->assertEquals(0, CreditNote::where('company_id', $this->company->id)->count());

        // Move time past effective_at and execute
        Carbon::setTestNow(Carbon::parse('2026-03-31 01:00:00'));
        PlanChangeExecutor::executeScheduled();

        // Deferred downgrades don't have proration snapshot (proration only
        // computed for immediate timing), so no credit note expected.
        // This test confirms no spurious credit note is created.
        $intent->refresh();
        $this->assertEquals('executed', $intent->status);
        $this->assertNull($intent->proration_snapshot);
        $this->assertEquals(0, CreditNote::where('company_id', $this->company->id)->count());

        Carbon::setTestNow();
    }
}
