<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-818 (chantier K) — a re-invited person who logs in before accepting never lands on an empty company :
 * an invitation pending acceptance is not an active company (it stays in /my-invitations).
 */
class Adr818AccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_invitation_is_not_an_active_company(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $a = Company::create(['name' => 'Active', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $b = Company::create(['name' => 'Invited', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $a->id, 'role' => 'owner']);
        Membership::create(['user_id' => $u->id, 'company_id' => $b->id, 'role' => 'user', 'acceptance_pending_at' => now()]);

        $ids = collect($this->actingAs($u)->getJson('/api/my-companies')->assertOk()->json('companies'))->pluck('id')->all();
        $this->assertSame([$a->id], $ids, 'the pending invitation is not offered as the current company');

        $only = User::factory()->create();
        Membership::create(['user_id' => $only->id, 'company_id' => $b->id, 'role' => 'user', 'acceptance_pending_at' => now()]);
        $this->assertSame([], $this->actingAs($only)->getJson('/api/my-companies')->assertOk()->json('companies'));
    }
}
