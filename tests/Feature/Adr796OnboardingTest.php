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
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementLevel;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Onboarding\Http\OnboardingPortalController;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation;
use App\Modules\Workforce\Onboarding\Models\OnboardingValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-796 (L6) — onboarding file without account : HR prepares → candidate fills → deposits → submits →
 * HR validates ; link security, scope, staging, DMS reuse, no new obligation, no new right.
 */
class Adr796OnboardingTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const FIELDS = ['birth_date', 'nationality', 'iban', 'social_security_number', 'phone', 'job_title', 'gender'];

    private Company $a;
    private Company $b;
    private User $hr;
    private JobRole $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_leave']]);
        $this->a = $this->company('A');
        $this->b = $this->company('B');
        $this->setUpCompanyRbac($this->a);
        $this->hr = $this->member($this->a, ['members.view', 'members.manage', 'workforce.view', 'workforce.manage', 'workforce.sensitive_read', 'members.sensitive_read', 'documents.view', 'documents.manage']);
        $this->driver = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Livreur', 'level' => 'agent', 'source' => 'preset', 'preset_item_key' => 'driver']);
    }

    private function company(string $n): Company
    {
        $c = Company::create(['name' => "Société {$n}", 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        foreach (['workforce', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        foreach (FieldDefinition::whereNull('company_id')->whereIn('code', self::FIELDS)->get() as $i => $d) {
            FieldActivation::create(['company_id' => $c->id, 'field_definition_id' => $d->id, 'enabled' => true, 'required_override' => $d->code === 'birth_date', 'order' => $i]);
        }
        foreach (DocumentType::where('is_system', true)->get() as $i => $t) {
            DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => $t->id, 'enabled' => true, 'order' => $i, 'required_override' => $t->code === 'id_card']);
        }

        return $c;
    }

    private function member(Company $c, array $perms, ?User $u = null): User
    {
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        $u ??= User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function hrApi(string $method, string $uri, array $body = [], ?User $u = null, ?Company $c = null)
    {
        return $this->actingAs($u ?? $this->hr)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id])->json($method, '/api/workforce/onboarding-files'.$uri, $body);
    }

    private function items(): array
    {
        return [['kind' => 'field', 'code' => 'birth_date', 'required' => true], ['kind' => 'field', 'code' => 'nationality', 'required' => false],
            ['kind' => 'field', 'code' => 'iban', 'required' => false], ['kind' => 'document', 'code' => 'id_card', 'required' => true]];
    }

    private function invite(array $extra = []): array
    {
        $file = $this->hrApi('POST', '', $extra + ['first_name' => 'Camille', 'last_name' => 'Test', 'hire_date' => '2026-12-01', 'candidate_email' => 'candidate@example.test',
            'job_role_id' => $this->driver->id, 'items' => $this->items(), 'purpose' => 'hiring_onboarding', 'legal_basis' => 'contract_preparation',
            'notice_text' => 'Vos informations servent à préparer votre embauche.'])->assertCreated()->json();
        $url = $this->hrApi('POST', "/{$file['id']}/link", ['days' => 7])->assertOk()->json('url');

        return [$file['id'], substr($url, strpos($url, '#t=') + 3)];
    }

    /** A fresh client per candidate : never authenticated as a company user. */
    private function portal(string $method, string $uri, array $body = [], ?string $cookie = null)
    {
        $this->app['auth']->forgetGuards();
        $req = $this->withHeaders(['Accept' => 'application/json'])->withCredentials();
        $req->defaultCookies = [];
        $req->unencryptedCookies = [];
        if ($cookie) {
            $req = $req->withUnencryptedCookie(OnboardingPortalController::COOKIE, $cookie);
        }

        return $req->json($method, '/api/public/onboarding'.$uri, $body);
    }

    private function open(string $token): string
    {
        $res = $this->portal('POST', '/session', ['token' => $token])->assertOk();
        $this->assertSame('no-referrer', $res->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        return collect($res->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === OnboardingPortalController::COOKIE)->getValue();
    }

    private function code(int $invitationId): string
    {
        // Le code réel part par e-mail (Mail::fake) ; on fixe ici un code connu pour la session de test.
        $code = '123456';
        OnboardingInvitation::whereKey($invitationId)->update(['code_hash' => hash('sha256', $invitationId.'|'.$code), 'code_expires_at' => now()->addMinutes(5), 'code_attempts' => 0]);

        return $code;
    }

    // Parcours complet : RH prépare → candidat renseigne → dépose → soumet → RH valide (1, 3, 4, 15, 16, 17, 18, 19, 23, 26, 27, 28)
    public function test_full_journey_without_account(): void
    {
        $operationalBefore = json_encode([DocumentResolverService::requirementMap($this->a->id, null, 'FR'), Requirement::count()]);
        $usersBefore = User::count();
        $rolesBefore = DB::table('company_role_permission')->count();
        [$id, $token] = $this->invite();
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->count(), 'une fiche RH préparatoire');
        $this->assertSame('inactive', Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->value('status'));
        $this->assertNull(Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->value('user_id'), 'aucun compte créé');

        $cookie = $this->open($token);
        $view = $this->portal('GET', '', [], $cookie)->assertOk()->json();
        $this->assertSame('Société A', $view['company']['name']);
        $this->assertSame(['birth_date', 'id_card'], $view['missing']['required']);
        $this->assertArrayNotHasKey('employee_id', $view);
        $this->assertSame([], array_intersect(array_keys(\Illuminate\Support\Arr::dot($view)), ['id', 'employee_id', 'company_id', 'user_id', 'token']), 'aucun identifiant interne');
        $this->assertSame(['done' => 0, 'total' => 4], $view['progress']);

        // Brouillon, reprise, mauvais format, champ non demandé
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1995-04-03']], $cookie)->assertOk();
        $this->portal('PUT', '/values', ['values' => ['birth_date' => 'pas-une-date']], $cookie)->assertStatus(422);
        $this->portal('PUT', '/values', ['values' => ['job_title' => 'Directeur']], $cookie)->assertStatus(422);
        $this->portal('PUT', '/values', ['values' => ['iban' => 'FR7630006000011234567890189']], $cookie)->assertStatus(422); // sensible : vérification requise
        $cookie2 = $this->open($token); // reprise (nouvelle session)
        $this->assertSame('1995-04-03', collect($this->portal('GET', '', [], $cookie2)->json('fields'))->firstWhere('code', 'birth_date')['value']);

        // Code sur canal distinct, puis champ sensible
        $this->portal('POST', '/verify', ['code' => '000000'], $cookie2)->assertStatus(422);
        $code = $this->code($id);
        $this->portal('POST', '/verify', ['code' => $code], $cookie2)->assertOk()->assertJsonPath('verified', true);
        $this->portal('PUT', '/values', ['values' => ['iban' => 'FR7630006000011234567890189']], $cookie2)->assertOk();

        // Documents : non demandé, interdit, valide
        $this->portal('POST', '/documents/driving_license', ['files' => [UploadedFile::fake()->create('p.pdf', 50, 'application/pdf')]], $cookie2)->assertNotFound();
        $this->portal('POST', '/documents/id_card', ['files' => [UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')]], $cookie2)->assertStatus(422);
        $this->portal('POST', '/documents/id_card', ['files' => [UploadedFile::fake()->image('id.jpg')]], $cookie2)->assertOk()->assertJsonPath('missing.required', []);
        $this->portal('POST', '/documents/id_card', ['files' => [UploadedFile::fake()->image('id.jpg')]], $cookie2)->assertOk();
        $emp = Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->first();
        $this->assertSame(1, DocumentRequest::where('document_subject_type', 'employee')->where('document_subject_id', $emp->id)->count(), 'une seule demande DMS');
        $this->assertSame(DocumentRequest::STATUS_SUBMITTED, DocumentRequest::first()->status, 'déposé ≠ validé');

        // Soumission, double soumission, plus d'écriture
        $this->portal('POST', '/submit', [], $cookie2)->assertOk()->assertJsonPath('status', 'submitted');
        $this->portal('POST', '/submit', [], $cookie2)->assertOk()->assertJsonPath('status', 'submitted');
        $this->portal('PUT', '/values', ['values' => ['nationality' => 'FR']], $cookie2)->assertStatus(422);
        $this->assertSame(0, FieldValue::where('model_type', 'employee')->count(), 'rien n’est une donnée RH avant validation');

        // Validation RH : décision par valeur, provenance tracée
        $detail = $this->hrApi('GET', "/{$id}")->assertOk()->json();
        $this->assertSame('1995-04-03', collect($detail['fields'])->firstWhere('code', 'birth_date')['provided']);
        $this->hrApi('POST', "/{$id}/validate", ['decisions' => ['birth_date' => 'accept']])->assertStatus(422); // décision manquante (iban)
        $this->hrApi('POST', "/{$id}/validate", ['decisions' => ['birth_date' => 'accept', 'iban' => 'accept']])->assertOk()->assertJsonPath('file.status', 'validated');
        $this->assertSame('1995-04-03', FieldValue::where('model_type', 'employee')->where('model_id', $emp->id)->where('field_definition_id', FieldDefinition::where('code', 'birth_date')->value('id'))->value('value'));
        $this->assertSame(2, DB::table('field_value_changes')->where('source', 'onboarding')->where('model_id', $emp->id)->count());
        $this->portal('GET', '', [], $cookie2)->assertStatus(401); // dossier clos

        $this->assertSame($usersBefore, User::count(), 'aucun compte');
        $this->assertSame($rolesBefore, DB::table('company_role_permission')->count(), 'aucun droit');
        $this->assertSame($operationalBefore, json_encode([DocumentResolverService::requirementMap($this->a->id, null, 'FR'), Requirement::count()]), 'aucune obligation opérationnelle');
    }

    // 2, 5, 6, 7, 8, 11, 35 — sécurité des liens, permissions, cross-tenant, aucune fuite
    public function test_link_security_and_permissions(): void
    {
        $this->hrApi('GET', '', [], $this->member($this->a, ['members.view']))->assertForbidden();
        $this->hrApi('POST', '', ['first_name' => 'X', 'last_name' => 'Y', 'hire_date' => '2026-12-01', 'items' => $this->items(), 'purpose' => 'hiring_onboarding'], $this->member($this->a, ['workforce.view']))->assertForbidden();

        $draft = $this->hrApi('POST', '', ['first_name' => 'N', 'last_name' => 'D', 'hire_date' => '2026-12-01', 'items' => $this->items(), 'purpose' => 'hiring_onboarding'])->assertCreated()->json('id');
        $this->hrApi('POST', "/{$draft}/link")->assertStatus(422); // base juridique et information non déclarées

        [$id, $token] = $this->invite(['email' => 'camille@example.test']);
        $this->portal('POST', '/session', ['token' => str_repeat('a', 64)])->assertNotFound();
        $this->portal('POST', '/session', ['token' => 'x'])->assertNotFound();
        $this->portal('GET', '')->assertStatus(401);
        $this->portal('GET', '', [], str_repeat('b', 64))->assertStatus(401);

        // Un autre dossier (société B) ne se lit jamais avec ce lien.
        $hrB = $this->member($this->b, ['workforce.view', 'workforce.manage']);
        $this->hrApi('GET', "/{$id}", [], $hrB, $this->b)->assertNotFound();
        $cookie = $this->open($token);
        $json = json_encode($this->portal('GET', '', [], $cookie)->json());
        foreach (['Société B', 'token', 'company_id', 'employee_id', 'Livreur'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }

        // Module Workforce désactivé pour la société : le dossier n'est plus accessible.
        CompanyModuleActivationReason::where('company_id', $this->a->id)->where('module_key', 'workforce')->delete();
        $this->portal('GET', '', [], $cookie)->assertStatus(401);
        CompanyModuleActivationReason::create(['company_id' => $this->a->id, 'module_key' => 'workforce', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        $this->portal('GET', '', [], $cookie)->assertOk();

        // Expiré puis révoqué ; régénération : l'ancien lien meurt.
        OnboardingInvitation::whereKey($id)->update(['expires_at' => now()->subMinute()]);
        $this->portal('GET', '', [], $cookie)->assertStatus(401);
        $this->portal('POST', '/session', ['token' => $token])->assertNotFound();
        $newUrl = $this->hrApi('POST', "/{$id}/link", ['days' => 3])->assertOk()->json('url');
        $this->portal('POST', '/session', ['token' => $token])->assertNotFound();
        $this->open(substr($newUrl, strpos($newUrl, '#t=') + 3));
        $this->hrApi('POST', "/{$id}/revoke")->assertOk();
        $this->portal('POST', '/session', ['token' => substr($newUrl, strpos($newUrl, '#t=') + 3)])->assertNotFound();
        $this->assertNull(OnboardingInvitation::find($id)->token_hash);
        $this->assertStringNotContainsString($token, json_encode(DB::table('company_audit_logs')->get()), 'jamais le jeton dans l’audit');
    }

    // 11, 34 — un candidat ne lit ni ne dépose pour un autre ; aucun téléchargement public
    public function test_candidates_are_isolated_from_each_other(): void
    {
        [$id1, $t1] = $this->invite();
        [$id2, $t2] = $this->invite(['first_name' => 'Autre', 'email' => 'autre@example.test']);
        $c1 = $this->open($t1);
        $c2 = $this->open($t2);
        $this->portal('POST', '/verify', ['code' => $this->code($id1)], $c1)->assertOk(); // pièce d'identité : session vérifiée
        $this->portal('POST', '/documents/id_card', ['files' => [UploadedFile::fake()->image('a.jpg')]], $c1)->assertOk();
        $this->assertFalse(collect($this->portal('GET', '', [], $c2)->json('documents'))->firstWhere('code', 'id_card')['deposited'], 'pièce de l’autre invisible');
        $this->portal('GET', '/documents/id_card', [], $c2)->assertStatus(405); // aucun téléchargement exposé
        $this->assertSame(1, MemberDocument::count());
    }

    // 21 — évolution des exigences après l'envoi : le dossier ne change pas ; révision explicite versionnée
    public function test_requirements_change_after_invitation_and_explicit_revision(): void
    {
        [$id, $token] = $this->invite();
        Requirement::create(['company_id' => $this->a->id, 'source' => 'company', 'target_kind' => 'field', 'field_definition_id' => FieldDefinition::where('code', 'gender')->value('id'),
            'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'status' => 'active']);
        $cookie = $this->open($token);
        $this->assertNull(collect($this->portal('GET', '', [], $cookie)->json('fields'))->firstWhere('code', 'gender'), 'photographie inchangée');
        $proposal = collect($this->hrApi('GET', '/proposal?job_role_id='.$this->driver->id)->assertOk()->json('items'))->firstWhere('code', 'gender');
        $this->assertSame('configured', $proposal['sources'][0]['kind']);
        $this->hrApi('PUT', "/{$id}/items", ['items' => array_merge($this->items(), [['kind' => 'field', 'code' => 'gender', 'required' => true]])])->assertOk()->assertJsonPath('snapshot_version', 2);
        $this->assertNotNull(collect($this->portal('GET', '', [], $cookie)->json('fields'))->firstWhere('code', 'gender'));
        $this->assertCount(1, OnboardingInvitation::find($id)->snapshot_history, 'version précédente conservée');
        $this->hrApi('PUT', "/{$id}/items", ['items' => [['kind' => 'field', 'code' => 'job_title']]])->assertStatus(422); // donnée RH : jamais demandée au candidat
    }

    // 20, 22, 24, 25 — complément ; salarié avec compte multi-employeurs ; contrat établi
    public function test_existing_employee_with_account_and_established_contract(): void
    {
        $person = $this->member($this->a, []);
        $this->member($this->b, [], $person);
        $emp = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'user_id' => $person->id, 'first_name' => 'P', 'last_name' => 'Q', 'status' => 'active', 'hire_date' => '2026-01-01']);
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'employee_id' => $emp->id, 'contract_type' => 'cdi', 'work_model_key' => 'hourly', 'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);
        FieldValue::create(['field_definition_id' => FieldDefinition::where('code', 'birth_date')->value('id'), 'model_type' => 'user', 'model_id' => $person->id, 'value' => '1980-01-01']);

        [$id, $token] = $this->invite(['employee_id' => $emp->id, 'first_name' => null, 'last_name' => null, 'hire_date' => null]);
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->where('user_id', $person->id)->count(), 'aucune 2e fiche');
        $this->hrApi('POST', '', ['employee_id' => $emp->id, 'items' => $this->items(), 'purpose' => 'hiring_onboarding'])->assertStatus(422); // un seul dossier ouvert

        $cookie = $this->open($token);
        $this->portal('POST', '/documents/id_card', ['files' => [UploadedFile::fake()->image('id.jpg')]], $cookie)->assertStatus(422); // sans vérification
        $this->portal('POST', '/verify', ['code' => $this->code($id)], $cookie)->assertOk();
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1981-01-01']], $cookie)->assertOk();
        $this->portal('POST', '/documents/id_card', ['files' => [UploadedFile::fake()->image('id.jpg')]], $cookie)->assertOk();
        $this->portal('POST', '/submit', [], $cookie)->assertOk();
        $this->hrApi('POST', "/{$id}/request-changes", ['message' => 'Merci de vérifier votre date de naissance.'])->assertOk()->assertJsonPath('status', 'changes_requested');
        $this->assertSame('Merci de vérifier votre date de naissance.', $this->portal('GET', '', [], $cookie)->json('hr_message'));
        $this->portal('POST', '/submit', [], $cookie)->assertOk();

        $detail = $this->hrApi('GET', "/{$id}")->json('fields');
        $birth = collect($detail)->firstWhere('code', 'birth_date');
        $this->assertNull($birth['current'], 'ancienne valeur ambiguë (2 employeurs) : jamais montrée à A');
        $this->assertFalse($birth['conflict']);
        $this->hrApi('POST', "/{$id}/validate", ['decisions' => ['birth_date' => 'accept']])->assertStatus(422); // contrat établi : confirmation explicite
        $this->hrApi('POST', "/{$id}/validate", ['decisions' => ['birth_date' => 'accept'], 'confirm_contractual' => true])->assertOk();
        $this->assertSame('1981-01-01', FieldValue::where('model_type', 'user')->where('model_id', $person->id)->value('value'));
        $d = DB::table('person_field_disclosures')->where('user_id', $person->id)->where('company_id', $this->a->id)->first();
        $this->assertSame(['onboarding', 'hiring_onboarding', 'contract_preparation', $id], [$d->source, $d->purpose, $d->legal_basis, (int) $d->onboarding_invitation_id]);
        $this->assertFalse(DB::table('person_field_disclosures')->where('user_id', $person->id)->where('company_id', $this->b->id)->exists(), 'jamais divulgué à B');
    }

    // 33 — concurrence : un seul dossier ouvert par fiche
    public function test_one_open_file_per_person_and_retention(): void
    {
        $emp = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'C', 'last_name' => 'D', 'status' => 'inactive', 'hire_date' => '2026-12-01']);
        $this->hrApi('POST', '', ['employee_id' => $emp->id, 'items' => $this->items(), 'purpose' => 'hiring_onboarding'])->assertCreated();
        $this->hrApi('POST', '', ['employee_id' => $emp->id, 'items' => $this->items(), 'purpose' => 'hiring_onboarding'])->assertStatus(422);
        $this->assertSame(1, OnboardingInvitation::where('employee_id', $emp->id)->count());

        // Conservation : expirée → aucune suppression immédiate ; purge des valeurs en attente après le délai, auditée.
        [$id, $token] = $this->invite(['email' => 'r@example.test', 'retention_days' => 5]);
        $cookie = $this->open($token);
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1990-01-01']], $cookie)->assertOk();
        OnboardingInvitation::whereKey($id)->update(['expires_at' => now()->subDay()]);
        $this->artisan('onboarding:retention', ['--apply' => true])->assertSuccessful();
        $this->assertSame('expired', OnboardingInvitation::find($id)->status);
        $this->assertSame(1, OnboardingValue::where('onboarding_invitation_id', $id)->count(), 'jamais supprimé à l’expiration');
        OnboardingInvitation::whereKey($id)->update(['expires_at' => now()->subDays(6)]);
        $this->artisan('onboarding:retention')->assertSuccessful(); // simulation
        $this->assertSame(1, OnboardingValue::where('onboarding_invitation_id', $id)->count());
        $this->artisan('onboarding:retention', ['--apply' => true])->assertSuccessful();
        $this->assertSame(0, OnboardingValue::where('onboarding_invitation_id', $id)->count());
        $this->assertNotNull(OnboardingInvitation::find($id)->purged_at);
    }
}
