<?php

namespace App\Console\Commands;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ModuleImportCommand extends Command
{
    protected $signature = 'module:import {path}';

    protected $description = 'Import a module configuration from a JSON package (DB sync only)';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (!File::exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $data = json_decode(File::get($path), true);

        if (!$data || !isset($data['manifest']['key'])) {
            $this->error('Invalid module package format. Missing manifest.key.');

            return self::FAILURE;
        }

        $key = $data['manifest']['key'];

        // Verify the PHP module class exists
        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if (!$manifest) {
            $this->error("Module '{$key}' not found in registry. PHP module class must exist before import.");

            return self::FAILURE;
        }

        // Ensure PlatformModule row exists
        ModuleRegistry::sync();

        $platformModule = PlatformModule::where('key', $key)->first();

        if (!$platformModule) {
            $this->error("PlatformModule row for '{$key}' not found after sync.");

            return self::FAILURE;
        }

        // Apply platform_module config (DB fields only — never overwrites manifest)
        if (!empty($data['platform_module'])) {
            $platformModule->update($data['platform_module']);
            $this->info('Platform module configuration applied.');
        }

        // Handle icon import (SVG only)
        if (($data['manifest']['icon_type'] ?? null) === 'image' && !empty($data['icon_data'])) {
            $modulePath = ModuleRegistry::modulePath($key);

            if ($modulePath) {
                $filename = $data['icon_data']['filename'] ?? 'icon.svg';
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                if ($extension !== 'svg') {
                    $this->warn("Icon '{$filename}' is not an SVG file. Skipping icon import.");
                } else {
                    $iconDir = $modulePath . '/resources';
                    File::ensureDirectoryExists($iconDir);
                    File::put($iconDir . '/' . $filename, base64_decode($data['icon_data']['content']));
                    $this->info("Icon written to {$iconDir}/{$filename}");
                }
            }
        }

        $this->info("Module '{$key}' imported successfully.");

        return self::SUCCESS;
    }
}
