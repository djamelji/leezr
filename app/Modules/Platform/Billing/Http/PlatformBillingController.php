<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\ReadModels\PlatformBillingReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform admin billing read endpoints.
 * Requires view_billing permission (read-only).
 */
class PlatformBillingController
{
    public function invoices(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['company_id', 'status', 'from', 'to']);
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::invoices($filters, $perPage),
        );
    }

    public function invoiceDetail(int $id): JsonResponse
    {
        $invoice = PlatformBillingReadService::invoiceDetail($id);

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        return response()->json(['invoice' => $invoice]);
    }

    public function payments(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['company_id', 'status']);
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::payments($filters, $perPage),
        );
    }

    public function creditNotes(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['company_id', 'status']);
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::creditNotes($filters, $perPage),
        );
    }

    public function wallets(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['company_id']);
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::wallets($filters, $perPage),
        );
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['status', 'plan_key', 'company_id']);
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::subscriptions($filters, $perPage),
        );
    }

    public function dunning(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::dunningInvoices($perPage),
        );
    }

    private function sanitizeFilters(Request $request, array $allowed): array
    {
        $filters = $request->only($allowed);

        if (isset($filters['company_id'])) {
            $filters['company_id'] = (int) $filters['company_id'];
        }

        return $filters;
    }
}
