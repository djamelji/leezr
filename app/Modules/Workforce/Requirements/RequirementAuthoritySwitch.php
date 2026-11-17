<?php

namespace App\Modules\Workforce\Requirements;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\Services\PersonPopulationResolver;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Requirements\RequirementAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-818 — explicit, per-company switch of the requirement authority (role → position, and back).
 *  - preview : per person, the documents the historical (role) decision requires vs the target (position)
 *    decision : unchanged, newly required, no longer required — read-only (rolled back) ;
 *  - switch to `position` : refused while documents would stop being required unless the company
 *    acknowledges each loss explicitly (`accept_losses`) — ambiguous history is never applied silently ;
 *  - no request is created, regenerated or cancelled ; existing documents and requests stay as they are ;
 *    only the expected list of NEW requests, views and compliance follow the new authority ;
 *  - switching back to `role` restores the historical computation at once (nothing migrated).
 */
final class RequirementAuthoritySwitch
{
    public function __construct(private Company $company) {}

    public function state(): array
    {
        $row = DB::table('company_document_settings')->where('company_id', $this->company->id)->first(['requirements_authority', 'requirements_authority_at', 'requirements_authority_by']);

        return [
            'authority' => $row->requirements_authority ?? RequirementAuthority::ROLE,
            'switched_at' => $row->requirements_authority_at ?? null,
            'switched_by' => isset($row->requirements_authority_by) ? User::whereKey($row->requirements_authority_by)->value('first_name') : null,
        ];
    }

    public function preview(): array
    {
        DB::beginTransaction(); // la population peut rattacher des lignes legacy (ADR-770) : rien ne persiste ici
        try {
            $market = $this->company->market_key;
            $codes = DocumentType::pluck('code', 'id');
            $labels = collect((new RequirementConfigService($this->company))->catalog()['documents'])->pluck('label', 'code');
            $population = PersonPopulationResolver::forCompany($this->company);
            $hist = [];
            $target = [];
            $byDoc = [];
            $people = ['total' => 0, 'unchanged' => 0, 'changed' => 0, 'accountless' => 0];
            foreach ($population as $p) {
                $people['total']++;
                $p['account_less'] && $people['accountless']++;
                $h = $hist[$p['role_key'] ?? ''] ??= $this->required(DocumentResolverService::requirementMap($this->company->id, $p['role_key'] ?? null, $market, DocumentType::SCOPE_COMPANY_USER));
                $t = $target[$p['job_role_id'] ?? 0] ??= $this->required(DocumentResolverService::positionMap($this->company->id, $p['job_role_id'] ?? null, $market, DocumentType::SCOPE_COMPANY_USER));
                $gained = array_diff($t, $h);
                $lost = array_diff($h, $t);
                ($gained || $lost) ? $people['changed']++ : $people['unchanged']++;
                foreach (['gained' => $gained, 'lost' => $lost] as $kind => $ids) {
                    foreach ($ids as $id) {
                        $code = $codes[$id] ?? (string) $id;
                        $byDoc[$kind][$code] ??= ['code' => $code, 'label' => $labels[$code] ?? $code, 'people' => 0, 'accountless' => 0];
                        $byDoc[$kind][$code]['people']++;
                        $p['account_less'] && $byDoc[$kind][$code]['accountless']++;
                    }
                }
            }
        } finally {
            DB::rollBack();
        }
        $positionsWithout = DB::table('workforce_job_roles')->where('company_id', $this->company->id)
            ->whereNotIn('id', DB::table('requirements')->where('company_id', $this->company->id)->whereNotNull('applicability')
                ->get(['applicability'])->flatMap(fn ($r) => json_decode($r->applicability, true)['job_role_ids'] ?? [])->unique()->all())->count();

        return $this->state() + [
            'people' => $people,
            'gained' => array_values($byDoc['gained'] ?? []),
            'lost' => array_values($byDoc['lost'] ?? []),
            'positions_without_requirements' => $positionsWithout,
            'ready' => ($byDoc['lost'] ?? []) === [],
        ];
    }

    public function switch(string $authority, User $actor, bool $acceptLosses = false): array
    {
        if (! in_array($authority, [RequirementAuthority::ROLE, RequirementAuthority::POSITION], true)) {
            throw ValidationException::withMessages(['authority' => __('requirements.authority_unknown')]);
        }
        $preview = $authority === RequirementAuthority::POSITION ? $this->preview() : null;
        if ($preview && ! $preview['ready'] && ! $acceptLosses) {
            throw ValidationException::withMessages(['accept_losses' => __('requirements.authority_losses', ['n' => count($preview['lost'])])]);
        }
        \App\Core\Documents\CompanyDocumentSetting::forCompany($this->company->id); // ligne de réglages (créée si absente)
        DB::table('company_document_settings')->where('company_id', $this->company->id)
            ->update(['requirements_authority' => $authority, 'requirements_authority_at' => now(), 'requirements_authority_by' => $actor->id, 'updated_at' => now()]);
        RequirementAuthority::flush($this->company->id);
        \App\Core\Fields\MandatoryContext::flush(); // ADR-820 : les obligations implicites dépendent de l'autorité
        app(AuditLogger::class)->logCompany($this->company->id, 'requirements.authority_switched', 'company', (string) $this->company->id, [
            'actorId' => $actor->id, 'actorType' => 'user',
            'metadata' => ['authority' => $authority, 'accepted_losses' => $preview ? array_column($preview['lost'], 'code') : [], 'people' => $preview['people'] ?? null],
        ]);

        return $this->state();
    }

    /** @return list<int> required + visible document type ids */
    private function required(array $map): array
    {
        return array_keys(array_filter($map, fn ($e) => $e['visible'] && $e['required']));
    }
}
