<?php

namespace App\Modules\Platform\Companies\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Modules\Core\Billing\Http\CompanyPaymentSetupController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ADR-272: Platform admin — payment methods + subscription lifecycle.
 */
class CompanySubscriptionAdminController
{
    // ─── Payment Methods ──────────────────────────────

    public function paymentMethods(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $profiles = CompanyPaymentProfile::where('company_id', $company->id)
            ->orderByDesc('is_default')
            ->get();

        $cards = $profiles->map(fn ($p) => CompanyPaymentSetupController::formatProfile($p));

        return response()->json(['cards' => $cards]);
    }

    public function setDefaultPaymentMethod(int $id, int $pmId): JsonResponse
    {
        $company = Company::findOrFail($id);

        $profile = CompanyPaymentProfile::where('id', $pmId)
            ->where('company_id', $company->id)
            ->first();

        if (! $profile) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        CompanyPaymentProfile::where('company_id', $company->id)
            ->where('id', '!=', $profile->id)
            ->update(['is_default' => false]);

        $profile->update(['is_default' => true]);

        try {
            $customer = CompanyPaymentCustomer::where('company_id', $company->id)
                ->where('provider_key', 'stripe')
                ->first();

            if ($customer && $profile->provider_payment_method_id) {
                $adapter = app(StripePaymentAdapter::class);
                $adapter->setDefaultPaymentMethod(
                    $customer->provider_customer_id,
                    $profile->provider_payment_method_id,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[billing:admin] Stripe set default PM failed', [
                'profile_id' => $profile->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Default payment method updated.']);
    }

    public function deletePaymentMethod(int $id, int $pmId): JsonResponse
    {
        $company = Company::findOrFail($id);

        $profile = CompanyPaymentProfile::where('id', $pmId)
            ->where('company_id', $company->id)
            ->first();

        if (! $profile) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        if ($profile->provider_payment_method_id) {
            try {
                $adapter = app(StripePaymentAdapter::class);
                $adapter->detachPaymentMethod($profile->provider_payment_method_id);
            } catch (\Throwable $e) {
                Log::warning('[billing:admin] Stripe detach failed', [
                    'profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $wasDefault = $profile->is_default;
        $profile->delete();

        if ($wasDefault) {
            CompanyPaymentProfile::where('company_id', $company->id)
                ->orderBy('id')
                ->first()
                ?->update(['is_default' => true]);
        }

        return response()->json(['message' => 'Payment method removed.']);
    }

    // ─── Subscription Lifecycle ───────────────────────

    public function cancelSubscription(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'No active subscription.'], 422);
        }

        $subscription->update(['cancel_at_period_end' => true]);

        app(AuditLogger::class)->logPlatform(
            AuditAction::CANCEL_REQUESTED,
            'company',
            (string) $company->id,
            [
                'diffBefore' => ['cancel_at_period_end' => false],
                'diffAfter' => ['cancel_at_period_end' => true],
            ],
        );

        return response()->json([
            'message' => 'Cancellation scheduled at end of period.',
            'subscription' => $subscription->fresh(),
        ]);
    }

    public function undoCancelSubscription(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $subscription = Subscription::where('company_id', $company->id)
            ->where('cancel_at_period_end', true)
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'No pending cancellation.'], 422);
        }

        $subscription->update(['cancel_at_period_end' => false]);

        app(AuditLogger::class)->logPlatform(
            AuditAction::CANCEL_REQUESTED,
            'company',
            (string) $company->id,
            [
                'diffBefore' => ['cancel_at_period_end' => true],
                'diffAfter' => ['cancel_at_period_end' => false],
                'metadata' => ['action' => 'undo_cancel'],
            ],
        );

        return response()->json([
            'message' => 'Cancellation undone.',
            'subscription' => $subscription->fresh(),
        ]);
    }

    public function extendTrial(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        $company = Company::findOrFail($id);

        $subscription = Subscription::where('company_id', $company->id)
            ->where('status', 'trialing')
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'No active trial subscription.'], 422);
        }

        $oldTrialEnd = $subscription->trial_ends_at;
        $newTrialEnd = ($oldTrialEnd ? $oldTrialEnd->copy() : now())->addDays($validated['days']);
        $subscription->update(['trial_ends_at' => $newTrialEnd]);

        app(AuditLogger::class)->logPlatform(
            AuditAction::PLAN_CHANGE_EXECUTED,
            'company',
            (string) $company->id,
            [
                'diffBefore' => ['trial_ends_at' => $oldTrialEnd?->toIso8601String()],
                'diffAfter' => ['trial_ends_at' => $newTrialEnd->toIso8601String()],
                'metadata' => ['action' => 'extend_trial', 'days' => $validated['days']],
            ],
        );

        return response()->json([
            'message' => "Trial extended by {$validated['days']} day(s).",
            'subscription' => $subscription->fresh(),
        ]);
    }

    // ─── Payment Method Setup (Stripe) ───────────────

    public function createSetupIntent(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        try {
            $adapter = app(StripePaymentAdapter::class);
            $result = $adapter->createSetupIntent($company, 'card');
        } catch (\Throwable $e) {
            Log::warning('[billing:admin] SetupIntent failed', ['company_id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $module = \App\Core\Billing\PlatformPaymentModule::where('provider_key', 'stripe')->first();

        return response()->json([
            'client_secret' => $result['client_secret'],
            'publishable_key' => $module?->getStripePublishableKey() ?? config('billing.stripe.key'),
        ]);
    }

    public function confirmPaymentMethod(Request $request, int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $request->attributes->set('company', $company);

        return app(CompanyPaymentSetupController::class)->confirmSetupIntent($request);
    }
}
