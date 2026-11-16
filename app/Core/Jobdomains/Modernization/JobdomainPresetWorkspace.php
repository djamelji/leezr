<?php

namespace App\Core\Jobdomains\Modernization;

use App\Core\Audit\AuditLogger;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Presets\JobdomainPresetLink;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;
use App\Modules\Workforce\Organization\OrganizationPreset;
use Illuminate\Support\Facades\DB;

/**
 * ADR-799 — the JobDomain page edits ITS presets directly, one tab per family, without manual
 * association nor version picking. Internally unchanged : versions, drafts, publication, history,
 * concurrency and audit (PresetService). Rules :
 *  - open    : the published version recommended for the domain + the open draft (if any) + the other
 *              domains sharing the same preset ;
 *  - edit    : an open draft is reused ; otherwise a new version of the recommended preset, or a first
 *              draft built from the domain's legacy configuration ;
 *  - publish : publishes the draft AND makes it the domain's recommendation (follows the latest
 *              published version) ; companies are never touched (application stays explicit).
 */
final class JobdomainPresetWorkspace
{
    public const KINDS = ['rbac', 'organization', 'requirements', 'planning'];

    public static function state(Jobdomain $jd): array
    {
        $recs = collect(PresetService::recommendationsFor($jd->key))->whereNull('market_key')->keyBy('kind');
        $out = [];
        foreach (self::KINDS as $kind) {
            $rec = $recs->get($kind);
            $family = $rec['family_key'] ?? self::ownFamily($jd, $kind);
            $versions = PresetService::familyVersions($kind, $family);
            $published = $rec && $rec['effective_id'] ? PresetService::find($kind, $rec['effective_id']) : null;
            $draft = $versions->firstWhere('status', 'draft');
            $out[$kind] = [
                'family_key' => $family,
                'published' => $published ? self::present($kind, $published) : null,
                'draft' => $draft ? self::present($kind, $draft) : null,
                'shared_with' => JobdomainPresetLink::where('kind', $kind)->where('family_key', $family)->where('jobdomain_key', '!=', $jd->key)->pluck('jobdomain_key')->unique()->values()->all(),
                'legacy_available' => $kind !== 'organization' && self::legacyItems($jd, $kind) > 0,
                'history' => $versions->map(fn ($v) => ['version' => $v->version, 'status' => $v->status, 'published_at' => $v->published_at?->toIso8601String()])->values()->all(),
            ];
        }

        return $out;
    }

    /** The editable draft of the domain for this family (created if needed) ; null = start from scratch. */
    public static function draft(Jobdomain $jd, string $kind, ?int $by): ?array
    {
        abort_if($kind === 'organization', 422, 'Organisation presets keep their dedicated editor.');

        return DB::transaction(function () use ($jd, $kind, $by) {
            $state = self::state($jd)[$kind];
            if ($state['draft']) {
                return $state['draft'];
            }
            if ($state['published']) {
                $next = Preset::findOrFail($state['published']['id'])->nextVersion($by);
                app(AuditLogger::class)->logPlatform('preset.version_opened', 'preset', $next->key, ['actorId' => $by, 'metadata' => ['jobdomain' => $jd->key]]);

                return self::present($kind, $next);
            }
            if ($state['legacy_available']) {
                return self::present($kind, LegacyPresetDraftFactory::draft($jd, $kind, $by)['preset']);
            }

            return null;
        });
    }

    /** Publish the draft and make it the domain's recommendation (other families and markets untouched). */
    public static function publish(Jobdomain $jd, string $kind, int $presetId, ?int $by): array
    {
        $preset = PresetService::find($kind, $presetId);
        DB::transaction(function () use ($jd, $kind, $preset, $by) {
            PresetService::publish($preset, $by);
            $links = JobdomainPresetLink::where('jobdomain_key', $jd->key)->orderBy('sort_order')->get()
                ->reject(fn ($l) => $l->kind === $kind && $l->market_key === null)
                ->map(fn ($l) => ['kind' => $l->kind, 'family_key' => $l->family_key, 'market_key' => $l->market_key, 'pinned_version' => $l->pinned_version])->values()->all();
            $links[] = ['kind' => $kind, 'family_key' => $preset->family_key, 'market_key' => null, 'pinned_version' => null];
            PresetService::setRecommendations($jd->key, $links);
        });

        return self::state($jd)[$kind];
    }

    public static function ownFamily(Jobdomain $jd, string $kind): string
    {
        return substr($jd->key.'_'.$kind, 0, 80);
    }

    private static function legacyItems(Jobdomain $jd, string $kind): int
    {
        return count(match ($kind) {
            'rbac' => LegacyJobdomainConfig::rbac($jd)['items'],
            'requirements' => LegacyJobdomainConfig::requirements($jd)['items'],
            'planning' => LegacyJobdomainConfig::planning($jd)['items'],
            default => [],
        });
    }

    private static function present(string $kind, $p): array
    {
        return ['id' => $p->id, 'kind' => $kind, 'key' => $p->key, 'family_key' => $p->family_key, 'label' => $p->label ?? $p->family_key, 'version' => $p->version,
            'status' => $p->status, 'revision' => (int) ($p->revision ?? 0), 'description' => $p->description,
            'content' => $p instanceof OrganizationPreset ? ['structure' => $p->structure] : $p->content];
    }
}
