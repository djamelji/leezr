<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\ReadModels\DocumentComplianceReadModel;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\Contributions\CompanyModuleContributions;
use App\Core\Modules\Contributions\ModuleContribution;
use App\Core\Modules\Contributions\ModuleContributionCatalog;
use App\Core\Modules\Contributions\ModuleContributionDecision;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Requirements\EffectiveRequirementResolver;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementLevel;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-794 (L4) — module contributions : catalogue (A), proposals (B), explicit company adoption (C,
 * prepared) — and NEVER the operational switch (D). Lifecycle, provenance, anti-duplicates,
 * customisation, deactivation / reactivation, isolation, RBAC, sensitive data, performance.
 */
class Adr794ModuleContributionsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $a;
    private Company $b;
    private User $owner;
    private JobRole $driverJob;
    private Employee $driverWithAccount;
    private Employee $driverAccountless;
    private Employee $noPosition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_leave', 'workforce_payroll']]);
        \App\Core\Jobdomains\Jobdomain::firstOrCreate(['key' => 'services'], ['label' => 'Services', 'default_modules' => []]);

        $this->a = $this->company('A', 'logistique');
        $this->b = $this->company('B', 'services');
        $admin = $this->setUpCompanyRbac($this->a);
        $this->owner = User::factory()->create();
        Membership::create(['user_id' => $this->owner->id, 'company_id' => $this->a->id, 'role' => 'owner', 'company_role_id' => $admin->id]);

        $this->driverJob = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Livreur', 'level' => 'agent', 'source' => 'preset', 'preset_item_key' => 'driver']);
        $this->driverWithAccount = $this->employee($this->a, $this->driverJob, User::factory()->create());
        $this->driverAccountless = $this->employee($this->a, $this->driverJob);
        $this->noPosition = $this->employee($this->a, null);
    }

    // ── Outils ───────────────────────────────────────────────────────────────

    private function company(string $name, string $jobdomain): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => $jobdomain, 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach ($jobdomain === 'logistique' ? ['workforce', 'workforce_leave', 'workforce_payroll'] : [] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        foreach (DocumentType::where('is_system', true)->get() as $i => $t) {
            DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => $t->id, 'enabled' => true, 'order' => $i, 'required_override' => $t->code === 'id_card']);
        }

        return $c;
    }

    private function employee(Company $c, ?JobRole $job, ?User $user = null): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'user_id' => $user?->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => 'active', 'hire_date' => '2026-01-01', 'job_role_id' => $job?->id]);
    }

    private function member(Company $c, array $perms, bool $admin = false): User
    {
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => $admin]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    /** A real module manifest whose contributions are replaced (same key, lifecycle, names). */
    private function manifest(string $key, array $contributions): ModuleManifest
    {
        return new ModuleManifest(...array_merge(get_object_vars(ModuleRegistry::definitions()[$key]), ['contributions' => $contributions]));
    }

    private function sync(array $byModule): array
    {
        $manifests = [];
        foreach ($byModule as $key => $contributions) {
            $manifests[$key] = $this->manifest($key, $contributions);
        }

        return ModuleContributionCatalog::sync($manifests);
    }

    private function reason(): array
    {
        return ['fr' => 'Justification.', 'en' => 'Justification.'];
    }

    private function workforce(int $version = 1, string $idLevel = 'configurable_required', array $extra = []): array
    {
        return ['version' => $version,
            'fields' => [['ref' => 'hire_date'], ['ref' => 'iban']],
            'documents' => [['ref' => 'id_card'], ['ref' => 'rib'], ['ref' => 'driving_license']],
            'requirements' => array_merge([
                ['target' => 'field:hire_date', 'level' => 'recommended', 'reason' => $this->reason()],
                ['target' => 'document:id_card', 'level' => $idLevel, 'stage' => 'hire', 'reason' => $this->reason()],
                ['target' => 'document:rib', 'level' => 'recommended', 'reason' => $this->reason()],
                ['target' => 'document:driving_license', 'level' => 'configurable_required', 'job_role_keys' => ['driver'], 'reason' => $this->reason()],
            ], $extra)];
    }

    private function payroll(int $version = 1): array
    {
        return ['version' => $version, 'fields' => [['ref' => 'iban']], 'documents' => [['ref' => 'rib']],
            'requirements' => [
                ['target' => 'field:iban', 'level' => 'configurable_required', 'reason' => $this->reason()],
                ['target' => 'document:rib', 'level' => 'configurable_required', 'reason' => $this->reason()],
            ]];
    }

    private function api(User $u, string $method, string $uri, array $body = [], ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id])->json($method, '/api/workforce/requirements'.$uri, $body);
    }

    private function module(string $key, ?User $u = null): array
    {
        return collect($this->api($u ?? $this->owner, 'GET', '/modules')->assertOk()->json('modules'))->firstWhere('module_key', $key);
    }

    private function proposal(string $module, string $ref): ?array
    {
        return collect($this->module($module)['proposals'])->firstWhere('ref', $ref);
    }

    private function decide(string $module, array $decisions, ?User $u = null, string $what = 'decisions')
    {
        return $this->api($u ?? $this->owner, 'POST', "/modules/{$module}/{$what}", ['decisions' => array_map(fn ($r, $d) => ['ref' => $r, 'decision' => $d], array_keys($decisions), $decisions)]);
    }

    private function resolved(Employee $e): array
    {
        return EffectiveRequirementResolver::forCompany($this->a->fresh())->resolveFor(EffectiveRequirementResolver::subjectFromEmployee($e->load('jobRole'), null));
    }

    private function docKey(string $code): string
    {
        return 'document:'.DocumentType::where('code', $code)->value('id');
    }

    private function fieldKey(string $code): string
    {
        return 'field:'.FieldDefinition::whereNull('company_id')->where('code', $code)->value('id');
    }

    private function operationalSnapshot(): string
    {
        return json_encode([
            array_map(fn ($e) => [$e['required'], $e['visible']], DocumentResolverService::requirementMap($this->a->id, null, 'FR')),
            DocumentComplianceReadModel::slotsFor($this->a->fresh()),
            DocumentRequest::count(),
            DocumentTypeActivation::where('company_id', $this->a->id)->get(['document_type_id', 'enabled', 'required_override'])->toArray(),
            FieldActivation::where('company_id', $this->a->id)->get(['field_definition_id', 'enabled', 'required_override'])->toArray(),
        ]);
    }

    // 1, 2, 6, 22 (schéma) — catalogue : création unique, collision, homonyme, règles opérationnelles refusées
    public function test_catalogue_contributions_create_once_detect_collisions_and_refuse_operational_rules(): void
    {
        $def = ['label' => 'Badge number', 'translations' => ['fr' => 'N° de badge', 'en' => 'Badge number'], 'type' => 'string', 'category' => 'hr', 'sensitive' => false];
        $doc = ['label' => 'Safety training', 'translations' => ['fr' => 'Formation sécurité', 'en' => 'Safety training'], 'category' => 'training', 'requires_expiration' => true, 'default_validity_months' => 24, 'renewable' => true];
        $report = $this->sync([
            'workforce' => ['version' => 1, 'fields' => [['ref' => 'badge_number', 'definition' => $def], ['ref' => 'badge_no', 'definition' => $def]], 'documents' => [['ref' => 'safety_training', 'definition' => $doc]]],
            'workforce_payroll' => ['version' => 1, 'fields' => [['ref' => 'badge_number', 'definition' => ['type' => 'date'] + $def], ['ref' => 'unknown_code']]],
        ]);
        $out = fn ($m, $ref) => collect($report[$m])->firstWhere('ref', $ref);
        $this->assertSame('created', $out('workforce', 'badge_number')['outcome']);
        $this->assertSame('created', $out('workforce', 'safety_training')['outcome']);
        $this->assertSame('homonym_of:badge_number', $out('workforce', 'badge_no')['detail'], 'même libellé, autre code : signalé, jamais fusionné');
        $this->assertSame('collision', $out('workforce_payroll', 'badge_number')['outcome'], 'même code, type incompatible');
        $this->assertSame('collision', $out('workforce_payroll', 'unknown_code')['outcome']);
        $this->assertSame(1, FieldDefinition::where('code', 'badge_number')->count(), 'une seule définition canonique');
        $this->assertSame(1, DocumentType::where('code', 'safety_training')->count());
        $this->assertTrue((bool) DocumentType::where('code', 'safety_training')->value('requires_expiration'));

        // Disponible au catalogue ≠ visible dans une fiche : aucune activation Company créée.
        $this->assertSame(0, FieldActivation::whereIn('field_definition_id', FieldDefinition::where('code', 'badge_number')->pluck('id'))->count());
        $this->assertSame(0, DocumentTypeActivation::whereIn('document_type_id', DocumentType::where('code', 'safety_training')->pluck('id'))->count());

        // Rejouer = aucun doublon, définition jamais modifiée.
        $label = FieldDefinition::where('code', 'badge_number')->value('label');
        $again = $this->sync(['workforce' => ['version' => 1, 'fields' => [['ref' => 'badge_number', 'definition' => $def], ['ref' => 'badge_no', 'definition' => $def]], 'documents' => [['ref' => 'safety_training', 'definition' => $doc]]]]);
        $this->assertSame(['unchanged'], array_values(array_unique(array_column($again['workforce'], 'outcome'))));
        $this->assertSame($label, FieldDefinition::where('code', 'badge_number')->value('label'));

        // Règles opérationnelles et niveau impératif refusés au manifeste.
        $bad = $this->sync(['workforce' => ['version' => 2, 'fields' => [['ref' => 'x_field', 'definition' => $def + ['required_by_modules' => ['workforce']]]],
            'documents' => [['ref' => 'id_card']], 'requirements' => [['target' => 'document:id_card', 'level' => RequirementLevel::IMPERATIVE, 'reason' => $this->reason()]]]]);
        $this->assertSame(['invalid'], array_values(array_unique(array_column($bad['workforce'], 'outcome'))));
        $this->assertSame(0, FieldDefinition::where('code', 'x_field')->count());
        $this->assertSame(0, Requirement::where('level', RequirementLevel::IMPERATIVE)->count());
    }

    // 3, 4, 25, 26, 27 — propositions jamais effectives avant adoption ; aperçu sans écriture ; aucun effet opérationnel
    public function test_proposals_never_apply_before_explicit_adoption_and_never_reach_operations(): void
    {
        $before = $this->operationalSnapshot();
        $this->sync(['workforce' => $this->workforce()]);
        $this->assertSame([], $this->resolved($this->driverWithAccount), 'une proposition n’est pas une exigence');

        $m = $this->module('workforce');
        $this->assertSame('active', $m['state']);
        $this->assertSame('optional', $this->proposal('workforce', 'field:hire_date@all')['status']);
        $this->assertSame('proposed_required', $this->proposal('workforce', 'document:id_card@all')['status']);

        $rows = Requirement::count();
        $decisions = DB::table('module_contribution_decisions')->count();
        $preview = $this->decide('workforce', ['document:id_card@all' => 'adopt', 'field:hire_date@all' => 'adopt'], null, 'preview')->assertOk()->json();
        $this->assertCount(2, $preview['changes']['adopted']);
        $this->assertSame(['total' => 3, 'accountless' => 2], $preview['people']['document:id_card@all']);
        $this->assertSame($rows, Requirement::count(), 'aperçu : rien écrit');
        $this->assertSame($decisions, DB::table('module_contribution_decisions')->count());

        $this->decide('workforce', ['document:id_card@all' => 'adopt', 'field:hire_date@all' => 'adopt'])->assertOk();
        $r = $this->resolved($this->noPosition);
        $this->assertTrue($r[$this->docKey('id_card')]['required']);
        $this->assertFalse($r[$this->fieldKey('hire_date')]['required'], 'facultatif = suggestion');
        $this->assertSame('module', $r[$this->docKey('id_card')]['retained']['source']);
        $this->assertSame('already_configured', $this->proposal('workforce', 'document:id_card@all')['status']);

        $this->assertSame($before, $this->operationalSnapshot(), 'fiches, conformité, demandes DMS et activations inchangées');
        $this->assertSame(0, DocumentRequest::count());
    }

    // 5, 21 — deux modules, même document : une définition, deux provenances, une seule exigence
    public function test_two_modules_proposing_the_same_document_merge_into_one_requirement(): void
    {
        $types = DocumentType::count();
        $this->sync(['workforce' => $this->workforce(), 'workforce_payroll' => $this->payroll()]);
        $this->assertSame($types, DocumentType::count(), 'aucun type dupliqué');
        $this->assertSame(2, ModuleContribution::where('kind', 'document')->where('ref', 'rib')->distinct('module_key')->count('module_key'));
        $rib = collect($this->module('workforce')['documents'])->firstWhere('code', 'rib');
        $this->assertSame(['Payroll'], array_map(fn ($n) => str_contains($n, 'Payroll') ? 'Payroll' : $n, $rib['shared_with']));
        $this->assertTrue($rib['sensitive'], 'RIB = pièce hautement confidentielle');

        $this->decide('workforce', ['document:rib@all' => 'adopt'])->assertOk();
        $this->decide('workforce_payroll', ['document:rib@all' => 'adopt', 'field:iban@all' => 'adopt'])->assertOk();
        $r = $this->resolved($this->noPosition)[$this->docKey('rib')];
        $this->assertTrue($r['required'], 'le plus fort l’emporte');
        $this->assertSame(['workforce', 'workforce_payroll'], collect($r['contributions'])->where('status', 'applicable')->pluck('source_ref')->sort()->values()->all());

        // Multi-company : rien chez B.
        $this->assertSame(0, Requirement::where('company_id', $this->b->id)->count());
        $this->assertSame(0, ModuleContributionDecision::where('company_id', $this->b->id)->count());
    }

    // 7 — l'entreprise possède déjà le champ / l'exigence
    public function test_existing_company_field_and_requirement_are_recognised(): void
    {
        $this->sync(['workforce' => $this->workforce()]);
        $hire = FieldDefinition::whereNull('company_id')->where('code', 'hire_date')->value('id');
        FieldActivation::create(['company_id' => $this->a->id, 'field_definition_id' => $hire, 'enabled' => true, 'required_override' => false, 'order' => 1]);
        $this->assertSame('already_available', collect($this->module('workforce')['fields'])->firstWhere('code', 'hire_date')['status']);

        $this->api($this->owner, 'POST', '', ['scope' => 'all', 'items' => [['kind' => 'document', 'code' => 'id_card', 'state' => 'required']]])->assertOk();
        $this->assertSame('already_configured', $this->proposal('workforce', 'document:id_card@all')['status']);
        $this->decide('workforce', ['document:id_card@all' => 'adopt'])->assertOk()->assertJsonCount(1, 'changes.skipped');
        $this->assertSame(0, Requirement::where('company_id', $this->a->id)->where('source', 'module')->count(), 'pas de doublon');
    }

    // 8, 9, 10 — personnalisation conservée ; nouvelle version détectée, différences, choix explicite tracé
    public function test_customisation_survives_module_updates_until_an_explicit_choice(): void
    {
        $this->sync(['workforce' => $this->workforce()]);
        $this->decide('workforce', ['document:id_card@all' => 'adopt', 'field:hire_date@all' => 'adopt'])->assertOk();
        // Personnalisation via la page ADR-792 : obligatoire → facultatif.
        $this->api($this->owner, 'POST', '', ['scope' => 'all', 'items' => [['kind' => 'document', 'code' => 'id_card', 'state' => 'optional']]])->assertOk();
        $custom = Requirement::where('company_id', $this->a->id)->where('source', 'company')->where('status', 'active')->first();
        $this->assertNotNull($custom->customized_at);
        $this->assertNotNull($custom->derived_from_id, 'origine conservée');
        $state = collect($this->api($this->owner, 'GET', '?scope=all')->json('state.documents'))->firstWhere('code', 'id_card');
        $this->assertSame('module', $state['origin']['proposed_by']['kind']);

        // Manifeste modifié SANS nouvelle version : refusé.
        $report = $this->sync(['workforce' => $this->workforce(1, 'default')]);
        $this->assertSame('version_not_bumped', collect($report['workforce'])->firstWhere('ref', 'document:id_card@all')['outcome']);

        // v2 : nouvelle proposition → personnalisation intacte, choix demandé.
        $this->sync(['workforce' => $this->workforce(2, 'default')]);
        $p = $this->proposal('workforce', 'document:id_card@all');
        $this->assertSame('requires_confirmation', $p['status']);
        $this->assertTrue($p['customized']);
        $this->assertSame(['from' => 1, 'to' => 2, 'level_now' => 'recommended', 'level_proposed' => 'default'], $p['update']);
        $this->assertSame('recommended', $custom->fresh()->level, 'jamais réinitialisée silencieusement');
        $this->assertSame('already_configured', $this->proposal('workforce', 'field:hire_date@all')['status'], 'élément inchangé : pas de bruit');

        $this->decide('workforce', ['document:id_card@all' => 'keep'])->assertOk()->assertJsonCount(1, 'changes.kept');
        $this->assertSame('already_configured', $this->proposal('workforce', 'document:id_card@all')['status']);
        $this->assertSame('active', $custom->fresh()->status);

        // v3 : on remplace explicitement la personnalisation.
        $this->sync(['workforce' => $this->workforce(3, 'configurable_required')]);
        $this->assertSame('requires_confirmation', $this->proposal('workforce', 'document:id_card@all')['status']);
        $this->decide('workforce', ['document:id_card@all' => 'replace'])->assertOk()->assertJsonCount(1, 'changes.replaced');
        $this->assertSame('superseded', $custom->fresh()->status, 'historique conservé');
        $copy = Requirement::where('company_id', $this->a->id)->where('source', 'module')->where('status', 'active')->where('document_type_id', DocumentType::where('code', 'id_card')->value('id'))->first();
        $this->assertSame(3, $copy->source_version);
        $this->assertSame(['adopted', 'kept_customization', 'replaced_customization'], ModuleContributionDecision::where('company_id', $this->a->id)->where('ref', 'document:id_card@all')->orderBy('id')->pluck('decision')->all());
        $this->assertGreaterThan(0, \App\Core\Audit\CompanyAuditLog::where('company_id', $this->a->id)->where('action', 'module_contributions.decided')->count());
    }

    // 11, 12, 13 (partagé) — désactivation : rien supprimé, seules les exigences propres au module suspendues ; réactivation sans doublon
    public function test_deactivation_suspends_only_module_dependent_requirements_and_reactivation_restores_them(): void
    {
        $this->sync(['workforce' => $this->workforce(), 'workforce_payroll' => $this->payroll()]);
        $this->decide('workforce', ['document:rib@all' => 'adopt', 'document:id_card@all' => 'adopt'])->assertOk();
        $this->decide('workforce_payroll', ['document:rib@all' => 'adopt', 'field:iban@all' => 'adopt'])->assertOk();
        $iban = FieldDefinition::whereNull('company_id')->where('code', 'iban')->value('id');
        FieldValue::create(['field_definition_id' => $iban, 'model_type' => (new User)->getMorphClass(), 'model_id' => $this->driverWithAccount->user_id, 'value' => 'FR7630006000011234567890189']);
        $counts = fn () => [Requirement::count(), FieldValue::count(), DocumentRequest::count(), DB::table('member_documents')->count()];
        $before = $counts();

        CompanyModuleActivationReason::where('company_id', $this->a->id)->where('module_key', 'workforce_payroll')->delete(); // désactivation
        CompanyModule::withoutGlobalScopes()->where('company_id', $this->a->id)->where('module_key', 'workforce_payroll')->update(['is_enabled_for_company' => false]);
        $r = $this->resolved($this->noPosition);
        $this->assertFalse(isset($r[$this->fieldKey('iban')]) && $r[$this->fieldKey('iban')]['required'], 'exigence propre à la paie suspendue');
        $rib = collect($r[$this->docKey('rib')]['contributions'])->pluck('status', 'source_ref')->all();
        $this->assertSame(['workforce' => 'applicable', 'workforce_payroll' => 'module_inactive'], $rib, 'RIB toujours demandé par Workforce (exigence partagée), la contribution Paie suspendue');
        $this->assertSame(RequirementLevel::RECOMMENDED, $r[$this->docKey('rib')]['level']);
        $m = $this->module('workforce_payroll');
        $this->assertSame('deactivated', $m['state']);
        $this->assertFalse($m['adoptable']);
        $this->assertSame('suspended', collect($m['suspended_now'])->firstWhere('kind', 'field')['outcome']);
        $this->assertSame('kept_other_source', collect($m['suspended_now'])->firstWhere('kind', 'document')['outcome']);
        $this->assertSame($before, $counts(), 'aucune exigence, valeur, demande ni document supprimé');
        $this->decide('workforce_payroll', ['field:iban@all' => 'adopt'])->assertStatus(422);

        CompanyModuleActivationReason::create(['company_id' => $this->a->id, 'module_key' => 'workforce_payroll', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]); // réactivation
        $this->assertTrue($this->resolved($this->noPosition)[$this->fieldKey('iban')]['required'], 'reconnue à la réactivation');
        $this->assertSame('already_configured', $this->proposal('workforce_payroll', 'field:iban@all')['status']);
        $this->decide('workforce_payroll', ['field:iban@all' => 'adopt'])->assertOk()->assertJsonCount(1, 'changes.skipped');
        $this->assertSame($before, $counts(), 'aucun doublon');
    }

    // 13, 14, 15, ambigu — cycle de vie et refus d'adoption
    public function test_lifecycle_states_and_adoption_only_when_truly_active(): void
    {
        $this->sync(['workforce_payroll' => $this->payroll()]);
        $state = fn () => CompanyModuleContributions::lifecycle($this->a->fresh(), 'workforce_payroll');
        $this->assertSame('active', $state());

        // Souscrit (droit réel) mais non activé.
        CompanyModuleActivationReason::where('company_id', $this->a->id)->where('module_key', 'workforce_payroll')->delete();
        CompanyModule::withoutGlobalScopes()->where('company_id', $this->a->id)->where('module_key', 'workforce_payroll')->update(['is_enabled_for_company' => false]);
        $this->assertSame('available', $state());
        // Drapeau historique sans raison d'activation : ambigu, jamais d'obligation.
        CompanyModule::withoutGlobalScopes()->where('company_id', $this->a->id)->where('module_key', 'workforce_payroll')->update(['is_enabled_for_company' => true]);
        $this->assertSame('ambiguous', $state());
        $this->assertSame('not_applicable', $this->proposal('workforce_payroll', 'field:iban@all')['status']);
        $this->decide('workforce_payroll', ['field:iban@all' => 'adopt'])->assertStatus(422);

        // Suspendu : abonnement add-on désactivé.
        CompanyModuleActivationReason::create(['company_id' => $this->a->id, 'module_key' => 'workforce_payroll', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_leave']]);
        PlatformModule::where('key', 'workforce_payroll')->update(['addon_pricing' => ['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 10]]]);
        CompanyAddonSubscription::create(['company_id' => $this->a->id, 'module_key' => 'workforce_payroll', 'interval' => 'monthly', 'amount_cents' => 1000, 'currency' => 'EUR', 'activated_at' => now()->subMonth(), 'deactivated_at' => now()->subDay()]);
        $this->assertSame('suspended', $state());
        $this->decide('workforce_payroll', ['field:iban@all' => 'adopt'])->assertStatus(422);

        // Non autorisé : autre JobDomain sans droit ni activation ; activé sans droit = ambigu.
        PlatformModule::where('key', 'workforce_payroll')->update(['addon_pricing' => null]);
        $this->assertSame('not_authorized', CompanyModuleContributions::lifecycle($this->b, 'workforce_payroll'));
        CompanyModuleActivationReason::create(['company_id' => $this->b->id, 'module_key' => 'workforce_payroll', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        $this->assertSame('ambiguous', CompanyModuleContributions::lifecycle($this->b, 'workforce_payroll'), 'activation sans droit réel');
        $this->assertSame(0, Requirement::whereNotNull('company_id')->count(), 'aucune obligation introduite');
    }

    // 16, 17, 18, 19 — population : avec / sans compte, avec / sans poste
    public function test_position_scoped_proposal_covers_people_with_or_without_account_and_never_people_without_position(): void
    {
        $this->sync(['workforce' => $this->workforce()]);
        $preview = $this->decide('workforce', ['document:driving_license@'.md5(json_encode(['job_role_keys' => ['driver']])) => 'adopt'], null, 'preview')->assertOk()->json('people');
        $this->assertSame(['total' => 2, 'accountless' => 1], array_values($preview)[0]);
        $ref = $this->module('workforce')['proposals'];
        $driving = collect($ref)->first(fn ($p) => $p['scope']['positions'] === ['Livreur']);
        $this->assertSame('proposed_required', $driving['status']);
        $this->decide('workforce', [$driving['ref'] => 'adopt'])->assertOk();

        $this->assertTrue($this->resolved($this->driverWithAccount)[$this->docKey('driving_license')]['required']);
        $this->assertTrue($this->resolved($this->driverAccountless)[$this->docKey('driving_license')]['required'], 'sans compte : même exigence');
        $this->assertArrayNotHasKey($this->docKey('driving_license'), $this->resolved($this->noPosition), 'sans poste : jamais déduit');
        $copy = Requirement::where('company_id', $this->a->id)->where('source', 'module')->first();
        $this->assertSame(['job_role_ids' => [$this->driverJob->id]], $copy->applicability, 'une copie par poste, modifiable en L2');

        // Poste absent d'une autre entreprise : non applicable (B n'a aucun poste « driver »).
        $this->assertSame('not_applicable', collect((new CompanyModuleContributions($this->b))->overview())->firstWhere('module_key', 'workforce')['proposals'][3]['status']);
    }

    // 22 — impératif vérifié : jamais modifiable, jamais issu d'un module
    public function test_verified_imperative_stays_locked_and_module_copy_merges_under_it(): void
    {
        $this->sync(['workforce' => $this->workforce()]);
        Requirement::create(['company_id' => null, 'source' => 'platform', 'target_kind' => 'document', 'document_type_id' => DocumentType::where('code', 'id_card')->value('id'),
            'level' => RequirementLevel::IMPERATIVE, 'legal_reference' => 'Code du travail L.1221-13', 'verified_at' => now(), 'status' => 'active']);
        $this->decide('workforce', ['document:id_card@all' => 'adopt'])->assertOk();
        $r = $this->resolved($this->noPosition)[$this->docKey('id_card')];
        $this->assertSame(RequirementLevel::IMPERATIVE, $r['level']);
        $this->assertSame('platform', $r['retained']['source']);
        $this->api($this->owner, 'POST', '', ['scope' => 'all', 'items' => [['kind' => 'document', 'code' => 'id_card', 'state' => 'removed']]])->assertStatus(422);
        $state = collect($this->api($this->owner, 'GET', '?scope=all')->json('state.documents'))->firstWhere('code', 'id_card');
        $this->assertTrue($state['locked']);
        $this->assertTrue($state['origin']['verified']);
    }

    // 23 — réapplication idempotente
    public function test_repeated_adoption_and_sync_are_idempotent(): void
    {
        $this->sync(['workforce' => $this->workforce()]);
        $this->sync(['workforce' => $this->workforce()]);
        $this->assertSame(4, Requirement::where('is_template', true)->where('status', 'active')->count());
        $this->decide('workforce', ['document:id_card@all' => 'adopt'])->assertOk();
        $rows = Requirement::count();
        $this->decide('workforce', ['document:id_card@all' => 'adopt'])->assertOk()->assertJsonCount(1, 'changes.skipped');
        $this->assertSame($rows, Requirement::count());
        $this->decide('workforce', ['field:hire_date@all' => 'decline'])->assertOk();
        $this->assertSame('declined', $this->proposal('workforce', 'field:hire_date@all')['previous_decision']);
        $this->assertSame('optional', $this->proposal('workforce', 'field:hire_date@all')['status'], 'refus tracé, toujours adoptable plus tard');
    }

    // 15, 21, 28 + RBAC — isolation, permissions par section, aucune valeur exposée
    public function test_isolation_permissions_and_no_sensitive_value_leak(): void
    {
        $this->sync(['workforce' => $this->workforce(), 'workforce_payroll' => $this->payroll()]);
        $iban = FieldDefinition::whereNull('company_id')->where('code', 'iban')->value('id');
        FieldValue::create(['field_definition_id' => $iban, 'model_type' => (new User)->getMorphClass(), 'model_id' => $this->driverWithAccount->user_id, 'value' => 'FR7630006000011234567890189']);
        $json = json_encode($this->api($this->owner, 'GET', '/modules')->assertOk()->json());
        $this->assertStringNotContainsString('FR7630006000011234567890189', $json, 'catalogue ≠ valeurs');
        $this->assertTrue(collect($this->module('workforce_payroll')['fields'])->firstWhere('code', 'iban')['sensitive']);
        $this->assertStringNotContainsString('"payload"', $json);
        $this->assertStringNotContainsString('fingerprint', $json);

        $docs = $this->member($this->a, ['documents.configure', 'documents.view'], true);
        $this->decide('workforce_payroll', ['field:iban@all' => 'adopt'], $docs)->assertForbidden();
        $this->decide('workforce_payroll', ['document:rib@all' => 'adopt'], $docs)->assertOk();
        $nobody = $this->member($this->a, ['members.view']);
        $this->api($nobody, 'GET', '/modules')->assertForbidden();

        // Utilisateur d'une autre société : jamais de lecture ni d'écriture chez A.
        $outsider = $this->member($this->b, ['settings.manage', 'documents.configure'], true);
        $this->api($outsider, 'GET', '/modules', [], $this->a)->assertForbidden();
        $this->assertSame(0, Requirement::where('company_id', $this->b->id)->count());
        $this->assertSame('not_authorized', collect((new CompanyModuleContributions($this->b))->overview())->firstWhere('module_key', 'workforce_payroll')['state'], 'B (autre JobDomain) : module non autorisé');
    }

    // 14 (mesure) — coût de l'aperçu indépendant du nombre de salariés (pas de N+1)
    public function test_overview_query_count_does_not_grow_with_employees(): void
    {
        $this->sync(['workforce' => $this->workforce(), 'workforce_payroll' => $this->payroll()]);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            (new CompanyModuleContributions($this->a->fresh(), $this->owner))->overview();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $small = $count();
        for ($i = 0; $i < 60; $i++) {
            $this->employee($this->a, $i % 2 ? $this->driverJob : null, $i % 3 ? null : User::factory()->create());
        }
        $this->assertSame($small, $count(), 'aucune requête par salarié');
        $this->assertLessThan(60, $small);
    }
}
