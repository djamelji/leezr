<?php

namespace App\Core\Fields;

use App\Core\Jobdomains\JobdomainPresetResolver;

/**
 * ADR-290 / ADR-834 — the company-scope fields collected when a company is CREATED, for a (jobdomain, market)
 * pair : preset fields of the domain, system definitions only, filtered by market applicability.
 * Single source for the public registration form, the Platform creation wizard and the server-side validation
 * of required fields (a field required by the market is required whatever the entry point).
 */
final class CompanyCreationFields
{
    /**
     * @return list<array{code: string, label: string, type: string, options: mixed, required: bool, max: ?int, pattern: ?string, group: string, order: int}>
     */
    public static function resolve(string $jobdomainKey, ?string $marketKey, string $locale = 'en'): array
    {
        $presets = JobdomainPresetResolver::resolve($jobdomainKey, $marketKey);
        $presetOrder = collect($presets->fields)->keyBy('code');

        $definitions = FieldDefinition::whereNull('company_id')
            ->where('scope', FieldDefinition::SCOPE_COMPANY)
            ->whereIn('code', $presetOrder->keys()->all())
            ->get();

        if ($marketKey) {
            $definitions = $definitions->filter(function ($def) use ($marketKey) {
                $markets = $def->validation_rules['applicable_markets'] ?? null;

                return $markets === null || in_array($marketKey, $markets);
            });
        }

        return $definitions->map(function ($def) use ($locale, $presetOrder) {
            $group = match ($def->validation_rules['category'] ?? null) {
                'billing' => 'billing',
                'address' => 'address',
                'contact' => 'contact',
                default => 'general',
            };

            return [
                'code' => $def->code,
                'label' => $def->resolvedLabel($locale),
                'type' => $def->type,
                'options' => $def->options,
                'required' => (bool) ($def->validation_rules['required'] ?? false),
                'max' => $def->validation_rules['max'] ?? null,
                'pattern' => $def->validation_rules['pattern'] ?? null,
                'group' => $group,
                'order' => $presetOrder->get($def->code)['order'] ?? $def->default_order ?? 999,
            ];
        })->sortBy('order')->values()->all();
    }

    /**
     * Same rule as FieldValidationService (ADR-785) : only a « / »-delimited pattern is a regular expression ;
     * « email », « phone »… are rendering hints and never refuse a value.
     */
    public static function matchesFormat(?string $pattern, string $value): bool
    {
        if (! is_string($pattern) || strlen($pattern) <= 2 || $pattern[0] !== '/') {
            return true;
        }

        return @preg_match($pattern, $value) !== 0;
    }
}
