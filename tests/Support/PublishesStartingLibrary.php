<?php

namespace Tests\Support;

use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;

/**
 * ADR-820 — the versioned creation needs a published starting library (catalog preset) besides the RBAC
 * preset. Tests that exercise that path link this minimal library (phone + id card, no obligation).
 */
trait PublishesStartingLibrary
{
    protected function libraryLink(string $family = 'test_library'): array
    {
        if (! Preset::where('kind', 'catalog')->where('family_key', $family)->where('status', 'published')->exists()) {
            $p = PresetService::createDraft('catalog', ['family_key' => $family, 'label' => 'Test library', 'jobdomain_key' => 'logistique',
                'content' => ['fields' => [['code' => 'phone', 'subject' => 'person', 'included' => true]],
                    'documents' => [['code' => 'id_card', 'subject' => 'person', 'included' => true]]]], null);
            PresetService::publish($p, null);
        }

        return ['kind' => 'catalog', 'family_key' => $family];
    }
}
