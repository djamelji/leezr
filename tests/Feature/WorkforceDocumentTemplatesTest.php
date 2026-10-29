<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentTemplate;
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
 * W-G1 (ADR-641) — endpoint des modèles documentaires DISPONIBLES (alimente le sélecteur métier,
 * fin de la saisie d'un code template à l'aveugle). Vérifie : résolution Company > plateforme,
 * filtrage par subject_type (jamais de modèle incompatible), isolation tenant, RBAC, actifs seuls.
 */
class WorkforceDocumentTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private int $platformEmployeeTplId;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_documents']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_documents', 'name' => 'Workforce Documents', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->companyA = $this->company('A', 'co-a');
        $this->companyB = $this->company('B', 'co-b');

        // Plateforme : modèle collaborateur (attestation), modèle contrat, modèle inactif.
        $platformEmployee = $this->template(null, null, 'attestation', 'Attestation (standard)', 'employee', true);
        $this->platformEmployeeTplId = $platformEmployee->id;
        $this->template(null, null, 'contrat_cdi', 'Contrat CDI (standard)', 'contract', true);
        $this->template(null, null, 'vieux_modele', 'Vieux modèle', 'employee', false); // inactif

        // Company A : surcharge de l'attestation (doit gagner sur la plateforme, par `code`).
        $this->template($this->companyA->id, $platformEmployee->id, 'attestation', 'Attestation (A)', 'employee', true);

        // Company B : modèle propre, ne doit JAMAIS fuiter vers A.
        $this->template($this->companyB->id, null, 'secret_b', 'Modèle confidentiel B', 'employee', true);
    }

    private function company(string $name, string $slug): Company
    {
        $c = Company::create(['name' => $name, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_documents'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        return $c;
    }

    private function template(?int $companyId, ?int $platformId, string $code, string $name, string $subjectType, bool $active): DocumentTemplate
    {
        return DocumentTemplate::create([
            'company_id' => $companyId,
            'platform_template_id' => $platformId,
            'code' => $code,
            'name' => $name,
            'subject_type' => $subjectType,
            'body_html' => '<p>{{ employee.first_name }}</p>',
            'variables_schema' => [],
            'version' => 1,
            'is_system' => false,
            'is_active' => $active,
        ]);
    }

    private function userWith(array $permKeys, Company $company): User
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permKeys)->pluck('id')->toArray());
        Membership::create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $user;
    }

    private function getTemplates(User $u, Company $c, string $query = '')
    {
        return $this->actingAs($u)->getJson('/api/workforce/documents/templates'.$query, ['X-Company-Id' => $c->id]);
    }

    public function test_lists_platform_and_company_templates_filtered_by_subject_type(): void
    {
        $user = $this->userWith(['workforce_documents.view'], $this->companyA);
        $res = $this->getTemplates($user, $this->companyA, '?subject_type=employee')->assertOk();

        $codes = collect($res->json('data'))->pluck('code')->all();
        $this->assertContains('attestation', $codes);
        $this->assertNotContains('contrat_cdi', $codes, 'type contract exclu quand on filtre employee');
        $this->assertNotContains('vieux_modele', $codes, 'modèle inactif exclu');
        $this->assertNotContains('secret_b', $codes, 'modèle d’une autre Company exclu (tenant)');
    }

    public function test_company_override_wins_over_platform(): void
    {
        $user = $this->userWith(['workforce_documents.view'], $this->companyA);
        $res = $this->getTemplates($user, $this->companyA, '?subject_type=employee')->assertOk();

        $attestation = collect($res->json('data'))->firstWhere('code', 'attestation');
        $this->assertSame('Attestation (A)', $attestation['name']);
        $this->assertSame('company', $attestation['source']);
    }

    public function test_platform_template_shown_when_no_company_override(): void
    {
        $user = $this->userWith(['workforce_documents.view'], $this->companyB);
        $res = $this->getTemplates($user, $this->companyB, '?subject_type=employee')->assertOk();

        $attestation = collect($res->json('data'))->firstWhere('code', 'attestation');
        $this->assertSame('Attestation (standard)', $attestation['name']);
        $this->assertSame('platform', $attestation['source']);
    }

    public function test_incompatible_subject_type_is_never_offered(): void
    {
        $user = $this->userWith(['workforce_documents.view'], $this->companyA);
        $res = $this->getTemplates($user, $this->companyA, '?subject_type=contract')->assertOk();

        $codes = collect($res->json('data'))->pluck('code')->all();
        $this->assertSame(['contrat_cdi'], $codes);
    }

    public function test_invalid_subject_type_returns_empty(): void
    {
        $user = $this->userWith(['workforce_documents.view'], $this->companyA);
        $res = $this->getTemplates($user, $this->companyA, '?subject_type=bogus')->assertOk();
        $this->assertSame([], $res->json('data'));
    }

    public function test_tenant_isolation_company_b_templates_never_leak(): void
    {
        $user = $this->userWith(['workforce_documents.view'], $this->companyA);
        $res = $this->getTemplates($user, $this->companyA)->assertOk(); // sans filtre
        $codes = collect($res->json('data'))->pluck('code')->all();
        $this->assertNotContains('secret_b', $codes);
    }

    public function test_requires_view_permission(): void
    {
        $user = $this->userWith(['workforce.view'], $this->companyA); // pas workforce_documents.view
        $this->getTemplates($user, $this->companyA, '?subject_type=employee')->assertForbidden();
    }
}
