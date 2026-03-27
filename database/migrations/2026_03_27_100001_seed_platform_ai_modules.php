<?php

use App\Core\Ai\PlatformAiModule;
use App\Platform\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR-418b: Seed AI modules if table is empty.
 * DevSeeder only runs manually — production/staging never got these records.
 * Without at least one active PlatformAiModule, AiPolicyResolver returns disabled
 * and ProcessDocumentAiJob skips all analysis.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only seed if table exists and is empty (don't overwrite manual config)
        if (! \Schema::hasTable('platform_ai_modules')) {
            return;
        }

        if (PlatformAiModule::count() > 0) {
            return;
        }

        // Ollama — primary self-hosted provider
        PlatformAiModule::create([
            'provider_key' => 'ollama',
            'name' => 'Ollama',
            'description' => 'Self-hosted AI inference via Ollama',
            'is_installed' => true,
            'is_active' => true,
            'credentials' => [
                'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
                'model' => env('OLLAMA_MODEL', 'moondream'),
                'vision_model' => env('OLLAMA_VISION_MODEL', 'moondream'),
                'timeout' => 120,
            ],
            'config' => [
                'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
            ],
            'health_status' => 'unknown',
            'sort_order' => 1,
        ]);

        // Anthropic — optional, requires API key
        PlatformAiModule::create([
            'provider_key' => 'anthropic',
            'name' => 'Anthropic',
            'description' => 'Anthropic API (Claude). Requires API key.',
            'is_installed' => true,
            'is_active' => false,
            'credentials' => [
                'api_key' => env('ANTHROPIC_API_KEY', ''),
                'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
                'timeout' => 60,
            ],
            'config' => [],
            'health_status' => 'unknown',
            'sort_order' => 10,
        ]);

        // Routing — route all capabilities to Ollama
        try {
            $settings = PlatformSetting::instance();
            $ai = $settings->ai ?? [];

            if (empty($ai['routing'])) {
                $ai['routing'] = [
                    'vision' => 'ollama',
                    'completion' => 'ollama',
                    'text_extraction' => 'ollama',
                ];
                $settings->update(['ai' => $ai]);
            }
        } catch (\Throwable) {
            // PlatformSetting may not exist yet — non-fatal
        }
    }

    public function down(): void
    {
        // Don't delete — data migration only
    }
};
