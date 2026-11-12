<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentCampaign;
use App\Core\Documents\DocumentCampaignRecipient;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\ReadModels\DocumentComplianceReadModel;
use App\Core\Documents\Services\DocumentCampaignService;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-761 (DMS M4) — campagnes collectives : ciblage/dédup, snapshot, lancement fiable idempotent,
 * account-less, annulation, suivi ; + agrégat de conformité subject-driven (P0).
 */
class DmsM4CampaignTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->company = Company::create(['name' => 'M4 Co', 'slug' => 'm4-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);

        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create(['company_id' => $this->company->id, 'document_type_id' => $type->id, 'enabled' => true, 'order' => $i * 10]);
        }
    }

    private function service(): DocumentCampaignService
    {
        return app(DocumentCampaignService::class);
    }

    private function employee(?int $userId = null, ?int $deptId = null, string $status = 'active'): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $userId, 'department_id' => $deptId,
            'first_name' => 'E', 'last_name' => (string) \Illuminate\Support\Str::uuid(), 'status' => $status,
            'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ]);
    }

    private function member(): User
    {
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $this->setUpCompanyRbac($this->company)->id]);

        return $u;
    }

    // ───────── Ciblage + déduplication + account-less ─────────

    public function test_targeting_all_dedupes_and_counts_accountless(): void
    {
        $u1 = $this->member();
        $this->employee($u1->id);           // Employee lié à un compte → compté UNE fois
        $this->employee(null);              // Employee sans compte → compté
        $this->member();                    // User sans Employee → compté

        $preview = $this->service()->preview($this->company, ['mode' => 'all']);
        // owner + u1(+employee) + employee-sans-compte + autre user = 4 personnes distinctes
        $this->assertSame(4, $preview['total']);
        $this->assertSame(1, $preview['account_less']);
    }

    public function test_department_targeting(): void
    {
        $dept = Department::create(['company_id' => $this->company->id, 'name' => 'Ops']);
        $this->employee(null, $dept->id);
        $this->employee(null, $dept->id);
        $this->employee(null);              // autre département (null)

        $preview = $this->service()->preview($this->company, ['mode' => 'department', 'department_ids' => [$dept->id]]);
        $this->assertSame(2, $preview['total']);
    }

    // ───────── Snapshot + lancement fiable ─────────

    public function test_create_snapshots_and_launch_creates_individual_requests(): void
    {
        $this->employee(null);              // account-less
        $this->member();

        $campaign = $this->service()->create($this->company, $this->owner, 'id_card', ['mode' => 'all'], ['message' => 'Pièce requise', 'due_date' => now()->addDays(7)->toDateString()]);
        // Snapshot figé
        $this->assertSame($campaign->recipients_total, DocumentCampaignRecipient::where('document_campaign_id', $campaign->id)->count());

        $stats = $this->service()->launch($campaign);
        $campaign->refresh();
        $this->assertSame(DocumentCampaign::STATUS_ACTIVE, $campaign->status);
        $this->assertSame(0, $stats['remaining']);

        // Chaque destinataire a une demande individuelle réelle
        $this->assertSame($campaign->recipients_total, DocumentCampaignRecipient::where('document_campaign_id', $campaign->id)->where('state', 'created')->count());
        // L'account-less a une demande SANS user_id artificiel
        $emp = DocumentCampaignRecipient::where('document_campaign_id', $campaign->id)->where('document_subject_type', 'employee')->first();
        $req = DocumentRequest::find($emp->document_request_id);
        $this->assertNotNull($req);
        $this->assertNull($req->user_id);
    }

    public function test_launch_is_idempotent_and_resumable(): void
    {
        $this->member();
        $this->employee(null);
        $campaign = $this->service()->create($this->company, $this->owner, 'id_card', ['mode' => 'all']);

        $this->service()->launch($campaign);
        $countAfterFirst = DocumentRequest::where('company_id', $this->company->id)->count();

        // Relance → AUCUN doublon (demandes actives réutilisées)
        $this->service()->launch($campaign);
        $this->assertSame($countAfterFirst, DocumentRequest::where('company_id', $this->company->id)->count());
    }

    public function test_no_eligible_recipients(): void
    {
        $campaign = $this->service()->create($this->company, $this->owner, 'id_card', ['mode' => 'employees', 'employee_ids' => [999999]]);
        $this->assertSame(0, $campaign->recipients_total);
        $stats = $this->service()->launch($campaign);
        $this->assertSame(0, $stats['created']);
    }

    public function test_cancel_campaign_cancels_pending_requests(): void
    {
        $this->member();
        $campaign = $this->service()->create($this->company, $this->owner, 'id_card', ['mode' => 'all']);
        $this->service()->launch($campaign);

        $this->service()->cancel($campaign);
        $campaign->refresh();
        $this->assertSame(DocumentCampaign::STATUS_CANCELLED, $campaign->status);
        // Les demandes 'requested' sont annulées
        $this->assertSame(0, DocumentRequest::where('company_id', $this->company->id)->where('status', 'requested')->count());
    }

    public function test_large_population_launch_chunks(): void
    {
        for ($i = 0; $i < 250; $i++) {
            $this->employee(null);
        }
        $campaign = $this->service()->create($this->company, $this->owner, 'id_card', ['mode' => 'all']);
        $this->assertGreaterThanOrEqual(250, $campaign->recipients_total);

        $this->service()->launch($campaign);
        $this->assertSame($campaign->recipients_total, DocumentCampaignRecipient::where('document_campaign_id', $campaign->id)->where('state', 'created')->count());
    }

    // ───────── HTTP + isolation tenant ─────────

    public function test_http_create_and_track(): void
    {
        $this->member();
        $resp = $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/document-campaigns', ['document_type_code' => 'id_card', 'targeting' => ['mode' => 'all']])
            ->assertCreated();
        $id = $resp->json('campaign.id');

        // Le job sync a lancé la campagne
        $summary = $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson("/api/company/document-campaigns/{$id}")->assertOk()->json();
        $this->assertArrayHasKey('partition', $summary);
        $this->assertGreaterThanOrEqual(1, $summary['campaign']['recipients_total']);
    }

    public function test_tenant_isolation(): void
    {
        $campaign = $this->service()->create($this->company, $this->owner, 'id_card', ['mode' => 'all']);

        $other = Company::create(['name' => 'Other', 'slug' => 'other-m4', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($other);
        $otherRole = $this->setUpCompanyRbac($other);
        $outsider = User::factory()->create();
        $other->memberships()->create(['user_id' => $outsider->id, 'role' => 'owner', 'company_role_id' => $otherRole->id]);

        $this->actingAs($outsider)->withHeaders(['X-Company-Id' => $other->id])
            ->getJson("/api/company/document-campaigns/{$campaign->id}")->assertNotFound();
    }

    // ───────── P0 : conformité subject-driven compte les account-less ─────────

    public function test_compliance_counts_accountless_employees(): void
    {
        $type = DocumentType::where('code', 'driving_license')->firstOrFail();
        DocumentTypeActivation::where('company_id', $this->company->id)->where('document_type_id', $type->id)->update(['required_override' => true]);

        $this->employee(null);              // account-less, concerné par l'obligation
        $this->employee(null);

        $summary = DocumentComplianceReadModel::forCompany($this->company->fresh())['summary'];
        // Les 2 employés sans compte + l'owner comptent dans le dénominateur (slots driving_license)
        $this->assertGreaterThanOrEqual(3, $summary['total']);
        $this->assertGreaterThanOrEqual(2, $summary['missing']); // account-less sans pièce = manquant
    }
}
