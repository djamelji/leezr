<?php

namespace App\Modules\Workforce\Onboarding\Http;

use App\Modules\Workforce\Onboarding\OnboardingPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-796 (L6) — PUBLIC onboarding portal. No company session, no internal identifier : the link token
 * (sent in the request BODY, read from the URL fragment by the page) is exchanged for an HttpOnly cookie
 * restricted to this API path ; every call re-resolves it. Unknown / expired / revoked → same answer.
 */
class OnboardingPortalController extends Controller
{
    public const COOKIE = 'leezr_onboarding';
    private const PATH = '/api/public/onboarding';

    private function portal(Request $request): OnboardingPortalService
    {
        $portal = OnboardingPortalService::fromSession($request->cookie(self::COOKIE));
        abort_unless($portal !== null, 401, __('onboarding.link_invalid'));

        return $portal;
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:100']);
        $session = OnboardingPortalService::openSession($data['token']);
        abort_unless($session !== null, 404, __('onboarding.link_invalid'));
        $view = OnboardingPortalService::fromSession($session)->view();

        return response()->json($view)->cookie(self::COOKIE, $session, OnboardingPortalService::SESSION_MINUTES, self::PATH, null, $request->isSecure() || app()->environment('production'), true, false, 'strict');
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->portal($request)->view());
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['values' => 'required|array|min:1|max:60']);

        return response()->json($this->portal($request)->saveDraft($data['values']));
    }

    public function upload(Request $request, string $code): JsonResponse
    {
        $files = $request->file('files') ?? ($request->hasFile('file') ? [$request->file('file')] : []);

        return response()->json($this->portal($request)->upload($code, is_array($files) ? $files : [$files]));
    }

    public function sendCode(Request $request): JsonResponse
    {
        $this->portal($request)->sendCode();

        return response()->json(['sent' => true]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|size:6']);
        $portal = $this->portal($request);
        abort_unless($portal->verify($data['code']), 422, __('onboarding.code_invalid'));

        return response()->json($portal->view());
    }

    public function submit(Request $request): JsonResponse
    {
        return response()->json($this->portal($request)->submit());
    }

    public function close(Request $request): JsonResponse
    {
        return response()->json(['closed' => true])->withoutCookie(self::COOKIE, self::PATH);
    }
}
