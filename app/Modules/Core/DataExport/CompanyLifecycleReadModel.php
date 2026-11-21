<?php

namespace App\Modules\Core\DataExport;

use App\Core\Billing\Subscription;
use App\Core\DataExport\CompanyDataExport;
use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * ADR-845 (Leezr 4.2 M8) — where a company stands in its relationship with Leezr, from EXISTING states only
 * (no new persistent status) :
 *  - commercial  : the subscription (Billing engine) — none · trial · active · pending_payment · payment_overdue ·
 *                  cancellation_scheduled · ended ;
 *  - operational : companies.status — active · suspended (access is never cut by a cancellation) ;
 *  - data        : exports (latest), retention policy of the market (config, may be undefined) ; permanent
 *                  deletion : not implemented ; archiving : not supported by the current model.
 *  - obligations : what is still open (unpaid invoices, payroll runs not exported) — shown, never blocking.
 */
class CompanyLifecycleReadModel
{
    public static function for(Company $company): array
    {
        $sub = Subscription::where('company_id', $company->id)->where('is_current', 1)->latest('id')->first()
            ?? Subscription::where('company_id', $company->id)->latest('id')->first();

        $commercial = match (true) {
            $sub === null => 'none',
            in_array($sub->status, ['cancelled', 'expired'], true) || ! $sub->is_current => 'ended',
            (bool) $sub->cancel_at_period_end => 'cancellation_scheduled',
            $sub->status === 'trialing' => 'trial',
            in_array($sub->status, ['pending', 'pending_payment'], true) => 'pending_payment',
            $sub->status === 'past_due' => 'payment_overdue',
            default => 'active',
        };

        $unpaid = DB::table('invoices')->where('company_id', $company->id)->whereIn('status', ['open', 'overdue'])->where('amount_due', '>', 0);
        $payroll = DB::getSchemaBuilder()->hasTable('workforce_payroll_runs')
            ? DB::table('workforce_payroll_runs')->where('company_id', $company->id)->whereIn('status', ['computed', 'validated'])->count() : 0;
        $latest = CompanyDataExport::where('company_id', $company->id)->latest('id')->first();

        return [
            'commercial' => [
                'state' => $commercial,
                'plan' => $sub?->plan_key, 'interval' => $sub?->interval,
                'period_end' => $sub?->current_period_end?->toDateString(),
                'trial_ends_at' => $sub?->trial_ends_at?->toDateString(),
                'cancel_at_period_end' => (bool) $sub?->cancel_at_period_end,
                'can_cancel' => in_array($commercial, ['active', 'trial'], true),
                'can_undo_cancel' => $commercial === 'cancellation_scheduled',
            ],
            'operational' => ['state' => $company->status === 'suspended' ? 'suspended' : 'active'],
            'data' => [
                'latest_export' => $latest?->summary(),
                'retention_policy' => config('company_lifecycle.retention.markets.'.($company->market_key ?? '')) ?: null,
                'permanent_deletion' => 'not_available',
                'archiving' => 'not_supported',
            ],
            'obligations' => [
                'unpaid_invoices' => (clone $unpaid)->count(),
                'unpaid_amount_cents' => (int) (clone $unpaid)->sum('amount_due'),
                'payroll_runs_open' => $payroll,
            ],
            'closing' => in_array($commercial, ['cancellation_scheduled', 'ended'], true),
        ];
    }
}
