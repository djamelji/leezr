<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\Contract\Services\ContractTemplateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-CONTRACT-1 (ADR-647) — module Contrats de travail : bibliothèque Platform/Company, duplication,
 * immutabilité des modèles publiés, versionnement, résolution Market, variables contrôlées (salaire
 * masqué), prévisualisation sans émission, génération + snapshot + archivage, RBAC, tenant.
 */
class WorkforceContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $employee;
    private int $platformTplId;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_documents', 'workforce_contract']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_documents', 'workforce_contract'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Ct Co', 'slug' => 'ct-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_documents', 'workforce_contract'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        $this->employee = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'Remy', 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'E1']);
        $contract = EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);
        CompensationPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'contract_id' => $contract->id, 'base_salary_cents' => 300000, 'currency' => 'EUR', 'pay_frequency' => 'monthly', 'overtime_rate_bps' => 2500, 'effective_from' => '2026-01-01', 'compensation_type' => 'monthly']);

        $this->platformTplId = $this->tpl(null, 'contract_cdi_fr', 'CDI FR', ['market_key' => 'FR', 'contract_type' => 'cdi'])->id;
        // Modèle Platform d'un autre market → incompatible, jamais proposé.
        $this->tpl(null, 'contract_cdi_gb', 'CDI GB', ['market_key' => 'GB', 'contract_type' => 'cdi']);
    }

    private function tpl(?int $companyId, string $code, string $name, array $meta, bool $active = true, int $version = 1): DocumentTemplate
    {
        return DocumentTemplate::create([
            'company_id' => $companyId, 'code' => $code, 'name' => $name, 'subject_type' => 'contract',
            'body_html' => '<p>{{ employee.full_name }} — {{ contract.contract_type }}</p>', 'variables_schema' => [],
            'version' => $version, 'is_system' => false, 'is_active' => $active,
            'metadata' => array_merge(['legal_status' => 'example_unvalidated', 'status' => $active ? 'published' : 'draft'], $meta),
        ]);
    }

    private function user(array $perms): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    private function api(User $u, string $method, string $path, array $body = [])
    {
        return $this->actingAs($u)->json($method, '/api/workforce/contract'.$path, $body, ['X-Company-Id' => $this->company->id]);
    }

    public function test_templates_lists_applicable_platform_excludes_incompatible_market(): void
    {
        $u = $this->user(['workforce_contract.view']);
        $codes = collect($this->api($u, 'GET', '/templates')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertContains('contract_cdi_fr', $codes);
        $this->assertNotContains('contract_cdi_gb', $codes, 'modèle d’un autre market exclu');
    }

    public function test_requires_view_permission(): void
    {
        $u = $this->user(['workforce.view']);
        $this->api($u, 'GET', '/templates')->assertForbidden();
    }

    public function test_variables_palette_masks_salary_without_compensation_read(): void
    {
        $u = $this->user(['workforce_contract.view']);
        $keys = collect($this->api($u, 'GET', '/templates/variables')->assertOk()->json('data'))->pluck('key')->all();
        $this->assertNotEmpty($keys);
        $this->assertEmpty(array_filter($keys, fn ($k) => str_contains($k, 'salary') || str_contains($k, 'compensation')), 'salaire masqué sans compensation_read');

        $hr = $this->user(['workforce_contract.view', 'workforce.compensation_read']);
        $keys2 = collect($this->api($hr, 'GET', '/templates/variables')->assertOk()->json('data'))->pluck('key')->all();
        $this->assertNotEmpty(array_filter($keys2, fn ($k) => str_contains($k, 'salary') || str_contains($k, 'compensation')), 'salaire visible avec permission');
    }

    public function test_duplicate_creates_company_copy_platform_unchanged(): void
    {
        $u = $this->user(['workforce_contract.view', 'workforce_contract.manage_templates']);
        $res = $this->api($u, 'POST', "/templates/{$this->platformTplId}/duplicate")->assertCreated();
        $this->assertSame('company', $res->json('source'));
        $this->assertSame('draft', $res->json('status'));
        // Platform intact.
        $this->assertSame('CDI FR', DocumentTemplate::find($this->platformTplId)->name);
    }

    public function test_published_template_is_immutable(): void
    {
        $u = $this->user(['workforce_contract.view', 'workforce_contract.manage_templates']);
        $copy = $this->api($u, 'POST', "/templates/{$this->platformTplId}/duplicate")->json();
        $this->api($u, 'POST', "/templates/{$copy['id']}/publish")->assertOk();
        // Édition d'un publié → 422.
        $this->api($u, 'PUT', "/templates/{$copy['id']}", ['name' => 'Hack'])->assertStatus(422);
    }

    public function test_versioning_and_publish_archives_prior(): void
    {
        $u = $this->user(['workforce_contract.view', 'workforce_contract.manage_templates']);
        $copy = $this->api($u, 'POST', "/templates/{$this->platformTplId}/duplicate")->json();
        $this->api($u, 'POST', "/templates/{$copy['id']}/publish")->assertOk();
        $v2 = $this->api($u, 'POST', "/templates/{$copy['id']}/version")->assertCreated()->json();
        $this->assertGreaterThan($copy['version'], $v2['version']);
        $this->api($u, 'POST', "/templates/{$v2['id']}/publish")->assertOk();
        // L'ancienne version publiée est archivée.
        $this->assertFalse((bool) DocumentTemplate::find($copy['id'])->is_active);
    }

    public function test_preview_does_not_emit_a_document(): void
    {
        $u = $this->user(['workforce_contract.view', 'workforce_contract.generate', 'workforce.view', 'workforce.manage']);
        $before = GeneratedDocument::withoutGlobalScopes()->count();
        $res = $this->api($u, 'POST', '/preview', ['template_id' => $this->platformTplId, 'employee_id' => $this->employee->id])->assertOk();
        $this->assertTrue($res->json('preview'));
        $this->assertStringContainsString('Remy', $res->json('html'));
        $this->assertSame($before, GeneratedDocument::withoutGlobalScopes()->count(), 'aperçu : aucun document émis');
    }

    public function test_preview_masks_salary_without_permission(): void
    {
        // Modèle contenant une variable salaire.
        $tpl = $this->tpl(null, 'contract_salary', 'Salaire', ['market_key' => 'FR']);
        $tpl->update(['body_html' => '<p>{{ contract.base_salary }}</p>']);
        $u = $this->user(['workforce_contract.view', 'workforce_contract.generate', 'workforce.view', 'workforce.manage']); // pas compensation_read
        $res = $this->api($u, 'POST', '/preview', ['template_id' => $tpl->id, 'employee_id' => $this->employee->id])->assertOk();
        $this->assertStringNotContainsString('3000', $res->json('html'));
    }

    public function test_generate_emits_document_and_is_idempotent(): void
    {
        $u = $this->user(['workforce_contract.view', 'workforce_contract.generate', 'workforce.view', 'workforce.manage']);
        $res = $this->api($u, 'POST', '/generate', ['template_code' => 'contract_cdi_fr', 'employee_id' => $this->employee->id])->assertCreated();
        $docId = $res->json('id');
        $this->assertNotNull($docId);
        $this->assertDatabaseHas('generated_documents', ['id' => $docId, 'subject_type' => 'contract']);
        // Idempotent : re-générer renvoie le même document.
        $res2 = $this->api($u, 'POST', '/generate', ['template_code' => 'contract_cdi_fr', 'employee_id' => $this->employee->id])->assertCreated();
        $this->assertSame($docId, $res2->json('id'));
    }

    public function test_preview_pdf_uses_same_pipeline_without_emission(): void
    {
        $u = $this->user(['workforce_contract.view', 'workforce_contract.generate', 'workforce.view', 'workforce.manage']);
        $before = GeneratedDocument::withoutGlobalScopes()->count();
        $res = $this->api($u, 'POST', '/preview-pdf', ['template_id' => $this->platformTplId, 'employee_id' => $this->employee->id])->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertSame($before, GeneratedDocument::withoutGlobalScopes()->count(), 'aperçu PDF : aucun document émis');
    }

    public function test_preview_pdf_sample_mode_without_employee(): void
    {
        $u = $this->user(['workforce_contract.view', 'workforce_contract.generate']);
        $res = $this->api($u, 'POST', '/preview-pdf', ['template_id' => $this->platformTplId])->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_server_sanitizes_wysiwyg_content(): void
    {
        $u = $this->user(['workforce_contract.view', 'workforce_contract.manage_templates']);
        $copy = $this->api($u, 'POST', "/templates/{$this->platformTplId}/duplicate")->json();
        $malicious = '<p>Bonjour {{ employee.full_name }}</p><script>alert(1)</script><a href="javascript:evil()">x</a><img src="https://tracker.example/p.gif">';
        $this->api($u, 'PUT', "/templates/{$copy['id']}", ['body_html' => $malicious])->assertOk();

        $saved = DocumentTemplate::find($copy['id'])->body_html;
        $this->assertStringNotContainsString('<script', $saved);
        $this->assertStringNotContainsString('javascript:', $saved);
        $this->assertStringNotContainsString('tracker.example', $saved, 'ressource externe retirée');
        $this->assertStringContainsString('{{ employee.full_name }}', $saved, 'variables préservées');
    }

    public function test_set_default_one_per_contract_type(): void
    {
        // Deux modèles Platform DISTINCTS de type cdi (codes différents) → dupliqués + publiés.
        $plat2 = $this->tpl(null, 'contract_cdi_fr_v2', 'CDI FR 2', ['market_key' => 'FR', 'contract_type' => 'cdi']);
        $u = $this->user(['workforce_contract.view', 'workforce_contract.manage_templates']);
        $a = $this->api($u, 'POST', "/templates/{$this->platformTplId}/duplicate")->json();
        $this->api($u, 'POST', "/templates/{$a['id']}/publish")->assertOk();
        $b = $this->api($u, 'POST', "/templates/{$plat2->id}/duplicate")->json();
        $this->api($u, 'POST', "/templates/{$b['id']}/publish")->assertOk();

        $this->api($u, 'POST', "/templates/{$a['id']}/default")->assertOk()->assertJsonFragment(['is_default' => true]);
        $this->api($u, 'POST', "/templates/{$b['id']}/default")->assertOk()->assertJsonFragment(['is_default' => true]);
        // b devient défaut → a ne l'est plus (un seul défaut par type de contrat).
        $this->assertFalse(ContractTemplateService::present(DocumentTemplate::find($a['id']))['is_default']);
    }

    public function test_set_default_requires_published(): void
    {
        $u = $this->user(['workforce_contract.view', 'workforce_contract.manage_templates']);
        $draft = $this->api($u, 'POST', "/templates/{$this->platformTplId}/duplicate")->json();
        $this->api($u, 'POST', "/templates/{$draft['id']}/default")->assertStatus(422);
    }

    public function test_preview_pdf_of_unsaved_content_goes_through_pipeline(): void
    {
        $u = $this->user(['workforce_contract.view', 'workforce_contract.generate', 'workforce.view', 'workforce.manage']);
        // Contenu NON ENREGISTRÉ avec script → doit passer par la sanitation + rendu, sans crash.
        $res = $this->api($u, 'POST', '/preview-pdf', [
            'template_id' => $this->platformTplId,
            'employee_id' => $this->employee->id,
            'body_html' => '<p>Édition en cours {{ employee.full_name }}</p><script>alert(1)</script>',
        ])->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
        // Le modèle stocké n'est PAS modifié (aperçu = aucune mutation).
        $this->assertStringNotContainsString('Édition en cours', DocumentTemplate::find($this->platformTplId)->body_html);
    }

    public function test_generate_requires_generate_permission(): void
    {
        $u = $this->user(['workforce_contract.view']);
        $this->api($u, 'POST', '/generate', ['template_code' => 'contract_cdi_fr', 'employee_id' => $this->employee->id])->assertForbidden();
    }

    public function test_generate_without_contract_fails_clearly(): void
    {
        $noContract = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'No', 'last_name' => 'Ct', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'E2']);
        $u = $this->user(['workforce_contract.view', 'workforce_contract.generate', 'workforce.view', 'workforce.manage']);
        $this->api($u, 'POST', '/generate', ['template_code' => 'contract_cdi_fr', 'employee_id' => $noContract->id])->assertStatus(422);
    }

    public function test_tenant_isolation_cannot_edit_other_company_template(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'other-ct', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $foreign = DocumentTemplate::create(['company_id' => $other->id, 'code' => 'foreign', 'name' => 'Foreign', 'subject_type' => 'contract', 'body_html' => '<p>x</p>', 'variables_schema' => [], 'version' => 1, 'is_system' => false, 'is_active' => false, 'metadata' => ['status' => 'draft']]);
        $u = $this->user(['workforce_contract.view', 'workforce_contract.manage_templates']);
        $this->api($u, 'PUT', "/templates/{$foreign->id}", ['name' => 'Hack'])->assertNotFound();
    }

    public function test_module_inactive_forbidden(): void
    {
        $noModule = Company::create(['name' => 'NoMod', 'slug' => 'nomod-ct', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        CompanyModuleActivationReason::create(['company_id' => $noModule->id, 'module_key' => 'workforce', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $noModule->id, 'key' => 'r2', 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce_contract.view'])->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $noModule->id, 'role' => 'member', 'company_role_id' => $role->id]);
        $this->actingAs($u)->getJson('/api/workforce/contract/templates', ['X-Company-Id' => $noModule->id])->assertForbidden();
    }
}
