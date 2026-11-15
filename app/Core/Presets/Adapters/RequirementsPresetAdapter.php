<?php

namespace App\Core\Presets\Adapters;

use App\Core\Documents\DocumentType;
use App\Core\Fields\FieldDefinition;
use App\Core\Models\Company;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementLevel;
use App\Core\Workforce\JobRole;
use Illuminate\Validation\ValidationException;

/**
 * ADR-793 (L3) — REQUIREMENTS family. Content :
 *   {items: [{kind: field|document, code, level: recommended|default|configurable_required,
 *             job_role_keys?: [..], stage?: hire|onboarding|ongoing}]}
 * Publish → TEMPLATE rows in the registry (company NULL, source preset, family + version) ; the previous
 * version's templates are superseded (kept : copies keep their derived_from). Apply → company COPIES
 * (derived_from = template). Never : values, files, DMS requests, positions, RBAC — and never
 * `imperative` (a preset is not a legal authority, L1 invariant).
 */
final class RequirementsPresetAdapter implements PresetFamilyAdapter
{
    public function kind(): string
    {
        return 'requirements';
    }

    public function validate(array $content, ?string $marketKey = null): void
    {
        $errors = [];
        foreach ($content['items'] ?? [] as $i => $item) {
            if (! in_array($item['kind'] ?? null, ['field', 'document'], true) || ! $this->targetId($item['kind'], (string) ($item['code'] ?? ''))) {
                $errors["items.{$i}.code"] = 'Unknown field or document definition.';
            }
            if (! in_array($item['level'] ?? null, [RequirementLevel::RECOMMENDED, RequirementLevel::DEFAULT, RequirementLevel::CONFIGURABLE_REQUIRED], true)) {
                $errors["items.{$i}.level"] = 'A preset proposes recommended, default or configurable_required only (never imperative).';
            }
            foreach ($item['job_role_keys'] ?? [] as $k) {
                if (! is_string($k) || ! preg_match('/^[a-z0-9_]{1,60}$/', $k)) {
                    $errors["items.{$i}.job_role_keys"] = 'Invalid position key.';
                }
            }
            if (isset($item['stage']) && ! in_array($item['stage'], ['hire', 'onboarding', 'ongoing'], true)) {
                $errors["items.{$i}.stage"] = 'Invalid stage.';
            }
        }
        if (empty($content['items'])) {
            $errors['items'] = 'A requirements preset needs at least one item.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function items(array $content): array
    {
        $out = [];
        foreach ($content['items'] ?? [] as $item) {
            $keys = $item['job_role_keys'] ?? [];
            sort($keys);
            $out[$item['kind'].':'.$item['code'].'@'.($keys ? implode(',', $keys) : 'all')] = $item;
        }

        return $out;
    }

    public function onPublish(object $preset): void
    {
        Requirement::whereNull('company_id')->where('source', 'preset')->where('source_ref', $preset->family_key)
            ->where('source_version', '<', $preset->version)->where('status', Requirement::STATUS_ACTIVE)
            ->get()->each(fn (Requirement $r) => $r->forceFill(['status' => Requirement::STATUS_SUPERSEDED, 'effective_until' => now()])->save());
        foreach ($this->items($preset->content) as $item) {
            $column = $item['kind'] === 'field' ? 'field_definition_id' : 'document_type_id';
            $targetId = $this->targetId($item['kind'], $item['code']);
            $exists = Requirement::whereNull('company_id')->where('source', 'preset')->where('source_ref', $preset->family_key)
                ->where('source_version', $preset->version)->where($column, $targetId)->get()
                ->contains(fn ($r) => $r->applicability == $this->applicability($item)); // comparaison JSON en PHP (portable)
            if (! $exists) {
                Requirement::create(['company_id' => null, 'source' => 'preset', 'source_ref' => $preset->family_key, 'source_version' => $preset->version,
                    'target_kind' => $item['kind'], $column => $targetId, 'applicability' => $this->applicability($item),
                    'level' => $item['level'], 'stage' => $item['stage'] ?? null, 'status' => Requirement::STATUS_ACTIVE]);
            }
        }
    }

    public function preview(Company $company, object $preset): array
    {
        $companyKeys = JobRole::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('preset_item_key')->pluck('preset_item_key')->all();
        $companyRows = Requirement::where('company_id', $company->id)->where('status', Requirement::STATUS_ACTIVE)->get();
        $out = [];
        foreach ($this->items($preset->content) as $key => $item) {
            $targetId = $this->targetId($item['kind'], $item['code']);
            $column = $item['kind'] === 'field' ? 'field_definition_id' : 'document_type_id';
            $missing = array_diff($item['job_role_keys'] ?? [], $companyKeys);
            $copy = $companyRows->first(fn ($r) => $r->source === 'preset' && $r->source_ref === $preset->family_key && $r->{$column} === $targetId && $r->applicability == $this->applicability($item));
            $own = $companyRows->first(fn ($r) => $r->source === 'company' && $r->{$column} === $targetId && $r->applicability == $this->applicability($item));
            [$status, $detail] = match (true) {
                ($item['job_role_keys'] ?? []) !== [] && count($missing) === count($item['job_role_keys']) => ['not_applicable', 'position_missing:'.implode(',', $missing)],
                $own && ($own->effect ?? 'require') === 'waive' => ['conflict', 'waived_by_company'],
                $copy && $copy->customized_at !== null => ['customized', null],
                $copy && (int) $copy->source_version < (int) $preset->version => ['requires_confirmation', 'update_from_v'.$copy->source_version],
                $copy && $copy->level === $item['level'] => ['identical', null],
                $own !== null => ['customized', 'company_already_requires'],
                default => ['new', null],
            };
            $out[] = ['key' => $key, 'label' => $item['kind'].':'.$item['code'], 'status' => $status, 'detail' => $detail];
        }

        return $out;
    }

    public function apply(Company $company, object $preset, ?array $selected, ?int $actorId): array
    {
        $this->onPublish($preset); // idempotent : modèles présents pour cette version
        $done = ['created' => [], 'updated' => [], 'skipped' => []];
        foreach ($this->preview($company, $preset) as $p) {
            $wanted = $selected === null ? $p['status'] === 'new' : in_array($p['key'], $selected, true) && in_array($p['status'], ['new', 'requires_confirmation'], true);
            if (! $wanted) {
                $done['skipped'][] = $p['key'];

                continue;
            }
            $item = $this->items($preset->content)[$p['key']];
            $column = $item['kind'] === 'field' ? 'field_definition_id' : 'document_type_id';
            $targetId = $this->targetId($item['kind'], $item['code']);
            $template = Requirement::whereNull('company_id')->where('source', 'preset')->where('source_ref', $preset->family_key)->where('source_version', $preset->version)->where($column, $targetId)->get()
                ->first(fn ($r) => $r->applicability == $this->applicability($item));
            $previous = Requirement::where('company_id', $company->id)->where('source', 'preset')->where('source_ref', $preset->family_key)->where('status', Requirement::STATUS_ACTIVE)->where($column, $targetId)->get()
                ->first(fn ($r) => $r->applicability == $this->applicability($item));
            if ($previous) {
                $previous->forceFill(['status' => Requirement::STATUS_SUPERSEDED, 'effective_until' => now(), 'reason' => 'preset_update'])->save();
            }
            Requirement::create([
                'company_id' => $company->id, 'target_kind' => $item['kind'], $column => $targetId, 'level' => $item['level'], 'stage' => $item['stage'] ?? null,
                'applicability' => $this->applicability($item), 'source' => 'preset', 'source_ref' => $preset->family_key, 'source_version' => $preset->version,
                'derived_from_id' => $template?->id, 'status' => Requirement::STATUS_ACTIVE, 'effective_from' => now(), 'created_by' => $actorId,
            ]);
            $done[$previous ? 'updated' : 'created'][] = $p['key'];
        }

        return $done;
    }

    private function applicability(array $item): ?array
    {
        $keys = $item['job_role_keys'] ?? [];
        sort($keys);

        return $keys ? ['job_role_keys' => array_values($keys)] : null;
    }

    private function targetId(string $kind, string $code): ?int
    {
        $id = $kind === 'field'
            ? FieldDefinition::whereNull('company_id')->where('code', $code)->where('scope', FieldDefinition::SCOPE_COMPANY_USER)->value('id')
            : DocumentType::where('is_system', true)->where('code', $code)->where('scope', DocumentType::SCOPE_COMPANY_USER)->value('id');

        return $id ? (int) $id : null;
    }
}
