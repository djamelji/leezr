<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\CompanyDocumentSetting;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentRequestEvent;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-772 (P0) — an employee WITHOUT account has a fully operational document file : request,
 * administrative deposit, review (approve / reject) by an authorised person, replacement, expiry,
 * scheduled renewal, history, compliance. Same engine as for members (DocumentRequestService) —
 * no artificial account, no second validation logic.
 */
class Adr772AccountlessEmployeeDocumentCycleTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $a;
    private Company $b;
    private User $owner;
    private User $ownerB;
    private User $viewer;   // documents.view only
    private Employee $employee;
    private Employee $employeeB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce']]);

        [$this->a, $this->owner, $this->employee] = $this->tenant('A');
        [$this->b, $this->ownerB, $this->employeeB] = $this->tenant('B');

        $role = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'viewer', 'name' => 'Viewer', 'is_administrative' => false]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', ['documents.view'])->pluck('id')->all());
        $this->viewer = User::factory()->create();
        $this->a->memberships()->create(['user_id' => $this->viewer->id, 'role' => 'user', 'company_role_id' => $role->id]);
    }

    private function tenant(string $key): array
    {
        $company = Company::create(['name' => "Co {$key}", 'slug' => 'co-'.strtolower($key).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($company);
        \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => 'workforce'], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        $admin = $this->setUpCompanyRbac($company);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $admin->id]);
        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create(['company_id' => $company->id, 'document_type_id' => $type->id, 'enabled' => true, 'required_override' => $type->code === 'id_card', 'order' => $i * 10]);
        }
        $employee = Employee::create(['company_id' => $company->id, 'user_id' => null, 'employee_number' => 'E'.uniqid(), 'first_name' => 'Sans', 'last_name' => 'Compte',
            'status' => 'active', 'hire_date' => '2026-01-01']);

        return [$company, $owner, $employee];
    }

    private function as(User $u, Company $c)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $c->id]);
    }

    private function pdf(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('id.pdf', "%PDF-1.4\nCYCLE[{$marker}]\n".str_repeat('x', 64)."\n%%EOF");
    }

    private function entry(User $actor, Company $c, Employee $e): array
    {
        return collect($this->as($actor, $c)->getJson("/api/company/people/employee-{$e->id}/documents")->assertOk()->json('documents'))->firstWhere('code', 'id_card');
    }

    private function request(Company $c, Employee $e): ?DocumentRequest
    {
        return DocumentRequest::where('company_id', $c->id)->where('document_subject_type', 'employee')->where('document_subject_id', $e->id)->first();
    }

    public function test_full_cycle_request_deposit_reject_replace_approve_expire_renew(): void
    {
        $users = User::count();
        $ref = "employee-{$this->employee->id}";
        $base = "/api/company/people/{$ref}/documents/id_card";

        // 1. Request addressed to the HR record itself.
        $this->as($this->owner, $this->a)->postJson('/api/company/document-requests', ['person_ref' => $ref, 'document_type_code' => 'id_card', 'message' => 'Merci'])->assertCreated();
        $this->assertSame(['requested', false], [$this->entry($this->owner, $this->a, $this->employee)['request_status'], $this->entry($this->owner, $this->a, $this->employee)['can_review']]);

        // 2. Administrative deposit = submission. It can now be reviewed.
        $this->as($this->owner, $this->a)->postJson($base, ['file' => $this->pdf('V1'), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $entry = $this->entry($this->owner, $this->a, $this->employee);
        $this->assertSame(['submitted', true], [$entry['request_status'], $entry['can_review']]);

        // 3. Refusal by an authorised person, with its reason.
        $this->as($this->viewer, $this->a)->putJson("{$base}/review", ['status' => 'rejected', 'review_note' => 'Illisible'])->assertStatus(403);
        $this->as($this->ownerB, $this->b)->putJson("{$base}/review", ['status' => 'approved'])->assertStatus(404);
        $this->as($this->owner, $this->a)->putJson("{$base}/review", ['status' => 'rejected', 'review_note' => 'Illisible'])->assertOk()->assertJsonPath('review.status', 'rejected');
        $entry = $this->entry($this->owner, $this->a, $this->employee);
        $this->assertSame(['rejected', 'Illisible', false], [$entry['request_status'], $entry['request_review_note'], $entry['can_review']]);

        // 4. Replacement : the previous file becomes history, the request is submitted again.
        $v1 = MemberDocument::where('company_id', $this->a->id)->where('document_subject_id', $this->employee->id)->value('file_path');
        $this->as($this->owner, $this->a)->postJson($base, ['file' => $this->pdf('V2'), 'expires_at' => now()->addDays(10)->toDateString()])->assertOk();
        Storage::disk('local')->assertExists($v1);
        $this->assertCount(1, $this->as($this->owner, $this->a)->getJson("{$base}/versions")->assertOk()->json('versions'));
        $this->assertSame('submitted', $this->request($this->a, $this->employee)->status);

        // 5. Approval : compliant.
        $this->as($this->owner, $this->a)->putJson("{$base}/review", ['status' => 'approved'])->assertOk()->assertJsonPath('review.status', 'approved');
        $entry = $this->entry($this->owner, $this->a, $this->employee);
        $this->assertSame(['approved', true], [$entry['request_status'], $entry['satisfies_obligation']]);
        $this->as($this->owner, $this->a)->putJson("{$base}/review", ['status' => 'approved'])->assertStatus(422); // nothing left to review

        // 6. Expiry approaching : the scheduled renewal re-opens the request ONCE, for the HR record.
        CompanyDocumentSetting::updateOrCreate(['company_id' => $this->a->id], ['auto_renew_enabled' => true, 'renew_days_before' => 30, 'auto_remind_enabled' => true, 'remind_after_days' => 7]);
        foreach (['documents:auto-renew', 'documents:auto-renew', 'documents:check-expiration'] as $command) {
            $this->artisan($command)->assertSuccessful();
        }
        $request = $this->request($this->a, $this->employee);
        $this->assertSame('requested', $request->status);
        $this->assertSame(1, DocumentRequestEvent::where('document_request_id', $request->id)->where('event', DocumentRequestEvent::E_RENEWED)->count());

        // 7. Renewal deposited and approved : compliant again, the whole history is kept.
        $this->as($this->owner, $this->a)->postJson($base, ['file' => $this->pdf('V3'), 'expires_at' => now()->addYears(2)->toDateString()])->assertOk();
        $this->as($this->owner, $this->a)->putJson("{$base}/review", ['status' => 'approved'])->assertOk();
        $this->assertCount(2, $this->as($this->owner, $this->a)->getJson("{$base}/versions")->json('versions'));
        $this->assertTrue($this->entry($this->owner, $this->a, $this->employee)['satisfies_obligation']);
        $events = DocumentRequestEvent::where('document_request_id', $request->id)->pluck('event')->all();
        foreach (['requested', 'submitted', 'rejected', 'approved', 'renewed'] as $expected) {
            $this->assertContains($expected, $events, "history keeps « {$expected} »");
        }

        // No artificial account, one document, one request, subject unchanged.
        $this->assertSame($users, User::count());
        $this->assertNull($this->employee->fresh()->user_id);
        $this->assertSame(1, MemberDocument::where('company_id', $this->a->id)->count());
        $this->assertSame(1, DocumentRequest::where('company_id', $this->a->id)->count());
        $this->assertNull(MemberDocument::where('company_id', $this->a->id)->value('user_id'));
    }

    public function test_review_queue_and_isolation(): void
    {
        // Same shape in B : must never be touched from A.
        $this->as($this->ownerB, $this->b)->postJson("/api/company/people/employee-{$this->employeeB->id}/documents/id_card", ['file' => $this->pdf('B'), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $this->as($this->owner, $this->a)->postJson("/api/company/people/employee-{$this->employee->id}/documents/id_card", ['file' => $this->pdf('A'), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $reqA = $this->request($this->a, $this->employee);
        $reqB = $this->request($this->b, $this->employeeB);

        // A cannot review B's record by reference, nor through the bulk queue with B's request id.
        $this->as($this->owner, $this->a)->putJson("/api/company/people/employee-{$this->employeeB->id}/documents/id_card/review", ['status' => 'approved'])->assertStatus(404);
        $this->as($this->owner, $this->a)->postJson('/api/company/document-requests/bulk-action', ['ids' => [$reqB->id], 'action' => 'approved'])->assertOk();
        $this->assertSame('submitted', $reqB->fresh()->status);

        // The validation queue handles the account-less request like any other.
        $this->as($this->viewer, $this->a)->postJson('/api/company/document-requests/bulk-action', ['ids' => [$reqA->id], 'action' => 'approved'])->assertStatus(403);
        $this->as($this->owner, $this->a)->postJson('/api/company/document-requests/bulk-action', ['ids' => [$reqA->id], 'action' => 'approved'])->assertOk()->assertJsonPath('processed', 1);
        $this->assertSame('approved', $reqA->fresh()->status);
        $this->assertSame('submitted', $reqB->fresh()->status);
    }
}
