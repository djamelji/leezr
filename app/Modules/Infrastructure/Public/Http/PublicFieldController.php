<?php

namespace App\Modules\Infrastructure\Public\Http;

use App\Core\Fields\FieldDefinition;
use App\Core\Jobdomains\JobdomainPresetResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-290: Public (no auth) endpoint for company-scope field definitions.
 * Returns field definitions for a (jobdomain, market) pair before registration.
 */
class PublicFieldController extends Controller
{
    /**
     * GET /api/public/fields?jobdomain={key}&market={key}
     */
    public function companyFields(Request $request): JsonResponse
    {
        $request->validate([
            'jobdomain' => ['required', 'string', 'exists:jobdomains,key'],
            'market' => ['sometimes', 'string', 'exists:markets,key'],
        ]);

        $jobdomainKey = $request->query('jobdomain');
        $marketKey = $request->query('market');
        $locale = $request->header('X-Locale', 'en');

        // Resolve preset fields for this (jobdomain, market) pair
        $presets = JobdomainPresetResolver::resolve($jobdomainKey, $marketKey);
        $presetCodes = collect($presets->fields)->pluck('code')->toArray();
        $presetOrder = collect($presets->fields)->keyBy('code');

        // Look up full definitions from system catalog (company_id = null)
        $definitions = FieldDefinition::whereNull('company_id')
            ->where('scope', FieldDefinition::SCOPE_COMPANY)
            ->whereIn('code', $presetCodes)
            ->get();

        // Filter by market applicability (e.g. siret only for FR)
        if ($marketKey) {
            $definitions = $definitions->filter(function ($def) use ($marketKey) {
                $markets = $def->validation_rules['applicable_markets'] ?? null;

                return $markets === null || in_array($marketKey, $markets);
            });
        }

        // Map to frontend-consumable shape for DynamicFormRenderer
        $fields = $definitions->map(function ($def) use ($locale, $presetOrder) {
            $category = $def->validation_rules['category'] ?? null;
            $group = match ($category) {
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
                'required' => $def->validation_rules['required'] ?? false,
                'max' => $def->validation_rules['max'] ?? null,
                'pattern' => $def->validation_rules['pattern'] ?? null,
                'group' => $group,
                'order' => $presetOrder->get($def->code)['order'] ?? $def->default_order ?? 999,
            ];
        })
            ->sortBy('order')
            ->values();

        return response()->json(['fields' => $fields]);
    }
}
