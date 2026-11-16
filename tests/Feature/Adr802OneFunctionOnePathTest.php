<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-802 — une fonction, un parcours. Invariants statiques (anciennes routes = redirections gardées comme
 * avant, vues regroupées sous la même navigation, un seul formulaire de temps de travail, édition des presets
 * à un seul endroit) et refus HTTP des API réutilisées par les vues regroupées (aucune garde serveur changée).
 */
class Adr802OneFunctionOnePathTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private function page(string $rel): string
    {
        return file_get_contents(base_path('resources/js/pages/'.$rel));
    }

    /** @return array<string, array{0:string,1:string,2:string}> old page => [meta that must be kept, redirect target] */
    public static function redirects(): array
    {
        return [
            'liste RH' => ['company/workforce/employees/index.vue', "definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })", "path: '/company/people'"],
            'liste membres' => ['company/members/index.vue', "definePage({ meta: { module: 'core.members', surface: 'structure', permission: 'members.view' } })", "path: '/company/people'"],
            'signatures' => ['company/workforce/signature/index.vue', "permission: 'workforce_signature.view'", "'/company/documents/signatures'"],
            'modèles de contrat' => ['company/workforce/contract/templates.vue', "definePage({ meta: { module: 'workforce_contract', permission: 'workforce_contract.view' } })", "'/company/documents/templates'"],
            'documents RH' => ['company/workforce/documents/index.vue', "definePage({ meta: { module: 'workforce_documents', permission: 'workforce_documents.view' } })", '/company/documents/${view}'],
        ];
    }

    /** @dataProvider redirects */
    public function test_old_page_is_a_guarded_redirect(string $file, string $meta, string $target): void
    {
        $src = $this->page($file);
        $this->assertStringContainsString($meta, $src, 'same guard as before : an old URL never bypasses permissions');
        $this->assertStringContainsString('router.replace', $src);
        $this->assertStringContainsString($target, $src);
        $this->assertLessThan(40, substr_count($src, "\n"), 'a redirect, not a second page');
    }

    public function test_hr_list_filters_survive_the_redirect(): void
    {
        $src = $this->page('company/workforce/employees/index.vue');
        foreach (['q', 'status', 'dept', 'role', 'manager', 'contract'] as $k) {
            $this->assertStringContainsString("'{$k}'", $src);
        }
        $this->assertStringContainsString("tab: 'hr'", $src);
    }

    public function test_former_pages_are_views_without_route_metadata(): void
    {
        foreach (['company/people/_PeopleHrTools.vue', 'company/people/_ProfileFieldsSettings.vue', 'company/people/_MemberHealth.vue',
            'company/workforce/signature/_SignaturesView.vue', 'company/workforce/contract/_ContractTemplatesView.vue', 'company/workforce/documents/_HrDocumentsView.vue'] as $f) {
            $this->assertStringNotContainsString('definePage(', $this->page($f), $f);
        }
    }

    public function test_team_page_hosts_the_specialised_views_with_their_original_guards(): void
    {
        $src = $this->page('company/people/index.vue');
        $this->assertStringContainsString("moduleStore.isActive('workforce') && can('workforce.view')", $src); // ex-page RH
        $this->assertStringContainsString('<PeopleHrTools v-if="tab === \'hr\' && canHr"', $src);
        $this->assertStringContainsString("can('members.view') && can('settings.manage')", $src); // garde de l'API des champs
        $this->assertStringContainsString('<MemberHealth v-if="tab === \'people\' && can(\'members.view\')"', $src);
        $this->assertStringNotContainsString("name: 'company-workforce-employees'", $src, 'no link to a second people page');
        $this->assertStringNotContainsString('to="/company/members"', $src);

        // Un seul formulaire de création : la vue RH déclenche « Ajouter » d'Équipe.
        $hr = $this->page('company/people/_PeopleHrTools.vue');
        $this->assertStringNotContainsString('createEmployee(', $hr);
        $this->assertStringContainsString("emit('create')", $hr);
        $this->assertStringContainsString("tab: 'hr'", $hr);
    }

    public function test_documents_space_embeds_the_views(): void
    {
        $src = $this->page('company/documents/[tab].vue');
        $this->assertStringContainsString("workforce/documents/_HrDocumentsView.vue'", $src);
        $this->assertStringContainsString("workforce/signature/_SignaturesView.vue'", $src);
    }

    public function test_one_working_time_form(): void
    {
        $settings = $this->page('company/workforce/settings/[tab].vue');
        $this->assertStringContainsString('<WorkingTimeSetup @loaded=', $settings);
        $this->assertStringContainsString('<WorkforceRulesSection domain="work-time" :exclude="setupKeys" />', $settings);
        $this->assertStringContainsString('<WorkforceRulesSection domain="breaks" :exclude="setupKeys" />', $settings);
        $this->assertStringContainsString('!props.exclude.includes(x.key)', $this->page('company/workforce/settings/_WorkforceRulesSection.vue'));
        $this->assertStringContainsString("emit('loaded'", $this->page('company/workforce/_WorkingTimeSetup.vue'));
    }

    public function test_preset_owned_by_a_domain_is_edited_only_from_that_domain(): void
    {
        $cat = $this->page('platform/catalog/_CatalogPresets.vue');
        $this->assertSame(3, substr_count($cat, '&& !ownedByDomain"'), 'edit, publish, new version hidden for domain-owned families');
        $this->assertStringContainsString('data-test="preset-open-jobdomain"', $cat);
        $tab = $this->page('platform/jobdomains/_JobdomainPresetTab.vue');
        $this->assertStringContainsString("emit('link', shareChoice)", $tab);
        foreach (['rbac', 'requirements', 'planning'] as $k) {
            $this->assertStringContainsString("@link=\"fk => linkFamily('{$k}', fk)\"", $this->page('platform/jobdomains/[id].vue'));
        }
        $this->assertStringNotContainsString("'key' => 'organization-presets'", file_get_contents(app_path('Modules/Platform/Jobdomains/JobdomainsModule.php')));
    }

    // ── HTTP : the APIs behind the grouped views keep their server guards ─────────────────

    private function companyUser(array $permissions, bool $administrative = false): array
    {
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_documents', 'workforce_signature', 'workforce_contract']]);
        $company = Company::create(['name' => 'A802', 'slug' => 'a802-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($company);
        foreach (['workforce', 'workforce_documents'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->setUpCompanyRbac($company);
        $role = CompanyRole::create(['company_id' => $company->id, 'key' => 'r'.uniqid(), 'name' => 'R', 'is_administrative' => $administrative]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());
        $u = User::factory()->create();
        $company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return [$u, $company];
    }

    public function test_driver_like_user_gets_none_of_the_grouped_views_data(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        [$u, $c] = $this->companyUser(['shipments.view_own']);
        $h = ['X-Company-Id' => $c->id];
        foreach (['/api/workforce/employees', '/api/workforce/employees/export', '/api/workforce/employees/import/template', '/api/company/members/1'] as $uri) {
            $this->actingAs($u)->withHeaders($h)->getJson($uri)->assertForbidden();
        }
        $this->actingAs($u)->withHeaders($h)->postJson('/api/company/field-definitions', ['code' => 'x', 'label' => 'X', 'scope' => 'company_user', 'type' => 'string'])->assertForbidden();
        $this->actingAs($u)->withHeaders($h)->postJson('/api/workforce/employees/bulk', [])->assertForbidden();
    }

    public function test_members_manager_without_settings_manage_cannot_write_profile_fields(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        [$u, $c] = $this->companyUser(['members.view', 'members.manage'], true); // members.manage est une permission d'administration
        $this->actingAs($u)->withHeaders(['X-Company-Id' => $c->id])
            ->postJson('/api/company/field-definitions', ['code' => 'x', 'label' => 'X', 'scope' => 'company_user', 'type' => 'string'])
            ->assertForbidden(); // d'où la garde UI `settings.manage` (alignée sur le serveur)
    }

    public function test_hr_viewer_reads_the_hr_view_but_cannot_bulk_or_export(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        [$u, $c] = $this->companyUser(['workforce.view']);
        $h = ['X-Company-Id' => $c->id];
        $this->actingAs($u)->withHeaders($h)->getJson('/api/workforce/employees')->assertOk();
        $this->actingAs($u)->withHeaders($h)->getJson('/api/workforce/employees/export')->assertForbidden();
        $this->actingAs($u)->withHeaders($h)->postJson('/api/workforce/employees/bulk', [])->assertForbidden();
    }
}
