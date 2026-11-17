<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\GeneratedDocument;
use App\Core\Documents\ReadModels\DocumentComplianceReadModel;
use App\Core\Documents\ReadModels\SubjectDocumentReadModel;
use App\Core\Documents\Satisfaction\DocumentProofRegistry;
use App\Core\Documents\Services\DocumentRequestService;
use App\Core\Documents\Services\RequiredDocumentRequests;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\Documents\SignedContractProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

/**
 * ADR-819 (Company 2.0) — ONE requirement « contrat de travail signé », TWO compatible sources : an upload
 * (historical path) or a contract generated AND signed in Workforce. The second is recognised automatically,
 * never copied, never asked again — and only when it really is the latest, signed version of the person's
 * current, effective contract, in the same company, with the contract module available.
 */
class Adr819DocumentProofTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private const MODULES = ['workforce', 'workforce_documents', 'workforce_contract', 'workforce_signature'];

    private Company $a;
    private Company $b;
    private DocumentTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => self::MODULES]);
        $this->a = $this->company('a');
        $this->b = $this->company('b');
        $this->template = DocumentTemplate::create(['company_id' => $this->a->id, 'code' => 'cdi', 'name' => 'CDI', 'subject_type' => 'contract',
            'body_html' => '<p>x</p>', 'variables_schema' => [], 'version' => 1, 'is_active' => true]);
    }

    private function company(string $slug): Company
    {
        $c = Company::create(['name' => $slug, 'slug' => 'adr819-'.$slug, 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach (self::MODULES as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $type = DocumentType::where('code', 'work_contract')->firstOrFail();
        DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => $type->id, 'enabled' => true, 'order' => 1, 'required_override' => true]);

        return $c;
    }

    private function employee(Company $c, string $n, string $contractStatus = 'active'): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'first_name' => 'Emp', 'last_name' => $n, 'status' => 'active',
            'hire_date' => '2026-01-01', 'employee_number' => $n, 'email' => strtolower($n).'@adr819.test']);
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => $c->id, 'employee_id' => $e->id, 'contract_type' => 'cdi',
            'work_model_key' => 'hourly', 'start_date' => '2026-01-01', 'status' => $contractStatus, 'is_current' => true]);

        return $e;
    }

    private function generate(Employee $e, string $status = 'none'): GeneratedDocument
    {
        $contract = EmploymentContract::withoutGlobalScopes()->where('employee_id', $e->id)->where('is_current', true)->firstOrFail();
        $doc = GeneratedDocument::create(['company_id' => $e->company_id, 'document_template_id' => $this->template->id, 'template_version' => 1, 'generated_by' => $this->actor()->id,
            'subject_type' => 'contract', 'subject_id' => $contract->id, 'generated_at' => now()->addSeconds(GeneratedDocument::count()),
            'file_path' => 'x.pdf', 'file_size_bytes' => 10, 'generation_snapshot' => [], 'signature_status' => $status === 'none' ? 'none' : 'pending']);
        if ($status === 'signed') {
            $doc->transitionSignatureTo('signed');
            $doc->signed_at = now();
            $doc->save();
        }

        return $doc->fresh();
    }

    private function actor(): \App\Core\Models\User
    {
        return \App\Core\Models\User::first() ?? \App\Core\Models\User::factory()->create();
    }

    private function slot(Company $c, Employee $e): array
    {
        return collect(DocumentComplianceReadModel::slotsFor($c))->first(fn ($s) => ($s['employee_id'] ?? null) === $e->id && $s['code'] === 'work_contract');
    }

    public function test_signed_workforce_contract_satisfies_the_requirement_without_copy(): void
    {
        $this->assertContains(SignedContractProof::class, DocumentProofRegistry::sources());
        $e = $this->employee($this->a, 'SIGN');
        $this->assertSame([], DocumentProofRegistry::forEmployees($this->a, [$e->id]));
        $this->assertNull($this->slot($this->a, $e)['satisfied_by']);

        $before = \App\Core\Documents\MemberDocument::count();
        $doc = $this->generate($e, 'signed');
        $proof = DocumentProofRegistry::forEmployees($this->a, [$e->id])[$e->id.':work_contract'];
        $this->assertSame(SignedContractProof::SOURCE, $proof['source']);
        $this->assertSame('generated_document:'.$doc->id, $proof['reference']);
        $this->assertSame($before, \App\Core\Documents\MemberDocument::count(), 'no file copied');

        $slot = $this->slot($this->a, $e);
        $this->assertSame(SignedContractProof::SOURCE, $slot['satisfied_by']);

        $hidden = collect(SubjectDocumentReadModel::get($this->a, DocumentSubject::employee($e->id), null)['documents'])->firstWhere('code', 'work_contract');
        $this->assertNull($hidden['satisfied_by'], 'an unauthorised reader learns nothing');
        $owner = \App\Core\Models\User::factory()->create();
        \App\Core\Models\Membership::create(['user_id' => $owner->id, 'company_id' => $this->a->id, 'role' => 'owner']);
        $file = collect(SubjectDocumentReadModel::get($this->a, DocumentSubject::employee($e->id), $owner)['documents'])->firstWhere('code', 'work_contract');
        $this->assertTrue($file['satisfies_obligation']);
        $this->assertSame(SignedContractProof::SOURCE, $file['satisfied_by']['source']);

        // Never asked again.
        $this->assertNotContains('work_contract', RequiredDocumentRequests::ensureForEmployee($this->a, $e));
    }

    public function test_draft_unsigned_pending_or_replaced_versions_never_count(): void
    {
        $draft = $this->employee($this->a, 'DRAFT', 'draft');
        $this->generate($draft, 'signed');
        $this->assertSame([], DocumentProofRegistry::forEmployees($this->a, [$draft->id]), 'a draft contract has not taken effect');

        $pending = $this->employee($this->a, 'PEND');
        $this->generate($pending, 'pending');
        $this->assertSame([], DocumentProofRegistry::forEmployees($this->a, [$pending->id]), 'sent for signature is not signed');

        $replaced = $this->employee($this->a, 'REPL');
        $this->generate($replaced, 'signed');
        $this->generate($replaced, 'none'); // regenerated afterwards : the newer version is not signed
        $this->assertSame([], DocumentProofRegistry::forEmployees($this->a, [$replaced->id]), 'a replaced signature does not count');
        $this->assertContains('work_contract', RequiredDocumentRequests::ensureForEmployee($this->a, $replaced));
    }

    public function test_module_and_tenant_boundaries(): void
    {
        $e = $this->employee($this->a, 'MOD');
        $this->generate($e, 'signed');
        $this->assertSame([], DocumentProofRegistry::forEmployees($this->b, [$e->id]), 'another company never sees the proof');

        CompanyModule::where('company_id', $this->a->id)->where('module_key', 'workforce_contract')->update(['is_enabled_for_company' => false]);
        CompanyModuleActivationReason::where('company_id', $this->a->id)->where('module_key', 'workforce_contract')->delete();
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame([], DocumentProofRegistry::forEmployees($this->a->fresh(), [$e->id]), 'module unavailable : no proof');
    }

    public function test_signature_closes_the_open_request_waiting_for_a_file(): void
    {
        $e = $this->employee($this->a, 'REQ');
        $this->assertContains('work_contract', RequiredDocumentRequests::ensureForEmployee($this->a, $e));
        $typeId = DocumentType::where('code', 'work_contract')->value('id');
        $request = app(DocumentRequestService::class)->findExisting($this->a->id, DocumentSubject::employee($e->id), $typeId);
        $this->assertSame(DocumentRequest::STATUS_REQUESTED, $request->status);

        $this->generate($e, 'signed');
        $this->assertSame(DocumentRequest::STATUS_CANCELLED, $request->fresh()->status);
    }
}
