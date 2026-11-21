<?php

namespace App\Core\Fields;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainPresetResolver;

/**
 * ADR-290 / ADR-834 / ADR-835 — the company-scope fields collected when a company is CREATED, for a
 * (jobdomain, market) pair. ONE rule for the public sign-up and the Platform creation wizard, server and client.
 *
 * Inheritance (ADR-835) :
 *  - global catalog (FieldDefinition.validation_rules, administered — SystemSync never overwrites a Platform edit) :
 *    required, applicable_markets, default, legal_required_markets, + technical limits (max, pattern, options) ;
 *  - JobDomain : shown ← the field is in the JobDomain's field list (preset + market overlay) ;
 *  - JobDomain customisation (JobdomainCompanyField, each attribute null = inherited) : visible, required,
 *    markets (⊆ catalog markets), default_value ;
 *  - legal floor : on a market of legal_required_markets the field is always shown and required.
 */
final class CompanyCreationFields
{
    /**
     * Every company field of the global catalog with its inherited, customised and effective (market-agnostic)
     * values for a JobDomain — the Platform « Fields » tab.
     *
     * @return list<array<string, mixed>>
     */
    public static function configuration(Jobdomain $jobdomain, string $locale = 'en'): array
    {
        $presetCodes = collect(JobdomainPresetResolver::resolve($jobdomain->key, null)->fields)->keyBy('code');
        $custom = JobdomainCompanyField::where('jobdomain_id', $jobdomain->id)->get()->keyBy('field_definition_id');

        return self::definitions()->map(function (FieldDefinition $def) use ($presetCodes, $custom, $locale) {
            $inherited = self::inherited($def, $presetCodes->has($def->code));
            $row = $custom->get($def->id);
            $customised = $row ? array_filter([
                'visible' => $row->visible,
                'required' => $row->required,
                'markets' => $row->markets,
                'default' => $row->default_value,
            ], fn ($v) => $v !== null) : [];

            return [
                'id' => $def->id,
                'code' => $def->code,
                'label' => $def->resolvedLabel($locale),
                'type' => $def->type,
                'options' => $def->options,
                'max' => $def->validation_rules['max'] ?? null,
                'pattern' => $def->validation_rules['pattern'] ?? null,
                'catalog_markets' => $def->validation_rules['applicable_markets'] ?? null,
                'legal_required_markets' => self::legalMarkets($def),
                'inherited' => $inherited,
                'custom' => $customised === [] ? null : $customised,
                'effective' => array_replace($inherited, $customised),
                'order' => $presetCodes->get($def->code)['order'] ?? $def->default_order ?? 999,
            ];
        })->sortBy('order')->values()->all();
    }

    /**
     * The fields shown for a (jobdomain, market) creation form, with their effective rules.
     *
     * @return list<array{code: string, label: string, type: string, options: mixed, required: bool, max: ?int, pattern: ?string, group: string, order: int, default: ?string}>
     */
    public static function resolve(string $jobdomainKey, ?string $marketKey, string $locale = 'en'): array
    {
        $jobdomain = Jobdomain::where('key', $jobdomainKey)->first();
        if (! $jobdomain) {
            return [];
        }
        $presetCodes = collect(JobdomainPresetResolver::resolve($jobdomainKey, $marketKey)->fields)->keyBy('code');
        $custom = JobdomainCompanyField::where('jobdomain_id', $jobdomain->id)->get()->keyBy('field_definition_id');

        return self::definitions()->map(function (FieldDefinition $def) use ($presetCodes, $custom, $marketKey, $locale) {
            $row = $custom->get($def->id);
            $inherited = self::inherited($def, $presetCodes->has($def->code));
            $visible = $row?->visible ?? $inherited['visible'];
            $required = $row?->required ?? $inherited['required'];
            $markets = self::withinCatalog($row?->markets ?? $inherited['markets'], $inherited['markets']);
            $default = $row?->default_value ?? $inherited['default'];

            if ($marketKey !== null) {
                if ($inherited['markets'] !== null && ! in_array($marketKey, $inherited['markets'], true)) {
                    return null; // the catalog does not apply this field to this market
                }
                if (in_array($marketKey, self::legalMarkets($def), true)) {
                    $visible = $required = true; // legal floor : never hidden nor optional on this market
                    $markets = null;
                }
                if ($markets !== null && ! in_array($marketKey, $markets, true)) {
                    return null;
                }
            }
            if (! $visible) {
                return null;
            }

            return [
                'code' => $def->code,
                'label' => $def->resolvedLabel($locale),
                'type' => $def->type,
                'options' => $def->options,
                'required' => (bool) $required,
                'max' => $def->validation_rules['max'] ?? null,
                'pattern' => $def->validation_rules['pattern'] ?? null,
                'group' => match ($def->validation_rules['category'] ?? null) {
                    'billing' => 'billing',
                    'address' => 'address',
                    'contact' => 'contact',
                    default => 'general',
                },
                'order' => $presetCodes->get($def->code)['order'] ?? $def->default_order ?? 999,
                'default' => ($default === null || $default === '') ? null : (string) $default,
            ];
        })->filter()->sortBy('order')->values()->all();
    }

