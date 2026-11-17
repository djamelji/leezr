<?php

use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\Modernization\LegacyJobdomainConfig;
use App\Core\Presets\JobdomainPresetLink;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR-821 — the planning starting values of an active JobDomain become a published, versioned PROFILE (preset
 * « planning ») built from the historical column. Workforce imports from it (the company then owns and
 * customises the imported objects in Workforce). Platform configuration only : no company is touched, the
 * historical column stays as fallback. Idempotent ; down() is a no-op (published presets are history).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('presets')) {
            return;
        }
        foreach (Jobdomain::where('is_active', true)->get() as $jd) {
            if (JobdomainPresetLink::where('jobdomain_key', $jd->key)->where('kind', 'planning')->exists()) {
                continue;
            }
            $legacy = LegacyJobdomainConfig::planning($jd);
            if ($legacy['items'] === []) {
                continue;
            }
            $family = substr($jd->key.'_planning', 0, 80);
            $preset = Preset::where('kind', 'planning')->where('family_key', $family)->where('status', 'published')->orderByDesc('version')->first();
            if (! $preset) {
                try {
                    $preset = PresetService::createDraft('planning', ['family_key' => $family, 'label' => $jd->label.' — valeurs initiales du planning',
                        'jobdomain_key' => $jd->key, 'content' => $legacy['content'], 'requires_modules' => ['workforce_planning']], null);
                    PresetService::publish($preset, null);
                } catch (\Illuminate\Validation\ValidationException) {
                    continue; // contenu historique invalide : on laisse le repli, rien n'est forcé
                }
            }
            $links = JobdomainPresetLink::where('jobdomain_key', $jd->key)->orderBy('sort_order')->get()
                ->map(fn ($l) => ['kind' => $l->kind, 'family_key' => $l->family_key, 'market_key' => $l->market_key, 'pinned_version' => $l->pinned_version])->values()->all();
            $links[] = ['kind' => 'planning', 'family_key' => $preset->family_key, 'market_key' => null, 'pinned_version' => null];
            PresetService::setRecommendations($jd->key, $links);
        }
    }

    public function down(): void {}
};
