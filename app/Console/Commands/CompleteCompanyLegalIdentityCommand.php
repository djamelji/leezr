<?php

namespace App\Console\Commands;

use App\Company\Fields\ReadModels\CompanyLegalIdentity;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Models\Company;
use App\Core\Workforce\Dsn\DsnCompanyResolver;
use Illuminate\Console\Command;

/**
 * ADR-785 — explicit, idempotent completion of the Company legal identity for EXISTING companies.
 *
 *  1. Activates the `naf_code` profile field where its market applies and no activation exists yet
 *     (an activation explicitly DISABLED by the company is left as is and reported).
 *  2. Copies VALID historical column values into EMPTY profile fields only : SIRET (Luhn), NAF/APE
 *     (format), street, complement, postal code, city. Never overwrites a profile value, never invents.
 *
 * Non-destructive (no delete, no overwrite). `--dry-run` reports without writing.
 */
class CompleteCompanyLegalIdentityCommand extends Command
{
    protected $signature = 'company:complete-legal-identity {--dry-run} {--company= : one company id}';

    protected $description = 'Activate the NAF profile field and reuse valid historical legal identity values in empty profile fields (ADR-785)';

    /** profile code => [legacy column, validator] */
    private const BACKFILL = [
        'siret' => ['siret', 'siret'],
        'naf_code' => ['naf_code', 'naf'],
        'company_address' => ['address_street', null],
        'company_complement' => ['address_complement', null],
        'company_postal_code' => ['address_postal_code', null],
        'company_city' => ['address_city', null],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $defs = FieldDefinition::whereNull('company_id')->where('scope', FieldDefinition::SCOPE_COMPANY)
            ->whereIn('code', array_keys(self::BACKFILL))->get()->keyBy('code');
        $naf = $defs->get('naf_code');
        if (! $naf) {
            $this->error('naf_code definition missing — run FieldDefinitionCatalog::sync() first.');

            return self::FAILURE;
        }

        $report = ['dry_run' => $dry, 'naf_activated' => [], 'naf_disabled_by_company' => [], 'backfilled' => [], 'skipped_invalid' => []];
        $companies = Company::withoutGlobalScopes()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($companies as $company) {
            $markets = $naf->validation_rules['applicable_markets'] ?? null;
            if ($markets === null || in_array($company->market_key, $markets, true)) {
                $activation = FieldActivation::where('company_id', $company->id)->where('field_definition_id', $naf->id)->first();
                if (! $activation) {
                    $dry || FieldActivation::create(['company_id' => $company->id, 'field_definition_id' => $naf->id, 'enabled' => true, 'required_override' => false, 'order' => $naf->default_order ?? 12]);
                    $report['naf_activated'][] = $company->id;
                } elseif (! $activation->enabled) {
                    $report['naf_disabled_by_company'][] = $company->id;
                }
            }

            foreach (self::BACKFILL as $code => [$column, $validator]) {
                $def = $defs->get($code);
                $legacy = $company->getAttribute($column);
                if (! $def || $legacy === null || trim((string) $legacy) === '') {
                    continue;
                }
                $active = $code === 'naf_code' || FieldActivation::where('company_id', $company->id)->where('field_definition_id', $def->id)->where('enabled', true)->exists();
                if (! $active) {
                    continue;
                }
                $current = FieldValue::where('field_definition_id', $def->id)->where('model_type', 'company')->where('model_id', $company->id)->value('value');
                if ($current !== null && trim((string) $current) !== '') {
                    continue; // never overwrite the profile
                }
                $value = trim((string) $legacy);
                if ($validator === 'siret' && ! DsnCompanyResolver::validateSiret($value)) {
                    $report['skipped_invalid'][] = "{$company->id}:{$code}";

                    continue;
                }
                if ($validator === 'naf') {
                    $value = CompanyLegalIdentity::normalizeNaf($value);
                    if (! DsnCompanyResolver::validateNafCode($value)) {
                        $report['skipped_invalid'][] = "{$company->id}:{$code}";

                        continue;
                    }
                }
                $dry || FieldValue::updateOrCreate(['field_definition_id' => $def->id, 'model_type' => 'company', 'model_id' => $company->id], ['value' => $value]);
                $report['backfilled'][] = "{$company->id}:{$code}";
            }
        }

        $this->line(json_encode($report));

        return self::SUCCESS;
    }
}
