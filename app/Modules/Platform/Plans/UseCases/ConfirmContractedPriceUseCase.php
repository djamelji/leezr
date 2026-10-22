<?php

namespace App\Modules\Platform\Plans\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * ADR-593 — controlled administrative confirmation of a subscription's contracted price. Turns a
 * provisional/ambiguous snapshot into a RELIABLE one (`admin_confirmed`) with proof, date and actor
 * recorded in the audit — so renewal may proceed. Never derives proof from the catalog automatically.
 */
class ConfirmContractedPriceUseCase
{
    /**
     * @param  int|null  $priceCents  explicit confirmed price; null confirms the current snapshot as-is.
     */
    public function confirm(int $subscriptionId, ?int $priceCents, ?int $actorId, ?string $proof = null): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $priceCents, $actorId, $proof) {
            $sub = Subscription::lockForUpdate()->findOrFail($subscriptionId);

            $before = $sub->plan_price_cents;
            if ($priceCents !== null) {
                $sub->plan_price_cents = $priceCents;
            }
            if ($sub->plan_price_cents === null) {
                throw new \DomainException('Cannot confirm: no price provided and no existing snapshot.');
            }

            $sub->plan_price_source = 'admin_confirmed';

            // Lift the tariff-review hold so renewal can proceed.
            $meta = $sub->metadata ?? [];
            unset($meta['pricing_review_required'], $meta['pricing_review_reason'], $meta['pricing_review_at']);
            $sub->metadata = $meta;
            $sub->save();

            app(AuditLogger::class)->logPlatform('subscription.contracted_price_confirmed', 'subscription', (string) $sub->id, [
                'severity' => 'warning',
                'actorId' => $actorId,
                'metadata' => [
                    'company_id' => $sub->company_id,
                    'from_cents' => $before,
                    'to_cents' => $sub->plan_price_cents,
                    'proof' => $proof,
                    'confirmed_at' => now()->toIso8601String(),
                ],
            ]);

            return $sub->fresh();
        });
    }
}
