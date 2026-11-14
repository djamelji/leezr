<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\ActionBuilder;
use App\Core\Navigation\NavBuilder;
use App\Core\Navigation\NavGroupRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-775 (Company Experience 2.0 / UX0) — navigation by INTENT.
 *
 * Invariants : home first ; one main entry per intent ; never an empty space ; presentation never
 * adds or removes a reachable entry ; personal entries follow the person ; contextual actions are
 * only those the user may perform ; nothing names a role or a jobdomain.
 */
class Ux0IntentNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER = ['home', 'team', 'time', 'documents', 'payroll', 'activity', 'settings', 'personal'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    /** Company with the listed kind of modules really entitled (jobdomain defaults + direct activation). */
    private function company(bool $allModules, string $jobdomain = 'logistique'): Company
    {
        $modules = $allModules
            ? ['workforce', 'workforce_leave', 'workforce_planning', 'workforce_payroll', 'workforce_documents', 'workforce_contract', 'workforce_signature', 'logistics_shipments']
            : [];
        Jobdomain::updateOrCreate(['key' => $jobdomain], ['label' => $jobdomain, 'default_modules' => $modules]);
        $company = Company::create(['name' => 'UX0 '.uniqid(), 'slug' => 'ux0-'.uniqid(), 'plan_key' => 'pro', 'jobdomain_key' => $jobdomain]);
        foreach ($modules as $key) {
            CompanyModuleActivationReason::create(['company_id' => $company->id, 'module_key' => $key, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
            CompanyModule::create(['company_id' => $company->id, 'module_key' => $key, 'is_enabled_for_company' => true]);
        }
        ModuleRegistry::clearCache();

        return $company;
    }

    private function nav(array $groups): array
    {
        return array_values(array_filter($groups, fn ($g) => $g['placement'] === 'nav'));
    }

    private function entries(array $groups): array
    {
        return collect($this->nav($groups))->flatMap(fn ($g) => array_column($g['items'], 'key'))->all();
    }

    private function menuKeys(array $groups): array
    {
        return collect($groups)->where('placement', 'menu')->flatMap(fn ($g) => array_column($g['items'], 'key'))->all();
    }

    public function test_owner_sees_one_entry_per_intent_home_first_in_the_validated_order(): void
    {
        $groups = NavBuilder::forCompany($this->company(true), null, 'management');
        $keys = array_column($this->nav($groups), 'key');

        $this->assertSame('home', $keys[0]);
        $this->assertSame($keys, array_values(array_intersect(self::ORDER, $keys)), 'validated order of intents');
        foreach ($this->nav($groups) as $group) {
            $this->assertNotEmpty($group['items'], "{$group['key']} : never an empty section");
            $this->assertSame('', $group['titleKey'], 'no section heading : an intent is an entry');
            if (! in_array($group['key'], ['personal'], true)) {
                $this->assertCount(1, $group['items'], "{$group['key']} : ONE main entry");
            }
        }
        $this->assertSame(['dashboard', 'people', 'space-time', 'documents', 'workforce-payroll', 'shipments', 'space-settings'], $this->entries($groups));
        $this->assertSame(['workforce-planning', 'workforce-time', 'workforce-leave'], array_column(collect($groups)->firstWhere('key', 'time')['items'][0]['children'], 'key'));
        $this->assertSame('nav.groups.settings', collect($groups)->firstWhere('key', 'settings')['items'][0]['labelKey']);
        // A manager's personal entries live in the avatar menu — all of them, none lost.
        $this->assertSame(['my-space', 'workforce-me', 'account-settings'], $this->menuKeys($groups));
    }

    public function test_presentation_never_adds_or_loses_a_reachable_entry(): void
    {
        $company = $this->company(true);
        $declared = collect(ModuleRegistry::forScope('company'))->flatMap(fn ($m) => array_column($m->capabilities->navItems, 'key'))->all();

        foreach ([null, ['members.view', 'documents.view', 'settings.view'], ['workforce.leave_request', 'workforce.leave_approve'], ['shipments.view_own'], []] as $perms) {
            foreach (['management', 'operational'] as $level) {
                $leaves = NavBuilder::leafKeys(NavBuilder::forCompany($company, $perms, $level));
                $this->assertSame($leaves, array_unique($leaves), 'no duplicate entry');
                $this->assertEmpty(array_diff($leaves, $declared), 'only manifest-declared entries');
                foreach (NavBuilder::forCompany($company, $perms, $level) as $group) {
                    $this->assertNotEmpty($group['items']);
                }
            }
        }
        // Owner : every declared entry that is not an exclusive variant is reachable.
        $owner = NavBuilder::leafKeys(NavBuilder::forCompany($company, null, 'management'));
        $this->assertEqualsCanonicalizing(array_values(array_diff($declared, ['workforce-people', 'my-deliveries'])), $owner);
    }

    public function test_spaces_exist_only_with_a_capability_and_single_items_are_not_nested(): void
    {
        // Company WITHOUT Workforce / business modules : no Planning & temps, no Paie, no Activité.
        $bare = NavBuilder::forCompany($this->company(false), null, 'management');
        $this->assertSame(['home', 'team', 'documents', 'settings'], array_column($this->nav($bare), 'key'));

        // One settings capability only : ONE direct entry labelled « Réglages », no sub-menu.
        $one = NavBuilder::forCompany($this->company(true), ['members.view', 'settings.view'], 'management');
        $settings = collect($one)->firstWhere('key', 'settings')['items'];
        $this->assertSame(['company-profile'], array_column($settings, 'key'));
        $this->assertSame('nav.groups.settings', $settings[0]['labelKey']);
        $this->assertSame([], $settings[0]['children']);

        // One time capability only : the item itself, under its own label.
        $leave = NavBuilder::forCompany($this->company(true), ['workforce.leave_request', 'workforce.leave_approve'], 'management');
        $time = collect($leave)->firstWhere('key', 'time')['items'];
        $this->assertSame(['workforce-leave'], array_column($time, 'key'));
        $this->assertArrayNotHasKey('labelKey', $time[0]);
    }

    public function test_personal_entries_follow_the_person(): void
    {
        $company = $this->company(true);

        // Employee without management entry : « Mon espace » stays a visible entry.
        $employee = NavBuilder::forCompany($company, ['shipments.view_own'], 'operational');
        $this->assertSame(['dashboard', 'my-deliveries', 'my-space', 'workforce-me'], $this->entries($employee));
        $this->assertSame(['account-settings'], $this->menuKeys($employee));

        // Manager (any management entry) : personal entries move to the avatar menu, none is lost.
        $manager = NavBuilder::forCompany($company, ['members.view'], 'management');
        $this->assertNotContains('my-space', $this->entries($manager));
        $this->assertSame(['my-space', 'workforce-me', 'account-settings'], $this->menuKeys($manager));
    }

    public function test_business_modules_join_activity_without_naming_a_jobdomain(): void
    {
        // The business module declares the jobdomains it fits (manifest data) ; the navigation only
        // follows what is active : « Activité » for one company, absent for the other, rest identical.
        $logistics = NavBuilder::forCompany($this->company(true, 'logistique'), null, 'management');
        $other = NavBuilder::forCompany($this->company(true, 'ux0-other'), null, 'management');
        $this->assertContains('activity', array_column($logistics, 'key'));
        $this->assertNotContains('activity', array_column($other, 'key'), 'never an empty section');
        $this->assertSame(array_values(array_diff(NavBuilder::leafKeys($logistics), ['shipments'])), NavBuilder::leafKeys($other));
        $source = file_get_contents(app_path('Core/Navigation/NavGroupRegistry.php')).file_get_contents(app_path('Core/Navigation/ActionBuilder.php'));
        foreach (['logistique', 'restauration', 'dispatcher', 'driver', 'owner'] as $hardcoded) {
            $this->assertStringNotContainsStringIgnoringCase("'{$hardcoded}'", $source);
        }
    }

    public function test_contextual_actions_are_only_those_the_user_may_perform(): void
    {
        $company = $this->company(true);
        $keys = fn (?array $perms) => array_column(ActionBuilder::forCompany($company, $perms), 'key');

        $this->assertSame(['team.add-person', 'team.generate-contract', 'time.plan', 'documents.request', 'documents.deposit'], $keys(null));
        $this->assertSame([], $keys(['members.view', 'documents.view', 'settings.view', 'shipments.view']), 'a Dispatcher-like profile gets no action it cannot perform');
        $this->assertSame(['team.add-employee'], $keys(['workforce.manage']), 'same intent, the variant this user can perform');
        $this->assertSame(['team.add-person'], $keys(['workforce.manage', 'members.invite']), 'never two « add a person »');
        $this->assertSame(['documents.request', 'documents.deposit'], $keys(['documents.manage']));
        $this->assertSame([], array_column(ActionBuilder::forCompany($this->company(false), ['workforce.planning_manage', 'workforce.contracts']), 'key'), 'inactive module contributes nothing');

        foreach (ActionBuilder::forCompany($company, null) as $action) {
            $this->assertNotNull(NavGroupRegistry::space($action['space']));
            $this->assertNotContains($action['key'], ['billing', 'profile'], 'rare settings are not everyday actions');
        }
        // Every action label exists in both locales.
        foreach (['fr', 'en'] as $locale) {
            $i18n = json_decode(file_get_contents(resource_path("js/plugins/i18n/locales/{$locale}.json")), true)['actions'];
            foreach (collect(ModuleRegistry::forScope('company'))->flatMap(fn ($m) => $m->capabilities->actions) as $a) {
                [$space, $verb] = explode('.', $a['key']);
                $this->assertArrayHasKey($verb, $i18n[$space] ?? [], "actions.{$a['key']} ({$locale})");
            }
        }
    }

    public function test_entries_match_what_the_person_can_really_use(): void
    {
        $company = $this->company(true);

        // An employee holding only his own timesheet capability is NOT a manager : « Mon espace » stays visible.
        $employee = NavBuilder::forCompany($company, ['workforce.timesheet_view', 'workforce.timesheet_submit', 'workforce.leave_request'], 'operational', ['employment' => true]);
        $this->assertSame(['dashboard', 'workforce-time', 'my-space', 'workforce-me'], $this->entries($employee));

        // « Mon activité » needs an employee record : never offered to a manager who has none…
        $noRecord = NavBuilder::forCompany($company, ['members.view', 'workforce.view'], 'management', ['employment' => false]);
        $this->assertSame(['my-space', 'account-settings'], $this->menuKeys($noRecord));
        // …and kept for an administrator who is ALSO an employee.
        $adminEmployee = NavBuilder::forCompany($company, null, 'management', ['employment' => true]);
        $this->assertSame(['my-space', 'workforce-me', 'account-settings'], $this->menuKeys($adminEmployee));

        // HR settings is a structure page (refused to operational profiles by its own guard) : the
        // entry follows the page — no entry leading to a refusal.
        $readOnly = NavBuilder::leafKeys(NavBuilder::forCompany($company, ['workforce.view'], 'operational', ['employment' => false]));
        $this->assertNotContains('workforce-settings', $readOnly);
        $this->assertContains('workforce-organization', $readOnly);
        $this->assertContains('workforce-settings', NavBuilder::leafKeys(NavBuilder::forCompany($company, ['workforce.view'], 'management')));
    }

    public function test_nav_endpoint_serves_spaces_placement_and_allowed_actions(): void
    {
        $company = $this->company(true);
        $owner = \App\Core\Models\User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $res = $this->actingAs($owner)->getJson('/api/nav', ['X-Company-Id' => $company->id])->assertOk();
        $this->assertSame('home', $res->json('groups.0.key'));
        $this->assertSame('nav', $res->json('groups.0.placement'));
        $this->assertContains('team.add-person', array_column($res->json('actions'), 'key'));
        // No employee record for this owner : no « Mon activité » anywhere.
        $this->assertNotContains('workforce-me', NavBuilder::leafKeys($res->json('groups')));
        $this->assertContains('my-space', NavBuilder::leafKeys($res->json('groups')));
    }
}
