<?php

namespace App\Modules\Infrastructure\Auth\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\BillingCoupon;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\InvoiceLineDescriptor;
use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Modules\Core\Billing\Services\CouponService;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldWriteService;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Plans\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterCompanyUseCase
{
    public function __construct(
        private readonly PaymentGatewayManager $gateway,
    ) {}

    public function execute(RegisterCompanyData $data): RegisterCompanyResult
    {
        $planKey = $data->planKey ?? 'starter';
        $plan = Plan::where('key', $planKey)->first();
        $isFree = ! $plan || ($plan->price_monthly <= 0 && $plan->price_yearly <= 0);
        $interval = $data->billingInterval ?? 'monthly';

        // Anti-abuse: check if this email already used a trial (any previous company)
        $hasUsedTrial = false;
        $existingUser = User::where('email', $data->email)->first();
        if ($existingUser) {
            $companyIds = $existingUser->companies()->pluck('companies.id');
            $hasUsedTrial = $companyIds->isNotEmpty() && Subscription::whereIn('company_id', $companyIds)
                ->whereNotNull('trial_ends_at')
                ->exists();
        }

        $hasTrial = ! $hasUsedTrial && ($plan?->trial_days ?? 0) > 0;

        $result = DB::transaction(function () use ($data, $planKey, $plan, $isFree, $hasTrial, $interval) {
            $user = User::create([
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'email' => $data->email,
                'password' => $data->password,
                'password_set_at' => now(),
            ]);

            // ADR-165: resolve market — explicit > default > null
            $marketKey = $data->marketKey;
            if (! $marketKey) {
                $defaultMarket = \App\Core\Markets\Market::where('is_default', true)->first();
                $marketKey = $defaultMarket?->key;
            }

            // ADR-167a: jobdomain_key is required — no fallback
            $company = Company::create([
                'name' => $data->companyName,
                'slug' => Str::slug($data->companyName) . '-' . Str::random(4),
                'plan_key' => $planKey,
                'market_key' => $marketKey,
                'jobdomain_key' => $data->jobdomainKey,
            ]);

            $company->memberships()->create([
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            // ADR-100: Assign jobdomain + activate defaults (modules, fields, roles, dashboard)
            JobdomainGate::assignToCompany($company, $data->jobdomainKey);

            // ADR-290: Set legal_status_key if provided
            if ($data->legalStatusKey) {
                $company->update(['legal_status_key' => $data->legalStatusKey]);
            }

            // ADR-290: Save dynamic company fields collected at registration
            if (! empty($data->dynamicFields)) {
                FieldWriteService::upsert(
                    $company,
                    $data->dynamicFields,
                    FieldDefinition::SCOPE_COMPANY,
                    $company->id,
                    $company->market_key,
                );
            }

            // ADR-300: Copy company address → billing address if toggle ON
            if ($data->billingSameAsCompany && ! empty($data->dynamicFields)) {
                $addressMap = [
                    'company_address' => 'billing_address',
                    'company_complement' => 'billing_complement',
                    'company_city' => 'billing_city',
                    'company_postal_code' => 'billing_postal_code',
                    'company_region' => 'billing_region',
                ];

                $billingOverrides = [];
                foreach ($addressMap as $src => $dst) {
                    if (isset($data->dynamicFields[$src])) {
                        $billingOverrides[$dst] = $data->dynamicFields[$src];
                    }
                }

                if (! empty($billingOverrides)) {
                    FieldWriteService::upsert(
                        $company,
                        $billingOverrides,
                        FieldDefinition::SCOPE_COMPANY,
                        $company->id,
                        $company->market_key,
                    );
                }
            }

            // ADR-300: Activate selected addon modules at registration
            foreach ($data->addonKeys as $addonKey) {
                ModuleActivationEngine::enable($company, $addonKey);
            }

            // ADR-231: Create subscription at registration
            if ($isFree) {
                $freePeriodEnd = $interval === 'yearly'
                    ? now()->addYear()
                    : now()->addMonth();

                Subscription::create([
                    'company_id' => $company->id,
                    'plan_key' => $planKey,
                    'interval' => $interval,
                    'status' => 'active',
                    'provider' => 'internal',
                    'is_current' => 1,
                    'current_period_start' => now(),
                    'current_period_end' => $freePeriodEnd,
                ]);
            } elseif ($hasTrial) {
                $trialSub = Subscription::create([
                    'company_id' => $company->id,
                    'plan_key' => $planKey,
                    'interval' => $interval,
                    'status' => 'trialing',
                    'provider' => 'internal',
                    'is_current' => 1,
                    'current_period_start' => now(),
                    'current_period_end' => now()->addDays($plan->trial_days),
                    'trial_ends_at' => now()->addDays($plan->trial_days),
                ]);

                // ADR-286: Notify owner that trial has started
                $user->notify(new \App\Notifications\Billing\TrialStarted($trialSub));
            }
            // Paid no-trial: subscription created by createCheckout() after transaction

            // ADR-320: Validate and attach coupon to subscription
            $validatedCoupon = null;
            if ($data->couponCode) {
                $couponService = app(CouponService::class);
                $result = $couponService->validate($data->couponCode, $company, $planKey, $interval);

                if ($result['valid']) {
                    $validatedCoupon = $result['coupon'];
                    $sub = Subscription::where('company_id', $company->id)->where('is_current', 1)->first();

                    if ($sub) {
                        $durationMonths = $validatedCoupon->duration_months;
                        $sub->update([
                            'coupon_id' => $validatedCoupon->id,
                            'coupon_months_remaining' => $durationMonths === 0 ? null : ($durationMonths ?? 1),
                        ]);
                    }
                }
            }

            return new RegisterCompanyResult($user, $company);
        });

        // After transaction: paid plan → collect payment method
        // ADR-303: trial_requires_payment_method governs whether we ask for PM during trial
        $checkout = null;
        if (! $isFree) {
            $policy = PlatformBillingPolicy::instance();
            $skipCheckout = $hasTrial && ! $policy->trial_requires_payment_method;

            // ADR-302: If trial + immediate charging → create plan invoice now
            // ConfirmRegistrationPaymentController will charge all open invoices
            if ($hasTrial && $policy->trial_charge_timing === 'immediate') {
                $sub = Subscription::where('company_id', $result->company->id)
                    ->where('is_current', 1)
                    ->first();

                if ($sub) {
                    $price = $interval === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

                    if ($price > 0) {
                        $periodEnd = $interval === 'yearly'
                            ? now()->addYear()
                            : now()->addMonth();

                        $invoice = InvoiceIssuer::createDraft(
                            $result->company,
                            $sub->id,
                            now()->toDateString(),
                            $periodEnd->toDateString(),
                        );

                        $desc = InvoiceLineDescriptor::resolve(Market::where('key', $marketKey)->value('locale') ?? 'fr-FR');

                        InvoiceIssuer::addLine($invoice, 'plan', $desc->plan($plan->name), $price, 1);

                        // ADR-320: Apply coupon discount to initial invoice
                        if ($sub->coupon_id) {
                            $coupon = BillingCoupon::find($sub->coupon_id);
                            if ($coupon) {
                                InvoiceIssuer::applyCoupon($invoice, $coupon, $result->company);
                            }
                        }

                        InvoiceIssuer::finalize($invoice);
                    }
                }
            }

            if (! $skipCheckout) {
                $checkout = $this->gateway->driver()->createCheckout(
                    $result->company,
                    $planKey,
                    $interval,
                );
            }

            // ADR-320: Attach coupon to paid no-trial subscription (created by createCheckout)
            if (! $hasTrial && $data->couponCode) {
                $couponService = app(CouponService::class);
                $couponResult = $couponService->validate($data->couponCode, $result->company, $planKey, $interval);

                if ($couponResult['valid']) {
                    $sub = Subscription::where('company_id', $result->company->id)
                        ->where('is_current', 1)
                        ->first();

                    if ($sub) {
                        $durationMonths = $couponResult['coupon']->duration_months;
                        $sub->update([
                            'coupon_id' => $couponResult['coupon']->id,
                            'coupon_months_remaining' => $durationMonths === 0 ? null : ($durationMonths ?? 1),
                        ]);
                    }
                }
            }
        }

        // Audit outside transaction — non-critical side-effect
        app(AuditLogger::class)->logCompany(
            $result->company->id,
            AuditAction::REGISTER,
            'user',
            (string) $result->user->id,
            ['actorId' => $result->user->id, 'metadata' => ['email' => $result->user->email]],
        );

        return new RegisterCompanyResult($result->user, $result->company, $checkout);
    }
}
