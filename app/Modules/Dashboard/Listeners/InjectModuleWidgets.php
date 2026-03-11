<?php

namespace App\Modules\Dashboard\Listeners;

use App\Core\Events\ModuleEnabled;
use App\Modules\Dashboard\CompanyDashboardLayout;
use App\Modules\Dashboard\CompanyDashboardWidgetSuggestion;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Modules\Dashboard\LayoutPacker;

/**
 * When a module is enabled for a company, auto-inject its widgets into the company dashboard.
 * Widgets that can't be placed are stored as pending suggestions.
 */
class InjectModuleWidgets
{
    public function handle(ModuleEnabled $event): void
    {
        $company = $event->company;
        $moduleKey = $event->moduleKey;

        // Find company-scoped widgets from this module
        $moduleWidgets = array_filter(
            DashboardWidgetRegistry::all(),
            fn ($w) => $w->module() === $moduleKey
                && in_array($w->scope(), ['company', 'both'], true)
        );

        if (empty($moduleWidgets)) {
            return;
        }

        // Get company default layout (user_id=NULL) — per-user layouts are separate (ADR-326)
        $layoutRow = CompanyDashboardLayout::where('company_id', $company->id)->whereNull('user_id')->first();
        $existing = $layoutRow?->layout_json ?? [];

        // Filter out widgets already in layout
        $existingKeys = array_column($existing, 'key');
        $newWidgets = [];

        foreach ($moduleWidgets as $widget) {
            if (!in_array($widget->key(), $existingKeys, true)) {
                $newWidgets[] = [
                    'key' => $widget->key(),
                    'scope' => 'company',
                    'config' => $widget->defaultConfig(),
                ];
            }
        }

        if (empty($newWidgets)) {
            return;
        }

        // Pack widgets into the layout
        $result = LayoutPacker::pack($existing, $newWidgets);

        // Save updated layout if anything was packed
        if (count($result['packed']) > count($existing)) {
            CompanyDashboardLayout::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => null],
                ['layout_json' => $result['packed']],
            );
        }

        // Create suggestions for widgets that couldn't be placed
        foreach ($result['pending'] as $widgetKey) {
            CompanyDashboardWidgetSuggestion::firstOrCreate(
                ['company_id' => $company->id, 'widget_key' => $widgetKey],
                ['module_key' => $moduleKey, 'status' => 'pending'],
            );
        }
    }
}
