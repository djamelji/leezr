<?php

namespace App\Modules\Infrastructure\Auth\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\Subscription;
use App\Core\Jobdomains\JobdomainGate;
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
        $hasTrial = ($plan?->trial_days ?? 0) > 0;
        $interval = $data->billingInterval ?? 'monthly';

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
                Subscription::create([
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
            }
            // Paid no-trial: handled after transaction via gateway

            return new RegisterCompanyResult($user, $company);
        });

        // After transaction: paid no-trial → delegate to payment gateway
        $checkout = null;
        if (! $isFree && ! $hasTrial) {
            $checkout = $this->gateway->driver()->createCheckout(
                $result->company,
                $planKey,
                $interval,
            );
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
