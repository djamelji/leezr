<?php

namespace App\Modules\Platform\Companies\Http;

use App\Company\Fields\ReadModels\CompanyUserProfileReadModel;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\PlatformAuditLog;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Billing\WalletLedger;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Models\Company;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController
{
    /**
     * ADR-271: Enhanced companies list with search, filters, and KPI stats.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Company::withCount('memberships')
            ->orderByDesc('created_at');

        // Search by name, slug, or owner email
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('slug', 'LIKE', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Filter by plan
        if ($plan = $request->query('plan_key')) {
            $query->where('plan_key', $plan);
        }

        $companies = $query->paginate(20);

        // KPI stats (lightweight aggregate queries)
        $stats = [
            'total_active' => Company::where('status', 'active')->count(),
            'total_suspended' => Company::where('status', 'suspended')->count(),
            'total' => Company::count(),
        ];

        return response()->json([
            'data' => $companies->items(),
            'current_page' => $companies->currentPage(),
            'last_page' => $companies->lastPage(),
            'total' => $companies->total(),
            'stats' => $stats,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $company = Company::with('jobdomains')
            ->withCount('memberships')
            ->findOrFail($id);

        // Eager-load owner (first membership with role=owner + user)
        $ownerMembership = $company->memberships()
            ->where('role', 'owner')
            ->with('user:id,first_name,last_name,email')
            ->first();

        // Active addon subscriptions
        $addonSubscriptions = \App\Core\Billing\CompanyAddonSubscription::where('company_id', $company->id)
            ->active()
            ->get();

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

        return response()->json([
            'message' => 'Company suspended.',
            'company' => $company,
        ]);
    }

    public function reactivate(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'active']);

        app(AuditLogger::class)->logPlatform(
            AuditAction::COMPANY_REACTIVATED, 'company', (string) $company->id,
            ['diffBefore' => ['status' => 'suspended'], 'diffAfter' => ['status' => 'active']],
        );

        return response()->json([
            'message' => 'Company reactivated.',
            'company' => $company,
        ]);
    }

    /**
     * ADR-268: Company 360° billing tab — subscription, invoices, payment methods, wallet, dunning.
     */
    public function billing(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $subscription = $company->subscriptions()
            ->where('is_current', true)
            ->first()
            ?? $company->subscriptions()
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->latest()
                ->first();

        $invoices = $company->invoices()
            ->orderByDesc('issued_at')
            ->limit(20)
            ->get();

        $paymentMethods = $company->paymentProfiles()
            ->orderByDesc('is_default')
            ->get();

        $walletBalance = WalletLedger::balance($company);

        $dunningInvoices = $company->invoices()
            ->whereIn('status', ['overdue', 'open'])
            ->where('retry_count', '>', 0)
            ->orderByDesc('next_retry_at')
            ->get();

        return response()->json([
            'subscription' => $subscription,
            'invoices' => $invoices,
            'payment_methods' => $paymentMethods,
            'wallet_balance' => $walletBalance,
            'currency' => $company->market?->currency ?? 'EUR',
            'dunning_invoices' => $dunningInvoices,
        ]);
    }

    /**
     * ADR-268: Company 360° members tab.
     */
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

    /**
     * ADR-268: Company 360° activity tab — audit log entries.
     */
    public function activity(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $logs = PlatformAuditLog::where('target_type', 'company')
            ->where('target_id', (string) $company->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($logs);
    }

}
