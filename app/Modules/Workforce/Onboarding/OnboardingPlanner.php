<?php

namespace App\Modules\Workforce\Onboarding;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Fields\EmployeeFieldStore;
use App\Core\Fields\FieldOwnership;
use App\Core\Fields\FieldResolverService;
use App\Core\Models\Company;
use App\Core\Modules\Contributions\CompanyModuleContributions;
use App\Core\Requirements\EffectiveRequirementResolver;
use App\Core\Workforce\JobRole;
use Illuminate\Validation\ValidationException;

/**
 * ADR-796 (L6) — PREPARATION mode of the requirements of ONE onboarding file. Reads, never decides :
 *   - historical (operational) rules : ADR-790 (`DocumentResolverService::requirementMap`, field activations) ;
 *   - configured / adopted rules for the selected position : L1 registry (EffectiveRequirementResolver) ;
 *   - module proposals NOT adopted yet (L4) — shown, never adopted by this screen.
 * Divergences between sources are reported. HR selects explicitly ; `snapshot()` freezes the selection for
 * THIS file only (no obligation reaches any existing employee, the operational engine is untouched).
 * Only what the candidate may provide is offered : PERSONAL fields and personal documents.
 */
final class OnboardingPlanner
{
    public function __construct(private Company $company) {}

    public function propose(?JobRole $job): array
    {
        $store = new EmployeeFieldStore($this->company);
        $locale = FieldResolverService::requestLocale();
        $resolved = EffectiveRequirementResolver::forCompany($this->company)->resolveFor(EffectiveRequirementResolver::subjectForJobRole($job));
        $historicalDocs = DocumentResolverService::requirementMap($this->company->id, null, $this->company->market_key, DocumentType::SCOPE_COMPANY_USER);
        $activations = \App\Core\Fields\FieldActivation::where('company_id', $this->company->id)->where('enabled', true)->pluck('required_override', 'field_definition_id');
        $proposals = collect((new CompanyModuleContributions($this->company))->overview())
            ->flatMap(fn ($m) => collect($m['proposals'])->filter(fn ($p) => in_array($p['status'], ['optional', 'proposed_required'], true))
                ->map(fn ($p) => ['key' => $p['kind'].':'.$p['code'], 'module' => $m['name'], 'required' => $p['status'] === 'proposed_required']))
            ->groupBy('key');

        $items = [];
        foreach ($store->definitions() as $def) {
            if (FieldOwnership::of($def) !== FieldOwnership::PERSON) {
                continue; // donnée RH de l'entreprise : jamais saisie par le candidat
            }
            $items[] = $this->item('field', $def->code, $def->resolvedLabel($locale), (bool) ($def->validation_rules['sensitive'] ?? false),
                (bool) ($activations[$def->id] ?? false), $resolved['field:'.$def->id] ?? null, $proposals['field:'.$def->code] ?? collect());
        }
        foreach ($historicalDocs as $typeId => $entry) {
            $type = $entry['type'];
            $items[] = $this->item('document', $type->code, $type->validation_rules['translations'][$locale] ?? $type->label,
                DocumentConfidentiality::isHighlyConfidential($type->code), (bool) $entry['required'], $resolved['document:'.$typeId] ?? null, $proposals['document:'.$type->code] ?? collect());
        }

        return $items;
    }

    /**
     * @param  list<array{kind: string, code: string, required?: bool}>  $selection
     * @return array{version: int, items: list<array>}
     */
    public function snapshot(?JobRole $job, array $selection, int $version = 1): array
    {
        $proposed = collect($this->propose($job))->keyBy(fn ($i) => $i['kind'].':'.$i['code']);
        $items = [];
        foreach ($selection as $s) {
            $p = $proposed[($s['kind'] ?? '').':'.($s['code'] ?? '')] ?? null;
            if (! $p) {
                throw ValidationException::withMessages(['items' => __('onboarding.unknown_item')]);
            }
            $items[] = ['kind' => $p['kind'], 'code' => $p['code'], 'label' => $p['label'], 'sensitive' => $p['sensitive'],
                'required' => (bool) ($s['required'] ?? $p['suggested_required']), 'sources' => $p['sources']];
        }
        if ($items === []) {
            throw ValidationException::withMessages(['items' => __('onboarding.empty_selection')]);
        }

        return ['version' => $version, 'job_role_id' => $job?->id, 'frozen_at' => now()->toIso8601String(), 'items' => $items];
    }

    private function item(string $kind, string $code, string $label, bool $sensitive, bool $historical, ?array $configured, $proposals): array
    {
        $sources = [];
        if ($historical) {
            $sources[] = ['kind' => 'historical', 'required' => true];
        }
        if ($configured && ! $configured['waived']) {
            $sources[] = ['kind' => 'configured', 'required' => (bool) $configured['required'], 'origin' => $configured['retained']['source'] ?? null];
        }
        foreach ($proposals as $p) {
            $sources[] = ['kind' => 'module_proposal', 'required' => $p['required'], 'module' => $p['module']];
        }
        $divergence = $historical && $configured && ($configured['waived'] || ! $configured['required'])
            ? 'historical_required_configured_not'
            : (! $historical && $configured && $configured['required'] ? 'configured_required_historical_not' : null);

        return ['kind' => $kind, 'code' => $code, 'label' => $label, 'sensitive' => $sensitive, 'sources' => $sources,
            // Pré-sélection : règles EN VIGUEUR seulement ; une proposition de module non adoptée reste visible, jamais cochée d'office.
            'suggested' => collect($sources)->contains(fn ($s) => $s['kind'] !== 'module_proposal'), 'suggested_required' => collect($sources)->contains(fn ($s) => $s['required'] && $s['kind'] !== 'module_proposal'),
            'divergence' => $divergence, 'waived' => (bool) ($configured['waived'] ?? false)];
    }
}
