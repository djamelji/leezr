# Audit de consolidation fonctionnelle — Espace Company

> **Date** : 2026-11-10 · **Nature** : AUDIT LECTURE SEULE (aucun développement, aucune route/menu/RBAC modifié) · **Méthode** : inventaire exhaustif du code réel (routes, manifests, modèles, read-models, services) + vérification des **données réelles** en base (DEV).
> **Doctrine de référence** : ADR-561→567 (socle horizontal, `contacts` = référentiel universel des personnes), ADR-596 (groupes de nav = source unique `NavGroupRegistry`), ADR-639 (hub settings RH), ADR-640 (fiche employé unifiée), ADR-690 (nav par activité, un espace RH), ADR-751/751b (accueil employé).

---

## 0. Synthèse exécutive (recommandation finale)

**Constat central prouvé par la base** : une personne physique est éclatée sur **3 entités non synchronisées** — `users` (identité/login), `memberships` (accès + rôle RBAC), `workforce_employees` (RH, qui **re-stocke** nom/email/téléphone). **Member ≠ Employee** : sur **234 employés, 216 (92 %) n'ont AUCUN compte** (`user_id` NULL), et des Users existent sans fiche employé (admins/comptes techniques). Il n'existe **aucune FK** Employee↔Membership, seulement un pont optionnel `user_id`.

**Trois risques réels** (pas cosmétiques) :
1. **Identité dupliquée sans synchro** : le nom/email est éditable sur **3 surfaces** (`/profile`, `/company/members/{id}`, `/workforce/employees/{id}`) écrivant sur **2 tables différentes** → divergence garantie possible.
2. **Double surface d'assignation de rôle avec règles asymétriques** : `members` autorise un rôle **admin**, l'accès-employé l'**interdit** → chemin d'escalade de privilège (inviter « opérationnel » depuis la fiche, promouvoir « admin » depuis Members).
3. **Sémantiques de départ incohérentes** : « supprimer membre » (DELETE membership) ≠ « terminer employé » (`employee.status`) ≠ « révoquer accès » (`EmployeeAccessService`) — trois chemins sur la même personne.

**Recommandation architecturale** : créer **une fiche Collaborateur unifiée, portée par un domaine CORE/company** (pas par Workforce), car Members+Roles sont `core` et une Company sans Workforce doit gérer personnes et accès. La fiche agrège, **par onglets conditionnés aux modules + permissions**, les facettes Identité / Accès (core) + Emploi-Contrat / Planning / Temps / Congés / Documents RH (workforce). Le read-model `EmployeeFileReadModel` fait **déjà** cette apparition conditionnelle (`available_sections`) — la cible prolonge l'existant, elle ne crée pas de moteur.

**Fusion sûre côté RBAC** : chaque donnée sensible (rémunération, contrats, documents RH, IBAN/SSN, paie) est **gated serveur indépendamment** (masquage read-model). La fusion visuelle ne crée **aucune** permission globale implicite — risque purement UX (onglets morts), jamais de fuite.

---

## 1. Inventaire exhaustif des routes Company (livrable 14.1)

Routage **file-based** (`unplugin-vue-router`), noms kebab (`typed-router.d.ts` = autorité). Garde central `resources/js/plugins/1.router/guards.js` : `unauthenticatedOnly` → cross-guard workspace (`/dashboard`↔`/home`) → `surface:'structure'` exige rôle management sinon `company403` → `meta.module` actif (`ModuleStore.isActive`) sinon redirect → `meta.permission`/`anyPermission`.

