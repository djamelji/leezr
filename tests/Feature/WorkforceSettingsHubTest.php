<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-639 (W-B) — hub Paramètres RH : sections conditionnées par modules actifs, contexte, RBAC, tenant.
 */
class WorkforceSettingsHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_leave', 'workforce_planning', 'workforce_documents', 'workforce_contract']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_leave', 'workforce_planning', 'workforce_documents', 'workforce_contract'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
    }

    private function company(array $modules): Company
    {
        $c = Company::create(['name' => 'S '.uniqid(), 'slug' => 's-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach ($modules as $m) {
            CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        return $c;
    }

    private function user(Company $c, array $perms): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    private function keys(array $json): array
    {
        return array_column($json['sections'], 'key');
    }

    public function test_sections_depend_on_active_modules(): void
    {
        $full = $this->company(['workforce', 'workforce_leave', 'workforce_planning', 'workforce_documents']);
        $u = $this->user($full, ['workforce.view']);
        $res = $this->actingAs($u)->getJson('/api/workforce/settings', ['X-Company-Id' => $full->id])->assertOk()->json();
        foreach (['general', 'organization', 'worktime', 'leave', 'planning', 'documents', 'rules'] as $k) {
            $this->assertContains($k, $this->keys($res), "section $k présente");
        }
        $this->assertSame('logistique', $res['context']['jobdomain_key']);
    }

    public function test_inactive_modules_hide_their_sections(): void
    {
        $min = $this->company(['workforce']); // ni leave, ni planning, ni documents
        $u = $this->user($min, ['workforce.view']);
        $res = $this->actingAs($u)->getJson('/api/workforce/settings', ['X-Company-Id' => $min->id])->assertOk()->json();
        $keys = $this->keys($res);
        $this->assertContains('worktime', $keys);
        $this->assertNotContains('leave', $keys);
        $this->assertNotContains('planning', $keys);
        $this->assertNotContains('documents', $keys);
    }

    public function test_contract_section_visible_only_when_module_active(): void
    {
        // Sans le module Contract : pas de section + pas d'identité employeur vide polluante.
        $without = $this->company(['workforce']);
        $u1 = $this->user($without, ['workforce.view']);
        $res1 = $this->actingAs($u1)->getJson('/api/workforce/settings', ['X-Company-Id' => $without->id])->assertOk()->json();
        $this->assertNotContains('contract', $this->keys($res1));

        // Avec le module Contract : section présente + identité documentaire employeur exposée (données Company).
        $with = $this->company(['workforce', 'workforce_contract']);
        $with->update(['siret' => '12345678900011', 'address_city' => 'Paris', 'address_postal_code' => '75001']);
        $u2 = $this->user($with, ['workforce.view']);
        $res2 = $this->actingAs($u2)->getJson('/api/workforce/settings', ['X-Company-Id' => $with->id])->assertOk()->json();
        $this->assertContains('contract', $this->keys($res2));
        $this->assertArrayHasKey('employer_identity', $res2['context']);
        $this->assertSame('12345678900011', $res2['context']['employer_identity']['siret']);
        $this->assertSame('75001 Paris', $res2['context']['employer_identity']['city']);
    }

    public function test_requires_view_permission(): void
    {
        $c = $this->company(['workforce']);
        $noPerm = $this->user($c, []); // administratif mais sans workforce.view
        $this->actingAs($noPerm)->getJson('/api/workforce/settings', ['X-Company-Id' => $c->id])->assertForbidden();
    }
}
