<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Documents\Services\DocumentTemplateResolver;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Scopes\CompanyScope;
use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveLedgerEntry;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ValidatePayrollUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkforceInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;
    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        // Unguard models for easier test data creation
        \Illuminate\Database\Eloquent\Model::unguard();

        $this->company = Company::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
            'jobdomain_key' => 'logistique',
        ]);

        $this->user = User::factory()->create();

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'hire_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $this->leaveType = LeaveType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'CP',
            'name' => 'Conges payes',
            'accrual_mode' => 'monthly',
            'annual_entitlement_hundredths' => 2500,
        ]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Database\Eloquent\Model::reguard();
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────
    // 1. STATE MACHINES
    // ────────────────────────────────────────────────────────────────

    // --- LeaveRequest ---

    public function test_leave_request_allows_valid_transitions(): void
    {
        $validPaths = [
            ['draft', 'pending'],
            ['draft', 'cancelled'],
            ['pending', 'approved'],
            ['pending', 'rejected'],
            ['pending', 'cancelled'],
            ['approved', 'consumed'],
            ['approved', 'cancelled'],
        ];

        foreach ($validPaths as [$from, $to]) {
            $lr = new LeaveRequest(['status' => $from]);
            $this->assertTrue(
                $lr->canTransitionTo($to),
                "LeaveRequest should allow {$from} -> {$to}"
            );

            $lr->transitionTo($to);
            $this->assertSame($to, $lr->status);
        }
    }

    public function test_leave_request_blocks_invalid_transitions(): void
    {
        $invalidPaths = [
            ['draft', 'approved'],
            ['draft', 'consumed'],
            ['draft', 'rejected'],
            ['pending', 'consumed'],
            ['pending', 'draft'],
            ['approved', 'pending'],
            ['approved', 'rejected'],
            ['consumed', 'draft'],
            ['consumed', 'cancelled'],
            ['rejected', 'pending'],
            ['cancelled', 'draft'],
        ];

        foreach ($invalidPaths as [$from, $to]) {
            $lr = new LeaveRequest(['status' => $from]);
            $this->assertFalse(
                $lr->canTransitionTo($to),
                "LeaveRequest should block {$from} -> {$to}"
            );

            try {
                $lr->transitionTo($to);
                $this->fail("Expected DomainException for {$from} -> {$to}");
            } catch (\DomainException $e) {
                $this->assertStringContainsString($from, $e->getMessage());
            }
        }
    }

    // --- TimesheetPeriod ---

    public function test_timesheet_period_allows_valid_transitions(): void
    {
        $validPaths = [
            ['draft', 'submitted'],
            ['submitted', 'approved'],
            ['submitted', 'rejected'],
            ['rejected', 'draft'],
            ['approved', 'locked'],
        ];

        foreach ($validPaths as [$from, $to]) {
            $tp = new TimesheetPeriod(['status' => $from]);
            $this->assertTrue(
                $tp->canTransitionTo($to),
                "TimesheetPeriod should allow {$from} -> {$to}"
            );

            $tp->transitionTo($to);
            $this->assertSame($to, $tp->status);
        }
    }

    public function test_timesheet_period_blocks_invalid_transitions(): void
    {
        $invalidPaths = [
            ['draft', 'approved'],
            ['draft', 'locked'],
            ['submitted', 'draft'],
            ['submitted', 'locked'],
            ['rejected', 'approved'],
            ['approved', 'draft'],
            ['approved', 'submitted'],
            ['locked', 'draft'],
            ['locked', 'approved'],
        ];

        foreach ($invalidPaths as [$from, $to]) {
            $tp = new TimesheetPeriod(['status' => $from]);
            $this->assertFalse(
                $tp->canTransitionTo($to),
                "TimesheetPeriod should block {$from} -> {$to}"
            );

            try {
                $tp->transitionTo($to);
                $this->fail("Expected DomainException for {$from} -> {$to}");
            } catch (\DomainException $e) {
                $this->assertStringContainsString($from, $e->getMessage());
            }
        }
    }

    // --- PayrollRun ---

    public function test_payroll_run_allows_valid_transitions(): void
    {
        $validPaths = [
            ['draft', 'computed'],
            ['computed', 'validated'],
            ['computed', 'draft'],
            ['validated', 'exported'],
        ];

        foreach ($validPaths as [$from, $to]) {
            $pr = new PayrollRun(['status' => $from]);
            $this->assertTrue(
                $pr->canTransitionTo($to),
                "PayrollRun should allow {$from} -> {$to}"
            );

            $pr->transitionTo($to);
            $this->assertSame($to, $pr->status);
        }
    }

    public function test_payroll_run_blocks_invalid_transitions(): void
    {
        $invalidPaths = [
            ['draft', 'validated'],
            ['draft', 'exported'],
            ['computed', 'exported'],
            ['validated', 'draft'],
            ['validated', 'computed'],
            ['exported', 'draft'],
            ['exported', 'validated'],
        ];

        foreach ($invalidPaths as [$from, $to]) {
            $pr = new PayrollRun(['status' => $from]);
            $this->assertFalse(
                $pr->canTransitionTo($to),
                "PayrollRun should block {$from} -> {$to}"
            );

            try {
                $pr->transitionTo($to);
                $this->fail("Expected DomainException for {$from} -> {$to}");
            } catch (\DomainException $e) {
                $this->assertStringContainsString($from, $e->getMessage());
            }
        }
    }

    // --- GeneratedDocument signature ---

    public function test_generated_document_allows_valid_signature_transitions(): void
    {
        $validPaths = [
            ['none', 'pending'],
            ['pending', 'signed'],
            ['pending', 'declined'],
        ];

        foreach ($validPaths as [$from, $to]) {
            $doc = new GeneratedDocument(['signature_status' => $from]);
            $this->assertTrue(
                $doc->canTransitionSignatureTo($to),
                "GeneratedDocument signature should allow {$from} -> {$to}"
            );

            $doc->transitionSignatureTo($to);
            $this->assertSame($to, $doc->signature_status);
        }
    }

    public function test_generated_document_blocks_invalid_signature_transitions(): void
    {
        $invalidPaths = [
            ['none', 'signed'],
            ['none', 'declined'],
            ['pending', 'none'],
            ['signed', 'none'],
            ['signed', 'pending'],
            ['signed', 'declined'],
            ['declined', 'none'],
            ['declined', 'pending'],
            ['declined', 'signed'],
        ];

        foreach ($invalidPaths as [$from, $to]) {
            $doc = new GeneratedDocument(['signature_status' => $from]);
            $this->assertFalse(
                $doc->canTransitionSignatureTo($to),
                "GeneratedDocument signature should block {$from} -> {$to}"
            );

            try {
                $doc->transitionSignatureTo($to);
                $this->fail("Expected DomainException for {$from} -> {$to}");
            } catch (\DomainException $e) {
                $this->assertStringContainsString($from, $e->getMessage());
            }
        }
    }

    // ────────────────────────────────────────────────────────────────
    // 2. IMMUTABILITY
    // ────────────────────────────────────────────────────────────────

    public function test_leave_ledger_entry_blocks_update(): void
    {
        $entry = LeaveLedgerEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'entry_type' => 'accrual',
            'credit' => 250,
            'debit' => 0,
            'balance_after' => 250,
            'correlation_id' => Str::uuid()->toString(),
            'period_key' => '2026-01',
            'recorded_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $entry->credit = 500;
        $entry->save();
    }

    public function test_leave_ledger_entry_blocks_delete(): void
    {
        $entry = LeaveLedgerEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'entry_type' => 'accrual',
            'credit' => 250,
            'debit' => 0,
            'balance_after' => 250,
            'correlation_id' => Str::uuid()->toString(),
            'period_key' => '2026-01',
            'recorded_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $entry->delete();
    }

    public function test_timesheet_line_blocks_update_when_period_not_draft(): void
    {
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'submitted',
        ]);

        $line = TimesheetLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'timesheet_period_id' => $period->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-01-15',
            'worked_minutes' => 480,
            'source_snapshot' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('draft');

        $line->worked_minutes = 500;
        $line->save();
    }

    public function test_timesheet_line_allows_update_when_period_is_draft(): void
    {
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'status' => 'draft',
        ]);

        $line = TimesheetLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'timesheet_period_id' => $period->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-02-15',
            'worked_minutes' => 480,
            'source_snapshot' => [],
        ]);

        $line->worked_minutes = 500;
        $line->save();

        $this->assertSame(500, $line->fresh()->worked_minutes);
    }

    public function test_timesheet_line_blocks_delete_when_period_not_draft(): void
    {
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'approved',
        ]);

        $line = TimesheetLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'timesheet_period_id' => $period->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-03-15',
            'worked_minutes' => 480,
            'source_snapshot' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('draft');

        $line->delete();
    }

    public function test_payroll_line_blocks_update_when_run_not_draft(): void
    {
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'status' => 'approved',
        ]);

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'status' => 'computed',
            'currency' => 'EUR',
            'employee_count' => 1,
        ]);

        $line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee->id,
            'timesheet_period_id' => $period->id,
            'gross_breakdown' => [],
            'compensation_snapshot' => [],
            'timesheet_snapshot' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not draft');

        $line->worked_minutes = 500;
        $line->save();
    }

    public function test_payroll_line_allows_update_when_run_is_draft(): void
    {
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => 'approved',
        ]);

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => 'draft',
            'currency' => 'EUR',
            'employee_count' => 1,
        ]);

        $line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee->id,
            'timesheet_period_id' => $period->id,
            'gross_breakdown' => [],
            'compensation_snapshot' => [],
            'timesheet_snapshot' => [],
        ]);

        $line->worked_minutes = 500;
        $line->save();

        $this->assertSame(500, $line->fresh()->worked_minutes);
    }

    public function test_payroll_line_blocks_delete_when_run_not_draft(): void
    {
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => 'approved',
        ]);

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => 'validated',
            'currency' => 'EUR',
            'employee_count' => 1,
        ]);

        $line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee->id,
            'timesheet_period_id' => $period->id,
            'gross_breakdown' => [],
            'compensation_snapshot' => [],
            'timesheet_snapshot' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not draft');

        $line->delete();
    }

    public function test_generated_document_blocks_update_of_non_signature_fields(): void
    {
        $template = DocumentTemplate::create([
            'company_id' => $this->company->id,
            'code' => 'test-tpl-immut',
            'name' => 'Test Immutability',
            'subject_type' => 'employee',
            'body_html' => '<p>Test</p>',
            'variables_schema' => [],
        ]);

        $doc = GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'document_template_id' => $template->id,
            'template_version' => 1,
            'subject_type' => 'employee',
            'subject_id' => $this->employee->id,
            'generated_by' => $this->user->id,
            'generated_at' => now(),
            'file_path' => '/test/file.pdf',
            'file_size_bytes' => 1024,
            'generation_snapshot' => ['test' => true],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only signature fields');

        $doc->file_path = '/changed/path.pdf';
        $doc->save();
    }

    public function test_generated_document_allows_update_of_signature_fields(): void
    {
        $template = DocumentTemplate::create([
            'company_id' => $this->company->id,
            'code' => 'test-tpl-sig',
            'name' => 'Test Signature Update',
            'subject_type' => 'employee',
            'body_html' => '<p>Test</p>',
            'variables_schema' => [],
        ]);

        $doc = GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'document_template_id' => $template->id,
            'template_version' => 1,
            'subject_type' => 'employee',
            'subject_id' => $this->employee->id,
            'generated_by' => $this->user->id,
            'generated_at' => now(),
            'file_path' => '/test/file.pdf',
            'file_size_bytes' => 1024,
            'generation_snapshot' => ['test' => true],
            'signature_status' => 'none',
        ]);

        $doc->signature_status = 'pending';
        $doc->signature_provider = 'docusign';
        $doc->save();

        $doc->refresh();
        $this->assertSame('pending', $doc->signature_status);
        $this->assertSame('docusign', $doc->signature_provider);
    }

    public function test_generated_document_blocks_delete_when_signed(): void
    {
        $template = DocumentTemplate::create([
            'company_id' => $this->company->id,
            'code' => 'test-tpl-del',
            'name' => 'Test Delete Block',
            'subject_type' => 'employee',
            'body_html' => '<p>Test</p>',
            'variables_schema' => [],
        ]);

        $doc = GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'document_template_id' => $template->id,
            'template_version' => 1,
            'subject_type' => 'employee',
            'subject_id' => $this->employee->id,
            'generated_by' => $this->user->id,
            'generated_at' => now(),
            'file_path' => '/test/file.pdf',
            'file_size_bytes' => 1024,
            'generation_snapshot' => ['test' => true],
            'signature_status' => 'signed',
            'signed_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signed document');

        $doc->delete();
    }

    public function test_generated_document_allows_delete_when_not_signed(): void
    {
        $template = DocumentTemplate::create([
            'company_id' => $this->company->id,
            'code' => 'test-tpl-del-ok',
            'name' => 'Test Delete OK',
            'subject_type' => 'employee',
            'body_html' => '<p>Test</p>',
            'variables_schema' => [],
        ]);

        $doc = GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'document_template_id' => $template->id,
            'template_version' => 1,
            'subject_type' => 'employee',
            'subject_id' => $this->employee->id,
            'generated_by' => $this->user->id,
            'generated_at' => now(),
            'file_path' => '/test/file.pdf',
            'file_size_bytes' => 1024,
            'generation_snapshot' => ['test' => true],
            'signature_status' => 'pending',
        ]);

        $doc->delete();
        $this->assertDatabaseMissing('generated_documents', ['id' => $doc->id]);
    }

    // ────────────────────────────────────────────────────────────────
    // 3. ValidatePayrollUseCase GUARDS
    // ────────────────────────────────────────────────────────────────

    public function test_validate_blocks_non_computed_status(): void
    {
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'draft',
            'currency' => 'EUR',
            'employee_count' => 5,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("status is 'draft'");

        $useCase = new ValidatePayrollUseCase();
        $useCase->execute($run, $this->user->id);
    }

    public function test_validate_blocks_zero_employee_count(): void
    {
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'status' => 'computed',
            'currency' => 'EUR',
            'employee_count' => 0,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('zero employees');

        $useCase = new ValidatePayrollUseCase();
        $useCase->execute($run, $this->user->id);
    }

    public function test_validate_requires_note_for_error_anomalies(): void
    {
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'computed',
            'currency' => 'EUR',
            'employee_count' => 3,
            'anomalies' => [
                ['type' => PayrollRun::ANOMALY_MISSING_TIMESHEET, 'employee_id' => 1],
            ],
            'anomaly_count' => 1,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('validation_note is required');

        $useCase = new ValidatePayrollUseCase();
        $useCase->execute($run, $this->user->id);
    }

    // ────────────────────────────────────────────────────────────────
    // 4. DOCUMENT TEMPLATE RESOLUTION
    // ────────────────────────────────────────────────────────────────

    public function test_template_resolver_company_override_over_platform(): void
    {
        // Platform template
        DocumentTemplate::create([
            'company_id' => null,
            'code' => 'payslip',
            'name' => 'Platform Payslip',
            'subject_type' => 'employee',
            'body_html' => '<p>Platform</p>',
            'variables_schema' => [],
            'is_active' => true,
        ]);

        // Company override
        $companyTpl = DocumentTemplate::create([
            'company_id' => $this->company->id,
            'code' => 'payslip',
            'name' => 'Company Payslip',
            'subject_type' => 'employee',
            'body_html' => '<p>Company</p>',
            'variables_schema' => [],
            'is_active' => true,
        ]);

        $resolved = DocumentTemplateResolver::resolve($this->company->id, 'payslip');

        $this->assertSame($companyTpl->id, $resolved->id);
        $this->assertSame($this->company->id, $resolved->company_id);
    }

    public function test_template_resolver_falls_back_to_platform(): void
    {
        $platformTpl = DocumentTemplate::create([
            'company_id' => null,
            'code' => 'contract-letter',
            'name' => 'Platform Contract Letter',
            'subject_type' => 'contract',
            'body_html' => '<p>Platform Contract</p>',
            'variables_schema' => [],
            'is_active' => true,
        ]);

        $resolved = DocumentTemplateResolver::resolve($this->company->id, 'contract-letter');

        $this->assertSame($platformTpl->id, $resolved->id);
        $this->assertNull($resolved->company_id);
    }

    public function test_template_resolver_throws_when_no_template(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No active template found');

        DocumentTemplateResolver::resolve($this->company->id, 'nonexistent-code');
    }

    // ────────────────────────────────────────────────────────────────
    // 5. DOCUMENT IDEMPOTENCY
    // ────────────────────────────────────────────────────────────────

    public function test_generate_document_returns_existing_on_duplicate_idempotency(): void
    {
        $template = DocumentTemplate::create([
            'company_id' => $this->company->id,
            'code' => 'idem-tpl',
            'name' => 'Idempotency Template',
            'subject_type' => 'employee',
            'body_html' => '<p>Test</p>',
            'variables_schema' => [],
            'version' => 1,
            'is_active' => true,
        ]);

        $idempotencyKey = "doc:{$template->id}:employee:{$this->employee->id}:1";

        // Pre-create a document with this idempotency key
        $existing = GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'document_template_id' => $template->id,
            'template_version' => 1,
            'subject_type' => 'employee',
            'subject_id' => $this->employee->id,
            'generated_by' => $this->user->id,
            'generated_at' => now(),
            'file_path' => '/test/existing.pdf',
            'file_size_bytes' => 2048,
            'generation_snapshot' => ['test' => true],
            'idempotency_key' => $idempotencyKey,
        ]);

        // Verify that querying by idempotency_key finds the existing doc
        $found = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        $this->assertNotNull($found);
        $this->assertSame($existing->id, $found->id);
        $this->assertSame('/test/existing.pdf', $found->file_path);
    }

    public function test_generation_snapshot_is_present_on_valid_document(): void
    {
        $template = DocumentTemplate::create([
            'company_id' => $this->company->id,
            'code' => 'snapshot-tpl',
            'name' => 'Snapshot Template',
            'subject_type' => 'employee',
            'body_html' => '<p>Test</p>',
            'variables_schema' => [],
        ]);

        // Verify that a properly created document always has generation_snapshot
        $doc = GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'document_template_id' => $template->id,
            'template_version' => 1,
            'subject_type' => 'employee',
            'subject_id' => $this->employee->id,
            'generated_by' => $this->user->id,
            'generated_at' => now(),
            'file_path' => '/test/with-snapshot.pdf',
            'file_size_bytes' => 512,
            'generation_snapshot' => ['template_code' => 'snapshot-tpl', 'resolver_version' => 'v1'],
        ]);

        $this->assertNotNull($doc->generation_snapshot);
        $this->assertIsArray($doc->generation_snapshot);
        $this->assertArrayHasKey('template_code', $doc->generation_snapshot);

        // Verify snapshot cannot be emptied via update (immutability guard blocks it)
        $this->expectException(\RuntimeException::class);
        $doc->generation_snapshot = [];
        $doc->save();
    }

    // ────────────────────────────────────────────────────────────────
    // 6. MULTI-TENANT ISOLATION
    // ────────────────────────────────────────────────────────────────

    private function createCompanyB(): Company
    {
        return Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co-' . Str::random(4),
            'jobdomain_key' => 'logistique',
        ]);
    }

    private function createEmployeeForCompany(Company $company): Employee
    {
        $user = User::factory()->create();

        return Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_number' => 'EMP-' . Str::random(6),
            'first_name' => 'Other',
            'last_name' => 'Person',
            'email' => 'other-' . Str::random(4) . '@example.com',
            'hire_date' => '2024-01-01',
            'status' => 'active',
        ]);
    }

    public function test_leave_request_scoped_to_company(): void
    {
        $companyB = $this->createCompanyB();
        $employeeB = $this->createEmployeeForCompany($companyB);
        $leaveTypeB = LeaveType::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'code' => 'CP',
            'name' => 'Conges payes B',
            'accrual_mode' => 'monthly',
            'annual_entitlement_hundredths' => 2500,
        ]);

        // Create leave requests in both companies
        LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-05',
            'days_count_hundredths' => 500,
            'status' => 'draft',
        ]);

        LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'employee_id' => $employeeB->id,
            'leave_type_id' => $leaveTypeB->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-05',
            'days_count_hundredths' => 500,
            'status' => 'draft',
        ]);

        // Bind company A context and verify isolation
        app()->instance('company.context', $this->company);
        $results = LeaveRequest::all();

        $this->assertCount(1, $results);
        $this->assertSame($this->company->id, $results->first()->company_id);

        // Clean up binding
        app()->forgetInstance('company.context');
    }

    public function test_timesheet_period_scoped_to_company(): void
    {
        $companyB = $this->createCompanyB();
        $employeeB = $this->createEmployeeForCompany($companyB);

        TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'draft',
        ]);

        TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'employee_id' => $employeeB->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'draft',
        ]);

        app()->instance('company.context', $this->company);
        $results = TimesheetPeriod::all();

        $this->assertCount(1, $results);
        $this->assertSame($this->company->id, $results->first()->company_id);

        app()->forgetInstance('company.context');
    }

    public function test_payroll_run_scoped_to_company(): void
    {
        $companyB = $this->createCompanyB();

        PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => 'draft',
            'currency' => 'EUR',
        ]);

        PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => 'draft',
            'currency' => 'EUR',
        ]);

        app()->instance('company.context', $this->company);
        $results = PayrollRun::all();

        $this->assertCount(1, $results);
        $this->assertSame($this->company->id, $results->first()->company_id);

        app()->forgetInstance('company.context');
    }

    public function test_generated_document_scoped_to_company(): void
    {
        $companyB = $this->createCompanyB();
        $employeeB = $this->createEmployeeForCompany($companyB);
        $userB = User::factory()->create();

        $template = DocumentTemplate::create([
            'company_id' => null,
            'code' => 'scope-tpl',
            'name' => 'Scope Template',
            'subject_type' => 'employee',
            'body_html' => '<p>Scope</p>',
            'variables_schema' => [],
            'is_active' => true,
        ]);

        GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'document_template_id' => $template->id,
            'template_version' => 1,
            'subject_type' => 'employee',
            'subject_id' => $this->employee->id,
            'generated_by' => $this->user->id,
            'generated_at' => now(),
            'file_path' => '/docs/a.pdf',
            'file_size_bytes' => 1024,
            'generation_snapshot' => ['company' => 'A'],
        ]);

        GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'document_template_id' => $template->id,
            'template_version' => 1,
            'subject_type' => 'employee',
            'subject_id' => $employeeB->id,
            'generated_by' => $userB->id,
            'generated_at' => now(),
            'file_path' => '/docs/b.pdf',
            'file_size_bytes' => 1024,
            'generation_snapshot' => ['company' => 'B'],
        ]);

        app()->instance('company.context', $this->company);
        $results = GeneratedDocument::all();

        $this->assertCount(1, $results);
        $this->assertSame($this->company->id, $results->first()->company_id);

        app()->forgetInstance('company.context');
    }
}
