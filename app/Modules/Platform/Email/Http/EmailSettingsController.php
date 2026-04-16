<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailService;
use App\Core\Theme\UIResolverService;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailSettingsController
{
    /**
     * Show SMTP settings with Laravel mail config as defaults.
     * Branding (logo, color) derived from theme + general settings — read-only here.
     */
    public function show(): JsonResponse
    {
        $settings = PlatformSetting::instance()->email ?? [];

        // Pre-fill from Laravel mail config when no custom SMTP configured
        $smtp = [
            'smtp_host' => $settings['smtp_host'] ?? config('mail.mailers.smtp.host', ''),
            'smtp_port' => $settings['smtp_port'] ?? config('mail.mailers.smtp.port', 587),
            'smtp_encryption' => $settings['smtp_encryption'] ?? config('mail.mailers.smtp.encryption', 'tls'),
            'smtp_username' => $settings['smtp_username'] ?? config('mail.mailers.smtp.username', ''),
            'smtp_password_set' => ! empty($settings['smtp_password']),
        ];

        // Branding = read-only, derived from theme + general
        $general = PlatformSetting::instance()->general ?? [];

        try {
            $primaryColor = UIResolverService::forPlatform()->primaryColor;
        } catch (\Throwable) {
            $primaryColor = '#7367F0';
        }

        return response()->json([
            'settings' => $smtp,
            'branding' => [
                'app_name' => $general['app_name'] ?? config('app.name', 'Leezr'),
                'primary_color' => $primaryColor,
            ],
        ]);
    }

    /**
     * Update SMTP settings (platform-wide).
     * Identity (from_name, from_email, reply_to) is per-admin — see identity endpoints.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'smtp_host' => 'nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|in:tls,ssl,none',
            'smtp_username' => 'nullable|string|max:255',
            'smtp_password' => 'nullable|string|max:255',
        ]);

        $instance = PlatformSetting::instance();
        $current = $instance->email ?? [];

        // If password is '********' (masked), keep the current one
        if (($validated['smtp_password'] ?? '') === '********') {
            $validated['smtp_password'] = $current['smtp_password'] ?? null;
        }

        // Preserve identity fields that might exist from old config
        $merged = array_merge($current, $validated);

        $instance->update(['email' => $merged]);

        return response()->json(['message' => 'Email settings updated.']);
    }

    public function test(): JsonResponse
    {
        $result = app(EmailService::class)->testConnection();

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Show the current admin's email identity.
     */
    public function showIdentity(Request $request): JsonResponse
    {
        $user = $request->user();
        $identity = $user->email_identity ?? [];

        return response()->json([
            'identity' => [
                'from_name' => $identity['from_name'] ?? $user->display_name,
                'from_email' => $identity['from_email'] ?? $user->email,
                'reply_to' => $identity['reply_to'] ?? $user->email,
            ],
        ]);
    }

    /**
     * Update the current admin's email identity.
     */
    public function updateIdentity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_name' => 'required|string|max:100',
            'from_email' => 'required|email|max:255',
            'reply_to' => 'nullable|email|max:255',
        ]);

        $user = $request->user();
        $user->update(['email_identity' => $validated]);

        return response()->json([
            'message' => 'Email identity updated.',
            'identity' => $validated,
        ]);
    }
}
