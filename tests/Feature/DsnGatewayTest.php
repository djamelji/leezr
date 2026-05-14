<?php

namespace Tests\Feature;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Dsn\DsnPreflightChecker;
use App\Core\Workforce\Dsn\PreflightResult;
use App\Core\Workforce\Dsn\Gateway\DsnGatewayInterface;
use App\Core\Workforce\Dsn\Gateway\DsnGatewayManager;
use App\Core\Workforce\Dsn\Gateway\DsnSubmissionResult;
use App\Core\Workforce\Dsn\Gateway\FileDsnGateway;
use App\Core\Workforce\Dsn\Gateway\NullDsnGateway;
use App\Core\Workforce\PayrollRun;
use App\Modules\Workforce\UseCases\SubmitDsnDeclarationUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for DSN Gateway system + SubmitDsnDeclarationUseCase.
 *
 * Sprint 6.5 — ADR-523
 */
class DsnGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private DsnDeclaration $declaration;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Storage::fake('local');

        Market::create([
            'key' => 'FR',
            'name' => 'France',
            'currency' => 'EUR',
            'locale' => 'fr-FR',
            'timezone' => 'Europe/Paris',
            'vat_rate_bps' => 2000,
            'dial_code' => '+33',
            'flag_code' => 'fr',
            'flag_svg' => '',
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'DSN Gateway Co',
            'slug' => 'dsn-gateway-co',
            'jobdomain_key' => 'tech',
            'market_key' => 'FR',
            'siret' => '73282932000074',
            'naf_code' => '6201Z',
            'address_street' => '10 rue de la Paix',
            'address_postal_code' => '75002',
            'address_city' => 'Paris',
        ]);

        $this->user = User::create([
            'name' => 'DSN Operator',
            'email' => 'dsn-operator@test.com',
            'password' => bcrypt('password'),
        ]);

        // Create a PayrollRun (needed for FK)
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'status' => 'exported',
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:gateway:test',
        ]);

        // Write a fake DSN file
        $filePath = "workforce/dsn/{$this->company->id}/dsn_test.dsn";
        $fileContent = "S21.G00.06.001,'73282932000074'\nS21.G00.06.002,'6201Z'";
        Storage::disk('local')->put($filePath, $fileContent);

        // Create an exported DsnDeclaration
        $this->declaration = DsnDeclaration::create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-04',
            'status' => DsnDeclaration::STATUS_EXPORTED,
            'file_path' => $filePath,
            'payload_snapshot' => ['test' => true],
            'payload_hash' => hash('sha256', $fileContent),
            'generated_by' => $this->user->id,
            'generated_at' => now()->subMinutes(30),
            'exported_by' => $this->user->id,
            'exported_at' => now()->subMinutes(10),
        ]);

        Model::reguard();

        // Enable real gateway submission (not dry-run) for gateway tests
        config(['workforce.dsn.submit_enabled' => true]);
        config(['workforce.dsn.max_attempts' => 1]); // No retry in gateway tests
    }

    /**
     * Create SubmitDsnDeclarationUseCase with preflight bypassed.
     * Gateway tests focus on gateway behavior, not preflight checks.
     */
    private function makeSubmitUseCase(DsnGatewayInterface $gateway): SubmitDsnDeclarationUseCase
    {
        $bypassPreflight = new class extends DsnPreflightChecker {
            public function check(\App\Core\Workforce\DsnDeclaration $declaration): PreflightResult
            {
                return PreflightResult::ready();
            }
        };

        return new SubmitDsnDeclarationUseCase($gateway, $bypassPreflight);
    }

    // ═══════════════════════════════════════════════════════════════
    // NullDsnGateway
    // ═══════════════════════════════════════════════════════════════

    public function test_null_gateway_returns_success(): void
    {
        $gateway = new NullDsnGateway();
        $result = $gateway->submit('any/path.dsn', [
            'company_id' => 1,
            'period_month' => '2026-04',
        ]);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->reference);
        $this->assertStringStartsWith('NULL-', $result->reference);
        $this->assertNull($result->error);
    }

    public function test_null_gateway_reference_contains_metadata(): void
    {
        $gateway = new NullDsnGateway();
        $result = $gateway->submit('test.dsn', [
            'company_id' => 42,
            'period_month' => '2026-03',
        ]);

        $this->assertStringContainsString('42', $result->reference);
        $this->assertStringContainsString('2026-03', $result->reference);
    }

    // ═══════════════════════════════════════════════════════════════
    // FileDsnGateway
    // ═══════════════════════════════════════════════════════════════

    public function test_file_gateway_copies_file(): void
    {
        $gateway = new FileDsnGateway('local', 'workforce/dsn/transmitted');
        $result = $gateway->submit($this->declaration->file_path, [
            'company_id' => $this->company->id,
            'period_month' => '2026-04',
        ]);

        $this->assertTrue($result->success);
        $this->assertStringStartsWith('FILE-', $result->reference);

        // Verify file was copied
        $targetPath = $result->rawResponse['target_path'];
        $this->assertTrue(Storage::disk('local')->exists($targetPath));

        // Verify content matches
        $original = Storage::disk('local')->get($this->declaration->file_path);
        $copy = Storage::disk('local')->get($targetPath);
        $this->assertSame($original, $copy);
    }

    public function test_file_gateway_fails_when_source_missing(): void
    {
        $gateway = new FileDsnGateway('local', 'workforce/dsn/transmitted');
        $result = $gateway->submit('nonexistent/file.dsn', [
            'company_id' => 1,
            'period_month' => '2026-04',
        ]);

        $this->assertFalse($result->success);
        $this->assertNull($result->reference);
        $this->assertStringContainsString('not found', $result->error);
    }

    // ═══════════════════════════════════════════════════════════════
    // DsnSubmissionResult
    // ═══════════════════════════════════════════════════════════════

    public function test_submission_result_success_factory(): void
    {
        $result = DsnSubmissionResult::success('REF-123', ['key' => 'val']);

        $this->assertTrue($result->success);
        $this->assertSame('REF-123', $result->reference);
        $this->assertNull($result->error);
        $this->assertSame(['key' => 'val'], $result->rawResponse);
    }

    public function test_submission_result_failure_factory(): void
    {
        $result = DsnSubmissionResult::failure('Connection refused', ['code' => 500]);

        $this->assertFalse($result->success);
        $this->assertNull($result->reference);
        $this->assertSame('Connection refused', $result->error);
        $this->assertSame(['code' => 500], $result->rawResponse);
    }

    // ═══════════════════════════════════════════════════════════════
    // DsnGatewayManager
    // ═══════════════════════════════════════════════════════════════

    public function test_manager_default_driver_is_null(): void
    {
        config(['workforce.dsn.gateway' => 'null']);
        $manager = app(DsnGatewayManager::class);

        $this->assertInstanceOf(NullDsnGateway::class, $manager->driver());
    }

    public function test_manager_file_driver(): void
    {
        config(['workforce.dsn.gateway' => 'file']);
        // Force a fresh instance
        $this->app->forgetInstance(DsnGatewayManager::class);
        $manager = app(DsnGatewayManager::class);

        $this->assertInstanceOf(FileDsnGateway::class, $manager->driver('file'));
    }

    public function test_interface_resolves_via_container(): void
    {
        config(['workforce.dsn.gateway' => 'null']);
        $gateway = app(DsnGatewayInterface::class);

        $this->assertInstanceOf(NullDsnGateway::class, $gateway);
    }

    // ═══════════════════════════════════════════════════════════════
    // SubmitDsnDeclarationUseCase — happy path
    // ═══════════════════════════════════════════════════════════════

    public function test_submit_happy_path(): void
    {
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());
        $result = $useCase->execute($this->declaration, $this->user->id);

        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $result->status);
        $this->assertNotNull($result->submission_reference);
        $this->assertStringStartsWith('NULL-', $result->submission_reference);
        $this->assertSame($this->user->id, $result->submitted_by);
        $this->assertNotNull($result->submitted_at);
    }

    public function test_submit_persists_to_database(): void
    {
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());
        $useCase->execute($this->declaration, $this->user->id);

        $fresh = DsnDeclaration::withoutGlobalScopes()->find($this->declaration->id);
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $fresh->status);
        $this->assertNotNull($fresh->submission_reference);
        $this->assertNotNull($fresh->submitted_at);
        $this->assertSame($this->user->id, $fresh->submitted_by);
    }

    public function test_submit_creates_audit_log(): void
    {
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());
        $useCase->execute($this->declaration, $this->user->id);

        $log = CompanyAuditLog::where('company_id', $this->company->id)
            ->where('action', 'dsn_declaration.submit_attempt')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->user->id, $log->actor_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // SubmitDsnDeclarationUseCase — guards
    // ═══════════════════════════════════════════════════════════════

    public function test_submit_blocks_draft_status(): void
    {
        Model::unguard();
        $this->declaration->update(['status' => DsnDeclaration::STATUS_DRAFT]);
        Model::reguard();

        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("status is 'draft'");
        $useCase->execute($this->declaration, $this->user->id);
    }

    public function test_submit_blocks_validated_status(): void
    {
        Model::unguard();
        $this->declaration->update(['status' => DsnDeclaration::STATUS_VALIDATED]);
        Model::reguard();

        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("status is 'validated'");
        $useCase->execute($this->declaration, $this->user->id);
    }

    public function test_submit_blocks_missing_file_path(): void
    {
        Model::unguard();
        $this->declaration->update(['file_path' => null]);
        Model::reguard();

        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no DSN file path');
        $useCase->execute($this->declaration, $this->user->id);
    }

    // ═══════════════════════════════════════════════════════════════
    // SubmitDsnDeclarationUseCase — idempotency
    // ═══════════════════════════════════════════════════════════════

    public function test_submit_idempotent_already_submitted(): void
    {
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());

        // First submit
        $first = $useCase->execute($this->declaration, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $first->status);
        $ref = $first->submission_reference;

        // Second submit — idempotent, returns same
        $second = $useCase->execute($this->declaration, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $second->status);
        $this->assertSame($ref, $second->submission_reference);
    }

    public function test_submit_idempotent_already_accepted(): void
    {
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());

        // Submit then accept
        $useCase->execute($this->declaration, $this->user->id);
        $useCase->markAccepted($this->declaration, $this->user->id);

        // Re-submit — returns accepted as-is
        $result = $useCase->execute($this->declaration, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $result->status);
    }

    // ═══════════════════════════════════════════════════════════════
    // SubmitDsnDeclarationUseCase — gateway failure
    // ═══════════════════════════════════════════════════════════════

    public function test_submit_gateway_failure_throws_and_keeps_exported(): void
    {
        $failingGateway = new class implements DsnGatewayInterface {
            public function submit(string $filePath, array $metadata): DsnSubmissionResult
            {
                return DsnSubmissionResult::failure('Network error');
            }
        };

        $useCase = $this->makeSubmitUseCase($failingGateway);

        try {
            $useCase->execute($this->declaration, $this->user->id);
            $this->fail('Expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Network error', $e->getMessage());
        }

        // Declaration stays exported — can retry
        $fresh = DsnDeclaration::withoutGlobalScopes()->find($this->declaration->id);
        $this->assertSame(DsnDeclaration::STATUS_EXPORTED, $fresh->status);
        $this->assertNull($fresh->submission_reference);
    }

    // ═══════════════════════════════════════════════════════════════
    // SubmitDsnDeclarationUseCase — accepted/rejected transitions
    // ═══════════════════════════════════════════════════════════════

    public function test_mark_accepted_transitions_from_submitted(): void
    {
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());
        $useCase->execute($this->declaration, $this->user->id);

        $result = $useCase->markAccepted($this->declaration, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $result->status);

        // Verify persisted
        $fresh = DsnDeclaration::withoutGlobalScopes()->find($this->declaration->id);
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $fresh->status);

        // Verify audit log
        $log = CompanyAuditLog::where('action', 'dsn_declaration.accepted')->first();
        $this->assertNotNull($log);
    }

    public function test_mark_rejected_transitions_from_submitted(): void
    {
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());
        $useCase->execute($this->declaration, $this->user->id);

        $result = $useCase->markRejected($this->declaration, $this->user->id, 'Invalid SIRET');
        $this->assertSame(DsnDeclaration::STATUS_REJECTED, $result->status);

        // Verify persisted
        $fresh = DsnDeclaration::withoutGlobalScopes()->find($this->declaration->id);
        $this->assertSame(DsnDeclaration::STATUS_REJECTED, $fresh->status);

        // Verify audit log with reason
        $log = CompanyAuditLog::where('action', 'dsn_declaration.rejected')->first();
        $this->assertNotNull($log);
        $this->assertSame('Invalid SIRET', $log->metadata['reason']);
    }

    public function test_mark_accepted_idempotent(): void
    {
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());
        $useCase->execute($this->declaration, $this->user->id);
        $useCase->markAccepted($this->declaration, $this->user->id);

        // Call again — idempotent
        $result = $useCase->markAccepted($this->declaration, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $result->status);
    }

    public function test_mark_rejected_blocks_from_exported(): void
    {
        // Cannot reject directly from exported (must submit first)
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());

        $this->expectException(\DomainException::class);
        $useCase->markRejected($this->declaration, $this->user->id);
    }

    // ═══════════════════════════════════════════════════════════════
    // DsnDeclaration — extended state machine
    // ═══════════════════════════════════════════════════════════════

    public function test_extended_state_machine_full_lifecycle(): void
    {
        Model::unguard();
        $run2 = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'validated',
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:gateway:lifecycle',
        ]);

        $decl = DsnDeclaration::create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run2->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-03',
            'status' => DsnDeclaration::STATUS_DRAFT,
            'generated_by' => $this->user->id,
            'generated_at' => now(),
        ]);
        Model::reguard();

        // draft → validated
        $this->assertTrue($decl->canTransitionTo(DsnDeclaration::STATUS_VALIDATED));
        $this->assertFalse($decl->canTransitionTo(DsnDeclaration::STATUS_EXPORTED));
        $decl->transitionTo(DsnDeclaration::STATUS_VALIDATED);
        $this->assertSame(DsnDeclaration::STATUS_VALIDATED, $decl->status);

        // validated → exported
        $this->assertTrue($decl->canTransitionTo(DsnDeclaration::STATUS_EXPORTED));
        $this->assertFalse($decl->canTransitionTo(DsnDeclaration::STATUS_SUBMITTED));
        $decl->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $this->assertSame(DsnDeclaration::STATUS_EXPORTED, $decl->status);

        // exported → submitted
        $this->assertTrue($decl->canTransitionTo(DsnDeclaration::STATUS_SUBMITTED));
        $this->assertFalse($decl->canTransitionTo(DsnDeclaration::STATUS_ACCEPTED));
        $decl->transitionTo(DsnDeclaration::STATUS_SUBMITTED);
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $decl->status);

        // submitted → accepted
        $this->assertTrue($decl->canTransitionTo(DsnDeclaration::STATUS_ACCEPTED));
        $this->assertTrue($decl->canTransitionTo(DsnDeclaration::STATUS_REJECTED));
        $this->assertFalse($decl->canTransitionTo(DsnDeclaration::STATUS_EXPORTED));
        $decl->transitionTo(DsnDeclaration::STATUS_ACCEPTED);
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $decl->status);

        // accepted → nothing
        $this->assertFalse($decl->canTransitionTo(DsnDeclaration::STATUS_SUBMITTED));
        $this->assertTrue($decl->isTerminal());
    }

    public function test_submitted_immutability_blocks_arbitrary_updates(): void
    {
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());
        $useCase->execute($this->declaration, $this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        $this->declaration->file_path = 'hacked/path.dsn';
        $this->declaration->save();
    }

    public function test_accepted_immutability_blocks_updates(): void
    {
        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());
        $useCase->execute($this->declaration, $this->user->id);
        $useCase->markAccepted($this->declaration, $this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->declaration->period_month = '2099-01';
        $this->declaration->save();
    }

    // ═══════════════════════════════════════════════════════════════
    // No mutation of payload
    // ═══════════════════════════════════════════════════════════════

    public function test_submit_does_not_mutate_payload(): void
    {
        $snapshotBefore = $this->declaration->payload_snapshot;
        $hashBefore = $this->declaration->payload_hash;
        $fileBefore = $this->declaration->file_path;

        $useCase = $this->makeSubmitUseCase(new NullDsnGateway());
        $useCase->execute($this->declaration, $this->user->id);

        $fresh = DsnDeclaration::withoutGlobalScopes()->find($this->declaration->id);
        $this->assertSame($snapshotBefore, $fresh->payload_snapshot);
        $this->assertSame($hashBefore, $fresh->payload_hash);
        $this->assertSame($fileBefore, $fresh->file_path);
    }

    // ═══════════════════════════════════════════════════════════════
    // File gateway with submit use case
    // ═══════════════════════════════════════════════════════════════

    public function test_file_gateway_integration_with_submit(): void
    {
        $gateway = new FileDsnGateway('local', 'workforce/dsn/transmitted');
        $useCase = $this->makeSubmitUseCase($gateway);

        $result = $useCase->execute($this->declaration, $this->user->id);

        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $result->status);
        $this->assertStringStartsWith('FILE-', $result->submission_reference);

        // Verify transmitted copy exists
        $transmittedFiles = Storage::disk('local')->files('workforce/dsn/transmitted');
        $this->assertCount(1, $transmittedFiles);
    }
}
