<?php

namespace App\Modules\Platform\Companies\Http;

use App\Company\Fields\ReadModels\CompanyUserProfileReadModel;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Audit\PlatformAuditLog;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\Invoice;
use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Companies\CompanyHealthScoreCalculator;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Models\Company;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use App\Core\Support\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController
{
    public function index(Request $request): JsonResponse
    {
        $query = Company::withCount('memberships')
            ->addSelect([
                'companies.*',
                'mrr_cents' => Plan::selectRaw('price_monthly')->whereColumn('plans.key', 'companies.plan_key')->where('is_active', true)->limit(1),
                'last_activity_at' => CompanyAuditLog::selectRaw('MAX(created_at)')->whereColumn('company_audit_logs.company_id', 'companies.id'),
                'open_tickets_count' => SupportTicket::selectRaw('COUNT(*)')->whereColumn('support_tickets.company_id', 'companies.id')->where('status', 'open'),
                'overdue_invoices_count' => Invoice::selectRaw('COUNT(*)')->whereColumn('invoices.company_id', 'companies.id')->where('status', 'overdue'),
                'current_sub_status' => Subscription::select('status')->whereColumn('subscriptions.company_id', 'companies.id')->where('is_current', 1)->limit(1),
                'current_trial_ends_at' => Subscription::select('trial_ends_at')->whereColumn('subscriptions.company_id', 'companies.id')->where('is_current', 1)->limit(1),
            ])
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'LIKE', "%{$search}%")->orWhere('slug', 'LIKE', "%{$search}%"));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($plan = $request->query('plan_key')) {
            $query->where('plan_key', $plan);
        }

        if ($segment = $request->query('segment')) {
            match ($segment) {
                'at_risk' => $query->where(function ($q) {
                    // Overdue invoices OR inactive > 30 days
                    $q->whereRaw('(SELECT COUNT(*) FROM invoices WHERE invoices.company_id = companies.id AND invoices.status = ?) > 0', ['overdue'])
                        ->orWhereRaw('(SELECT MAX(created_at) FROM company_audit_logs WHERE company_audit_logs.company_id = companies.id) < ?', [now()->subDays(30)]);
                }),
                'high_value' => $query->whereIn('plan_key', ['business', 'pro'])
                    ->orderByRaw('FIELD(plan_key, ?, ?, ?) DESC', ['business', 'pro', 'starter']),
                'trial_ending' => $query->whereHas('subscriptions', function ($q) {
                    $q->where('is_current', 1)
                        ->where('status', 'trialing')
                        ->where('trial_ends_at', '<=', now()->addDays(7))
                        ->where('trial_ends_at', '>', now());
                }),
                default => null,
            };
        }

        $companies = $query->paginate(20);
        $calculator = new CompanyHealthScoreCalculator;
        $items = collect($companies->items())->map(function ($company) use ($calculator) {
            $score = $calculator->calculate($company);

            $company->health_score = $score;
            $company->health_label = CompanyHealthScoreCalculator::label($score);
            $company->health_color = CompanyHealthScoreCalculator::color($score);
            $company->mrr = ($company->mrr_cents ?? 0) / 100;

            return $company;
        });

        if ($health = $request->query('health')) {
            $items = $items->filter(fn ($c) => $c->health_label === $health)->values();
        }

        $totalActive = Company::where('status', 'active')->count();
        $totalSuspended = Company::where('status', 'suspended')->count();
        $totalMrrCents = Plan::join('companies', 'plans.key', '=', 'companies.plan_key')->where('plans.is_active', true)->where('companies.status', 'active')->sum('plans.price_monthly');
        $atRiskCount = Company::whereRaw('(SELECT COUNT(*) FROM invoices WHERE invoices.company_id = companies.id AND invoices.status = ?) > 0', ['overdue'])->count();

        return response()->json([
            'data' => $items, 'current_page' => $companies->currentPage(), 'last_page' => $companies->lastPage(), 'total' => $companies->total(),
            'stats' => ['total_active' => $totalActive, 'total_suspended' => $totalSuspended, 'total' => $totalActive + $totalSuspended, 'total_mrr' => $totalMrrCents / 100, 'at_risk_count' => $atRiskCount],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $company = Company::with('jobdomains')
            ->withCount('memberships')
            ->findOrFail($id);

        $ownerMembership = $company->memberships()
            ->where('role', 'owner')->with('user:id,first_name,last_name,email')->first();

        $addonSubscriptions = \App\Core\Billing\CompanyAddonSubscription::where('company_id', $company->id)
            ->active()->get();

        $addonModuleKeys = $addonSubscriptions->pluck('module_key')->toArray();
        $addonModuleNames = ! empty($addonModuleKeys)
            ? \App\Core\Modules\PlatformModule::whereIn('key', $addonModuleKeys)->pluck('name', 'key')->toArray()
            : [];

        return response()->json([
            'company' => $company,
            'dynamic_fields' => FieldResolverService::resolve(
                model: $company,
                scope: FieldDefinition::SCOPE_COMPANY,
                companyId: $company->id,
                marketKey: $company->market_key,
                locale: FieldResolverService::requestLocale(),
            ),
            'owner' => $ownerMembership ? [
                'name' => $ownerMembership->user?->display_name,
                'email' => $ownerMembership->user?->email,
                'user_id' => $ownerMembership->user_id,
            ] : null,
            'plan' => PlanRegistry::definitions()[CompanyEntitlements::planKey($company)] ?? null,
            'modules' => ModuleCatalogReadModel::forCompany($company),
            'addon_subscriptions' => $addonSubscriptions->map(fn ($a) => [
                'id' => $a->id,
                'module_key' => $a->module_key,
                'name' => $addonModuleNames[$a->module_key] ?? $a->module_key,
                'amount_cents' => $a->amount_cents,
                'interval' => $a->interval,
                'currency' => $a->currency,
                'activated_at' => $a->activated_at,
            ]),
            'incomplete_profiles_count' => CompanyUserProfileReadModel::incompleteCount($company),
        ]);
    }

    public function suspend(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'suspended']);

        app(AuditLogger::class)->logPlatform(
            AuditAction::COMPANY_SUSPENDED, 'company', (string) $company->id,
            ['diffBefore' => ['status' => 'active'], 'diffAfter' => ['status' => 'suspended']],
        );

        return response()->json(['message' => 'Company suspended.', 'company' => $company]);
    }

    public function reactivate(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'active']);
        app(AuditLogger::class)->logPlatform(AuditAction::COMPANY_REACTIVATED, 'company', (string) $company->id, ['diffBefore' => ['status' => 'suspended'], 'diffAfter' => ['status' => 'active']]);

        return response()->json(['message' => 'Company reactivated.', 'company' => $company]);
    }

    public function billing(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $subscription = $company->subscriptions()->with('coupon:id,code,name,type,value')->where('is_current', true)->first()
            ?? $company->subscriptions()->with('coupon:id,code,name,type,value')->whereIn('status', ['active', 'trialing', 'past_due'])->latest()->first();

        $invoices = $company->invoices()->orderByDesc('issued_at')->limit(20)->get();
        $paymentMethods = $company->paymentProfiles()->orderByDesc('is_default')->get();
        $dunningInvoices = $company->invoices()->whereIn('status', ['overdue', 'open'])->where('retry_count', '>', 0)->orderByDesc('next_retry_at')->get();
        $providerCustomer = CompanyPaymentCustomer::where('company_id', $company->id)->first();
        $lastPayment = $company->payments()->latest()->first();
        [$providerLinks, $invoices] = $this->resolveProviderLinks($providerCustomer, $subscription, $lastPayment, $invoices);

        return response()->json([
            'subscription' => $subscription, 'invoices' => $invoices, 'payment_methods' => $paymentMethods,
            'wallet_balance' => WalletLedger::balance($company), 'currency' => $company->market?->currency ?? 'EUR',
            'dunning_invoices' => $dunningInvoices, 'provider_customer_id' => $providerCustomer?->provider_customer_id,
            'provider_links' => $providerLinks,
            'last_payment' => $lastPayment ? ['id' => $lastPayment->id, 'amount' => $lastPayment->amount, 'currency' => $lastPayment->currency, 'status' => $lastPayment->status, 'provider_payment_id' => $lastPayment->provider_payment_id, 'created_at' => $lastPayment->created_at] : null,
        ]);
    }

    public function members(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $members = $company->memberships()
            ->with('user:id,first_name,last_name,email')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user?->display_name,
                'email' => $m->user?->email,
                'role' => $m->role,
                'created_at' => $m->created_at,
            ]);

        return response()->json([
            'members' => $members,
            'total' => $members->count(),
        ]);
    }

    public function activity(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $logs = PlatformAuditLog::where('target_type', 'company')
            ->where('target_id', (string) $company->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($logs);
    }

    private function resolveProviderLinks($providerCustomer, $subscription, $lastPayment, $invoices): array
    {
        $links = ['customer_url' => null, 'subscription_url' => null, 'payment_url' => null];
        $driver = app(PaymentGatewayManager::class)->driver();

        if (! ($driver instanceof PaymentProviderAdapter)) {
            return [$links, $invoices];
        }

        $links = $driver->getDashboardLinks(
            customerId: $providerCustomer?->provider_customer_id,
            subscriptionId: $subscription?->provider_subscription_id,
            paymentId: $lastPayment?->provider_payment_id,
        );

        $invoices->transform(function ($inv) use ($driver) {
            $inv->provider_url = $driver->getDashboardLinks(invoiceId: $inv->provider_invoice_id)['invoice_url'];

            return $inv;
        });

        return [$links, $invoices];
    }
}
