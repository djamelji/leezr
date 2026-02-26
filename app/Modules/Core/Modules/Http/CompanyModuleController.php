<?php

namespace App\Modules\Core\Modules\Http;

use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use App\Core\Security\SecurityDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyModuleController
{
    /**
     * List all modules with activation status and capabilities for the current company.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'modules' => ModuleCatalogReadModel::forCompany($company),
        ]);
    }

    /**
     * Enable a module for the current company.
     */
    public function enable(Request $request, string $key): JsonResponse
    {
        $company = $request->attributes->get('company');
        $result = ModuleActivationEngine::enable($company, $key);

        if ($result['success']) {
            Log::info('module.enabled', [
                'module_key' => $key,
                'company_id' => $company->id,
                'user_id' => $request->user()->id,
                'activated' => $result['data']['activated'] ?? [],
            ]);

            // ADR-125: publish after mutation
            app(RealtimePublisher::class)->publish(
                EventEnvelope::invalidation('modules.changed', $company->id, ['action' => 'enabled', 'module_key' => $key])
            );

            // ADR-130: audit log
            app(AuditLogger::class)->logCompany($company->id, AuditAction::MODULE_ENABLED, 'module', $key);

            // ADR-129: detect abnormal module toggling
            SecurityDetector::check('abnormal.module_toggling', "module:{$key}:company:{$company->id}", $company->id, $request->user()->id);
        }

        return response()->json($result['data'], $result['status']);
    }

    /**
     * Disable a module for the current company.
     */
    public function disable(Request $request, string $key): JsonResponse
    {
        $company = $request->attributes->get('company');
        $result = ModuleActivationEngine::disable($company, $key);

        if ($result['success']) {
            Log::info('module.disabled', [
                'module_key' => $key,
                'company_id' => $company->id,
                'user_id' => $request->user()->id,
                'deactivated' => $result['data']['deactivated'] ?? [],
            ]);

            // ADR-125: publish after mutation
            app(RealtimePublisher::class)->publish(
                EventEnvelope::invalidation('modules.changed', $company->id, ['action' => 'disabled', 'module_key' => $key])
            );

            // ADR-130: audit log
            app(AuditLogger::class)->logCompany($company->id, AuditAction::MODULE_DISABLED, 'module', $key);

            // ADR-129: detect abnormal module toggling
            SecurityDetector::check('abnormal.module_toggling', "module:{$key}:company:{$company->id}", $company->id, $request->user()->id);
        }

        return response()->json($result['data'], $result['status']);
    }

    /**
     * Get module settings (config_json).
     */
    public function getSettings(Request $request, string $key): JsonResponse
    {
        $company = $request->attributes->get('company');

        $manifest = ModuleRegistry::definitions()[$key] ?? null;
        if (!$manifest || $manifest->scope !== 'company') {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        if (!ModuleGate::isActive($company, $key)) {
            return response()->json(['message' => 'Module is not active.'], 422);
        }

        $companyModule = CompanyModule::where('company_id', $company->id)
            ->where('module_key', $key)
            ->first();

        return response()->json([
            'module_key' => $key,
            'settings' => $companyModule?->config_json ?? (object) [],
        ]);
    }

    /**
     * Update module settings (config_json).
     */
    public function updateSettings(Request $request, string $key): JsonResponse
    {
        $company = $request->attributes->get('company');

        $manifest = ModuleRegistry::definitions()[$key] ?? null;
        if (!$manifest || $manifest->scope !== 'company') {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        if (!ModuleGate::isActive($company, $key)) {
            return response()->json(['message' => 'Module is not active.'], 422);
        }

        $validated = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $companyModule = CompanyModule::where('company_id', $company->id)
            ->where('module_key', $key)
            ->firstOrFail();

        $companyModule->update(['config_json' => $validated['settings']]);

        Log::info('module.settings_updated', [
            'module_key' => $key,
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
        ]);

        // ADR-125: publish after mutation
        app(RealtimePublisher::class)->publish(
            EventEnvelope::invalidation('modules.changed', $company->id, ['action' => 'settings_updated', 'module_key' => $key])
        );

        // ADR-130: audit log
        app(AuditLogger::class)->logCompany($company->id, AuditAction::MODULE_SETTINGS_UPDATED, 'module', $key);

        return response()->json([
            'message' => 'Module settings updated.',
            'module_key' => $key,
            'settings' => $companyModule->config_json,
        ]);
    }
}
