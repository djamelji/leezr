<?php

namespace Tests\Feature;

use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-848 (Leezr 4.2 M9) — technical cleanup : removed diagnostic endpoint, bounded public endpoint, and the
 * consolidations kept consolidated (no second copy of the planning perimeter or of the invoice PDF rendering).
 */
class Adr848TechnicalCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_whoami_diagnostic_is_gone_and_me_still_answers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/whoami')->assertNotFound();
        $this->actingAs($user, 'web')->getJson('/api/me')->assertOk();
    }

    public function test_public_funnel_tracking_accepts_bounded_metadata_only(): void
    {
        $this->postJson('/api/register/funnel-track', ['step' => 'started', 'metadata' => ['source' => 'landing', 'n' => 2]])->assertOk();
        $this->postJson('/api/register/funnel-track', ['step' => 'started', 'metadata' => array_fill_keys(range(1, 21), 'x')])->assertUnprocessable();
        $this->postJson('/api/register/funnel-track', ['step' => 'started', 'metadata' => ['nested' => ['a' => 1]]])->assertUnprocessable();
        $this->postJson('/api/register/funnel-track', ['step' => 'started', 'metadata' => ['long' => str_repeat('x', 256)]])->assertUnprocessable();
    }

    public function test_consolidations_have_a_single_implementation(): void
    {
        $planning = glob(app_path('Modules/Workforce/Planning/Http/*.php'));
        foreach ($planning as $file) {
            $src = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/function (planningScope|guardShiftScope|guardScope)\(/', $src, basename($file).' must use the PlanningScope trait');
        }
        foreach (['Modules/Core/Billing/Http/CompanyBillingController.php', 'Modules/Platform/Billing/Http/PlatformBillingController.php'] as $c) {
            $src = file_get_contents(app_path($c));
            $this->assertStringContainsString('InvoicePdfRenderer::render', $src);
            $this->assertStringNotContainsString("loadView('billing.invoice-pdf'", $src, "$c must not render the invoice PDF itself");
        }
        // « new link + invitation e-mail » for an already invited signer : one implementation (SignerInvitationIssuer)
        $remind = file_get_contents(app_path('Modules/Workforce/Signature/UseCases/RemindSignatureRequestUseCase.php'));
        $this->assertStringContainsString('SignerInvitationIssuer', $remind);
        $this->assertStringNotContainsString('SignatureTokenService::generate', $remind);
        $this->assertStringContainsString('SignerInvitationIssuer', file_get_contents(app_path('Modules/Workforce/Signature/Services/SignerEnrollmentService.php')));
    }
}
