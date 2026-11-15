<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\EmployeeFieldStore;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\FieldValue;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\Contributions\ModuleContributionCatalog;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Requirements\EffectiveRequirementResolver;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementLevel;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-795 (L5) — dynamic fields of an EMPLOYEE with or without account : ownership, isolation,
 * confidentiality, permissions, history, established contract, account created later, legacy values,
 * requirements coherence, DMS boundary — and no operational change.
 */
class Adr795EmployeeFieldsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const CODES = ['job_title', 'contract_type', 'work_mode', 'birth_date', 'iban', 'license_expiry', 'phone'];

    private Company $a;
    private Company $b;
    private User $owner;
    private User $hr;
    private User $hrSensitive;
    private Employee $accountless;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_leave']]);
        $this->a = $this->company('A');
        $this->b = $this->company('B');
        $admin = $this->setUpCompanyRbac($this->a);
        $this->owner = User::factory()->create();
        Membership::create(['user_id' => $this->owner->id, 'company_id' => $this->a->id, 'role' => 'owner', 'company_role_id' => $admin->id]);
        $this->hr = $this->member($this->a, ['workforce.view', 'workforce.manage']);
        $this->hrSensitive = $this->member($this->a, ['workforce.view', 'workforce.manage', 'workforce.sensitive_read']);
        $this->accountless = $this->employee($this->a);
    }

    private function company(string $name): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach (['workforce', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        foreach (FieldDefinition::whereNull('company_id')->whereIn('code', self::CODES)->get() as $i => $d) {
            FieldActivation::create(['company_id' => $c->id, 'field_definition_id' => $d->id, 'enabled' => true, 'required_override' => false, 'order' => $i]);
        }

        return $c;
    }

    private function member(Company $c, array $perms): User
    {
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function employee(Company $c, ?User $u = null, ?JobRole $job = null): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'user_id' => $u?->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => 'active', 'hire_date' => '2026-01-01', 'job_role_id' => $job?->id]);
    }

    private function api(User $u, string $method, Employee $e, array $body = [], ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id])->json($method, "/api/workforce/employees/{$e->id}/fields", $body);
    }

    private function field(array $fields, string $code): ?array
    {
        return collect($fields)->firstWhere('code', $code);
    }

    private function defId(string $code): int
    {
        return (int) FieldDefinition::whereNull('company_id')->where('code', $code)->value('id');
    }

    // 1, 9, 13, 15, 14 — salarié sans compte : saisie RH, typage, champ inconnu, historique
    public function test_hr_fills_fields_of_an_employee_without_account(): void
    {
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['job_title' => 'Chef d’équipe', 'contract_type' => 'CDI', 'birth_date' => '1990-04-02', 'work_mode' => 'on_site']])->assertOk()
            ->assertJsonPath('changed', ['job_title', 'contract_type', 'birth_date', 'work_mode']);
        $fields = $this->api($this->hr, 'GET', $this->accountless)->assertOk()->assertJsonPath('has_account', false)->json('fields');
        $this->assertSame('CDI', $this->field($fields, 'contract_type')['value']);
        $this->assertSame('filled', $this->field($fields, 'birth_date')['status']);
        $this->assertSame('person', $this->field($fields, 'birth_date')['owner'], 'personnel, stocké sur la fiche faute de compte');
        $this->assertSame('missing', $this->field($fields, 'license_expiry')['status']);
        $this->assertSame(4, FieldValue::where('model_type', 'employee')->where('model_id', $this->accountless->id)->count());
        $this->assertSame(0, FieldValue::where('model_type', 'user')->count(), 'aucune identité globale créée');

        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['contract_type' => 'Freelance']])->assertStatus(422)->assertJsonValidationErrors('fields.contract_type');
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['birth_date' => 'not-a-date']])->assertStatus(422);
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['unknown_code' => 'x']])->assertStatus(422)->assertJsonValidationErrors('fields.unknown_code');
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['contract_type' => 'CDD']])->assertOk();
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['contract_type' => 'CDD']])->assertOk()->assertJsonPath('changed', []); // idempotent

        $history = DB::table('field_value_changes')->where('model_type', 'employee')->where('model_id', $this->accountless->id)->where('field_definition_id', $this->defId('contract_type'))->orderBy('id')->get();
        $this->assertSame(['"CDI"', '"CDD"'], $history->pluck('new_value')->all());
        $this->assertSame($this->hr->id, (int) $history->last()->changed_by);

        // Valeur vidée ≠ absente : ligne conservée, statut « manquant ».
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['work_mode' => null]])->assertOk();
        $this->assertSame('missing', $this->field($this->api($this->hr, 'GET', $this->accountless)->json('fields'), 'work_mode')['status']);
        $this->assertTrue(FieldValue::where('model_type', 'employee')->where('model_id', $this->accountless->id)->where('field_definition_id', $this->defId('work_mode'))->exists());
    }

    // 5, 6, 7 — confidentialité et permissions
    public function test_sensitive_values_and_permissions(): void
    {
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['iban' => 'FR7630006000011234567890189']])->assertForbidden();
        $this->api($this->hrSensitive, 'PUT', $this->accountless, ['fields' => ['iban' => 'FR7630006000011234567890189']])->assertOk();
        $hidden = $this->field($this->api($this->hr, 'GET', $this->accountless)->assertOk()->json('fields'), 'iban');
        $this->assertNull($hidden['value']);
        $this->assertTrue($hidden['masked']);
        $this->assertFalse($hidden['editable']);
        $this->assertStringNotContainsString('0189', json_encode($this->api($this->hr, 'GET', $this->accountless)->json()), 'aucun fragment');
        $this->assertSame('FR7630006000011234567890189', $this->field($this->api($this->hrSensitive, 'GET', $this->accountless)->json('fields'), 'iban')['value']);
        $h = DB::table('field_value_changes')->where('field_definition_id', $this->defId('iban'))->first();
        $this->assertTrue((bool) $h->masked);
        $this->assertStringStartsWith('"sha256:', $h->new_value, 'historique sans valeur en clair');

        $viewer = $this->member($this->a, ['workforce.view']);
        $this->api($viewer, 'GET', $this->accountless)->assertForbidden(); // hors périmètre (ni RH ni manager)
        $this->api($viewer, 'PUT', $this->accountless, ['fields' => ['job_title' => 'X']])->assertForbidden();
        $nobody = $this->member($this->a, ['members.view']);
        $this->api($nobody, 'GET', $this->accountless)->assertForbidden();
    }

    // 3, 16 — multi-company et cross-tenant
    public function test_values_are_isolated_per_company_for_a_multi_company_person(): void
    {
        $person = User::factory()->create();
        $empA = $this->employee($this->a, $person);
        $empB = $this->employee($this->b, $person);
        $hrB = $this->member($this->b, ['workforce.view', 'workforce.manage']);
        $this->api($this->hr, 'PUT', $empA, ['fields' => ['job_title' => 'Livreur A', 'contract_type' => 'CDI']])->assertOk();
        $this->api($hrB, 'PUT', $empB, ['fields' => ['job_title' => 'Préparateur B'], ], $this->b)->assertOk();
        $this->assertSame('Livreur A', $this->field($this->api($this->hr, 'GET', $empA)->json('fields'), 'job_title')['value']);
        $this->assertSame('Préparateur B', $this->field($this->api($hrB, 'GET', $empB, [], $this->b)->json('fields'), 'job_title')['value']);
        $this->assertNull($this->field($this->api($hrB, 'GET', $empB, [], $this->b)->json('fields'), 'contract_type')['value'], 'jamais la valeur de A');

        $this->api($this->hr, 'GET', $empB)->assertNotFound(); // salarié de B avec l'en-tête de A
        $this->api($this->hr, 'PUT', $empB, ['fields' => ['job_title' => 'pirate']])->assertNotFound();
        $this->api($this->hr, 'GET', $empA, [], $this->b)->assertForbidden(); // pas membre de B
        $this->assertSame('Préparateur B', FieldValue::where('model_type', 'employee')->where('model_id', $empB->id)->value('value'));
    }

    // 2, 4, 10 — avec compte, owner salarié, compte créé ensuite (aucune perte, aucune seconde identité)
    public function test_account_created_later_keeps_values_and_personal_data_is_reconciled_not_overwritten(): void
    {
        $this->api($this->hrSensitive, 'PUT', $this->accountless, ['fields' => ['job_title' => 'Cariste', 'phone' => '0612345678', 'birth_date' => '1991-01-01']])->assertOk();
        $phone = FieldValue::where('model_type', 'employee')->where('model_id', $this->accountless->id)->where('field_definition_id', $this->defId('phone'))->value('value');
        $this->assertSame('+33612345678', $phone, 'normalisation téléphone réutilisée');

        $user = User::factory()->create();
        FieldValue::create(['field_definition_id' => $this->defId('birth_date'), 'model_type' => 'user', 'model_id' => $user->id, 'value' => '1991-02-02']);
        $this->accountless->update(['user_id' => $user->id]); // compte créé / rattaché plus tard
        $fields = $this->api($this->hr, 'GET', $this->accountless->fresh())->assertOk()->assertJsonPath('has_account', true)->json('fields');
        $this->assertSame('Cariste', $this->field($fields, 'job_title')['value'], 'champ d’emploi conservé');
        $birth = $this->field($fields, 'birth_date');
        $this->assertSame('1991-02-02', $birth['value'], 'le compte fait foi pour l’identité');
        $this->assertSame('needs_review', $birth['status'], 'écart signalé, rien écrasé');
        $this->assertTrue($birth['managed_by_account']);
        $this->assertSame('needs_review', $this->field($fields, 'phone')['status'], 'saisi avant le compte : à rapprocher');
        $this->assertSame('1991-01-01', FieldValue::where('model_type', 'employee')->where('model_id', $this->accountless->id)->where('field_definition_id', $this->defId('birth_date'))->value('value'));
        $this->api($this->hr, 'PUT', $this->accountless->fresh(), ['fields' => ['birth_date' => '1990-01-01']])->assertStatus(422)->assertJsonValidationErrors('fields.birth_date');
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('user_id', $user->id)->count(), 'une seule fiche RH');

        // Owner également salarié : même mécanique.
        $ownerEmp = $this->employee($this->a, $this->owner);
        $this->api($this->owner, 'PUT', $ownerEmp, ['fields' => ['job_title' => 'Gérant']])->assertOk();
        $this->assertSame('Gérant', $this->field($this->api($this->owner, 'GET', $ownerEmp)->json('fields'), 'job_title')['value']);
    }

    // 8 — contrat établi : confirmation explicite, contrat jamais modifié
    public function test_established_contract_requires_explicit_confirmation(): void
    {
        $contract = EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'employee_id' => $this->accountless->id,
            'contract_type' => 'cdi', 'work_model_key' => 'hourly', 'start_date' => '2026-01-01', 'status' => EmploymentContract::STATUS_ACTIVE, 'is_current' => true])->fresh();
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['contract_type' => 'CDI']])->assertStatus(422); // même une première saisie
        $this->assertSame(0, FieldValue::where('model_type', 'employee')->count());
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['job_title' => 'Responsable']])->assertStatus(422)->assertJsonValidationErrors('fields.job_title');
        $this->assertFalse(FieldValue::where('model_type', 'employee')->where('field_definition_id', $this->defId('job_title'))->exists(), 'rien écrit sans confirmation');
        $this->assertTrue($this->field($this->api($this->hr, 'GET', $this->accountless)->json('fields'), 'job_title')['contract_protected']);
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['phone' => '0611111111']])->assertOk(); // coordonnées : libres
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['job_title' => 'Responsable'], 'confirm_contractual' => true])->assertOk();
        $this->assertSame($contract->getAttributes(), $contract->fresh()->getAttributes(), 'contrat inchangé');
    }

    // 11, 12, 13 — changement de poste, départ, champ désactivé : valeurs conservées
    public function test_values_survive_position_change_departure_and_deactivation(): void
    {
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['job_title' => 'Livreur', 'work_mode' => 'remote']])->assertOk();
        $job = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Chef', 'level' => 'manager', 'source' => 'company']);
        $this->accountless->update(['job_role_id' => $job->id]);
        $this->accountless->update(['status' => 'terminated', 'termination_date' => '2026-06-30']);
        $this->assertSame('remote', $this->field($this->api($this->hr, 'GET', $this->accountless->fresh())->json('fields'), 'work_mode')['value']);

        FieldActivation::where('company_id', $this->a->id)->where('field_definition_id', $this->defId('work_mode'))->update(['enabled' => false]);
        $this->assertNull($this->field($this->api($this->hr, 'GET', $this->accountless->fresh())->json('fields'), 'work_mode'), 'désactivé : non affiché');
        $this->api($this->hr, 'PUT', $this->accountless->fresh(), ['fields' => ['work_mode' => 'hybrid']])->assertStatus(422);
        FieldActivation::where('company_id', $this->a->id)->where('field_definition_id', $this->defId('work_mode'))->update(['enabled' => true]);
        $this->assertSame('remote', $this->field($this->api($this->hr, 'GET', $this->accountless->fresh())->json('fields'), 'work_mode')['value'], 'valeur historique intacte');
    }

    // Transition : valeurs posées sur le compte avant L5
    public function test_legacy_account_values_are_reported_and_adopted_only_explicitly(): void
    {
        $person = User::factory()->create();
        $empA = $this->employee($this->a, $person);
        $this->employee($this->b, $person);
        FieldValue::create(['field_definition_id' => $this->defId('job_title'), 'model_type' => 'user', 'model_id' => $person->id, 'value' => 'Ancien titre']);
        $f = $this->field($this->api($this->hr, 'GET', $empA)->json('fields'), 'job_title');
        $this->assertSame(['Ancien titre', 'legacy_account', 'needs_review'], [$f['value'], $f['source'], $f['status']]);

        $this->artisan('fields:ownership-report')->expectsOutputToContain('ambiguous_multi_company')->assertSuccessful();
        $this->artisan('fields:ownership-report', ['--adopt' => 'job_title', '--employee' => $empA->id])->assertFailed(); // société obligatoire
        $this->artisan('fields:ownership-report', ['--adopt' => 'job_title', '--employee' => $empA->id, '--company' => [(string) $this->b->id], '--apply' => true])->assertFailed(); // jamais un salarié d'une autre société
        $this->artisan('fields:ownership-report', ['--adopt' => 'job_title', '--employee' => $empA->id, '--company' => [(string) $this->a->id]])->assertSuccessful(); // dry-run
        $this->assertSame(0, FieldValue::where('model_type', 'employee')->count());
        $this->artisan('fields:ownership-report', ['--adopt' => 'job_title', '--employee' => $empA->id, '--company' => [(string) $this->a->id], '--apply' => true])->assertSuccessful();
        $this->assertSame('Ancien titre', FieldValue::where('model_type', 'employee')->where('model_id', $empA->id)->value('value'));
        $this->assertTrue(FieldValue::where('model_type', 'user')->where('model_id', $person->id)->exists(), 'valeur du compte jamais supprimée');
        $this->assertSame('filled', $this->field($this->api($this->hr, 'GET', $empA)->json('fields'), 'job_title')['status']);
        $this->assertFalse((new EmployeeFieldStore($this->a))->adoptLegacy($empA->fresh(), 'job_title', null), 'jamais écrasée une seconde fois');
    }

    // 17, 18, 19 — requirements, N+1, aucune obligation opérationnelle
    public function test_presence_matches_requirements_without_n_plus_one_and_without_operational_change(): void
    {
        $before = json_encode([DocumentResolverService::requirementMap($this->a->id, null, 'FR'), DocumentRequest::count()]);
        Requirement::create(['company_id' => $this->a->id, 'source' => 'company', 'target_kind' => 'field', 'field_definition_id' => $this->defId('contract_type'),
            'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'status' => 'active']);
        $resolved = EffectiveRequirementResolver::forCompany($this->a)->resolveFor(EffectiveRequirementResolver::subjectFromEmployee($this->accountless, null));
        $this->assertTrue($resolved['field:'.$this->defId('contract_type')]['required']);
        $store = new EmployeeFieldStore($this->a);
        $this->assertSame('missing', $store->presence([$this->accountless])[$this->accountless->id][$this->defId('contract_type')]);
        $this->api($this->hr, 'PUT', $this->accountless, ['fields' => ['contract_type' => 'CDI']])->assertOk();
        $this->assertSame('filled', (new EmployeeFieldStore($this->a))->presence([$this->accountless])[$this->accountless->id][$this->defId('contract_type')]);
        FieldValue::where('model_type', 'employee')->update(['value' => json_encode('Freelance')]); // valeur devenue invalide (option retirée)
        $this->assertSame('invalid', (new EmployeeFieldStore($this->a))->presence([$this->accountless])[$this->accountless->id][$this->defId('contract_type')], 'présence ≠ validité');

        $count = function () {
            $employees = Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->get();
            DB::flushQueryLog();
            DB::enableQueryLog();
            (new EmployeeFieldStore($this->a))->presence($employees);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->employee($this->a, User::factory()->create()); // composition identique (avec et sans compte) avant la mesure
        $small = $count();
        for ($i = 0; $i < 40; $i++) {
            $this->employee($this->a, $i % 2 ? User::factory()->create() : null);
        }
        $this->assertSame($small, $count(), 'aucune requête par salarié');

        $this->assertSame($before, json_encode([DocumentResolverService::requirementMap($this->a->id, null, 'FR'), DocumentRequest::count()]), 'obligations opérationnelles inchangées');
        $user = User::factory()->create();
        $this->assertSame([], array_filter(FieldResolverService::resolve($user, FieldDefinition::SCOPE_COMPANY_USER, $this->b->id), fn ($f) => $f['value'] !== null), 'chemin historique (compte) inchangé');
    }

    // 20 — frontière DMS : une confidentialité déclarée mais non appliquée par le DMS est refusée
    public function test_module_document_declaring_unenforced_confidentiality_is_refused(): void
    {
        $doc = ['label' => 'Medical aptitude', 'translations' => ['fr' => 'Aptitude médicale', 'en' => 'Medical aptitude'], 'category' => 'health'];
        $manifest = fn ($c) => new ModuleManifest(...array_merge(get_object_vars(ModuleRegistry::definitions()['workforce']), ['contributions' => $c]));
        $report = ModuleContributionCatalog::sync(['workforce' => $manifest(['version' => 1, 'documents' => [
            ['ref' => 'medical_aptitude', 'definition' => $doc + ['confidentiality' => 'highly_confidential']],
            ['ref' => 'safety_card', 'definition' => ['label' => 'Safety card', 'translations' => ['fr' => 'Carte sécurité', 'en' => 'Safety card'], 'category' => 'training', 'confidentiality' => 'confidential']],
        ]])]);
        $out = collect($report['workforce'])->keyBy('ref');
        $this->assertSame(['collision', 'confidentiality_not_enforced'], [$out['medical_aptitude']['outcome'], $out['medical_aptitude']['detail']]);
        $this->assertFalse(DocumentType::where('code', 'medical_aptitude')->exists(), 'jamais créé avec une promesse non tenue');
        $this->assertSame('created', $out['safety_card']['outcome']);
        $this->assertSame('confidential', app(\App\Core\Documents\Services\DocumentAccessPolicy::class)->classificationFor('safety_card'), 'niveau effectif = politique DMS');
    }
}
