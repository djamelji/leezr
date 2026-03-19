<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * Shipment workflow — full lifecycle tests.
 *
 * Covers:
 *   - CRUD (create, index, show)
 *   - Status transitions (state machine)
 *   - Assignment to members
 *   - My-deliveries driver endpoint
 *   - Filtering & pagination
 *   - Company scoping / isolation
 *   - Validation errors
 */
class ShipmentWorkflowTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $owner;
    private User $member;
    private User $driver;
    private Company $company;
    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        // --- Company A (primary) ---
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->driver = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Shipment Co',
            'slug' => 'shipment-co',
            'jobdomain_key' => 'logistique',
        ]);

        $this->activateCompanyModules($this->company);
        $this->createActivationReasons($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $adminRole->id,
        ]);

        // Driver with limited permissions (view_own + manage_status)
        $driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_administrative' => false,
        ]);

        $driverPerms = CompanyPermission::whereIn('key', [
            'shipments.view_own',
            'shipments.manage_status',
        ])->pluck('id')->toArray();
        $driverRole->permissions()->sync($driverPerms);

        $this->company->memberships()->create([
            'user_id' => $this->driver->id,
            'role' => 'user',
            'company_role_id' => $driverRole->id,
        ]);

        // --- Company B (isolation check) ---
        $this->otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'jobdomain_key' => 'logistique',
        ]);
        $this->activateCompanyModules($this->otherCompany);
        $this->createActivationReasons($this->otherCompany);
        $this->setUpCompanyRbac($this->otherCompany);
        $this->otherCompany->memberships()->create([
            'user_id' => User::factory()->create()->id,
            'role' => 'owner',
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────

    private function createActivationReasons(Company $company): void
    {
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type !== 'core') {
                CompanyModuleActivationReason::firstOrCreate([
                    'company_id' => $company->id,
                    'module_key' => $key,
                ], [
                    'reason' => CompanyModuleActivationReason::REASON_DIRECT,
                ]);
            }
        }
    }

    private function actAs(User $user, ?Company $company = null)
    {
        $c = $company ?? $this->company;

        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $c->id]);
    }

    private function createShipment(array $overrides = []): Shipment
    {
        return Shipment::create(array_merge([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'reference' => Shipment::generateReference($this->company->id),
            'status' => Shipment::STATUS_DRAFT,
            'origin_address' => '10 Rue de Paris',
            'destination_address' => '25 Av. de Lyon',
            'scheduled_at' => now()->addDay(),
            'notes' => null,
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════════
    // 1. Create shipment
    // ═══════════════════════════════════════════════════════════

    public function test_owner_can_create_shipment(): void
    {
        $response = $this->actAs($this->owner)
            ->postJson('/api/shipments', [
                'origin_address' => '1 Rue A',
                'destination_address' => '2 Rue B',
                'scheduled_at' => now()->addDay()->toIso8601String(),
                'notes' => 'Fragile',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Shipment created.')
            ->assertJsonPath('shipment.status', 'draft');

        $this->assertDatabaseHas('shipments', [
            'company_id' => $this->company->id,
            'origin_address' => '1 Rue A',
            'status' => 'draft',
        ]);
    }

    public function test_shipment_reference_follows_format(): void
    {
        $this->actAs($this->owner)
            ->postJson('/api/shipments', [
                'origin_address' => 'A',
                'destination_address' => 'B',
            ]);

        $shipment = Shipment::where('company_id', $this->company->id)->first();

        $this->assertMatchesRegularExpression(
            '/^SHP-\d{8}-\d{4}$/',
            $shipment->reference,
        );
    }

    public function test_create_shipment_validation_rejects_invalid_scheduled_at(): void
    {
        $response = $this->actAs($this->owner)
            ->postJson('/api/shipments', [
                'scheduled_at' => 'not-a-date',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_at');
    }

    // ═══════════════════════════════════════════════════════════
    // 2. Index & pagination
    // ═══════════════════════════════════════════════════════════

    public function test_index_returns_paginated_shipments(): void
    {
        // Create 3 shipments
        for ($i = 0; $i < 3; $i++) {
            $this->createShipment();
        }

        $response = $this->actAs($this->owner)
            ->getJson('/api/shipments?per_page=2');

        $response->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 3);
    }

    public function test_index_filters_by_status(): void
    {
        $this->createShipment(['status' => Shipment::STATUS_DRAFT]);
        $this->createShipment(['status' => Shipment::STATUS_PLANNED]);
        $this->createShipment(['status' => Shipment::STATUS_PLANNED]);

        $response = $this->actAs($this->owner)
            ->getJson('/api/shipments?status=planned');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_search_by_reference(): void
    {
        $s1 = $this->createShipment();
        $this->createShipment();

        $response = $this->actAs($this->owner)
            ->getJson('/api/shipments?search=' . $s1->reference);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $s1->reference);
    }

    // ═══════════════════════════════════════════════════════════
    // 3. Show
    // ═══════════════════════════════════════════════════════════

    public function test_show_returns_single_shipment(): void
    {
        $shipment = $this->createShipment();

        $response = $this->actAs($this->owner)
            ->getJson("/api/shipments/{$shipment->id}");

        $response->assertOk()
            ->assertJsonPath('shipment.id', $shipment->id)
            ->assertJsonPath('shipment.reference', $shipment->reference);
    }

    // ═══════════════════════════════════════════════════════════
    // 4. Status transitions (state machine)
    // ═══════════════════════════════════════════════════════════

    public function test_transition_draft_to_planned(): void
    {
        $shipment = $this->createShipment(['status' => Shipment::STATUS_DRAFT]);

        $response = $this->actAs($this->member)
            ->putJson("/api/shipments/{$shipment->id}/status", [
                'status' => 'planned',
            ]);

        $response->assertOk()
            ->assertJsonPath('shipment.status', 'planned');

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'status' => 'planned',
        ]);
    }

    public function test_transition_planned_to_in_transit(): void
    {
        $shipment = $this->createShipment(['status' => Shipment::STATUS_PLANNED]);

        $response = $this->actAs($this->member)
            ->putJson("/api/shipments/{$shipment->id}/status", [
                'status' => 'in_transit',
            ]);

        $response->assertOk()
            ->assertJsonPath('shipment.status', 'in_transit');
    }

    public function test_transition_in_transit_to_delivered(): void
    {
        $shipment = $this->createShipment(['status' => Shipment::STATUS_IN_TRANSIT]);

        $response = $this->actAs($this->member)
            ->putJson("/api/shipments/{$shipment->id}/status", [
                'status' => 'delivered',
            ]);

        $response->assertOk()
            ->assertJsonPath('shipment.status', 'delivered');
    }

    public function test_cannot_transition_to_invalid_state(): void
    {
        // draft -> in_transit is not allowed (must go through planned first)
        $shipment = $this->createShipment(['status' => Shipment::STATUS_DRAFT]);

        $response = $this->actAs($this->member)
            ->putJson("/api/shipments/{$shipment->id}/status", [
                'status' => 'in_transit',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', "Cannot transition from 'draft' to 'in_transit'.");

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'status' => 'draft',
        ]);
    }

    public function test_cannot_transition_from_terminal_status(): void
    {
        $delivered = $this->createShipment(['status' => Shipment::STATUS_DELIVERED]);
        $canceled = $this->createShipment(['status' => Shipment::STATUS_CANCELED]);

        $r1 = $this->actAs($this->member)
            ->putJson("/api/shipments/{$delivered->id}/status", ['status' => 'planned']);
        $r1->assertStatus(422);

        $r2 = $this->actAs($this->member)
            ->putJson("/api/shipments/{$canceled->id}/status", ['status' => 'draft']);
        $r2->assertStatus(422);
    }

    public function test_any_non_terminal_status_can_be_canceled(): void
    {
        $draft = $this->createShipment(['status' => Shipment::STATUS_DRAFT]);
        $planned = $this->createShipment(['status' => Shipment::STATUS_PLANNED]);
        $inTransit = $this->createShipment(['status' => Shipment::STATUS_IN_TRANSIT]);

        foreach ([$draft, $planned, $inTransit] as $shipment) {
            $response = $this->actAs($this->member)
                ->putJson("/api/shipments/{$shipment->id}/status", [
                    'status' => 'canceled',
                ]);
            $response->assertOk()
                ->assertJsonPath('shipment.status', 'canceled');
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 5. Assignment
    // ═══════════════════════════════════════════════════════════

    public function test_assign_shipment_to_company_member(): void
    {
        $shipment = $this->createShipment();

        $response = $this->actAs($this->member)
            ->postJson("/api/shipments/{$shipment->id}/assign", [
                'user_id' => $this->driver->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Shipment assigned.')
            ->assertJsonPath('shipment.assigned_to_user_id', $this->driver->id);

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'assigned_to_user_id' => $this->driver->id,
        ]);
    }

    public function test_assign_to_non_member_fails(): void
    {
        $shipment = $this->createShipment();
        $outsider = User::factory()->create();

        $response = $this->actAs($this->member)
            ->postJson("/api/shipments/{$shipment->id}/assign", [
                'user_id' => $outsider->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'User is not a member of this company.');
    }

    // ═══════════════════════════════════════════════════════════
    // 6. Company scoping / isolation
    // ═══════════════════════════════════════════════════════════

    public function test_shipments_are_company_scoped(): void
    {
        // Create a shipment in company A
        $this->createShipment();

        // Create a shipment in company B
        $otherOwner = $this->otherCompany->memberships()->first()->user;
        Shipment::create([
            'company_id' => $this->otherCompany->id,
            'created_by_user_id' => $otherOwner->id,
            'reference' => Shipment::generateReference($this->otherCompany->id),
            'status' => Shipment::STATUS_DRAFT,
        ]);

        // Company A should see only its own shipments
        $response = $this->actAs($this->owner)
            ->getJson('/api/shipments');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company_id', $this->company->id);
    }

    // ═══════════════════════════════════════════════════════════
    // 7. My-deliveries (driver endpoint)
    // ═══════════════════════════════════════════════════════════

    public function test_my_deliveries_returns_only_assigned_shipments(): void
    {
        // Assigned to driver
        $assigned = $this->createShipment(['assigned_to_user_id' => $this->driver->id]);
        // Assigned to someone else
        $this->createShipment(['assigned_to_user_id' => $this->member->id]);
        // Not assigned
        $this->createShipment();

        $response = $this->actAs($this->driver)
            ->getJson('/api/my-deliveries');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);
    }

    public function test_change_status_validates_unknown_status(): void
    {
        $shipment = $this->createShipment();

        $response = $this->actAs($this->member)
            ->putJson("/api/shipments/{$shipment->id}/status", [
                'status' => 'nonexistent_status',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }
}
