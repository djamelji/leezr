<?php

namespace App\Core\Email;

class TemplateRenderer
{
    /**
     * Render a template string by replacing {{ variable }} placeholders.
     * Safe — no eval, no Blade compilation.
     */
    public static function render(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace("{{ {$key} }}", (string) $value, $template);
            $template = str_replace("{{$key}}", (string) $value, $template); // no spaces variant
        }
        return $template;
    }

    /**
     * Extract variable names from a template string.
     * Returns array of variable names found in {{ ... }} placeholders.
     */
    public static function extractVariables(string $template): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $template, $matches);
        return array_unique($matches[1] ?? []);
    }
}
