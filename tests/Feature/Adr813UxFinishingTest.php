<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Audit\AuditAction;
use App\Core\Documents\Services\SubjectDocumentUploadService;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\MailLocale;
use App\Notifications\Members\AccountActivationNotification;
use App\Platform\Models\PlatformUser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ADR-813 — finishing of the anomalies left by ADR-811/812 : default role names, e-mail language,
 * audit labels, chart remount, accepted formats, « Documents reçus » block.
 */
class Adr813UxFinishingTest extends TestCase
{
    use RefreshDatabase;

    private const FR_NAMES = ['owner' => 'Propriétaire', 'manager' => 'Responsable', 'dispatcher' => 'Répartiteur',
        'driver' => 'Chauffeur-livreur', 'ops_manager' => 'Responsable d’exploitation'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
    }

    private function company(string $market = 'FR', string $name = 'Transports Recette'): Company
    {
        return Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => $market]);
    }

    private function gbMarket(): void
    {
        if (! DB::table('markets')->where('key', 'GB')->exists()) {
            DB::table('markets')->insert(['key' => 'GB', 'name' => 'United Kingdom', 'currency' => 'GBP', 'vat_rate_bps' => 2000, 'locale' => 'en-GB',
                'timezone' => 'Europe/London', 'dial_code' => '+44', 'is_active' => true, 'is_default' => false, 'is_eu' => false, 'sort_order' => 9,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Last e-mail sent through the array mailer, as rendered HTML. */
    private function lastHtml(): string
    {
        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertGreaterThan(0, $messages->count(), 'an e-mail was sent');

        return (string) $messages->last()->getOriginalMessage()->getHtmlBody();
    }

    private function resetMailbox(): void
    {
        app('mail.manager')->mailer('array')->getSymfonyTransport()->flush();
    }

    // ── 1. Default roles ────────────────────────────────────────────────────

    public function test_a_new_company_gets_french_business_role_names_matching_the_logistics_preset(): void
    {
        $company = $this->company();
        JobdomainGate::assignToCompany($company, 'logistique');
        $names = CompanyRole::where('company_id', $company->id)->pluck('name', 'key')->all();
        foreach (self::FR_NAMES as $key => $name) {
            $this->assertSame($name, $names[$key] ?? null, $key);
        }

        // Same names as the published Logistics RBAC preset (ADR-808).
        $preset = DB::table('presets')->where('family_key', 'logistique_rbac')->value('content');
        if ($preset) {
            foreach (json_decode($preset, true)['roles'] ?? [] as $r) {
                $this->assertSame($r['name'], self::FR_NAMES[$r['key']] ?? $r['name'], $r['key']);
            }
        }
    }

    public function test_reassigning_the_jobdomain_never_renames_an_existing_companys_roles(): void
    {
        $company = $this->company();
        JobdomainGate::assignToCompany($company, 'logistique');
        $driver = CompanyRole::where('company_id', $company->id)->where('key', 'driver')->firstOrFail();
        $driver->update(['name' => 'Livreur maison']);
        $legacy = CompanyRole::where('company_id', $company->id)->where('key', 'dispatcher')->firstOrFail();
        $legacy->update(['name' => 'Dispatcher']); // a company created before ADR-813
        $permsBefore = $driver->permissions()->pluck('key')->sort()->values()->all();

        JobdomainGate::assignToCompany($company, 'logistique');

        $this->assertSame('Livreur maison', $driver->fresh()->name);
        $this->assertSame('Dispatcher', $legacy->fresh()->name);
        $this->assertSame($permsBefore, $driver->fresh()->permissions()->pluck('key')->sort()->values()->all());
        $this->assertSame(CompanyRole::where('company_id', $company->id)->count(), CompanyRole::where('company_id', $company->id)->distinct()->count('key'));
    }

    public function test_the_migration_renames_only_untouched_english_defaults_of_the_jobdomain_definition(): void
    {
        $jd = Jobdomain::where('key', 'logistique')->firstOrFail();
        $roles = $jd->default_roles;
        $roles['manager']['name'] = 'Manager';
        $roles['driver']['name'] = 'Driver';
        $roles['dispatcher']['name'] = 'Planificateur'; // edited on the platform : must stay
        $jd->update(['default_roles' => $roles]);
        $existing = $this->company();
        CompanyRole::create(['company_id' => $existing->id, 'key' => 'driver', 'name' => 'Driver', 'is_administrative' => false]);

        (require database_path('migrations/2026_12_07_000001_logistique_default_role_names_fr.php'))->up();

        $after = $jd->fresh()->default_roles;
        $this->assertSame('Responsable', $after['manager']['name']);
        $this->assertSame('Chauffeur-livreur', $after['driver']['name']);
        $this->assertSame('Planificateur', $after['dispatcher']['name']);
        $this->assertSame($roles['driver']['bundles'] ?? null, $after['driver']['bundles'] ?? null);
        $this->assertSame('Driver', CompanyRole::where('company_id', $existing->id)->where('key', 'driver')->value('name'), 'company roles untouched');
    }

    // ── 2. E-mails ─────────────────────────────────────────────────────────

    public function test_mail_locale_follows_the_company_market_with_a_coherent_fallback(): void
    {
        $this->gbMarket();
        $fr = $this->company('FR');
        $gb = $this->company('GB', 'UK Haulage');
        $this->assertSame('fr', MailLocale::forCompany($fr));
        $this->assertSame('en', MailLocale::forCompany($gb));
        $this->assertSame('fr', MailLocale::forCompany(null), 'default market');

        $u = User::factory()->create();
        $this->assertSame('fr', $u->preferredLocale(), 'no company → default market');
        $gb->memberships()->create(['user_id' => $u->id, 'role' => 'user']);
        $this->assertSame('en', $u->fresh()->preferredLocale());
        $fr->memberships()->create(['user_id' => $u->id, 'role' => 'user']);
        $this->assertSame('fr', $u->fresh()->preferredLocale(), 'ambiguous (two markets) → default market');
        $this->assertSame('fr', PlatformUser::factory()->create()->preferredLocale());
    }

    public function test_forgot_password_email_is_entirely_french_for_a_french_company_and_english_for_a_british_one(): void
    {
        $this->gbMarket();
        config(['mail.default' => 'array']);
        $frUser = User::factory()->create(['email' => 'fr@example.test']);
        $this->company('FR')->memberships()->create(['user_id' => $frUser->id, 'role' => 'owner']);
        $gbUser = User::factory()->create(['email' => 'gb@example.test']);
        $this->company('GB', 'UK Haulage')->memberships()->create(['user_id' => $gbUser->id, 'role' => 'owner']);

        $this->postJson('/api/forgot-password', ['email' => 'fr@example.test'])->assertOk();
        $html = $this->lastHtml();
        foreach (['Réinitialiser le mot de passe', 'Tous droits réservés.', 'Cordialement,', 'Si le bouton « Réinitialiser le mot de passe » ne fonctionne pas'] as $s) {
            $this->assertStringContainsString(htmlspecialchars($s, ENT_QUOTES), $html, $s);
        }
        $this->assertStringNotContainsString('All rights reserved', $html);

        $this->resetMailbox();
        $this->postJson('/api/forgot-password', ['email' => 'gb@example.test'])->assertOk();
        $html = $this->lastHtml();
        $this->assertStringContainsString('All rights reserved.', $html);
        $this->assertStringContainsString('Reset Password', $html);
        $this->assertStringNotContainsString('Tous droits réservés', $html);
    }

    public function test_invitation_and_french_only_notifications_carry_a_french_layout_even_for_an_ambiguous_recipient(): void
    {
        config(['mail.default' => 'array']);
        $company = $this->company('FR');
        $user = User::factory()->create(['email' => 'invitee@example.test']);
        $user->notify(new AccountActivationNotification('Transports Recette', 'Olga Owner', 'tok', 'invitee@example.test', 60));
        $html = $this->lastHtml();
        $this->assertStringContainsString('Activer mon compte', $html);
        $this->assertStringContainsString('Tous droits réservés.', $html);
        $this->assertStringContainsString(htmlspecialchars('Si le bouton « Activer mon compte » ne fonctionne pas', ENT_QUOTES), $html);
        $this->assertStringNotContainsString("If you're having trouble", $html);

        // Every notification whose copy is French-only pins its layout locale.
        foreach (['Members/AccountActivationNotification', 'Members/CompanyInvitationNotification', 'Signature/SignatureInvitationNotification',
            'Signature/SignedCopyNotification', 'Workforce/PlanningPublishedNotification'] as $n) {
            $this->assertStringContainsString("\$this->locale = 'fr';", file_get_contents(app_path("Notifications/{$n}.php")), $n);
        }
    }

    public function test_translated_email_copy_follows_the_recipients_company_language(): void
    {
        $this->gbMarket();
        config(['mail.default' => 'array']);
        $frUser = User::factory()->create();
        $this->company('FR')->memberships()->create(['user_id' => $frUser->id, 'role' => 'owner']);
        $gbUser = User::factory()->create();
        $this->company('GB', 'UK Haulage')->memberships()->create(['user_id' => $gbUser->id, 'role' => 'owner']);
        $subject = fn () => app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getSubject();

        $frUser->notify(new \App\Notifications\Onboarding\OnboardingCodeNotification('Acme', '123456', 10));
        $this->assertSame(__('onboarding.code_subject', ['company' => 'Acme'], 'fr'), $subject());
        $this->assertStringContainsString('Tous droits réservés.', $this->lastHtml());
        $gbUser->notify(new \App\Notifications\Onboarding\OnboardingCodeNotification('Acme', '123456', 10));
        $this->assertSame(__('onboarding.code_subject', ['company' => 'Acme'], 'en'), $subject());
        $this->assertNotSame(__('onboarding.code_subject', ['company' => 'Acme'], 'fr'), __('onboarding.code_subject', ['company' => 'Acme'], 'en'));
        $this->assertStringContainsString('All rights reserved.', $this->lastHtml());

        // A candidate without account : the company's market decides (onboarding services pin it).
        foreach (['OnboardingInvitationService', 'OnboardingPortalService'] as $svc) {
            $this->assertStringContainsString('MailLocale::forCompany(', file_get_contents(app_path("Modules/Workforce/Onboarding/{$svc}.php")), $svc);
        }
    }

    // ── 3. P3 ──────────────────────────────────────────────────────────────

    public function test_every_audit_action_has_a_readable_label_and_the_code_stays_available(): void
    {
        $codes = array_values((new \ReflectionClass(AuditAction::class))->getConstants());
        $codes = array_filter($codes, fn ($c) => is_string($c) && str_contains($c, '.'));
        $this->assertGreaterThan(50, count($codes));
        foreach (['fr', 'en'] as $lang) {
            $l = json_decode(file_get_contents(resource_path("js/plugins/i18n/locales/{$lang}.json")), true);
            foreach ($codes as $code) {
                [$entity, $verb] = explode('.', $code, 2);
                $this->assertNotEmpty($l['audit']['actions'][$entity][$verb] ?? null, "{$lang}: audit.actions.{$code}");
            }
            $this->assertNotEmpty($l['audit']['actionCode'] ?? null);
        }
        $page = file_get_contents(resource_path('js/pages/company/audit/index.vue'));
        $this->assertStringContainsString('actionLabel(item.action)', $page);
        $this->assertStringContainsString('data-test="audit-action-code">{{ item.action }}', $page);
    }

    public function test_displayed_accepted_formats_are_the_enforced_ones(): void
    {
        $this->assertSame(['pdf', 'jpg', 'jpeg', 'png'], SubjectDocumentUploadService::DEFAULT_ACCEPTED_TYPES);
        $this->assertSame(['pdf'], SubjectDocumentUploadService::acceptedTypes(['accepted_types' => ['pdf']]));
        $this->assertSame(SubjectDocumentUploadService::DEFAULT_ACCEPTED_TYPES, SubjectDocumentUploadService::acceptedTypes([]));
        $type = new \App\Core\Documents\DocumentType(['validation_rules' => []]);
        $this->assertContains('mimes:pdf,jpg,jpeg,png', SubjectDocumentUploadService::fileRules($type));
        foreach (['app/Core/Documents/DocumentResolverService.php', 'app/Core/Documents/ReadModels/MemberDocumentWorkflowReadModel.php', 'app/Modules/Core/Members/UseCases/UploadOwnDocumentUseCase.php'] as $f) {
            $src = file_get_contents(base_path($f));
            $this->assertStringContainsString('SubjectDocumentUploadService::acceptedTypes($rules)', $src, $f);
            $this->assertStringNotContainsString("?? ['pdf', 'jpg', 'png']", $src, $f);
        }
        foreach (['fr' => 'Formats acceptés : {types}', 'en' => 'Accepted formats: {types}'] as $lang => $s) {
            $this->assertSame($s, json_decode(file_get_contents(resource_path("js/plugins/i18n/locales/{$lang}.json")), true)['documents']['acceptedFormatsList']);
        }
        $this->assertStringContainsString("t('documents.acceptedFormatsList', { types:", file_get_contents(resource_path('js/views/pages/account-settings/AccountSettingsDocuments.vue')));
    }

    public function test_chart_remounts_on_height_or_type_change_and_the_documents_block_explains_itself(): void
    {
        $chart = file_get_contents(resource_path('js/core/components/LzrApexChart.vue'));
        $this->assertStringContainsString(':key="chartKey"', $chart);
        $this->assertStringContainsString('${props.type}-${props.height}', $chart);
        $this->assertStringContainsString(':key="`${measuredW}-${height}`"', file_get_contents(resource_path('js/core/components/LzrGauge.vue')));

        $fr = json_decode(file_get_contents(resource_path('js/plugins/i18n/locales/fr.json')), true)['workforceMe']['hours'];
        $this->assertSame('Documents reçus', $fr['documentsTitle']);
        $this->assertStringContainsString('pièces que vous devez fournir', $fr['noDocuments']);
    }
}
