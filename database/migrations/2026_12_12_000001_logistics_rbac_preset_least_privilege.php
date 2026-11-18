<?php

use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR-823 — the published Logistics RBAC preset (used to CREATE companies) gave the delivery driver
 * `members.team_view` (colleagues' profiles and personal fields), `settings.company_info` and `documents.access`
 * (company vault and colleagues' documents — already removed from the registry by ADR-788). A new version is
 * published with the driver limited to what the work needs (theme, own deliveries ; the personal space needs no
 * permission). Platform configuration only : no company role is modified (existing roles are reported by
 * `rbac:least-privilege-report`). Other roles unchanged ; an existing draft is left untouched. Idempotent.
 */
return new class extends Migration
{
    private const REMOVED = ['members.team_view', 'settings.company_info', 'documents.access'];

    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('presets')) {
            return;
        }
        $published = Preset::where('kind', 'rbac')->where('family_key', 'logistique_rbac')->where('status', 'published')->orderByDesc('version')->first();
        if (! $published) {
            return;
        }
        $content = PresetService::content($published);
        $changed = false;
        foreach ($content['roles'] ?? [] as $i => $role) {
            if (($role['key'] ?? null) !== 'driver') {
                continue;
            }
            $kept = array_values(array_diff($role['bundles'] ?? [], self::REMOVED));
            if ($kept !== array_values($role['bundles'] ?? [])) {
                $content['roles'][$i]['bundles'] = $kept;
                $changed = true;
            }
        }
        if (! $changed) {
            return;
        }
        $draft = PresetService::createDraft('rbac', [
            'family_key' => $published->family_key, 'label' => $published->label, 'description' => $published->description,
            'jobdomain_key' => $published->jobdomain_key, 'market_key' => $published->market_key,
            'content' => $content, 'requires_modules' => $published->requires_modules,
        ], null);
        PresetService::publish($draft, null);
    }

    public function down(): void {}
};
