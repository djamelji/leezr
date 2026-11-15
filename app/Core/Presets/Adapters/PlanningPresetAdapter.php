<?php

namespace App\Core\Presets\Adapters;

use App\Core\Models\Company;
use App\Modules\Workforce\Presets\WorkforcePresetImportService;
use Illuminate\Validation\ValidationException;

/**
 * ADR-793 (L3) — PLANNING family : versioned content in `presets` (same shape as the historical
 * `jobdomains.workforce_presets`), previewed and applied by the EXISTING import engine (idempotent,
 * provenance per item, selection « type:code »), fed with the explicit version.
 */
final class PlanningPresetAdapter implements PresetFamilyAdapter
{
    private const STATUS = ['new' => 'new', 'existing' => 'identical', 'customized' => 'customized', 'conflict' => 'conflict'];

    private const TYPES = ['coverage_categories' => 'coverage_category', 'activity_presets' => 'activity_preset', 'schedule_templates' => 'schedule_template', 'week_templates' => 'week_template'];

    public function kind(): string
    {
        return 'planning';
    }

    public function validate(array $content, ?string $marketKey = null): void
    {
        $errors = [];
        foreach (self::TYPES as $section => $type) {
            $codes = [];
            foreach ($content[$section] ?? [] as $i => $item) {
                if (! preg_match('/^[a-z0-9_]{1,60}$/', (string) ($item['code'] ?? '')) || blank($item['name'] ?? null)) {
                    $errors["{$section}.{$i}"] = 'Each item needs a code and a name.';
                }
                if (isset($codes[$item['code'] ?? ''])) {
                    $errors["{$section}.{$i}.code"] = 'Duplicate code.';
                }
                $codes[$item['code'] ?? ''] = true;
            }
        }
        $activities = array_column($content['activity_presets'] ?? [], 'code');
        foreach ($content['schedule_templates'] ?? [] as $i => $tpl) {
            foreach ($tpl['composition']['activities'] ?? [] as $a) {
                if (! in_array($a['preset_code'] ?? null, $activities, true)) {
                    $errors["schedule_templates.{$i}.composition"] = 'References an unknown activity.';
                }
            }
        }
        if (! array_filter(array_map(fn ($s) => $content[$s] ?? [], array_keys(self::TYPES)))) {
            $errors['content'] = 'A planning preset needs at least one item.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function items(array $content): array
    {
        $out = [];
        foreach (self::TYPES as $section => $type) {
            foreach ($content[$section] ?? [] as $item) {
                $out[$type.':'.$item['code']] = $item;
            }
        }

        return $out;
    }

    public function onPublish(object $preset): void {}

    public function preview(Company $company, object $preset): array
    {
        $diff = app(WorkforcePresetImportService::class)->withPresets($preset->content)->preview($company);
        $out = [];
        foreach (self::TYPES as $section => $type) {
            foreach ($diff[$section] ?? [] as $i) {
                $out[] = ['key' => $type.':'.$i['code'], 'label' => $i['name'], 'status' => self::STATUS[$i['status']] ?? $i['status'], 'detail' => null];
            }
        }

        return $out;
    }

    public function apply(Company $company, object $preset, ?array $selected, ?int $actorId): array
    {
        return app(WorkforcePresetImportService::class)->withPresets($preset->content)->apply($company, $selected, $actorId);
    }
}
