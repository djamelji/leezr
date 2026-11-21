<?php

namespace App\Modules\Infrastructure\Auth\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Auth\TwoFactorService;
use App\Modules\Infrastructure\Auth\TwoFactorSensitiveActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorSensitiveActions $sensitive,
    ) {}

    public function enable(Request $request): JsonResponse
    {
        $result = $this->twoFactor->enable($request->user());

        return response()->json([
            'secret' => $result['secret'],
            'qr_url' => $result['qr_url'],
            'backup_codes' => $result['backup_codes'],
        ])->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);

        if (!$this->twoFactor->confirm($request->user(), $request->code)) {
            return response()->json(['message' => 'Invalid verification code.'], 422);
        }

        $this->audit($request)(AuditAction::TWO_FACTOR_ENABLED, ['result' => 'success'], 'info');

        return response()->json(['message' => '2FA enabled successfully.']);
    }

    /** ADR-838 — password AND a fresh factor (TOTP or backup code), throttled, audited, owner notified. */
    public function disable(Request $request): JsonResponse
    {
        return $this->sensitive->disable($request, $request->user(), $this->audit($request));
    }

    /** ADR-838 — a fresh factor ; old codes replaced only once the new ones are written. */
    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        return $this->sensitive->regenerateBackupCodes($request, $request->user(), $this->audit($request));
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => $this->twoFactor->isEnabled($request->user()),
        ]);
    }

    /** Company audit log of the current company ; the target is the account itself. */
    private function audit(Request $request): callable
    {
        $companyId = $request->attributes->get('company')?->id;
        $userId = (string) $request->user()->id;

        return function (string $action, array $metadata, string $severity) use ($companyId, $userId) {
            if ($companyId) {
                app(AuditLogger::class)->logCompany($companyId, $action, 'user', $userId, ['metadata' => $metadata, 'severity' => $severity]);
            }
        };
    }
}
