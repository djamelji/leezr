<?php

namespace App\Core\Email;

use App\Core\Models\Company;
use Illuminate\Support\Facades\Log;

class EmailOrchestrator
{
    /**
     * Check if an email should be sent for a given trigger event.
     * Returns the active EmailTemplate if sending is allowed, null otherwise.
     */
    public static function shouldSend(string $triggerEvent, array $context = []): ?EmailTemplate
    {
        $rule = EmailOrchestrationRule::active()
            ->forEvent($triggerEvent)
            ->orderBy('sort_order')
            ->first();

        if (!$rule) {
            return null;
        }

        if (!$rule->evaluateConditions($context)) {
            return null;
        }

        $template = EmailTemplate::where('key', $rule->template_key)
            ->active()
            ->first();

        if (!$template) {
            Log::warning("[email-orchestrator] Template not found or inactive", [
                'trigger' => $triggerEvent,
                'template_key' => $rule->template_key,
            ]);
            return null;
        }

        return $template;
    }

    /**
     * Get all rules for a trigger event (for multi-step sequences).
     */
    public static function rulesForEvent(string $triggerEvent): \Illuminate\Database\Eloquent\Collection
    {
        return EmailOrchestrationRule::active()
            ->forEvent($triggerEvent)
            ->orderBy('sort_order')
            ->with('template')
            ->get();
    }

    /**
     * Check if a template is active (shortcut for existing code that sends directly).
     */
    public static function isTemplateActive(string $templateKey): bool
    {
        return EmailTemplate::where('key', $templateKey)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Render subject and body for a template with given variables.
     */
    public static function renderTemplate(EmailTemplate $template, array $variables, string $locale = 'fr'): array
    {
        return [
            'subject' => TemplateRenderer::render($template->subject($locale), $variables),
            'body' => TemplateRenderer::render($template->body($locale), $variables),
        ];
    }
}