    /**
     * Server-side check shared by BOTH creation paths. Returns the values to store (trimmed, empty dropped,
     * default applied to a shown field left empty) and the errors keyed by field code.
     *
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public static function validate(string $jobdomainKey, ?string $marketKey, array $values, string $locale = 'en'): array
    {
        $fields = collect(self::resolve($jobdomainKey, $marketKey, $locale))->keyBy('code');
        $errors = [];
        $out = [];
        foreach ($values as $code => $value) {
            if (! $fields->has($code) && trim((string) $value) !== '') {
                $errors[$code] = __('platformCompanyCreation.errors.unknownField');
            }
        }
        foreach ($fields as $code => $f) {
            $v = trim((string) ($values[$code] ?? ''));
            if ($v === '' && $f['default'] !== null) {
                $v = $f['default'];
            }
            $problem = self::valueProblem($f, $v);
            if ($problem !== null) {
                $errors[$code] = __('platformCompanyCreation.errors.'.$problem, ['field' => $f['label']]);
            } elseif ($v !== '') {
                $out[$code] = $v;
            }
        }

        return ['values' => $out, 'errors' => $errors];
    }

    /** null when the value is acceptable for the field ; otherwise the error key (fieldRequired, fieldTooLong…). */
    public static function valueProblem(array $field, string $value): ?string
    {
        if ($value === '') {
            return ! empty($field['required']) ? 'fieldRequired' : null;
        }
        if (! empty($field['max']) && mb_strlen($value) > (int) $field['max']) {
            return 'fieldTooLong';
        }
        if (! self::matchesFormat($field['pattern'] ?? null, $value)) {
            return 'fieldFormat';
        }
        $options = self::optionValues($field);
        if (($field['type'] ?? null) === FieldDefinition::TYPE_SELECT && $options !== [] && ! in_array($value, $options, true)) {
            return 'fieldFormat';
        }

        return null;
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

    /** @return list<string> markets where the law imposes the field (global catalog) */
    public static function legalMarkets(FieldDefinition $def): array
    {
        return array_values(array_filter((array) ($def->validation_rules['legal_required_markets'] ?? []), 'is_string'));
    }

    /** Codes a JobDomain shows at creation although they are not in its field list (activated for the company). */
    public static function extraVisibleCodes(string $jobdomainKey): array
    {
        $jobdomain = Jobdomain::where('key', $jobdomainKey)->first();
        if (! $jobdomain) {
            return [];
        }

        return JobdomainCompanyField::where('jobdomain_id', $jobdomain->id)->where('visible', true)
            ->with('definition:id,code')->get()->pluck('definition.code')->filter()->values()->all();
    }

    /** @return list<string> */
    private static function optionValues(array $field): array
    {
        return array_map(fn ($o) => is_array($o) ? (string) ($o['value'] ?? '') : (string) $o, (array) ($field['options'] ?? []));
    }

    /** @return \Illuminate\Support\Collection<int, FieldDefinition> */
    private static function definitions()
    {
        return FieldDefinition::whereNull('company_id')->where('scope', FieldDefinition::SCOPE_COMPANY)->get();
    }

    private static function inherited(FieldDefinition $def, bool $inFieldList): array
    {
        $rules = $def->validation_rules ?? [];
        $default = $rules['default'] ?? null;

        return [
            'visible' => $inFieldList,
            'required' => (bool) ($rules['required'] ?? false),
            'markets' => isset($rules['applicable_markets']) ? array_values((array) $rules['applicable_markets']) : null,
            'default' => ($default === null || $default === '') ? null : (string) $default,
        ];
    }

    /** A JobDomain never widens the catalog's markets (technical limit). */
    private static function withinCatalog(?array $markets, ?array $catalog): ?array
    {
        if ($markets === null) {
            return $catalog;
        }

        return $catalog === null ? array_values($markets) : array_values(array_intersect($markets, $catalog));
    }
}
