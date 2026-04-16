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

        // IMAP settings for inbox fetching
        $imap = [
            'imap_host' => $settings['imap_host'] ?? '',
            'imap_port' => $settings['imap_port'] ?? 993,
            'imap_encryption' => $settings['imap_encryption'] ?? 'ssl',
            'imap_username' => $settings['imap_username'] ?? '',
            'imap_password_set' => ! empty($settings['imap_password']),
            'imap_folder' => $settings['imap_folder'] ?? 'INBOX',
        ];

        return response()->json([
            'settings' => $smtp,
            'imap' => $imap,
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
            'imap_host' => 'nullable|string|max:255',
            'imap_port' => 'nullable|integer|min:1|max:65535',
            'imap_encryption' => 'nullable|in:ssl,tls,none',
            'imap_username' => 'nullable|string|max:255',
            'imap_password' => 'nullable|string|max:255',
            'imap_folder' => 'nullable|string|max:100',
        ]);

        $instance = PlatformSetting::instance();
        $current = $instance->email ?? [];

        // If password is '********' (masked), keep the current one
        if (($validated['smtp_password'] ?? '') === '********') {
            $validated['smtp_password'] = $current['smtp_password'] ?? null;
        }
        if (($validated['imap_password'] ?? '') === '********') {
            $validated['imap_password'] = $current['imap_password'] ?? null;
        }

        // Preserve existing fields
        $merged = array_merge($current, $validated);

        $instance->update(['email' => $merged]);

        return response()->json(['message' => 'Email settings updated.']);
    }

    public function test(): JsonResponse
    {
        $result = app(EmailService::class)->testConnection();

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function testImap(): JsonResponse
    {
        $fetcher = new \App\Core\Email\ImapFetcher;

        if (! $fetcher->isConfigured()) {
            return response()->json(['success' => false, 'message' => 'IMAP not configured.'], 422);
        }

        $success = $fetcher->connect();
        $fetcher->disconnect();

        return response()->json([
            'success' => $success,
            'message' => $success ? 'IMAP connection successful.' : 'IMAP connection failed: '.imap_last_error(),
        ], $success ? 200 : 422);
    }

    public function fetchInbox(): JsonResponse
    {
        $fetcher = new \App\Core\Email\ImapFetcher;

        if (! $fetcher->isConfigured()) {
            return response()->json(['success' => false, 'message' => 'IMAP not configured.'], 422);
        }

        if (! $fetcher->connect()) {
            return response()->json(['success' => false, 'message' => 'IMAP connection failed.'], 422);
        }

        $count = $fetcher->fetch(50);
        $fetcher->disconnect();

        return response()->json([
            'success' => true,
            'message' => "{$count} email(s) fetched.",
            'count' => $count,
        ]);
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
