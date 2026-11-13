<?php

namespace Tests\Feature;

use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Documents\DocumentCampaign;
use App\Core\Documents\DocumentCampaignRecipient;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\Payslip\PayslipImportBatch;
use App\Core\Documents\Payslip\PayslipImportItem;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Tenancy\CompanyContext;
use App\Core\Workforce\Employee;
use App\Jobs\Documents\IngestPayslipBatchJob;
use App\Jobs\Documents\LaunchDocumentCampaignJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-770 (M7 LOT 4) — operational paths of the background work : what is really scheduled, what
 * needs a worker, what happens when the queue is stopped or a job fails, and whether a second run
 * doubles an effect. Executed for real (artisan commands, `database` queue connection, worker).
 */
class M7Lot4JobsSchedulerTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->company = Company::create(['name' => 'jobs-a', 'slug' => 'jobs-a', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);
        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::firstOrCreate(['company_id' => $this->company->id, 'document_type_id' => $type->id], ['enabled' => true, 'order' => $i * 10]);
        }
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function employee(string $matricule): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'first_name' => 'Emp', 'last_name' => $matricule, 'status' => 'active',
            'hire_date' => '2026-01-01', 'employee_number' => $matricule,
        ]);
    }

    private function payslipPdf(string $matricule): UploadedFile
    {
        $pdf = new Fpdi();
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        foreach (['Bulletin de paie', "Matricule: {$matricule}", 'Periode: 2026-01', 'Page 1/1'] as $line) {
            $pdf->Cell(0, 10, $line, 0, 1);
        }

        return UploadedFile::fake()->createWithContent('p.pdf', $pdf->Output('S'));
    }

    private function secondType(): string
    {
        return DocumentType::where('is_system', true)->where('scope', DocumentType::SCOPE_COMPANY_USER)->where('code', '!=', 'id_card')->value('code');
    }

    /** Runs the real worker until the queue is empty. `--memory` : the worker measures the memory of the WHOLE test process ; in a long suite it would exit with code 12 (memory limit) before processing anything. */
    private function work(string $queues = 'default'): void
    {
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => $queues, '--tries' => 3, '--sleep' => 0, '--memory' => 4096])->assertSuccessful();
    }

    private function pendingJobs(): array
    {
        return DB::table('jobs')->pluck('payload')->map(fn ($p) => class_basename(json_decode($p, true)['displayName'] ?? '?'))->all();
    }

    // ═══ Scheduler wiring ═══════════════════════════════════════════════════

    public function test_every_dms_and_membership_task_is_scheduled_once_and_listed_in_the_cockpit_registry(): void
    {
        $events = collect(app(Schedule::class)->events());
        $expected = [
            'documents:check-expiration' => '0 0 * * *',
            'documents:auto-renew' => '0 8 * * *',
            'documents:auto-remind' => '0 9 * * *',
            'workforce:signature-expire' => '0 * * * *',
            'members:invitations-expire' => '0 * * * *',
        ];

        foreach ($expected as $command => $cron) {
            $matches = $events->filter(fn ($e) => str_contains((string) $e->command, $command));
            $this->assertCount(1, $matches, "{$command} must be scheduled exactly once");
            $this->assertSame($cron, $matches->first()->expression, "{$command} frequency");
            $this->assertTrue($matches->first()->withoutOverlapping, "{$command} must not overlap");
            $this->assertArrayHasKey($command, ScheduledTaskRegistry::all(), "{$command} must be visible in the automation cockpit");
        }
    }

    public function test_scheduled_commands_run_twice_without_error_and_without_any_bound_tenant(): void
    {
        $this->assertFalse(CompanyContext::has());
        foreach (['documents:check-expiration', 'documents:auto-renew', 'documents:auto-remind', 'workforce:signature-expire', 'members:invitations-expire'] as $command) {
            $this->artisan($command)->assertSuccessful();
            $this->artisan($command)->assertSuccessful();
        }
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_invitation_expiry_audit_is_written_once(): void
    {
        $invited = User::factory()->create();
        $role = \App\Company\RBAC\CompanyRole::where('company_id', $this->company->id)->first();
        $m = Membership::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'user_id' => $invited->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $m->forceFill(['acceptance_pending_at' => now()->subDays(30), 'suspended_at' => now()->subDays(30), 'invitation_expires_at' => now()->subDay()])->save();

        $this->artisan('members:invitations-expire')->assertSuccessful();
        $this->artisan('members:invitations-expire')->assertSuccessful();

        $this->assertSame(1, DB::table('company_audit_logs')->where('company_id', $this->company->id)->where('action', 'member.invitation_expired')->count());
        $this->assertNotNull(Membership::withoutGlobalScopes()->find($m->id), 'nothing is deleted');
    }

    // ═══ Queue stopped (no worker) ══════════════════════════════════════════

    public function test_with_the_queue_stopped_nothing_is_lost_and_every_state_stays_honest_until_a_worker_runs(): void
    {
        config(['queue.default' => 'database']);
        $a = $this->employee('A100');
        $member = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $member->id, 'role' => 'user', 'company_role_id' => \App\Company\RBAC\CompanyRole::where('company_id', $this->company->id)->value('id')]);

        // 1. Payslip import : the batch is stored and visible as « uploaded » — not processed, not lost.
        $batchId = $this->as($this->owner)->post('/api/company/payslip-imports', ['files' => [$this->payslipPdf('A100')]], ['Accept' => 'application/json'])->assertCreated()->json('batch.id');
        $this->assertSame(PayslipImportBatch::STATUS_UPLOADED, PayslipImportBatch::withoutGlobalScopes()->find($batchId)->status);
        $this->assertSame(0, PayslipImportItem::withoutGlobalScopes()->count());
        $this->as($this->owner)->getJson('/api/company/payslip-imports')->assertOk();

        // 2. Campaign : created with its frozen snapshot, shown as not yet launched ; no request exists.
        $campaignId = $this->as($this->owner)->postJson('/api/company/document-campaigns', ['document_type_code' => 'id_card', 'targeting' => ['mode' => 'all']])->assertCreated()->json('campaign.id');
        $campaign = DocumentCampaign::findOrFail($campaignId);
        $this->assertSame(DocumentCampaign::STATUS_DRAFT, $campaign->status);
        $this->assertGreaterThan(0, $campaign->recipients_total);
        $this->assertSame($campaign->recipients_total, DocumentCampaignRecipient::where('document_campaign_id', $campaignId)->where('state', DocumentCampaignRecipient::STATE_PENDING)->count());
        $this->assertSame(0, DocumentRequest::where('company_id', $this->company->id)->count());

        // 3. Invitation : the membership exists, the e-mail is declared « queued » — never « sent ».
        $role = \App\Company\RBAC\CompanyRole::where('company_id', $this->company->id)->where('is_administrative', false)->first()
            ?? \App\Company\RBAC\CompanyRole::create(['company_id' => $this->company->id, 'key' => 'basic', 'name' => 'Basic', 'is_administrative' => false]);
        $invite = $this->as($this->owner)->postJson('/api/company/members', ['email' => 'queued-'.uniqid().'@jobs.test', 'first_name' => 'Q', 'last_name' => 'D', 'company_role_id' => $role->id])->assertCreated();
        $this->assertSame('queued', $invite->json('invitation.delivery'));
        $membership = Membership::withoutGlobalScopes()->findOrFail($invite->json('member.id'));
        $this->assertSame('queued', $membership->invitation_delivery_status);
        $this->assertNull($membership->invitation_sent_at);

        // The three jobs wait in the `jobs` table ; none failed, none ran.
        $pending = $this->pendingJobs();
        foreach (['IngestPayslipBatchJob', 'LaunchDocumentCampaignJob', 'SendMembershipInvitationJob'] as $job) {
            $this->assertContains($job, $pending, "{$job} is waiting for a worker");
        }
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // A worker starts : every pending effect happens exactly once.
        $this->work();
        $this->assertSame([], $this->pendingJobs());
        $this->assertSame(PayslipImportBatch::STATUS_REVIEW, PayslipImportBatch::withoutGlobalScopes()->find($batchId)->status);
        $this->assertSame($a->id, PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $batchId)->value('matched_employee_id'));
        $this->assertSame(DocumentCampaign::STATUS_ACTIVE, $campaign->fresh()->status);
        $this->assertSame($campaign->recipients_total, DocumentRequest::where('company_id', $this->company->id)->count());
        $this->assertSame('sent', $membership->fresh()->invitation_delivery_status);
        $this->assertNotNull($membership->fresh()->invitation_sent_at);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // Replaying the same jobs (duplicate delivery by the queue) changes nothing.
        (new IngestPayslipBatchJob($batchId, $this->company->id))->handle(app(\App\Core\Documents\Payslip\PayslipIngestor::class));
        (new LaunchDocumentCampaignJob($campaignId))->handle(app(\App\Core\Documents\Services\DocumentCampaignService::class));
        $this->assertSame(1, PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $batchId)->count());
        $this->assertSame($campaign->recipients_total, DocumentRequest::where('company_id', $this->company->id)->count());
    }

    // ═══ Failing jobs ═══════════════════════════════════════════════════════

    public function test_an_unreadable_payslip_batch_ends_failed_and_readable_never_stuck_in_processing(): void
    {
        config(['queue.default' => 'database']);
        $batchId = $this->as($this->owner)->post('/api/company/payslip-imports', ['files' => [$this->payslipPdf('A100')]], ['Accept' => 'application/json'])->assertCreated()->json('batch.id');

        // The stored source disappears before the worker picks the job (disk incident).
        foreach (Storage::disk('local')->allFiles("payslip-imports/{$this->company->id}/{$batchId}") as $file) {
            Storage::disk('local')->delete($file);
        }
        $this->work();

        $batch = PayslipImportBatch::withoutGlobalScopes()->find($batchId);
        $this->assertNotSame(PayslipImportBatch::STATUS_PROCESSING, $batch->status, 'a failed ingestion must not stay « processing » forever');
        $this->assertNotSame(PayslipImportBatch::STATUS_UPLOADED, $batch->status);
        $this->assertSame(0, \App\Core\Documents\Payslip\PublishedPayslip::withoutGlobalScopes()->count());
        $this->assertSame([], $this->pendingJobs(), 'the job is not retried in a loop');
        // The operator can read the outcome through the API.
        $this->as($this->owner)->getJson("/api/company/payslip-imports/{$batchId}")->assertOk();
    }

    public function test_a_job_that_keeps_throwing_is_retried_then_recorded_in_failed_jobs(): void
    {
        config(['queue.default' => 'database']);
        $campaignId = $this->as($this->owner)->postJson('/api/company/document-campaigns', ['document_type_code' => 'id_card', 'targeting' => ['mode' => 'all']])->assertCreated()->json('campaign.id');

        // The launch service is broken for the duration of the test (infrastructure fault).
        $this->mock(\App\Core\Documents\Services\DocumentCampaignService::class, function ($mock) {
            $mock->shouldReceive('launch')->andThrow(new \RuntimeException('database unavailable'));
        });
        $this->work();

        $this->assertSame([], $this->pendingJobs());
        $failed = DB::table('failed_jobs')->get();
        $this->assertCount(1, $failed, 'after its 3 attempts the job is kept in failed_jobs (replayable with queue:retry)');
        $this->assertStringContainsString('LaunchDocumentCampaignJob', $failed[0]->payload);
        $this->assertStringContainsString('database unavailable', $failed[0]->exception);
        // The campaign is still a draft with its snapshot : nothing half-done, relaunchable by hand.
        $this->assertSame(DocumentCampaign::STATUS_DRAFT, DocumentCampaign::findOrFail($campaignId)->status);

        $this->app->forgetInstance(\App\Core\Documents\Services\DocumentCampaignService::class);
        $this->as($this->owner)->postJson("/api/company/document-campaigns/{$campaignId}/launch")->assertOk();
        $this->work();
        $this->assertSame(DocumentCampaign::STATUS_ACTIVE, DocumentCampaign::findOrFail($campaignId)->status);
    }

    // ═══ Tenant scoping outside HTTP ════════════════════════════════════════

    public function test_background_work_is_tenant_safe_under_strict_tenancy(): void
    {
        $other = Company::create(['name' => 'jobs-b', 'slug' => 'jobs-b', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($other);
        $campaign = app(\App\Core\Documents\Services\DocumentCampaignService::class)->create($this->company, $this->owner, 'id_card', ['mode' => 'all']);

        $legacy = app(\App\Core\Documents\Services\DocumentCampaignService::class)->create($this->company, $this->owner, $this->secondType(), ['mode' => 'all']);

        // Worker conditions : no HTTP request, no tenant bound, fail-closed tenancy (ADR-733).
        $this->assertFalse(CompanyContext::has());
        CompanyContext::$strict = true;
        try {
            (new LaunchDocumentCampaignJob($campaign->id, $this->company->id))->handle(app(\App\Core\Documents\Services\DocumentCampaignService::class));
            // A job serialised BEFORE the tenant travelled in the payload still runs, under its own company.
            (new LaunchDocumentCampaignJob($legacy->id))->handle(app(\App\Core\Documents\Services\DocumentCampaignService::class));
            $this->assertSame(DocumentCampaign::STATUS_ACTIVE, CompanyContext::runGlobal(fn () => DocumentCampaign::find($legacy->id)->status));
            foreach (['workforce:signature-expire', 'members:invitations-expire'] as $command) {
                $this->artisan($command)->assertSuccessful();
            }
        } finally {
            CompanyContext::$strict = false;
        }

        $this->assertSame(DocumentCampaign::STATUS_ACTIVE, DocumentCampaign::withoutGlobalScopes()->find($campaign->id)->status);
        $this->assertSame(0, DocumentRequest::withoutGlobalScopes()->where('company_id', $other->id)->count(), 'nothing is created in another tenant');
        $this->assertFalse(CompanyContext::has(), 'no tenant leaks after the run');
    }

    /**
     * ADR-771 — formerly a KNOWN GAP reported as skipped (ADR-770) : the document lifecycle read tenant
     * models across companies without declaring it and threw under strict tenancy. The orchestrator
     * now enumerates the companies in a declared global block and works inside each company context.
     * No skip path any more : a missing tenant context FAILS this test.
     */
    public function test_document_lifecycle_commands_under_strict_tenancy(): void
    {
        \App\Core\Documents\CompanyDocumentSetting::updateOrCreate(['company_id' => $this->company->id], ['auto_renew_enabled' => true, 'renew_days_before' => 30, 'auto_remind_enabled' => true, 'remind_after_days' => 7]);
        CompanyContext::$strict = true;
        try {
            $stats = app(\App\Core\Documents\Services\DocumentLifecycleOrchestrator::class)->run(['renew', 'remind']);
            $this->assertSame(1, $stats['companies'], 'the configured company was processed, inside its own context');
            // The three scheduled commands go through the same authority.
            foreach (['documents:check-expiration', 'documents:auto-renew', 'documents:auto-remind'] as $command) {
                $this->artisan($command)->assertSuccessful();
            }
        } finally {
            CompanyContext::$strict = false;
        }
        $this->assertFalse(CompanyContext::has());
    }

    // ═══ Queues a worker must consume ═══════════════════════════════════════

    public function test_the_ai_analysis_job_uses_its_own_queue_so_the_worker_must_listen_to_it(): void
    {
        $job = new \App\Jobs\Documents\ProcessDocumentAiJob(\App\Core\Documents\MemberDocument::class, 1, 1);
        $this->assertSame('ai', $job->queue, 'a worker started with the default queue only never analyses documents');
        $this->assertNull((new IngestPayslipBatchJob(1, $this->company->id))->queue);
        $this->assertNull((new LaunchDocumentCampaignJob(1))->queue);
    }
}
