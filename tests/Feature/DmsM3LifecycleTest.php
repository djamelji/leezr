<?php

namespace Tests\Feature;

use App\Core\Documents\CompanyDocumentSetting;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentRequestEvent;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentLifecycleOrchestrator;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-760 (DMS M3) — orchestrateur unifié : renouvellement + relances idempotents, sans doublon,
 * tiers configurables, signal account-less. Détection d'expiration fiable.
 */
class DmsM3LifecycleTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->company = Company::create(['name' => 'M3 Co', 'slug' => 'm3-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);

        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);
        $this->userB = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->userB->id, 'role' => 'user', 'company_role_id' => $adminRole->id]);

        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create(['company_id' => $this->company->id, 'document_type_id' => $type->id, 'enabled' => true, 'order' => $i * 10]);
        }
    }

    private function orchestrator(): DocumentLifecycleOrchestrator
    {
        return app(DocumentLifecycleOrchestrator::class);
    }

    private function settings(array $policy = [], array $over = []): CompanyDocumentSetting
    {
        return CompanyDocumentSetting::updateOrCreate(['company_id' => $this->company->id], array_merge([
            'auto_renew_enabled' => true, 'renew_days_before' => 30,
            'auto_remind_enabled' => true, 'remind_after_days' => 7,
            'reminder_policy' => $policy ?: null,
        ], $over));
    }

    private function memberDoc(int $userId, string $typeCode, $expiresAt, array $over = []): MemberDocument
    {
        $type = DocumentType::where('code', $typeCode)->firstOrFail();

        return MemberDocument::create(array_merge([
            'company_id' => $this->company->id,
            'user_id' => $userId,
            'document_subject_type' => 'user',
            'document_subject_id' => $userId,
            'document_type_id' => $type->id,
            'file_path' => "documents/{$this->company->id}/{$userId}/x.pdf",
            'file_name' => 'x.pdf', 'file_size_bytes' => 100, 'mime_type' => 'application/pdf',
            'file_sha256' => str_repeat('a', 64), 'published_state' => 'published',
            'uploaded_by' => $this->owner->id, 'expires_at' => $expiresAt,
        ], $over));
    }

    // ───────── Renouvellement idempotent ─────────

    public function test_renewal_opens_request_for_expiring_document_once(): void
    {
        $this->settings();
        $this->memberDoc($this->userB->id, 'id_card', now()->addDays(10)); // dans la fenêtre de 30j

        $this->orchestrator()->run(['renew']);
        $this->assertSame(1, DocumentRequest::where('company_id', $this->company->id)->count());
        $this->assertDatabaseHas('document_request_events', ['event' => 'renewed']);

        // Deuxième exécution → AUCUN doublon (demande déjà active)
        $this->orchestrator()->run(['renew']);
        $this->assertSame(1, DocumentRequest::where('company_id', $this->company->id)->count());
        $this->assertSame(1, DocumentRequestEvent::where('event', 'renewed')->count());
    }

    public function test_no_renewal_for_document_without_expiry(): void
    {
        $this->settings();
        $this->memberDoc($this->userB->id, 'id_card', null); // n'expire jamais

        $this->orchestrator()->run(['renew']);
        $this->assertSame(0, DocumentRequest::where('company_id', $this->company->id)->count());
    }

    public function test_no_renewal_for_far_future_expiry(): void
    {
        $this->settings();
        $this->memberDoc($this->userB->id, 'id_card', now()->addDays(120)); // hors fenêtre

        $this->orchestrator()->run(['renew']);
        $this->assertSame(0, DocumentRequest::where('company_id', $this->company->id)->count());
    }

    // ───────── Relances : tiers + idempotence ─────────

    public function test_reminder_fires_once_per_tier(): void
    {
        $this->settings(['expiration_before_days' => [7], 'expiration_at' => false, 'expiration_after_days' => [], 'request_due_before_days' => [], 'request_due_after_days' => []]);
        $doc = $this->memberDoc($this->userB->id, 'id_card', now()->addDays(5)); // dans la fenêtre J-7
        // Une demande active liée au document
        DocumentRequest::create([
            'company_id' => $this->company->id, 'document_subject_type' => 'user', 'document_subject_id' => $this->userB->id,
            'user_id' => $this->userB->id, 'document_type_id' => $doc->document_type_id,
            'status' => 'submitted', 'requires_validation' => true, 'requested_at' => now()->subDay(), 'submitted_at' => now(),
        ]);

        $this->orchestrator()->run(['remind']);
        $this->assertSame(1, DocumentRequestEvent::where('event', 'reminded')->count());

        // Rejouer l'orchestrateur → PAS de relance en double (dédup déterministe par événement)
        $this->orchestrator()->run(['remind']);
        $this->assertSame(1, DocumentRequestEvent::where('event', 'reminded')->count());
    }

    public function test_overdue_request_triggers_due_after_reminder(): void
    {
        $this->settings(['expiration_before_days' => [], 'expiration_at' => false, 'expiration_after_days' => [], 'request_due_before_days' => [], 'request_due_after_days' => [1]]);
        DocumentRequest::create([
            'company_id' => $this->company->id, 'document_subject_type' => 'user', 'document_subject_id' => $this->userB->id,
            'user_id' => $this->userB->id, 'document_type_id' => DocumentType::where('code', 'id_card')->first()->id,
            'status' => 'requested', 'requires_validation' => true, 'requested_at' => now()->subDays(10), 'due_date' => now()->subDays(3),
        ]);

        $this->orchestrator()->run(['remind']);
        $reminded = DocumentRequestEvent::where('event', 'reminded')->get();
        $this->assertCount(1, $reminded);
        $this->assertSame('due+1', $reminded->first()->metadata['tier']);
    }

    // ───────── Account-less : signal gestionnaire, sans compte artificiel ─────────

    public function test_accountless_renewal_signals_without_fake_user(): void
    {
        $emp = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => null,
            'first_name' => 'No', 'last_name' => 'Acc', 'status' => Employee::STATUS_ACTIVE,
            'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ]);
        $this->settings();
        // Document expirant rattaché au sujet employee (pas de user_id)
        $type = DocumentType::where('code', 'id_card')->firstOrFail();
        MemberDocument::create([
            'company_id' => $this->company->id, 'user_id' => null,
            'document_subject_type' => 'employee', 'document_subject_id' => $emp->id,
            'document_type_id' => $type->id, 'file_path' => 'x.pdf', 'file_name' => 'x.pdf',
            'file_size_bytes' => 100, 'mime_type' => 'application/pdf', 'file_sha256' => str_repeat('b', 64),
            'published_state' => 'published', 'uploaded_by' => $this->owner->id, 'expires_at' => now()->addDays(10),
        ]);

        $this->orchestrator()->run(['renew']);

        // Une demande account-less est ouverte, SANS user_id artificiel
        $req = DocumentRequest::where('document_subject_type', 'employee')->where('document_subject_id', $emp->id)->first();
        $this->assertNotNull($req);
        $this->assertNull($req->user_id);
    }

    // ───────── Lock : réexécution séquentielle sûre ─────────

    public function test_runs_are_safe_to_repeat(): void
    {
        $this->settings();
        $this->memberDoc($this->userB->id, 'id_card', now()->addDays(10));

        $r1 = $this->orchestrator()->run(['renew', 'remind']);
        $r2 = $this->orchestrator()->run(['renew', 'remind']);

        $this->assertArrayHasKey('renewed', $r1);
        $this->assertArrayHasKey('renewed', $r2);
        // Aucun doublon de demande malgré deux passages complets
        $this->assertSame(1, DocumentRequest::where('company_id', $this->company->id)->count());
    }
}
