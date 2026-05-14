<?php

namespace Tests\Feature;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Dsn\DsnPreflightChecker;
use App\Core\Workforce\Dsn\DsnSubmissionLockService;
use App\Core\Workforce\Dsn\PreflightResult;
use App\Core\Workforce\Dsn\Gateway\DsnGatewayInterface;
use App\Core\Workforce\Dsn\Gateway\DsnSubmissionResult;
use App\Core\Workforce\Dsn\Gateway\NullDsnGateway;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ExportPayrollDsnUseCase;
use App\Modules\Workforce\UseCases\SubmitDsnDeclarationUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 6.8 — DSN Pre-Flight & Safety Layer.
 *
 * Tests: preflight checks, submission lock, retry policy,
 * hash integrity, dry-run, enriched audit, multi-tenant isolation.
 *
 * ADR-526
 */
class DsnPreflightTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private PayrollRun $payrollRun;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

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
            'name' => 'Preflight Co',
            'slug' => 'preflight-co',
            'jobdomain_key' => 'tech',
            'market_key' => 'FR',
            'siret' => '73282932000074',
            'naf_code' => '6201Z',
            'address_street' => '15 rue de la Paix',
            'address_postal_code' => '75002',
            'address_city' => 'Paris',
            'address_country_code' => 'FR',
            'average_headcount' => 25,
        ]);

        $this->user = User::create([
            'name' => 'Preflight Op',
            'email' => 'op@preflight.test',
            'password' => bcrypt('password'),
        ]);

        Model::reguard();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. Preflight checks
    // ═══════════════════════════════════════════════════════════════

    public function test_preflight_ok_allows_submit(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        // Transition to exported
        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        $checker = new DsnPreflightChecker();
        $result = $checker->check($declaration);

        $this->assertTrue($result->isReady);
        $this->assertEmpty($result->blockingErrors);
    }

    public function test_preflight_ko_blocks_submit(): void
    {
        Model::unguard();
        $declaration = $this->createMinimalDeclaration([
            'status' => DsnDeclaration::STATUS_EXPORTED,
            'payload_snapshot' => ['company' => ['siret' => '00000000000000'], 'employees' => []],
        ]);
        Model::reguard();

        $checker = new DsnPreflightChecker();
        $result = $checker->check($declaration);

        $this->assertFalse($result->isReady);
        $this->assertNotEmpty($result->blockingErrors);
    }

    public function test_preflight_blocks_non_exported_status(): void
    {
        Model::unguard();
        $declaration = $this->createMinimalDeclaration(['status' => DsnDeclaration::STATUS_VALIDATED]);
        Model::reguard();

        $checker = new DsnPreflightChecker();
        $result = $checker->check($declaration);

        $this->assertFalse($result->isReady);
        $this->assertTrue(
            collect($result->blockingErrors)->contains(fn ($e) => str_contains($e, 'exported'))
        );
    }

    public function test_preflight_blocks_missing_employees(): void
    {
        Model::unguard();
        Storage::fake('local');
        $filePath = 'workforce/dsn/test_preflight.dsn';
        Storage::disk('local')->put($filePath, 'test');
        $snapshot = ['company' => ['siret' => '73282932000074'], 'employees' => []];

        $declaration = $this->createMinimalDeclaration([
            'status' => DsnDeclaration::STATUS_EXPORTED,
            'file_path' => $filePath,
            'payload_snapshot' => $snapshot,
            'payload_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE)),
        ]);
        Model::reguard();

        $checker = new DsnPreflightChecker();
        $result = $checker->check($declaration);

        $this->assertFalse($result->isReady);
        $this->assertTrue(
            collect($result->blockingErrors)->contains(fn ($e) => str_contains($e, 'No employees'))
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. Submission lock (anti-double submit)
    // ═══════════════════════════════════════════════════════════════

    public function test_double_submit_blocked_by_lock(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => true, 'workforce.dsn.max_attempts' => 1]);

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        // Manually acquire lock
        $lockService = new DsnSubmissionLockService();
        $acquired = $lockService->acquire($declaration->id);
        $this->assertTrue($acquired);

        // Second attempt should be blocked
        $submitUC = new SubmitDsnDeclarationUseCase(new NullDsnGateway());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('locked');
        $submitUC->execute($declaration, $this->user->id);
    }

    public function test_lock_expires_allows_retry(): void
    {
        $lockService = new DsnSubmissionLockService();

        Model::unguard();
        $declaration = $this->createMinimalDeclaration();
        Model::reguard();

        // Acquire lock with very short TTL
        $acquired = $lockService->acquire($declaration->id, 1);
        $this->assertTrue($acquired);
        $this->assertTrue($lockService->isLocked($declaration->id));

        // Wait for expiry
        sleep(2);

        // Should be able to re-acquire
        $reacquired = $lockService->acquire($declaration->id);
        $this->assertTrue($reacquired);
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. Retry policy
    // ═══════════════════════════════════════════════════════════════

    public function test_retry_on_timeout_exception(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => true, 'workforce.dsn.max_attempts' => 3, 'workforce.dsn.backoff_base_seconds' => 0]);

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        // Gateway that throws on first 2 calls, succeeds on 3rd
        $attemptCounter = 0;
        $retryGateway = new class($attemptCounter) implements DsnGatewayInterface {
            private int $callCount = 0;

            public function __construct(private int &$counter) {}

            public function submit(string $filePath, array $metadata): DsnSubmissionResult
            {
                $this->callCount++;
                $this->counter = $this->callCount;

                if ($this->callCount < 3) {
                    throw new \RuntimeException('Connection timeout');
                }

                return DsnSubmissionResult::success('RETRY-OK-' . $this->callCount);
            }
        };

        $submitUC = new SubmitDsnDeclarationUseCase($retryGateway);
        $result = $submitUC->execute($declaration, $this->user->id);

        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $result->status);
        $this->assertStringStartsWith('RETRY-OK-', $result->submission_reference);
        $this->assertEquals(3, $attemptCounter);
    }

    public function test_retry_stops_after_max_attempts(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => true, 'workforce.dsn.max_attempts' => 2, 'workforce.dsn.backoff_base_seconds' => 0]);

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        // Gateway that always fails
        $alwaysFail = new class implements DsnGatewayInterface {
            public function submit(string $filePath, array $metadata): DsnSubmissionResult
            {
                return DsnSubmissionResult::failure('Server unavailable');
            }
        };

        $submitUC = new SubmitDsnDeclarationUseCase($alwaysFail);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('failed after 2 attempts');
        $submitUC->execute($declaration, $this->user->id);
    }

    public function test_business_error_no_retry(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => true, 'workforce.dsn.max_attempts' => 3, 'workforce.dsn.backoff_base_seconds' => 0]);

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        // Gateway that returns business rejection
        $bizReject = new class implements DsnGatewayInterface {
            public function submit(string $filePath, array $metadata): DsnSubmissionResult
            {
                return DsnSubmissionResult::failure('SIRET inconnu', ['status' => 'rejected', 'error_code' => 'BIZ_SIRET']);
            }
        };

        $submitUC = new SubmitDsnDeclarationUseCase($bizReject);

        try {
            $submitUC->execute($declaration, $this->user->id);
            $this->fail('Expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('business error', $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. Hash integrity
    // ═══════════════════════════════════════════════════════════════

    public function test_hash_mismatch_throws_tampered(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => true]);

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();

        // Tamper with hash
        Model::unguard();
        $declaration->payload_hash = 'tampered_hash_value_0000000000000000000000000000000000';
        $declaration->save();
        Model::reguard();

        $submitUC = new SubmitDsnDeclarationUseCase(new NullDsnGateway());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('dsn_payload_tampered');
        $submitUC->execute($declaration, $this->user->id);
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. Dry-run
    // ═══════════════════════════════════════════════════════════════

    public function test_dry_run_uses_null_gateway(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => false]); // dry-run

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        // Even with a "failing" gateway injected, dry-run forces NullDsnGateway
        $failGateway = new class implements DsnGatewayInterface {
            public function submit(string $filePath, array $metadata): DsnSubmissionResult
            {
                return DsnSubmissionResult::failure('Should not be called');
            }
        };

        $submitUC = new SubmitDsnDeclarationUseCase($failGateway);
        $result = $submitUC->execute($declaration, $this->user->id);

        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $result->status);
        $this->assertStringStartsWith('NULL-', $result->submission_reference);
    }

    // ═══════════════════════════════════════════════════════════════
    // 6. Audit enrichi
    // ═══════════════════════════════════════════════════════════════

    public function test_audit_log_created_per_attempt(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => true, 'workforce.dsn.max_attempts' => 1]);

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        $submitUC = new SubmitDsnDeclarationUseCase(new NullDsnGateway());
        $submitUC->execute($declaration, $this->user->id);

        $logs = CompanyAuditLog::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('action', 'dsn_declaration.submit_attempt')
            ->get();

        $this->assertGreaterThanOrEqual(1, $logs->count());

        $log = $logs->first();
        $this->assertArrayHasKey('attempt_number', $log->metadata);
        $this->assertArrayHasKey('duration_ms', $log->metadata);
        $this->assertArrayHasKey('gateway', $log->metadata);
        $this->assertArrayHasKey('result', $log->metadata);
        $this->assertEquals('success', $log->metadata['result']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 7. Payload immutable
    // ═══════════════════════════════════════════════════════════════

    public function test_payload_immutable_after_submission(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => true, 'workforce.dsn.max_attempts' => 1]);

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        $snapshotBefore = $declaration->payload_snapshot;
        $hashBefore = $declaration->payload_hash;

        $submitUC = new SubmitDsnDeclarationUseCase(new NullDsnGateway());
        $submitted = $submitUC->execute($declaration, $this->user->id);

        $fresh = DsnDeclaration::withoutGlobalScopes()->find($submitted->id);
        $this->assertSame($snapshotBefore, $fresh->payload_snapshot);
        $this->assertSame($hashBefore, $fresh->payload_hash);
    }

    // ═══════════════════════════════════════════════════════════════
    // 8. Multi-tenant isolation
    // ═══════════════════════════════════════════════════════════════

    public function test_multi_tenant_lock_isolation(): void
    {
        $lockService = new DsnSubmissionLockService();

        Model::unguard();
        $declaration1 = $this->createMinimalDeclaration();

        // Create second company
        $company2 = Company::withoutGlobalScopes()->create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'jobdomain_key' => 'tech',
            'market_key' => 'FR',
        ]);
        $run2 = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $company2->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => PayrollRun::STATUS_VALIDATED,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:mt:' . uniqid(),
        ]);
        $declaration2 = DsnDeclaration::withoutGlobalScopes()->create([
            'company_id' => $company2->id,
            'payroll_run_id' => $run2->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-01',
            'status' => 'exported',
            'generated_by' => $this->user->id,
            'generated_at' => now(),
        ]);
        Model::reguard();

        // Lock declaration 1
        $lockService->acquire($declaration1->id);
        $this->assertTrue($lockService->isLocked($declaration1->id));

        // Declaration 2 should NOT be locked
        $this->assertFalse($lockService->isLocked($declaration2->id));

        // Can lock declaration 2 independently
        $this->assertTrue($lockService->acquire($declaration2->id));
    }

    // ═══════════════════════════════════════════════════════════════
    // 9. Sprint 6.9 — Audit gap tests
    // ═══════════════════════════════════════════════════════════════

    public function test_lock_released_on_gateway_exception(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => true, 'workforce.dsn.max_attempts' => 1, 'workforce.dsn.backoff_base_seconds' => 0]);

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        // Gateway that always throws
        $explodingGateway = new class implements DsnGatewayInterface {
            public function submit(string $filePath, array $metadata): DsnSubmissionResult
            {
                throw new \RuntimeException('Simulated network failure');
            }
        };

        $lockService = new DsnSubmissionLockService();
        $submitUC = new SubmitDsnDeclarationUseCase($explodingGateway, null, $lockService);

        try {
            $submitUC->execute($declaration, $this->user->id);
            $this->fail('Expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('failed after 1 attempts', $e->getMessage());
        }

        // Lock MUST be released after exception (finally block)
        $this->assertFalse($lockService->isLocked($declaration->id));
    }

    public function test_no_payroll_mutation_during_export(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        $runBefore = PayrollRun::withoutGlobalScopes()->find($this->payrollRun->id);
        $statusBefore = $runBefore->status;
        $grossBefore = $runBefore->total_gross_cents;

        $calcsBefore = PayrollCalculation::withoutGlobalScopes()
            ->whereHas('payrollLine', fn ($q) => $q->where('payroll_run_id', $this->payrollRun->id))
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'status' => $c->status, 'gross' => $c->gross_total_cents])
            ->toArray();

        // Export DSN
        $useCase = new ExportPayrollDsnUseCase();
        $useCase->execute($this->payrollRun, $this->user->id);

        // Verify PayrollRun unchanged
        $runAfter = PayrollRun::withoutGlobalScopes()->find($this->payrollRun->id);
        $this->assertSame($statusBefore, $runAfter->status);
        $this->assertSame($grossBefore, $runAfter->total_gross_cents);

        // Verify PayrollCalculation unchanged
        $calcsAfter = PayrollCalculation::withoutGlobalScopes()
            ->whereHas('payrollLine', fn ($q) => $q->where('payroll_run_id', $this->payrollRun->id))
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'status' => $c->status, 'gross' => $c->gross_total_cents])
            ->toArray();

        $this->assertSame($calcsBefore, $calcsAfter);
    }

    public function test_concurrent_lock_acquire_handles_unique_violation(): void
    {
        $lockService = new DsnSubmissionLockService();

        Model::unguard();
        $declaration = $this->createMinimalDeclaration();
        Model::reguard();

        // First acquire succeeds
        $first = $lockService->acquire($declaration->id);
        $this->assertTrue($first);

        // Second acquire on same id returns false (not exception)
        $second = $lockService->acquire($declaration->id);
        $this->assertFalse($second);

        // Still locked
        $this->assertTrue($lockService->isLocked($declaration->id));
    }

    public function test_rejected_regenerate_reexport_full_flow(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => true, 'workforce.dsn.max_attempts' => 1]);

        $exportUC = new ExportPayrollDsnUseCase();

        // 1. Export → declaration in validated status
        $declaration = $exportUC->execute($this->payrollRun, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_VALIDATED, $declaration->status);

        // 2. Transition to exported → submitted → rejected
        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        $submitUC = new SubmitDsnDeclarationUseCase(new NullDsnGateway());
        $submitted = $submitUC->execute($declaration, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $submitted->status);

        $submitUC->markRejected($submitted, $this->user->id, 'SIRET inconnu');
        $rejected = DsnDeclaration::withoutGlobalScopes()->find($submitted->id);
        $this->assertSame(DsnDeclaration::STATUS_REJECTED, $rejected->status);

        // 3. canRegenerate should be true
        $this->assertTrue($rejected->canRegenerate());

        // 4. Re-export (old declaration deleted, new one created)
        $newDeclaration = $exportUC->execute($this->payrollRun, $this->user->id);
        $this->assertSame(DsnDeclaration::STATUS_VALIDATED, $newDeclaration->status);
        $this->assertNotEquals($rejected->id, $newDeclaration->id);

        // 5. Old declaration should be deleted
        $this->assertNull(DsnDeclaration::withoutGlobalScopes()->find($rejected->id));
    }

    public function test_full_audit_timeline_export_submit_accept(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();
        config(['workforce.dsn.submit_enabled' => true, 'workforce.dsn.max_attempts' => 1]);

        // Export
        $exportUC = new ExportPayrollDsnUseCase();
        $declaration = $exportUC->execute($this->payrollRun, $this->user->id);

        // Transition to exported
        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        // Submit
        $submitUC = new SubmitDsnDeclarationUseCase(new NullDsnGateway());
        $submitted = $submitUC->execute($declaration, $this->user->id);

        // Accept
        $submitUC->markAccepted($submitted, $this->user->id);

        // Verify full audit timeline
        $logs = \App\Core\Audit\CompanyAuditLog::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('target_type', 'dsn_declaration')
            ->where('target_id', (string) $declaration->id)
            ->orderBy('id')
            ->pluck('action')
            ->toArray();

        $this->assertContains('dsn_declaration.generated', $logs);
        $this->assertContains('dsn_declaration.submit_attempt', $logs);
        $this->assertContains('dsn_declaration.accepted', $logs);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createMinimalDeclaration(array $overrides = []): DsnDeclaration
    {
        static $counter = 0;
        $counter++;

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => PayrollRun::STATUS_VALIDATED,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:pf:' . $counter . ':' . uniqid(),
        ]);

        return DsnDeclaration::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-01',
            'status' => 'draft',
            'generated_by' => $this->user->id,
            'generated_at' => now(),
        ], $overrides));
    }

    private function seedFullPayrollFixtures(): void
    {
        Model::unguard();

        \App\Core\Fields\FieldDefinitionCatalog::sync();

        $employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'email' => 'marie@preflight.test',
            'employee_number' => 'EMP-PF-001',
            'hire_date' => '2024-01-15',
            'status' => 'active',
        ]);

        $contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2024-01-15',
            'is_current' => true,
        ]);

        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $contract->id,
            'base_salary_cents' => 300000,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2024-01-15',
        ]);

        $fieldValues = [
            'social_security_number' => '2 85 12 75 108 042 29',
            'gender' => 'F',
            'birth_date' => '1985-12-15',
            'birth_city' => 'Paris',
            'birth_department' => '75',
            'birth_country' => 'FR',
            'nationality' => 'FR',
            'personal_address_street' => '15 rue de la Paix',
            'personal_address_postal_code' => '75001',
            'personal_address_city' => 'Paris',
            'personal_address_country_code' => 'FR',
        ];

        foreach ($fieldValues as $code => $value) {
            $fieldDef = FieldDefinition::whereNull('company_id')
                ->where('code', $code)
                ->first();

            if ($fieldDef) {
                FieldValue::create([
                    'field_definition_id' => $fieldDef->id,
                    'model_type' => 'App\\Core\\Models\\User',
                    'model_id' => $this->user->id,
                    'value' => ['value' => $value],
                ]);
            }
        }

        $this->payrollRun = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => PayrollRun::STATUS_VALIDATED,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:pf-full:' . uniqid(),
        ]);

        $timesheet = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
        ]);

        $line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $this->payrollRun->id,
            'employee_id' => $employee->id,
            'timesheet_period_id' => $timesheet->id,
            'worked_minutes' => 9100,
            'break_minutes' => 0,
            'daily_overtime_minutes' => 0,
            'weekly_overtime_minutes' => 0,
            'total_overtime_minutes' => 0,
            'planned_minutes' => 9100,
            'leave_days_hundredths' => 0,
            'paid_leave_days_hundredths' => 0,
            'unpaid_leave_days_hundredths' => 0,
            'leave_minutes' => 0,
            'base_salary_cents' => 300000,
            'overtime_rate_bps' => 2500,
            'gross_basis_cents' => 300000,
            'gross_breakdown' => [
                'base_salary_cents' => 300000,
                'overtime_cents' => 0,
                'base_hours' => 151.67,
                'total_hours' => 151.67,
            ],
            'compensation_snapshot' => [
                'base_salary_cents' => 300000,
                'overtime_rate_bps' => 2500,
                'currency' => 'EUR',
                'pay_frequency' => 'monthly',
                'contract_id' => $contract->id,
                'weekly_hours' => 35,
                'benefits' => [],
            ],
            'timesheet_snapshot' => ['total_worked_minutes' => 9100],
        ]);

        PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_line_id' => $line->id,
            'status' => PayrollCalculation::STATUS_VALIDATED,
            'rule_version' => 'payroll-calc-v2',
            'gross_total_cents' => 300000,
            'plafond_ss_monthly_cents' => 383400,
            'contributions_employee_cents' => 67800,
            'contributions_employer_cents' => 128700,
            'total_cost_employer_cents' => 428700,
            'taxable_income_cents' => 232200,
            'tax_breakdown' => ['tax_rate_bps' => 1150],
            'tax_cents' => 26703,
            'net_before_tax_cents' => 232200,
            'net_payable_cents' => 205497,
            'net_social_cents' => 218000,
            'benefits_cents' => 0,
            'deductions_cents' => 0,
            'contribution_lines' => [
                ['code' => 'urssaf_maladie', 'label' => 'Maladie', 'category' => 'social', 'base_type' => 'deplafonne', 'base_cents' => 300000, 'employee_rate_bps' => 0, 'employer_rate_bps' => 700, 'employee_cents' => 0, 'employer_cents' => 21000],
                ['code' => 'urssaf_vieillesse_plaf', 'label' => 'Vieillesse plaf.', 'category' => 'social', 'base_type' => 'plafonnee', 'base_cents' => 300000, 'employee_rate_bps' => 690, 'employer_rate_bps' => 855, 'employee_cents' => 20700, 'employer_cents' => 25650],
                ['code' => 'urssaf_vieillesse_deplaf', 'label' => 'Vieillesse déplaf.', 'category' => 'social', 'base_type' => 'deplafonne', 'base_cents' => 300000, 'employee_rate_bps' => 40, 'employer_rate_bps' => 185, 'employee_cents' => 1200, 'employer_cents' => 5550],
                ['code' => 'allocations_familiales', 'label' => 'AF', 'category' => 'social', 'base_type' => 'deplafonne', 'base_cents' => 300000, 'employee_rate_bps' => 0, 'employer_rate_bps' => 530, 'employee_cents' => 0, 'employer_cents' => 15900],
                ['code' => 'retraite_t1', 'label' => 'Retraite T1', 'category' => 'retraite', 'base_type' => 'plafonnee', 'base_cents' => 300000, 'employee_rate_bps' => 390, 'employer_rate_bps' => 630, 'employee_cents' => 11700, 'employer_cents' => 18900],
                ['code' => 'ceg_t1', 'label' => 'CEG T1', 'category' => 'retraite', 'base_type' => 'plafonnee', 'base_cents' => 300000, 'employee_rate_bps' => 85, 'employer_rate_bps' => 150, 'employee_cents' => 2550, 'employer_cents' => 4500],
                ['code' => 'chomage', 'label' => 'Chômage', 'category' => 'social', 'base_type' => 'plafonnee', 'base_cents' => 300000, 'employee_rate_bps' => 0, 'employer_rate_bps' => 420, 'employee_cents' => 0, 'employer_cents' => 12600],
                ['code' => 'csg_deductible', 'label' => 'CSG déductible', 'category' => 'csg', 'base_type' => 'csg', 'base_cents' => 294750, 'employee_rate_bps' => 681, 'employer_rate_bps' => 0, 'employee_cents' => 20073, 'employer_cents' => 0],
                ['code' => 'csg_non_deductible', 'label' => 'CSG non déductible', 'category' => 'csg', 'base_type' => 'csg', 'base_cents' => 294750, 'employee_rate_bps' => 240, 'employer_rate_bps' => 0, 'employee_cents' => 7074, 'employer_cents' => 0],
                ['code' => 'crds', 'label' => 'CRDS', 'category' => 'csg', 'base_type' => 'csg', 'base_cents' => 294750, 'employee_rate_bps' => 50, 'employer_rate_bps' => 0, 'employee_cents' => 1474, 'employer_cents' => 0],
            ],
            'relief_lines' => ['total_employer_relief_cents' => 0, 'lines' => []],
            'blocking_anomalies' => [],
            'calculation_snapshot' => ['snapshot_version' => 'calc-snapshot-v3'],
            'calculated_at' => now(),
        ]);

        app()->instance('company.context', $this->company);

        Model::reguard();
    }
}
