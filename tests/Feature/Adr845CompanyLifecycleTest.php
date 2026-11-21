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
 * ADR-845 (Leezr 4.2 M8) — end of relationship : lifecycle states from existing data, full data export (owner,
 * password, one at a time, temporary and audited download, expiry, revocation, partial failure), undo of a scheduled
 * cancellation, no deletion anywhere.
 */
class Adr845CompanyLifecycleTest extends TestCase
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

    public function test_lifecycle_states_come_from_existing_data(): void
    {
        $l = $this->as($this->admin)->getJson('/api/company/lifecycle')->assertOk();
        $this->assertSame('active', $l->json('commercial.state'));
        $this->assertSame('active', $l->json('operational.state'));
        $this->assertSame(1, $l->json('obligations.unpaid_invoices'));
        $this->assertNull($l->json('data.retention_policy'), 'no invented legal duration');
        $this->assertSame('not_available', $l->json('data.permanent_deletion'));
        $this->assertFalse($l->json('can_export'), 'an administrator who is not the owner cannot export');

        PlatformBillingPolicy::instance()->update(['downgrade_timing' => 'end_of_period']);
        SubscriptionCanceller::cancel($this->company, 'k-1');
        $this->assertSame('cancellation_scheduled', $this->as($this->admin)->getJson('/api/company/lifecycle')->json('commercial.state'));
        // a suspended company is blocked by the existing access middleware (its users included) : the operational
        // state is supervised from Platform — exporting while suspended is a decision left open (ADR-845)
        DB::table('companies')->where('id', $this->company->id)->update(['status' => 'suspended']);
        $op = PlatformUser::create(['first_name' => 'Op', 'last_name' => 'Sup', 'email' => 'sup-845@platform.test', 'password' => 'P@ssw0rd!Strong']);
        $role = PlatformRole::create(['key' => 'r_sup845', 'name' => 'Sup 845']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', ['view_companies'])->pluck('id')->all());
        $op->roles()->attach($role);
        $this->actingAs($op, 'platform')->getJson("/api/platform/companies/{$this->company->id}/lifecycle")->assertOk()->assertJsonPath('operational.state', 'suspended');
    }

    public function test_full_export_content_manifest_and_isolation(): void
    {
        $export = $this->readyExport()->fresh();
        $this->assertSame(CompanyDataExport::READY, $export->status, 'built by the queued job (sync queue in tests)');
        $files = $this->zipEntries($export);

        foreach (['manifest.json', 'company.json', 'members.csv', 'workforce/employees.csv', 'workforce/time/entries_2026.csv', 'logistics/shipments.csv', 'billing/invoices.csv', 'billing/subscriptions.csv', 'audit/company_audit_log.csv', 'documents/index.csv'] as $f) {
            $this->assertArrayHasKey($f, $files, "$f in the archive");
        }
        $payslip = collect(array_keys($files))->first(fn ($n) => str_starts_with($n, 'documents/payslips/'));
        $this->assertNotNull($payslip);
        $this->assertSame('%PDF-1.4 fictive payslip', $files[$payslip], 'the document itself, byte for byte');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $files['workforce/employees.csv']);
        $this->assertStringContainsString('E-1;Données;Émile', $files['workforce/employees.csv']);
        $this->assertStringContainsString('2026-03-10 09:00', $files['workforce/time/entries_2026.csv'], 'M7 export reused (company timezone)');
        $this->assertStringContainsString('INV-2026-001;open', $files['billing/invoices.csv']);
        $this->assertStringContainsString('34,80', $files['billing/invoices.csv']);

        $manifest = json_decode($files['manifest.json'], true);
        $this->assertSame([], $manifest['errors']);
        $this->assertSame(1, collect($manifest['files'])->firstWhere('path', 'workforce/employees.csv')['rows']);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-12-31'], collect($manifest['files'])->firstWhere('path', 'workforce/time/entries_2026.csv')['period']);
        $this->assertNotEmpty($manifest['exclusions']);

        $all = implode("\n", $files);
        $this->assertStringNotContainsString('Secret', $all, 'another company\'s data never exported');
        $this->assertStringNotContainsString('$2y$', $all, 'no password hash');
        $this->assertStringNotContainsString('remember_token', $all);

        Notification::assertSentTo($this->owner, \App\Notifications\DataExport\CompanyDataExportReadyNotification::class);
        $this->assertSame(['company_export.requested', 'company_export.ready'],
            CompanyAuditLog::where('company_id', $this->company->id)->where('action', 'like', 'company_export.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_owner_only_password_and_one_export_at_a_time(): void
    {
        $this->as($this->admin)->postJson('/api/company/data-exports', ['password' => 'password'])->assertForbidden();
        $this->as($this->owner)->postJson('/api/company/data-exports', ['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');

        Bus::fake([BuildCompanyDataExportJob::class]);
        $this->as($this->owner)->postJson('/api/company/data-exports', ['password' => 'password'])->assertStatus(202);
        $this->as($this->owner)->postJson('/api/company/data-exports', ['password' => 'password'])->assertUnprocessable()->assertJsonPath('errors.export.0', __('companyExport.already_running'));
        $this->assertSame(1, CompanyDataExport::where('company_id', $this->company->id)->count(), 'simultaneous requests : one export');
        CompanyDataExport::query()->update(['status' => 'failed']);
        $this->as($this->owner)->postJson('/api/company/data-exports', ['password' => 'password'])->assertUnprocessable(); // cooldown
        Carbon::setTestNow(now()->addMinutes(11));
        $this->as($this->owner)->postJson('/api/company/data-exports', ['password' => 'password'])->assertStatus(202);
    }

    public function test_download_is_owner_only_temporary_and_scoped(): void
    {
        $export = $this->readyExport()->fresh();
        $this->as($this->owner)->get("/api/company/data-exports/{$export->id}/download")->assertOk()->assertHeader('Cache-Control');
        $this->assertSame(1, $export->fresh()->download_count);
        $this->assertTrue(CompanyAuditLog::where('action', 'company_export.downloaded')->exists());
        $this->as($this->admin)->get("/api/company/data-exports/{$export->id}/download")->assertForbidden();

        // another company's owner cannot reach it
        $stranger = User::factory()->create();
        $other = Company::where('slug', 'voisine')->first();
        $other->memberships()->create(['user_id' => $stranger->id, 'role' => 'owner']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($stranger)->withHeaders(['X-Company-Id' => $other->id])->get("/api/company/data-exports/{$export->id}/download")->assertNotFound();

        // expiry : link refused, then the FILE is pruned, the row stays, no company data touched
        Carbon::setTestNow(now()->addDays(8));
        $this->as($this->owner)->get("/api/company/data-exports/{$export->id}/download")->assertStatus(410);
        $path = $export->path;
        $this->assertSame(1, app(CompanyDataExportService::class)->pruneExpired());
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('expired', $export->fresh()->status);
        $this->assertSame(2, Employee::withoutCompanyScope()->count(), 'data retention : nothing deleted');
    }

    public function test_partial_failure_still_delivers_the_rest(): void
    {
        Schema::drop('shipments');
        $export = $this->readyExport()->fresh();
        $this->assertSame('partial', $export->status); // ADR-846 : an archive with missing data is never declared complete
        $manifest = json_decode($this->zipEntries($export)['manifest.json'], true);
        $this->assertSame([['part' => 'shipments', 'reason' => 'generation_failed']], $manifest['errors']);
        $this->assertArrayHasKey('workforce/employees.csv', $this->zipEntries($export));
    }

    public function test_cancellation_scheduled_then_undone_and_platform_supervision(): void
    {
        PlatformBillingPolicy::instance()->update(['downgrade_timing' => 'end_of_period']);
        $this->as($this->owner)->putJson('/api/billing/subscription/cancel', ['idempotency_key' => 'cancel-1'])->assertOk();
        $this->assertTrue(Subscription::where('company_id', $this->company->id)->value('cancel_at_period_end'));
        $this->as($this->owner)->postJson('/api/billing/subscription/undo-cancel')->assertOk()->assertJsonPath('commercial.state', 'active');
        $this->assertTrue(CompanyAuditLog::where('action', 'subscription.cancel_undone')->exists());
        $this->as($this->owner)->postJson('/api/billing/subscription/undo-cancel')->assertUnprocessable();

        $export = $this->readyExport()->fresh();
        $mk = function (array $perms) {
            $u = PlatformUser::create(['first_name' => 'Op', 'last_name' => 'M8', 'email' => Str::lower(Str::random(8)).'@platform.test', 'password' => 'P@ssw0rd!Strong']);
            $r = PlatformRole::create(['key' => 'r_'.Str::random(6), 'name' => 'R '.Str::random(4)]);
            $r->permissions()->sync(PlatformPermission::whereIn('key', $perms)->pluck('id')->all());
            $u->roles()->attach($r);

            return $u;
        };
        $reader = $mk(['view_companies']);
        $this->actingAs($reader, 'platform')->getJson("/api/platform/companies/{$this->company->id}/lifecycle")->assertOk()->assertJsonPath('exports.0.status', 'ready');
        $ending = collect($this->actingAs($reader, 'platform')->getJson('/api/platform/companies?segment=ending')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$this->company->id], $ending, 'ending segment : export available (the neighbour is not listed)');
        $this->actingAs($reader, 'platform')->postJson("/api/platform/companies/{$this->company->id}/data-exports/{$export->id}/revoke")->assertForbidden();
        $manager = $mk(['view_companies', 'manage_companies']);
        $this->actingAs($manager, 'platform')->postJson("/api/platform/companies/{$this->company->id}/data-exports/{$export->id}/revoke")->assertOk()->assertJsonPath('export.status', 'revoked');
        $this->as($this->owner)->get("/api/company/data-exports/{$export->id}/download")->assertStatus(410);
        $this->assertStringNotContainsString('company-exports/', json_encode($this->as($this->owner)->getJson('/api/company/lifecycle')->json('exports.0')), 'the storage path never leaves the server');
    }

    public function test_immediate_cancellation_keeps_access_and_data_and_cannot_be_undone_here(): void
    {
        PlatformBillingPolicy::instance()->update(['downgrade_timing' => 'immediate']);
        $this->as($this->owner)->putJson('/api/billing/subscription/cancel', ['idempotency_key' => 'cancel-now'])->assertOk();
        $l = $this->as($this->owner)->getJson('/api/company/lifecycle')->assertOk();
        $this->assertSame('ended', $l->json('commercial.state'));
        $this->assertSame('active', $l->json('operational.state'), 'access is not cut by a cancellation');
        $this->assertFalse($l->json('commercial.can_undo_cancel'));
        $this->as($this->owner)->postJson('/api/billing/subscription/undo-cancel')->assertUnprocessable();
        $this->assertSame('ready', $this->readyExport()->fresh()->status, 'data can still be retrieved after the end');
        $this->assertSame(1, DB::table('invoices')->where('company_id', $this->company->id)->count(), 'invoices kept');
    }

    public function test_large_export_documents_beyond_budget_are_listed_not_silently_dropped(): void
    {
        config(['company_lifecycle.export.max_document_bytes' => 10]);
        $export = $this->readyExport()->fresh();
        $files = $this->zipEntries($export);
        $this->assertEmpty(array_filter(array_keys($files), fn ($n) => str_starts_with($n, 'documents/payslips/')));
        $this->assertStringContainsString('over_size_budget', $files['documents/index.csv']);
        $manifest = json_decode($files['manifest.json'], true);
        $this->assertSame('1 document file(s)', collect($manifest['exclusions'])->last()['what']);
    }
}
