<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Invoice;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use App\Core\Billing\ReadModels\ConsolidatedPreviewReadService;
use App\Core\Billing\Subscription;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CompanyBillingController
{
    public function overview(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(array_merge(
            CompanyBillingReadService::overview($company),
            ['pending_subscription' => CompanyBillingReadService::pendingSubscription($company)],
        ));
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

    /**
     * ADR-632 — « Aperçu consolidé V2 » : projection visuelle read-only des factures V1 existantes.
     * N'écrit rien, ne crée aucune créance, ne modifie aucune facture.
     */
    public function consolidatedPreview(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(ConsolidatedPreviewReadService::forCompany($company));
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
            'pending_subscription' => CompanyBillingReadService::pendingSubscription($company),
        ]);
    }

    /**
     * Dismiss a rejected subscription so the company can request again (ADR-289).
     */
    public function dismissPendingSubscription(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $deleted = Subscription::where('company_id', $company->id)
            ->where('status', 'rejected')
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No rejected subscription to dismiss.'], 404);
        }

        return response()->json(['message' => 'Rejected subscription dismissed.']);
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
            $validated['to_interval'] ?? PlatformBillingPolicy::instance()->default_billing_interval,
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
            ->with(['lines', 'company', 'parentInvoice', 'annexes', 'creditNotes'])
            ->first();

        if (!$invoice) {
            abort(404, 'Invoice not found.');
        }

        $pdfContent = \App\Core\Billing\Documents\InvoicePdfRenderer::render($invoice); // ADR-848
        $filename = \App\Core\Billing\Documents\InvoicePdfRenderer::filename($invoice);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
