<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\ReadModels\DocumentComplianceReadModel;
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
 * ADR-757 (DMS M1) — socle documentaire exploitable : versioning non destructif tous canaux,
 * conformité consolidée (workflow inclus), confidentialité EFFECTIVE côté serveur, self-service
 * autonome, versions historiques protégées, isolation multi-tenant.
 */
class DmsM1FoundationsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;      // owner → toutes permissions
    private $memberB;         // membership cible
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->company = Company::create([
            'name' => 'M1 Co', 'slug' => 'm1-co',
            'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);

        $this->owner = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id,
        ]);

        $this->userB = User::factory()->create();
        $this->memberB = $this->company->memberships()->create([
            'user_id' => $this->userB->id, 'role' => 'user', 'company_role_id' => $adminRole->id,
        ]);

        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create([
                'company_id' => $this->company->id, 'document_type_id' => $type->id,
                'enabled' => true, 'order' => $i * 10,
            ]);
        }
    }

    // ───────────────────────── helpers ─────────────────────────

    private function makeUserWithPerms(array $permKeys, bool $administrative = false): User
    {
        $user = User::factory()->create();
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'role-'.$user->id,
            'name' => 'Role '.$user->id,
            'is_administrative' => $administrative,
        ]);
        $ids = CompanyPermission::whereIn('key', $permKeys)->pluck('id')->toArray();
        $role->permissions()->sync($ids);
        $this->company->memberships()->create([
            'user_id' => $user->id, 'role' => 'user', 'company_role_id' => $role->id,
        ]);

        return $user;
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function pdf(string $name, string $marker = 'X'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nM1[{$marker}]\n".str_repeat('x', 64)."\n%%EOF");
    }

    private function memberUri(string $code): string
    {
        return "/api/company/members/{$this->memberB->id}/documents/{$code}";
    }

    private function uploadForB(string $code, string $marker = 'A'): void
    {
        $this->as($this->owner)->postJson($this->memberUri($code), ['file' => $this->pdf("{$code}.pdf", $marker)])->assertOk();
    }

    // ───────────────────────── Confidentialité ─────────────────────────

    public function test_manager_with_view_only_cannot_download_others_highly_confidential(): void
    {
        $this->uploadForB('id_card'); // highly_confidential
        $manager = $this->makeUserWithPerms(['documents.view']);

        $this->as($manager)->getJson($this->memberUri('id_card').'/download')->assertForbidden();
    }

    public function test_manager_with_view_only_can_download_others_non_highly_confidential(): void
    {
        $this->uploadForB('driving_license'); // confidential, pas highly
        $manager = $this->makeUserWithPerms(['documents.view']);

        $this->as($manager)->getJson($this->memberUri('driving_license').'/download')->assertOk();
    }

    public function test_confidential_viewer_can_download_others_highly_confidential(): void
    {
        $this->uploadForB('id_card');
        $viewer = $this->makeUserWithPerms(['documents.view', 'documents.view_confidential'], administrative: true);

        $this->as($viewer)->getJson($this->memberUri('id_card').'/download')->assertOk();
    }

    public function test_admin_can_download_others_highly_confidential(): void
    {
        $this->uploadForB('id_card');
        $this->as($this->owner)->getJson($this->memberUri('id_card').'/download')->assertOk();
    }

    public function test_self_can_download_own_highly_confidential(): void
    {
        // Le membre dépose lui-même son id_card via le self-service, puis le télécharge.
        $this->as($this->userB)->postJson('/api/profile/documents/id_card', ['file' => $this->pdf('id.pdf')])->assertOk();
        $this->as($this->userB)->get('/api/profile/documents/id_card/download')->assertOk();
    }

    public function test_index_annotates_confidentiality_and_can_download(): void
    {
        $this->uploadForB('id_card');
        $this->uploadForB('driving_license');
        $manager = $this->makeUserWithPerms(['documents.view']);

        $docs = collect($this->as($manager)->getJson("/api/company/members/{$this->memberB->id}/documents")->json('documents'))
            ->keyBy('code');

        $this->assertTrue($docs['id_card']['is_highly_confidential']);
        $this->assertFalse($docs['id_card']['can_download']);        // highly conf d'autrui + view seul
        $this->assertFalse($docs['driving_license']['is_highly_confidential']);
        $this->assertTrue($docs['driving_license']['can_download']);
    }

    // ───────────────────────── Versions historiques protégées ─────────────────────────

    public function test_version_history_is_kept_and_gated(): void
    {
        $this->uploadForB('id_card', 'V1');
        $this->uploadForB('id_card', 'V2'); // remplacement → 1 version archivée

        // L'admin voit l'historique
        $versions = $this->as($this->owner)->getJson($this->memberUri('id_card').'/versions')->assertOk()->json('versions');
        $this->assertCount(1, $versions);
        $versionId = $versions[0]['id'];

        // Un manager view-seul ne peut PAS télécharger la version d'une pièce highly confidentielle
        $this->as($this->makeUserWithPerms(['documents.view']))
            ->getJson($this->memberUri('id_card')."/versions/{$versionId}/download")->assertForbidden();

        // L'admin peut
        $this->as($this->owner)->getJson($this->memberUri('id_card')."/versions/{$versionId}/download")->assertOk();
    }

    // ───────────────────────── Conformité consolidée (workflow inclus) ─────────────────────────

    public function test_pending_and_rejected_do_not_count_as_compliant(): void
    {
        // Rendre driving_license OBLIGATOIRE pour tous
        $type = DocumentType::where('code', 'driving_license')->firstOrFail();
        DocumentTypeActivation::where('company_id', $this->company->id)
            ->where('document_type_id', $type->id)
            ->update(['required_override' => true]);

        // Le membre dépose → DocumentRequest=submitted (en attente de validation)
        $this->uploadForB('driving_license');

        $summary = DocumentComplianceReadModel::forCompany($this->company->fresh())['summary'];
        $this->assertGreaterThanOrEqual(1, $summary['pending_validation']);
        // Le slot soumis ne compte PAS comme "valid"
        $pendingSlotCompliant = $summary['valid'] >= $summary['total']; // ne doit pas être "tout valide"
        $this->assertFalse($pendingSlotCompliant);

        // Refus → compte comme rejected, toujours non conforme
        DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->userB->id)
            ->where('document_type_id', $type->id)
            ->update(['status' => DocumentRequest::STATUS_REJECTED, 'reviewed_at' => now()]);

        $summary2 = DocumentComplianceReadModel::forCompany($this->company->fresh())['summary'];
        $this->assertGreaterThanOrEqual(1, $summary2['rejected']);
    }

    public function test_approved_and_valid_counts_as_compliant(): void
    {
        $type = DocumentType::where('code', 'driving_license')->firstOrFail();
        DocumentTypeActivation::where('company_id', $this->company->id)
            ->where('document_type_id', $type->id)
            ->update(['required_override' => true]);

        $this->uploadForB('driving_license');

        // Validation admin
        DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->userB->id)
            ->where('document_type_id', $type->id)
            ->update(['status' => DocumentRequest::STATUS_APPROVED, 'reviewed_at' => now()]);

        $byType = collect(DocumentComplianceReadModel::forCompany($this->company->fresh())['by_type'])
            ->keyBy('code');
        $this->assertSame(0, $byType['driving_license']['pending_validation']);
        $this->assertGreaterThanOrEqual(1, $byType['driving_license']['valid']);
    }

    // ───────────────────────── Self-service autonome ─────────────────────────

    public function test_self_service_works_without_members_or_workforce_view(): void
    {
        // Un collaborateur dont le rôle n'a AUCUNE permission documents/members/workforce
        $basic = $this->makeUserWithPerms([]);

        // Il consulte ses propres documents (route gated uniquement par le module)
        $this->as($basic)->getJson('/api/profile/documents')->assertOk();

        // Il dépose puis télécharge sa propre pièce
        $this->as($basic)->postJson('/api/profile/documents/id_card', ['file' => $this->pdf('id.pdf')])->assertOk();
        $this->as($basic)->get('/api/profile/documents/id_card/download')->assertOk();
    }

    // ───────────────────────── Multi-tenant ─────────────────────────

    public function test_other_company_actor_cannot_download(): void
    {
        $this->uploadForB('id_card');

        // Société + owner distincts
        $otherCompany = Company::create([
            'name' => 'Other', 'slug' => 'other-m1',
            'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($otherCompany);
        $otherRole = $this->setUpCompanyRbac($otherCompany);
        $outsider = User::factory()->create();
        $otherCompany->memberships()->create(['user_id' => $outsider->id, 'role' => 'owner', 'company_role_id' => $otherRole->id]);

        // Même avec le contexte de SA société, il ne peut atteindre le membre de l'autre
        $this->actingAs($outsider)->withHeaders(['X-Company-Id' => $otherCompany->id])
            ->getJson($this->memberUri('id_card').'/download')->assertNotFound();
    }
}
