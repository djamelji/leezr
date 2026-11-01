<?php

namespace Tests\Feature;

use App\Core\Documents\GeneratedDocument;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\ReadModels\GeneratedDocumentReadModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRODUCT-UX-2 (LOT A2) — registre documentaire transversal : le filtre collaborateur résout
 * document→employé quel que soit le type de sujet (employee / contract), enrichit chaque ligne du
 * collaborateur, et reste borné à la company (isolation tenant).
 */
class DocumentRegistryEmployeeFilterTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->uid = \App\Core\Models\User::factory()->create()->id;
        \App\Core\Jobdomains\Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce']]);
        \App\Core\Markets\Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $this->companyA = Company::create(['name' => 'Doc A', 'slug' => 'doc-a', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $this->companyB = Company::create(['name' => 'Doc B', 'slug' => 'doc-b', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
    }

    private function employee(Company $c, string $first): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'first_name' => $first, 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid()]);
    }

    private function template(string $subjectType): int
    {
        return \App\Core\Documents\DocumentTemplate::withoutGlobalScopes()->create([
            'code' => 'tpl-'.uniqid(), 'name' => 'Modèle '.$subjectType, 'subject_type' => $subjectType, 'is_active' => true, 'body_html' => '<p>x</p>', 'variables_schema' => [],
        ])->id;
    }

    private function doc(Company $c, string $subjectType, int $subjectId, ?string $filePath = null): GeneratedDocument
    {
        return GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $c->id, 'document_template_id' => $this->template($subjectType), 'template_version' => 1,
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'generated_by' => $this->uid, 'generation_snapshot' => [], 'generated_at' => now(), 'file_path' => $filePath ?: 'docs/'.uniqid().'.pdf', 'file_size_bytes' => 100,
            'signature_status' => GeneratedDocument::SIGNATURE_NONE,
        ]);
    }

    public function test_employee_filter_resolves_across_subject_types(): void
    {
        $emp = $this->employee($this->companyA, 'Thomas');
        $contract = EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'employee_id' => $emp->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);

        $dEmp = $this->doc($this->companyA, 'employee', $emp->id);          // doc direct employé
        $dContract = $this->doc($this->companyA, 'contract', $contract->id); // doc contrat de l'employé
        // Bruit : doc d'un AUTRE employé + d'une AUTRE company
        $other = $this->employee($this->companyA, 'Sophie');
        $this->doc($this->companyA, 'employee', $other->id);
        $this->doc($this->companyB, 'employee', $emp->id); // même id mais autre company

        $page = GeneratedDocumentReadModel::forCompany($this->companyA->id, ['employee_id' => $emp->id]);
        $ids = collect($page->items())->pluck('id')->sort()->values()->all();

        $this->assertSame([$dEmp->id, $dContract->id], collect([$dEmp->id, $dContract->id])->sort()->values()->all());
        $this->assertEqualsCanonicalizing([$dEmp->id, $dContract->id], $ids, 'le filtre résout employee + contract, et exclut les autres');
    }

    public function test_each_row_is_enriched_with_resolved_collaborator(): void
    {
        $emp = $this->employee($this->companyA, 'Thomas');
        $this->doc($this->companyA, 'employee', $emp->id);

        $page = GeneratedDocumentReadModel::forCompany($this->companyA->id, []);
        $row = collect($page->items())->first();

        $this->assertSame($emp->id, $row->employee_id);
        $this->assertSame('Thomas X', $row->employee_name);
        // Vérifie que l'attribut est bien sérialisé (exposé au front).
        $this->assertSame('Thomas X', $row->toArray()['employee_name'] ?? null);
    }

    public function test_tenant_isolation_employee_filter(): void
    {
        $empA = $this->employee($this->companyA, 'Thomas');
        $this->doc($this->companyA, 'employee', $empA->id);

        // Filtrer la company B sur l'employé de A ne doit rien renvoyer.
        $page = GeneratedDocumentReadModel::forCompany($this->companyB->id, ['employee_id' => $empA->id]);
        $this->assertCount(0, $page->items());
    }

    public function test_search_filter_matches_file_path(): void
    {
        $emp = $this->employee($this->companyA, 'Thomas');
        $this->doc($this->companyA, 'employee', $emp->id, 'docs/contrat-unique-xyz.pdf');

        $page = GeneratedDocumentReadModel::forCompany($this->companyA->id, ['search' => 'unique-xyz']);
        $this->assertCount(1, $page->items());

        $none = GeneratedDocumentReadModel::forCompany($this->companyA->id, ['search' => 'zzz-absent']);
        $this->assertCount(0, $none->items());
    }
}
