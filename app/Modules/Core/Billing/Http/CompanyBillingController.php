<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\ReadModels\CompanyBillingReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyBillingController
{
    public function paymentMethods(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'methods' => CompanyBillingReadService::availablePaymentMethods($company),
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'invoices' => CompanyBillingReadService::invoices($company),
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'payments' => CompanyBillingReadService::payments($company),
        ]);
    }

    public function subscription(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'subscription' => CompanyBillingReadService::currentSubscription($company),
        ]);
    }

    public function portalUrl(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'url' => CompanyBillingReadService::portalUrl($company),
        ]);
    }
}
