<?php

namespace App\Core\Presets;

use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Presets\Adapters\OrganizationPresetAdapter;
use App\Core\Presets\Adapters\PlanningPresetAdapter;
use App\Core\Presets\Adapters\PresetFamilyAdapter;
use App\Core\Presets\Adapters\RbacPresetAdapter;
use App\Core\Presets\Adapters\RequirementsPresetAdapter;
use App\Modules\Workforce\Organization\OrganizationPreset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-793 (L3) — common orchestration of versioned presets (four families). Versioning = the shared
 * VersionedPresetLifecycle ; family logic = adapters ; nothing here is wired to company creation.
 * A new version is NEVER applied automatically : apply() is always an explicit call, on a published
 * version, inside a transaction with the company row locked, recorded and audited.
 */
final class PresetService
{
    public const KINDS = ['rbac', 'organization', 'requirements', 'planning'];

    public static function adapter(string $kind): PresetFamilyAdapter
    {
        return match ($kind) {
            'rbac' => new RbacPresetAdapter(),
            'organization' => new OrganizationPresetAdapter(),
            'requirements' => new RequirementsPresetAdapter(),
            'planning' => new PlanningPresetAdapter(),
            default => throw ValidationException::withMessages(['kind' => 'Unknown preset family.']),
        };
    }

    public static function find(string $kind, int $id): Model
    {
        return $kind === 'organization' ? OrganizationPreset::findOrFail($id) : Preset::where('kind', $kind)->findOrFail($id);
    }

    public static function content(Model $preset): array
    {
        return $preset instanceof OrganizationPreset
            ? $preset->only(['jobdomain_key', 'market_key', 'org_profile_key', 'structure'])
            : (array) $preset->content;
    }

    // ── Catalogue (RBAC, Exigences, Planning ; l'Organisation garde son éditeur existant) ─────────

    public static function createDraft(string $kind, array $attrs, ?int $by): Preset
    {
        abort_if(! in_array($kind, Preset::KINDS, true), 422, 'Organization presets keep their dedicated editor.');
        self::adapter($kind)->validate($attrs['content'] ?? [], $attrs['market_key'] ?? null);
        $family = $attrs['family_key'];
        $version = (int) Preset::where('family_key', $family)->max('version') + 1;

        return Preset::create($attrs + ['kind' => $kind, 'version' => $version, 'key' => $family.'_v'.$version, 'status' => 'draft', 'created_by' => $by, 'updated_by' => $by]);
    }

    public static function updateDraft(Preset $preset, array $attrs, ?int $by): Preset
    {
        if (! $preset->isEditable()) {
            throw ValidationException::withMessages(['status' => 'A published version is immutable : create a new version.']);
        }
        self::adapter($preset->kind)->validate($attrs['content'] ?? $preset->content, $attrs['market_key'] ?? $preset->market_key);
        $preset->fill($attrs + ['updated_by' => $by])->save();

        return $preset;
    }

    public static function publish(Model $preset, ?int $by): void
    {
        $adapter = self::adapter($preset instanceof OrganizationPreset ? 'organization' : $preset->kind);
        $adapter->validate(self::content($preset), $preset->market_key);
        DB::transaction(function () use ($preset, $by, $adapter) {
            $preset->publishVersion($by);
            $adapter->onPublish($preset);
        });
    }

    /** Item-level comparison of two versions of the SAME family. */
    public static function diff(Model $from, Model $to): array
    {
        if ($from->family_key !== $to->family_key || get_class($from) !== get_class($to)) {
            throw ValidationException::withMessages(['to' => 'Only versions of the same preset can be compared.']);
        }
        $adapter = self::adapter($from instanceof OrganizationPreset ? 'organization' : $from->kind);
        $a = $adapter->items(self::content($from));
        $b = $adapter->items(self::content($to));
        $changed = array_values(array_filter(array_keys(array_intersect_key($a, $b)), fn ($k) => json_encode($a[$k]) !== json_encode($b[$k])));

        return ['from' => $from->version, 'to' => $to->version, 'added' => array_values(array_diff(array_keys($b), array_keys($a))),
            'removed' => array_values(array_diff(array_keys($a), array_keys($b))), 'changed' => $changed];
    }

    // ── Société : aperçu (lecture seule) et application explicite ─────────────

