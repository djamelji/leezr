<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Invoice;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use App\Core\Plans\PlanRegistry;
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

    public function subscription(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'subscription' => CompanyBillingReadService::currentSubscription($company),
        ]);
    }

    public function nextInvoicePreview(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $preview = CompanyBillingReadService::nextInvoicePreview($company);

        if (!$preview) {
            return response()->json(['preview' => null]);
        }

        return response()->json(['preview' => $preview]);
    }

    public function planChangePreview(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'to_plan_key' => ['required', 'string', 'in:' . implode(',', PlanRegistry::keys())],
            'to_interval' => ['sometimes', 'string', 'in:monthly,yearly'],
        ]);

        $preview = CompanyBillingReadService::planChangePreview(
            $company,
            $validated['to_plan_key'],
            $validated['to_interval'] ?? 'monthly',
        );

        if (!$preview) {
            return response()->json(['message' => 'No active subscription or invalid plan.'], 422);
        }

        return response()->json(['preview' => $preview]);
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
