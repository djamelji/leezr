<?php

namespace Tests\Feature;

use App\Core\Audit\PlatformAuditLog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-311: Audit export tests.
 */
class AuditExportTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();

        $this->admin = PlatformUser::create([
            'first_name' => 'Audit',
            'last_name' => 'Admin',
            'email' => 'audit-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    public function test_export_requires_platform_admin(): void
    {
        // A regular company user cannot access platform routes (auth:platform guard rejects)
        $user = User::create([
            'first_name' => 'Regular',
            'last_name' => 'User',
            'email' => 'regular@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/platform/billing/audit-export?start_date=2026-01-01&end_date=2026-12-31');

        // 401 because auth:platform guard does not authenticate a regular User
        $response->assertStatus(401);
    }

    public function test_export_json_returns_audit_logs(): void
    {
        PlatformAuditLog::create([
            'actor_id' => $this->admin->id,
            'actor_type' => 'platform_user',
            'action' => 'invoice.voided',
            'target_type' => 'invoice',
            'target_id' => '1',
            'severity' => 'medium',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/billing/audit-export?start_date=' . now()->subDay()->format('Y-m-d') . '&end_date=' . now()->addDay()->format('Y-m-d') . '&format=json');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['action' => 'invoice.voided']);
    }

    public function test_export_csv_returns_stream(): void
    {
        PlatformAuditLog::create([
            'actor_id' => $this->admin->id,
            'actor_type' => 'platform_user',
            'action' => 'test.csv',
            'target_type' => 'test',
            'target_id' => '1',
            'severity' => 'low',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->get('/api/platform/billing/audit-export?start_date=' . now()->subDay()->format('Y-m-d') . '&end_date=' . now()->addDay()->format('Y-m-d') . '&format=csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_export_respects_date_range(): void
    {
        PlatformAuditLog::create([
            'actor_id' => $this->admin->id,
            'actor_type' => 'platform_user',
            'action' => 'in_range',
            'target_type' => 'test',
            'target_id' => '1',
            'severity' => 'low',
            'created_at' => '2026-03-05 12:00:00',
        ]);

        PlatformAuditLog::create([
            'actor_id' => $this->admin->id,
            'actor_type' => 'platform_user',
            'action' => 'out_of_range',
            'target_type' => 'test',
            'target_id' => '2',
            'severity' => 'low',
            'created_at' => '2026-01-01 12:00:00',
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/billing/audit-export?start_date=2026-03-01&end_date=2026-03-31&format=json');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['action' => 'in_range']);
    }
}
