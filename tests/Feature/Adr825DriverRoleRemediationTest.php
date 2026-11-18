<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-825 — controlled remediation of historical driver roles : simulation by default, explicit database
 * confirmation to apply, targeted removal (delivery rights kept), audit with batch id, safe resume,
 * customised roles left to the company, controlled restore, tenant isolation.
 */
class Adr825DriverRoleRemediationTest extends TestCase
{
    use RefreshDatabase;

    private function driver(string $name, array $perms): CompanyRole
    {
        $c = Company::create(['name' => $name, 'slug' => $name.'-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'driver', 'name' => 'Chauffeur', 'is_system' => true, 'is_administrative' => false]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id'));

        return $role;
    }

    private function run_(array $args): array
    {
        Artisan::call('rbac:driver-role-remediate', $args);

        return json_decode(Artisan::output(), true) ?? [];
    }

    private function keys(CompanyRole $r): array
    {
        return $r->fresh()->permissions()->pluck('key')->sort()->values()->all();
    }

    public function test_simulation_apply_resume_customisation_restore(): void
    {
        CompanyPermissionCatalog::sync();
        $full = ['members.view', 'settings.view', 'documents.view', 'shipments.view_own', 'shipments.manage_status'];
        $a = $this->driver('alpha', $full);
        $b = $this->driver('beta', $full);
        $custom = $this->driver('gamma', $full);
        DB::table('company_audit_logs')->insert(['id' => (string) \Illuminate\Support\Str::ulid(), 'company_id' => $custom->company_id, 'action' => 'role.capabilities_changed',
            'target_type' => 'company_role', 'target_id' => (string) $custom->id, 'severity' => 'info', 'actor_type' => 'user', 'created_at' => now()]);

        // Simulation : nothing written
        $sim = $this->run_([]);
        $this->assertSame('simulation', $sim['mode']);
        $this->assertSame(['would_apply' => 2, 'skipped_customised' => 1], $sim['summary']);
        $this->assertCount(5, $this->keys($a));

        // Apply refused without database confirmation
        $this->assertSame(1, Artisan::call('rbac:driver-role-remediate', ['--apply' => true]));
        $this->assertCount(5, $this->keys($a));

        // Apply limited to company alpha (as after an interruption), then resume on everything
        $db = DB::connection()->getDatabaseName();
        $first = $this->run_(['--apply' => true, '--confirm-database' => $db, '--company' => [$a->company_id]]);
        $this->assertSame(['shipments.manage_status', 'shipments.view_own'], $this->keys($a), 'only the three rights removed');
        $this->assertCount(5, $this->keys($b), 'other company untouched');
        $resume = $this->run_(['--apply' => true, '--confirm-database' => $db]);
        $this->assertSame(['already_compliant' => 1, 'applied' => 1, 'skipped_customised' => 1], $resume['summary'], 'resume skips done roles');
        $this->assertCount(5, $this->keys($custom), 'customised role left to the company');

        // Audit with batch, before / after
        $log = DB::table('company_audit_logs')->where('action', 'role.remediation_applied')->where('target_id', (string) $a->id)->first();
        $this->assertSame($first['batch'], json_decode($log->metadata, true)['batch']);
        $this->assertContains('members.view', json_decode($log->diff_before, true)['permissions']);

        // Customised role only with an explicit --role
        $this->run_(['--apply' => true, '--confirm-database' => $db, '--role' => [$custom->id]]);
        $this->assertSame(['shipments.manage_status', 'shipments.view_own'], $this->keys($custom));

        // Controlled restore of the first batch : simulation, then apply, then idempotent
        $this->assertSame(['would_restore' => 1], $this->run_(['--restore' => $first['batch']])['summary']);
        $this->assertSame(['restored' => 1], $this->run_(['--restore' => $first['batch'], '--apply' => true, '--confirm-database' => $db])['summary']);
        $this->assertCount(5, $this->keys($a));
        $this->assertCount(2, $this->keys($b), 'restore limited to the batch');
        $this->assertSame(['already_restored' => 1], $this->run_(['--restore' => $first['batch'], '--apply' => true, '--confirm-database' => $db])['summary']);
    }

    public function test_delivery_rights_are_never_removed_and_gaps_reported(): void
    {
        CompanyPermissionCatalog::sync();
        $r = $this->driver('delta', ['members.view', 'shipments.view_own']);
        $out = $this->run_(['--apply' => true, '--confirm-database' => DB::connection()->getDatabaseName()]);
        $this->assertSame(['shipments.view_own'], $this->keys($r));
        $this->assertArrayHasKey('warning', $out['roles'][0], 'missing delivery right reported, not added');
    }
}
