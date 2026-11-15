<?php

namespace App\Core\Presets\Adapters;

use App\Core\Models\Company;
use App\Modules\Workforce\Organization\OrganizationPresetService;
use App\Modules\Workforce\Organization\OrganizationPresetStructureValidator;
use Illuminate\Validation\ValidationException;

/**
 * ADR-793 (L3) — ORGANIZATION family : the existing engine, unchanged (ADR-636/638) — table
 * `organization_presets`, validator, diff and idempotent apply (a version is now passed explicitly ;
 * without it, the historical resolution applies). Its statuses are mapped to the common vocabulary.
 */
final class OrganizationPresetAdapter implements PresetFamilyAdapter
{
    private const STATUS = ['new' => 'new', 'existing' => 'identical', 'customized' => 'customized', 'conflict' => 'conflict'];

    public function kind(): string
    {
        return 'organization';
    }

    public function validate(array $content, ?string $marketKey = null): void
    {
        $errors = OrganizationPresetStructureValidator::validate($content);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function items(array $content): array
    {
        $out = [];
        foreach ($content['structure']['departments'] ?? [] as $d) {
            $out['department:'.$d['key']] = $d;
        }
        foreach ($content['structure']['job_roles'] ?? [] as $r) {
            $out['job_role:'.$r['key']] = $r;
        }

        return $out;
    }

    public function onPublish(object $preset): void {}

    public function preview(Company $company, object $preset): array
    {
        $diff = OrganizationPresetService::diffAgainst($company, $preset);
        $out = [];
        foreach ($diff['departments'] as $d) {
            $out[] = ['key' => 'department:'.$d['key'], 'label' => $d['name'], 'status' => self::STATUS[$d['status']] ?? $d['status'], 'detail' => null];
        }
        foreach ($diff['job_roles'] as $r) {
            $out[] = ['key' => 'job_role:'.$r['key'], 'label' => $r['title'], 'status' => self::STATUS[$r['status']] ?? $r['status'], 'detail' => null];
        }

        return $out;
    }

    public function apply(Company $company, object $preset, ?array $selected, ?int $actorId): array
    {
        // Le moteur d'organisation applique la structure entière, idempotent (existants préservés) :
        // une sélection partielle n'est pas supportée par ce moteur → refus explicite plutôt qu'un effet inattendu.
        if ($selected !== null) {
            throw ValidationException::withMessages(['selected' => 'The organization engine applies the whole structure (existing items preserved) ; partial selection is not supported.']);
        }

        return OrganizationPresetService::apply($company, $actorId, $preset);
    }
}
