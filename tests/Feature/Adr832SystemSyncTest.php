<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Core\Fields\FieldDefinition;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\LegalStatus;
use App\Core\Models\Company;
use App\Core\Modules\PlatformModule;
use App\Core\Notifications\NotificationTopic;
use App\Core\Plans\Plan;
use App\Core\System\SystemSync;
use App\Modules\Platform\Plans\UseCases\UpsertPlanUseCase;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Tests\TestCase;

/**
 * ADR-832 — a deployment updates the software without overwriting decisions taken in Platform.
 */
class Adr832SystemSyncTest extends TestCase
{
    use RefreshDatabase;

    private function sync(array $adopt = []): array
    {
        SystemSync::begin($adopt);
        (new SystemSeeder)->setContainer(app())->__invoke();
        $report = SystemSync::report();
        SystemSync::end();

        return $report;
    }

    private static function of(array $report, string $type, ?string $subject = null): array
    {
        return array_values(array_filter($report, fn ($e) => $e['type'] === $type && ($subject === null || $e['subject'] === $subject)));
    }

    private static function fingerprint(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            $rows = DB::table($t)->get()->map(fn ($r) => (array) $r)->map(function ($r) {
                unset($r['updated_at'], $r['created_at']);

                return $r;
            })->sortBy(fn ($r) => json_encode($r))->values()->all();
            $out[$t] = hash('sha256', json_encode($rows));
        }

