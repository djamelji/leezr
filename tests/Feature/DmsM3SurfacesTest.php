<?php

namespace Tests\Feature;

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
 * ADR-760 (DMS M3) — surfaces : demande & aperçu account-less dans le centre, versions self-service.
 */
class DmsM3SurfacesTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->company = Company::create(['name' => 'M3S Co', 'slug' => 'm3s-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);

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
        return UploadedFile::fake()->createWithContent('d.pdf', "%PDF-1.4\nM3[{$m}]\n".str_repeat('x', 40)."\n%%EOF");
    }

    public function test_accountless_request_shows_person_label_and_preview_in_queue(): void
    {
        $emp = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => null,
            'first_name' => 'Sans', 'last_name' => 'Compte', 'status' => Employee::STATUS_ACTIVE,
            'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ]);

        // Demande via person_ref (centre) + dépôt admin
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => "employee-{$emp->id}", 'document_type_code' => 'id_card'])->assertCreated();
        $this->as($this->owner)->postJson("/api/company/people/employee-{$emp->id}/documents/id_card", ['file' => $this->pdf()])->assertOk();

        $queue = $this->as($this->owner)->getJson('/api/company/document-requests/queue')->assertOk()->json('queue');
        $row = collect($queue)->firstWhere('person_ref', "employee-{$emp->id}");
        $this->assertNotNull($row, 'La demande account-less apparaît dans le centre');
        $this->assertSame('Sans', $row['user']['first_name']);          // libellé personne (sans compte)
        $this->assertTrue($row['user']['account_less'] ?? false);
        $this->assertNotNull($row['upload']);                            // aperçu keyé par sujet
    }

    public function test_self_service_version_history(): void
    {
        $member = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $member->id, 'role' => 'user', 'company_role_id' => $this->setUpCompanyRbac($this->company)->id]);

        // Dépôt puis remplacement (contenu différent) → une version archivée
        $this->as($member)->postJson('/api/profile/documents/id_card', ['file' => $this->pdf('V1')])->assertOk();
        $this->as($member)->postJson('/api/profile/documents/id_card', ['file' => $this->pdf('V2')])->assertOk();

        $versions = $this->as($member)->getJson('/api/profile/documents/id_card/versions')->assertOk()->json('versions');
        $this->assertCount(1, $versions);

        // Téléchargement de la version archivée (self autorisé)
        $this->as($member)->get("/api/profile/documents/id_card/versions/{$versions[0]['id']}/download")->assertOk();
    }
}
