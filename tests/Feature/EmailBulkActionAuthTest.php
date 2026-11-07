<?php

namespace Tests\Feature;

use App\Core\Email\EmailThread;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\PlatformModule;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-733.2 (P0-D.2) — EmailBulkActionService est une surface ADMIN PLATFORM (boîte email partagée),
 * PAS une surface Company-tenant : route `POST /platform/email/inbox/bulk`, gated `manage_email`, user
 * `platform`. `company_id` y est un FILTRE optionnel, pas une frontière tenant → l'absence de
 * `where('company_id')` dans le service est CORRECTE (le flag P0-D.1 était un faux positif).
 * La vraie frontière de sécurité est donc le RBAC PLATFORM : on la prouve ici (sens du REFUS).
 */
class EmailBulkActionAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Jobdomains\Jobdomain::firstOrCreate(['key' => 'logistique'], ['label' => 'L', 'default_modules' => []]);
        PlatformModule::where('key', 'platform.email')->update(['is_enabled_globally' => true]);
    }

    private function platformUserWith(array $permissionKeys): PlatformUser
    {
        $role = PlatformRole::create(['key' => 'r_'.uniqid(), 'name' => 'R']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissionKeys)->pluck('id')->all());
        $u = PlatformUser::create(['first_name' => 'P', 'last_name' => 'U', 'email' => uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach($role->id);

        return $u;
    }

    private function thread(?int $companyId): EmailThread
    {
        return EmailThread::create([
            'subject' => 'S', 'company_id' => $companyId, 'participant_email' => uniqid().'@x.com',
            'folder' => 'inbox', 'is_starred' => false, 'unread_count' => 1,
        ]);
    }

    // 1. REFUS : un user Platform SANS manage_email ne peut pas agir en masse (frontière RBAC).
    public function test_bulk_denied_without_manage_email(): void
    {
        $t = $this->thread(null);
        $user = $this->platformUserWith(['view_dashboard']); // pas de manage_email
        $this->actingAs($user, 'platform')
            ->postJson('/api/platform/email/inbox/bulk', ['ids' => [$t->id], 'action' => 'star'])
            ->assertForbidden();
        $this->assertFalse((bool) $t->fresh()->is_starred, 'aucune modification sans droit');
    }

    // 2. AUTORISÉ : un admin Platform avec manage_email agit GLOBALEMENT sur la boîte partagée (by design).
    //    company_id n'est pas une frontière : l'admin gère les fils de toutes origines.
    public function test_bulk_allowed_with_manage_email_operates_globally(): void
    {
        $c = Company::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $withCompany = $this->thread($c->id);
        $platformThread = $this->thread(null);
        $user = $this->platformUserWith(['view_dashboard', 'manage_email']);

        $this->actingAs($user, 'platform')
            ->postJson('/api/platform/email/inbox/bulk', ['ids' => [$withCompany->id, $platformThread->id], 'action' => 'star'])
            ->assertOk();

        $this->assertTrue((bool) $withCompany->fresh()->is_starred);
        $this->assertTrue((bool) $platformThread->fresh()->is_starred, 'admin platform agit sur toute la boîte (pas de frontière company_id)');
    }

    // 3. REFUS : un utilisateur Company (guard web) ne peut PAS atteindre la surface Platform email.
    public function test_company_user_cannot_reach_platform_email_bulk(): void
    {
        $t = $this->thread(null);
        $user = User::factory()->create();
        $res = $this->actingAs($user) // guard par défaut (web), PAS platform
            ->postJson('/api/platform/email/inbox/bulk', ['ids' => [$t->id], 'action' => 'star']);
        $this->assertContains($res->status(), [401, 403], 'un user non-platform est refusé');
        $this->assertFalse((bool) $t->fresh()->is_starred);
    }
}
