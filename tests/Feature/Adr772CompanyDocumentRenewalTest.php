<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\CompanyDocument;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-772 (P1) — a document obligation borne by the COMPANY itself (insurance, K-bis, licence…) :
 * expiry detection, internal renewal request, reminders, new version, validation, compliance,
 * history. Same request engine and same scheduled orchestrator as for persons ; the subject is the
 * Company — never an employee, and nobody is invented as its addressee.
 */
class Adr772CompanyDocumentRenewalTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $a;
    private Company $b;
    private User $owner;
    private User $ownerB;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        [$this->a, $this->owner] = $this->tenant('A');
        [$this->b, $this->ownerB] = $this->tenant('B');
        $role = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'viewer', 'name' => 'Viewer', 'is_administrative' => false]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', ['documents.view'])->pluck('id')->all());
        $this->viewer = User::factory()->create();
        $this->a->memberships()->create(['user_id' => $this->viewer->id, 'role' => 'user', 'company_role_id' => $role->id]);
    }

    private function tenant(string $key): array
    {
        $company = Company::create(['name' => "Co {$key}", 'slug' => 'co-'.strtolower($key).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($company);
        $admin = $this->setUpCompanyRbac($company);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $admin->id]);
        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create(['company_id' => $company->id, 'document_type_id' => $type->id, 'enabled' => true, 'required_override' => $type->code === 'kbis', 'order' => $i * 10]);
        }
        CompanyDocumentSetting::updateOrCreate(['company_id' => $company->id], ['auto_renew_enabled' => true, 'renew_days_before' => 30, 'auto_remind_enabled' => true, 'remind_after_days' => 7]);

        return [$company, $owner];
    }

    private function as(User $u, Company $c)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $c->id]);
    }

    private function pdf(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('kbis.pdf', "%PDF-1.4\nKBIS[{$marker}]\n".str_repeat('x', 64)."\n%%EOF");
    }

    private function slot(User $u, Company $c): array
    {
        return collect($this->as($u, $c)->getJson('/api/company/documents')->assertOk()->json('documents'))->firstWhere('code', 'kbis');
    }

    private function compliance(User $u, Company $c): array
    {
        return collect($this->as($u, $c)->getJson('/api/company/documents/compliance')->assertOk()->json('by_type'))->firstWhere('code', 'kbis');
    }

    private function request(Company $c): ?DocumentRequest
    {
        return DocumentRequest::withoutGlobalScopes()->where('company_id', $c->id)->where('document_subject_type', 'company')->first();
    }

    public function test_company_document_expiry_renewal_validation_and_history(): void
    {
        // A valid company document : compliant, no request needed.
        $this->as($this->owner, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('V1'), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $this->as($this->ownerB, $this->b)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('B'), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $this->assertNull($this->request($this->a));
        $this->assertSame(1, (int) $this->compliance($this->owner, $this->a)['valid']);
        // Time passes : both documents now expire in 10 days.
        CompanyDocument::withoutGlobalScopes()->update(['expires_at' => now()->addDays(10)]);
        CompanyDocumentSetting::where('company_id', $this->b->id)->update(['auto_renew_enabled' => false]);

        // Expiry approaching : ONE internal renewal request, borne by the Company subject.
        foreach (['documents:auto-renew', 'documents:auto-renew', 'documents:check-expiration'] as $command) {
            $this->artisan($command)->assertSuccessful();
        }
        $request = $this->request($this->a);
        $this->assertNotNull($request, 'a renewal request is opened for the company document');
        $this->assertSame(['requested', 'company', $this->a->id, null], [$request->status, $request->document_subject_type, (int) $request->document_subject_id, $request->user_id]);
        $this->assertSame(1, DocumentRequestEvent::where('document_request_id', $request->id)->where('event', DocumentRequestEvent::E_RENEWED)->count());
        $this->assertSame(1, DocumentRequest::withoutGlobalScopes()->where('company_id', $this->a->id)->count());
        $this->assertNull($this->request($this->b), 'B has automation off : untouched');

        // The request is borne by the Company subject (addressees = company managers, existing `notify`) :
        // never an employee subject, no member document.
        $this->assertSame(0, MemberDocument::withoutGlobalScopes()->count());
        $slot = $this->slot($this->owner, $this->a);
        $this->assertSame(['requested', false], [$slot['request_status'], $slot['can_review']]);

        // New version deposited : submitted, previous version kept, awaiting validation.
        $v1 = CompanyDocument::where('company_id', $this->a->id)->value('file_path');
        $this->as($this->owner, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('V2'), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        Storage::disk('local')->assertExists($v1);
        $slot = $this->slot($this->owner, $this->a);
        $this->assertSame(['submitted', true], [$slot['request_status'], $slot['can_review']]);
        $this->assertSame(0, (int) $this->compliance($this->owner, $this->a)['valid'], 'not compliant until validated');

        // Validation by an authorised person ; refusal keeps its reason.
        $this->as($this->viewer, $this->a)->putJson('/api/company/documents/kbis/review', ['status' => 'approved'])->assertStatus(403);
        $this->as($this->owner, $this->a)->putJson('/api/company/documents/kbis/review', ['status' => 'rejected', 'review_note' => 'Document tronqué'])->assertOk();
        $this->assertSame(['rejected', 'Document tronqué'], [$this->slot($this->owner, $this->a)['request_status'], $this->slot($this->owner, $this->a)['request_review_note']]);
        $this->as($this->owner, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('V3'), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $this->as($this->owner, $this->a)->putJson('/api/company/documents/kbis/review', ['status' => 'approved'])->assertOk()->assertJsonPath('review.status', 'approved');

        // Compliance recomputed, history kept (versions + events), nothing re-opened by a new run.
        $this->assertSame(1, (int) $this->compliance($this->owner, $this->a)['valid']);
        $this->assertCount(2, $this->as($this->owner, $this->a)->getJson('/api/company/documents/kbis/versions')->json('versions'));
        $events = DocumentRequestEvent::where('document_request_id', $request->id)->pluck('event')->all();
        foreach (['renewed', 'submitted', 'rejected', 'approved'] as $expected) {
            $this->assertContains($expected, $events);
        }
        $this->artisan('documents:auto-renew')->assertSuccessful();
        $this->assertSame('approved', $request->fresh()->status);

        // B strictly untouched.
        $this->assertSame(0, DocumentRequest::withoutGlobalScopes()->where('company_id', $this->b->id)->count());
        $this->as($this->ownerB, $this->b)->putJson('/api/company/documents/kbis/review', ['status' => 'approved'])->assertStatus(404);
    }

    public function test_reminders_address_the_company_managers_once_per_tier(): void
    {
        $this->as($this->owner, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('V1'), 'expires_at' => now()->addDays(10)->toDateString()])->assertOk();
        $this->artisan('documents:auto-renew')->assertSuccessful();
        $request = $this->request($this->a);

        $this->travel(4)->days(); // inside the J-7 window of the expiring document
        $this->artisan('documents:auto-remind')->assertSuccessful();
        $this->artisan('documents:auto-remind')->assertSuccessful();

        $reminded = DocumentRequestEvent::where('document_request_id', $request->id)->where('event', DocumentRequestEvent::E_REMINDED)->get();
        $this->assertGreaterThanOrEqual(1, $reminded->count(), 'the company request is reminded');
        $this->assertSame($reminded->count(), $reminded->pluck('metadata')->map(fn ($m) => $m['tier'] ?? null)->unique()->count(), 'once per tier');
    }
}
