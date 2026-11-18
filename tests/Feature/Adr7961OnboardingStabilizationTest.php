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
use App\Core\Documents\Scanning\SimulatedScanner;
use App\Core\Documents\Services\PersonPopulationResolver;
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
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Modules\Core\People\ReadModels\PeopleListReadModel;
use App\Modules\Workforce\Onboarding\Http\OnboardingPortalController;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation as Inv;
use App\Modules\Workforce\Onboarding\Models\OnboardingSession;
use App\Modules\Workforce\Onboarding\Models\OnboardingUpload;
use App\Modules\Workforce\Onboarding\Models\OnboardingValue;
use App\Modules\Workforce\Onboarding\OnboardingUploadScanService;
use App\Modules\Workforce\Requirements\RequirementConfigService;
use App\Notifications\Onboarding\OnboardingCodeNotification;
use App\Notifications\Onboarding\OnboardingInvitationNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-796.1 — stabilisation of the onboarding file : quarantine and analysis of deposits (fail-closed),
 * candidate verification (one-time code), invitation e-mail, lifecycle without side effects on reads,
 * revision workflow, preparatory records, fixtures cleanup, isolation.
 */
class Adr7961OnboardingStabilizationTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private const FIELDS = ['birth_date', 'nationality', 'iban', 'gender'];

    private Company $a;

    private Company $b;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('quarantine');
        Notification::fake();
        // Les limites de débit par IP (couvertes par ADR-796) gêneraient les scénarios de tentatives répétées ici.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['documents.scanner.driver' => 'none']); // défaut réel : aucun moteur → fail-closed
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce', 'workforce_leave']]);
        $this->a = $this->company('A');
        $this->b = $this->company('B');
        $this->setUpCompanyRbac($this->a);
        $this->hr = $this->member($this->a, ['members.view', 'members.manage', 'workforce.view', 'workforce.manage', 'workforce.sensitive_read', 'members.sensitive_read', 'documents.view', 'documents.manage']);
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

    private function create(array $extra = []): int
    {
        return $this->hrApi('POST', '', $extra + ['first_name' => 'Camille', 'last_name' => 'Test', 'hire_date' => '2026-12-01', 'candidate_email' => 'candidate@example.test',
            'items' => $this->items(), 'purpose' => 'hiring_onboarding', 'legal_basis' => 'contract_preparation', 'notice_text' => 'Information.'])->assertCreated()->json('id');
    }

    private function link(int $id): string
    {
        $url = $this->hrApi('POST', "/{$id}/link", ['days' => 7])->assertOk()->json('url');

        return substr($url, strpos($url, '#t=') + 3);
    }

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

        return collect($res->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === OnboardingPortalController::COOKIE)->getValue();
    }

    /** Asks a code through the real flow and reads it from the (faked) e-mail actually sent. */
    private function sentCode(string $cookie): string
    {
        $this->portal('POST', '/code', [], $cookie)->assertOk();
        $code = null;
        Notification::assertSentOnDemand(OnboardingCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    private function verified(string $token): string
    {
        $cookie = $this->open($token);
        $this->portal('POST', '/verify', ['code' => $this->sentCode($cookie)], $cookie)->assertOk()->assertJsonPath('verified', true);

        return $cookie;
    }

    private function idCard(string $content = '%PDF-1.4 fictive'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('id.pdf', "%PDF-1.4\n".$content."\n%%EOF");
    }

    private function doc(string $cookie, string $code = 'id_card'): array
    {
        return collect($this->portal('GET', '', [], $cookie)->json('documents'))->firstWhere('code', $code);
    }

    // Antivirus indisponible (défaut) : quarantaine, jamais exploitable ; échec technique → indéterminé ; reprise ;
    // fichier sain simulé → DMS (à vérifier) ; idempotence ; validation impossible avant analyse ; aucune obligation créée.
    public function test_quarantine_fail_closed_then_clean_ingestion(): void
    {
        $operationalBefore = json_encode([DocumentResolverService::requirementMap($this->a->id, null, 'FR'), Requirement::count()]);
        $id = $this->create();
        $cookie = $this->verified($this->link($id));
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1990-02-03']], $cookie)->assertOk();
        $this->portal('POST', '/documents/id_card', ['files' => [$this->idCard()]], $cookie)->assertOk();

        $up = OnboardingUpload::sole();
        $this->assertSame(OnboardingUpload::QUARANTINED, $up->status, 'aucun moteur : reste en quarantaine');
        $this->assertSame('none', $up->scan_engine);
        $this->assertSame('no_engine_configured', $up->last_error);
        $this->assertSame(0, MemberDocument::count(), 'jamais un document DMS avant analyse');
        $this->assertSame(0, DocumentRequest::count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'rien dans le coffre documentaire');
        Storage::disk('quarantine')->assertExists($up->files[0]['path']);
        $this->assertSame('scanning', $this->doc($cookie)['scan']);
        $this->assertFalse($this->doc($cookie)['deposited']);

        // La recrue a fait sa part : soumission possible ; la validation RH, elle, est bloquée.
        $this->portal('POST', '/submit', [], $cookie)->assertOk()->assertJsonPath('status', 'submitted');
        $detail = $this->hrApi('GET', "/{$id}")->assertOk()->json();
        $this->assertSame(['id_card'], $detail['blocking_documents']);
        $this->assertSame('quarantined', collect($detail['documents'])->firstWhere('code', 'id_card')['scan']['status']);
        $this->hrApi('POST', "/{$id}/validate", ['decisions' => ['birth_date' => 'accept']])->assertStatus(422)->assertJsonValidationErrors('documents');

        // Reprises sans moteur → indéterminé après le maximum ; jamais « sain ».
        config(['documents.scanner.max_attempts' => 3]);
        $this->artisan('onboarding:scan-uploads')->assertSuccessful();
        $this->artisan('onboarding:scan-uploads')->assertSuccessful();
        $this->assertSame(OnboardingUpload::SCAN_FAILED, $up->refresh()->status);
        $this->assertSame('scan_failed', $this->doc($cookie)['scan']);
        $this->assertTrue(DB::table('company_audit_logs')->where('action', 'onboarding.upload_scan_failed')->exists());
        $this->assertSame(0, MemberDocument::count());

        // Nouvelle analyse demandée par les RH, puis moteur (simulé) disponible : ingestion unique.
        config(['documents.scanner.driver' => 'simulated']);
        $this->hrApi('POST', "/{$id}/uploads/{$up->id}/rescan")->assertOk();
        $this->assertSame(OnboardingUpload::INGESTED, $up->refresh()->status, 'queue sync : analysé');
        $this->assertSame('simulated', $up->scan_engine, 'le moteur réellement utilisé est tracé');
        Storage::disk('quarantine')->assertMissing($up->files[0]['path']);
        $this->assertSame(1, MemberDocument::count());
        $this->assertSame(DocumentRequest::STATUS_SUBMITTED, DocumentRequest::sole()->status, 'déposé ≠ validé');
        (new OnboardingUploadScanService(new SimulatedScanner))->process($up->id); // rejeu
        $this->assertSame(1, MemberDocument::count(), 'idempotent');
        $this->hrApi('POST', "/{$id}/uploads/{$up->id}/rescan")->assertStatus(422); // seul un indéterminé se relance

        $this->hrApi('POST', "/{$id}/validate", ['decisions' => ['birth_date' => 'accept']])->assertOk()->assertJsonPath('file.status', 'validated');
        $this->assertSame($operationalBefore, json_encode([DocumentResolverService::requirementMap($this->a->id, null, 'FR'), Requirement::count()]), 'aucune obligation opérationnelle');
    }

    // Fichier infecté simulé : refusé, supprimé, jamais au DMS ; la pièce redevient manquante ; nouveau dépôt sain accepté.
    public function test_infected_file_is_refused_and_deleted(): void
    {
        config(['documents.scanner.driver' => 'simulated']);
        $id = $this->create();
        $cookie = $this->verified($this->link($id));
        $this->portal('POST', '/documents/id_card', ['files' => [$this->idCard(SimulatedScanner::EICAR)]], $cookie)->assertOk();
        $up = OnboardingUpload::sole();
        $this->assertSame(OnboardingUpload::INFECTED, $up->status);
        $this->assertSame('Eicar-Test-Signature', $up->scan_signature);
        $this->assertNotNull($up->files_deleted_at);
        Storage::disk('quarantine')->assertMissing($up->files[0]['path']);
        $this->assertSame(0, MemberDocument::count());
        $this->assertSame('refused', $this->doc($cookie)['scan']);
        $this->assertContains('id_card', $this->portal('GET', '', [], $cookie)->json('missing.required'));
        $this->assertSame(1, DB::table('company_audit_logs')->where('action', 'onboarding.upload_infected')->count());

        $this->portal('POST', '/documents/id_card', ['files' => [$this->idCard()]], $cookie)->assertOk();
        $this->assertTrue($this->doc($cookie)['deposited']);
        $this->assertSame(1, MemberDocument::count());
    }

    // Échec de scan simulé + dépôt remplacé avant analyse ; isolation entre candidats et entre sociétés.
    public function test_scan_failure_superseded_deposit_and_isolation(): void
    {
        config(['documents.scanner.driver' => 'simulated', 'documents.scanner.max_attempts' => 1]);
        $id = $this->create();
        $cookie = $this->verified($this->link($id));
        $this->portal('POST', '/documents/id_card', ['files' => [$this->idCard(SimulatedScanner::FAILURE_MARKER)]], $cookie)->assertOk();
        $failed = OnboardingUpload::sole();
        $this->assertSame(OnboardingUpload::SCAN_FAILED, $failed->status);
        $this->assertSame('simulated_failure', $failed->last_error);

        // Un autre candidat (même société) ne voit rien de ce dépôt ; la société B n'atteint rien.
        $other = $this->create(['first_name' => 'Autre', 'email' => 'autre@example.test', 'candidate_email' => 'autre@example.test']);
        $c2 = $this->open($this->link($other));
        $this->assertNull($this->doc($c2)['scan']);
        $hrB = $this->member($this->b, ['workforce.view', 'workforce.manage']);
        $this->hrApi('POST', "/{$id}/uploads/{$failed->id}/rescan", [], $hrB, $this->b)->assertNotFound();
        $this->hrApi('POST', "/{$id}/send", [], $hrB, $this->b)->assertNotFound();
        $this->hrApi('POST', "/{$id}/items/preview", ['items' => $this->items()], $hrB, $this->b)->assertNotFound();
        $this->hrApi('POST', "/{$other}/uploads/{$failed->id}/rescan")->assertNotFound(); // dépôt d'un autre dossier

        // Nouveau dépôt : l'ancien (indéterminé) est remplacé, ses fichiers supprimés.
        $this->portal('POST', '/documents/id_card', ['files' => [$this->idCard()]], $cookie)->assertOk();
        $this->assertSame(OnboardingUpload::SUPERSEDED, $failed->refresh()->status);
        $this->assertNotNull($failed->files_deleted_at);
        $this->assertTrue($this->doc($cookie)['deposited']);
    }

    // Code à usage unique : incorrect, expiré, réutilisé, tentatives excessives, verrouillage, plafond d'envois,
    // sessions multiples, lien transféré, changement d'adresse, échec d'envoi ; jamais de code dans l'audit.
    public function test_one_time_code_hardening(): void
    {
        $id = $this->create();
        $token = $this->link($id);
        $s1 = $this->open($token);
        $s2 = $this->open($token); // lien transféré / deuxième appareil
        $this->portal('PUT', '/values', ['values' => ['iban' => 'FR7630006000011234567890189']], $s1)->assertStatus(422); // avant vérification
        $this->assertTrue($this->portal('GET', '', [], $s1)->json('verification_required'));

        $code = $this->sentCode($s1);
        $this->portal('POST', '/verify', ['code' => $code === '000000' ? '111111' : '000000'], $s1)->assertStatus(422);
        Inv::whereKey($id)->update(['code_expires_at' => now()->subSecond()]);
        $this->portal('POST', '/verify', ['code' => $code], $s1)->assertStatus(422); // expiré
        Notification::fake();
        $code = $this->sentCode($s1);
        $this->portal('POST', '/verify', ['code' => $code], $s1)->assertOk();
        $this->portal('POST', '/verify', ['code' => $code], $s2)->assertStatus(422); // réutilisé : usage unique
        $this->assertFalse($this->portal('GET', '', [], $s2)->json('verified'), 'la vérification ne vaut que pour SA session');
        $this->assertNull(collect($this->portal('GET', '', [], $s2)->json('fields'))->firstWhere('code', 'iban')['value']);

        // Tentatives excessives → verrouillage ; il faut un nouveau lien.
        config(['onboarding.code.max_failures' => 3]);
        Notification::fake();
        $this->sentCode($s2);
        foreach (range(1, 3) as $_) {
            $this->portal('POST', '/verify', ['code' => $_ === '999999' ? '0' : 'abcdef'], $s2)->assertStatus(422);
        }
        $this->assertNotNull(Inv::find($id)->verification_locked_at);
        $this->portal('POST', '/code', [], $s2)->assertStatus(422);
        $this->assertTrue($this->portal('GET', '', [], $s2)->json('verification_locked'));
        $this->assertTrue(DB::table('company_audit_logs')->where('action', 'onboarding.verification_locked')->exists());
        $token = $this->link($id); // nouveau lien : verrou levé, anciennes sessions mortes
        $this->portal('GET', '', [], $s1)->assertStatus(401);
        $s3 = $this->open($token);

        // Plafond quotidien d'envois de codes.
        config(['onboarding.code.max_sends_per_day' => 2]);
        Inv::whereKey($id)->update(['code_sends' => 0, 'code_window_at' => null]);
        $this->portal('POST', '/code', [], $s3)->assertOk();
        $this->portal('POST', '/code', [], $s3)->assertOk();
        $this->portal('POST', '/code', [], $s3)->assertStatus(422);

        // Changement d'adresse : code, lien et sessions invalidés.
        $this->hrApi('PUT', "/{$id}/contact", ['candidate_email' => 'new@example.test'])->assertOk();
        $this->portal('GET', '', [], $s3)->assertStatus(401);
        $this->portal('POST', '/session', ['token' => $token])->assertNotFound();
        $fresh = Inv::find($id);
        $this->assertSame('new@example.test', $fresh->candidate_email);
        $this->assertNull($fresh->code_hash);

        // Échec d'envoi : aucun code valide ne subsiste ; aucun code / jeton / adresse dans l'audit.
        $s4 = $this->open($this->link($id));
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, new class
        {
            public function sendNow(...$a)
            {
                throw new \RuntimeException('smtp down');
            }

            public function send(...$a)
            {
                throw new \RuntimeException('smtp down');
            }
        });
        $this->portal('POST', '/code', [], $s4)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertNull(Inv::find($id)->code_hash, 'un code non transmis n’existe pas');
        $audit = json_encode(DB::table('company_audit_logs')->get());
        foreach ([$code, $token, 'new@example.test', 'candidate@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }
    }

    // Un dossier qui demande des éléments sensibles ne se finalise jamais sans vérification ; pas d'e-mail → pas de lien.
    public function test_sensitive_file_cannot_be_finalised_without_verification(): void
    {
        $id = $this->create();
        $cookie = $this->open($this->link($id));
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1990-02-03']], $cookie)->assertOk();
        $this->portal('POST', '/submit', [], $cookie)->assertStatus(422)->assertJsonValidationErrors('verification');
        $this->assertSame('in_progress', Inv::find($id)->status);

        $noMail = $this->create(['first_name' => 'Sans', 'email' => null, 'candidate_email' => null]);
        $this->hrApi('POST', "/{$noMail}/link")->assertStatus(422)->assertJsonValidationErrors('candidate_email');

        // Canal non transactionnel (journal) : aucun code, accès sensible bloqué.
        config(['mail.default' => 'log']);
        $this->assertFalse($this->portal('GET', '', [], $cookie)->json('verification_available'));
        $this->portal('POST', '/code', [], $cookie)->assertStatus(422);
        // Hors production, un SMTP distant n'est jamais utilisé (aucun vrai destinataire depuis DEV).
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);
        $this->portal('POST', '/code', [], $cookie)->assertStatus(422);
        $this->hrApi('POST', "/{$id}/send")->assertStatus(422)->assertJsonValidationErrors('mail');
        Notification::assertNothingSent();
    }

    // Invitation par e-mail : envoi, anti-renvoi, plafond, nouveau lien à chaque envoi, échec tracé sans lien, révocation.
    public function test_invitation_email_delivery(): void
    {
        $id = $this->create();
        $res = $this->hrApi('POST', "/{$id}/send", ['days' => 5])->assertOk()->assertJsonPath('delivered', true);
        $this->assertStringNotContainsString('#t=', $res->getContent(), 'le lien ne revient jamais dans la réponse');
        $url = null;
        Notification::assertSentOnDemand(OnboardingInvitationNotification::class, function ($n, $channels, $notifiable) use (&$url) {
            $url = $n->url;

            return $notifiable->routes['mail'] === 'candidate@example.test';
        });
        $token = substr($url, strpos($url, '#t=') + 3);
        $this->open($token);
        $inv = Inv::find($id);
        $this->assertSame(['sent', 'sent'], [$inv->status === Inv::IN_PROGRESS ? 'sent' : $inv->status, $inv->delivery_status]);

        $this->hrApi('POST', "/{$id}/send")->assertStatus(422)->assertJsonValidationErrors('mail'); // anti-renvoi immédiat
        $this->travel(3)->minutes();
        Notification::fake();
        $this->hrApi('POST', "/{$id}/send")->assertOk();
        $this->portal('POST', '/session', ['token' => $token])->assertNotFound(); // renvoi = nouveau lien
        config(['onboarding.mail.max_per_day' => 2]);
        $this->travel(3)->minutes();
        $this->hrApi('POST', "/{$id}/send")->assertStatus(422);

        // Échec de délivrance : état failed, erreur sans lien, audit sans jeton.
        config(['onboarding.mail.max_per_day' => 10]);
        $this->travel(3)->minutes();
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, new class
        {
            public function sendNow(...$a)
            {
                throw new \RuntimeException('Connection refused while sending https://leezr.test/onboarding#t=abc');
            }
        });
        $this->hrApi('POST', "/{$id}/send")->assertStatus(502)->assertJsonPath('delivered', false)->assertJsonPath('file.delivery.status', 'failed');
        $this->assertStringNotContainsString('#t=', (string) Inv::find($id)->delivery_error);
        $this->assertStringNotContainsString($token, json_encode(DB::table('company_audit_logs')->get()));

        $this->hrApi('POST', "/{$id}/revoke")->assertOk();
        $this->assertFalse($this->hrApi('GET', "/{$id}")->json('link_active'));
    }

    // Une lecture reste une lecture ; expiration constatée par le service, idempotente ; soumis jamais expiré ;
    // rétention planifiée en simulation.
    public function test_reads_never_write_and_expiry_is_explicit(): void
    {
        $id = $this->create();
        $cookie = $this->open($this->link($id));
        $submitted = $this->create(['first_name' => 'Soumis', 'email' => 's@example.test', 'items' => [['kind' => 'field', 'code' => 'birth_date', 'required' => true]]]);
        $sc = $this->open($this->link($submitted));
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1990-01-01']], $sc)->assertOk();
        $this->portal('POST', '/submit', [], $sc)->assertOk();
        Inv::whereIn('id', [$id, $submitted])->update(['expires_at' => now()->subHour()]);
        $before = json_encode(Inv::orderBy('id')->get()->map->getAttributes());

        $list = collect($this->hrApi('GET', '')->assertOk()->json('invitations'))->keyBy('id');
        $this->assertSame('expired', $list[$id]['status'], 'affiché expiré');
        $this->hrApi('GET', "/{$id}")->assertOk()->assertJsonPath('status', 'expired');
        $this->assertSame($before, json_encode(Inv::orderBy('id')->get()->map->getAttributes()), 'GET : aucune écriture');

        $this->artisan('onboarding:expire')->assertSuccessful();
        $this->artisan('onboarding:expire')->assertSuccessful();
        $this->assertSame(Inv::EXPIRED, Inv::find($id)->status);
        $this->assertNotNull(Inv::find($id)->expired_at);
        $this->assertNull(Inv::find($id)->token_hash);
        $this->assertSame(Inv::SUBMITTED, Inv::find($submitted)->status, 'un dossier soumis attend les RH');
        $this->assertSame(1, DB::table('company_audit_logs')->where('action', 'onboarding.expired')->count(), 'idempotent');
        $this->assertSame(0, OnboardingSession::where('onboarding_invitation_id', $id)->count());
        $this->assertSame(1, OnboardingValue::where('onboarding_invitation_id', $submitted)->count(), 'aucune suppression à l’expiration');
        $this->portal('GET', '', [], $cookie)->assertStatus(401);
        // Renouvellement : un dossier expiré reçoit un nouveau lien et reprend où il en était.
        $renewed = $this->link($id);
        $this->assertSame(Inv::IN_PROGRESS, Inv::find($id)->status);
        $this->assertNull(Inv::find($id)->expired_at);
        $this->open($renewed);

        $events = collect(app(Schedule::class)->events())->map->command->filter(fn ($c) => str_contains((string) $c, 'onboarding:'))->implode(' | ');
        $this->assertStringContainsString('onboarding:expire', $events);
        $this->assertStringContainsString('onboarding:scan-uploads', $events);
        $this->assertStringContainsString('onboarding:retention', $events);
        $this->assertStringNotContainsString('--apply', $events, 'aucune purge réelle planifiée par défaut');
    }

    // Révision : prévisualisation, obligation injustifiée refusée, après soumission = complément motivé,
    // réponses à un élément retiré conservées et jamais écrites dans la fiche.
    public function test_revision_preview_and_workflow(): void
    {
        config(['documents.scanner.driver' => 'simulated']);
        $id = $this->create();
        $cookie = $this->verified($this->link($id));
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1990-02-03', 'nationality' => 'Française']], $cookie)->assertOk();
        $this->portal('POST', '/documents/id_card', ['files' => [$this->idCard()]], $cookie)->assertOk();

        $next = [['kind' => 'field', 'code' => 'birth_date', 'required' => true], ['kind' => 'field', 'code' => 'iban', 'required' => false],
            ['kind' => 'field', 'code' => 'gender', 'required' => false], ['kind' => 'document', 'code' => 'id_card', 'required' => true]];
        $preview = $this->hrApi('POST', "/{$id}/items/preview", ['items' => $next])->assertOk()->json();
        $this->assertSame(['gender'], array_column($preview['added'], 'code'));
        $this->assertSame(['nationality'], array_column($preview['removed'], 'code'));
        $this->assertSame(['field:nationality'], $preview['removed_with_answers']);
        $this->assertFalse($preview['message_required']);
        $this->assertSame(1, Inv::find($id)->snapshot_version, 'prévisualiser ne change rien');

        $this->hrApi('POST', "/{$id}/items/preview", ['items' => [['kind' => 'field', 'code' => 'nationality', 'required' => true]]])
            ->assertStatus(422)->assertJsonValidationErrors('items'); // obligation non justifiée

        $this->portal('POST', '/submit', [], $cookie)->assertOk();
        $this->assertTrue($this->hrApi('POST', "/{$id}/items/preview", ['items' => $next])->json('message_required'));
        $this->hrApi('PUT', "/{$id}/items", ['items' => $next])->assertStatus(422)->assertJsonValidationErrors('message');
        $this->hrApi('PUT', "/{$id}/items", ['items' => $next, 'message' => 'Merci d’indiquer aussi votre genre.'])->assertOk()
            ->assertJsonPath('status', 'changes_requested')->assertJsonPath('snapshot_version', 2);
        $this->assertSame('Merci d’indiquer aussi votre genre.', $this->portal('GET', '', [], $cookie)->json('hr_message'));
        $this->assertCount(1, Inv::find($id)->snapshot_history, 'version initiale conservée');
        $this->assertSame(1, MemberDocument::count(), 'pièce déjà déposée conservée');

        $this->portal('POST', '/submit', [], $cookie)->assertOk();
        $this->hrApi('POST', "/{$id}/validate", ['decisions' => ['birth_date' => 'accept']])->assertOk();
        $nat = FieldDefinition::where('code', 'nationality')->value('id');
        $this->assertSame(OnboardingValue::NOT_REQUESTED, OnboardingValue::where('field_definition_id', $nat)->value('status'), 'réponse conservée, non reprise');
        $this->assertFalse(FieldValue::where('field_definition_id', $nat)->exists(), 'jamais écrite dans la fiche');
    }

    // Fiches préparatoires : statut distinct, hors conformité documentaire, campagnes et impact des exigences ;
    // redeviennent opérationnelles à l'activation ; paie/planning n'incluent que les actifs.
    public function test_preparatory_records_are_not_operational(): void
    {
        $this->create();
        $prep = Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->sole();
        $this->assertTrue($prep->isPreparatory());
        $regular = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'R', 'last_name' => 'S', 'status' => 'inactive', 'hire_date' => '2026-01-01']);

        $rows = collect(PeopleListReadModel::list($this->a, $this->hr, [], 50)['data'])->keyBy('employee_id');
        $this->assertSame('preparatory', $rows[$prep->id]['employment_status']);
        $this->assertSame('inactive', $rows[$regular->id]['employment_status'], 'un inactif historique reste inactif');

        $subjects = collect(PersonPopulationResolver::forCompany($this->a))->map(fn ($p) => (string) $p['subject'])->all();
        $this->assertNotContains('employee:'.$prep->id, $subjects);
        $this->assertContains('employee:'.$regular->id, $subjects);
        $this->assertSame(1, collect((new RequirementConfigService($this->a))->scopes())->firstWhere('key', 'all')['people']);

        $this->assertSame(0, Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->active()->count(), 'paie / planning : actifs seulement');
        $prep->update(['status' => 'active']);
        $this->assertFalse($prep->refresh()->isPreparatory());
        $this->assertContains('employee:'.$prep->id, collect(PersonPopulationResolver::forCompany($this->a))->map(fn ($p) => (string) $p['subject'])->all());
    }

    // Nettoyage ciblé des fixtures : ids explicites, simulation par défaut, refus de tout ce qui n'est pas une fixture.
    public function test_fixture_cleanup_is_explicit_and_guarded(): void
    {
        config(['documents.scanner.driver' => 'simulated']);
        $id = $this->create(['first_name' => 'E2E Recrue', 'last_name' => '000001']);
        $cookie = $this->verified($this->link($id));
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1990-02-03']], $cookie)->assertOk();
        $this->portal('POST', '/documents/id_card', ['files' => [$this->idCard()]], $cookie)->assertOk();
        $fixture = Employee::withoutGlobalScopes()->where('first_name', 'E2E Recrue')->sole();
        $real = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'Réel', 'last_name' => 'X', 'status' => 'inactive', 'hire_date' => '2026-01-01']);
        $withContract = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'E2E Contrat', 'last_name' => 'Y', 'status' => 'inactive', 'hire_date' => '2026-01-01']);
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'employee_id' => $withContract->id, 'contract_type' => 'cdi', 'work_model_key' => 'hourly', 'start_date' => '2026-01-01', 'status' => 'draft']);

        $this->artisan('onboarding:cleanup-fixtures', ['employee' => [$fixture->id, $real->id], '--apply' => true])->assertFailed();
        $this->artisan('onboarding:cleanup-fixtures', ['employee' => [$withContract->id], '--apply' => true])->assertFailed();
        $this->artisan('onboarding:cleanup-fixtures', ['employee' => [$fixture->id]])->assertSuccessful(); // simulation
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($fixture->id));
        $this->assertSame(1, MemberDocument::count());

        // ADR-828 : outside a marked clone, --apply needs the explicit live database name — refused, nothing removed
        $this->artisan('onboarding:cleanup-fixtures', ['employee' => [$fixture->id], '--apply' => true])->assertFailed();
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($fixture->id));
        $this->app->instance(\App\Console\Recipe\RecipeDatabaseGuard::class, new \Tests\Support\AllowingRecipeGuard);
        $this->artisan('onboarding:cleanup-fixtures', ['employee' => [$fixture->id], '--apply' => true])->assertSuccessful();
        $this->assertNull(Employee::withoutGlobalScopes()->find($fixture->id));
        $this->assertSame(0, Inv::count() + MemberDocument::count() + DocumentRequest::count() + OnboardingValue::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($real->id), 'rien d’autre');
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($withContract->id));
        $this->assertTrue(DB::table('company_audit_logs')->where('action', 'onboarding.fixture_removed')->exists(), 'historique d’audit conservé');
    }
}