        return $out;
    }

    private const CATALOG = ['plans', 'platform_modules', 'jobdomains', 'markets', 'legal_statuses', 'languages', 'market_language',
        'company_permissions', 'platform_permissions', 'platform_roles', 'platform_role_permission', 'notification_topics',
        'field_definitions', 'market_rule_sets', 'convention_collectives', 'convention_collective_rules', 'document_templates',
        'platform_payment_modules', 'platform_payment_method_rules', 'presets', 'system_sync_baselines'];

    public function test_blank_install_creates_everything_and_a_second_run_changes_nothing(): void
    {
        $first = $this->sync();
        $this->assertNotEmpty(self::of($first, 'created', 'plans'));
        $this->assertGreaterThan(0, Plan::count());
        $this->assertGreaterThan(0, PlatformModule::count());
        $this->assertSame([], SystemSync::conflicts(), 'a blank install has no conflict');
        $admin = PlatformRole::where('key', 'admin')->first();
        $this->assertSame(PlatformPermission::count() - count(PlatformPermissionCatalog::EXPLICIT_GRANT_ONLY), $admin->permissions()->count(), 'admin is offered every permission on a blank install — except the explicit-only ones (ADR-852)');
        $this->assertGreaterThan(0, DB::table('system_sync_baselines')->count());

        $before = self::fingerprint(self::CATALOG);
        $second = $this->sync();
        $this->assertSame([], array_values(array_filter($second, fn ($e) => $e['type'] !== 'preserved_removal')), 'idempotent: nothing created, updated or in conflict');
        $this->assertSame($before, self::fingerprint(self::CATALOG));
    }

    public function test_platform_decisions_survive_a_deployment_and_conflicts_are_reported(): void
    {
        $this->sync();
        $company = Company::create(['name' => 'Recette ADR-832', 'slug' => 'recette-adr832', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
        $companyTables = ['companies', 'company_modules', 'company_roles', 'company_role_permission', 'memberships', 'users'];
        $companyTables = array_values(array_filter($companyTables, fn ($t) => \Schema::hasTable($t)));

        // Platform decisions
        $plan = Plan::where('key', 'pro')->first() ?? Plan::orderByDesc('level')->first();
        app(UpsertPlanUseCase::class)->execute($plan->id, array_merge($plan->only('key', 'name', 'level', 'description', 'is_popular', 'trial_days', 'feature_labels', 'limits'), [
            'price_monthly' => 123.45, 'price_yearly' => 1234.5,
        ]));
        $ls = LegalStatus::where('is_vat_applicable', true)->first();
        $ls->update(['vat_rate' => 7.7]);
        $jd = Jobdomain::where('key', 'logistique')->first();
        $jd->update(['label' => 'Transport (personnalisé)', 'default_modules' => array_values(array_slice($jd->default_modules ?? [], 0, 1))]);
        $topic = NotificationTopic::query()->first();
        $topic->update(['label' => 'Libellé Platform', 'default_channels' => ['in_app']]);
        $field = FieldDefinition::whereNull('company_id')->where('is_system', true)->first();
        $field->update(['label' => 'Champ renommé']);
        $module = PlatformModule::query()->first();
        $module->update(['is_enabled_globally' => false]);
        $admin = PlatformRole::where('key', 'admin')->first();
        $removed = $admin->permissions()->first();
        $admin->permissions()->detach($removed->id);
        $admin->update(['name' => 'Administrateurs Leezr']);
        $custom = PlatformRole::create(['key' => 'support_custom', 'name' => 'Support']);
        $custom->permissions()->sync(PlatformPermission::limit(2)->pluck('id'));
        $customPerms = $custom->permissions()->pluck('platform_permissions.id')->sort()->values()->all();
        $manual = DB::table('platform_payment_method_rules')->where('method_key', 'manual')->first();
        DB::table('platform_payment_method_rules')->where('id', $manual->id)->update(['is_active' => true, 'priority' => 7]);

        // A new technical definition arrives with the deployment (simulated: absent from the database)
        $newPerm = PlatformPermission::where('key', '!=', $removed->key)->orderByDesc('id')->first();
        $newPermKey = $newPerm->key;
        DB::table('platform_role_permission')->where('platform_permission_id', $newPerm->id)->delete();
        $newPerm->delete();
        DB::table('system_sync_baselines')->where('subject', 'platform_role_permission')->where('field', 'member:'.$newPermKey)->delete();
        $newCompanyPerm = CompanyPermission::orderByDesc('id')->first()->key;
        CompanyPermission::where('key', $newCompanyPerm)->delete();
        $newModule = PlatformModule::orderByDesc('id')->first()->key;
        PlatformModule::where('key', $newModule)->delete();

        $presets = self::fingerprint(['presets']);
        $companyBefore = self::fingerprint($companyTables);

        $report = $this->sync();

        // 1. new technical definitions installed
        $this->assertTrue(PlatformPermission::where('key', $newPermKey)->exists());
        $this->assertTrue(CompanyPermission::where('key', $newCompanyPerm)->exists());
        $this->assertTrue(PlatformModule::where('key', $newModule)->exists());
        $this->assertTrue($admin->fresh()->permissions()->where('key', $newPermKey)->exists(), 'a NEW permission is offered to admin');
        // 2. administered values unchanged
        $this->assertSame(12345, $plan->fresh()->price_monthly);
        $this->assertSame(123450, $plan->fresh()->price_yearly);
        $this->assertEquals(7.7, (float) $ls->fresh()->vat_rate);
        $this->assertSame('Transport (personnalisé)', $jd->fresh()->label);
        $this->assertSame(array_values(array_slice($jd->default_modules ?? [], 0, 1)), $jd->fresh()->default_modules);
        $this->assertSame('Libellé Platform', $topic->fresh()->label);
        $this->assertSame('Champ renommé', $field->fresh()->label);
        $this->assertFalse((bool) $module->fresh()->is_enabled_globally);
        $m = DB::table('platform_payment_method_rules')->where('id', $manual->id)->first();
        $this->assertSame([1, 7], [(int) $m->is_active, (int) $m->priority]);
        // 3. roles preserved
        $this->assertFalse($admin->fresh()->permissions()->where('platform_permissions.id', $removed->id)->exists(), 'a permission removed in Platform is not re-imposed');
        $this->assertSame('Administrateurs Leezr', $admin->fresh()->name);
        $this->assertSame($customPerms, $custom->permissions()->pluck('platform_permissions.id')->sort()->values()->all());
        // 4. published presets intact ; 5. Company data unchanged
        $this->assertSame($presets, self::fingerprint(['presets']));
        $this->assertSame($companyBefore, self::fingerprint($companyTables));
        $this->assertTrue($company->fresh()->exists);
        // 7. conflicts reported (administered value differs from the code value)
        $fields = array_map(fn ($e) => $e['subject'].':'.$e['field'], self::of($report, 'conflict'));
        foreach (['plans:price_monthly', 'plans:price_yearly', 'legal_statuses:vat_rate', 'jobdomains:label', 'jobdomains:default_modules', 'notification_topics:label', 'field_definitions:label'] as $expected) {
            $this->assertContains($expected, $fields);
        }
        $this->assertNotEmpty(self::of($report, 'preserved_removal', 'platform_role_permission'));
        $this->assertSame([], self::of($report, 'evolved'), 'nothing administered is evolved when Platform changed it');

        // 6. a second run: no unexpected difference (same conflicts, no write)
        $catalog = self::fingerprint(self::CATALOG);
        $again = $this->sync();
        $this->assertSame($catalog, self::fingerprint(self::CATALOG));
        $this->assertSame([], array_values(array_filter($again, fn ($e) => ! in_array($e['type'], ['conflict', 'preserved_removal'], true))));
    }

    public function test_an_unchanged_value_follows_a_code_evolution(): void
    {
        $this->sync();
        $plan = Plan::orderBy('level')->first();
        $codePrice = $plan->price_monthly;
        // as if the PREVIOUS code release had written 999 and nobody edited it since
        $plan->forceFill(['price_monthly' => 999])->save();
        DB::table('system_sync_baselines')->where(['subject' => 'plans', 'subject_key' => $plan->key, 'field' => 'price_monthly'])
            ->update(['value' => '999', 'value_hash' => hash('sha256', '999')]);

        $report = $this->sync();

        $this->assertSame($codePrice, $plan->fresh()->price_monthly);
        $this->assertCount(1, array_filter(self::of($report, 'evolved', 'plans'), fn ($e) => $e['field'] === 'price_monthly'));
    }

    public function test_historical_install_without_baseline_preserves_then_adopt_applies_on_purpose(): void
    {
        $this->sync();
        DB::table('system_sync_baselines')->delete(); // install seeded before ADR-832
        $jd = Jobdomain::where('key', 'logistique')->first();
        $codeRoles = $jd->default_roles;
        $old = $codeRoles;
        $old['__legacy'] = ['name' => 'old'];
        $jd->forceFill(['default_roles' => $old])->save();

        $report = $this->sync();
        $this->assertEquals($old, $jd->fresh()->default_roles, 'unknown provenance: preserved');
        $c = array_values(array_filter(self::of($report, 'conflict', 'jobdomains'), fn ($e) => $e['field'] === 'default_roles'));
        $this->assertSame('unknown', $c[0]['baseline']);
        $this->assertSame('jobdomains:logistique:default_roles', $c[0]['adopt']);

        $adopted = $this->sync(['jobdomains:logistique:default_roles']);
        $this->assertEquals($codeRoles, $jd->fresh()->default_roles);
        $this->assertNotEmpty(self::of($adopted, 'adopted', 'jobdomains'));
        $this->assertSame([], array_values(array_filter(self::of($this->sync(), 'conflict', 'jobdomains'), fn ($e) => $e['field'] === 'default_roles')));
    }

    public function test_structural_divergence_is_reported_never_applied(): void
    {
        $this->sync();
        $field = FieldDefinition::whereNull('company_id')->where('is_system', true)->where('type', 'string')->first()
            ?? FieldDefinition::whereNull('company_id')->where('is_system', true)->first();
        $type = $field->type;
        $field->forceFill(['type' => $type === 'string' ? 'text' : 'string'])->save();

        $report = $this->sync();

        $this->assertNotSame($type, $field->fresh()->type);
        $this->assertNotEmpty(array_filter(self::of($report, 'structural_conflict', 'field_definitions'), fn ($e) => $e['field'] === 'type' && $e['key'] === '|'.$field->code));
    }

    public function test_dry_run_keeps_nothing(): void
    {
        $this->sync();
        Plan::query()->delete();
        $path = sys_get_temp_dir().'/adr832-dry-'.uniqid().'.json';

        Artisan::call('system:sync', ['--dry-run' => true, '--json' => $path]);

        $this->assertSame(0, Plan::count(), 'dry run rolled back');
        $json = json_decode(file_get_contents($path), true);
        $this->assertTrue($json['dry_run']);
        $this->assertGreaterThan(0, $json['summary']['created'] ?? 0);
        @unlink($path);
    }
}
