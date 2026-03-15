<?php

namespace App\Modules\Infrastructure\Auth\Http;

use App\Core\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function enable(Request $request): JsonResponse
    {
        $result = $this->twoFactor->enable($request->user());

        return response()->json([
            'secret' => $result['secret'],
            'qr_url' => $result['qr_url'],
            'backup_codes' => $result['backup_codes'],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);

        if (!$this->twoFactor->confirm($request->user(), $request->code)) {
            return response()->json(['message' => 'Invalid verification code.'], 422);
        }

        return response()->json(['message' => '2FA enabled successfully.']);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (!Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Invalid password.'], 422);
        }

        $this->twoFactor->disable($request->user());

        return response()->json(['message' => '2FA disabled.']);
    }

    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        $codes = $this->twoFactor->regenerateBackupCodes($request->user());

        if (empty($codes)) {
            return response()->json(['message' => '2FA is not enabled.'], 422);
        }

        return response()->json(['backup_codes' => $codes]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => $this->twoFactor->isEnabled($request->user()),
        ]);
    }
}
