<?php

namespace App\Modules\Platform\Plans\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Explicit, audited tariff MIGRATION of selected subscriptions (ADR-588). Distinct from a public
 * price change: this deliberately moves chosen subscribers to a new contracted price. NEVER
 * triggered automatically by a public price change, never a mass migration. Preview has NO financial
 * effect (no invoice/payment). Apply is atomic + idempotent + audited; it only changes the
 * contracted snapshot (immediate) or schedules it for the next renewal — it issues nothing.
 */
class MigrateSubscriptionPriceUseCase
{
    /**
     * Read-only preview — per subscription current vs new, grouped per currency. No writes.
     *
     * @param  array<int>  $subscriptionIds
     * @return array{lines: array<int,array<string,mixed>>, by_currency: array<int,array<string,mixed>>}
     */
    public function preview(array $subscriptionIds, int $newPriceCents): array
    {
        $subs = Subscription::whereIn('id', $subscriptionIds)->with('company.market')->get();

        $lines = [];
        $byCur = [];
        foreach ($subs as $s) {
            $cur = $s->company?->market?->currency ?? 'EUR';
            $current = (int) ($s->contractedPlanPriceCents() ?? 0);
            $delta = $newPriceCents - $current;
            $lines[] = [
                'subscription_id' => $s->id, 'company_id' => $s->company_id, 'company' => $s->company?->name,
                'currency' => $cur, 'current_contracted_cents' => $current, 'new_cents' => $newPriceCents, 'delta_cents' => $delta,
            ];
            $byCur[$cur] ??= ['currency' => $cur, 'count' => 0, 'total_delta_cents' => 0];
            $byCur[$cur]['count']++;
            $byCur[$cur]['total_delta_cents'] += $delta;
        }

        return ['lines' => $lines, 'by_currency' => array_values($byCur)];
    }

    /**
     * Apply the migration. Atomic, idempotent (skips subs already at the target), audited.
     * timing: 'immediate' sets the contracted snapshot now; 'at_renewal' schedules it (honoured by
     * BillingRenewCommand at the next renewal on/after effective_at). Issues no invoice/payment.
     *
     * @param  array<int>  $subscriptionIds
     * @return array{migrated: int, scheduled: int, skipped: int}
     */
    public function apply(array $subscriptionIds, int $newPriceCents, string $timing = 'immediate', ?Carbon $effectiveAt = null, ?int $actorId = null): array
    {
        if (! in_array($timing, ['immediate', 'at_renewal'], true)) {
            throw new \DomainException('Invalid migration timing.');
        }

        return DB::transaction(function () use ($subscriptionIds, $newPriceCents, $timing, $effectiveAt, $actorId) {
            $stats = ['migrated' => 0, 'scheduled' => 0, 'skipped' => 0];
            $subs = Subscription::whereIn('id', $subscriptionIds)->lockForUpdate()->get();

            foreach ($subs as $s) {
                if ($timing === 'immediate') {
                    if ((int) ($s->plan_price_cents ?? -1) === $newPriceCents) {
                        $stats['skipped']++;

                        continue; // idempotent
                    }
                    $before = $s->plan_price_cents;
                    $s->plan_price_cents = $newPriceCents;
                    $s->save();
                    $stats['migrated']++;
                    $this->audit($s, 'subscription.price_migrated', $actorId, ['from_cents' => $before, 'to_cents' => $newPriceCents, 'timing' => 'immediate']);
                } else {
                    $meta = $s->metadata ?? [];
                    $meta['scheduled_price_migration'] = ['new_price_cents' => $newPriceCents, 'effective_at' => ($effectiveAt ?: now())->toIso8601String()];
                    $s->metadata = $meta;
                    $s->save();
                    $stats['scheduled']++;
                    $this->audit($s, 'subscription.price_migration_scheduled', $actorId, ['to_cents' => $newPriceCents, 'effective_at' => ($effectiveAt ?: now())->toDateString(), 'timing' => 'at_renewal']);
                }
            }

            return $stats;
        });
    }

    private function audit(Subscription $s, string $action, ?int $actorId, array $metadata): void
    {
        app(AuditLogger::class)->logPlatform($action, 'subscription', (string) $s->id, [
            'severity' => 'warning',
            'actorId' => $actorId,
            'metadata' => array_merge(['company_id' => $s->company_id, 'plan_key' => $s->plan_key], $metadata),
        ]);
    }
}
