<?php

namespace Tests\Unit;

use App\Core\Billing\CompanyWallet;
use App\Core\Billing\CompanyWalletTransaction;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->company = Company::create([
            'name' => 'Wallet Co',
            'slug' => 'wallet-co',
        ]);
    }

    // ── Credit ──

    public function test_credit_creates_wallet_and_transaction(): void
    {
        $txn = WalletLedger::credit(
            company: $this->company,
            amount: 5000,
            sourceType: 'admin_adjustment',
            description: 'Initial credit',
        );

        $this->assertEquals('credit', $txn->type);
        $this->assertEquals(5000, $txn->amount);
        $this->assertEquals(5000, $txn->balance_after);

        $wallet = CompanyWallet::where('company_id', $this->company->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(5000, $wallet->cached_balance);
    }

    public function test_multiple_credits_accumulate(): void
    {
        WalletLedger::credit($this->company, 3000, 'admin_adjustment');
        WalletLedger::credit($this->company, 2000, 'admin_adjustment');

        $this->assertEquals(5000, WalletLedger::balance($this->company));
    }

    public function test_credit_rejects_zero_or_negative(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Credit amount must be positive.');

        WalletLedger::credit($this->company, 0, 'admin_adjustment');
    }

    // ── Debit ──

    public function test_debit_reduces_balance(): void
    {
        WalletLedger::credit($this->company, 10000, 'admin_adjustment');
        $txn = WalletLedger::debit($this->company, 3000, 'invoice_payment');

        $this->assertEquals('debit', $txn->type);
        $this->assertEquals(3000, $txn->amount);
        $this->assertEquals(7000, $txn->balance_after);
        $this->assertEquals(7000, WalletLedger::balance($this->company));
    }

    public function test_debit_rejects_insufficient_balance(): void
    {
        WalletLedger::credit($this->company, 1000, 'admin_adjustment');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient wallet balance.');

        WalletLedger::debit($this->company, 2000, 'invoice_payment');
    }

    public function test_debit_allows_negative_when_policy_permits(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['allow_negative_wallet' => true]);

        WalletLedger::credit($this->company, 1000, 'admin_adjustment');
        $txn = WalletLedger::debit($this->company, 2000, 'invoice_payment');

        $this->assertEquals(-1000, $txn->balance_after);
        $this->assertEquals(-1000, WalletLedger::balance($this->company));
    }

    // ── Idempotency ──

    public function test_idempotency_key_prevents_duplicate(): void
    {
        $txn1 = WalletLedger::credit(
            $this->company, 5000, 'refund',
            idempotencyKey: 'refund-42',
        );

        $txn2 = WalletLedger::credit(
            $this->company, 5000, 'refund',
            idempotencyKey: 'refund-42',
        );

        $this->assertEquals($txn1->id, $txn2->id);
        $this->assertEquals(5000, WalletLedger::balance($this->company));
        $this->assertEquals(1, CompanyWalletTransaction::count());
    }

    // ── Invariant: cached_balance = computed ──

    public function test_cached_balance_matches_computed(): void
    {
        WalletLedger::credit($this->company, 10000, 'admin_adjustment');
        WalletLedger::debit($this->company, 3000, 'invoice_payment');
        WalletLedger::credit($this->company, 500, 'refund');

        $wallet = CompanyWallet::where('company_id', $this->company->id)->first();

        $this->assertEquals(7500, $wallet->cached_balance);
        $this->assertEquals(7500, $wallet->computedBalance());
    }

    // ── Edge: balance for company without wallet ──

    public function test_balance_returns_zero_for_new_company(): void
    {
        $this->assertEquals(0, WalletLedger::balance($this->company));
    }

    // ── ensureWallet ──

    public function test_ensure_wallet_creates_once(): void
    {
        $w1 = WalletLedger::ensureWallet($this->company);
        $w2 = WalletLedger::ensureWallet($this->company);

        $this->assertEquals($w1->id, $w2->id);
        $this->assertEquals(1, CompanyWallet::count());
    }
}
