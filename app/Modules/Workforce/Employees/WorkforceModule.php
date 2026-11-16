<?php

namespace App\Modules\Workforce\Employees;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class WorkforceModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'workforce',
            name: 'Workforce',
            description: 'Employee management, contracts, compensation, and time tracking',
            surface: 'hr',
            sortOrder: 200,
            capabilities: new Capabilities(
                navItems: [
                    // W-HR-UX-7 : « Mon espace » (self-service du membre connecté) rejoint le groupe
                    // personnel « Compte » — séparé du RH management (ne pas mélanger Company/Employee).
                    ['key' => 'workforce-me', 'title' => 'My Work', 'to' => ['name' => 'company-workforce-me'], 'icon' => 'tabler-calendar-time', 'surface' => 'hr', 'group' => 'personal', 'sort' => 20, 'requires' => 'employment'],
                    // PRODUCT-UX-3 (P3-B) : l'accueil RH « À traiter » est consolidé dans l'accueil
                    // Company unique (/dashboard, section fixe en tête). Plus d'entrée de nav propre ;
                    // la route company-workforce redirige vers /dashboard (deep-link compat).
                    // E2 (ADR-690) : espace « Ressources humaines » (group workforce), gating fin par activité.
                    // ADR-754 (P2) — l'entrée « Employés » est remplacée par l'entrée unique
                    // « Collaborateurs » (Core, MembersModule). La route employés reste résolvable
                    // (deep-link / fiche RH détaillée), mais n'a plus d'entrée de nav dédiée.
                    // E2 / ADR-729 (M6.A) : « Temps & présences » = pointage/réel (cockpit, pointages, feuilles),
                    // DISTINCT du planning prévisionnel désormais porté par l'entrée « Planning » dédiée.
                    // N'apparaît que si l'utilisateur peut LIRE le pointage (timesheet_view) → pas de menu mort
                    // (time_manage/*_approve seuls ne donnent pas la lecture ; l'index route exige timesheet_view).
                    // ADR-767 : l'entrée unique « Collaborateurs » (Core) est une entrée de STRUCTURE, donc
                    // masquée aux rôles non administratifs. Depuis ADR-754 (retrait de « Employés », surface
                    // `hr`), un manager/RH NON administratif détenant `workforce.view` n'avait plus AUCUNE
                    // entrée vers ses collaborateurs. On restitue cet accès pour ces seuls profils
                    // (`operationalOnly` → jamais en double de l'entrée Core côté management) ; le
                    // périmètre de données reste décidé par WorkforceAccess (soi + rapports, ou RH).
                    ['key' => 'workforce-people', 'title' => 'Collaborators', 'to' => ['name' => 'company-people'], 'icon' => 'tabler-users', 'permission' => 'workforce.view', 'surface' => 'hr', 'operationalOnly' => true, 'group' => 'team', 'sort' => 10],
                    ['key' => 'workforce-time', 'title' => 'Time Tracking', 'to' => ['name' => 'company-workforce-time'], 'icon' => 'tabler-clock', 'permission' => 'workforce.timesheet_view', 'surface' => 'hr', 'group' => 'time', 'sort' => 20],
                    ['key' => 'workforce-organization', 'title' => 'Organization', 'to' => ['name' => 'company-workforce-organization-tab', 'params' => ['tab' => 'departments']], 'icon' => 'tabler-hierarchy-2', 'permission' => 'workforce.view', 'surface' => 'hr', 'group' => 'settings', 'sort' => 20, 'settings' => ['section' => 'organization', 'level' => 'essential']],
                    // ADR-639 (W-B) : « Paramètres RH » (hub agrégateur) remplace l'ancien lien « Configuration »
                    // dans la navigation ; la route configuration reste valide (redirige vers le hub — deep-links).
                    // ADR-792 (L2) : informations & documents demandés par poste — mode parallèle.
                    ['key' => 'workforce-requirements', 'title' => 'Requested information & documents', 'to' => ['name' => 'company-workforce-requirements'], 'icon' => 'tabler-checklist', 'anyPermission' => ['settings.manage', 'documents.configure'], 'surface' => 'structure', 'group' => 'settings', 'sort' => 25, 'settings' => ['section' => 'work', 'level' => 'essential']],
                    ['key' => 'workforce-settings', 'title' => 'HR Settings', 'to' => ['name' => 'company-workforce-settings-tab', 'params' => ['tab' => 'general']], 'icon' => 'tabler-adjustments-cog', 'permission' => 'workforce.view', 'surface' => 'structure', 'group' => 'settings', 'sort' => 30, 'settings' => ['section' => 'work', 'level' => 'regular']],
                ],
                routeNames: [],
                priorityProviders: [\App\Modules\Workforce\Home\WorkforceHomePriorities::class], // ADR-776
                actions: [ // ADR-775 — même intention « Ajouter une personne » pour qui gère les fiches sans inviter
                    ['key' => 'team.add-employee', 'space' => 'team', 'icon' => 'tabler-user-plus', 'to' => ['name' => 'company-people', 'query' => ['action' => 'create']], 'permission' => 'workforce.manage', 'excludePermission' => 'members.invite', 'home' => true, 'primary' => true, 'sort' => 10],
                ],
                middlewareKey: 'workforce',
            ),
            permissions: [
                ['key' => 'workforce.view', 'label' => 'View Employees', 'hint' => 'View employee list and basic details.'],
                ['key' => 'workforce.manage', 'label' => 'Manage Employees', 'hint' => 'Create, edit, and manage employees.'],
                // W-G2 (ADR-642) — capacités distinctes import/export (data egress & création en masse).
                ['key' => 'workforce.export', 'label' => 'Export Employees', 'hint' => 'Export the employee list (within your visibility, no sensitive data).'],
                ['key' => 'workforce.import', 'label' => 'Import Employees', 'is_admin' => true, 'hint' => 'Bulk-import new employees from a CSV file.'],
                ['key' => 'workforce.contracts', 'label' => 'Manage Contracts', 'is_admin' => true, 'hint' => 'Create and manage employment contracts.'],
                ['key' => 'workforce.compensation_read', 'label' => 'View Compensation', 'is_admin' => true, 'hint' => 'View salary and compensation details.'],
                ['key' => 'workforce.compensation_manage', 'label' => 'Manage Compensation', 'is_admin' => true, 'hint' => 'Modify salary and compensation plans.'],
                ['key' => 'workforce.sensitive_read', 'label' => 'View Sensitive Data', 'is_admin' => true, 'hint' => 'Access SSN, IBAN, and other sensitive fields.'],
                ['key' => 'workforce.time_manage', 'label' => 'Manage Time Entries', 'hint' => 'Correct and manage time tracking entries.'],
                ['key' => 'workforce.time_approve', 'label' => 'Approve Timesheets', 'is_admin' => true, 'hint' => 'Validate and approve employee timesheets.'],
                ['key' => 'workforce.admin', 'inert' => true /* ADR-807 : guards nothing (ADR-800/806) — kept, see ADR-807 §2 */, 'label' => 'Workforce Administration', 'is_admin' => true, 'hint' => 'Full workforce administration access.'],
                ['key' => 'workforce.timesheet_view', 'label' => 'View Timesheets', 'hint' => 'View own timesheets.'],
                ['key' => 'workforce.timesheet_submit', 'label' => 'Submit Timesheets', 'hint' => 'Submit timesheets for approval.'],
                ['key' => 'workforce.timesheet_approve', 'label' => 'Approve Timesheets (Company)', 'is_admin' => true, 'hint' => 'Approve or reject any employee\'s submitted timesheets (company-wide).'],
                // B3-checkpoint (ADR-699) — approbation limitée au PÉRIMÈTRE MANAGÉRIAL (rapports directs),
                // à attribuer explicitement (hors bundles par défaut). Jamais d'auto-approbation par cette seule clé.
                ['key' => 'workforce.timesheet_approve_team', 'label' => 'Approve Timesheets (Team)', 'hint' => 'Approve or reject timesheets of your direct reports only (not your own).'],
            ],
            bundles: [
                // E2.1 (ADR-691) — profils de référence cohérents : toute capacité de gestion est
                // empaquetée avec la capacité de LECTURE correspondante (manage ⇒ view au niveau du
                // bundle). La nav/les guards restent gardés sur la lecture ; les endpoints restent
                // stricts (pas d'élargissement implicite des droits).
                [
                    'key' => 'workforce.team',
                    'label' => 'Team Management',
                    'hint' => 'View and manage employees, correct and view time entries.',
                    // + timesheet_view / planning_view : un chef d'équipe VOIT les pointages et le planning.
                    'permissions' => ['workforce.view', 'workforce.manage', 'workforce.time_manage', 'workforce.timesheet_view', 'workforce.planning_view', 'workforce.export'],
                ],
                [
                    'key' => 'workforce.hr_management',
                    'label' => 'HR Management',
                    'hint' => 'Full HR access: employees, contracts, compensation, sensitive data.',
                    // + workforce.view : une RH doit voir la liste des collaborateurs, l'organisation et les
                    // paramètres ; + timesheet_view/planning_view pour la visibilité temps/planning.
                    'permissions' => ['workforce.view', 'workforce.contracts', 'workforce.compensation_read', 'workforce.compensation_manage', 'workforce.sensitive_read', 'workforce.admin', 'workforce.import', 'workforce.export', 'workforce.timesheet_view', 'workforce.planning_view'],
                    'is_admin' => true,
                    'sensitive' => true, // ADR-803 : rémunération et données sensibles — présenté à part
                ],
                [
                    'key' => 'workforce.manager',
                    'label' => 'Team Manager (planning & approvals)',
                    'hint' => 'See your team, build & publish planning, approve timesheets and leave — no compensation.',
                    // Manager opérationnel : équipe + planning + validations congés/feuilles. SANS rémunération,
                    // SANS contrats, SANS paie. VOIT Collaborateurs, Temps, Congés, Organisation.
                    'permissions' => ['workforce.view', 'workforce.timesheet_view', 'workforce.timesheet_approve', 'workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish', 'workforce.leave_request', 'workforce.leave_view_all', 'workforce.leave_approve'],
                    'is_admin' => true,
                ],
                [
                    'key' => 'workforce.operations',
                    'label' => 'Operations Supervisor',
                    'hint' => 'Manage time clocking and planning day-to-day — no contracts, no payroll.',
                    // Responsable d'exploitation : gestion pointages + planning. VOIT Collaborateurs, Temps, Organisation.
                    'permissions' => ['workforce.view', 'workforce.timesheet_view', 'workforce.time_manage', 'workforce.timesheet_approve', 'workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish'],
                    'is_admin' => true,
                ],
                [
                    'key' => 'workforce.time_validation',
                    'label' => 'Time Validation',
                    'hint' => 'View, manage and approve time entries and timesheets.',
                    // + timesheet_view : valider/gérer implique de VOIR les pointages.
                    'permissions' => ['workforce.timesheet_view', 'workforce.time_manage', 'workforce.time_approve'],
                    'is_admin' => true,
                ],
                [
                    'key' => 'workforce.timesheet_employee',
                    'label' => 'Timesheet (Employee)',
                    'hint' => 'View and submit own timesheets.',
                    'scope' => 'own', // ADR-803 : ses propres données
                    'permissions' => ['workforce.timesheet_view', 'workforce.timesheet_submit'],
                ],
                [
                    'key' => 'workforce.timesheet_management',
                    'label' => 'Timesheet Management',
                    'hint' => 'Approve and manage timesheets.',
                    'permissions' => ['workforce.timesheet_view', 'workforce.timesheet_submit', 'workforce.timesheet_approve', 'workforce.time_approve'],
                ],
                // B3-checkpoint (ADR-699) — profil « validateur d'équipe » : valide les feuilles de ses
                // RAPPORTS DIRECTS seulement (pas Company). Bundle DÉDIÉ (ne modifie aucun bundle existant).
                [
                    'key' => 'workforce.timesheet_team_approver',
                    'label' => 'Timesheet Team Approver',
                    'scope' => 'team', // ADR-803 : ses rapports directs seulement
                    'hint' => 'Approve/reject timesheets of direct reports only (team perimeter).',
                    'permissions' => ['workforce.timesheet_view', 'workforce.timesheet_approve_team'],
                ],
            ],
            scope: 'company',
            type: 'addon',
            // ADR-794 (L4) — contributions PROPOSÉES (jamais imposées) : définitions canoniques existantes.
            // ADR-803 — module pilote de la configuration guidée (Accès → Temps de travail → Confirmation).
            setup: [
                'steps' => ['access', 'working_time'],
                'readiness' => \App\Modules\Workforce\Settings\WorkforceSetupReadiness::class,
            ],
            contributions: [
                'version' => 1,
                'fields' => [['ref' => 'hire_date'], ['ref' => 'contract_type'], ['ref' => 'iban']],
                'documents' => [['ref' => 'id_card'], ['ref' => 'rib']],
                'requirements' => [
                    ['target' => 'field:hire_date', 'level' => 'default', 'stage' => 'hire',
                        'reason' => ['fr' => "Sert au suivi de l'ancienneté, des congés et des échéances de contrat.", 'en' => 'Used for seniority, leave and contract milestones.']],
                    ['target' => 'field:contract_type', 'level' => 'default', 'stage' => 'hire',
                        'reason' => ['fr' => 'Détermine les règles applicables au collaborateur (durée, période d’essai).', 'en' => 'Determines the rules that apply to the employee (term, probation).']],
                    ['target' => 'document:id_card', 'level' => 'configurable_required', 'stage' => 'hire',
                        'reason' => ['fr' => "Vérification de l'identité lors de l'embauche.", 'en' => 'Identity check at hiring.']],
                    ['target' => 'field:iban', 'level' => 'recommended', 'stage' => 'onboarding',
                        'reason' => ['fr' => 'Permet le remboursement des frais.', 'en' => 'Allows expense reimbursements.']],
                ],
            ],
        );
    }
}
