<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ADR-843 (Leezr 4.2 M6) — Company workspace invariants (behaviour : tests/e2e/audit842-m6-recipe.spec.js).
 */
class Adr843CompanyWorkspaceTest extends TestCase
{
    private function locale(string $lang): array
    {
        return json_decode(file_get_contents(resource_path("js/plugins/i18n/locales/$lang.json")), true);
    }

    public function test_one_primary_add_action_with_two_explained_choices(): void
    {
        $src = file_get_contents(resource_path('js/pages/company/people/_PeopleSpace.vue'));
        $this->assertSame(1, substr_count($src, 'data-test="people-add"'), 'a single primary button');
        $this->assertSame(1, substr_count($src, 'data-test="people-hire"'), 'hiring is a choice of that button, not a second button');
        $this->assertStringContainsString('data-test="people-add-quick"', $src);
        $this->assertStringContainsString("const canHire = computed(() => canModule('workforce') && can('workforce.manage'))", $src);
        foreach (['fr', 'en'] as $lang) {
            $add = $this->locale($lang)['people']['add'];
            foreach (['choiceHire', 'choiceHireHint', 'choiceQuick', 'choiceQuickHint'] as $k) {
                $this->assertNotEmpty($add[$k] ?? null, "$lang people.add.$k");
            }
        }
    }

    public function test_menu_entry_is_not_named_like_its_group_and_access_states_share_one_vocabulary(): void
    {
        foreach (['fr', 'en'] as $lang) {
            $l = $this->locale($lang);
            $this->assertNotSame($l['nav']['groups']['team'], $l['nav']['company']['people'], "$lang : no « Équipe › Équipe »");
            foreach (array_intersect_key($l['people']['account'], $l['employeeAccess']['states']) as $state => $label) {
                $this->assertSame($label, $l['employeeAccess']['states'][$state], "$lang : « $state » says the same thing in the list and on the record");
            }
        }
        $fr = $this->locale('fr');
        $this->assertNotContains('Compte activé', $fr['people']['account']);
        $this->assertNotContains('Compte connecté', $fr['people']['account']);
    }

    public function test_every_english_key_exists_in_french(): void
    {
        $missing = [];
        $walk = function ($en, $fr, $path) use (&$walk, &$missing) {
            foreach ($en as $k => $v) {
                $p = $path === '' ? $k : "$path.$k";
                if (is_array($v)) {
                    $walk($v, is_array($fr[$k] ?? null) ? $fr[$k] : [], $p);
                } elseif (! array_key_exists($k, $fr)) {
                    $missing[] = $p;
                }
            }
        };
        $walk($this->locale('en'), $this->locale('fr'), '');
        $this->assertSame([], $missing, 'French interface : no English fallback');
    }

    public function test_field_journeys_need_fewer_steps(): void
    {
        $deliveries = file_get_contents(resource_path('js/pages/company/my-deliveries/index.vue'));
        $this->assertStringContainsString("next.status === 'in_transit' && statusFilter.value === 'todo' ? 'in_transit' : null", $deliveries,
            'a started delivery stays on screen, its next step one tap away');
        $this->assertStringContainsString("state.value = !token && useCookie('userData').value ? 'reserved' : 'invalid'",
            file_get_contents(resource_path('js/pages/onboarding/index.vue')));
        $this->assertStringContainsString(':aria-label="t(\'onboardingWidget.dismissAria\')"',
            file_get_contents(resource_path('js/pages/company/dashboard/_OnboardingWidget.vue')));
        $this->assertStringContainsString('person-name-missing', file_get_contents(resource_path('js/pages/company/people/_PeopleSpace.vue')));
    }
}
