<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Payment;
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
 * ADR-135 D2a — Platform Admin Invoice Mutations E2E tests.
 *
 * Covers: mark-paid-offline, void, update-notes.
 * Auth, idempotency, guards, audit, wallet-credit-reversal.
 */
class AdminInvoiceMutationTest extends TestCase
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
            'first_name' => 'Invoice',
            'last_name' => 'Admin',
            'email' => 'invoice-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);

        // Company with subscription
        $owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
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

    private function createFinalizedInvoice(int $amount = 2900, int $walletCredit = 0): Invoice
    {
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', $amount);

        $invoice = InvoiceIssuer::finalize($invoice);

        if ($walletCredit > 0) {
            $invoice->update([
                'wallet_credit_applied' => $walletCredit,
                'amount_due' => $invoice->amount - $walletCredit,
            ]);
            $invoice->refresh();
        }

        return $invoice;
    }

    // ═══════════════════════════════════════════════════════════
    // AUTH & PERMISSION
    // ═══════════════════════════════════════════════════════════

    public function test_unauthenticated_returns_401(): void
    {
        $this->putJson('/api/platform/billing/invoices/1/mark-paid-offline', [
            'idempotency_key' => 'test',
        ])->assertStatus(401);
    }

    public function test_viewer_cannot_mark_paid_offline(): void
    {
        $viewer = PlatformUser::create([
            'first_name' => 'View',
            'last_name' => 'Only',
            'email' => 'viewer@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $viewerRole = PlatformRole::where('key', 'viewer')->first();

        if ($viewerRole) {
            $viewer->roles()->attach($viewerRole);
        }

        $invoice = $this->createFinalizedInvoice();

        $this->actingAs($viewer, 'platform')
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/mark-paid-offline", [
                'idempotency_key' => 'test-key',
            ])
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════
    // MARK PAID OFFLINE
    // ═══════════════════════════════════════════════════════════

    public function test_mark_paid_offline_success(): void
    {
        $invoice = $this->createFinalizedInvoice(2900);

        $response = $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/mark-paid-offline", [
                'idempotency_key' => 'mpk-001',
            ]);

        $response->assertOk()
            ->assertJsonPath('invoice.status', 'paid')
            ->assertJsonPath('message', 'Invoice marked as paid offline.');

        // Verify invoice DB state
        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        // Verify Payment created
        $payment = Payment::where('company_id', $this->company->id)
            ->where('provider', 'offline')
            ->first();

        $this->assertNotNull($payment);
        $this->assertEquals('succeeded', $payment->status);
        $this->assertEquals($invoice->amount_due, $payment->amount);
        $this->assertEquals($invoice->id, $payment->metadata['invoice_id']);

        // Verify audit log
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::INVOICE_MARKED_PAID,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    public function test_mark_paid_offline_idempotent_replay(): void
    {
        $invoice = $this->createFinalizedInvoice();

        // First call
        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/mark-paid-offline", [
                'idempotency_key' => 'mpk-idem',
            ])
            ->assertOk();

        // Second call — same key
        $response = $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/mark-paid-offline", [
                'idempotency_key' => 'mpk-idem',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Already processed (idempotent replay).');

        // Only one Payment created
        $paymentCount = Payment::where('company_id', $this->company->id)
            ->where('provider', 'offline')
            ->count();

        $this->assertEquals(1, $paymentCount);
    }

    public function test_mark_paid_offline_rejects_already_paid_different_key(): void
    {
        $invoice = $this->createFinalizedInvoice();

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/mark-paid-offline", [
                'idempotency_key' => 'key-a',
            ])
            ->assertOk();

        // Different key on already-paid invoice
        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/mark-paid-offline", [
                'idempotency_key' => 'key-b',
            ])
            ->assertStatus(409);
    }

    public function test_mark_paid_offline_rejects_draft_invoice(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/mark-paid-offline", [
                'idempotency_key' => 'draft-test',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice must be finalized.');
    }

    public function test_mark_paid_offline_rejects_voided_invoice(): void
    {
        $invoice = $this->createFinalizedInvoice();
        $invoice->update(['status' => 'void', 'voided_at' => now()]);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/mark-paid-offline", [
                'idempotency_key' => 'voided-test',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice is already voided.');
    }

    public function test_mark_paid_offline_requires_idempotency_key(): void
    {
        $invoice = $this->createFinalizedInvoice();

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/mark-paid-offline", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_mark_paid_offline_404_for_missing_invoice(): void
    {
        $this->actAsPlatform()
            ->putJson('/api/platform/billing/invoices/999999/mark-paid-offline', [
                'idempotency_key' => 'missing-test',
            ])
            ->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════════
    // VOID
    // ═══════════════════════════════════════════════════════════

    public function test_void_success(): void
    {
        $invoice = $this->createFinalizedInvoice();

        $response = $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/void", [
                'idempotency_key' => 'void-001',
            ]);

        $response->assertOk()
            ->assertJsonPath('invoice.status', 'void')
            ->assertJsonPath('message', 'Invoice voided.');

        $invoice->refresh();
        $this->assertEquals('void', $invoice->status);
        $this->assertNotNull($invoice->voided_at);

        // Verify audit log
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::INVOICE_VOIDED,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    public function test_void_idempotent_replay(): void
    {
        $invoice = $this->createFinalizedInvoice();

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/void", [
                'idempotency_key' => 'void-idem',
            ])
            ->assertOk();

        // Second call — same key
        $response = $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/void", [
                'idempotency_key' => 'void-idem',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Already processed (idempotent replay).');
    }

    public function test_void_rejects_paid_invoice(): void
    {
        $invoice = $this->createFinalizedInvoice();
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/void", [
                'idempotency_key' => 'void-paid',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot void a paid invoice. Refund first.');
    }

    public function test_void_rejects_draft_invoice(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/void", [
                'idempotency_key' => 'void-draft',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice must be finalized.');
    }

    public function test_void_with_wallet_credit_issues_credit_note(): void
    {
        // Ensure wallet exists first
        WalletLedger::credit(
            company: $this->company,
            amount: 1000,
            sourceType: 'admin_adjustment',
            description: 'Test credit',
        );

        $invoice = $this->createFinalizedInvoice(2900, walletCredit: 500);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/void", [
                'idempotency_key' => 'void-wallet',
            ])
            ->assertOk();

        // Credit note should be created and applied
        $creditNote = CreditNote::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($creditNote);
        $this->assertEquals(500, $creditNote->amount);
        $this->assertEquals('applied', $creditNote->status);
        $this->assertNotNull($creditNote->wallet_transaction_id);
    }

    public function test_void_without_wallet_credit_no_credit_note(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, walletCredit: 0);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/void", [
                'idempotency_key' => 'void-no-wallet',
            ])
            ->assertOk();

        $creditNoteCount = CreditNote::where('invoice_id', $invoice->id)->count();
        $this->assertEquals(0, $creditNoteCount);
    }

    public function test_void_requires_idempotency_key(): void
    {
        $invoice = $this->createFinalizedInvoice();

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/void", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    // ═══════════════════════════════════════════════════════════
    // UPDATE NOTES
    // ═══════════════════════════════════════════════════════════

    public function test_update_notes_success(): void
    {
        $invoice = $this->createFinalizedInvoice();

        $response = $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/notes", [
                'notes' => 'Customer called to confirm payment.',
            ]);

        $response->assertOk()
            ->assertJsonPath('invoice.notes', 'Customer called to confirm payment.')
            ->assertJsonPath('message', 'Invoice notes updated.');

        // Verify audit log
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::INVOICE_NOTES_UPDATED,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    public function test_update_notes_no_change_skips_audit(): void
    {
        $invoice = $this->createFinalizedInvoice();
        $invoice->update(['notes' => 'Existing note.']);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/notes", [
                'notes' => 'Existing note.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'No change.');

        $this->assertDatabaseMissing('platform_audit_logs', [
            'action' => AuditAction::INVOICE_NOTES_UPDATED,
        ]);
    }

    public function test_update_notes_can_clear_notes(): void
    {
        $invoice = $this->createFinalizedInvoice();
        $invoice->update(['notes' => 'Some note.']);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/notes", [
                'notes' => null,
            ])
            ->assertOk()
            ->assertJsonPath('invoice.notes', null);
    }

    public function test_update_notes_rejects_draft_invoice(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/notes", [
                'notes' => 'Test note.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice must be finalized.');
    }

    public function test_update_notes_rejects_voided_invoice(): void
    {
        $invoice = $this->createFinalizedInvoice();
        $invoice->update(['status' => 'void', 'voided_at' => now()]);

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/notes", [
                'notes' => 'Test note.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice is already voided.');
    }

    public function test_update_notes_validates_max_length(): void
    {
        $invoice = $this->createFinalizedInvoice();

        $this->actAsPlatform()
            ->putJson("/api/platform/billing/invoices/{$invoice->id}/notes", [
                'notes' => str_repeat('x', 2001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_update_notes_404_for_missing_invoice(): void
    {
        $this->actAsPlatform()
            ->putJson('/api/platform/billing/invoices/999999/notes', [
                'notes' => 'Test.',
            ])
            ->assertStatus(404);
    }
}
