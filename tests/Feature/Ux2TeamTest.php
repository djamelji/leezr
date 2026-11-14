<?php

namespace Tests\Feature;

use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-777 (Company Experience 2.0 / UX2) — Équipe.
 *
 * The single « add a person » journey and the five-group person file are INTERFACE : they reuse the
 * existing endpoints and authorities (covered by their own tests). What changes server-side is the
 * field-role preset — checked here — and two additive compatibilities checked next to their domain.
 */
class Ux2TeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function permissionsOf(string $jobdomain, string $role): array
    {
        return array_values(ModuleRegistry::resolveBundles(JobdomainRegistry::get($jobdomain)['default_roles'][$role]['bundles']));
    }

    public function test_a_field_role_views_the_team_but_no_longer_invites_by_default(): void
    {
        $driver = $this->permissionsOf('logistique', 'driver');

        $this->assertContains('members.view', $driver, 'a driver still sees the team');
        $this->assertNotContains('members.invite', $driver, 'a field role does not invite');
        $this->assertNotContains('members.manage', $driver);
    }

    public function test_roles_that_need_to_invite_keep_it_and_the_view_only_bundle_is_declared(): void
    {
        // Every other preset role of the jobdomain that held the « Team Access » bundle keeps it.
        foreach (JobdomainRegistry::get('logistique')['default_roles'] as $key => $role) {
            if ($key === 'driver') {
                $this->assertContains('members.team_view', $role['bundles']);
                $this->assertNotContains('members.team_access', $role['bundles']);

                continue;
            }
            if (in_array('members.team_access', $role['bundles'], true)) {
                $this->assertContains('members.invite', $this->permissionsOf('logistique', $key), "{$key} keeps inviting");
            }
        }

        $bundles = collect(ModuleRegistry::forScope('company'))->flatMap(fn ($m) => $m->bundles)->keyBy('key');
        $this->assertSame(['members.view'], $bundles['members.team_view']['permissions']);
        $this->assertSame(['members.view', 'members.invite'], $bundles['members.team_access']['permissions'], 'existing bundle untouched');
        foreach (['fr', 'en'] as $locale) {
            $labels = json_decode(file_get_contents(resource_path("js/plugins/i18n/locales/{$locale}.json")), true)['permissionCatalog']['bundles'];
            $this->assertArrayHasKey('members.team_view', $labels, $locale);
        }
    }

    public function test_existing_roles_are_not_rewritten_by_the_preset_change(): void
    {
        // The preset is cloned at company creation only : nothing in this lot rewrites stored roles.
        $migrations = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f));
        $this->assertSame([], $migrations->filter(fn ($f) => str_contains($f, 'ux2') || str_contains($f, 'team_view') || str_contains($f, 'driver'))->values()->all());
    }
}