| URL | Fichier Vue | Meta (module / perm / surface) | Domaine | Module | Menu ? | État |
|---|---|---|---|---|---|---|
| `/dashboard` | `dashboard.vue` | `core.dashboard` | Accueil gestion | core.dashboard | oui | utilisé |
| `/home` | `home.vue` | *(aucune)* | Accueil opérationnel employé | — | non (workspace) | utilisé |
| `/company/members` | `members/index.vue` | `core.members` · `members.view` · structure | Membres (accès) | **core.members** | oui | utilisé |
| `/company/members/:id` | `members/[id].vue` | idem | Fiche membre | core.members | non | utilisé |
| `/company/roles` | `roles.vue` | `core.roles` · `roles.view` · structure | Définitions RBAC | **core.roles** | oui | utilisé |
| `/company/modules` + `/:key` | `modules/*` | `core.modules` · `modules.manage` | Catalogue modules | core.modules | oui | utilisé |
| `/company/billing` + `/:tab` + invoices/pay/consolidated | `billing/*` | `core.billing` · `billing.manage` | Facturation | core.billing | oui (tab) | utilisé ; `consolidated-preview` = simulation non comptable (flag) |
| `/company/plan` | `plan.vue` | `core.billing` · `billing.manage` | Choix de plan | core.billing | non | utilisé |
| `/company/documents/:tab` | `documents/[tab].vue` | `core.documents` · `documents.view` | Coffre/compliance/requests/settings | core.documents | oui (tab) | utilisé |
| `/company/audit` | `audit/index.vue` | `core.audit` · `audit.view` | Journal d'audit | core.audit | oui | utilisé |
| `/company/workflows` + `/:id` | `workflows/*` | `core.workflows` · `automations.view` | Automatisations | core.workflows | oui | utilisé |
| `/company/support` + `/:id` | `support/*` | `core.support` · `support.view` | Support | core.support | **footer** | utilisé (no-menu) |
| `/company/notifications` | `notifications/index.vue` | `core.notifications` | Notifications | core.notifications | non (cloche) | utilisé |
| `/company/profile/:tab` | `profile/[tab].vue` | `core.settings` · `settings.view` | **Profil de la COMPANY** (entité légale) | core.settings | oui (tab) | utilisé |
| `/company/shipments` + create/:id | `shipments/*` | `logistics_shipments` · `shipments.*` · operations | Expéditions (toutes) | logistics_shipments | oui | utilisé |
| `/company/my-deliveries` + `/:id` | `my-deliveries/*` | `logistics_shipments` · `shipments.view_own` | Mes livraisons (chauffeur) | logistics_shipments | oui (`excludePermission:shipments.view`) | utilisé |
| `/company/workforce` | `workforce/index.vue` | `workforce` · `workforce.view` | — | workforce | non | **redirect → /dashboard** |
| `/company/workforce/me` | `workforce/me.vue` | `workforce` *(sans perm)* | Self-service employé | workforce | oui (groupe `account`) | utilisé |
| `/company/workforce/employees` + `/:id` | `workforce/employees/*` | `workforce` · `workforce.view` | Collaborateurs RH + fiche | workforce | oui | utilisé |
| `/company/workforce/time` + `/:id` | `workforce/time/*` | `workforce` · anyPerm[timesheet_view,planning_view] | Temps & pointage | workforce | oui | utilisé |
| `/company/workforce/planning` | `workforce/planning/index.vue` | `workforce_planning` · `planning_view` | Planning | workforce_planning | oui | utilisé |
| `/company/workforce/leave` | `workforce/leave/index.vue` | `workforce_leave` · `leave_request`/anyPerm | Congés | workforce_leave | oui | utilisé |
| `/company/workforce/payroll` + `/:id` | `workforce/payroll/*` | `workforce_payroll` · `payroll_prepare` | Paie | workforce_payroll | oui | utilisé |
| `/company/workforce/dsn` + `/:id` | `workforce/dsn/*` | `workforce_payroll` · `payroll_prepare` | DSN | workforce_payroll | non (onglet Paie) | utilisé (deep-link) |
| `/company/workforce/documents` | `workforce/documents/index.vue` | `workforce_documents` · `workforce_documents.view` | Documents RH générés | workforce_documents | oui | utilisé |
| `/company/workforce/organization/:tab` | `workforce/organization/[tab].vue` | `workforce` · `workforce.view` | Dépts/postes/sites | workforce | oui (tab) | utilisé |
| `/company/workforce/settings/:tab` | `workforce/settings/[tab].vue` | `workforce` · `workforce.view` · structure | Hub réglages RH | workforce | oui (tab) | utilisé |
| `/company/workforce/configuration` | `workforce/configuration.vue` | `workforce` | — | workforce | non | **redirect legacy (ADR-639)** |
| `/company/workforce/onboarding` | `workforce/onboarding.vue` | `workforce` · `workforce.manage` | Assistant onboarding | workforce | non | utilisé (no-menu) |
| `/company/workforce/signature` | `workforce/signature/index.vue` | `workforce_signature` | E-signature | workforce_signature | non (onglet Docs) | utilisé (deep-link) |
| `/company/workforce/contract/templates` | `workforce/contract/templates.vue` | `workforce_contract` | Gabarits contrats | workforce_contract | non (onglet Docs) | utilisé (deep-link) |
| `/company/403` | `company/403.vue` | — | Interdit | — | non | système |
| `/company/dev-lab` | `company/dev-lab.vue` | `workforce` · `workforce.view` | Bac à sable design (ADR-694) | — | non | **dev/legacy (hors nav)** |

