<?php

namespace App\Core\Billing\Http;

use App\Core\Billing\Contracts\BillingProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController
{
    public function __invoke(Request $request, BillingProvider $billing): JsonResponse
    {
        $payload = $request->all();
        $signature = $request->header('Stripe-Signature', '');

        $result = $billing->handleWebhook($payload, $signature);

        return response()->json(['handled' => $result !== null]);
    }
}
