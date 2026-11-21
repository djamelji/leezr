<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Billing\SubscriptionCanceller;
use App\Core\DataExport\CompanyDataExport;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Core\Workforce\Employee;
use App\Core\Workforce\TimeEntry;
use App\Modules\Core\DataExport\CompanyDataExportService;
use App\Modules\Core\DataExport\Jobs\BuildCompanyDataExportJob;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;
use ZipArchive;

/**
 * ADR-846 (Leezr 4.2 M8.1) — reliable data recovery : export while SUSPENDED (only that path opens), complete /
 * partial / failed exports with the missing data shown, and the exceptional Platform procedure (owner unavailable).
 * Setup shared with ADR-845 (same fictitious company).
 *
 * ADR-845 (Leezr 4.2 M8) — end of relationship : lifecycle states from existing data, full data export (owner,
 * password, one at a time, temporary and audited download, expiry, revocation, partial failure), undo of a scheduled
 * cancellation, no deletion anywhere.
 */
class Adr846CompanyDataRecoveryTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private Company $company;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        MarketRegistry::sync();
        PlanRegistry::sync();
        FieldDefinitionCatalog::sync();
        PlatformPermissionCatalog::sync();
        Storage::fake('local');
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-16 10:00:00'));

        $this->company = Company::create(['name' => 'Fin De Contrat', 'slug' => 'fin-de-contrat', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'siret' => '12345678900011']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create(['password' => bcrypt('password'), 'first_name' => 'Olga', 'last_name' => 'Owner']);
        $this->admin = User::factory()->create(['password' => bcrypt('password')]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company->memberships()->create(['user_id' => $this->admin->id, 'role' => 'user', 'company_role_id' => $adminRole->id]);
        Subscription::create(['company_id' => $this->company->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active', 'plan_price_cents' => 2900, 'plan_price_source' => 'catalog', 'is_current' => 1,
            'current_period_start' => '2026-03-01', 'current_period_end' => '2026-04-01']);

        $e = Employee::create(['company_id' => $this->company->id, 'first_name' => 'Émile', 'last_name' => 'Données', 'employee_number' => 'E-1', 'status' => 'active', 'hire_date' => '2025-06-01']);
        TimeEntry::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => '2026-03-10', 'clock_in' => '2026-03-10 08:00:00', 'clock_out' => '2026-03-10 12:00:00', 'status' => 'completed', 'total_worked_minutes' => 240, 'total_break_minutes' => 0]);
        DB::table('shipments')->insert(['company_id' => $this->company->id, 'created_by_user_id' => $this->owner->id, 'reference' => 'SHP-1', 'status' => 'delivered', 'origin_address' => 'Dépôt', 'destination_address' => 'Client', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoices')->insert(['company_id' => $this->company->id, 'number' => 'INV-2026-001', 'amount' => 3480, 'subtotal' => 2900, 'tax_amount' => 580, 'amount_due' => 3480, 'currency' => 'EUR', 'status' => 'open', 'issued_at' => '2026-03-01', 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('local')->put('payslips/e1-2026-02.pdf', '%PDF-1.4 fictive payslip');
        DB::table('published_payslips')->insert(['company_id' => $this->company->id, 'document_subject_type' => 'employee', 'document_subject_id' => $e->id, 'period' => '2026-02',
            'file_path' => 'payslips/e1-2026-02.pdf', 'file_name' => 'bulletin-fevrier.pdf', 'file_sha256' => str_repeat('a', 64), 'file_size_bytes' => 24, 'source' => 'import', 'version_no' => 1, 'created_at' => now()]);

        // another company's data : must never appear
        $other = Company::create(['name' => 'Voisine', 'slug' => 'voisine', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        Employee::create(['company_id' => $other->id, 'first_name' => 'Secret', 'last_name' => 'Voisin', 'employee_number' => 'X-9', 'status' => 'active', 'hire_date' => '2025-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'web')->withHeaders(['X-Company-Id' => $this->company->id, 'X-Locale' => 'fr']);
    }

    private function readyExport(): CompanyDataExport
    {
        $id = $this->as($this->owner)->postJson('/api/company/data-exports', ['password' => 'password'])->assertStatus(202)->json('export.id');

        return CompanyDataExport::findOrFail($id);
    }

    private function zipEntries(CompanyDataExport $export): array
    {
        $zip = new ZipArchive();
        $zip->open(Storage::disk('local')->path($export->path));
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }

        return $out;
    }

    private function operator(array $perms, string $tag): PlatformUser
    {
        $u = PlatformUser::create(['first_name' => 'Op', 'last_name' => $tag, 'email' => "op-$tag@platform.test", 'password' => 'P@ssw0rd!Strong']);
        $r = PlatformRole::create(['key' => "r_$tag", 'name' => "R $tag"]);
        $r->permissions()->sync(PlatformPermission::whereIn('key', $perms)->pluck('id')->all());
        $u->roles()->attach($r);

        return $u;
    }

    private function plainMember(): User
    {
        $u = User::factory()->create(['password' => bcrypt('password')]);
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'plain', 'name' => 'Sans droit']);
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function suspend(): void
    {
        DB::table('companies')->where('id', $this->company->id)->update(['status' => 'suspended']);
    }

    // 1 — suspended company, owner : only the recovery path opens
    public function test_suspended_company_owner_can_request_and_download_only(): void
    {
        $this->suspend();
        $this->as($this->owner)->getJson('/api/company/lifecycle')->assertOk()->assertJsonPath('operational.state', 'suspended')->assertJsonPath('can_export', true);
        $this->as($this->owner)->postJson('/api/company/data-exports', ['password' => 'wrong'])->assertUnprocessable();
        $export = $this->readyExport()->fresh();
        $this->assertSame('ready', $export->status);
        $this->as($this->owner)->get("/api/company/data-exports/{$export->id}/download")->assertOk();
        // ordinary routes stay closed — business, settings, members, modules
        foreach (['/api/workforce/contracts', '/api/workforce/departments', '/api/company/members', '/api/modules', '/api/shipments', '/api/company/roles'] as $route) {
            $this->assertSame(403, $this->as($this->owner)->getJson($route)->status(), "$route must stay closed while suspended");
        }
        $this->as($this->owner)->postJson('/api/workforce/departments', ['name' => 'X'])->assertForbidden();
        // nothing reactivated to allow the export
        $this->assertSame('suspended', DB::table('companies')->where('id', $this->company->id)->value('status'));
        $this->assertSame('active', Subscription::where('company_id', $this->company->id)->value('status'));
    }

    // 2 — suspended company, member without the right
    public function test_suspended_company_member_without_right_is_refused(): void
    {
        $this->suspend();
        $plain = $this->plainMember();
        $this->as($plain)->getJson('/api/company/lifecycle')->assertForbidden();
        $this->as($plain)->postJson('/api/company/data-exports', ['password' => 'password'])->assertForbidden();
        $this->as($this->admin)->postJson('/api/company/data-exports', ['password' => 'password'])->assertForbidden(); // billing right, not owner
        $this->assertSame(0, CompanyDataExport::count());
    }

    // 3 + 4 + 5 — complete, excluded file, partial failure : distinct statuses, missing data listed with its reason
    public function test_complete_partial_and_missing_are_never_confused(): void
    {
        $complete = $this->readyExport()->fresh();
        $this->assertSame('ready', $complete->status);
        $this->assertSame('complete', json_decode($this->zipEntries($complete)['manifest.json'], true)['outcome']);
        $this->assertSame([], $complete->summary()['missing']);

        Carbon::setTestNow(now()->addMinutes(11));
        config(['company_lifecycle.export.max_document_bytes' => 10]); // the payslip no longer fits
        $tooBig = $this->readyExport()->fresh();
        $this->assertSame('partial', $tooBig->status);
        $this->assertSame([['part' => 'documents', 'reason' => 'over_size_budget', 'count' => 1]], $tooBig->summary()['missing']);
        $this->assertTrue($tooBig->isDownloadable(), 'a partial archive is still delivered');

        Carbon::setTestNow(now()->addMinutes(11));
        config(['company_lifecycle.export.max_document_bytes' => 500 * 1024 * 1024]);
        Schema::drop('shipments');
        Storage::disk('local')->delete('payslips/e1-2026-02.pdf');
        $broken = $this->readyExport()->fresh();
        $this->assertSame('partial', $broken->status);
        $manifest = json_decode($this->zipEntries($broken)['manifest.json'], true);
        $this->assertSame('partial', $manifest['outcome']);
        $this->assertEqualsCanonicalizing([['part' => 'shipments', 'reason' => 'generation_failed', 'count' => null], ['part' => 'documents', 'reason' => 'missing_file', 'count' => 1]], $manifest['missing']);
        $this->assertArrayHasKey('workforce/employees.csv', $this->zipEntries($broken), 'several data types still in the archive');
        $this->assertSame('partial', $this->as($this->owner)->getJson('/api/company/lifecycle')->json('exports.0.status'));
        $this->assertTrue(CompanyAuditLog::where('action', 'company_export.ready')->get()->contains(fn ($l) => ($l->metadata['result'] ?? null) === 'partial'));
        $this->assertSame('ready', $complete->fresh()->status, 'earlier archives untouched');
        Storage::disk('local')->assertExists($complete->fresh()->path);
    }

    // 6 — total failure : failed, no archive left behind, earlier archive and data untouched
    public function test_total_failure_is_failed_and_keeps_everything_else(): void
    {
        $previous = $this->readyExport()->fresh();
        Carbon::setTestNow(now()->addMinutes(11));
        // the storage refuses the archive (disk full) : the builder cannot produce anything
        $this->app->bind(\App\Modules\Core\DataExport\CompanyDataExportBuilder::class, fn () => new class
        {
            public function build(): array
            {
                throw new \RuntimeException('disk full');
            }
        });
        $id = $this->as($this->owner)->postJson('/api/company/data-exports', ['password' => 'password'])->assertStatus(202)->json('export.id');
        $this->assertSame('failed', CompanyDataExport::find($id)->status);
        $this->assertFalse(CompanyDataExport::find($id)->isDownloadable());
        $this->as($this->owner)->get("/api/company/data-exports/$id/download")->assertStatus(410);
        $this->assertTrue(CompanyAuditLog::where('action', 'company_export.failed')->exists());
        $this->assertSame('ready', $previous->fresh()->status);
        $this->assertSame(2, Employee::withoutCompanyScope()->count());
    }

    // 7 + 8 + 9 — revoked / expired link, no session, other tenant
    public function test_links_sessions_and_tenants(): void
    {
        $export = $this->readyExport()->fresh();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Company-Id' => $this->company->id])->getJson("/api/company/data-exports/{$export->id}/download")->assertUnauthorized();
        $this->withHeaders(['X-Company-Id' => $this->company->id])->postJson('/api/company/data-exports', ['password' => 'password'])->assertUnauthorized();

        $stranger = User::factory()->create();
        $other = Company::where('slug', 'voisine')->first();
        $other->memberships()->create(['user_id' => $stranger->id, 'role' => 'owner']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($stranger, 'web')->withHeaders(['X-Company-Id' => $other->id])->get("/api/company/data-exports/{$export->id}/download")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($stranger, 'web')->withHeaders(['X-Company-Id' => $this->company->id])->get("/api/company/data-exports/{$export->id}/download")->assertForbidden();

        app(\App\Modules\Core\DataExport\CompanyDataExportService::class)->revoke($export);
        $this->as($this->owner)->get("/api/company/data-exports/{$export->id}/download")->assertStatus(410);
    }

    // 10 — exceptional Platform procedure : permission, justification, recipient, double approval, execution OFF, delivery
    public function test_exceptional_platform_procedure(): void
    {
        $base = "/api/platform/companies/{$this->company->id}/data-exports";
        $payload = ['recipient_user_id' => $this->admin->id, 'justification' => 'Propriétaire décédé — demande du liquidateur, dossier support #4521', 'authority_check' => 'Jugement de liquidation et pièce d’identité vérifiés'];
        $plainOp = $this->operator(['view_companies', 'manage_companies'], 'plain');
        $this->actingAs($plainOp, 'platform')->postJson("$base/exceptional", $payload)->assertForbidden();

        $a = $this->operator(['view_companies', 'exceptional_company_export'], 'a');
        $b = $this->operator(['view_companies', 'exceptional_company_export'], 'b');
        $this->actingAs($a, 'platform')->postJson("$base/exceptional", ['justification' => 'trop court'] + $payload)->assertUnprocessable()->assertJsonValidationErrors('justification');
        $this->actingAs($a, 'platform')->postJson("$base/exceptional", ['recipient_user_id' => User::factory()->create()->id] + $payload)->assertUnprocessable()->assertJsonValidationErrors('recipient_user_id');
        $id = $this->actingAs($a, 'platform')->postJson("$base/exceptional", $payload)->assertCreated()->assertJsonPath('export.status', 'awaiting_approval')->json('export.id');
        $this->actingAs($a, 'platform')->postJson("$base/exceptional", $payload)->assertUnprocessable(); // one at a time

        // the same operator cannot approve ; while execution is OFF a second operator gets 409 and nothing is built
        $this->actingAs($a, 'platform')->postJson("$base/$id/approve")->assertUnprocessable();
        $this->actingAs($b, 'platform')->postJson("$base/$id/approve")->assertStatus(409)->assertJsonPath('code', 'exceptional_export_disabled');
        $this->assertSame('awaiting_approval', CompanyDataExport::find($id)->status);
        $this->assertTrue(CompanyAuditLog::where('action', 'company_export.exceptional_blocked')->exists());

        // once validated (flag ON) : second operator approves, archive built, delivered to the designated member only
        config(['company_lifecycle.exceptional_export.enabled' => true]);
        $this->actingAs($b, 'platform')->postJson("$base/$id/approve")->assertOk()->assertJsonPath('export.status', 'ready');
        Notification::assertSentTo($this->admin, \App\Notifications\DataExport\CompanyDataExportReadyNotification::class);
        $this->as($this->admin)->get("/api/company/data-exports/$id/download")->assertOk();
        $this->assertSame([$id], collect($this->as($this->admin)->getJson('/api/company/lifecycle')->json('exports'))->pluck('id')->all());
        $this->as($this->plainMember())->get("/api/company/data-exports/$id/download")->assertForbidden();
        // no Platform download route exists
        $this->assertContains($this->actingAs($b, 'platform')->get("$base/$id/download")->status(), [404, 405]);
        $this->assertTrue(CompanyAuditLog::where('action', 'company_export.downloaded')->get()->contains(fn ($l) => ($l->metadata['by_recipient'] ?? false) === true));

        // rejection path
        $id2 = $this->actingAs($a, 'platform')->postJson("$base/exceptional", $payload)->assertCreated()->json('export.id');
        $this->actingAs($b, 'platform')->postJson("$base/$id2/reject")->assertOk()->assertJsonPath('export.status', 'rejected');
        $this->actingAs($b, 'platform')->postJson("$base/$id2/approve")->assertUnprocessable();
    }

    // ADR-850 (M10) — a job lost by the worker (timeout / attempts exceeded) : failed, audited, and never blocks
    public function test_a_lost_export_job_is_failed_and_does_not_block_the_company(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Modules\Core\DataExport\Jobs\BuildCompanyDataExportJob::class]);
        $id = $this->as($this->owner)->postJson('/api/company/data-exports', ['password' => 'password'])->assertStatus(202)->json('export.id');
        CompanyDataExport::whereKey($id)->update(['status' => 'running']);
        (new \App\Modules\Core\DataExport\Jobs\BuildCompanyDataExportJob($id))->failed(new \RuntimeException('MaxAttemptsExceeded'));
        $this->assertSame('failed', CompanyDataExport::find($id)->status);
        $this->assertTrue(CompanyAuditLog::where('action', 'company_export.failed')->exists());

        // a preparation stuck « running » beyond the job's maximum duration no longer blocks a new request
        $stuck = CompanyDataExport::create(['company_id' => $this->company->id, 'requested_by_user_id' => $this->owner->id, 'status' => 'running', 'disk' => 'local']);
        $stuck->forceFill(['created_at' => now()->subHours(2)])->save();
        Carbon::setTestNow(now()->addMinutes(11));
        $this->as($this->owner)->postJson('/api/company/data-exports', ['password' => 'password'])->assertStatus(202);
    }
}
