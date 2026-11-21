<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ADR-842 (Leezr 4.2 M5) — Platform ergonomics invariants (structure of the pages ; the behaviour is covered by
 * the browser recipe tests/e2e/audit842-m5-recipe.spec.js).
 */
class Adr842PlatformErgonomicsTest extends TestCase
{
    private function page(string $path): string
    {
        return file_get_contents(resource_path("js/pages/platform/$path"));
    }

    public function test_every_billing_page_belongs_to_exactly_one_group_and_keeps_its_address(): void
    {
        $src = $this->page('billing/[tab].vue');
        preg_match_all("/tab: '([a-z-]+)', group: '([a-z]+)'/", $src, $tabs, PREG_SET_ORDER);
        $pages = array_column($tabs, 2, 1);
        $this->assertCount(15, $pages, 'the 15 historical pages are all still there');

        preg_match_all("/\\{ key: '([a-z]+)', icon: '[a-z-]+', order: \\[([^\\]]+)\\] \\}/", $src, $groups, PREG_SET_ORDER);
        $this->assertSame(['overview', 'subscriptions', 'invoicing', 'offers', 'control'], array_column($groups, 1));
        $inGroups = [];
        foreach ($groups as [, $key, $order]) {
            foreach (array_map(fn ($x) => trim($x, " '"), explode(',', $order)) as $tab) {
                $this->assertSame($key, $pages[$tab] ?? null, "« $tab » is declared in group $key");
                $inGroups[] = $tab;
            }
        }
        $this->assertEqualsCanonicalizing(array_keys($pages), $inGroups, 'no orphan, no duplicate');
        foreach (array_keys($pages) as $tab) {
            $this->assertStringContainsString("<VWindowItem value=\"$tab\">", $src, "/platform/billing/$tab still renders its page");
        }
        // permissions kept per page ; a group is shown only when one of its pages is allowed
        $this->assertStringContainsString("tab: 'coupons', group: 'offers', permission: 'manage_billing'", $src);
        $this->assertStringContainsString("tab: 'tariff-review', group: 'subscriptions', permission: 'manage_billing'", $src);
        $this->assertStringContainsString('.filter(g => g.pages.length)', $src);
    }

    public function test_company_record_has_one_place_per_information(): void
    {
        $src = $this->page('companies/[id]/[[tab]].vue');
        $this->assertStringNotContainsString('data-test="pc-summary"', $src, 'identity lives in the left panel only');
        $config = substr($src, strpos($src, '<VWindowItem value="configuration">'), 4000);
        $this->assertStringNotContainsString('requestPlanChange', $config, 'no commercial decision in the identity form');
        $billing = substr($src, strpos($src, '<VWindowItem value="billing">'), 2500);
        $this->assertStringContainsString('data-testid="company-plan-change"', $billing);
        $this->assertStringContainsString('v-if="canManage"', $billing);
        $this->assertStringContainsString('@update:model-value="requestPlanChange"', $billing);
    }

    public function test_lists_have_named_icon_buttons_and_no_duplicate_menus(): void
    {
        foreach (['companies/_CompaniesTab.vue', 'access/_UsersTab.vue'] as $file) {
            $src = $this->page($file);
            $this->assertStringNotContainsString('tabler-dots-vertical', $src, "$file : the « ⋮ » menu repeated the row actions");
            preg_match_all('/<IconBtn\b([^>]*)>/s', $src, $buttons);
            foreach ($buttons[1] as $attrs) {
                $this->assertStringContainsString(':aria-label=', $attrs, "$file : every icon button has an accessible name");
            }
        }
        $this->assertStringContainsString("v-if=\"canManageCompanies && item.status === 'active'\"", $this->page('companies/_CompaniesTab.vue'));
        $this->assertStringContainsString('new Intl.RelativeTimeFormat(locale.value', $this->page('companies/_CompaniesTab.vue'));
    }

    public function test_template_tools_and_raw_keys_are_not_shown(): void
    {
        foreach (['PlatformLayoutWithVerticalNav.vue', 'PlatformLayoutWithHorizontalNav.vue'] as $layout) {
            $src = file_get_contents(resource_path("js/layouts/components/$layout"));
            $this->assertStringContainsString('<TheCustomizer v-if="canCustomizeTheme" />', $src);
            $this->assertStringContainsString("hasPermission('manage_theme_settings')", $src);
        }
        $this->assertStringContainsString('{{ subjectLabel(log.subject) }}', $this->page('email/_EmailLogsTab.vue'));
        $this->assertStringContainsString('visibleSections', $this->page('modules/[key]/[[tab]].vue'));
    }
}
