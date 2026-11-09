<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\CompanyModule;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use Illuminate\Support\Facades\DB;

/** ADR-746 (P2) — « Catalogue & adoption » (platform, server). Sources : CompanyModule, Jobdomain. */
class PlatformCatalogOverviewWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'platform.catalog_overview'; }
    public function module(): string { return 'platform.modules'; }
    public function layout(): array { return ['default_w' => 4, 'default_h' => 3, 'min_w' => 3, 'max_w' => 12, 'min_h' => 2, 'max_h' => 4]; }
    public function category(): string { return 'catalog'; }
    public function component(): string { return 'PlatformCatalogOverview'; }
    public function tags(): array { return ['catalog', 'modules', 'jobdomains']; }
    public function labelKey(): string { return 'platform.widgets.catalogOverview'; }
    public function descriptionKey(): string { return 'platform.widgets.catalogOverviewDesc'; }
    public function audience(): string { return 'platform'; }
    public function scope(): string { return 'both'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['manage_modules']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return null; }

    public function resolve(array $context): array
    {
        try {
            // Adoption : nb de companies distinctes par module (top 5).
            $top = CompanyModule::select('module_key', DB::raw('COUNT(DISTINCT company_id) as c'))
                ->groupBy('module_key')->orderByDesc('c')->limit(5)->get()
                ->map(fn ($r) => ['key' => $r->module_key, 'companies' => (int) $r->c])->all();

            $jobdomains = Jobdomain::withCount('companies')->get()
                ->map(fn ($j) => ['key' => $j->key, 'companies' => (int) $j->companies_count])->all();

            return [
                'modules_tracked' => CompanyModule::distinct('module_key')->count('module_key'),
                'top_modules' => $top,
                'jobdomains' => $jobdomains,
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
