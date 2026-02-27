<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Invoice;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CompanyBillingController
{
    public function overview(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(
            CompanyBillingReadService::overview($company),
        );
    }

    public function invoices(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $filters = $request->only(['status', 'from', 'to']);
        $perPage = (int) $request->input('per_page', 15);

        return response()->json(
            CompanyBillingReadService::invoices($company, $filters, $perPage),
        );
    }

    public function invoiceDetail(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        $invoice = CompanyBillingReadService::invoiceDetail($company, $id);

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        return response()->json(['invoice' => $invoice]);
    }

    public function payments(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $perPage = (int) $request->input('per_page', 15);

        return response()->json(
            CompanyBillingReadService::payments($company, $perPage),
        );
    }

    public function wallet(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(
            CompanyBillingReadService::wallet($company),
        );
    }

    public function subscription(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'subscription' => CompanyBillingReadService::currentSubscription($company),
        ]);
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'methods' => CompanyBillingReadService::availablePaymentMethods($company),
        ]);
    }

    public function portalUrl(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'url' => CompanyBillingReadService::portalUrl($company),
        ]);
    }

    public function invoicePdf(Request $request, int $id): Response
    {
        $company = $request->attributes->get('company');

        $invoice = Invoice::where('company_id', $company->id)
            ->where('id', $id)
            ->whereNotNull('finalized_at')
            ->with(['lines', 'company'])
            ->first();

        if (!$invoice) {
            abort(404, 'Invoice not found.');
        }

        $html = view('billing.invoice-pdf', [
            'invoice' => $invoice,
            'company' => $invoice->company,
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
