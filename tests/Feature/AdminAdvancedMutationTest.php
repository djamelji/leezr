<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-136 D2c — Advanced Platform Admin Invoice Mutations E2E tests.
 *
 * Covers: refund, retry-payment, dunning-transition, credit-note, write-off.
 * Auth, idempotency, guards, audit, state machine transitions.
 */
class AdminAdvancedMutationTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;
    private Company $company;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        // Platform admin with super_admin role
        $this->admin = PlatformUser::create([
            'first_name' => 'Advanced',
            'last_name' => 'Admin',
            'email' => 'advanced-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);

        // Company with subscription
        $owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Test Co',
            'slug' => 'test-co-adv',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);
    }

    private function actAsPlatform(): static
    {
        return $this->actingAs($this->admin, 'platform');
    }

    private function createFinalizedInvoice(int $amount = 2900, string $status = 'open'): Invoice
    {
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', $amount);

        $invoice = InvoiceIssuer::finalize($invoice);

        if ($status !== 'open' && $status !== $invoice->status) {
            $updates = ['status' => $status];

            if ($status === 'paid') {
                $updates['paid_at'] = now();
            } elseif ($status === 'overdue') {
                $updates['next_retry_at'] = now()->addDay();
            }

            $invoice->update($updates);
            $invoice->refresh();
        }

        return $invoice;
    }

    private function createViewer(): PlatformUser
    {
        $viewer = PlatformUser::create([
            'first_name' => 'View',
            'last_name' => 'Only',
            'email' => 'viewer-adv@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $viewerRole = PlatformRole::where('key', 'viewer')->first();

        if ($viewerRole) {
            $viewer->roles()->attach($viewerRole);
        }

        return $viewer;
    }

    // ═══════════════════════════════════════════════════════════
    // AUTH — ALL 5 ENDPOINTS
    // ═══════════════════════════════════════════════════════════

    public function test_refund_requires_manage_billing(): void
    {
        $viewer = $this->createViewer();
        $invoice = $this->createFinalizedInvoice(2900, 'paid');

        $this->actingAs($viewer, 'platform')
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'Test',
                'idempotency_key' => 'auth-test',
            ])
            ->assertStatus(403);
    }

    public function test_retry_payment_requires_manage_billing(): void
    {
        $viewer = $this->createViewer();
        $invoice = $this->createFinalizedInvoice(2900, 'overdue');

        $this->actingAs($viewer, 'platform')
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/retry-payment", [
                'idempotency_key' => 'auth-test',
            ])
            ->assertStatus(403);
    }

    public function test_dunning_transition_requires_manage_billing(): void
    {
        $viewer = $this->createViewer();
        $invoice = $this->createFinalizedInvoice();

        $this->actingAs($viewer, 'platform')
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/dunning-transition", [
                'target_status' => 'overdue',
                'idempotency_key' => 'auth-test',
            ])
            ->assertStatus(403);
    }

    public function test_credit_note_requires_manage_billing(): void
    {
        $viewer = $this->createViewer();
        $invoice = $this->createFinalizedInvoice();

        $this->actingAs($viewer, 'platform')
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/credit-note", [
                'amount' => 500,
                'reason' => 'Test',
                'apply_to_wallet' => false,
                'idempotency_key' => 'auth-test',
            ])
            ->assertStatus(403);
    }

    public function test_write_off_requires_manage_billing(): void
    {
        $viewer = $this->createViewer();
        $invoice = $this->createFinalizedInvoice(2900, 'overdue');

        $this->actingAs($viewer, 'platform')
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/write-off", [
                'idempotency_key' => 'auth-test',
            ])
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════
    // REFUND
    // ═══════════════════════════════════════════════════════════

    public function test_refund_creates_issued_credit_note(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'paid');

        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'Partial refund per customer request',
                'idempotency_key' => 'refund-001',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Refund credit note issued.')
            ->assertJsonPath('credit_note.status', 'issued')
            ->assertJsonPath('credit_note.amount', 1000);

        // CreditNote exists with correct metadata
        $cn = CreditNote::where('invoice_id', $invoice->id)
            ->whereJsonContains('metadata->type', 'refund')
            ->first();

        $this->assertNotNull($cn);
        $this->assertEquals(1000, $cn->amount);
        $this->assertEquals('issued', $cn->status);
        $this->assertNotNull($cn->number);
        $this->assertNull($cn->wallet_transaction_id); // Not applied in V1

        // Audit log
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::BILLING_REFUND,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    public function test_refund_idempotent_replay(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'paid');

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'First refund',
                'idempotency_key' => 'refund-idem',
            ])
            ->assertOk();

        // Second call — same key
        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'First refund',
                'idempotency_key' => 'refund-idem',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Already processed (idempotent replay).');

        // Only one CreditNote created
        $count = CreditNote::where('invoice_id', $invoice->id)
            ->whereJsonContains('metadata->type', 'refund')
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_refund_rejects_unpaid_invoice(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'open');

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'Should fail',
                'idempotency_key' => 'refund-unpaid',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only paid invoices can be refunded.');
    }

    public function test_refund_rejects_amount_exceeding_total(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'paid');

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => $invoice->amount + 1,
                'reason' => 'Too much',
                'idempotency_key' => 'refund-exceed',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Refund amount exceeds invoice total.');
    }

    public function test_refund_cumulative_guard(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'paid');

        // First refund: 2000
        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 2000,
                'reason' => 'Partial refund 1',
                'idempotency_key' => 'refund-cum-1',
            ])
            ->assertOk();

        // Second refund: 1000 (would exceed 2900 total: 2000 + 1000 > 2900)
        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'Partial refund 2',
                'idempotency_key' => 'refund-cum-2',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cumulative refund amount exceeds invoice total.');
    }

    // ═══════════════════════════════════════════════════════════
    // RETRY PAYMENT
    // ═══════════════════════════════════════════════════════════

    public function test_retry_payment_succeeds_with_wallet_balance(): void
    {
        // Fund the wallet
        WalletLedger::credit(
            company: $this->company,
            amount: 5000,
            sourceType: 'admin_adjustment',
            description: 'Test credit',
        );

        $invoice = $this->createFinalizedInvoice(2900, 'overdue');

        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/retry-payment", [
                'idempotency_key' => 'retry-001',
            ]);

        $response->assertOk()
            ->assertJsonPath('result', 'paid')
            ->assertJsonPath('invoice.status', 'paid');

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);

        // Audit log
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::DUNNING_FORCE_RETRY,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    public function test_retry_payment_reschedules_without_balance(): void
    {
        // No wallet balance — retry should reschedule
        $invoice = $this->createFinalizedInvoice(2900, 'overdue');

        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/retry-payment", [
                'idempotency_key' => 'retry-no-bal',
            ]);

        $response->assertOk();

        $invoice->refresh();
        $result = $response->json('result');

        // Should be either 'retried' (rescheduled) or 'exhausted' depending on retry_count
        $this->assertContains($result, ['retried', 'exhausted']);
    }

    public function test_retry_payment_rejects_non_overdue(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'open');

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/retry-payment", [
                'idempotency_key' => 'retry-open',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only overdue invoices can be retried.');
    }

    public function test_retry_payment_idempotent_on_paid(): void
    {
        // Fund wallet generously
        WalletLedger::credit(
            company: $this->company,
            amount: 10000,
            sourceType: 'admin_adjustment',
            description: 'Test credit',
        );

        $invoice = $this->createFinalizedInvoice(2900, 'overdue');

        // First retry — pays the invoice
        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/retry-payment", [
                'idempotency_key' => 'retry-idem-1',
            ])
            ->assertOk()
            ->assertJsonPath('result', 'paid');

        // Second retry — invoice is now paid, should reject
        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/retry-payment", [
                'idempotency_key' => 'retry-idem-2',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only overdue invoices can be retried.');
    }

    // ═══════════════════════════════════════════════════════════
    // DUNNING TRANSITION
    // ═══════════════════════════════════════════════════════════

    public function test_dunning_open_to_overdue(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'open');

        $response = $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/dunning-transition", [
                'target_status' => 'overdue',
                'idempotency_key' => 'dun-001',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Dunning transition applied.')
            ->assertJsonPath('invoice.status', 'overdue');

        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);
        $this->assertNotNull($invoice->next_retry_at);

        // Subscription should be past_due
        $this->subscription->refresh();
        $this->assertEquals('past_due', $this->subscription->status);

        // Audit log
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::INVOICE_DUNNING_FORCED,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    public function test_dunning_overdue_to_uncollectible_applies_failure_action(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'overdue');

        // Set subscription to past_due (normal state when overdue)
        $this->subscription->update(['status' => 'past_due']);

        $response = $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/dunning-transition", [
                'target_status' => 'uncollectible',
                'idempotency_key' => 'dun-uncoll',
            ]);

        $response->assertOk()
            ->assertJsonPath('invoice.status', 'uncollectible');

        $invoice->refresh();
        $this->assertEquals('uncollectible', $invoice->status);
        $this->assertNull($invoice->next_retry_at);

        // Failure action should have been applied
        $this->company->refresh();
        $this->subscription->refresh();

        // Default failure_action is 'suspend'
        $this->assertEquals('suspended', $this->company->status);
    }

    public function test_dunning_rejects_invalid_transition(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'paid');

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/dunning-transition", [
                'target_status' => 'overdue',
                'idempotency_key' => 'dun-invalid',
            ])
            ->assertStatus(409);
    }

    public function test_dunning_idempotent_replay(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'open');

        // First call
        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/dunning-transition", [
                'target_status' => 'overdue',
                'idempotency_key' => 'dun-idem',
            ])
            ->assertOk();

        // Second call — already overdue
        $response = $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/dunning-transition", [
                'target_status' => 'overdue',
                'idempotency_key' => 'dun-idem',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Already processed (idempotent replay).');
    }

    public function test_dunning_transition_rejects_paid_invoice(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'paid');

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/dunning-transition", [
                'target_status' => 'uncollectible',
                'idempotency_key' => 'dun-paid',
            ])
            ->assertStatus(409);
    }

    // ═══════════════════════════════════════════════════════════
    // CREDIT NOTE
    // ═══════════════════════════════════════════════════════════

    public function test_credit_note_issued_without_wallet(): void
    {
        $invoice = $this->createFinalizedInvoice(2900);

        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/credit-note", [
                'amount' => 500,
                'reason' => 'Goodwill adjustment',
                'apply_to_wallet' => false,
                'idempotency_key' => 'cn-001',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Credit note issued.')
            ->assertJsonPath('credit_note.status', 'issued')
            ->assertJsonPath('credit_note.amount', 500);

        $cn = CreditNote::where('invoice_id', $invoice->id)
            ->whereJsonContains('metadata->type', 'manual')
            ->first();

        $this->assertNotNull($cn);
        $this->assertEquals('issued', $cn->status);
        $this->assertNull($cn->wallet_transaction_id);

        // Audit log
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::CREDIT_NOTE_MANUAL,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    public function test_credit_note_issued_and_applied_to_wallet(): void
    {
        $invoice = $this->createFinalizedInvoice(2900);

        $balanceBefore = WalletLedger::balance($this->company);

        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/credit-note", [
                'amount' => 500,
                'reason' => 'Wallet credit compensation',
                'apply_to_wallet' => true,
                'idempotency_key' => 'cn-wallet',
            ]);

        $response->assertOk()
            ->assertJsonPath('credit_note.status', 'applied');

        $cn = CreditNote::where('invoice_id', $invoice->id)
            ->whereJsonContains('metadata->type', 'manual')
            ->first();

        $this->assertNotNull($cn);
        $this->assertEquals('applied', $cn->status);
        $this->assertNotNull($cn->wallet_transaction_id);

        // Wallet should have increased
        $balanceAfter = WalletLedger::balance($this->company);
        $this->assertEquals($balanceBefore + 500, $balanceAfter);
    }

    public function test_credit_note_idempotent_replay(): void
    {
        $invoice = $this->createFinalizedInvoice(2900);

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/credit-note", [
                'amount' => 500,
                'reason' => 'First CN',
                'apply_to_wallet' => false,
                'idempotency_key' => 'cn-idem',
            ])
            ->assertOk();

        // Second call — same key
        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/credit-note", [
                'amount' => 500,
                'reason' => 'First CN',
                'apply_to_wallet' => false,
                'idempotency_key' => 'cn-idem',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Already processed (idempotent replay).');

        // Only one CN
        $count = CreditNote::where('invoice_id', $invoice->id)->count();
        $this->assertEquals(1, $count);
    }

    public function test_credit_note_rejects_voided_invoice(): void
    {
        $invoice = $this->createFinalizedInvoice(2900);
        $invoice->update(['status' => 'void', 'voided_at' => now()]);

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/credit-note", [
                'amount' => 500,
                'reason' => 'Should fail',
                'apply_to_wallet' => false,
                'idempotency_key' => 'cn-voided',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice is already voided.');
    }

    // ═══════════════════════════════════════════════════════════
    // WRITE-OFF
    // ═══════════════════════════════════════════════════════════

    public function test_write_off_marks_uncollectible(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'overdue');

        $response = $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/write-off", [
                'idempotency_key' => 'wo-001',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Invoice written off.')
            ->assertJsonPath('invoice.status', 'uncollectible');

        $invoice->refresh();
        $this->assertEquals('uncollectible', $invoice->status);
        $this->assertNull($invoice->next_retry_at);

        // Audit log
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::INVOICE_WRITTEN_OFF,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    public function test_write_off_does_not_apply_failure_action(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'overdue');

        // Set subscription to past_due
        $this->subscription->update(['status' => 'past_due']);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/write-off", [
                'idempotency_key' => 'wo-no-fa',
            ])
            ->assertOk();

        // Company should NOT be suspended (write-off = pure accounting)
        $this->company->refresh();
        $this->assertEquals('active', $this->company->status);

        // Subscription should NOT change status
        $this->subscription->refresh();
        $this->assertEquals('past_due', $this->subscription->status);
    }

    public function test_write_off_idempotent_replay(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'overdue');

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/write-off", [
                'idempotency_key' => 'wo-idem',
            ])
            ->assertOk();

        // Second call — already uncollectible
        $response = $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/write-off", [
                'idempotency_key' => 'wo-idem',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Already processed (idempotent replay).');
    }

    public function test_write_off_rejects_non_overdue(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'open');

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/write-off", [
                'idempotency_key' => 'wo-open',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only overdue invoices can be written off.');
    }

    // ═══════════════════════════════════════════════════════════
    // AUDIT — ALL MUTATIONS
    // ═══════════════════════════════════════════════════════════

    public function test_all_mutations_create_audit_logs(): void
    {
        // 1. Refund
        $paidInvoice = $this->createFinalizedInvoice(2900, 'paid');

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$paidInvoice->id}/refund", [
                'amount' => 500,
                'reason' => 'Audit test refund',
                'idempotency_key' => 'audit-refund',
            ])
            ->assertOk();

        // 2. Retry payment (need wallet for this to produce a result)
        $overdueInvoice1 = $this->createFinalizedInvoice(2900, 'overdue');

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$overdueInvoice1->id}/retry-payment", [
                'idempotency_key' => 'audit-retry',
            ])
            ->assertOk();

        // 3. Dunning transition
        $openInvoice = $this->createFinalizedInvoice(2900, 'open');

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$openInvoice->id}/dunning-transition", [
                'target_status' => 'overdue',
                'idempotency_key' => 'audit-dunning',
            ])
            ->assertOk();

        // 4. Credit note
        $invoiceForCn = $this->createFinalizedInvoice(2900, 'open');

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoiceForCn->id}/credit-note", [
                'amount' => 100,
                'reason' => 'Audit test CN',
                'apply_to_wallet' => false,
                'idempotency_key' => 'audit-cn',
            ])
            ->assertOk();

        // 5. Write-off
        $overdueInvoice2 = $this->createFinalizedInvoice(2900, 'overdue');

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$overdueInvoice2->id}/write-off", [
                'idempotency_key' => 'audit-wo',
            ])
            ->assertOk();

        // Verify all audit actions exist
        $this->assertDatabaseHas('platform_audit_logs', ['action' => AuditAction::BILLING_REFUND]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => AuditAction::DUNNING_FORCE_RETRY]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => AuditAction::INVOICE_DUNNING_FORCED]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => AuditAction::CREDIT_NOTE_MANUAL]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => AuditAction::INVOICE_WRITTEN_OFF]);
    }
}
