<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ADR-194: Structural invariants for controllers.
 *
 * Ensures:
 *   - No controller exceeds 250 lines (known debt baselined)
 *   - No controller uses DB:: facade directly (known debt baselined)
 *   - No controller calls ->save() on models (known debt baselined)
 *
 * Known violations are baselined as technical debt.
 * These allowlists MUST NOT grow — only shrink as controllers are refactored.
 */
class ControllerSizeInvariantTest extends TestCase
{
    /**
     * Baselined violations — tracked as technical debt.
     * When a controller is refactored, REMOVE it from this list.
     */
    private const SIZE_ALLOWLIST = [
        'app/Modules/Core/Members/Http/MembershipController.php' => 270,
    ];

    private const DB_FACADE_ALLOWLIST = [
        'app/Modules/Core/Settings/Http/CompanyFieldDefinitionController.php',
        'app/Modules/Platform/Settings/Http/SessionSettingsController.php',
        'app/Modules/Platform/Settings/Http/GeneralSettingsController.php',
        'app/Modules/Platform/Settings/Http/TypographyController.php',
        'app/Modules/Platform/Settings/Http/ThemeController.php',
        'app/Modules/Platform/Settings/Http/WorldSettingsController.php',
        'app/Modules/Platform/Maintenance/Http/MaintenanceSettingsController.php',
        'app/Modules/Platform/Translations/Http/TranslationController.php',
        'app/Modules/Infrastructure/Webhooks/Http/PaymentWebhookController.php',
    ];

    private const SAVE_ALLOWLIST = [
        'app/Modules/Core/Theme/Http/ThemePreferenceController.php',
        'app/Modules/Core/Members/Http/MemberCredentialController.php',
        'app/Modules/Platform/Users/Http/UserController.php',
        'app/Modules/Infrastructure/Auth/Http/PasswordResetController.php',
        'app/Modules/Infrastructure/Theme/Http/PlatformThemePreferenceController.php',
        'app/Modules/Infrastructure/AdminAuth/Http/PlatformPasswordResetController.php',
    ];

    private function controllerFiles(): array
    {
        $files = [];

        $dirs = [
            base_path('app/Modules'),
        ];

        foreach ($dirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), 'Controller.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    public function test_no_controller_exceeds_250_lines(): void
    {
        $violations = [];

        foreach ($this->controllerFiles() as $file) {
            $lineCount = count(file($file));
            $relative = str_replace(base_path() . '/', '', $file);

            $allowedMax = self::SIZE_ALLOWLIST[$relative] ?? 250;

            if ($lineCount > $allowedMax) {
                $violations[] = "{$relative}: {$lineCount} lines (max {$allowedMax})";
            }
        }

        $this->assertEmpty(
            $violations,
            "Controllers exceeding size limit:\n" . implode("\n", $violations),
        );
    }

    public function test_no_controller_uses_db_facade(): void
    {
        $violations = [];

        foreach ($this->controllerFiles() as $file) {
            $relative = str_replace(base_path() . '/', '', $file);

            if (in_array($relative, self::DB_FACADE_ALLOWLIST, true)) {
                continue;
            }

            $content = file_get_contents($file);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNum => $line) {
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                if (preg_match('/\bDB::/', $line)) {
                    $violations[] = "{$relative}:" . ($lineNum + 1) . " — uses DB:: facade";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Controllers using DB:: facade (not in allowlist):\n" . implode("\n", $violations),
        );
    }

    public function test_no_controller_calls_save_on_models(): void
    {
        $violations = [];

        foreach ($this->controllerFiles() as $file) {
            $relative = str_replace(base_path() . '/', '', $file);

            if (in_array($relative, self::SAVE_ALLOWLIST, true)) {
                continue;
            }

            $content = file_get_contents($file);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNum => $line) {
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                if (preg_match('/->save\s*\(/', $line)) {
                    $violations[] = "{$relative}:" . ($lineNum + 1) . " — calls ->save()";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Controllers calling ->save() (not in allowlist):\n" . implode("\n", $violations),
        );
    }

    public function test_allowlists_do_not_grow(): void
    {
        $this->assertLessThanOrEqual(
            1,
            count(self::SIZE_ALLOWLIST),
            'SIZE_ALLOWLIST must not grow — refactor controllers to reduce size',
        );

        $this->assertLessThanOrEqual(
            9,
            count(self::DB_FACADE_ALLOWLIST),
            'DB_FACADE_ALLOWLIST must not grow — use services/UseCases instead of DB::',
        );

        $this->assertLessThanOrEqual(
            6,
            count(self::SAVE_ALLOWLIST),
            'SAVE_ALLOWLIST must not grow — use services/UseCases instead of ->save()',
        );
    }
}
