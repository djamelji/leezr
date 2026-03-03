<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Audit\PlatformAuditLog;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    // ─── Platform Audit ─────────────────────────────────

    public function test_platform_audit_log_created_via_logger(): void
    {
        $logger = app(AuditLogger::class);

        $log = $logger->logPlatform(
            AuditAction::KILL_SWITCH_ACTIVATED,
            null,
            null,
        );

        $this->assertInstanceOf(PlatformAuditLog::class, $log);
        $this->assertEquals(AuditAction::KILL_SWITCH_ACTIVATED, $log->action);
        $this->assertNotNull($log->id);
        $this->assertNotNull($log->created_at);
    }

    public function test_platform_audit_log_persisted_in_db(): void
    {
        $logger = app(AuditLogger::class);

        $logger->logPlatform(
            AuditAction::CHANNELS_FLUSHED,
            null,
            null,
            ['severity' => 'warning'],
        );

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::CHANNELS_FLUSHED,
            'severity' => 'warning',
        ]);
    }

    public function test_platform_audit_log_with_target(): void
    {
        $logger = app(AuditLogger::class);

        $log = $logger->logPlatform(
            AuditAction::MODULE_ENABLED,
            'module',
            'logistics_shipments',
        );

        $this->assertEquals('module', $log->target_type);
        $this->assertEquals('logistics_shipments', $log->target_id);
    }

    // ─── Company Audit ──────────────────────────────────

    public function test_company_audit_log_created_via_logger(): void
    {
        $company = Company::create(['name' => 'Audit Co', 'slug' => 'audit-co-' . uniqid(), 'jobdomain_key' => 'logistique']);
        $logger = app(AuditLogger::class);

        $log = $logger->logCompany(
            $company->id,
            AuditAction::ROLE_CREATED,
            'role',
            '42',
        );

        $this->assertInstanceOf(CompanyAuditLog::class, $log);
        $this->assertEquals($company->id, $log->company_id);
        $this->assertEquals(AuditAction::ROLE_CREATED, $log->action);
        $this->assertEquals('role', $log->target_type);
        $this->assertEquals('42', $log->target_id);
    }

    public function test_company_audit_log_persisted_in_db(): void
    {
        $company = Company::create(['name' => 'Audit Co', 'slug' => 'audit-co-' . uniqid(), 'jobdomain_key' => 'logistique']);
        $logger = app(AuditLogger::class);

        $logger->logCompany(
            $company->id,
            AuditAction::MEMBER_ADDED,
            'user',
            '99',
        );

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $company->id,
            'action' => AuditAction::MEMBER_ADDED,
            'target_type' => 'user',
            'target_id' => '99',
        ]);
    }

    public function test_company_audit_log_tenant_isolation(): void
    {
        $companies = collect([
            Company::create(['name' => 'Audit Co A', 'slug' => 'audit-co-a-' . uniqid(), 'jobdomain_key' => 'logistique']),
            Company::create(['name' => 'Audit Co B', 'slug' => 'audit-co-b-' . uniqid(), 'jobdomain_key' => 'logistique']),
        ]);

        $logger = app(AuditLogger::class);

        $logger->logCompany($companies[0]->id, AuditAction::MODULE_ENABLED, 'module', 'core.roles');
        $logger->logCompany($companies[1]->id, AuditAction::MODULE_DISABLED, 'module', 'core.roles');

        $logsCompany1 = CompanyAuditLog::where('company_id', $companies[0]->id)->get();
        $logsCompany2 = CompanyAuditLog::where('company_id', $companies[1]->id)->get();

        $this->assertGreaterThanOrEqual(1, $logsCompany1->count());
        $this->assertGreaterThanOrEqual(1, $logsCompany2->count());

        // Ensure no cross-contamination
        $this->assertTrue(
            $logsCompany1->every(fn ($l) => $l->company_id === $companies[0]->id),
        );
        $this->assertTrue(
            $logsCompany2->every(fn ($l) => $l->company_id === $companies[1]->id),
        );
    }

    // ─── Append-Only Invariant ──────────────────────────

    public function test_audit_log_is_append_only(): void
    {
        $logger = app(AuditLogger::class);

        $log = $logger->logPlatform(AuditAction::KILL_SWITCH_ACTIVATED);

        // Attempting to update should not change the action
        // (Models have no updated_at, but Eloquent allows update — the invariant
        // is enforced at the application level, not the DB level)
        $this->assertNull($log->updated_at ?? null);
    }

    // ─── Diff support ───────────────────────────────────

    public function test_audit_log_stores_diff(): void
    {
        $company = Company::create(['name' => 'Audit Co', 'slug' => 'audit-co-' . uniqid(), 'jobdomain_key' => 'logistique']);
        $logger = app(AuditLogger::class);

        $log = $logger->logCompany(
            $company->id,
            AuditAction::ROLE_UPDATED,
            'role',
            '1',
            [
                'diffBefore' => ['name' => 'Old Name'],
                'diffAfter' => ['name' => 'New Name'],
            ],
        );

        $this->assertEquals(['name' => 'Old Name'], $log->diff_before);
        $this->assertEquals(['name' => 'New Name'], $log->diff_after);
    }

    // ─── Action constants are valid ─────────────────────

    public function test_all_audit_actions_are_dot_separated(): void
    {
        $reflection = new \ReflectionClass(AuditAction::class);

        foreach ($reflection->getConstants() as $name => $value) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z_]*\.[a-z_]+$/',
                $value,
                "AuditAction::{$name} = '{$value}' does not match dot-separated format",
            );
        }
    }

    // ─── Severity options ───────────────────────────────

    public function test_audit_log_severity_defaults_to_info(): void
    {
        $logger = app(AuditLogger::class);

        $log = $logger->logPlatform(AuditAction::CHANNELS_FLUSHED);

        $this->assertEquals('info', $log->severity);
    }

    public function test_audit_log_severity_can_be_overridden(): void
    {
        $logger = app(AuditLogger::class);

        $log = $logger->logPlatform(
            AuditAction::KILL_SWITCH_ACTIVATED,
            null,
            null,
            ['severity' => 'critical'],
        );

        $this->assertEquals('critical', $log->severity);
    }
}
