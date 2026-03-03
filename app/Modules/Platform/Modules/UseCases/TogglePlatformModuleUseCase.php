<?php

namespace App\Modules\Platform\Modules\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Modules\AdminModuleService;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Validation\ValidationException;

class TogglePlatformModuleUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Toggle a module's global availability.
     * Supports both admin-scope (delegates to AdminModuleService) and company-scope modules.
     *
     * @return array{message: string, module: PlatformModule}
     */
    public function execute(string $key): array
    {
        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if ($manifest?->scope === 'admin') {
            return $this->toggleAdmin($key);
        }

        return $this->toggleCompany($key);
    }

    private function toggleAdmin(string $key): array
    {
        $module = PlatformModule::where('key', $key)->firstOrFail();

        $result = $module->is_enabled_globally
            ? AdminModuleService::disable($key)
            : AdminModuleService::enable($key);

        if (! $result['success']) {
            throw ValidationException::withMessages([
                'module' => [$result['data']['message'] ?? 'Toggle failed.'],
            ]);
        }

        $module->refresh();

        $this->audit->logPlatform(
            $module->is_enabled_globally ? AuditAction::MODULE_ENABLED : AuditAction::MODULE_DISABLED,
            'platform_module', $key,
        );

        return [
            'message' => $result['data']['message'],
            'module' => $module,
        ];
    }

    private function toggleCompany(string $key): array
    {
        $module = PlatformModule::where('key', $key)->firstOrFail();

        $wasBefore = $module->is_enabled_globally;
        $module->is_enabled_globally = ! $module->is_enabled_globally;
        $module->save();

        $this->audit->logPlatform(
            $module->is_enabled_globally ? AuditAction::MODULE_ENABLED : AuditAction::MODULE_DISABLED,
            'platform_module', $key,
            ['diffBefore' => ['is_enabled_globally' => $wasBefore], 'diffAfter' => ['is_enabled_globally' => $module->is_enabled_globally]],
        );

        return [
            'message' => $module->is_enabled_globally ? 'Module enabled globally.' : 'Module disabled globally.',
            'module' => $module,
        ];
    }
}
