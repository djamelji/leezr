<?php

use App\Core\Jobdomains\Modernization\LegacyJobdomainConfig;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR-820 — starting libraries offering the one-line `address` get a new published version with the structured
 * personal address instead (no duplicate in new companies). Platform configuration only : existing companies
 * and their values are untouched ; previous versions stay in the history. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('presets')) {
            return;
        }
        $families = Preset::where('kind', 'catalog')->where('status', 'published')->get()->unique('family_key');
        foreach ($families as $published) {
            $content = (array) $published->content;
            if (! collect($content['fields'] ?? [])->contains(fn ($f) => ($f['code'] ?? null) === 'address' && ($f['subject'] ?? 'person') === 'person')) {
                continue;
            }
            if (Preset::where('kind', 'catalog')->where('family_key', $published->family_key)->where('status', 'draft')->exists()) {
                continue; // un brouillon en cours appartient à l'administrateur : jamais écrasé
            }
            $next = $published->nextVersion(null);
            PresetService::updateDraft($next, ['content' => ['fields' => LegacyJobdomainConfig::structuredAddress($content['fields']), 'documents' => $content['documents'] ?? []]], null);
            PresetService::publish($next->fresh(), null);
        }
    }

    public function down(): void {}
};
