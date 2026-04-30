<?php

namespace App\Core\FeatureFlag;

class FeatureFlagService
{
    public function list(): array
    {
        return FeatureFlag::orderBy('key')->get()->toArray();
    }

    public function get(string $key): ?FeatureFlag
    {
        return FeatureFlag::where('key', $key)->first();
    }

    public function createOrUpdate(array $data): FeatureFlag
    {
        return FeatureFlag::updateOrCreate(
            ['key' => $data['key']],
            [
                'description' => $data['description'] ?? null,
                'enabled_globally' => $data['enabled_globally'] ?? false,
                'company_overrides' => $data['company_overrides'] ?? null,
            ]
        );
    }

    public function delete(string $key): bool
    {
        return FeatureFlag::where('key', $key)->delete() > 0;
    }

    public function toggleGlobal(string $key, bool $enabled): ?FeatureFlag
    {
        $flag = FeatureFlag::where('key', $key)->first();
        if (!$flag) {
            return null;
        }
        $flag->update(['enabled_globally' => $enabled]);

        return $flag;
    }

    public function setCompanyOverride(string $key, int $companyId, ?bool $enabled): ?FeatureFlag
    {
        $flag = FeatureFlag::where('key', $key)->first();
        if (!$flag) {
            return null;
        }

        $overrides = $flag->company_overrides ?? [];
        if ($enabled === null) {
            unset($overrides[(string) $companyId]);
        } else {
            $overrides[(string) $companyId] = $enabled;
        }
        $flag->update(['company_overrides' => $overrides]);

        return $flag;
    }

    /**
     * Get all flags resolved for a specific company.
     */
    public function resolvedForCompany(int $companyId): array
    {
        return FeatureFlag::all()->mapWithKeys(function (FeatureFlag $flag) use ($companyId) {
            $overrides = $flag->company_overrides ?? [];
            $override = $overrides[(string) $companyId] ?? null;
            $resolved = $override !== null ? (bool) $override : $flag->enabled_globally;

            return [$flag->key => $resolved];
        })->toArray();
    }
}
