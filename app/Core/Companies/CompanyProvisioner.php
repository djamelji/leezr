<?php

namespace App\Core\Companies;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldWriteService;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\Modernization\VersionedCompanyInitializer;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use Illuminate\Support\Str;

/**
 * ADR-834 — THE company creation engine, shared by the public registration (RegisterCompanyUseCase) and the
 * Platform creation wizard (CreatePlatformCompanyUseCase). Callers own the transaction, the owner account and
 * the subscription ; this class owns what a company IS at birth : identity, single activity, initial
 * configuration, legal status and company fields.
 */
final class CompanyProvisioner
{
    /** ADR-165 : explicit market > default market > null. */
    public static function resolveMarketKey(?string $marketKey): ?string
    {
        return $marketKey ?: Market::where('is_default', true)->value('key');
    }

    /** ADR-167a : the jobdomain is required — a company has exactly ONE activity (scalar column). */
    public static function create(string $name, ?string $marketKey, string $jobdomainKey, string $planKey): Company
    {
        return Company::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'plan_key' => $planKey,
            'market_key' => $marketKey,
            'jobdomain_key' => $jobdomainKey,
        ]);
    }

    /**
     * ADR-100 / ADR-798 / ADR-808 / ADR-820 : assigns the activity and its initial configuration (modules
     * included by the domain, roles, field & document library). Versioned initialisation when operational for
     * this domain/market, historical path otherwise. Only the `owner` role (sensitive) is confirmed here ; the
     * other sensitive roles and the explicit decisions (planning, organisation) stay with the company.
     *
     * `$decisions` : explicit decisions taken by whoever creates the company (`planning`, `organization` booleans) ;
     * absent = left to the company. Requirements are never decided here (ADR-820).
     *
     * @param  array{planning?: bool, organization?: bool}  $decisions
     * @return array{path: string, summary: array}
     */
    public static function initializeActivity(Company $company, string $jobdomainKey, ?int $ownerUserId, array $decisions = []): array
    {
        if (VersionedCompanyInitializer::shouldUse($jobdomainKey, $company->market_key)) {
            $confirm = ['rbac' => ['owner']] + array_intersect_key(array_filter($decisions), ['planning' => true, 'organization' => true]);

            return ['path' => 'versioned', 'summary' => VersionedCompanyInitializer::initializeNew($company, $jobdomainKey, $confirm, $ownerUserId)];
        }
        JobdomainGate::assignToCompany($company, $jobdomainKey);

        return ['path' => 'historical', 'summary' => []];
    }

    /** ADR-290 / ADR-300 : legal status, dynamic company fields, optional copy of the address to billing. */
    public static function applyIdentity(Company $company, ?string $legalStatusKey, array $dynamicFields, bool $billingSameAsCompany): void
    {
        if ($legalStatusKey) {
            $company->update(['legal_status_key' => $legalStatusKey]);
        }
        if ($dynamicFields === []) {
            return;
        }
        FieldWriteService::upsert($company, $dynamicFields, FieldDefinition::SCOPE_COMPANY, $company->id, $company->market_key);

        if (! $billingSameAsCompany) {
            return;
        }
        $addressMap = [
            'company_address' => 'billing_address',
            'company_complement' => 'billing_complement',
            'company_city' => 'billing_city',
            'company_postal_code' => 'billing_postal_code',
            'company_region' => 'billing_region',
        ];
        $billing = [];
        foreach ($addressMap as $src => $dst) {
            if (isset($dynamicFields[$src])) {
                $billing[$dst] = $dynamicFields[$src];
            }
        }
        if ($billing !== []) {
            FieldWriteService::upsert($company, $billing, FieldDefinition::SCOPE_COMPANY, $company->id, $company->market_key);
        }
    }
}