    public static function preview(Company $company, Model $preset): array
    {
        $kind = $preset instanceof OrganizationPreset ? 'organization' : $preset->kind;

        return ['kind' => $kind, 'family_key' => $preset->family_key, 'version' => $preset->version, 'status' => $preset->status,
            'missing_modules' => self::missingModules($company, $preset), 'last_applied_version' => self::lastApplied($company, $kind, $preset->family_key),
            'items' => self::adapter($kind)->preview($company, $preset)];
    }

    public static function apply(Company $company, Model $preset, ?array $selected, string $actorType, ?int $actorId): array
    {
        $kind = $preset instanceof OrganizationPreset ? 'organization' : $preset->kind;
        if ($preset->status !== 'published') {
            throw ValidationException::withMessages(['preset' => 'Only a published version can be applied.']);
        }
        if ($missing = self::missingModules($company, $preset)) {
            throw ValidationException::withMessages(['preset' => 'Required modules unavailable: '.implode(', ', $missing)]);
        }

        return DB::transaction(function () use ($company, $preset, $selected, $actorType, $actorId, $kind) {
            Company::query()->where('id', $company->id)->lockForUpdate()->first(); // applications concurrentes sérialisées
            $summary = self::adapter($kind)->apply($company, $preset, $selected, $actorId);
            PresetApplication::create(['company_id' => $company->id, 'kind' => $kind, 'family_key' => $preset->family_key, 'preset_key' => $preset->key,
                'preset_version' => $preset->version, 'selection' => $selected, 'summary' => $summary, 'applied_by_type' => $actorType, 'applied_by' => $actorId]);
            app(AuditLogger::class)->logCompany($company->id, 'preset.applied', 'preset', $preset->key, [
                'severity' => $kind === 'rbac' ? 'warning' : 'info',
                'metadata' => ['kind' => $kind, 'family_key' => $preset->family_key, 'version' => $preset->version, 'actor_type' => $actorType, 'actor_id' => $actorId],
            ]);

            return $summary;
        });
    }

    private static function missingModules(Company $company, Model $preset): array
    {
        return array_values(array_filter($preset->requires_modules ?? [], fn ($m) => ! ModuleGate::isActive($company, $m)));
    }

    private static function lastApplied(Company $company, string $kind, string $family): ?int
    {
        return PresetApplication::where('company_id', $company->id)->where('kind', $kind)->where('family_key', $family)->max('preset_version');
    }

    // ── JobDomain : recommandations (jamais appliquées seules) ────────────────

    /** @return list<array{kind: string, family_key: string, published_version: ?int, label: ?string}> */
    public static function recommendationsFor(string $jobdomainKey, ?string $marketKey = null): array
    {
        return JobdomainPresetLink::where('jobdomain_key', $jobdomainKey)
            ->where(fn ($q) => $q->whereNull('market_key')->orWhere('market_key', $marketKey))
            ->orderBy('sort_order')->get()
            ->map(function ($l) {
                $published = $l->kind === 'organization'
                    ? OrganizationPreset::where('family_key', $l->family_key)->where('status', 'published')->first()
                    : Preset::where('kind', $l->kind)->where('family_key', $l->family_key)->where('status', 'published')->first();

                return ['kind' => $l->kind, 'family_key' => $l->family_key, 'market_key' => $l->market_key, 'published_version' => $published?->version, 'label' => $published?->label];
            })->all();
    }

    public static function setRecommendations(string $jobdomainKey, array $links): void
    {
        foreach ($links as $l) {
            $exists = $l['kind'] === 'organization'
                ? OrganizationPreset::where('family_key', $l['family_key'])->exists()
                : Preset::where('kind', $l['kind'])->where('family_key', $l['family_key'])->exists();
            if (! in_array($l['kind'], self::KINDS, true) || ! $exists) {
                throw ValidationException::withMessages(['links' => "Unknown preset family {$l['kind']}:{$l['family_key']}."]);
            }
        }
        DB::transaction(function () use ($jobdomainKey, $links) {
            JobdomainPresetLink::where('jobdomain_key', $jobdomainKey)->delete(); // liens de recommandation uniquement (aucune donnée métier)
            foreach (array_values($links) as $i => $l) {
                JobdomainPresetLink::create(['jobdomain_key' => $jobdomainKey, 'market_key' => $l['market_key'] ?? null, 'kind' => $l['kind'], 'family_key' => $l['family_key'], 'sort_order' => $i]);
            }
        });
    }
}
