<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\BillingWebhookDeadLetter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * ADR-345: Recovery operations exposed to platform admin UI.
 *
 * Wraps the CLI commands (billing:recover-checkouts, billing:recover-webhooks,
 * billing:webhook-replay) as HTTP endpoints for the Recovery tab.
 *
 * Requires manage_billing permission (write).
 */
class PlatformRecoveryController
{
    public function recoverCheckouts(): JsonResponse
    {
        Artisan::call('billing:recover-checkouts');
        $output = Artisan::output();

        return response()->json([
            'message' => 'Checkout recovery completed.',
            'output' => trim($output),
            'stats' => $this->parseStats($output, ['activated', 'expired', 'still_pending', 'failed']),
        ]);
    }

    public function recoverWebhooks(): JsonResponse
    {
        Artisan::call('billing:recover-webhooks');
        $output = Artisan::output();

        return response()->json([
            'message' => 'Webhook recovery completed.',
            'output' => trim($output),
            'stats' => $this->parseStats($output, ['recovered', 'expired', 'failed']),
        ]);
    }

    public function replayAllDeadLetters(): JsonResponse
    {
        Artisan::call('billing:webhook-replay');
        $output = Artisan::output();

        return response()->json([
            'message' => 'Dead letter replay completed.',
            'output' => trim($output),
            'stats' => $this->parseStats($output, ['replayed', 'failed']),
        ]);
    }

    public function replayDeadLetter(int $id): JsonResponse
    {
        $dl = BillingWebhookDeadLetter::findOrFail($id);

        if ($dl->status !== 'pending') {
            return response()->json(['message' => 'Dead letter is not in pending status.'], 422);
        }

        if ($dl->replay_attempts >= 3) {
            return response()->json(['message' => 'Max replay attempts reached (3/3).'], 422);
        }

        Artisan::call('billing:webhook-replay', ['--id' => $id]);
        $output = Artisan::output();

        $dl->refresh();

        return response()->json([
            'message' => $dl->status === 'replayed' ? 'Dead letter replayed successfully.' : 'Replay attempted.',
            'output' => trim($output),
            'dead_letter' => $dl,
        ]);
    }

    public function listDeadLetters(Request $request): JsonResponse
    {
        $deadLetters = BillingWebhookDeadLetter::where('status', 'pending')
            ->where('replay_attempts', '<', 3)
            ->oldest('failed_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($deadLetters);
    }

    private function parseStats(string $output, array $keys): array
    {
        $stats = array_fill_keys($keys, 0);

        foreach ($keys as $key) {
            $label = ucfirst(str_replace('_', ' ', $key));
            if (preg_match("/{$label}:\s*(\d+)/i", $output, $m)) {
                $stats[$key] = (int) $m[1];
            }
        }

        return $stats;
    }
}
