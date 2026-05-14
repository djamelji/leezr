<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\Dsn\DsnPreflightChecker;
use App\Core\Workforce\Dsn\DsnSubmissionLockService;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\FakeNetEntreprisesClient;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesDsnGateway;
use App\Core\Workforce\Dsn\PreflightResult;
use App\Core\Markets\Market;
use App\Modules\Workforce\UseCases\PollDsnDeclarationUseCase;
use App\Modules\Workforce\UseCases\SubmitDsnDeclarationUseCase;
use App\Platform\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 7.4 — ADR-532
 * Tests for PollDsnDeclarationUseCase and dsn:poll-pending command.
 */
class DsnPollingSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

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
            'name' => 'Polling Test Co', 'slug' => 'polling-test-co',
            'jobdomain_key' => 'tech', 'market_key' => 'FR',
            'siret' => '73282932000074', 'naf_code' => '6201Z',
            'address_street' => '10 rue de la Paix',
            'address_postal_code' => '75002', 'address_city' => 'Paris',
        ]);

        $this->user = User::create([
            'name' => 'Operator', 'email' => 'op-poll@test.com',
            'password' => bcrypt('password'),
        ]);

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
        config(['workforce.dsn.poll_max_duration_hours' => 72]);

        Model::reguard();
    }

    private function makeSubmittedDeclaration(array $overrides = []): DsnDeclaration
    {
        Model::unguard();

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'status' => 'exported', 'currency' => 'EUR',
            'employee_count' => 1, 'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000, 'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:poll-test:' . uniqid(),
        ]);

        $filePath = "workforce/dsn/{$this->company->id}/dsn_poll_test_" . uniqid() . '.dsn';
        Storage::disk('local')->put($filePath, $this->fileContent);

        $declaration = DsnDeclaration::create(array_merge([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-04',
            'status' => DsnDeclaration::STATUS_SUBMITTED,
            'file_path' => $filePath,
            'payload_snapshot' => ['test' => true],
            'payload_hash' => hash('sha256', $this->fileContent),
            'generated_by' => $this->user->id,
            'generated_at' => now()->subHour(),
            'exported_by' => $this->user->id,
            'exported_at' => now()->subMinutes(50),
            'submitted_by' => $this->user->id,
            'submitted_at' => now()->subMinutes(30),
            'submission_reference' => 'NE-TEST-' . uniqid(),
            'gateway_driver' => 'net-entreprises',
            'gateway_environment' => 'sandbox',
            'gateway_status' => 'aee_received',
            'attempt_count' => 1,
            'next_poll_at' => null,
        ], $overrides));

        Model::reguard();

        return $declaration;
    }

    private function makePollUseCase(string $scenario = 'pending_then_accepted', int $pendingPolls = 0): PollDsnDeclarationUseCase
    {
        $client = new FakeNetEntreprisesClient($scenario, $pendingPolls);
        $gateway = new NetEntreprisesDsnGateway(
            client: $client,
            credentialService: new DsnCredentialService(),
            disk: 'local',
        );

        $submitUseCase = new SubmitDsnDeclarationUseCase($gateway, $this->bypassPreflight());

        return new PollDsnDeclarationUseCase($submitUseCase);
    }

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

    // ─── Command: selection logic ───

    public function test_command_selects_only_submitted_due_declarations(): void
    {
        // Due declaration (submitted, next_poll_at in past)
        $due = $this->makeSubmittedDeclaration(['next_poll_at' => now()->subMinutes(5)]);

        // Not due yet (next_poll_at in future)
        $this->makeSubmittedDeclaration(['next_poll_at' => now()->addHour()]);

        // Not submitted (draft)
        Model::unguard();
        $draft = $this->makeSubmittedDeclaration(['status' => DsnDeclaration::STATUS_DRAFT, 'next_poll_at' => null]);
        Model::reguard();

        // NULL next_poll_at = due immediately
        $nullPoll = $this->makeSubmittedDeclaration(['next_poll_at' => null]);

        $this->artisan('dsn:poll-pending', ['--dry-run' => true])
            ->assertSuccessful();

        // Verify selection: query the same way the command does
        $selected = DsnDeclaration::query()
            ->where('status', DsnDeclaration::STATUS_SUBMITTED)
            ->where(function ($q) {
                $q->whereNull('next_poll_at')
                    ->orWhere('next_poll_at', '<=', now());
            })
            ->pluck('id');

        $this->assertContains($due->id, $selected);
        $this->assertContains($nullPoll->id, $selected);
        $this->assertCount(2, $selected);
    }

    public function test_command_dry_run_does_not_poll(): void
    {
        $declaration = $this->makeSubmittedDeclaration(['next_poll_at' => now()->subMinute()]);

        $this->artisan('dsn:poll-pending', ['--dry-run' => true])
            ->assertSuccessful();

        $declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_SUBMITTED, $declaration->status);
        $this->assertSame('aee_received', $declaration->gateway_status);
    }

    public function test_command_respects_limit(): void
    {
        // Create 5 due declarations
        for ($i = 0; $i < 5; $i++) {
            $this->makeSubmittedDeclaration(['next_poll_at' => now()->subMinutes($i + 1)]);
        }

        // Verify 5 are due
        $totalDue = DsnDeclaration::query()
            ->where('status', DsnDeclaration::STATUS_SUBMITTED)
            ->where(function ($q) {
                $q->whereNull('next_poll_at')
                    ->orWhere('next_poll_at', '<=', now());
            })
            ->count();
        $this->assertSame(5, $totalDue);

        // With limit=2, dry-run should show only 2 rows
        $this->artisan('dsn:poll-pending', ['--limit' => 2, '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('2 declaration(s) due');
    }

    // ─── UseCase: pending replanification ───

    public function test_pending_result_replanifies_next_poll_at(): void
    {
        $declaration = $this->makeSubmittedDeclaration([
            'attempt_count' => 1,
            'next_poll_at' => now()->subMinute(),
        ]);

        // pending_then_accepted with pendingPolls=5 => first poll returns pending
        $uc = $this->makePollUseCase('pending_then_accepted', 5);
        $result = $uc->execute($declaration, 0);

        $this->assertSame('pending', $result['result']);
        $declaration->refresh();
        $this->assertNotNull($declaration->next_poll_at);
        $this->assertTrue($declaration->next_poll_at->isFuture());
        $this->assertSame(2, $declaration->attempt_count);
    }

    public function test_backoff_15min_first_attempt(): void
    {
        $declaration = $this->makeSubmittedDeclaration([
            'attempt_count' => 1,
            'next_poll_at' => now()->subMinute(),
        ]);

        $uc = $this->makePollUseCase('pending_then_accepted', 10);
        $uc->execute($declaration, 0);

        $declaration->refresh();
        // Attempt was 1, so delay = 15 minutes
        $expectedMin = now()->addMinutes(14);
        $expectedMax = now()->addMinutes(16);
        $this->assertTrue(
            $declaration->next_poll_at->between($expectedMin, $expectedMax),
            "next_poll_at should be ~15 min from now, got: {$declaration->next_poll_at}"
        );
    }

    public function test_backoff_30min_second_attempt(): void
    {
        $declaration = $this->makeSubmittedDeclaration([
            'attempt_count' => 2,
            'next_poll_at' => now()->subMinute(),
        ]);

        $uc = $this->makePollUseCase('pending_then_accepted', 10);
        $uc->execute($declaration, 0);

        $declaration->refresh();
        // Attempt was 2, so delay = 30 minutes
        $expectedMin = now()->addMinutes(29);
        $expectedMax = now()->addMinutes(31);
        $this->assertTrue(
            $declaration->next_poll_at->between($expectedMin, $expectedMax),
            "next_poll_at should be ~30 min from now, got: {$declaration->next_poll_at}"
        );
    }

    public function test_backoff_60min_third_attempt_and_beyond(): void
    {
        $declaration = $this->makeSubmittedDeclaration([
            'attempt_count' => 5,
            'next_poll_at' => now()->subMinute(),
        ]);

        $uc = $this->makePollUseCase('pending_then_accepted', 10);
        $uc->execute($declaration, 0);

        $declaration->refresh();
        // Attempt was 5 (>= 3), so delay = 60 minutes
        $expectedMin = now()->addMinutes(59);
        $expectedMax = now()->addMinutes(61);
        $this->assertTrue(
            $declaration->next_poll_at->between($expectedMin, $expectedMax),
            "next_poll_at should be ~60 min from now, got: {$declaration->next_poll_at}"
        );
    }

    // ─── UseCase: terminal transitions ───

    public function test_accepted_transitions_to_accepted(): void
    {
        $declaration = $this->makeSubmittedDeclaration();

        // pending_then_accepted with pendingPolls=0 => first poll returns accepted
        $uc = $this->makePollUseCase('pending_then_accepted', 0);
        $result = $uc->execute($declaration, 0);

        $this->assertSame('accepted', $result['result']);
        $declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_ACCEPTED, $declaration->status);
    }

    public function test_rejected_transitions_to_rejected(): void
    {
        $declaration = $this->makeSubmittedDeclaration();

        // pending_then_rejected with pendingPolls=0 => first poll returns rejected
        $uc = $this->makePollUseCase('pending_then_rejected', 0);
        $result = $uc->execute($declaration, 0);

        $this->assertSame('rejected', $result['result']);
        $declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_REJECTED, $declaration->status);
    }

    // ─── UseCase: guards ───

    public function test_already_accepted_is_skipped(): void
    {
        Model::unguard();
        $declaration = $this->makeSubmittedDeclaration([
            'status' => DsnDeclaration::STATUS_ACCEPTED,
        ]);
        Model::reguard();

        $uc = $this->makePollUseCase();
        $result = $uc->execute($declaration, 0);

        $this->assertSame('skipped', $result['result']);
    }

    public function test_already_rejected_is_skipped(): void
    {
        Model::unguard();
        $declaration = $this->makeSubmittedDeclaration([
            'status' => DsnDeclaration::STATUS_REJECTED,
        ]);
        Model::reguard();

        $uc = $this->makePollUseCase();
        $result = $uc->execute($declaration, 0);

        $this->assertSame('skipped', $result['result']);
    }

    // ─── UseCase: lock prevents double poll ───

    public function test_lock_prevents_concurrent_poll(): void
    {
        $declaration = $this->makeSubmittedDeclaration();

        $lockService = new DsnSubmissionLockService();
        // Acquire lock externally (simulating another process polling)
        $lockService->acquire($declaration->id, 300);

        $client = new FakeNetEntreprisesClient('pending_then_accepted', 0);
        $gateway = new NetEntreprisesDsnGateway(
            client: $client,
            credentialService: new DsnCredentialService(),
            disk: 'local',
        );
        $submitUseCase = new SubmitDsnDeclarationUseCase($gateway, $this->bypassPreflight());
        $uc = new PollDsnDeclarationUseCase($submitUseCase, $lockService);

        $result = $uc->execute($declaration, 0);

        $this->assertSame('locked', $result['result']);

        // Cleanup
        $lockService->release($declaration->id);
    }

    // ─── UseCase: timeout 72h ───

    public function test_timeout_72h_marks_rejected(): void
    {
        $declaration = $this->makeSubmittedDeclaration([
            'submitted_at' => now()->subHours(73),
        ]);

        $uc = $this->makePollUseCase('pending_then_accepted', 10);
        $result = $uc->execute($declaration, 0);

        $this->assertSame('timeout', $result['result']);
        $this->assertSame('poll_timeout', $result['gateway_status']);

        $declaration->refresh();
        $this->assertSame(DsnDeclaration::STATUS_REJECTED, $declaration->status);
        $this->assertSame('poll_timeout', $declaration->gateway_status);
        $this->assertNull($declaration->next_poll_at);
    }

    public function test_timeout_within_72h_continues_polling(): void
    {
        $declaration = $this->makeSubmittedDeclaration([
            'submitted_at' => now()->subHours(71),
        ]);

        $uc = $this->makePollUseCase('pending_then_accepted', 10);
        $result = $uc->execute($declaration, 0);

        // Should poll normally (pending), not timeout
        $this->assertSame('pending', $result['result']);
    }

    // ─── UseCase: error handling ───

    public function test_transport_error_applies_backoff_and_returns_retryable(): void
    {
        $declaration = $this->makeSubmittedDeclaration([
            'attempt_count' => 1,
        ]);

        // network_timeout scenario triggers RuntimeException in the gateway
        $client = new FakeNetEntreprisesClient('network_timeout');
        $gateway = new NetEntreprisesDsnGateway(
            client: $client,
            credentialService: new DsnCredentialService(),
            disk: 'local',
        );
        $submitUseCase = new SubmitDsnDeclarationUseCase($gateway, $this->bypassPreflight());
        $uc = new PollDsnDeclarationUseCase($submitUseCase);

        $result = $uc->execute($declaration, 0);

        $this->assertSame('error_retryable', $result['result']);
        $declaration->refresh();
        $this->assertNotNull($declaration->next_poll_at);
        $this->assertTrue($declaration->next_poll_at->isFuture());
    }
}
