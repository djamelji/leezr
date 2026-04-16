<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailOrchestrator;
use App\Core\Email\EmailTemplate;
use App\Core\Email\TemplateRenderer;
use App\Core\Email\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateController
{
    public function index(Request $request): JsonResponse
    {
        $query = EmailTemplate::orderBy('category')->orderBy('name');

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json(['templates' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|max:100|unique:email_templates,key|regex:/^[a-z0-9._]+$/',
            'category' => 'required|string|max:50',
            'name' => 'required|string|max:200',
            'subject_fr' => 'required|string|max:500',
            'subject_en' => 'nullable|string|max:500',
            'body_fr' => 'required|string|max:10000',
            'body_en' => 'nullable|string|max:10000',
            'variables' => 'nullable|array',
            'variables.*' => 'string|max:50',
            'is_active' => 'sometimes|boolean',
        ]);

        $validated['is_system'] = false;

        $template = \App\Core\Email\EmailTemplate::create($validated);

        return response()->json(['message' => 'Template created.', 'template' => $template], 201);
    }

    public function show(string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->firstOrFail();

        return response()->json(['template' => $template]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->firstOrFail();

        $validated = $request->validate([
            'subject_fr' => 'sometimes|string|max:500',
            'subject_en' => 'sometimes|string|max:500',
            'body_fr' => 'sometimes|string|max:10000',
            'body_en' => 'sometimes|string|max:10000',
            'is_active' => 'sometimes|boolean',
        ]);

        $template->update($validated);

        return response()->json(['message' => 'Template updated.', 'template' => $template->fresh()]);
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->firstOrFail();
        $locale = $request->query('locale', 'fr');

        // Use preview_data from template or custom data from request
        $variables = $request->input('variables', $template->preview_data ?? []);

        $rendered = EmailOrchestrator::renderTemplate($template, $variables, $locale);

        // Wrap in email layout HTML (inline — no Blade dependency for preview)
        $branding = EmailService::branding();
        $color = $branding['color'] ?? '#7367F0';
        $appName = $branding['app_name'] ?? 'Leezr';
        $bodyHtml = nl2br(e($rendered['body']));
        $html = "<div style=\"max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;\">"
            ."<div style=\"background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;\">"
            ."<div style=\"padding:24px 32px 16px;text-align:center;border-bottom:1px solid #f0f0f0;\">"
            ."<span style=\"font-size:22px;font-weight:700;color:#333;\">".strtolower($appName)."</span>"
            ."<span style=\"font-size:22px;font-weight:700;color:{$color};\">.</span></div>"
            ."<div style=\"padding:32px;color:#333;font-size:15px;line-height:1.6;\">{$bodyHtml}</div>"
            ."<div style=\"padding:16px 32px;text-align:center;color:#999;font-size:12px;border-top:1px solid #f0f0f0;\">"
            ."&copy; ".date('Y')." {$appName}</div></div></div>";

        return response()->json([
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            'html' => $html,
            'variables_used' => TemplateRenderer::extractVariables($template->body($locale)),
        ]);
    }

    public function sendTest(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->firstOrFail();
        $locale = $request->query('locale', 'fr');
        $variables = $template->preview_data ?? [];
        $rendered = EmailOrchestrator::renderTemplate($template, $variables, $locale);
        $branding = EmailService::branding();

        // Send test email to current admin user
        $admin = $request->user('platform');
        if (!$admin) {
            return response()->json(['message' => 'No authenticated admin.'], 401);
        }

        try {
            $service = app(EmailService::class);
            // Create a simple mail notification for the test
            $notification = new \App\Notifications\Email\TestEmailNotification(
                $rendered['subject'],
                $rendered['body'],
                $branding,
            );
            $service->send($notification, $admin, "test.{$template->key}", null, ['test' => true]);

            return response()->json(['message' => "Test email sent to {$admin->email}."]);
        } catch (\Throwable $e) {
            return response()->json(['message' => "Failed: {$e->getMessage()}"], 422);
        }
    }
}
