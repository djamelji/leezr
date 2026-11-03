<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Navigation\NavBuilder;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Documents\WorkforceDocumentsModule;
use App\Modules\Workforce\Employees\WorkforceModule;
use App\Modules\Workforce\Payroll\WorkforcePayrollModule;
use App\Modules\Workforce\WorkforceLeaveModule;
use App\Modules\Workforce\WorkforcePlanningModule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-691 (WORKFORCE EXPERIENCE 2026 / E2.1) — contrats d'accès des PROFILS MÉTIER DE RÉFÉRENCE.
 *
 * Les utilisateurs sont construits à partir des BUNDLES RÉELS déclarés dans les manifestes (permissions
 * réellement attribuées, jamais de persona en dur). On prouve pour chaque profil : (1) la navigation
 * visible correspond à ses responsabilités ; (2) l'accès serveur concorde (200 autorisé / 403 refusé).
 * On prouve aussi la cohérence interne « manage ⇒ view » de tous les bundles, et le gating par
 * permission des onglets de configuration administrative (Contrats, Signature).
 */
class WorkforceAccessProfilesE21Test extends TestCase
{
    use RefreshDatabase;

    private const MODULES = ['workforce', 'workforce_leave', 'workforce_planning', 'workforce_payroll', 'workforce_documents', 'workforce_contract', 'workforce_signature'];

    /** space => [navItemKey|null, landingRoutePath, readPermission]. */
    private const SPACES = [
        'employees' => ['workforce-employees', '/workforce/employees', 'workforce.view'],
        'time' => ['workforce-time', '/workforce/time-entries', 'workforce.timesheet_view'],
        'planning' => [null, '/workforce/planning/locations', 'workforce.planning_view'],
        'leave' => ['workforce-leave', '/workforce/leaves', 'workforce.leave_view_all'],
        'documents' => ['workforce-documents', '/workforce/documents', 'workforce_documents.view'],
        'payroll' => ['workforce-payroll', '/workforce/payroll', 'workforce.payroll_prepare'],
        'organization' => ['workforce-organization', '/workforce/departments', 'workforce.view'],
        'settings' => ['workforce-settings', '/workforce/settings', 'workforce.view'],
    ];

    /** Capacité de GESTION ⇒ capacité de LECTURE minimale requise (cohérence des bundles). */
    private const MANAGE_IMPLIES_READ = [
        'workforce.manage' => 'workforce.view',
        'workforce.contracts' => 'workforce.view',
        'workforce.compensation_manage' => 'workforce.view',
        'workforce.admin' => 'workforce.view',
        'workforce.time_manage' => 'workforce.timesheet_view',
        'workforce.timesheet_approve' => 'workforce.timesheet_view',
        'workforce.time_approve' => 'workforce.timesheet_view',
        'workforce.planning_manage' => 'workforce.planning_view',
        'workforce.planning_publish' => 'workforce.planning_view',
        'workforce.leave_approve' => 'workforce.leave_request',
        'workforce.leave_view_all' => 'workforce.leave_request',
        'workforce.payroll_validate' => 'workforce.payroll_prepare',
        'workforce.payroll_export' => 'workforce.payroll_prepare',
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'hr', 'label' => 'HR', 'default_modules' => self::MODULES]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (self::MODULES as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'E21 Co', 'slug' => 'e21-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'hr', 'market_key' => 'FR']);
        foreach (self::MODULES as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
            CompanyModule::create(['company_id' => $this->company->id, 'module_key' => $m, 'is_enabled_for_company' => true]);
        }
        ModuleRegistry::clearCache();
    }

    /** Résout les permissions d'un bundle depuis le manifeste du module (source de vérité). */
    private function bundle(string $moduleClass, string $key): array
    {
        $b = collect($moduleClass::manifest()->bundles)->firstWhere('key', $key);
        $this->assertNotNull($b, "bundle {$key} introuvable dans {$moduleClass}");

        return $b['permissions'];
    }

    private function profilePerms(array $bundles): array
    {
        return collect($bundles)->flatMap(fn ($b) => $this->bundle($b[0], $b[1]))->unique()->values()->all();
    }

