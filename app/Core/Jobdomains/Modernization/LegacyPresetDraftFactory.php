<?php

namespace App\Core\Jobdomains\Modernization;

use App\Core\Audit\AuditLogger;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;
use Illuminate\Validation\ValidationException;

/**
 * ADR-797 (L7) — builds a DRAFT versioned preset from the historical configuration of a JobDomain (RBAC,
 * requirements, planning). Catalogue only : nothing is published, recommended or applied ; no company is
 * touched. Per-role prescriptions are NOT carried (a role is not a position) and are reported.
 */
final class LegacyPresetDraftFactory
{
    public static function draft(Jobdomain $jd, string $kind, ?int $by): array
    {
        $legacy = match ($kind) {
            'catalog' => LegacyJobdomainConfig::catalog($jd),
            'rbac' => LegacyJobdomainConfig::rbac($jd),
            'requirements' => LegacyJobdomainConfig::requirements($jd),
            'planning' => LegacyJobdomainConfig::planning($jd),
            default => throw ValidationException::withMessages(['kind' => 'Only catalog, rbac, requirements and planning can be drafted from the legacy configuration.']),
        };
        if ($legacy['items'] === []) {
            throw ValidationException::withMessages(['kind' => 'No historical configuration to draft from.']);
        }
        $family = substr($jd->key.'_'.$kind, 0, 80);
        // Un brouillon ouvert de la même famille est réutilisé (jamais deux brouillons concurrents).
        $open = Preset::where('kind', $kind)->where('family_key', $family)->where('status', 'draft')->first();
        $attrs = ['family_key' => $family, 'label' => $jd->label.' — '.$kind, 'description' => 'Brouillon issu de la configuration historique du JobDomain « '.$jd->key.' » (ADR-797).',
            'jobdomain_key' => $jd->key, 'content' => $legacy['content'], 'requires_modules' => []];
        $preset = $open ? PresetService::updateDraft($open, ['content' => $legacy['content']], $by) : PresetService::createDraft($kind, $attrs, $by);
        app(AuditLogger::class)->logPlatform('preset.drafted_from_legacy', 'preset', $preset->key, ['actorId' => $by, 'metadata' => ['jobdomain' => $jd->key, 'kind' => $kind, 'items' => count($legacy['items'])]]);

        return ['preset' => $preset, 'skipped' => $legacy['skipped'] ?? [], 'not_carried' => $kind === 'rbac' ? LegacyJobdomainConfig::rolePrescriptions($jd) : []];
    }
}
