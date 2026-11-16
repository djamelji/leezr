<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Notifications\Members\AccountActivationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-812 — regressions of the anomalies proven by the ADR-811 browser acceptance.
 */
class Adr812UxCorrectionsTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private function locale(string $lang): array
    {
        return json_decode(file_get_contents(resource_path("js/plugins/i18n/locales/{$lang}.json")), true);
    }

    /** ADR-811 A2 : the module catalogue printed `companyModules.metric_employees` as its pricing unit. */
    public function test_every_module_pricing_metric_and_password_rule_has_a_label(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Modules\ModuleRegistry::sync();
        $this->seed(\Database\Seeders\ModuleProductConfigSeeder::class);
        $metrics = DB::table('platform_modules')->whereNotNull('addon_pricing')->pluck('addon_pricing')
            ->map(fn ($p) => json_decode($p, true)['pricing_metric'] ?? null)->filter(fn ($m) => $m !== 'none')->unique()->values(); // 'none' = flat price, no unit shown
        $this->assertContains('employees', $metrics->all());

        foreach (['fr', 'en'] as $lang) {
            $l = $this->locale($lang);
            foreach ($metrics as $metric) {
                $this->assertNotEmpty($l['companyModules']["metric_{$metric}"] ?? null, "{$lang}: companyModules.metric_{$metric}");
            }
            foreach (['min', 'upper', 'lower', 'number', 'special'] as $rule) {
                $this->assertNotEmpty($l['auth']['passwordRules'][$rule] ?? null, "{$lang}: auth.passwordRules.{$rule}");
            }
        }
        $this->assertSame('Au moins 8 caractères', $this->locale('fr')['auth']['passwordRules']['min']);
        $this->assertStringNotContainsString('précédemment', $this->locale('fr')['auth']['setPasswordSubtitle']);
    }

    /**
     * ADR-811 C1 : a new member received the generic English « password reset request » e-mail, naming
     * neither the company nor the invitation. Now an activation e-mail, same token, broker and expiry.
     */
    public function test_new_account_invitation_sends_an_activation_email_naming_the_company(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Notification::fake();
        $company = Company::create(['name' => 'Transports Recette', 'slug' => 'tr-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->activateCompanyModules($company);
        $this->setUpCompanyRbac($company);
        $staff = CompanyRole::create(['company_id' => $company->id, 'key' => 'staff', 'name' => 'Staff', 'is_administrative' => false]);
        $owner = User::factory()->create(['first_name' => 'Olga', 'last_name' => 'Owner']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $this->actingAs($owner)->withHeaders(['X-Company-Id' => $company->id])
            ->postJson('/api/company/members', ['email' => 'new-hire@example.test', 'company_role_id' => $staff->id])->assertCreated();
        $invitee = User::where('email', 'new-hire@example.test')->firstOrFail();

        Notification::assertNotSentTo($invitee, ResetPassword::class);
        Notification::assertSentTo($invitee, AccountActivationNotification::class, function (AccountActivationNotification $n) use ($invitee) {
            $mail = $n->toMail($invitee);
            $text = implode(' ', array_merge($mail->introLines, $mail->outroLines));

            $this->assertSame('Votre accès à Transports Recette sur Leezr', $mail->subject);
            $this->assertStringContainsString('Olga Owner vous a ajouté(e)', $text);
            $this->assertStringContainsString('valable 60 minutes', $text);
            $this->assertSame('Activer mon compte', $mail->actionText);
            $this->assertSame('À bientôt sur Leezr', $mail->salutation);
            $this->assertStringContainsString('/reset-password?token='.$n->token.'&email=new-hire%40example.test', $mail->actionUrl);
            // The link is a real broker token : it activates the account.
            $this->assertTrue(Password::broker('users')->tokenExists($invitee, $n->token));

            return true;
        });
    }

    /**
     * ADR-811 D3 / D4 / F1 : refusals explained in the user's language. The planning page maps the EXACT server
     * messages (guards against drift) ; Mon activité explains an empty leave-type list ; the person file treats
     * 403/404 as « not available » (no pointless retry).
     */
    public function test_known_refusals_are_explained_in_the_users_language(): void
    {
        $page = file_get_contents(resource_path('js/pages/company/workforce/planning/index.vue'));
        foreach (['CreateShiftUseCase', 'UpdateShiftUseCase'] as $uc) {
            $src = file_get_contents(app_path("Modules/Workforce/UseCases/{$uc}.php"));
            $this->assertStringContainsString("'Employee must have an active contract.'", $src, $uc);
        }
        $this->assertStringContainsString("'Employee must have an active contract.': 'planning.errorNoActiveContract'", $page);
        $this->assertStringContainsString("'Employee must be active to be assigned a shift.': 'planning.errorEmployeeInactive'", $page);
        $this->assertStringContainsString('shiftErrorMessage(', $page);

        foreach (['fr', 'en'] as $lang) {
            $l = $this->locale($lang);
            foreach ([['planning', 'errorNoActiveContract'], ['planning', 'errorEmployeeInactive'], ['workforceMe', 'noLeaveBalance'], ['people', 'notAvailableTitle'], ['people', 'notAvailableMessage']] as [$ns, $k]) {
                $this->assertNotEmpty($l[$ns][$k] ?? null, "{$lang}: {$ns}.{$k}");
            }
        }
        $me = file_get_contents(resource_path('js/pages/company/workforce/me.vue'));
        $this->assertStringContainsString('data-test="me-leave-no-balance"', $me);
        $this->assertStringContainsString(':disabled="!leaveTypeOptions.length"', $me);
        $file = file_get_contents(resource_path('js/pages/company/people/[personRef].vue'));
        $this->assertStringContainsString('[403, 404].includes(store.errorStatus)', $file);
    }
}
