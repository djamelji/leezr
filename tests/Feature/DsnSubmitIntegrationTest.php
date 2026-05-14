<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\Dsn\DsnPreflightChecker;
use App\Core\Workforce\Dsn\Gateway\DsnGatewayInterface;
use App\Core\Workforce\Dsn\Gateway\DsnPollingInterface;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\FakeNetEntreprisesClient;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesDsnGateway;
use App\Core\Workforce\Dsn\Gateway\NullDsnGateway;
use App\Core\Workforce\Dsn\PreflightResult;
use App\Modules\Workforce\UseCases\SubmitDsnDeclarationUseCase;
use App\Core\Markets\Market;
use App\Platform\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 7.3 — ADR-531
 * Integration tests for submit + poll lifecycle with gateway tracking.
 */
class DsnSubmitIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private DsnDeclaration $declaration;

    private string $fileContent = "S21.G00.06.001,'73282932000074'\nS21.G00.06.002,'6201Z'";

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Storage::fake('local');

        Market::create([
            'key' => 'FR', 'name' => 'France', 'currency' => 'EUR',
            'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000,
            'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '',
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Submit Integration Co', 'slug' => 'submit-int-co',
            'jobdomain_key' => 'tech', 'market_key' => 'FR',
            'siret' => '73282932000074', 'naf_code' => '6201Z',
            'address_street' => '10 rue de la Paix',
            'address_postal_code' => '75002', 'address_city' => 'Paris',
        ]);

        $this->user = User::create([
            'name' => 'Operator', 'email' => 'op-submit@test.com',
            'password' => bcrypt('password'),
        ]);

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'status' => 'exported', 'currency' => 'EUR',
            'employee_count' => 1, 'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000, 'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:submit-int:test',
        ]);

        $filePath = "workforce/dsn/{$this->company->id}/dsn_submit_int.dsn";
        Storage::disk('local')->put($filePath, $this->fileContent);

        $this->declaration = DsnDeclaration::create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-04',
            'status' => DsnDeclaration::STATUS_EXPORTED,
            'file_path' => $filePath,
            'payload_snapshot' => ['test' => true],
            'payload_hash' => hash('sha256', $this->fileContent),
            'generated_by' => $this->user->id,
            'generated_at' => now()->subMinutes(30),
            'exported_by' => $this->user->id,
            'exported_at' => now()->subMinutes(10),
        ]);

        Model::reguard();

        // Seed valid credentials for NE gateway
        PlatformSetting::instance()->update([
            'dsn' => [
                'ne_siret' => '73282932000074',
                'ne_nom' => 'DUPONT',
                'ne_prenom' => 'Jean',
                'ne_password' => 'SecureP@ss123',
                'ne_environment' => 'sandbox',
            ],
        ]);

        config(['workforce.dsn.submit_enabled' => true]);
        config(['workforce.dsn.max_attempts' => 1]);
        config(['workforce.dsn.backoff_base_seconds' => 0]);
    }

    private function makeUseCase(string $scenario = 'success', int $pendingPolls = 1): SubmitDsnDeclarationUseCase
    {
        $client = new FakeNetEntreprisesClient($scenario, $pendingPolls);
        $gateway = new NetEntreprisesDsnGateway(
            client: $client,
            credentialService: new DsnCredentialService(),
            disk: 'local',
        );

        return new SubmitDsnDeclarationUseCase($gateway, $this->bypassPreflight());
    }

    /**
     * Bypass preflight checks — these tests focus on gateway integration,
     * not preflight validation (covered by DsnPreflightTest).
     */
    private function bypassPreflight(): DsnPreflightChecker
    {
        return new class extends DsnPreflightChecker
        {
            public function check(\App\Core\Workforce\DsnDeclaration $declaration): PreflightResult
            {
                return PreflightResult::ready();
            }
        };
    }

    // ─── Submit: happy path with gateway tracking ───

    public function test_submit_happy_path_persists_gateway_fields(): void
    {
        $uc = $this->makeUseCase('success');
        $result = $uc->execute($this->declaration, $this->user->id);

        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $result->status);
        $this->assertNotNull($result->submission_reference);
        $this->assertStringStartsWith('NE-', $result->submission_reference);
        $this->assertSame('net-entreprises', $result->gateway_driver);
        $this->assertSame('sandbox', $result->gateway_environment);
        $this->assertSame('aee_received', $result->gateway_status);
        $this->assertSame(1, $result->attempt_count);
        $this->assertNotNull($result->next_poll_at);
        $this->assertSame($this->user->id, $result->submitted_by);
        $this->assertNotNull($result->submitted_at);
    }

    public function test_submit_stores_technical_receipt(): void
    {
        $uc = $this->makeUseCase('success');
        $result = $uc->execute($this->declaration, $this->user->id);

        $this->assertNotNull($result->technical_receipt_path);
        $this->assertTrue(
            Storage::disk('local')->exists($result->technical_receipt_path)
        );
    }

    public function test_submit_schedules_first_poll(): void
    {
        $uc = $this->makeUseCase('success');
        $before = now();
        $result = $uc->execute($this->declaration, $this->user->id);

        $this->assertNotNull($result->next_poll_at);
        $expectedDelay = config('workforce.dsn.poll_initial_delay_seconds', 15);
        $this->assertTrue($result->next_poll_at->gte($before->addSeconds($expectedDelay - 1)));
    }

    // ─── Submit: rejection scenarios ───

    public function test_submit_auth_failure_does_not_transition(): void
    {
        $uc = $this->makeUseCase('authentication_failed');

        try {
            $uc->execute($this->declaration, $this->user->id);
            $this->fail('Expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('authentication_failed', $e->getMessage());
        }

        $this->declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_EXPORTED, $this->declaration->status);
        $this->assertNull($this->declaration->submission_reference);
    }

    public function test_submit_network_timeout_retryable_throws(): void
    {
        config(['workforce.dsn.max_attempts' => 1]);
        $uc = $this->makeUseCase('network_timeout');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('failed after 1 attempts');

        $uc->execute($this->declaration, $this->user->id);
    }

    public function test_submit_technical_rejection_does_not_transition(): void
    {
        $uc = $this->makeUseCase('technical_rejection');

        try {
            $uc->execute($this->declaration, $this->user->id);
            $this->fail('Expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('technical_rejection', $e->getMessage());
        }

        $this->declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_EXPORTED, $this->declaration->status);
    }

    // ─── Submit: guards ───

    public function test_submit_idempotent_already_submitted(): void
    {
        $uc = $this->makeUseCase('success');
        $first = $uc->execute($this->declaration, $this->user->id);

        $second = $uc->execute($first, $this->user->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $second->status);
    }

    public function test_submit_idempotent_already_accepted(): void
    {
        $uc = $this->makeUseCase('success');
        $submitted = $uc->execute($this->declaration, $this->user->id);
        $accepted = $uc->markAccepted($submitted, $this->user->id);

        $result = $uc->execute($accepted, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $result->status);
    }

    public function test_submit_blocks_draft_status(): void
    {
        Model::unguard();
        $this->declaration->update(['status' => DsnDeclaration::STATUS_DRAFT]);
        Model::reguard();

        $uc = $this->makeUseCase('success');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("status is 'draft'");

        $uc->execute($this->declaration, $this->user->id);
    }

    public function test_submit_fails_without_credentials(): void
    {
        PlatformSetting::instance()->update(['dsn' => null]);

        $uc = $this->makeUseCase('success');

        try {
            $uc->execute($this->declaration, $this->user->id);
            $this->fail('Expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('credentials_missing', $e->getMessage());
        }

        $this->declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_EXPORTED, $this->declaration->status);
    }

    // ─── Poll: happy path ───

    public function test_poll_pending_updates_tracking(): void
    {
        $uc = $this->makeUseCase('pending_then_accepted', 5);
        $submitted = $uc->execute($this->declaration, $this->user->id);

        $polled = $uc->poll($submitted, $this->user->id);

        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $polled->status);
        $this->assertSame('pending', $polled->gateway_status);
        $this->assertNotNull($polled->last_polled_at);
        $this->assertNotNull($polled->next_poll_at);
    }

    public function test_poll_accepted_transitions_to_accepted(): void
    {
        $uc = $this->makeUseCase('pending_then_accepted', 0);
        $submitted = $uc->execute($this->declaration, $this->user->id);

        $polled = $uc->poll($submitted, $this->user->id);

        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $polled->status);
        $this->assertSame('cco_accepted', $polled->gateway_status);
        $this->assertNotNull($polled->business_report_path);
        $this->assertNotNull($polled->gateway_metadata);
        $this->assertNull($polled->next_poll_at);
    }

    public function test_poll_rejected_transitions_to_rejected(): void
    {
        $uc = $this->makeUseCase('pending_then_rejected', 0);
        $submitted = $uc->execute($this->declaration, $this->user->id);

        $polled = $uc->poll($submitted, $this->user->id);

        $this->assertSame(DsnDeclaration::STATUS_REJECTED, $polled->status);
        $this->assertSame('ban_rejected', $polled->gateway_status);
        $this->assertNotNull($polled->gateway_error_code);
        $this->assertNotNull($polled->gateway_error_message);
        $this->assertNull($polled->next_poll_at);
    }

    public function test_poll_sequence_pending_then_accepted(): void
    {
        $client = FakeNetEntreprisesClient::pendingThenAccepted(2);
        $gateway = new NetEntreprisesDsnGateway(
            client: $client,
            credentialService: new DsnCredentialService(),
            disk: 'local',
        );
        $uc = new SubmitDsnDeclarationUseCase($gateway, $this->bypassPreflight());

        $submitted = $uc->execute($this->declaration, $this->user->id);

        // Poll 1 → pending
        $p1 = $uc->poll($submitted, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $p1->status);
        $this->assertSame('pending', $p1->gateway_status);

        // Poll 2 → still pending
        $p2 = $uc->poll($p1, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $p2->status);

        // Poll 3 → accepted
        $p3 = $uc->poll($p2, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $p3->status);
        $this->assertSame('cco_accepted', $p3->gateway_status);
    }

    // ─── Poll: guards ───

    public function test_poll_noop_on_terminal_accepted(): void
    {
        $uc = $this->makeUseCase('success');
        $submitted = $uc->execute($this->declaration, $this->user->id);
        $accepted = $uc->markAccepted($submitted, $this->user->id);

        $result = $uc->poll($accepted, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $result->status);
    }

    public function test_poll_noop_on_terminal_rejected(): void
    {
        $uc = $this->makeUseCase('success');
        $submitted = $uc->execute($this->declaration, $this->user->id);
        $rejected = $uc->markRejected($submitted, $this->user->id, 'test rejection');

        $result = $uc->poll($rejected, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_REJECTED, $result->status);
    }

    public function test_poll_blocks_exported_status(): void
    {
        $uc = $this->makeUseCase('success');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("status is 'exported'");

        $uc->poll($this->declaration, $this->user->id);
    }

    public function test_poll_blocks_missing_reference(): void
    {
        Model::unguard();
        $this->declaration->update([
            'status' => DsnDeclaration::STATUS_SUBMITTED,
            'submission_reference' => null,
            'submitted_by' => $this->user->id,
            'submitted_at' => now(),
        ]);
        Model::reguard();

        $uc = $this->makeUseCase('success');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no submission_reference');

        $uc->poll($this->declaration, $this->user->id);
    }

    // ─── Poll with NullDsnGateway (dry-run) ───

    public function test_poll_with_null_gateway_auto_accepts(): void
    {
        config(['workforce.dsn.submit_enabled' => false]);

        $uc = new SubmitDsnDeclarationUseCase(new NullDsnGateway(), $this->bypassPreflight());

        // Submit via dry-run
        $submitted = $uc->execute($this->declaration, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $submitted->status);

        // Poll via dry-run → auto-accept
        $polled = $uc->poll($submitted, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $polled->status);
    }

    // ─── Audit ───

    public function test_submit_creates_audit_with_category(): void
    {
        $uc = $this->makeUseCase('success');
        $uc->execute($this->declaration, $this->user->id);

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $this->company->id,
            'action' => 'dsn_declaration.submit_attempt',
        ]);

        $log = \Illuminate\Support\Facades\DB::table('company_audit_logs')
            ->where('action', 'dsn_declaration.submit_attempt')
            ->first();

        $metadata = json_decode($log->metadata, true);
        $this->assertSame('workforce.dsn.submit', $metadata['category']);
        $this->assertSame('success', $metadata['result']);
        $this->assertArrayHasKey('gateway', $metadata);
        $this->assertArrayHasKey('duration_ms', $metadata);
        $this->assertArrayHasKey('retryable', $metadata);
    }

    public function test_poll_creates_audit(): void
    {
        $uc = $this->makeUseCase('pending_then_accepted', 0);
        $submitted = $uc->execute($this->declaration, $this->user->id);
        $uc->poll($submitted, $this->user->id);

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $this->company->id,
            'action' => 'dsn_declaration.poll_attempt',
        ]);
    }

    // ─── Secret safety ───

    public function test_no_secrets_in_audit_logs(): void
    {
        $uc = $this->makeUseCase('success');
        $uc->execute($this->declaration, $this->user->id);

        $logs = \Illuminate\Support\Facades\DB::table('company_audit_logs')
            ->where('company_id', $this->company->id)
            ->get();

        $secretPatterns = ['SecureP@ss', 'ne_password', 'motdepasse', 'jeton'];

        foreach ($logs as $log) {
            $content = $log->metadata . ($log->action ?? '');
            foreach ($secretPatterns as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern, $content,
                    "Secret pattern '{$pattern}' found in audit log"
                );
            }
        }
    }

    // ─── Immutability ───

    public function test_submitted_declaration_core_fields_immutable(): void
    {
        $uc = $this->makeUseCase('success');
        $submitted = $uc->execute($this->declaration, $this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable after submission');

        $submitted->payload_hash = 'tampered';
        $submitted->save();
    }

    public function test_submitted_declaration_gateway_fields_mutable(): void
    {
        $uc = $this->makeUseCase('success');
        $submitted = $uc->execute($this->declaration, $this->user->id);

        // Should NOT throw — gateway tracking fields are allowed
        $submitted->gateway_status = 'pending';
        $submitted->last_polled_at = now();
        $submitted->next_poll_at = now()->addMinutes(5);
        $submitted->save();

        $submitted->refresh();
        $this->assertSame('pending', $submitted->gateway_status);
    }

    // ─── Multi-tenant isolation ───

    public function test_multi_tenant_submit_isolation(): void
    {
        Model::unguard();

        $company2 = Company::withoutGlobalScopes()->create([
            'name' => 'Other Co', 'slug' => 'other-co',
            'jobdomain_key' => 'tech', 'market_key' => 'FR',
            'siret' => '44254064000019', 'naf_code' => '6201Z',
            'address_street' => '1 rue', 'address_postal_code' => '75001',
            'address_city' => 'Paris',
        ]);

        $run2 = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $company2->id,
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'status' => 'exported', 'currency' => 'EUR',
            'employee_count' => 1, 'total_worked_minutes' => 9100,
            'total_gross_cents' => 200000, 'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:other:test',
        ]);

        $filePath2 = "workforce/dsn/{$company2->id}/dsn_other.dsn";
        Storage::disk('local')->put($filePath2, $this->fileContent);

        $decl2 = DsnDeclaration::create([
            'company_id' => $company2->id,
            'payroll_run_id' => $run2->id,
            'declaration_type' => 'monthly', 'period_month' => '2026-04',
            'status' => DsnDeclaration::STATUS_EXPORTED,
            'file_path' => $filePath2,
            'payload_snapshot' => ['test' => true],
            'payload_hash' => hash('sha256', $this->fileContent),
            'generated_by' => $this->user->id, 'generated_at' => now(),
            'exported_by' => $this->user->id, 'exported_at' => now(),
        ]);

        Model::reguard();

        $uc = $this->makeUseCase('success');

        $r1 = $uc->execute($this->declaration, $this->user->id);
        $r2 = $uc->execute($decl2, $this->user->id);

        $this->assertNotSame($r1->submission_reference, $r2->submission_reference);
        $this->assertSame($this->company->id, $r1->company_id);
        $this->assertSame($company2->id, $r2->company_id);
    }
}
