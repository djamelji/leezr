<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-759 (DMS M2) — cycle de demande documentaire : demande → dépôt → validation/refus →
 * traçabilité append-only → conformité exacte. Continuité user/employee, demandes account-less.
 */
class DmsM2FoundationsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;
    private $memberB;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->company = Company::create(['name' => 'M2 Co', 'slug' => 'm2-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);

        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);
        $this->userB = User::factory()->create();
        $this->memberB = $this->company->memberships()->create(['user_id' => $this->userB->id, 'role' => 'user', 'company_role_id' => $adminRole->id]);

        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create(['company_id' => $this->company->id, 'document_type_id' => $type->id, 'enabled' => true, 'order' => $i * 10]);
        }
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function pdf(string $m = 'A'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\nM2[{$m}]\n".str_repeat('x', 40)."\n%%EOF");
    }

    private function memberUpload(string $code, string $m = 'A'): void
    {
        $this->as($this->owner)->postJson("/api/company/members/{$this->memberB->id}/documents/{$code}", ['file' => $this->pdf($m)])->assertOk();
    }

    private function review(string $code, string $status, ?string $note = null): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->owner)->putJson("/api/company/members/{$this->memberB->id}/documents/{$code}/review", array_filter(['status' => $status, 'review_note' => $note]));
    }

    private function accountlessEmployee(): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => null,
            'first_name' => 'No', 'last_name' => 'Acc', 'status' => Employee::STATUS_ACTIVE,
            'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ]);
    }

    private function events(int $typeCode = null): \Illuminate\Support\Collection
    {
        return \App\Core\Documents\DocumentRequestEvent::where('company_id', $this->company->id)->get();
    }

    // ───────── Cycle complet : demande → dépôt → validation ─────────

    public function test_full_request_to_approval_lifecycle(): void
    {
        // 1. La Company demande une pièce (avec message + échéance)
        $this->as($this->owner)->postJson('/api/company/document-requests', [
            'user_id' => $this->userB->id, 'document_type_code' => 'id_card',
            'message' => 'Merci de fournir votre pièce', 'due_date' => now()->addDays(7)->toDateString(),
        ])->assertCreated();

        $req = DocumentRequest::where('company_id', $this->company->id)->where('document_type_id', DocumentType::where('code', 'id_card')->first()->id)->first();
        $this->assertSame('requested', $req->status);
        $this->assertSame('Merci de fournir votre pièce', $req->message);
        $this->assertDatabaseHas('document_request_events', ['document_request_id' => $req->id, 'event' => 'requested']);

        // 2. Le collaborateur la fournit (dépôt admin)
        $this->memberUpload('id_card');
        $req->refresh();
        $this->assertSame('submitted', $req->status);
        $this->assertDatabaseHas('document_request_events', ['document_request_id' => $req->id, 'event' => 'submitted']);

        // 3. La Company valide
        $this->review('id_card', 'approved')->assertOk()->assertJsonPath('review.status', 'approved');
        $req->refresh();
        $this->assertSame('approved', $req->status);
        $this->assertSame($this->owner->id, $req->reviewer_id);

        // Traçabilité : l'acceptation enregistre la référence de version validée (sha256).
        $approvedEvent = \App\Core\Documents\DocumentRequestEvent::where('document_request_id', $req->id)->where('event', 'approved')->first();
        $this->assertNotNull($approvedEvent);
        $this->assertNotNull($approvedEvent->metadata['validated_sha256'] ?? null);
    }

    public function test_reject_with_motif_preserves_history_and_allows_resubmission(): void
    {
        $this->memberUpload('id_card');
        $this->review('id_card', 'rejected', 'Photo illisible')->assertOk()->assertJsonPath('review.status', 'rejected');

        $req = DocumentRequest::where('company_id', $this->company->id)->first();
        $this->assertSame('rejected', $req->status);              // le refus RESTE l'état
        $this->assertSame('Photo illisible', $req->review_note);  // motif conservé
        $this->assertDatabaseHas('document_request_events', ['document_request_id' => $req->id, 'event' => 'rejected', 'note' => 'Photo illisible']);

        // Nouvelle soumission possible après refus
        $this->memberUpload('id_card', 'B');
        $req->refresh();
        $this->assertSame('submitted', $req->status);

        // Historique préservé : au moins submitted, rejected, submitted
        $events = \App\Core\Documents\DocumentRequestEvent::where('document_request_id', $req->id)->pluck('event')->all();
        $this->assertContains('rejected', $events);
        $this->assertSame(2, collect($events)->filter(fn ($e) => $e === 'submitted')->count());
    }

    public function test_replacement_after_approval_requires_new_validation(): void
    {
        $this->memberUpload('id_card');
        $this->review('id_card', 'approved')->assertOk();
        $req = DocumentRequest::where('company_id', $this->company->id)->first();
        $this->assertSame('approved', $req->status);

        // Remplacement → repasse en soumission (nouvelle validation nécessaire)
        $this->memberUpload('id_card', 'B');
        $req->refresh();
        $this->assertSame('submitted', $req->status);
    }

    public function test_cancel_records_event(): void
    {
        $this->as($this->owner)->postJson('/api/company/document-requests', ['user_id' => $this->userB->id, 'document_type_code' => 'id_card'])->assertCreated();
        $req = DocumentRequest::where('company_id', $this->company->id)->first();

        $this->as($this->owner)->putJson("/api/company/document-requests/{$req->id}/cancel")->assertOk();
        $req->refresh();
        $this->assertSame('cancelled', $req->status);
        $this->assertDatabaseHas('document_request_events', ['document_request_id' => $req->id, 'event' => 'cancelled']);
    }

    // ───────── Demande à un Employee SANS compte ─────────

    public function test_request_and_submit_for_accountless_employee(): void
    {
        $emp = $this->accountlessEmployee();

        // Demande via personRef employee (aucun compte)
        $this->as($this->owner)->postJson('/api/company/document-requests', [
            'person_ref' => "employee-{$emp->id}", 'document_type_code' => 'id_card', 'message' => 'Pièce requise',
        ])->assertCreated();

        $req = DocumentRequest::where('company_id', $this->company->id)
            ->where('document_subject_type', 'employee')->where('document_subject_id', $emp->id)->first();
        $this->assertNotNull($req);
        $this->assertNull($req->user_id);                  // aucun compte artificiel
        $this->assertSame('requested', $req->status);

        // Dépôt admin pour l'employé sans compte
        $this->as($this->owner)->postJson("/api/company/people/employee-{$emp->id}/documents/id_card", ['file' => $this->pdf()])->assertOk();
        $req->refresh();
        $this->assertSame('submitted', $req->status);
    }

    // ───────── Continuité : ajout de compte après dépôt ─────────

    public function test_document_continuity_when_account_added_after_deposit(): void
    {
        $emp = $this->accountlessEmployee();

        // Dépôt account-less (sujet employee)
        $this->as($this->owner)->postJson("/api/company/people/employee-{$emp->id}/documents/id_card", ['file' => $this->pdf()])->assertOk();

        // Ajout d'un compte à l'employé (lien EXPLICITE, aucun déplacement de fichier)
        $user = User::factory()->create();
        $emp->update(['user_id' => $user->id]);
        $this->company->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => $this->setUpCompanyRbac($this->company)->id]);

        // La fiche (sujet canonique = user) voit TOUJOURS le document déposé sous employee:{id}
        $docs = collect($this->as($this->owner)->getJson("/api/company/people/employee-{$emp->id}/documents")->json('documents'))->keyBy('code');
        $this->assertTrue($docs['id_card']['has_file'], 'Document account-less conservé après ajout du compte');

        // Le fichier n'a pas été déplacé : il reste rattaché au sujet employee
        $this->assertDatabaseHas('member_documents', ['document_subject_type' => 'employee', 'document_subject_id' => $emp->id]);
    }

    // ───────── Conformité exacte ─────────

    public function test_compliance_reflects_request_states(): void
    {
        $type = DocumentType::where('code', 'driving_license')->firstOrFail();
        DocumentTypeActivation::where('company_id', $this->company->id)->where('document_type_id', $type->id)->update(['required_override' => true]);

        $this->memberUpload('driving_license');                 // → submitted (en validation)
        $summary = \App\Core\Documents\ReadModels\DocumentComplianceReadModel::forCompany($this->company->fresh())['summary'];
        $this->assertGreaterThanOrEqual(1, $summary['pending_validation']);

        $this->review('driving_license', 'approved')->assertOk();
        $byType = collect(\App\Core\Documents\ReadModels\DocumentComplianceReadModel::forCompany($this->company->fresh())['by_type'])->keyBy('code');
        $this->assertGreaterThanOrEqual(1, $byType['driving_license']['valid']);
        $this->assertSame(0, $byType['driving_license']['pending_validation']);
    }

    public function test_overdue_request_flagged(): void
    {
        $this->as($this->owner)->postJson('/api/company/document-requests', [
            'user_id' => $this->userB->id, 'document_type_code' => 'id_card',
        ])->assertCreated();
        // Force une échéance passée
        DocumentRequest::where('company_id', $this->company->id)->update(['due_date' => now()->subDay()]);

        $docs = collect($this->as($this->owner)->getJson("/api/company/people/user-{$this->userB->id}/documents")->json('documents'))->keyBy('code');
        $this->assertTrue($docs['id_card']['is_overdue']);
    }

    // ───────── Isolation tenant ─────────

    public function test_tenant_isolation_on_requests(): void
    {
        $this->as($this->owner)->postJson('/api/company/document-requests', ['user_id' => $this->userB->id, 'document_type_code' => 'id_card'])->assertCreated();
        $req = DocumentRequest::where('company_id', $this->company->id)->first();

        $other = Company::create(['name' => 'Other', 'slug' => 'other-m2', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($other);
        $otherRole = $this->setUpCompanyRbac($other);
        $outsider = User::factory()->create();
        $other->memberships()->create(['user_id' => $outsider->id, 'role' => 'owner', 'company_role_id' => $otherRole->id]);

        // L'outsider ne peut annuler une demande d'une autre société
        $this->actingAs($outsider)->withHeaders(['X-Company-Id' => $other->id])
            ->putJson("/api/company/document-requests/{$req->id}/cancel")->assertNotFound();
    }
}