**Pages intentionnellement menuless fondues en onglets** (ADR PRODUCT-UX-2) : signature, contract/templates → Documents ; dsn → Paie. **Redirects** : `workforce/index`→dashboard, `workforce/configuration`→settings. **Non tracé** : stores `logistics_shipments` (hors `modules/company/**`).

---

## 2. Inventaire des menus Company (livrable 14.2)

Sidebar **manifest-driven** : un seul moteur `NavBuilder.php` (pipeline 11 étapes identique admin/company), groupes = **source unique `NavGroupRegistry.php:35-50`** (ADR-596), i18n `nav.groups.*`. Un module assigne un item à un groupe via `group` explicite, sinon dérivé du `surface`. Front = re-check permission dernier rempart (`useCompanyNav.js:70-74`).

**Groupes company (ordre)** : `identity`(10) · `contact`(20) · `dispatch`(30) · `driving`(40) · **`workforce`(50)** · `hr_*`(51-57, legacy compat, élagués si vides) · `account`(60) · `operations`(70) · `company`(900 défaut). Libellés : workforce=« Ressources humaines », account=« Compte », operations=« Opérations », company=« Entreprise ».

**Items réels par module** : Dashboard, Members, Roles, Modules, Billing, Documents, Audit, Workflows, Profile → groupe **`company`** (défaut) ; workforce-me + account-settings → **`account`** ; workforce-employees/time/planning/leave/payroll/documents/organization/settings → **`workforce`** ; shipments/my-deliveries → **`operations`**. Support = footer. Notifications/jobdomain/theme/documentation = `navItems: []`.

