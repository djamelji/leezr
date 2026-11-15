<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\ReadModels\DocumentComplianceReadModel;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-790 (L0) — for one person, the documents required by the person views (fiche, dossier,
 * self-service) and by the company compliance (page, dashboard, home priorities) come from the SAME
 * decision (DocumentResolverService::requirementMap). Existing rules only : no new obligation,
 * the RBAC role is never turned into a job.
 */
class Adr790ComplianceConsistencyTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private CompanyRole $admin;
    private CompanyRole $driverRole;
    private CompanyRole $officeRole;
    private User $owner;
    private User $driver;
    private User $office;
    private User $userOnly;
    private Employee $accountless;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->company = $this->makeCompany('L0 Co');
        $this->admin = $this->setUpCompanyRbac($this->company);
        // Rôle « terrain » : le permis lui est exigé PAR SA CONFIGURATION DE RÔLE existante (doc_config).
        $this->driverRole = $this->role('driver', ['documents.view'], [['code' => 'driving_license', 'visible' => true, 'required' => true, 'order' => 5]]);
        // Rôle « bureau » : le permis est masqué (non obligatoire) par sa configuration.
        $this->officeRole = $this->role('office', [], [['code' => 'driving_license', 'visible' => false]]);

        $this->owner = $this->member($this->admin, 'owner');
        $this->driver = $this->member($this->driverRole);
        $this->office = $this->member($this->officeRole);
        $this->userOnly = $this->member($this->officeRole); // compte sans fiche salarié
        foreach ([$this->owner, $this->driver, $this->office] as $u) { // propriétaire ÉGALEMENT salarié
            Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'user_id' => $u->id, 'first_name' => 'E', 'last_name' => (string) $u->id, 'status' => 'active', 'hire_date' => '2026-01-01']);
        }
        $this->accountless = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'Sans', 'last_name' => 'Compte', 'status' => 'active', 'hire_date' => '2026-01-01']);
    }

    private function makeCompany(string $name): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => $type->id, 'enabled' => true, 'order' => $i * 10,
                'required_override' => $type->code === 'id_card']); // pièce d'identité exigée par la Company
        }

        return $c;
    }

    private function role(string $key, array $perms, ?array $docConfig): CompanyRole
    {
        $r = CompanyRole::create(['company_id' => $this->company->id, 'key' => $key, 'name' => $key, 'is_administrative' => false, 'doc_config' => $docConfig]);
        $r->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());

        return $r;
    }

    private function member(CompanyRole $role, string $base = 'user', ?Company $c = null): User
    {
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => ($c ?? $this->company)->id, 'role' => $base, 'company_role_id' => $role->id]);

        return $u;
    }

    /** Required company_user codes as the PERSON VIEW computes them (fiche / dossier / self-service). */
    private function personView(?User $user, ?string $roleKey): array
    {
        $docs = DocumentResolverService::resolve($user, $this->company->id, $roleKey, 'FR', DocumentType::SCOPE_COMPANY_USER);

        return collect($docs)->where('required', true)->pluck('code')->sort()->values()->all();
    }

    /** Required company_user codes for one subject as the COMPANY COMPLIANCE computes them. */
    private function complianceFor(string $subject): array
    {
        return collect(DocumentComplianceReadModel::slotsFor($this->company->fresh()))
            ->where('scope', DocumentType::SCOPE_COMPANY_USER)->where('subject', $subject)
            ->pluck('code')->sort()->values()->all();
    }

    /** Sujet canonique de la conformité (PersonPopulationResolver) : le compte quand il existe. */
    private function subjectOfUser(User $u): string
    {
        return 'user:'.$u->id;
    }

    private function byType(): array
    {
        return collect(DocumentComplianceReadModel::forCompany($this->company->fresh())['by_type'])->keyBy('code')->all();
    }

    // ── 1. Divergence (détectée sur le code précédent) ────────────────────────

    public function test_permit_required_for_the_driver_appears_in_company_compliance(): void
    {
        $this->assertContains('driving_license', $this->personView($this->driver, 'driver'));
        $byType = $this->byType();
        $this->assertArrayHasKey('driving_license', $byType, 'le permis exigé par la fiche du livreur doit figurer dans la conformité');
        $this->assertSame(1, $byType['driving_license']['total'], 'exigé pour le seul livreur, pas pour toute la population');
    }

    // ── 2-5. Cohérence personne par personne ──────────────────────────────────

    public function test_each_person_has_the_same_required_documents_in_every_view(): void
    {
        $cases = [
            'livreur' => [$this->driver, 'driver'],
            'administratif' => [$this->office, 'office'],
            'propriétaire salarié' => [$this->owner, 'admin'],
            'compte sans fiche' => [$this->userOnly, 'office'],
        ];
        foreach ($cases as $label => [$user, $roleKey]) {
            $this->assertSame($this->personView($user, $roleKey), $this->complianceFor($this->subjectOfUser($user)), $label);
        }
        // Salarié sans compte : aucune règle de rôle (aucun rôle), seulement les règles de la Company.
        $this->assertSame($this->personView(null, null), $this->complianceFor('employee:'.$this->accountless->id), 'sans compte');
        $this->assertSame(['id_card'], $this->complianceFor('employee:'.$this->accountless->id));
        $this->assertNotContains('driving_license', $this->complianceFor($this->subjectOfUser($this->office)), 'administratif : pas de permis');
    }

    public function test_role_is_never_turned_into_a_job_requirement(): void
    {
        // Sans configuration de rôle, le permis n'est exigé à personne (aucune déduction depuis le rôle).
        $this->driverRole->forceFill(['doc_config' => null])->save();
        $this->assertArrayNotHasKey('driving_license', $this->byType());
    }

    // ── 6-9. Statuts ──────────────────────────────────────────────────────────

    private function permitSlot(): array
    {
        return collect(DocumentComplianceReadModel::slotsFor($this->company->fresh()))
            ->where('code', 'driving_license')->where('subject', $this->subjectOfUser($this->driver))->first();
    }

    private function upload(?string $expires): MemberDocument
    {
        $type = DocumentType::where('code', 'driving_license')->firstOrFail();
        return MemberDocument::create(['company_id' => $this->company->id, 'user_id' => $this->driver->id, 'document_subject_type' => 'user', 'document_subject_id' => $this->driver->id,
            'document_type_id' => $type->id, 'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'file_size_bytes' => 10, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->owner->id, 'expires_at' => $expires]);
    }

    public function test_statuses_missing_valid_expired_and_renewal(): void
    {
        $this->assertSame('missing', $this->permitSlot()['compliance_status']);

        $doc = $this->upload(now()->addYear()->toDateString());
        $this->assertSame('valid', $this->permitSlot()['compliance_status']);

        $doc->forceFill(['expires_at' => now()->subDay()])->save();
        $this->assertSame('expired', $this->permitSlot()['compliance_status']);

        $type = DocumentType::where('code', 'driving_license')->firstOrFail();
        DocumentRequest::create(['company_id' => $this->company->id, 'document_subject_type' => 'user', 'document_subject_id' => $this->driver->id, 'user_id' => $this->driver->id,
            'document_type_id' => $type->id, 'status' => DocumentRequest::STATUS_REQUESTED, 'requires_validation' => true]);
        $this->assertNotSame('valid', $this->permitSlot()['compliance_status'], 'renouvellement en cours : jamais compté conforme');
    }

    // ── 10. Configuration Company personnalisée ───────────────────────────────

    public function test_company_customisation_applies_identically_everywhere(): void
    {
        $rib = DocumentType::where('code', 'rib')->firstOrFail();
        DocumentTypeActivation::where('company_id', $this->company->id)->where('document_type_id', $rib->id)->update(['required_override' => true]);
        foreach ([[$this->driver, 'driver'], [$this->office, 'office']] as [$u, $rk]) {
            $this->assertContains('rib', $this->complianceFor($this->subjectOfUser($u)));
            $this->assertSame($this->personView($u, $rk), $this->complianceFor($this->subjectOfUser($u)));
        }
    }

    // ── Pas de doublon, pas d'écriture ────────────────────────────────────────

    public function test_computing_compliance_creates_nothing(): void
    {
        $before = [DocumentRequest::count(), MemberDocument::count(), DocumentTypeActivation::count()];
        DocumentComplianceReadModel::forCompany($this->company->fresh());
        $this->assertSame($before, [DocumentRequest::count(), MemberDocument::count(), DocumentTypeActivation::count()]);
        $slots = collect(DocumentComplianceReadModel::slotsFor($this->company->fresh()))->map(fn ($s) => ($s['subject'] ?? 'company').'|'.$s['code']);
        $this->assertSame($slots->count(), $slots->unique()->count(), 'une exigence par personne et par document');
    }

    // ── 11-13. Confidentialité, Workforce, isolation ──────────────────────────

    public function test_collective_compliance_stays_reserved(): void
    {
        $plain = $this->member($this->role('plain', [], null));
        $this->actingAs($plain)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/company/documents/compliance')->assertForbidden();
        $this->actingAs($plain)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/profile/documents')->assertOk();
    }

    public function test_company_without_workforce_and_other_company_isolation(): void
    {
        $other = $this->makeCompany('Other');
        $otherUser = $this->member($this->setUpCompanyRbac($other), 'owner', $other);
        $subjects = collect(DocumentComplianceReadModel::slotsFor($this->company->fresh()))->pluck('subject')->unique();
        $this->assertFalse($subjects->contains('user:'.$otherUser->id), 'aucune personne d\'une autre société');
        // Société sans fiche salarié (sans Workforce) : la population = comptes ; mêmes règles.
        $this->assertSame(['id_card'], collect(DocumentComplianceReadModel::slotsFor($other->fresh()))->where('subject', 'user:'.$otherUser->id)->pluck('code')->values()->all());
    }
}
