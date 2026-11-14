<?php

namespace Tests\Feature;

use App\Core\Documents\CompanyDocument;
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
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-771 — the Company's OWN document vault (insurance, tax/social certificates, licence, K-bis…),
 * through the real HTTP path with real PDF bytes. A company document has its own documentary
 * identity (`CompanyDocument`), a tenant-scoped storage, and never lands in an employee's file.
 */
class Adr771CompanyVaultTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $a;
    private Company $b;
    private User $ownerA;
    private User $ownerB;
    private User $viewer;
    private User $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        [$this->a, $this->ownerA] = $this->tenant('A');
        [$this->b, $this->ownerB] = $this->tenant('B');
        $this->viewer = $this->member($this->a, ['documents.view']);
        $this->plain = $this->member($this->a, []);
    }

    private function tenant(string $key): array
    {
        $company = Company::create(['name' => "Vault {$key}", 'slug' => 'vault-'.strtolower($key).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($company);
        $admin = $this->setUpCompanyRbac($company);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $admin->id]);
        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create(['company_id' => $company->id, 'document_type_id' => $type->id, 'enabled' => true, 'required_override' => $type->code === 'kbis', 'order' => $i * 10]);
        }

        return [$company, $owner];
    }

    private function member(Company $company, array $permissions): User
    {
        $role = \App\Company\RBAC\CompanyRole::create(['company_id' => $company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => false]);
        $role->syncPermissionsSafe(\App\Company\RBAC\CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());
        $user = User::factory()->create();
        $company->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $user;
    }

    private function as(User $u, Company $c)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $c->id]);
    }

    private function pdf(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\nVAULT[{$marker}]\n".str_repeat('x', 64)."\n%%EOF");
    }

    private function slot(User $u, Company $c, string $code): ?array
    {
        return collect($this->as($u, $c)->getJson('/api/company/documents')->assertOk()->json('documents'))->firstWhere('code', $code);
    }

    public function test_company_vault_deposit_read_replace_history_and_withdrawal(): void
    {
        $this->assertNotNull($this->slot($this->ownerA, $this->a, 'kbis'), 'the company type is offered by the vault');
        $this->assertNull($this->slot($this->ownerA, $this->a, 'kbis')['upload'] ?? null);

        // Deposit, with an OPTIONAL expiry date.
        $this->as($this->ownerA, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('V1'), 'expires_at' => now()->addMonths(3)->toDateString()])->assertOk();
        $doc = CompanyDocument::where('company_id', $this->a->id)->firstOrFail();
        $v1Path = $doc->file_path;
        $v1Sha = hash('sha256', Storage::disk('local')->get($v1Path));

        // Own documentary identity, tenant-scoped storage, never in a person's file.
        $this->assertStringStartsWith("documents/{$this->a->id}/company/", $v1Path);
        $this->assertSame($v1Sha, $doc->file_sha256);
        $this->assertSame(0, MemberDocument::withoutGlobalScopes()->count(), 'a company document never lands in an employee file');
        $this->assertNotNull($this->slot($this->ownerA, $this->a, 'kbis')['upload']['expires_at']);
        $this->assertSame($v1Sha, hash('sha256', $this->as($this->viewer, $this->a)->get('/api/company/documents/kbis/download')->assertOk()->streamedContent()));

        // Replacement = new version ; the previous bytes are kept and downloadable.
        $this->as($this->ownerA, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('V2')])->assertOk();
        $doc->refresh();
        $this->assertNotSame($v1Path, $doc->file_path);
        $this->assertSame($v1Sha, hash('sha256', Storage::disk('local')->get($v1Path)), 'the previous version is not overwritten');
        $this->assertSame(1, CompanyDocument::where('company_id', $this->a->id)->count(), 'one current document per type');
        $versions = $this->as($this->ownerA, $this->a)->getJson('/api/company/documents/kbis/versions')->assertOk()->json('versions');
        $this->assertCount(1, $versions);
        $archived = $this->as($this->ownerA, $this->a)->get("/api/company/documents/kbis/versions/{$versions[0]['id']}/download")->assertOk()->streamedContent();
        $this->assertSame($v1Sha, hash('sha256', $archived), 'the archived version serves the ORIGINAL bytes');

        // Same file again : no new version (deduplicated by fingerprint).
        $this->as($this->ownerA, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('V2')])->assertOk();
        $this->assertCount(1, $this->as($this->ownerA, $this->a)->getJson('/api/company/documents/kbis/versions')->json('versions'));

        // Rights : read with documents.view, write with documents.manage, nothing without.
        $this->as($this->viewer, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('X')])->assertStatus(403);
        $this->as($this->viewer, $this->a)->deleteJson('/api/company/documents/kbis')->assertStatus(403);
        $this->as($this->plain, $this->a)->getJson('/api/company/documents')->assertStatus(403);
        $this->as($this->plain, $this->a)->get('/api/company/documents/kbis/download')->assertStatus(403);

        // Withdrawal : traced, the files stay on disk (current and archived).
        $currentPath = $doc->file_path;
        $this->as($this->ownerA, $this->a)->deleteJson('/api/company/documents/kbis')->assertOk();
        $this->assertNull($this->slot($this->ownerA, $this->a, 'kbis')['upload'] ?? null);
        Storage::disk('local')->assertExists($currentPath);
        Storage::disk('local')->assertExists($v1Path);
        $this->assertDatabaseHas('company_audit_logs', ['company_id' => $this->a->id, 'action' => 'document.company_withdrawn']);
    }

    public function test_company_vault_is_isolated_between_companies(): void
    {
        $this->as($this->ownerA, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('A')])->assertOk();
        $this->as($this->ownerB, $this->b)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('B')])->assertOk();
        $docA = CompanyDocument::where('company_id', $this->a->id)->firstOrFail();
        $docB = CompanyDocument::where('company_id', $this->b->id)->firstOrFail();

        $this->assertStringStartsWith("documents/{$this->b->id}/company/", $docB->file_path);
        $this->assertNotSame($docA->file_path, $docB->file_path);
        $bytesB = $this->as($this->ownerB, $this->b)->get('/api/company/documents/kbis/download')->assertOk()->streamedContent();
        $this->assertStringContainsString('VAULT[B]', $bytesB);
        $this->assertStringNotContainsString('VAULT[A]', $bytesB);

        // A member of A reaches nothing of B ; replacing in A leaves B byte-identical.
        $this->as($this->ownerA, $this->b)->getJson('/api/company/documents')->assertStatus(403);
        $before = hash('sha256', Storage::disk('local')->get($docB->file_path));
        $this->as($this->ownerA, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('A2')])->assertOk();
        $this->as($this->ownerA, $this->a)->deleteJson('/api/company/documents/kbis')->assertOk();
        $this->assertSame($before, hash('sha256', Storage::disk('local')->get($docB->fresh()->file_path)));
        $this->assertNotNull($this->slot($this->ownerB, $this->b, 'kbis')['upload']);
    }

    public function test_compliance_follows_the_company_document_when_the_type_is_required(): void
    {
        $slot = fn () => collect($this->as($this->ownerA, $this->a)->getJson('/api/company/documents/compliance')->assertOk()->json('by_type') ?? [])->firstWhere('code', 'kbis');

        $missing = $slot();
        $this->assertNotNull($missing, 'the required company type is tracked by compliance');
        $this->assertSame(1, (int) ($missing['missing'] ?? 0));

        $this->as($this->ownerA, $this->a)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('OK'), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $this->assertSame(0, (int) ($slot()['missing'] ?? 0));
        $this->assertSame(1, (int) ($slot()['valid'] ?? 0));

        // Expired : compliance says so (the date is real, set in the past on the stored row).
        CompanyDocument::where('company_id', $this->a->id)->update(['expires_at' => now()->subDay()]);
        $this->assertSame(1, (int) ($slot()['expired'] ?? 0));

        // ADR-772 : the scheduled renewal now opens an internal request borne by the Company subject.
        \App\Core\Documents\CompanyDocumentSetting::updateOrCreate(['company_id' => $this->a->id], ['auto_renew_enabled' => true, 'renew_days_before' => 30]);
        CompanyDocument::where('company_id', $this->a->id)->update(['expires_at' => now()->addDays(5)]);
        $this->artisan('documents:auto-renew')->assertSuccessful();
        $this->assertSame(['company'], \App\Core\Documents\DocumentRequest::withoutGlobalScopes()->where('company_id', $this->a->id)->pluck('document_subject_type')->all());
    }
}
