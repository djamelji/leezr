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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-690 (WORKFORCE EXPERIENCE 2026 / E2) — RECETTE multi-permissions.
 *
 * Pour chaque utilisateur fictif (combinaison de permissions, PAS de persona en dur), on vérifie
 * DEUX choses :
 *   1. le MENU : les entrées d'activité visibles correspondent exactement aux capacités réelles ;
 *   2. le SERVEUR : les routes de chaque espace répondent 200 (autorisé) / 403 (interdit).
 *
 * « La disparition d'un menu ne suffit pas à démontrer la sécurité » : chaque espace masqué est
 * aussi prouvé refusé côté serveur, et chaque espace visible est prouvé réellement accessible.
 */
class WorkforceNavE2RecetteTest extends TestCase
{
    use RefreshDatabase;

    private const MODULES = ['workforce', 'workforce_leave', 'workforce_planning', 'workforce_payroll', 'workforce_documents'];

    /** space => [navItemKey, landingRoutePath, readPermission]. */
    private const SPACES = [
        'employees' => ['workforce-employees', '/workforce/employees', 'workforce.view'],
        'time' => ['workforce-time', '/workforce/time-entries', 'workforce.timesheet_view'],
        // ADR-729 (M6.A) — « Planning » est une entrée de nav de premier niveau (page autonome), plus un
        // deep-link vers l'onglet de « Temps & présences » → on vérifie désormais sa visibilité en menu.
        'planning' => ['workforce-planning', '/workforce/planning/locations', 'workforce.planning_view'],
        'leave' => ['workforce-leave', '/workforce/leaves', 'workforce.leave_request'],
        'documents' => ['workforce-documents', '/workforce/documents', 'workforce_documents.view'],
        'payroll' => ['workforce-payroll', '/workforce/payroll', 'workforce.payroll_prepare'],
        'organization' => ['workforce-organization', '/workforce/departments', 'workforce.view'],
        'settings' => ['workforce-settings', '/workforce/settings', 'workforce.view'],
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

        $this->company = Company::create(['name' => 'E2 Co', 'slug' => 'e2-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'hr', 'market_key' => 'FR']);
        foreach (self::MODULES as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
            CompanyModule::create(['company_id' => $this->company->id, 'module_key' => $m, 'is_enabled_for_company' => true]);
        }
        ModuleRegistry::clearCache();
    }

    /** Crée un user + role(perms) + membership ; lie à un Employee si demandé. */
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

    /**
     * Assertion centrale : pour chaque espace, le menu ET le serveur sont cohérents avec les capacités.
     *
     * @param  array<string>  $allowedSpaces  clés de self::SPACES réellement accessibles
     */
    private function assertMatrix(User $user, ?array $perms, array $allowedSpaces): void
    {
        $navKeys = $this->navItemKeys($perms);

        foreach (self::SPACES as $space => [$navKey, $path, $readPerm]) {
            $allowed = in_array($space, $allowedSpaces, true);

            // 1. MENU (si l'espace a une entrée de nav propre).
            if ($navKey !== null) {
                if ($allowed) {
                    $this->assertContains($navKey, $navKeys, "[menu] {$navKey} doit être visible");
                } else {
                    $this->assertNotContains($navKey, $navKeys, "[menu] {$navKey} doit être masqué");
                }
            }

            // 2. SERVEUR : la garde de permission passe (autorisé) ou refuse en 403 (interdit).
            // « Accessible » = la garde laisse passer (statut ≠ 401/403 et pas d'erreur serveur) ;
            // un 422 (paramètres requis) prouve que la permission a été franchie, c'est suffisant.
            $res = $this->actingAs($user)->getJson('/api'.$path, ['X-Company-Id' => $this->company->id]);
            if ($allowed) {
                $this->assertNotSame(403, $res->status(), "[serveur] {$path} ne doit PAS être refusé ({$readPerm})");
                $this->assertNotSame(401, $res->status(), "[serveur] {$path} authentifié");
                $this->assertLessThan(500, $res->status(), "[serveur] {$path} pas d'erreur serveur");
            } else {
                $this->assertSame(403, $res->status(), "[serveur] {$path} doit être refusé (403) sans {$readPerm}");
            }
        }
    }

    // ── Utilisateurs fictifs ───────────────────────────────────────

    public function test_rh_sans_paie(): void
    {
        // RH complète SAUF paie : collaborateurs, temps, congés, documents, organisation, paramètres.
        $perms = ['workforce.view', 'workforce.manage', 'workforce.timesheet_view', 'workforce.planning_view',
            'workforce.leave_request', 'workforce.leave_approve', 'workforce.leave_view_all',
            'workforce_documents.view'];
        $u = $this->userWith($perms, admin: true);
        $this->assertMatrix($u, $perms, ['employees', 'time', 'planning', 'leave', 'documents', 'organization', 'settings']);
    }

    public function test_manager_limite_equipe(): void
    {
        // workforce.view seul : voit la liste (scopée équipe côté serveur), organisation, paramètres.
        $perms = ['workforce.view'];
        $u = $this->userWith($perms, admin: false);
        $this->assertMatrix($u, $perms, ['employees', 'organization', 'settings']);
    }

    public function test_responsable_exploitation(): void
    {
        // Pointages + planning, SANS contrats ni paie.
        $perms = ['workforce.timesheet_view', 'workforce.time_manage', 'workforce.planning_view', 'workforce.planning_manage'];
        $u = $this->userWith($perms, admin: false);
        $this->assertMatrix($u, $perms, ['time', 'planning']);
    }

    public function test_gestionnaire_paie_sans_members_manage(): void
    {
        // Paie uniquement : voit Paie, pas Collaborateurs ni Temps.
        $perms = ['workforce.payroll_prepare', 'workforce.payroll_validate', 'workforce.payroll_export', 'workforce.compensation_read'];
        $u = $this->userWith($perms, admin: true);
        $this->assertMatrix($u, $perms, ['payroll']);
    }

    public function test_salarie(): void
    {
        // Self-service : bundles employé (timesheet_view + leave_request). Il demande ses congés depuis
        // « Mon espace », PAS depuis la vue management → le menu Congés management reste masqué, et
        // /workforce/leaves (liste non filtrée) est refusé côté serveur (403). Données scopées à soi.
        $perms = ['workforce.timesheet_view', 'workforce.timesheet_submit', 'workforce.leave_request'];
        $u = $this->userWith($perms, admin: false, asEmployee: true);
        $this->assertMatrix($u, $perms, ['time']);
        // « Mon espace » (self-service) toujours présent, indépendamment des permissions.
        $this->assertContains('workforce-me', $this->navItemKeys($perms), 'Mon espace visible pour le salarié');
        // Il peut bien créer/consulter SES congés via le portail self-service.
        $this->actingAs($u)->getJson('/api/workforce/me/leaves', ['X-Company-Id' => $this->company->id])->assertOk();
    }

    public function test_owner_voit_tout(): void
    {
        $owner = User::factory()->create();
        Membership::create(['user_id' => $owner->id, 'company_id' => $this->company->id, 'role' => 'owner']);
        // Owner = bypass (perms = null côté nav). Tous les espaces accessibles.
        $this->assertMatrix($owner, null, array_keys(self::SPACES));
    }

    public function test_admin_aussi_salarie_accede_aux_deux_contextes(): void
    {
        // Admin RH ET salarié : nav management complète + accès à son espace personnel /workforce/me.
        $perms = ['workforce.view', 'workforce.manage', 'workforce.timesheet_view', 'workforce.planning_view',
            'workforce.leave_request', 'workforce_documents.view'];
        $u = $this->userWith($perms, admin: true, asEmployee: true);
        $this->assertContains('workforce-employees', $this->navItemKeys($perms));
        $this->assertContains('workforce-me', $this->navItemKeys($perms), 'Mon espace accessible même en admin');
        // Le contexte personnel reste joignable côté serveur.
        $this->actingAs($u)->getJson('/api/workforce/me', ['X-Company-Id' => $this->company->id])->assertOk();
    }

    public function test_combinaison_custom(): void
    {
        // Rôle sur mesure « chef d'équipe » : voit les pointages + approuve les absences de l'équipe
        // (leave_request + leave_approve = capacité de vue management des congés), sans paie ni contrats.
        $perms = ['workforce.timesheet_view', 'workforce.leave_request', 'workforce.leave_approve'];
        $u = $this->userWith($perms, admin: false);
        $this->assertMatrix($u, $perms, ['time', 'leave']);
    }

    public function test_leave_menu_requires_management_capability(): void
    {
        // leave_request SEUL (sans view_all/approve) : PAS de menu Congés management, et /workforce/leaves 403.
        // La composition permission(ET) + anyPermission(OU) exprime la condition réelle d'usage.
        $perms = ['workforce.leave_request'];
        $u = $this->userWith($perms, admin: false);
        $this->assertNotContains('workforce-leave', $this->navItemKeys($perms), 'leave_request seul → pas de menu management');
        $this->actingAs($u)->getJson('/api/workforce/leaves', ['X-Company-Id' => $this->company->id])->assertForbidden();
    }

    public function test_capacite_gestion_sans_lecture_ne_cree_pas_de_menu_mort(): void
    {
        // time_manage SANS timesheet_view : ne peut pas LIRE les pointages → menu Temps masqué
        // ET l'index pointages refusé côté serveur (cohérence menu ⟺ accessibilité réelle).
        $perms = ['workforce.time_manage'];
        $u = $this->userWith($perms, admin: false);
        $this->assertNotContains('workforce-time', $this->navItemKeys($perms), 'menu Temps masqué (pas de lecture)');
        $this->actingAs($u)->getJson('/api/workforce/time-entries', ['X-Company-Id' => $this->company->id])->assertForbidden();
    }
}
