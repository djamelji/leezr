<?php

namespace App\Console\Commands;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ModuleExportCommand extends Command
{
    protected $signature = 'module:export {module_key}';

    protected $description = 'Export a module as a structured JSON package';

    public function handle(): int
    {
        $key = $this->argument('module_key');
        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if (!$manifest) {
            $this->error("Module '{$key}' not found in registry.");

            return self::FAILURE;
        }

        $platformModule = PlatformModule::where('key', $key)->first();
        $modulePath = ModuleRegistry::modulePath($key);

        $export = [
            'manifest' => [
                'key' => $manifest->key,
                'name' => $manifest->name,
                'description' => $manifest->description,
                'type' => $manifest->type,
                'scope' => $manifest->scope,
                'surface' => $manifest->surface,
                'sort_order' => $manifest->sortOrder,
                'visibility' => $manifest->visibility,
                'min_plan' => $manifest->minPlan,
                'compatible_jobdomains' => $manifest->compatibleJobdomains,
                'requires' => $manifest->requires,
                'icon_type' => $manifest->iconType,
                'icon_ref' => $manifest->iconRef,
            ],
            'permissions' => $manifest->permissions,
            'bundles' => $manifest->bundles,
            'platform_module' => $platformModule ? $platformModule->only([
                'is_enabled_globally',
                'pricing_mode',
                'is_listed',
                'is_sellable',
                'pricing_model',
                'pricing_metric',
                'pricing_params',
                'settings_schema',
                'notes',
                'display_name_override',
                'description_override',
                'min_plan_override',
                'sort_order_override',
            ]) : null,
            'metadata' => [
                'version' => '1.0.0',
                'exported_at' => now()->toIso8601String(),
            ],
        ];

        // Read module.json if it exists
        if ($modulePath && File::exists($modulePath . '/module.json')) {
            $moduleJson = json_decode(File::get($modulePath . '/module.json'), true);

            if ($moduleJson && isset($moduleJson['version'])) {
                $export['metadata']['version'] = $moduleJson['version'];
            }
        }

        // Handle image icon export (base64, SVG only)
        if ($manifest->iconType === 'image' && $modulePath) {
            $iconPath = $modulePath . '/resources/' . $manifest->iconRef;

            if (File::exists($iconPath)) {
                $extension = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));

                if ($extension === 'svg') {
                    $export['icon_data'] = [
                        'filename' => $manifest->iconRef,
                        'content' => base64_encode(File::get($iconPath)),
                    ];
                } else {
                    $this->warn("Icon '{$manifest->iconRef}' is not an SVG file. Skipping icon export.");
                }
            } else {
                $this->warn("Icon file not found: {$iconPath}");
            }
        }

        // Write output
        $outputDir = storage_path('modules');
        File::ensureDirectoryExists($outputDir);

        $outputPath = $outputDir . "/{$key}.json";
        File::put($outputPath, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info("Module exported to: {$outputPath}");

        return self::SUCCESS;
    }
}
