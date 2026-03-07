<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Wallet ledger — credit/debit operations with SELECT FOR UPDATE locking.
 *
 * Source of truth: company_wallet_transactions.
 * cached_balance is recomputed on every write within the lock.
 *
 * Invariants:
 *   - cached_balance = SUM(credits) - SUM(debits)
 *   - cached_balance >= 0 (unless allow_negative_wallet)
 *   - Idempotency via idempotency_key (duplicate writes are silently skipped)
 */
class WalletLedger
{
    /**
     * Ensure a wallet exists for the company. Creates one if not.
     */
    public static function ensureWallet(Company $company, ?string $currency = null): CompanyWallet
    {
        return CompanyWallet::firstOrCreate(
            ['company_id' => $company->id],
            ['currency' => $currency ?? $company->market?->currency ?? config('billing.default_currency', 'EUR'), 'cached_balance' => 0],
        );
    }

    /**
     * Credit the wallet (money in).
     *
     * @return CompanyWalletTransaction The created transaction
     */
    public static function credit(
        Company $company,
        int $amount,
        string $sourceType,
        ?int $sourceId = null,
        ?string $description = null,
        ?string $actorType = null,
        ?int $actorId = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): CompanyWalletTransaction {
        if ($amount <= 0) {
            throw new RuntimeException('Credit amount must be positive.');
        }

        return static::record(
            company: $company,
            type: 'credit',
            amount: $amount,
            sourceType: $sourceType,
            sourceId: $sourceId,
            description: $description,
            actorType: $actorType,
            actorId: $actorId,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata,
        );
    }

    /**
     * Debit the wallet (money out).
     *
     * @return CompanyWalletTransaction The created transaction
     *
     * @throws RuntimeException If insufficient balance and negative wallet disallowed
     */
    public static function debit(
        Company $company,
        int $amount,
        string $sourceType,
        ?int $sourceId = null,
        ?string $description = null,
        ?string $actorType = null,
        ?int $actorId = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): CompanyWalletTransaction {
        if ($amount <= 0) {
            throw new RuntimeException('Debit amount must be positive.');
        }

        return static::record(
            company: $company,
            type: 'debit',
            amount: $amount,
            sourceType: $sourceType,
            sourceId: $sourceId,
            description: $description,
            actorType: $actorType,
            actorId: $actorId,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata,
        );
    }

    /**
     * Get the current balance for a company.
     */
    public static function balance(Company $company): int
    {
        $wallet = CompanyWallet::where('company_id', $company->id)->first();

        return $wallet ? $wallet->cached_balance : 0;
    }

    /**
     * Core write operation — always runs inside a DB transaction with row lock.
     *
     * Hard guard: system-initiated writes (actorType = 'system') MUST provide
     * an idempotency_key. Manual admin adjustments (actorType != 'system') may omit it.
     */
    private static function record(
        Company $company,
        string $type,
        int $amount,
        string $sourceType,
        ?int $sourceId,
        ?string $description,
        ?string $actorType,
        ?int $actorId,
        ?string $idempotencyKey,
        ?array $metadata,
    ): CompanyWalletTransaction {
        // Hard guard: automatic writes must be idempotent
        if ($actorType === 'system' && $idempotencyKey === null) {
            throw new RuntimeException(
                'System-initiated wallet writes must provide an idempotency_key.'
            );
        }

        return DB::transaction(function () use (
            $company, $type, $amount, $sourceType, $sourceId,
            $description, $actorType, $actorId, $idempotencyKey, $metadata,
        ) {
            // Idempotency check
            if ($idempotencyKey !== null) {
                $existing = CompanyWalletTransaction::where('idempotency_key', $idempotencyKey)->first();

                if ($existing) {
                    return $existing;
                }
            }

            // Ensure wallet exists
            $wallet = CompanyWallet::firstOrCreate(
                ['company_id' => $company->id],
                ['currency' => $company->market?->currency ?? config('billing.default_currency', 'EUR'), 'cached_balance' => 0],
            );

            // Lock the wallet row
            $wallet = CompanyWallet::where('id', $wallet->id)->lockForUpdate()->first();

            // Recompute actual balance from transactions
            $credits = (int) CompanyWalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'credit')->sum('amount');
            $debits = (int) CompanyWalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'debit')->sum('amount');
            $computedBalance = $credits - $debits;

            // Self-heal cached_balance if drift detected
            if ($wallet->cached_balance !== $computedBalance) {
                $wallet->cached_balance = $computedBalance;
            }

            // Compute new balance
            $newBalance = $type === 'credit'
                ? $computedBalance + $amount
                : $computedBalance - $amount;

            // Enforce non-negative wallet (unless policy allows)
            if ($type === 'debit' && $newBalance < 0) {
                $policy = PlatformBillingPolicy::instance();

                if (!$policy->allow_negative_wallet) {
                    throw new RuntimeException(
                        "Insufficient wallet balance. Available: {$computedBalance}, requested debit: {$amount}."
                    );
                }
            }

            // Create transaction
            $transaction = CompanyWalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            // Update cached balance
            $wallet->update(['cached_balance' => $newBalance]);

            return $transaction;
        });
    }
}