    private function userWith(array $permKeys, bool $admin = false, bool $asEmployee = false): User
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => $admin]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permKeys)->pluck('id')->toArray());
        Membership::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'role' => 'user', 'company_role_id' => $role->id]);
        if ($asEmployee) {
            Employee::withoutGlobalScopes()->create([
                'company_id' => $this->company->id, 'user_id' => $user->id,
                'first_name' => 'Self', 'last_name' => 'Emp', 'status' => Employee::STATUS_ACTIVE,
                'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
            ]);
        }

        return $user;
    }

    private function navItemKeys(?array $perms): array
    {
        $groups = NavBuilder::forCompany($this->company, $perms, 'management');

        return collect($groups)->flatMap(fn ($g) => array_column($g['items'], 'key'))->all();
    }

    /** Assertion centrale : menu ET serveur cohérents avec les capacités, pour chaque espace. */
    private function assertMatrix(User $user, array $perms, array $allowedSpaces): void
    {
        $navKeys = $this->navItemKeys($perms);

        foreach (self::SPACES as $space => [$navKey, $path, $readPerm]) {
            $allowed = in_array($space, $allowedSpaces, true);

            if ($navKey !== null) {
                if ($allowed) {
                    $this->assertContains($navKey, $navKeys, "[menu] {$navKey} doit être visible");
                } else {
                    $this->assertNotContains($navKey, $navKeys, "[menu] {$navKey} doit être masqué");
                }
            }

            $res = $this->actingAs($user)->getJson('/api'.$path, ['X-Company-Id' => $this->company->id]);
            if ($allowed) {
                $this->assertNotSame(403, $res->status(), "[serveur] {$path} ne doit PAS être refusé ({$readPerm})");
                $this->assertLessThan(500, $res->status(), "[serveur] {$path} pas d'erreur serveur");
            } else {
                $this->assertSame(403, $res->status(), "[serveur] {$path} doit être refusé (403) sans {$readPerm}");
            }
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Cohérence des bundles : manage ⇒ view (sur TOUS les bundles).
    // ══════════════════════════════════════════════════════════════
    public function test_all_bundles_are_internally_coherent_manage_implies_read(): void
    {
        $modules = [WorkforceModule::class, WorkforceLeaveModule::class, WorkforcePlanningModule::class, WorkforcePayrollModule::class, WorkforceDocumentsModule::class];
        foreach ($modules as $moduleClass) {
            foreach ($moduleClass::manifest()->bundles as $b) {
                $perms = $b['permissions'];
                foreach (self::MANAGE_IMPLIES_READ as $manage => $read) {
                    if (in_array($manage, $perms, true)) {
                        $this->assertContains($read, $perms, "bundle {$b['key']} contient {$manage} mais pas {$read} (manage sans lecture → menu mort)");
                    }
                }
            }
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Profils métier de référence (construits depuis les bundles réels).
    // ══════════════════════════════════════════════════════════════

    public function test_consultation_view_only(): void
    {
        $perms = ['workforce.view'];
        $u = $this->userWith($perms);
        // Lecture seule : collaborateurs + organisation + paramètres (general/worktime). Rien d'autre.
        $this->assertMatrix($u, $perms, ['employees', 'organization', 'settings']);
    }

    public function test_manager_bundle(): void
    {
        $perms = $this->bundle(WorkforceModule::class, 'workforce.manager');
        $u = $this->userWith($perms, admin: true);
        // Manager : équipe, temps, planning, congés (approuve), organisation, paramètres. PAS documents ni paie.
        $this->assertMatrix($u, $perms, ['employees', 'time', 'planning', 'leave', 'organization', 'settings']);
        // Peut approuver : voit la file des congés de l'équipe.
        $this->actingAs($u)->getJson('/api/workforce/leaves', ['X-Company-Id' => $this->company->id])->assertOk();
    }

    public function test_operations_bundle(): void
    {
        $perms = $this->bundle(WorkforceModule::class, 'workforce.operations');
        $u = $this->userWith($perms, admin: true);
        // Responsable d'exploitation : temps + planning + équipe + organisation. PAS congés, documents, paie.
        $this->assertMatrix($u, $perms, ['employees', 'time', 'planning', 'organization', 'settings']);
    }

    public function test_rh_bundle(): void
    {
        $perms = $this->profilePerms([
            [WorkforceModule::class, 'workforce.hr_management'],
            [WorkforceLeaveModule::class, 'workforce.leave_management'],
            [WorkforceDocumentsModule::class, 'workforce_documents.management'],
        ]);
        $u = $this->userWith($perms, admin: true);
        // RH : tout sauf la paie.
        $this->assertMatrix($u, $perms, ['employees', 'time', 'planning', 'leave', 'documents', 'organization', 'settings']);
    }

    public function test_payroll_bundle(): void
    {
        $perms = $this->bundle(WorkforcePayrollModule::class, 'workforce.payroll');
        $u = $this->userWith($perms, admin: true);
        // Gestionnaire de paie : paie uniquement (pas de workforce.view → ni collaborateurs/organisation/paramètres).
        $this->assertMatrix($u, $perms, ['payroll']);
    }

    public function test_team_lead_bundle_sees_time(): void
    {
        // Correction E2.1 : le bundle « team » inclut désormais timesheet_view/planning_view.
        $perms = $this->bundle(WorkforceModule::class, 'workforce.team');
        $u = $this->userWith($perms, admin: true);
        $this->assertMatrix($u, $perms, ['employees', 'time', 'planning', 'organization', 'settings']);
    }

    public function test_salarie_self_service(): void
    {
        $perms = $this->profilePerms([
            [WorkforceModule::class, 'workforce.timesheet_employee'],
            [WorkforceLeaveModule::class, 'workforce.leave_employee'],
        ]);
        $u = $this->userWith($perms, asEmployee: true);
        // Salarié : « Temps » (pointages scopés à soi). PAS congés management, PAS planning/organisation/paramètres.
        $this->assertMatrix($u, $perms, ['time']);
        $this->assertContains('workforce-me', $this->navItemKeys($perms), 'Mon espace visible');
        $this->actingAs($u)->getJson('/api/workforce/me/leaves', ['X-Company-Id' => $this->company->id])->assertOk();
    }

    // ══════════════════════════════════════════════════════════════
    // Onglets de configuration administrative : gating par permission.
    // ══════════════════════════════════════════════════════════════
    public function test_settings_admin_tabs_are_permission_gated(): void
    {
        // RH sans contrat/signature : voit general + worktime, PAS contract ni signature.
        $rh = $this->userWith(['workforce.view', 'workforce.admin']);
        $res = $this->actingAs($rh)->getJson('/api/workforce/settings', ['X-Company-Id' => $this->company->id]);
        $res->assertOk();
        $keys = collect($res->json('sections'))->pluck('key')->all();
        $this->assertContains('general', $keys);
        $this->assertContains('worktime', $keys);
        $this->assertNotContains('contract', $keys, 'onglet contrat exige workforce_contract.view');
        $this->assertNotContains('signature', $keys, 'onglet signature exige workforce_signature.admin');

        // Un gestionnaire contrats + admin signature : voit les onglets correspondants.
        $admin = $this->userWith(['workforce.view', 'workforce_contract.view', 'workforce_signature.admin']);
        $keys2 = collect($this->actingAs($admin)->getJson('/api/workforce/settings', ['X-Company-Id' => $this->company->id])->json('sections'))->pluck('key')->all();
        $this->assertContains('contract', $keys2);
        $this->assertContains('signature', $keys2);
    }

    // ══════════════════════════════════════════════════════════════
    // Gestion sans lecture : pas d'élargissement, pas de menu mort, 403 serveur.
    // ══════════════════════════════════════════════════════════════
    public function test_management_without_read_is_denied_everywhere(): void
    {
        $cases = [
            ['perm' => 'workforce.planning_manage', 'navKey' => 'workforce-time', 'path' => '/workforce/planning/locations'],
            ['perm' => 'workforce.time_manage', 'navKey' => 'workforce-time', 'path' => '/workforce/time-entries'],
            ['perm' => 'workforce.leave_approve', 'navKey' => 'workforce-leave', 'path' => '/workforce/leaves'],
            ['perm' => 'workforce.payroll_export', 'navKey' => 'workforce-payroll', 'path' => '/workforce/payroll'],
        ];
        foreach ($cases as $c) {
            $perms = [$c['perm']];
            $u = $this->userWith($perms, admin: true);
            $this->assertNotContains($c['navKey'], $this->navItemKeys($perms), "{$c['perm']} seul → pas de menu {$c['navKey']}");
            $this->actingAs($u)->getJson('/api'.$c['path'], ['X-Company-Id' => $this->company->id])
                ->assertForbidden();
        }
    }

    public function test_owner_sees_everything(): void
    {
        $owner = User::factory()->create();
        Membership::create(['user_id' => $owner->id, 'company_id' => $this->company->id, 'role' => 'owner']);

        // Nav owner (bypass = perms null) : toutes les entrées d'activité.
        $nav = $this->navItemKeys(null);
        foreach (['workforce-employees', 'workforce-time', 'workforce-leave', 'workforce-documents', 'workforce-payroll', 'workforce-organization', 'workforce-settings'] as $k) {
            $this->assertContains($k, $nav);
        }

        // Serveur : owner accède à tous les espaces (jamais 403).
        foreach (self::SPACES as [$navKey, $path, $readPerm]) {
            $res = $this->actingAs($owner)->getJson('/api'.$path, ['X-Company-Id' => $this->company->id]);
            $this->assertNotSame(403, $res->status(), "[serveur owner] {$path} ne doit pas être refusé");
        }
    }
}
