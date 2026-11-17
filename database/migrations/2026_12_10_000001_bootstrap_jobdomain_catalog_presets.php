<?php

use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\Modernization\LegacyJobdomainConfig;
use App\Core\Presets\JobdomainPresetLink;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR-820 — every active JobDomain gets its starting LIBRARY (catalog preset v1, published) built from its
 * historical default fields / documents, WITHOUT the historical `required` flag. Platform configuration only :
 * no company is touched (existing companies keep their activations). Idempotent : a domain already linked to
 * a catalog family is skipped. down() is a no-op (published presets are history, never deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('presets')) {
            return;
        }
        foreach (Jobdomain::where('is_active', true)->get() as $jd) {
            if (JobdomainPresetLink::where('jobdomain_key', $jd->key)->where('kind', 'catalog')->exists()) {
                continue;
            }
            $legacy = LegacyJobdomainConfig::catalog($jd);
            if ($legacy['items'] === []) {
                continue;
            }
            $family = substr($jd->key.'_catalog', 0, 80);
            $preset = Preset::where('kind', 'catalog')->where('family_key', $family)->where('status', 'published')->orderByDesc('version')->first()
                ?? tap(PresetService::createDraft('catalog', ['family_key' => $family, 'label' => $jd->label.' — bibliothèque de départ',
                    'description' => 'Champs et types de documents proposés aux nouvelles sociétés (aucune obligation).',
                    'jobdomain_key' => $jd->key, 'content' => $legacy['content'], 'requires_modules' => []], null), fn ($p) => PresetService::publish($p, null));
            $links = JobdomainPresetLink::where('jobdomain_key', $jd->key)->orderBy('sort_order')->get()
                ->map(fn ($l) => ['kind' => $l->kind, 'family_key' => $l->family_key, 'market_key' => $l->market_key, 'pinned_version' => $l->pinned_version])->values()->all();
            array_unshift($links, ['kind' => 'catalog', 'family_key' => $preset->family_key, 'market_key' => null, 'pinned_version' => null]);
            PresetService::setRecommendations($jd->key, $links);
        }
    }

    public function down(): void {}
};