**Module désactivé = aucune exposition (double-gate)** : `NavBuilder` filtre via `ModuleGate::isActiveForScope` (pas d'item) **et** le garde route bloque le deep-link (`guards.js:128-138`). `ModuleGate` (ADR-204) : manifest → `is_enabled_globally` → `type==='core'` toujours actif → `EntitlementResolver` (plan+addon) → `CompanyModuleActivationReason`. Tous les `core.*` toujours ON ; addons (`workforce*`, `logistics_*`) exigent entitlement + activation.

---

## 3. Cartographie des modules propriétaires (livrable 14.3)

| Module | Type / requires | Propriété fonctionnelle | Permissions clés |
|---|---|---|---|
| core.members | core | **Personnes + accès** (Membership) | members.view/invite/manage/credentials/sensitive_read |
| core.roles | core | **Définitions RBAC** (CompanyRole) | roles.view/manage |
| core.settings | core | Profil company + account-settings | settings.view/manage |
| core.documents | core | Pièces justificatives personne/company | documents.view/manage/configure |
| core.billing / modules / audit / workflows / support / notifications | core | Transverses | billing.manage / modules.manage / audit.view / automations.* / support.view |
| **workforce** | **addon** | **Référentiel RH Employee** + accès employé (délègue à core members.*) | workforce.view/manage/export/import/contracts/compensation_read/compensation_manage/sensitive_read |
| workforce_planning | addon → workforce | Planning/shifts | planning_view/manage/publish |
| workforce_leave | addon → workforce | Congés | leave_request/leave_view_all/leave_approve |
| workforce_documents | addon → workforce | Documents RH générés/signés | workforce_documents.view/generate/manage_templates/sign |
| workforce_payroll | addon → workforce,leave | Paie + DSN | payroll_prepare/validate/export |
| workforce_contract | addon → workforce,documents | Gabarits contrats | workforce_contract.view/manage_templates/generate |
| workforce_signature | addon → workforce,documents | E-signature | workforce_signature.view/sign |
| logistics_shipments (+fleet/tracking/analytics) | addon | Vertical logistique | shipments.view/view_own/create/assign/manage_status |

> **Note doctrine** : `Employee` vit dans `app/Core/Workforce/` — tension avec ADR-562 (« aucun `app/Core/{Vertical}/` »), même si Workforce est horizontal. Hors scope, relevé.

---

## 4. Audit Members / Employees (livrable 14.4)

### Modèle de données (schéma réel vérifié)
```
User (users)                    identité/login : first_name,last_name,email,password,avatar
  │  hasMany
Membership (memberships)        user_id, company_id, role(owner|user), company_role_id→CompanyRole,
  │                             suspended_at…   UNIQUE(user_id, company_id)
  └─ belongsTo CompanyRole (company_roles)   RBAC: key,name,is_administrative,archetype,field_config,doc_config

Employee (workforce_employees)  company_id, user_id(NULLABLE), employee_number,
                                first_name,last_name,email,phone (COPIE identité!), hire/termination_date,
                                status, department_id, job_role_id→JobRole, manager_id
  └─ hasMany EmploymentContract (workforce_employment_contracts)  type,dates,heures,is_current ; salaire via metadata

Employee ↔ Membership : AUCUNE FK — pont uniquement via user_id partagé.
CompanyRole (RBAC/accès) ≠ JobRole (poste RH, workforce_job_roles, default_hourly_rate_cents).
```

### Réponses explicites (prouvées)
- **Member == Employee ? NON** — pas de FK, `user_id` nullable. **216/234 employés sans compte** ; des Users sans fiche employé (admins/techniques).
- **Users sans contrat/employé** : OUI (membership `accountOnly`, badge `members/index.vue:558`).
- **Salariés sans accès applicatif** : OUI, **cas par défaut** (`EmployeeController::store` crée la fiche sans User).
- **Admins aussi salariés** : OUI (owner/admin + Employee lié). Mais `EmployeeAccessService::grant` **n'assigne jamais** de rôle admin (`:217-226`).
- **Prestataires externes** : pas de modèle dédié ; « Invité » (User `password_set_at=null`) est first-class.
- **Multi-company** : OUI (`User hasMany Membership`, unicité par `(user_id,company_id)` — 1 cas réel en base).

### Les deux pages
- **`/company/members`** (core.members) : liste des **memberships** (identité User, statut, CompanyRole, badge `is_employee` → deep-link fiche employé). Possède : invitation (User+Membership), **assignation de rôle** (`company_role_id`, admin autorisé), édition identité **sur User** + champs dynamiques, credentials, suppression.
- **`/company/workforce/employees`** (workforce) : fiche **déjà unifiée par onglets** (ADR-640) : overview/profile/contract/planning/time/leave/documents/history. Possède : identité **sur `workforce_employees`**, contrats, planning, temps, congés, documents, signatures, terminaison, **et gestion d'accès** (`_EmployeeAccessDialog` → grant/suspend/revoke avec rôle **non-admin**).

### Conséquences du regroupement visuel
Le regroupement est **souhaitable** (une seule personne, un seul parcours) **et sûr RBAC** (§9), mais doit traiter : (a) les **216 fiches RH sans compte** (la liste unifiée ne peut pas présupposer un User) ; (b) les **membres sans fiche employé** (admins) ; (c) la **dé-duplication identité** User↔Employee ; (d) la **réconciliation des règles de rôle** (admin autorisé côté Members, interdit côté accès-employé).

---

## 5. Audit Roles / Permissions (livrable 14.5)

- **`/company/roles`** (core.roles) gère les **DÉFINITIONS** : `name`, `is_administrative`, `permissions` (via `_PermissionMatrix`), `field_config`. Un `CompanyRole` est **partagé** (`hasMany memberships`) : éditer ses permissions **re-façonne l'accès de tous** ses porteurs. L'**assignation** se fait ailleurs (Members + accès-employé).
- **Réponse à la question métier** : la **définition** des rôles doit **rester une surface centrale distincte** (impact multi-utilisateurs, ne pas l'enterrer par personne). L'**assignation** appartient à la fiche personne (onglet Accès). Organisation cible :
  - **Liste Collaborateurs** : onglet *Personnes* + onglet *Rôles & autorisations* (= la gestion des définitions, aujourd'hui `roles.vue`, rapatriée comme onglet au même contexte) + éventuel onglet *Invitations & accès*.
  - **Fiche personne** : onglet *Accès* = rôle(s) attribué(s), **permissions effectives** (lecture), statut du compte, historique. La **modification de la définition** reste gated `roles.manage`, l'**assignation** gated `members.manage`.
- **Caveat sécurité** : aujourd'hui l'assignation admin est possible via Members mais pas via accès-employé → **asymétrie à corriger** (une seule règle d'assignation).

---

## 6. Cartographie des données d'une personne (livrable 14.6)

| Donnée | Propriétaire (table) | Éditée sur (surfaces) | Multi-surface ? | Risque | Surface primaire cible |
|---|---|---|---|---|---|
| Nom (first/last) | `users` **+ copie** `workforce_employees` | `/profile` · members/{id} (User) · employees/{id} (Employee) | **3** | **ÉLEVÉ** | User = source ; fiche employé lecture seule ou synchro |
| Email | `users` **+ copie** `workforce_employees` | `/profile` · members (RO) · employees (éditable) | **2 éditables** | **ÉLEVÉ** | Email = User (login) |
| Téléphone | `workforce_employees.phone` | fiche employé | 1 | Moyen | Fiche employé |
| Compte/mot de passe | `users` | self `/profile/password` · members credentials · EmployeeAccessService | **3 systèmes** | **ÉLEVÉ** | Un seul service de cycle de vie compte |
| Thème/préférences | cookie | account-settings | 1 | Faible | OK |
| Rôle company | `memberships.company_role_id` | updateMember · AddMemberDrawer · MemberProfileForm (+ accès-employé) | **≥3 entrées** | Moyen (règles divergentes) | Onglet Accès (1 règle) |
| Suspension/départ | `memberships` DELETE **+** `employee.status` **+** accès | members DELETE · terminate · suspend/revoke | **3 concepts** | **ÉLEVÉ** | 1 workflow « départ » orchestré |
| Infos pro (poste/dépt/manager) | `workforce_employees` | fiche employé ; dépts/postes CRUD = organization | 1 | Faible | Fiche employé |
| Contrat | `workforce_employment_contracts` | fiche employé ; gabarits = settings | conceptuel | Faible | Fiche employé ; gabarits = settings RH |
| Planning/shifts | `WorkforceShift` | planning (édit) ; me/fiche (lecture) | lecture multiple | Faible | workforce/planning |
| Pointage/temps | `TimeEntry` | clock self ; time cockpit ; fiche | self+admin | Moyen | workforce/time ; self=clock |
| Feuilles de temps | timesheets | time/[id] ; fiche | 1 | Faible | workforce/time |
| Congés+soldes | `LeaveRequest` | leave (admin) ; me (self) ; fiche | self+admin | Faible | workforce/leave |
| Documents | **2 systèmes** (core vs workforce) | 5+ pages | **OUI** | **ÉLEVÉ** | §7 B-4 |
| Formations | **inexistant** (type de congé) | — | — | — | N/A |
| Rôles/permissions (déf.) | company_roles + field_config | roles.vue | 1 | Faible | roles (central) |
| Notifications | prefs topic | company/notifications | 1 | Faible | OK |
| Historique/audit | `/company/audit` | audit ; timelines docs/signatures | lecture dispersée | Faible | audit (agréger) |

---

## 7. Doublons et pages obsolètes (livrable 14.7) + 8. Problèmes UX (livrable 14.8)

- **B-1 — Deux listes de personnes** : `members` (core, surface structure) **et** `workforce/employees` (addon, groupe RH) listent le **même ensemble**, deux chemins de création (invite User vs créer Employee). **Doublon primaire.**
- **B-2 — Deux entrées « Documents »** dans la nav (même libellé) : `core.documents` (surface structure) vs `workforce_documents` (surface hr). **Confusion UX.**
- **B-3 — Dashboards `/dashboard` vs `/home`** : séparation intentionnelle (ADR-751) mais recouvrement réel (les deux montent `DashboardGrid` + clock + priorités). À factoriser, pas fusionner.
- **B-4 — Documents gérés depuis 5+ endroits, 2 backends** : Core (`/company/document*`, pièces personne) vs Workforce (`/workforce/documents`, générés/paie), + member docs panel + self `/profile/documents` + `/workforce/me/documents` + payslips. **Config des types dupliquée** entre `documents/[tab]` settings et `settings.store` → **doublon réel de config**.
- **B-5 — Settings** : globalement centralisés (ADR-639 hub) ; `workforce/configuration` = route morte redirigée ; **point d'attention = config types de documents (B-4)**.
- **B-6 — Routes sans valeur métier** : `dev-lab` (bac à sable, hors nav → exclure prod), `billing/consolidated-preview` (simulation non comptable → flag), `workforce/configuration` (redirect legacy).
- **B-7 — Logistique** : `shipments` (all) vs `my-deliveries` (own) — séparation légitime mais **2 stores** → logique de liste dupliquée.
- **Pages à NE PAS fusionner** : définitions de rôles (impact multi-users, central), paie/DSN (sensible, séparé), planning (tableau opérationnel, pas par-personne), audit (transverse), billing, modules, coffre documentaire company, profil **company** (≠ profil personne).

---

## 9. Matrice RBAC par onglet/action (livrable 14.12) + sécurité (section 9)

**Verdict : la fusion ne crée aucune permission globale implicite.** Chaque donnée sensible est gated serveur **indépendamment** (masquage read-model, non sérialisé sans droit) :

| Onglet / action | Permission (serveur) | Preuve |
|---|---|---|
| Liste / fiche personne (accès) | module `core.members` | `company.php:217-219` |
| Champs sensibles membre (IBAN/SSN) | `members.sensitive_read` (masqué) | `MembershipController.php:74,230` |
| Inviter | `members.invite` | `company.php:221` |
| Éditer profil / assigner rôle / retirer | `members.manage` | `company.php:223-226` |
| Credentials (reset/set mdp) | `members.credentials` | `company.php:229-232` |
| Définitions de rôles | `roles.view` / `roles.manage` | `company.php:196-205` |
| Liste/fiche employé | `workforce.view` | `company.php:483,494` |
| Fiche composite `/file` | scope `canViewEmployeeData` = (view+manage) OU self OU N+1 | `EmployeeController.php:168-172` |
| **Rémunération** | `workforce.compensation_read` (clé absente du payload sinon) | `EmployeeFileReadModel.php:124-128` |
| Éditer/créer contrat | `workforce.contracts` | `company.php:507-509` |
| Générer contrat | `workforce_contract.generate` | `company.php:731` |
| Documents RH générés | `workforce_documents.view` | `company.php:707` |
| Documents personnels (pièces) | `documents.view` | `company.php:290` |
| Paie/bulletins | `workforce.payroll_prepare` | `company.php:668` |
| Audit | `audit.view` | `company.php:350` |
| Grant/suspend/revoke accès | `members.invite` / `members.manage` | `company.php:499-504` |

**À préserver impérativement en cas de fusion** : (1) le résumé **contrat non-salarial** (type/heures/convention) n'est **pas** derrière une permission dédiée (module + scope seulement) → « onglet contrat visible » ≠ « salaire visible » ; (2) un manager basique ne doit voir que le **chrome** des onglets, chaque appel restant gated. Règle d'or : **gater la PAGE au moins-disant (`members.view`), chaque onglet gardant son gate d'endpoint existant**.

---

## 10. Compatibilité sans Workforce (livrable 14.13)

**Prouvé** : `MembersModule`/`RolesModule` = `type:core`, **sans `requires`** → toujours actifs, routes gated `core.members`/`core.roles` (jamais `workforce`). L'Employee est un **add-on** sur une Membership core ; le cycle d'accès employé **réutilise** `members.invite/manage` (core). Le read-model gate déjà chaque section sur `ModuleGate::isActive(workforce_*)` et renvoie `null` si inactif.

**Conclusion** : la fiche Collaborateur unifiée doit être **portée par le Core/company** (shell = identité + membership + rôle + accès), les onglets Workforce (contrat/temps/congés/planning/documents/paie/rémunération) étant **conditionnels à l'activation du module**. Une Company « Logistique seule » ou « Documents seule » gère ses personnes et accès **sans** Workforce ; une Company RH **gagne** les onglets.

---

## 11. Impacts routes & deep-links (livrable 14.14)

Références entrantes **porteuses** à préserver (redirect/alias, ne jamais casser les fiches individuelles) :
- Nav : `MembersModule.php:21` (`company-members`), `WorkforceModule.php:28` (`company-workforce-employees`).
- Deep-link backend : `HomePrioritiesReadModel.php:106` → `company-workforce-employees`.
- Cross-link existant : `members/index.vue:553` → `/company/workforce/employees/{employee_id}`.
- Boutons/quick-actions : `dashboard.vue:262`, `_QuickActionsBar.vue:20`, `_OnboardingWidget.vue:27` → `/company/members` ; `workforce/documents/index.vue:587`, `_TimeCockpit.vue:393`, `payroll/[id].vue:204` → fiche employé.
- **Tests à préserver** : Feature (`CompanyRolesModuleTest`, `CompanyMemberProfileTest`, `CompanyMemberCredentialTest`, `InvitationTest`, `CompanyPermissionTest`, `PageModuleAlignmentTest`, `NavItemTest`, `WorkforceNavHrGroupTest`, `ManageStructureHardeningTest`…) ; E2E (`workforce-employee-file`, `workforce-authz`, `company-member-lifecycle`, `workforce-owner-identity-members`, `security-cross-tenant`…).
- **Routes à garder résolvables** : `company-members`, `company-members-id`, `company-workforce-employees`, `company-workforce-employees-id`.

---

## 12. Matrice de consolidation exhaustive (livrable 14.9)

| Page actuelle | Propriétaire métier | Redondance | Page cible | Action | RBAC | Risque |
|---|---|---|---|---|---|---|
| `/company/members` (liste) | Core (personnes+accès) | avec employees | **Collaborateurs › Personnes** | **FUSIONNER** (shell core) | members.* | Élevé (dédup identité) |
| `/company/members/:id` | Core | avec fiche employé | **Fiche personne › onglets** | **FUSIONNER** (+alias) | members.view | Élevé |
| `/company/workforce/employees` (liste) | Workforce (RH) | avec members | **Collaborateurs › Personnes** (colonnes RH si module) | **FUSIONNER** | workforce.view | Élevé (216 sans compte) |
| `/company/workforce/employees/:id` | Workforce | — (déjà tabbé) | **Socle de la fiche unifiée** | **CONSERVER comme base** | scope+module | Moyen |
| `/company/roles` | Core RBAC | non (définitions) | **Collaborateurs › Rôles & autorisations** (onglet) | **DÉPLACER EN ONGLET** (définition reste distincte) | roles.manage | Moyen |
| `/company/workforce/me` | Workforce self | avec /home | **Conserver** (self détaillé) ; /home = résumé | CONSERVER | module only | Faible |
| `/company/workforce/time` · `/planning` · `/leave` · `/payroll` · `/dsn` | Workforce | non | **Conserver** (surfaces opérationnelles/sensibles) | perms dédiées | Faible |
| `/company/documents/:tab` (settings) | Core docs | config types dupliquée (settings.store) | **Une seule config types** | **DÉDOUBLONNER** | documents.configure | Moyen |
| Nav « Documents » ×2 | Core vs Workforce | libellé identique | **Renommer/distinguer** (Pièces vs Documents RH) | — | Faible |
| `/company/workforce/configuration` | Workforce | redirect legacy | — | **DÉPRÉCIER** (garder redirect) | — | Faible |
| `/company/dev-lab` | — | bac à sable | — | **SORTIR DE PROD** (hors nav déjà) | — | Faible |
| `/company/billing/consolidated-preview` | Billing | simulation | — | **CONSERVER derrière flag** | billing.manage | Faible |
| `shipments` vs `my-deliveries` | Logistics | 2 stores | **Conserver** (mutualiser logique liste) | shipments.* | Faible |
| `/dashboard` vs `/home` | Core vs self | widgets communs | **Conserver** (factoriser widgets) | — | Faible |
| `/company/profile/:tab` | Core settings | — (company ≠ personne) | **Conserver** (ne PAS fusionner) | settings.view | — |

---

## 13. Architecture cible (livrable 14.10/14.11/13-A..E)

### A. Arborescence Company cible (groupes config-driven, permission-aware)
```
● Accueil                      /dashboard (gestion) · /home (opérationnel employé)
● Collaborateurs  [CORE]       /company/people
    › Personnes                liste unifiée (membership ∪ employee), colonnes conditionnées modules
    › Rôles & autorisations    définitions RBAC (ex-/company/roles) — gate roles.*
    › Invitations & accès      (optionnel) comptes invités/suspendus
    → Fiche personne /company/people/:id  (onglets, cf. B)
● Ressources humaines [addon workforce]  (apparaît si module actif)
    Temps · Planning · Congés · Paie & DSN · Documents RH · Organisation · Réglages RH
● Opérations [addon logistics]  Expéditions · Mes livraisons
● Documents  [CORE]            Pièces & conformité  (distinct de « Documents RH »)
● Facturation · Modules · Automatisations · Audit  [CORE]
● Entreprise                   Profil company (entité légale) · Paramètres
● Compte                       Mon espace (self) · Paramètres du compte
```
Critère de regroupement : **par contexte métier** (une personne = un lieu), groupes issus de `NavGroupRegistry` (pas de liste codée en dur), items gated par module+permission. On évite 20 onglets : les surfaces **opérationnelles/sensibles de masse** (planning, temps, paie) restent des pages dédiées, pas des onglets par-personne.

### B. Fiche Collaborateur unifiée (onglets conditionnés module + permission)
| Onglet | Source/propriété | Condition d'affichage |
|---|---|---|
| Vue d'ensemble | agrégat read-model | toujours |
| Informations personnelles | **User** (identité) + champs dynamiques | `members.view` |
| Accès & autorisations | Membership + CompanyRole + **permissions effectives** | `members.view` (modif `members.manage`) |
| Emploi & contrat | Employee + EmploymentContract (**salaire** si `compensation_read`) | module `workforce` |
| Planning | Shifts | module `workforce_planning` + `planning_view` |
| Temps & pointage | TimeEntry/timesheets | module `workforce` + `timesheet_view` |
| Congés & absences | LeaveRequest + soldes | module `workforce_leave` |
| Documents | pièces (core) + RH générés (workforce_documents) | `documents.view` / `workforce_documents.view` |
| Historique & audit | audit scoping personne | `audit.view` *(aujourd'hui placeholder vide)* |

La fiche **existe déjà** (`employees/[id].vue`, `available_sections`) → la cible = la **généraliser au Core** (clé = personne, pas Employee) pour couvrir les membres sans fiche RH et les fiches RH sans compte.

### C. Schéma de données (relations — NON modifié)
Voir §4. Point de décision migration : **une seule source d'identité** (User) ; `workforce_employees.first_name/last_name/email` deviennent dérivés/synchronisés (ou lecture seule). **Aucune fusion de table** : la fusion est **visuelle**, pas en base (la fiche lit User ∪ Membership ∪ Employee).

### D. Matrice des permissions — voir §9 (par page/onglet/action, serveur-enforced).

### E. Plan de migration par étapes (livrable 14.15)
- **Phase 0 — Socle sans risque** : (a) **réconcilier la règle d'assignation de rôle** (une seule règle admin/non-admin, corrige l'escalade) ; (b) **synchroniser/verrouiller l'identité** User↔Employee (source unique) ; (c) **workflow départ unifié** (orchestration membership+status+accès). *Tests : `company-member-lifecycle`, `security-suspend-recette`, `workforce-owner-identity-members`.*
- **Phase 1 — Fiche personne CORE** : généraliser `EmployeeFileReadModel` → `PersonFileReadModel` (clé personne = user_id ou employee_id), route `/company/people/:id` avec **alias/redirect** depuis `members/:id` et `workforce/employees/:id`. Onglets conditionnels (déjà `available_sections`). RBAC inchangé (gates par endpoint).
- **Phase 2 — Liste unifiée** : `/company/people` = union memberships ∪ employees, colonnes workforce conditionnelles. Nav : remplacer les 2 entrées (Members, Employees) par **Collaborateurs** ; conserver routes historiques en redirect.
- **Phase 3 — Rôles en onglet** : rapatrier `roles.vue` comme onglet « Rôles & autorisations » (définitions), assignation sur l'onglet Accès. Conserver `/company/roles` en redirect.
- **Phase 4 — Nettoyage** : distinguer les 2 nav « Documents », dédoublonner config types, sortir `dev-lab` de prod, flag `consolidated-preview`, factoriser widgets dashboard/home et stores shipment/delivery.
- **Rétro-compatibilité** : garder `company-members`, `company-members-id`, `company-workforce-employees`, `company-workforce-employees-id` résolvables (alias) ; préserver params/ids (deep-links fiches). **Condition de suppression** d'une ancienne page = 0 lien entrant restant + redirect en place ≥ 1 cycle + tests verts.

---

## 16. Risques techniques & métier (livrable 14.16)
- **Dé-duplication identité** (User vs workforce_employees) : risque de perte si synchro mal ordonnée → Phase 0 isolée, tests identité.
- **Escalade de privilège** (double règle rôle) : à corriger **avant** la fusion visuelle.
- **Régression RBAC** : chaque onglet garde son gate ; tester le « manager basique » (chrome sans données).
- **216 fiches sans compte** : la liste unifiée ne doit pas présupposer un User (colonnes accès = « aucun compte »).
- **Multi-company** : la fiche doit rester scoping-company.
- **Deep-links/tests** : 14 Feature + 9 E2E à maintenir via alias.
- **Doctrine** : `Employee` sous `app/Core/Workforce/` (tension ADR-562) — à arbitrer hors ce lot.

## 17. Recommandation architecturale finale (livrable 14.17)
**Construire une fiche et une liste « Collaborateurs » portées par un domaine CORE/company, agrégeant visuellement (sans fusion de tables) les facettes Identité (User), Accès+Rôle (Membership/CompanyRole) et RH (Employee) via des onglets conditionnés aux modules et permissions — en généralisant le read-model `available_sections` existant.** Traiter d'abord les **3 risques de divergence** (identité, règle de rôle, départ) en Phase 0, puis consolider liste/fiche/rôles en Phases 1-3 avec alias de compatibilité, et finir par le nettoyage des doublons documentaires et des routes sans valeur (Phase 4). Les surfaces opérationnelles et sensibles de masse (planning, temps, paie, définitions de rôles) restent des pages dédiées : une personne = un parcours, sans transformer l'app en page géante.

**Fin de l'audit — aucune modification de code, route, menu, RBAC ou donnée effectuée.**
