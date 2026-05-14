# AUDIT ARCHITECTURE — MODULE WORKFORCE (HR / Payroll)

> Auditeur : Claude Opus 4.6 — Senior SaaS B2B Multi-Tenant Architect
> Date : 2026-04-30
> Méthode : BMAD (Business → Domain → Architecture → Decisions)
> Codebase : Leezr (Laravel 12 / Vue 3 / Vuetify 3 / Vuexy)

---

## 1. VERDICT GLOBAL

**OK — avec 4 évolutions structurelles de l'existant**

L'architecture Leezr est prête à accueillir Workforce. Les fondations (multi-tenant, modules, RBAC, Fields, Documents, Audit, Markets, Jobdomains) sont matures — 210 migrations, 34 domaines Core, 44 modules, 73 permissions, patterns UseCase/ReadModel éprouvés.

**Mais** Workforce ne peut pas se poser "au-dessus" de l'existant tel quel. 4 briques doivent évoluer pour devenir des infrastructures transverses solides :

1. **Fields** — ajouter `target_entity` configurable + FieldSchemaSnapshot
2. **Documents** — ajouter génération (templates + variables) + workflow states + e-signature
3. **Markets** — ajouter `MarketRuleSet` versionné pour règles légales
4. **Company Settings** — créer `CompanyPolicyStore` pour politiques métier (distinct des Fields)

Sans ces évolutions, Workforce créerait une architecture parallèle — exactement ce que l'audit interdit.

---

## 2. SYNTHÈSE EXÉCUTIVE — 10 DÉCISIONS PRINCIPALES

| # | Décision | Justification |
|---|----------|---------------|
| D1 | **Employee est une entité Core Workforce fondamentale** (`app/Core/Workforce/Employee.php`), distincte de User et Membership — pas dans un Module | Entité fondamentale comme Company ou User — d'autres modules en dépendront |
| D2 | **Workforce est une famille de 6 modules**, pas un module unique | 38 sous-domaines ne tiennent pas dans 1 manifest — activabilité granulaire requise |
| D3 | **Leezr évolue vers un moteur de paie complet, par phases** | MVP = préparation + export. Cible = calcul cotisations, bulletins, DSN. 5 blocs séparés. |
| D4 | **Les champs structurels restent structurels**, les dynamic Fields enrichissent | Salaire, contrat, solde CP = colonnes structurées ; taille de vêtement = Field |
| D5 | **Le ledger congés est append-only** avec balance calculée | Pattern comptable — jamais de UPDATE/DELETE sur le ledger |
| D6 | **Les règles légales sont versionnées par Market** via MarketRuleSet | Pas dans Company, pas dans Module — dans Core/Markets |
| D7 | **JobDomain propose, Company configure, Contract override** | JobDomain n'est JAMAIS source de vérité — il propose des presets |
| D8 | **Les documents générés réutilisent le système Documents existant** étendu | Pas de sous-système documentaire parallèle |
| D9 | **MVP = France uniquement** pour les règles légales | Multi-pays phase 2+ |
| D10 | **15 permissions max en MVP**, regroupées en 4 bundles | Pas 40 permissions ingérables — bundles haut-niveau |

---

## 3. CE QUI EXISTE DÉJÀ À RÉUTILISER

| Brique existante | Fichier clé | Usage Workforce | Risque si mal utilisé |
|------------------|-------------|-----------------|----------------------|
| **BelongsToCompany** | `app/Core/Traits/BelongsToCompany.php` | Toutes les tables Workforce scopées company | Aucun — trait mature |
| **FieldDefinitionCatalog** | `app/Core/Fields/FieldDefinitionCatalog.php` | Champs HR existants (hire_date, contract_type, employee_status, SSN, IBAN) | Duplication si on recrée des champs structurés |
| **FieldResolverService** | `app/Core/Fields/FieldResolverService.php` | Résolution dynamique champs Employee | N+1 si mal scopé |
| **FieldValue (EAV)** | `app/Core/Fields/FieldValue.php` | Polymorphe — peut cibler Employee, Contract | model_type inflation si trop d'entités |
| **MandatoryContext** | `app/Core/Fields/MandatoryContext.php` | Résolution mandatory champs Workforce (modules, jobdomain, tags) | Aucun — pattern extensible |
| **DocumentTypeCatalog** | `app/Core/Documents/DocumentTypeCatalog.php` | Types documents RH (contrat, avenant, certificat, attestation) | Duplication si on crée un sous-système |
| **DocumentResolverService** | `app/Core/Documents/DocumentResolverService.php` | Résolution documents par scope + market + role | Scope `company_user` insuffisant — besoin `employee` |
| **DocumentLifecycleService** | `app/Core/Documents/DocumentLifecycleService.php` | Lifecycle status (missing, valid, expiring, expired) | Insuffisant — pas de workflow states |
| **ModuleManifest** | `app/Core/Modules/ModuleManifest.php` | Déclaration modules Workforce activables | Pas de concept "famille" — requires suffit |
| **EntitlementResolver** | `app/Core/Modules/EntitlementResolver.php` | Gating Workforce par plan/jobdomain | Aucun — gates extensibles |
| **CompanyRole + Permissions** | `app/Company/RBAC/CompanyRole.php` | Rôles Workforce (hr_manager, payroll, etc.) | 73 permissions existantes + 15 Workforce = lisibilité |
| **Permission Bundles** | via ModuleManifest.bundles | Bundles Workforce (gestion_rh, pointage, paie) | Aucun — pattern éprouvé |
| **field_config / doc_config** | sur CompanyRole (JSON) | Visibilité champs/docs par rôle Workforce | Aucun — pattern identique |
| **Market** | `app/Core/Markets/Market.php` | Devise, TVA, timezone, locale pour paie | Pas de règles légales versionnées |
| **LegalStatus** | `app/Core/Markets/LegalStatus.php` | Formes juridiques par marché | Aucun — read-only |
| **AuditLogger** | `app/Core/Audit/AuditLogger.php` | Traçabilité contrats, compensation, paie | Pas de masquage auto des données sensibles |
| **CompanyAuditLog** | `app/Core/Audit/CompanyAuditLog.php` | Audit trail company Workforce | Pas de catégorisation structurée |
| **JobdomainRegistry** | `app/Core/Jobdomains/JobdomainRegistry.php` | Presets Workforce par métier | Pas de presets Workforce structurés |
| **JobdomainPresetResolver** | `app/Core/Jobdomains/JobdomainPresetResolver.php` | Market overlay pour presets Workforce | Aucun — extensible |
| **JobdomainGate** | `app/Core/Jobdomains/JobdomainGate.php` | Assignment atomique lors de l'inscription | Doit déclencher aussi activation Workforce |
| **TagDictionary** | `app/Core/Fields/TagDictionary.php` | Tags Workforce (PAYROLL, ONBOARDING, etc.) | Extension simple |
| **UseCase pattern** | `*Data` → `execute()` → `*Result` | Toutes les opérations Workforce | Aucun — 39 UseCases existants comme modèle |
| **ReadModel pattern** | Static methods, arrays | Lectures métier Workforce | Aucun — 25 ReadModels existants |
| **PlatformSetting** | `app/Platform/Models/PlatformSetting.php` | Section `workforce` dans settings platform | Singleton JSON extensible |
| **Subscription state machine** | `app/Core/Billing/Subscription.php` | Pattern boot guards pour TimeEntry, Contract, LeaveRequest | Aucun — pattern éprouvé |
| **useCan() composable** | `resources/js/composables/useCan.js` | Vérification permissions Workforce en frontend | Aucun — can/canAny/canAll |

---

## 4. CE QUI DOIT ÊTRE CRÉÉ

| Brique | Criticité | Phase | Commentaire |
|--------|-----------|-------|-------------|
| **Employee model** (Core Workforce — `app/Core/Workforce/Employee.php`) | HAUTE | MVP | Entité fondamentale, distincte de User et Membership, FK nullable vers User |
| **EmploymentContract model** | HAUTE | MVP | Agrégat racine, state machine |
| **CompensationPlan model** | HAUTE | MVP | Versionné (effective_from/to) |
| **TimeEntry + TimeEntryBreak** | HAUTE | MVP | State machine pointage |
| **WorkforceModule manifest** | HAUTE | MVP | Module racine de la famille |
| **WorkforcePlanningModule manifest** | MOYENNE | Phase 2 | Shifts, templates |
| **WorkforceLeaveModule manifest** | MOYENNE | Phase 2 | Congés, ledger, balances |
| **WorkforcePayrollModule manifest** | MOYENNE | Phase 2+ | Paie complète 5 blocs (préparation → calcul → bulletins → déclarations → export) |
| **MarketRuleSet model** (Core) | HAUTE | MVP | Règles légales versionnées par Market |
| **CompanyPolicyStore (domain='workforce') model** | HAUTE | MVP | Politiques company (enforcement mode) |
| **LeaveType model** | MOYENNE | Phase 2 | Types de congés |
| **LeaveLedger model** | MOYENNE | Phase 2 | Append-only, ULID |
| **LeaveRequest model** | MOYENNE | Phase 2 | Workflow demande/validation |
| **Shift model** | MOYENNE | Phase 2 | Instances planning |
| **WorkScheduleTemplate model** | MOYENNE | Phase 2 | Patrons récurrents |
| **PayrollPeriod model** | MOYENNE | Phase 3 | Période de paie |
| **PayrollLine model** | MOYENNE | Phase 3 | Lignes paie par employé |
| **DocumentTemplate model** (Core) | HAUTE | Phase 2 | Templates avec variables — TRANSVERSE |
| **SignatureRequest model** (Core) | BASSE | Phase 3 | E-signature — TRANSVERSE |
| **EmployeeBenefit model** | BASSE | Phase 3 | Avantages/primes |

---

## 5. UNIFORMISATION RECOMMANDÉE

### 5.1 Fields

**Stratégie complète** :

#### Champs STRUCTURELS (colonnes DB sur Employee/Contract/Compensation)

| Champ | Entité | Type DB | Raison |
|-------|--------|---------|--------|
| employee_number | Employee | string | Identifiant unique, recherche |
| first_name, last_name | Employee | string | Identité fondamentale |
| email, phone | Employee | string | Contact |
| hire_date | Employee | date | Calculs ancienneté, congés |
| termination_date | Employee | date nullable | Lifecycle |
| status | Employee | string | State machine (active/inactive/on_leave/suspended/terminated) |
| contract_type | Contract | string | CDI/CDD/stage/alternance/freelance |
| work_model_key | Contract | string | horaire/forfait_jours/forfait_heures |
| weekly_hours | Contract | decimal | Durée contractuelle |
| start_date, end_date | Contract | date | Bornes contrat |
| probation_end_date | Contract | date nullable | Fin période d'essai |
| base_salary_cents | Compensation | integer | Salaire brut (en centimes) |
| currency | Compensation | char(3) | EUR, USD |
| pay_frequency | Compensation | string | monthly/biweekly/weekly |
| overtime_rate_bps | Compensation | integer | Taux heure sup (basis points) |

**Raison** : Ces champs participent à des CALCULS (paie, conformité, congés). Ils ne peuvent pas être des Fields dynamiques car leur type, validation et usage sont fixés.

#### Champs DYNAMIC FIELDS (via FieldDefinitionCatalog)

| Champ | Scope | Source | Category |
|-------|-------|--------|----------|
| social_security_number | SCOPE_COMPANY_USER | system (EXISTE) | hr |
| iban | SCOPE_COMPANY_USER | system (EXISTE) | hr |
| hire_date | SCOPE_COMPANY_USER | system (EXISTE) | hr |
| contract_type | SCOPE_COMPANY_USER | system (EXISTE) | hr |
| employee_status | SCOPE_COMPANY_USER | system (EXISTE) | hr |
| emergency_contact_name | SCOPE_COMPANY_USER | system (EXISTE) | hr |
| emergency_contact_phone | SCOPE_COMPANY_USER | system (EXISTE) | hr |
| birth_date | SCOPE_COMPANY_USER | system (EXISTE) | base |
| nationality | SCOPE_COMPANY_USER | system (EXISTE) | base |
| badge_number | SCOPE_COMPANY_USER | module (workforce) | hr |
| shoe_size | SCOPE_COMPANY_USER | company custom | hr |
| vehicle_preference | SCOPE_COMPANY_USER | company custom | domain |

**Migration existant→structurel — PAS DE DOUBLE SOURCE DE VÉRITÉ** :

Les champs `hire_date`, `contract_type`, `employee_status` existent dans FieldDefinitionCatalog (category `hr`).

**Sans module Workforce** : Ils restent des Fields dynamiques sur User (scope `company_user`). Aucun changement.

**À l'activation du module Workforce** :
1. `MigrateFieldsToEmployeeUseCase` copie les valeurs existantes vers Employee/Contract (colonnes structurées)
2. Les FieldValues sources sont marquées `migrated_to: 'workforce_employees'` dans leur metadata
3. Les FieldActivations HR sont désactivées (`enabled = false`, `migration_status = 'migrated'`)
4. Les FieldDefinitions HR restent dans le catalogue (pour les companies sans Workforce)
5. **Pas de miroir synchronisé** — Employee/Contract est la source unique

**Période transitoire** : 0 — migration atomique dans une transaction. Pas de période de double écriture.

**Rollback** : `RollbackFieldsMigrationUseCase` restaure les FieldValues depuis Employee/Contract, réactive les FieldActivations, et supprime le flag `migrated_to`. Utilisable si la company désactive Workforce.

**Audit de migration** : Chaque migration est loggée dans AuditLogger avec `category: 'workforce.field_migration'`, `diff_before` (FieldValues) et `diff_after` (Employee columns).

#### Résolution des sources de Fields

```
1. Core system fields (FieldDefinitionCatalog.all())
   ↓ merge
2. Module required fields (validation_rules.required_by_modules)
   ↓ merge
3. JobDomain preset fields (default_fields dans jobdomain)
   ↓ merge
4. Market overlay (JobdomainMarketOverlay.override_fields)
   ↓ filter
5. Company activation (FieldActivation.enabled)
   ↓ overlay
6. Role visibility (CompanyRole.field_config)
   ↓ resolve
7. Final field set pour l'entité
```

**Conflits** : Dernier override gagne SAUF mandatory — un field mandatory par module/jobdomain ne peut PAS être désactivé par company/role.

#### Champs sensibles

| Champ | Niveau | Permission requise |
|-------|--------|-------------------|
| social_security_number | TRÈS SENSIBLE | `workforce.sensitive_read` (is_admin) |
| iban | TRÈS SENSIBLE | `workforce.sensitive_read` (is_admin) |
| base_salary_cents | SENSIBLE | `workforce.compensation_read` (is_admin) |
| medical_notes | TRÈS SENSIBLE | `workforce.medical_read` (is_admin) — phase 2 |

**Masquage** : Le `FieldResolverService` existant masque déjà les SENSITIVE_CODES (`****1234`). Étendre `SENSITIVE_CODES` pour les nouveaux champs Workforce.

#### Extension nécessaire : `target_entity`

Actuellement le scope Fields est : `platform_user`, `company`, `company_user`.

**Évolution** : Ajouter la possibilité de cibler `employee` et `contract` comme `model_type` dans FieldValue sans changer les scopes. Le polymorphisme EAV le supporte déjà — `FieldValue.model_type` peut être `App\Core\Workforce\Employee` ou `App\Core\Workforce\EmploymentContract`.

**Impact** : Aucune migration sur `field_definitions` ou `field_values`. Seul `FieldResolverService::resolve()` doit accepter un Employee ou Contract comme `$model`.

#### FieldSchemaSnapshot

**Pas de snapshot actuellement.** Nécessaire pour :
- Figer les field definitions utilisées dans un Timesheet validé
- Figer les field definitions utilisées dans un PayrollPeriod exporté
- Audit trail des fields au moment d'un événement

**Implémentation** : JSON snapshot dans `metadata` du Timesheet/PayrollPeriod, pas un model séparé. Pattern léger.

### 5.2 Documents

**Stratégie complète** :

#### Types de documents à ajouter (via DocumentTypeCatalog)

| Code | Scope | Requires Expiration | Category |
|------|-------|--------------------|---------|
| employment_contract | company_user | false | workforce |
| contract_amendment | company_user | false | workforce |
| absence_justification | company_user | true | workforce |
| medical_certificate | company_user | true | workforce |
| employer_attestation | company_user | false | workforce |
| payslip_imported | company_user | false | workforce |
| signature_proof | company_user | false | workforce |

**Réutilisation existante** : Ces types utilisent exactement le même pattern que les types existants (id_card, driving_license). Activation per company via `DocumentTypeActivation`. Mandatory via `required_by_modules: ['workforce']`.

#### Génération de documents — Extension TRANSVERSE

**Aujourd'hui** : Documents = upload-only. Pas de génération PDF.

**Évolution nécessaire** (bénéficie à TOUS les modules, pas seulement Workforce) :

```
DocumentTemplate (NOUVEAU — Core)
├── code (string, unique par company_id)
├── company_id (nullable — null = platform template)
├── document_type_code (FK vers document_types.code)
├── name (string)
├── content_html (text — template Blade/Mustache)
├── variables_schema (JSON — déclaration des variables requises)
├── version (integer, auto-increment)
├── is_active (boolean)
├── timestamps
```

**Variable injection** :
```php
DocumentVariableResolver::resolve(DocumentTemplate $template, array $context): array
// context = ['employee' => Employee, 'contract' => Contract, 'company' => Company, ...]
// Résout : {{employee.first_name}}, {{contract.start_date}}, {{company.name}},
//          {{field.siret}}, {{field.iban}}, etc.
// Sources : modèles structurels + FieldValues dynamiques
```

**Workflow** :
1. Platform crée un template par défaut
2. Company peut override (copie avec `company_id` set)
3. Utilisateur déclenche génération → PDF stocké comme `MemberDocument`
4. Document généré = immutable (snapshot du contexte à la génération)

#### E-signature — Extension TRANSVERSE (Phase 3)

```
SignatureRequest (NOUVEAU — Core)
├── id
├── company_id
├── document_id (FK → member_documents.id)
├── provider (string — 'yousign', 'docusign', etc.)
├── provider_request_id (string nullable)
├── status (enum: pending, sent, viewed, signed, refused, expired, error)
├── signers (JSON — [{name, email, signed_at?, refused_at?}])
├── expires_at (datetime)
├── signed_at (datetime nullable)
├── signature_proof_url (string nullable)
├── metadata (JSON)
├── timestamps
```

**Séparation** : ContractGeneration (UseCase) → crée le PDF → SignatureRequest (UseCase) → envoie au provider → webhook callback → archive document signé.

#### Versioning documents

**Évolution** : Ajouter `parent_document_id` (nullable FK self-reference) sur `member_documents`. Un avenant pointe vers le contrat original.

### 5.3 JobDomain

**Stratégie complète** :

#### Ce que JobDomain PEUT proposer pour Workforce

```json
// Nouveau bloc dans jobdomain definition/DB
"workforce_presets": {
  "contract_types": ["cdi", "cdd", "interim"],
  "work_models": ["hourly", "shift"],
  "planning_mode": "shift_based",
  "time_tracking_mode": "clock_in_out",
  "break_policy": { "auto_deduct_lunch": true, "lunch_duration_minutes": 60 },
  "leave_types": ["cp", "rtt", "maladie", "sans_solde"],
  "recommended_benefits": ["ticket_restaurant", "transport"],
  "compliance_mode": "warn"
}
```

#### Résolution JobDomain → Company → Contract

```
1. Platform capabilities (quels modules Workforce sont disponibles)
   ↓
2. Market legal rules (durée légale, congés légaux, SMIC)
   ↓
3. JobDomain presets (contract_types fréquents, planning mode, etc.)
   ↓
4. Module required fields/settings (quels champs le module Workforce impose)
   ↓
5. Company workforce policy (enforcement mode, custom rules)
   ↓
6. Company custom fields/policies (champs custom, avantages custom)
   ↓
7. Contract overrides (durée hebdo, modèle temps, avantages spécifiques)
   ↓
8. Employee active contract (source de vérité pour cet employé)
   ↓
9. Timesheet validated snapshot (fige les données pour la période)
   ↓
10. Payroll preparation (consomme le snapshot)
```

**Principe** : JobDomain PROPOSE. Company ACCEPTE/MODIFIE/REFUSE. Contract OVERRIDE. Payroll FIGE.

#### Ce que JobDomain ne doit PAS faire

- Ne pas stocker de règles légales (c'est Market)
- Ne pas imposer des rules non overridables (c'est Company qui décide)
- Ne pas devenir source de vérité pour des données d'exécution
- Ne pas calculer quoi que ce soit (c'est déclaratif)

#### Évolution nécessaire

Le `JobdomainRegistry` actuel stocke `default_modules`, `default_fields`, `default_documents`, `default_roles`. Il faut ajouter `workforce_presets` comme nouveau bloc — PAS dans le code, dans la DB (table `jobdomains` colonne JSON `workforce_presets`).

**Migration** : `ALTER TABLE jobdomains ADD COLUMN workforce_presets JSON NULLABLE AFTER default_roles`

**Platform admin** peut modifier ces presets sans redéploiement.

### 5.4 Modules

**Stratégie** : Workforce est une FAMILLE de modules avec un module racine + modules optionnels.

| Module | Key | Type | Dépendances | Activable séparément | Phase |
|--------|-----|------|-------------|---------------------|-------|
| **Workforce Core** | `workforce` | core | — | NON (auto si jobdomain le propose) | MVP |
| **Workforce Planning** | `workforce_planning` | addon | `workforce` | OUI | Phase 2 |
| **Workforce Leave** | `workforce_leave` | addon | `workforce` | OUI | Phase 2 |
| **Workforce Payroll** | `workforce_payroll` | addon | `workforce`, `workforce_leave` | OUI | Phase 2 (préparation MVP), Phase 3+ (calcul, bulletins, DSN) |
| **Workforce Documents** | `workforce_documents` | addon | `workforce` | OUI | Phase 2 |
| **Workforce E-Signature** | `workforce_esign` | addon | `workforce_documents` | OUI | Phase 3 |

**Module racine `workforce`** = Employee + Contract + Compensation + TimeTracking basique.
**Modules additionnels** = features activables par plan/company.

**Entitlement** :
- `workforce` = type `core` si jobdomain le propose, sinon addon avec `minPlan: 'pro'`
- `workforce_planning` = addon, `minPlan: 'pro'`
- `workforce_leave` = addon, `minPlan: 'pro'`
- `workforce_payroll` = addon, `minPlan: 'business'` (5 blocs activables progressivement)
- `workforce_documents` = addon, `minPlan: 'pro'`
- `workforce_esign` = addon, `minPlan: 'business'`

### 5.5 RBAC

#### Permissions MVP (15 permissions, 4 bundles)

**Permissions** :

| Key | Label | is_admin | Module |
|-----|-------|----------|--------|
| `workforce.view` | Voir les employés | false | workforce |
| `workforce.manage` | Gérer les employés | false | workforce |
| `workforce.contracts` | Gérer les contrats | true | workforce |
| `workforce.compensation_read` | Voir les rémunérations | true | workforce |
| `workforce.compensation_manage` | Gérer les rémunérations | true | workforce |
| `workforce.sensitive_read` | Voir SSN/IBAN | true | workforce |
| `workforce.time_manage` | Corriger le pointage | false | workforce |
| `workforce.time_approve` | Valider les timesheets | true | workforce |
| `workforce.leave_request` | Demander un congé | false | workforce_leave |
| `workforce.leave_approve` | Valider les congés | true | workforce_leave |
| `workforce.planning_manage` | Gérer le planning | false | workforce_planning |
| `workforce.payroll_prepare` | Préparer la paie | true | workforce_payroll |
| `workforce.payroll_validate` | Valider la paie | true | workforce_payroll |
| `workforce.payroll_export` | Exporter la paie | true | workforce_payroll |
| `workforce.admin` | Administration Workforce | true | workforce |

**Bundles** :

| Key | Label | Permissions | is_admin |
|-----|-------|-------------|----------|
| `workforce.team` | Gestion d'équipe | view, manage, time_manage, leave_request | false |
| `workforce.hr_management` | Gestion RH | contracts, compensation_read, compensation_manage, sensitive_read, admin | true |
| `workforce.time_validation` | Validation temps | time_manage, time_approve, planning_manage | true (mixed) |
| `workforce.payroll` | Paie | payroll_prepare, payroll_validate, payroll_export, compensation_read | true |

**Rôles recommandés** (via JobDomain default_roles) :

| Rôle | Bundles |
|------|---------|
| owner | tous les bundles |
| hr_manager | workforce.hr_management, workforce.team, workforce.time_validation |
| manager | workforce.team, workforce.time_validation |
| payroll_manager | workforce.payroll |
| employee | workforce.view (self), workforce.leave_request |

**Permissions sensibles** :
- `workforce.sensitive_read` → accès SSN/IBAN (is_admin, masquage si absent)
- `workforce.compensation_read` → accès salaires (is_admin, masquage si absent)
- `workforce.compensation_manage` → modification salaires (is_admin, audit obligatoire)
- `workforce.payroll_validate` → validation paie (is_admin, non-répudiable)

### 5.6 Markets / Legal Rules

**Évolution nécessaire** : Créer `MarketRuleSet` dans Core/Markets.

```
MarketRuleSet (NOUVEAU — Core)
├── id
├── market_key (FK markets.key)
├── domain (string — 'workforce', 'billing', 'compliance', etc.)
├── rule_key (string — 'weekly_hours_legal', 'daily_hours_max', etc.)
├── value (JSON — { amount, unit, enforcement?, exceptions? })
├── effective_from (date)
├── effective_until (date nullable)
├── source (string — 'law', 'convention', 'custom')
├── reference (string nullable — texte de loi)
├── timestamps
UNIQUE(market_key, domain, rule_key, effective_from)
```

**Règles MVP (France)** :

| rule_key | value | source |
|----------|-------|--------|
| weekly_hours_legal | `{"amount": 35, "unit": "hours"}` | Code du travail L3121-27 |
| daily_hours_max | `{"amount": 10, "unit": "hours"}` | L3121-18 |
| weekly_hours_max | `{"amount": 48, "unit": "hours"}` | L3121-20 |
| rest_daily_min | `{"amount": 11, "unit": "hours"}` | L3131-1 |
| rest_weekly_min | `{"amount": 35, "unit": "hours"}` | L3132-2 |
| break_after_hours | `{"amount": 6, "unit": "hours", "break_min": 20, "break_unit": "minutes"}` | L3121-16 |
| annual_leave_days | `{"amount": 25, "unit": "days", "basis": "working_days"}` | L3141-3 |
| min_wage_hourly_cents | `{"amount": 1178, "currency": "EUR"}` | D3231-3 (2026) |

**Versioning** : `effective_from`/`effective_until` permet de maintenir l'historique quand une loi change.

### 5.6bis CompanyPolicyStore — Évaluation des options

#### Option A — CompanyPolicyStore (domain='workforce') MVP

Table dédiée Workforce :
```
workforce_company_policies
├── company_id (unique)
├── enforcement_mode (JSON)
├── custom_rules (JSON)
├── break_policy (JSON)
├── metadata (JSON)
├── timestamps
```

**Avantage** : Simple, immédiat, scopé.
**Inconvénient** : Crée une dette — chaque futur module devra créer sa propre table de policies. Impossible de snapshotter les policies d'une company de façon transverse.

#### Option B — CompanyPolicyStore transverse (RECOMMANDÉ)

Table transverse réutilisable par tous les modules :
```
company_policies (NOUVEAU — Core)
├── id
├── company_id (FK)
├── domain (string — 'workforce', 'billing', 'documents', 'compliance', etc.)
├── policy_key (string — 'enforcement_mode', 'break_policy', 'leave_accrual_mode', etc.)
├── value (JSON — valeur de la policy)
├── effective_from (date — début de validité)
├── effective_until (date NULLABLE — fin de validité, null = courant)
├── source (string — 'jobdomain_preset', 'admin_manual', 'contract_sync', 'platform_default')
├── metadata (JSON NULLABLE)
├── timestamps
UNIQUE(company_id, domain, policy_key, effective_from)
```

**Avantages** :
1. Un seul système pour toutes les policies company (workforce, billing, documents, etc.)
2. Versioning natif via `effective_from`/`effective_until` — historique des changements
3. `source` permet de tracer l'origine (preset jobdomain, admin, contrat, etc.)
4. Snapshot facile — `WHERE company_id AND domain AND effective_from <= $date AND (effective_until IS NULL OR effective_until >= $date)`
5. Pattern identique à `MarketRuleSet` — cohérence architecturale
6. Les modules existants (billing, documents) pourront migrer leurs config éparses

**Inconvénient** : Légèrement plus abstrait qu'une table dédiée en MVP.

#### Recommandation : Option B

**Pourquoi** :
- La dette de l'Option A est certaine : billing a déjà `CompanyModule.config_json`, documents a `CompanyDocumentSetting`, et chaque nouveau module inventerait sa propre table. CompanyPolicyStore unifie.
- L'effort supplémentaire est minime (1 table au lieu d'1, même pattern).
- Le versioning (`effective_from`) est GRATUIT et nécessaire pour la paie (snapshot).
- La source tracking (`source`) résout le problème "d'où vient cette config ?"

**Policies Workforce dans CompanyPolicyStore** :

| domain | policy_key | value (exemple) | Snapshotée dans |
|--------|-----------|-----------------|-----------------|
| workforce | enforcement_mode | `{"weekly_hours":"warn","daily_hours":"block","break":"allow"}` | PayrollPeriod.rule_snapshot |
| workforce | break_policy | `{"auto_deduct_lunch":true,"lunch_duration_minutes":45}` | Timesheet metadata |
| workforce | leave_accrual_mode | `{"type":"monthly","rate":2.08}` | — (runtime) |
| workforce | overtime_policy | `{"threshold_daily":10,"threshold_weekly":35,"rate_25_bps":2500}` | PayrollPeriod.rule_snapshot |
| workforce | time_tracking_mode | `{"mode":"clock_in_out","source":["manual","mobile"]}` | — (runtime) |
| workforce | custom_leave_types | `[{"code":"demenagement","days":1,"paid":true}]` | — (runtime) |

**Modes d'enforcement** :
- `allow` : pas de vérification
- `warn` : alerte visuelle, pas bloquant
- `block` : interdit l'action (clock_out, validation timesheet)
- `manager_approval` : nécessite validation manager pour dépasser

**Service de résolution** :
```php
CompanyPolicyResolver::get(int $companyId, string $domain, string $policyKey, ?Carbon $at = null): mixed
// Résout la policy active à la date donnée (default = now)
// Fallback : MarketRuleSet si policy company absente

CompanyPolicyResolver::snapshot(int $companyId, string $domain, ?Carbon $at = null): array
// Snapshot de TOUTES les policies actives d'un domain à une date
// Utilisé par PayrollPeriod.rule_snapshot et Timesheet.metadata
```

### 5.7 Audit

**Évolution nécessaire** :

1. **Catégorisation** : Ajouter `category` dans les options AuditLogger. Pattern : `logCompany(..., ['category' => 'workforce.contract'])`.

2. **Masquage automatique** : Créer un helper `AuditSanitizer::sanitize($diff, $sensitiveKeys)` qui masque les valeurs sensibles dans `diff_before`/`diff_after` avant logging.

3. **Reason obligatoire pour Workforce** : Convention — toutes les opérations Workforce critiques DOIVENT inclure `metadata.reason`. Enforced par convention UseCase, pas par AuditLogger.

**Événements Workforce à auditer** :

| Action | Severity | Category | Raison obligatoire |
|--------|----------|----------|-------------------|
| employee.created | info | workforce.employee | non |
| employee.terminated | warning | workforce.employee | OUI |
| contract.created | info | workforce.contract | non |
| contract.activated | info | workforce.contract | non |
| contract.terminated | warning | workforce.contract | OUI |
| compensation.changed | warning | workforce.compensation | OUI |
| time_entry.corrected | warning | workforce.time | OUI |
| time_entry.force_completed | warning | workforce.time | OUI |
| leave.approved | info | workforce.leave | non |
| leave.rejected | info | workforce.leave | OUI |
| timesheet.approved | info | workforce.timesheet | non |
| payroll.validated | info | workforce.payroll | non |
| payroll.exported | info | workforce.payroll | non |
| signature.completed | info | workforce.documents | non |

### 5.8 ReadModels

**Pattern existant suffisant** : Classes statiques, pas de base class, 3-query pattern.

**Évolution recommandée** :
- Pas de cache en MVP — queries directes
- Snapshots pour payroll/timesheet = JSON `metadata` sur le model, pas un ReadModel

**ReadModels Workforce suivent exactement le pattern existant** (voir section 20).

---

## 6. ARCHITECTURE CIBLE

### Platform Layer

```
PlatformSetting.workforce (JSON)
├── default_enforcement_mode
├── payroll_export_formats
├── signature_providers
└── workforce_feature_flags

MarketRuleSet (par market_key)
├── workforce rules (versioned)
└── effective_from / effective_until

PlatformModule overrides
├── workforce → is_enabled_globally, addon_pricing
├── workforce_planning → is_enabled_globally, addon_pricing
└── etc.
```

### Company Layer

```
CompanyPolicyStore (transverse — domain='workforce' en MVP)
├── enforcement_mode per rule
├── overtime_policy, break_policy
├── Versionné effective_from/until
└── Snapshotable dans PayrollPeriod.rule_snapshot

CompanyModule config
├── workforce → { time_tracking_mode: 'clock_in_out', ... }
├── workforce_planning → { default_shift_duration: 480, ... }
└── workforce_leave → { accrual_start: 'hire_date', ... }

CompanyRole
├── field_config (+ champs Employee)
├── doc_config (+ docs Workforce)
└── permissions Workforce bundles
```

### Employee Layer

```
Employee (Core Workforce — app/Core/Workforce/Employee.php)
│   Entité fondamentale, distincte de User et Membership.
├── company_id, user_id?, employee_number
├── first_name, last_name, email, phone
├── hire_date, termination_date?, status
├── FieldValues (dynamic fields via EAV)
└── MemberDocuments (via existing system)

EmploymentContract
├── employee_id, contract_type, work_model_key
├── weekly_hours, start_date, end_date?
├── status (draft → active → suspended → terminated)
├── is_current (bool, unique constraint per employee)
└── CompensationPlan (1:N versioned)
    ├── base_salary_cents, currency, pay_frequency
    ├── overtime_rate_bps
    └── effective_from, effective_until?
```

### Operations Layer

```
TimeEntry (source de vérité unique — PAS de split à minuit)
├── employee_id, date, clock_in, clock_out?
├── status (idle → working → on_break → completed)
├── total_worked_minutes, total_break_minutes
├── source (manual, mobile, kiosk, import)
└── TimeEntryBreak (1:N)
    ├── start_at, end_at?, type, duration_minutes
    └── (lunch, rest, personal)

AccountingDayAllocator (Service — pas de table)
├── allocate(TimeEntry): AccountingAllocation[]
├── Répartit les heures d'un TimeEntry par jour comptable
├── Ex: clock_in 22:00 J → clock_out 06:00 J+1 = 2h J + 6h J+1
└── Utilisé par : Timesheet, PayrollPreparation, ReadModels temps

Shift (Phase 2 — workforce_planning)
├── employee_id, date, start_time, end_time
├── template_id?, location?
└── status (planned → confirmed → completed → cancelled)

LeaveRequest (Phase 2 — workforce_leave)
├── employee_id, leave_type_id
├── start_date, end_date, days_count
├── status (pending → approved → taken / rejected / cancelled)
└── approved_by?, reason?

LeaveLedger (Phase 2 — append-only ULID)
├── employee_id, leave_type_id, year
├── date, amount (+accrual / -debit)
├── reason, reference_type, reference_id
└── created_at (no updated_at)
```

### Payroll Layer (5 blocs — Phase 2+ progressive)

> **Vision cible** : moteur de paie complet (préparation → calcul → bulletins → déclarations → export).
> **MVP** : PayrollPreparation + PayrollExport. Les autres blocs arrivent progressivement.

```
── Bloc 1 : PayrollPreparation (Phase 2) ──
PayrollPeriod
├── company_id, year, month
├── status (open → collecting → review → validated → calculated → exported)
├── validated_by?, validated_at?, exported_at?
├── rule_snapshot (JSON — MarketRuleSet + CompanyPolicy au moment de la validation)
└── metadata (JSON)

PayrollLine
├── payroll_period_id, employee_id, contract_id
├── gross_base_cents, overtime_cents, premiums_cents
├── benefits_cents, deductions_cents
├── gross_total_cents (= base + overtime + premiums + benefits - deductions)
├── payroll_breakdown (JSON — détail de chaque composant du calcul)
├── estimated_net_preview_cents (nullable, preview_only — dépend de rule_version)
├── export_payload (JSON — données pré-formatées pour export)
├── currency (char 3)
└── metadata (JSON)

PayrollAdjustment
├── payroll_line_id, type, label, amount_cents, reason
└── created_by

── Bloc 2 : PayrollCalculationEngine (Phase 3) ──
PayrollCalculationRuleSet
├── market_key, rule_version, effective_from
├── contribution_rules (JSON — cotisations sociales, tranches, taux)
├── tax_rules (JSON — barème IR, abattements)
└── is_active

PayrollCalculationResult
├── payroll_line_id
├── rule_version (FK — version des règles utilisées)
├── gross_total_cents, contributions_employee_cents, contributions_employer_cents
├── taxable_income_cents, tax_cents, net_before_tax_cents, net_cents
├── calculation_breakdown (JSON — détail par ligne de cotisation)
├── is_official (boolean — false = preview, true = officiel)
└── calculated_at

── Bloc 3 : PayslipGeneration (Phase 3+) ──
Payslip
├── payroll_line_id, calculation_result_id
├── template_version, generated_at
├── document_id (FK → member_documents — PDF archivé)
├── snapshot (JSON — toutes les données figées)
└── status (draft → generated → sent → archived)

── Bloc 4 : PayrollDeclarations (Phase 4) ──
PayrollDeclaration
├── company_id, type (dsn_mensuelle, dsn_evenementielle, etc.)
├── period_year, period_month
├── status (draft → generated → submitted → accepted → rejected)
├── payload (JSON — contenu déclaration)
├── submission_reference, submitted_at, response_at
└── metadata (JSON)

── Bloc 5 : PayrollExport (Phase 2 — dès MVP) ──
PayrollExport (pas de table — service stateless)
├── export(PayrollPeriod, format): fichier
├── Formats : csv, json, silae, sage, custom
└── Utilise export_payload de PayrollLine
```

### Documents Layer

```
DocumentTemplate (Core — transverse)
├── code, company_id?, document_type_code
├── content_html, variables_schema
├── version, is_active

SignatureRequest (Core — transverse, Phase 3)
├── company_id, document_id
├── provider, status, signers
├── expires_at, signed_at
```

---

## 7. MODULES PROPOSÉS

| Module | Key | Responsabilité | Dépendances | Activable | Phase |
|--------|-----|---------------|-------------|-----------|-------|
| **Workforce** | `workforce` | Employee, Contract, Compensation, TimeTracking basique, Attendance | — | Auto si jobdomain le propose, sinon addon | MVP |
| **Workforce Planning** | `workforce_planning` | Shifts, WorkScheduleTemplate, ShiftSwap | `workforce` | OUI (addon) | Phase 2 |
| **Workforce Leave** | `workforce_leave` | LeaveType, LeaveRequest, LeaveLedger, LeaveBalance, Accrual | `workforce` | OUI (addon) | Phase 2 |
| **Workforce Documents** | `workforce_documents` | DocumentTemplate, ContractGeneration, HR document types | `workforce` | OUI (addon) | Phase 2 |
| **Workforce Payroll** | `workforce_payroll` | 5 blocs : Preparation, CalculationEngine, PayslipGeneration, Declarations, Export | `workforce`, `workforce_leave` | OUI (addon) | Phase 2 (prep+export), Phase 3+ (calcul, bulletins, DSN) |
| **Workforce E-Signature** | `workforce_esign` | SignatureRequest, Provider integration, Callbacks | `workforce_documents` | OUI (addon) | Phase 3 |

---

## 8. DÉPENDANCES AUTORISÉES

| Module Workforce | Peut dépendre de |
|-----------------|-----------------|
| Tous | `Core\Traits\BelongsToCompany`, `Core\Models\Company`, `Core\Models\Membership` |
| Tous | `Core\Fields\*`, `Core\Documents\*`, `Core\Audit\*` |
| Tous | `Core\Modules\*`, `Core\RBAC\*`, `Core\Markets\*` |
| Tous | `Core\Jobdomains\*` (lecture presets, jamais écriture) |
| Tous | `Core\Automation\*` (règles automatiques) |
| Tous | `Core\Notifications\*` (alertes, rappels) |
| workforce_payroll | `Core\Billing\Money` (helpers monétaires, pas le billing SaaS) |

---

## 9. DÉPENDANCES INTERDITES

| Interdit | Raison |
|----------|--------|
| `Core\Billing\Subscription` | La paie N'EST PAS un abonnement SaaS |
| `Core\Billing\Invoice` | Les bulletins NE SONT PAS des factures SaaS |
| `Core\Billing\PaymentGatewayManager` | Les salaires ne passent PAS par Stripe |
| `Modules\Logistics\*` | Workforce est transversal, pas spécifique logistique |
| `Modules\Payments\*` | Aucun paiement de salaire via le payment gateway |
| Toute lib de paie externe intégrée en runtime | Les règles de calcul sont internes à Leezr via MarketRuleSet versionné |
| `User` directement pour des données Employee | Passer par `Employee->user` (nullable) |

---

## 10. FIELD STRATEGY COMPLÈTE

### Sources des fields

```
                    ┌─────────────────────┐
                    │  FieldDefinition    │
                    │  Catalog (Code)     │
                    └────────┬────────────┘
                             │ sync()
                    ┌────────▼────────────┐
                    │  field_definitions  │
                    │  (DB — runtime)     │
                    │  company_id = NULL  │
                    └────────┬────────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
    ┌─────────▼──────┐ ┌────▼──────┐ ┌─────▼──────────┐
    │ Module declares│ │ JobDomain │ │ Market overlay │
    │ required_by_   │ │ activates │ │ adds/removes   │
    │ modules        │ │ defaults  │ │ per country    │
    └────────────────┘ └───────────┘ └────────────────┘
              │              │              │
              └──────────────┼──────────────┘
                             │
                    ┌────────▼────────────┐
                    │  FieldActivation    │
                    │  (per company)      │
                    │  enabled, required_ │
                    │  override, order    │
                    └────────┬────────────┘
                             │
                    ┌────────▼────────────┐
                    │  CompanyRole        │
                    │  field_config       │
                    │  (visibility/order  │
                    │   per role)         │
                    └────────┬────────────┘
                             │
                    ┌────────▼────────────┐
                    │  FieldValue (EAV)   │
                    │  model_type =       │
                    │  Employee/Contract  │
                    └─────────────────────┘
```

### Structured vs Dynamic

| Critère | Structurel (colonne DB) | Dynamic Field |
|---------|------------------------|---------------|
| Participe à un calcul | OUI | NON |
| Utilisé dans une state machine | OUI | NON |
| Référencé dans une requête SQL WHERE/JOIN | OUI | NON |
| Affiché dans un formulaire comme enrichissement | NON | OUI |
| Peut varier entre companies | NON (structure fixe) | OUI |
| Peut être ajouté par admin company | NON | OUI |

### Champs requis vs recommandés vs custom

| Source | Required | Recommended | Custom |
|--------|----------|-------------|--------|
| Core system | hire_date, contract_type | emergency_contact | — |
| Module workforce | badge_number, employee_number | work_schedule | — |
| JobDomain logistique | license_number, vehicle_type | geographic_zone | — |
| Company | — | — | shoe_size, parking_spot |

### Stratégie migration Fields HR existants

Les 5 champs HR dans FieldDefinitionCatalog (`hire_date`, `contract_type`, `employee_status`, `social_security_number`, `iban`) :

1. **Quand Workforce N'EST PAS activé** : Ils restent des Fields dynamiques sur User (scope `company_user`)
2. **Quand Workforce EST activé** : Les données migrent vers Employee (structurel). Les FieldValues HR sont marquées `migrated` et désactivées — PAS de miroir synchronisé, PAS de double source.
3. **Migration** : UseCase `MigrateFieldsToEmployeeUseCase` — atomique par company à l'activation du module. Rollback possible via `RollbackFieldsMigrationUseCase`.

### Company Custom Fields Strategy

Une company peut créer des fields personnalisés sur les entités Workforce via le système FieldDefinitionCatalog existant étendu.

**Entités cibles supportées** :

| target_entity | Exemple de fields custom |
|---------------|------------------------|
| `employee` | badge_number, parking_spot, shoe_size, t_shirt_size |
| `contract` | contract_reference, probation_notes, renewal_clause |
| `work_location` | site_code, building, floor, access_badge_type |
| `shift` | shift_code, required_certification, vehicle_type |
| `leave_request` | justification_type, replacement_employee |
| `document` | classification_level, retention_years |
| `payroll_adjustment` | accounting_code, cost_center |

**Schéma du custom field** (via `field_definitions` existant + extensions) :

```
field_definitions (table existante)
├── id, company_id (NOT NULL pour custom fields company)
├── code (string — unique par company)
├── label (string — libellé traduit par i18n ou saisi)
├── type (string — string, number, date, boolean, select, json)
├── scope (string — étendu : company_user, employee, contract, shift, etc.)
├── required (boolean — obligatoire au formulaire)
├── recommended (boolean — suggéré mais optionnel)
├── sensitive (boolean — masquage si sans permission)
├── encrypted (boolean — chiffrement at rest si données sensibles)
├── visibility_roles (JSON NULLABLE — rôles autorisés à voir ce field, null = tous)
├── validation_rules (JSON NULLABLE — règles Laravel ex: { "max": 255, "regex": "..." })
├── sort_order (integer — ordre d'affichage)
├── archived_at (timestamp NULLABLE — soft archive sans perte de données)
├── category (string — hr, domain, custom)
├── source (string — system, module, company)
├── timestamps
```

**Évolutions nécessaires sur `field_definitions`** :
- Ajouter `encrypted` (boolean, default false)
- Ajouter `visibility_roles` (JSON nullable)
- Ajouter `recommended` (boolean, default false)
- Le `scope` accepte déjà des valeurs extensibles (string, pas enum)

**Règle cardinale** : Les custom fields enrichissent les entités, mais ne remplacent JAMAIS :
- Contrat (colonnes structurées)
- Rémunération (colonnes structurées)
- Solde CP (ledger)
- Temps travaillé (TimeEntry)
- Conformité (ComplianceEngine)
- Paie (PayrollLine)

**Invariants Custom Fields** :
```
INV-CF-001: Un custom field company ne peut pas avoir le même code qu'un field system
INV-CF-002: La limite de custom fields par target_entity est contrôlée par entitlement platform/company, avec 20 comme valeur par défaut MVP. La limite effective est résolue via : entitlement company > entitlement plan > défaut platform (20).
INV-CF-003: Un field avec sensitive=true requiert workforce.sensitive_read pour lecture
INV-CF-004: Un field avec encrypted=true est chiffré at rest (même pattern que SMTP credentials)
INV-CF-005: Un field archivé (archived_at set) n'apparaît plus en saisie mais les données restent accessibles
INV-CF-006: visibility_roles=null → visible par tous les rôles de la company
```

---

## 11. DOCUMENT STRATEGY COMPLÈTE

### Types de documents

| Code | Scope | Source | Expiration | Category |
|------|-------|--------|------------|----------|
| employment_contract | company_user | system | NON | workforce |
| contract_amendment | company_user | system | NON | workforce |
| absence_justification | company_user | system | OUI (30j) | workforce |
| medical_certificate | company_user | system | OUI | workforce |
| employer_attestation | company_user | system | NON | workforce |
| payslip_imported | company_user | system | NON | workforce |
| internal_hr_document | company_user | company custom | NON | workforce |

### Templates

```
Platform fournit des templates par défaut
→ Company peut copier et personnaliser
→ Génération utilise DocumentVariableResolver
→ Variables : Core (company, employee, contract, compensation) + Fields dynamiques
→ Output : PDF stocké comme MemberDocument
→ Immutable après génération
```

### Variables disponibles

```
// Core variables (structurelles)
{{company.name}}, {{company.legal_name}}
{{employee.first_name}}, {{employee.last_name}}, {{employee.hire_date}}
{{contract.contract_type}}, {{contract.start_date}}, {{contract.weekly_hours}}
{{compensation.base_salary_formatted}}, {{compensation.currency}}

// Field variables (dynamiques)
{{field.siret}}, {{field.billing_address}}, {{field.social_security_number}}

// Computed variables
{{today}}, {{contract.duration_text}}, {{employee.seniority_months}}
```

### Flux génération

```
1. User sélectionne template + employee
2. DocumentVariableResolver collecte données (Employee + Contract + Company + Fields)
3. Blade/Mustache render → HTML
4. dompdf/Browsershot → PDF
5. Store comme MemberDocument (code = employment_contract, file = PDF)
6. Optionnel : créer SignatureRequest pour e-signature
```

### Flux e-signature (Phase 3)

```
1. Document généré (PDF dans MemberDocument)
2. CreateSignatureRequestUseCase → crée SignatureRequest + envoie au provider
3. Provider envoie email au(x) signataire(s)
4. Signataire signe via interface provider
5. Webhook callback → UpdateSignatureStatusUseCase
6. Statut : signed → archive document signé + preuve
7. Statut : refused → notification manager
8. Statut : expired → notification + option relance
```

### Subject-Based Document Model

**Problème** : Aujourd'hui les documents sont liés à `MemberDocument` (scope `company_user`). Workforce a besoin de rattacher des documents à Employee, Contract, LeaveRequest, PayrollPeriod, Payslip, etc.

**Décision** : Polymorphisme via `document_subject_type` / `document_subject_id` sur `member_documents`.

**Évolution du modèle** :

```
member_documents (table existante — extension)
├── ... colonnes existantes ...
├── document_subject_type (string NULLABLE — 'employee', 'contract', 'leave_request', 'payroll_period', etc.)
├── document_subject_id (bigint NULLABLE — FK vers le sujet)
├── relation_type (string NULLABLE — 'source', 'generated', 'signed', 'attachment', 'proof')
├── generation_snapshot (JSON NULLABLE — variables structurelles + field values + template_version + rule_version figés)
```

**Pourquoi pas un pivot séparé (MVP)** : Un document a un seul sujet principal (le contrat signé, la demande de congé). Le rattachement secondaire (ex: document visible depuis l'employee ET depuis le contrat) se fait via les ReadModels qui naviguent les relations.

**Note d'évolution** :
- **MVP** : `document_subject_type` / `document_subject_id` directement sur `member_documents` — un document = un sujet principal.
- **Évolution future** : Si un document doit être rattaché à **plusieurs sujets métier** simultanément (ex: un bulletin de paie lié à la fois au payroll_period ET à l'employee), introduire une table pivot `document_subjects` (document_id, subject_type, subject_id, relation_type). Ne bloque **pas** le MVP avec ce pivot.

**Sujets supportés** :

| subject_type | Exemple | relation_type |
|-------------|---------|---------------|
| `employee` | Certificat de travail | `generated` |
| `contract` | Contrat signé | `signed` |
| `leave_request` | Justificatif médical | `attachment` |
| `payroll_period` | Export paie PDF | `generated` |
| `payroll_line` | Bulletin de paie | `generated` |
| `signature_request` | Preuve de signature | `proof` |
| `company` | Document institutionnel | `source` |

**Snapshot de génération** : Tout document généré DOIT snapshotter dans `generation_snapshot` :
- Variables structurelles (employee, contract, company)
- Field values dynamiques (au moment de la génération)
- `template_version` (quelle version du template)
- `rule_version` (si applicable — règles MarketRuleSet utilisées)
- Signer metadata (si e-signature — noms, emails, dates)

### Invariants documentaires

```
DOC-WF-001: Un document généré est IMMUTABLE — toute modification = nouvelle version
DOC-WF-002: Un document signé ne peut PAS être supprimé (archivage obligatoire)
DOC-WF-003: La preuve de signature doit être stockée séparément du document (relation_type = 'proof')
DOC-WF-004: Les variables au moment de la génération sont snapshotées dans generation_snapshot
DOC-WF-005: Un template platform ne peut être modifié que par platform admin
DOC-WF-006: Un template company hérite du template platform (copie à la première modification)
DOC-WF-007: document_subject_type/id peuvent être NULL (documents legacy ou uploadés sans contexte)
DOC-WF-008: generation_snapshot est obligatoire pour tout document avec relation_type = 'generated'
```

---

## 12. JOBDOMAIN STRATEGY COMPLÈTE

### Presets Platform

```php
// Dans JobdomainRegistry ou DB jobdomains.workforce_presets
'logistique' => [
    'workforce_presets' => [
        'contract_types' => ['cdi', 'cdd', 'interim'],
        'work_models' => ['hourly', 'shift'],
        'planning_mode' => 'shift_based',
        'time_tracking_mode' => 'clock_in_out',
        'break_policy' => ['auto_deduct_lunch' => true, 'lunch_duration_minutes' => 45],
        'leave_types' => ['cp', 'rtt', 'maladie', 'accident_travail', 'sans_solde'],
        'compliance_mode' => 'warn',
        'recommended_benefits' => ['ticket_restaurant', 'prime_transport'],
    ],
],
'restauration' => [
    'workforce_presets' => [
        'contract_types' => ['cdi', 'cdd', 'extras'],
        'work_models' => ['shift', 'hourly'],
        'planning_mode' => 'shift_based',
        'time_tracking_mode' => 'clock_in_out',
        'break_policy' => ['auto_deduct_lunch' => false, 'meal_provided' => true],
        'leave_types' => ['cp', 'maladie', 'sans_solde'],
        'compliance_mode' => 'warn',
        'recommended_benefits' => ['repas_en_nature', 'prime_coupure'],
    ],
],
```

### Activation Company

1. Company est créée avec un jobdomain
2. `JobdomainGate::assignToCompany()` lit `workforce_presets`
3. Si module `workforce` est dans `default_modules` → auto-activation
4. `CompanyPolicyStore (domain='workforce')` est créée avec les defaults du preset
5. Company admin peut modifier via UI (settings Workforce)

### Overrides

```
JobDomain preset : planning_mode = 'shift_based'
Company override : planning_mode = 'flexible' (via CompanyPolicyStore (domain='workforce'))
Contract override : weekly_hours = 39 (au lieu des 35 du preset)
```

### Snapshot obligatoire à l'activation

`workforce_presets` JSON est acceptable comme configuration platform, mais UNIQUEMENT comme source de configuration initiale.

**À l'activation company** (via `JobdomainGate::assignToCompany()`) :
1. Les presets sont lus depuis `jobdomains.workforce_presets`
2. Ils sont snapshotés dans `CompanyPolicyStore` (domain='workforce')
3. Les modules sont activés via `CompanyModuleActivation`
4. Les fields sont activés via `FieldActivation`
5. Les documents sont activés via `DocumentTypeActivation`

**Après l'activation** :
- Les presets snapshotés dans CompanyPolicyStore sont la source de vérité company
- Si le JobDomain change côté platform, les companies existantes NE changent PAS automatiquement
- Un admin platform peut déclencher un `ReconcileWorkforcePresetsUseCase` pour proposer les nouveaux defaults (notification, pas application auto)

**Impact sur les entités créées après activation** :
- Modules activés : suivent CompanyModuleActivation (pas le preset live)
- Fields activés : suivent FieldActivation (pas le preset live)
- Documents activés : suivent DocumentTypeActivation (pas le preset live)
- Policies company : suivent CompanyPolicyStore (pas le preset live)
- Contrats créés : les defaults viennent de CompanyPolicyStore, pas du JobDomain

**Évolution future** (hors MVP — Phase 3+) :
- `jobdomain_presets` table versionnée (remplace le JSON)
- `jobdomain_preset_items` par domaine (workforce, billing, etc.)
- `jobdomain_market_overlays` étendus avec versioning

### Limites

- JobDomain ne CALCULE rien — il propose des valeurs initiales
- JobDomain ne BLOQUE rien — seul CompanyPolicyStore peut bloquer (enforcement_mode)
- JobDomain ne STOCKE PAS de données d'exécution
- JobDomain est un TEMPLATE, pas une source de vérité — le snapshot est la source

---

## 13. COMPLIANCE STRATEGY COMPLÈTE

### Legal Rules (MarketRuleSet)

Voir section 5.6. Règles versionnées par Market, consultables par `ComplianceEngine`.

### Company Enforcement (CompanyPolicyStore (domain='workforce'))

```php
ComplianceEngine::check(
    Company $company,
    string $ruleKey,
    mixed $actualValue
): ComplianceResult
// Returns: { compliant: bool, mode: 'allow'|'warn'|'block'|'manager_approval',
//            rule: MarketRuleSet, actual: mixed, limit: mixed, message: string }
```

**Workflow** :
1. TimeEntry clock_out → `ComplianceEngine::check('daily_hours_max', $totalWorkedMinutes / 60)`
2. Si `mode = 'block'` → rejet avec message
3. Si `mode = 'warn'` → accepté + notification manager
4. Si `mode = 'manager_approval'` → statut `pending_approval` jusqu'à validation

### Contract Override

Un contrat peut avoir `custom_compliance_rules` (JSON) qui override les règles Market pour cet employé spécifique (ex: cadre forfait jours → pas de durée max quotidienne).

### Anomalies

```
AnomalyType :
- overtime_exceeded : heures > weekly_hours contractuelles
- break_missing : pointage > 6h sans pause
- rest_insufficient : repos inter-journée < 11h
- night_work : clock_in ou clock_out entre 21h et 6h
- sunday_work : pointage un dimanche
- holiday_work : pointage un jour férié (via MarketRuleSet.public_holidays)
- late_arrival : clock_in > shift.start_time + tolerance
- early_departure : clock_out < shift.end_time - tolerance
- missing_clock_out : clock_in sans clock_out après X heures
```

**Détection** : `AnomalyDetector::scan(Company, Carbon $date)` — exécuté quotidiennement par scheduler ou à chaque clock_out.

---

## 13bis. RULE RESOLUTION STRATEGY

### Ordre de résolution global

```
1. Platform capability        — quels modules sont disponibles globalement
2. MarketRuleSet              — règles légales versionnées (cadre minimal obligatoire)
3. JobDomain preset           — suggestions par métier (defaults, pas obligations)
4. Module required settings   — contraintes techniques du module activé
5. CompanyPolicyStore         — politiques company (enforcement, overrides)
6. Contract override          — clauses spécifiques au contrat de l'employé
7. Employee active contract   — source de vérité pour cet employé
8. Validated Timesheet snap   — données figées pour la période de temps
9. PayrollPeriod snapshot     — données figées pour la période de paie
```

### Résolution par domaine

**Pour les règles calculatoires (cotisations, plafonds, tranches)** :
```
Contract override > CompanyPolicyStore > MarketRuleSet

Exemple : weekly_hours
├── MarketRuleSet.FR = 35h (légal)
├── CompanyPolicyStore = 37h (accord d'entreprise)
└── Contract = 39h (clause individuelle) ← GAGNE
```

**Pour les presets (types contrat, planning, congés)** :
```
CompanyPolicyStore > JobDomain preset

Exemple : leave_types
├── JobDomain.logistique = [cp, rtt, maladie, at]
└── CompanyPolicyStore = [cp, rtt, maladie, at, conge_demenagement] ← GAGNE (union)
```

**Pour les obligations légales (durée max, repos min, pause)** :
```
MarketRuleSet = cadre minimal NON overridable par company
CompanyPolicyStore.enforcement_mode = comment appliquer (allow, warn, block, manager_approval)

Exemple : daily_hours_max
├── MarketRuleSet.FR = 10h (L3121-18) — NON négociable
├── CompanyPolicyStore.enforcement_mode = 'warn' (l'employeur choisit la réaction)
└── Contract NE PEUT PAS override (protégé)
```

**Règle cardinale** : `MarketRuleSet` est le plancher légal. `CompanyPolicyStore` choisit le mode d'application (`allow`/`warn`/`block`/`manager_approval`) QUAND la loi le permet. `Contract` peut être plus favorable que la loi, jamais moins.

### Invariants de résolution

```
INV-RES-001: MarketRuleSet est TOUJOURS consulté — aucun bypass possible
INV-RES-002: Contract override ne peut PAS descendre en-dessous du MarketRuleSet (protection salariée)
INV-RES-003: CompanyPolicyStore.enforcement_mode s'applique au MODE, pas à la VALEUR légale
INV-RES-004: Un changement de MarketRuleSet (nouvelle loi) déclenche une alerte company
INV-RES-005: Le RuleResolver produit un ResolvedRuleSet immutable, snapshotable dans PayrollPeriod
INV-RES-006: Chaque résolution est loggée avec la source de chaque valeur (traçabilité)
```

### Risques de résolution

| # | Risque | Impact | Mitigation |
|---|--------|--------|------------|
| RES-1 | Contract override illégal (< SMIC, > 48h/sem) | Très haut | RuleResolver rejette, ComplianceEngine bloque |
| RES-2 | Changement MarketRuleSet rétroactif | Haut | effective_from/until, pas de modification en place |
| RES-3 | CompanyPolicy manquante → fallback silencieux | Moyen | Fallback explicite vers MarketRuleSet + log warning |

---

## 14. LEAVE / CP STRATEGY COMPLÈTE

### Ledger

```
workforce_leave_ledger (append-only, ULID PK)
├── id (ULID)
├── company_id
├── employee_id
├── leave_type_id
├── year (smallint)
├── date (date de l'opération)
├── amount (decimal — positif = accrual, négatif = debit)
├── reason (string — 'monthly_accrual', 'leave_taken', 'manual_correction', 'carryover', 'expiration')
├── reference_type (nullable — 'leave_request', 'admin_correction')
├── reference_id (nullable)
├── actor_id (nullable — qui a fait l'opération)
├── created_at (timestamp — NO updated_at)
```

**Invariants** :
```
LEAVE-001: LeaveLedger est APPEND-ONLY — jamais UPDATE ni DELETE
LEAVE-002: Correction = entrée inverse (storno : amount = -montant_erroné + montant_correct)
LEAVE-003: Balance = SUM(amount) WHERE employee_id AND leave_type_id AND year
LEAVE-004: Balance ne peut pas devenir négative sauf si LeaveType.allow_negative = true
LEAVE-005: Un accrual ne peut pas dépasser max_balance du LeaveType
LEAVE-006: Un carryover ne peut pas dépasser carryover_max_days
```

### Accrual

```php
AccrueLeaveUseCase::execute(AccrueLeaveData $data): void
// Appelé mensuellement par scheduler
// Pour chaque Employee actif avec contrat actif :
//   1. Calculer prorata (temps partiel, mois incomplet)
//   2. Vérifier max_balance
//   3. Insérer entrée ledger (amount = accrual_rate * prorata)
```

**Modes d'acquisition** :
- `monthly` : X jours par mois (défaut France : 2.08 jours/mois = 25/12)
- `annual` : X jours au 1er janvier (ou date anniversaire)
- `per_hour_worked` : X jours par heure travaillée (intérimaires)
- `per_day_worked` : X jours par jour travaillé

### Balance ReadModel

```php
LeaveBalanceReadModel::forEmployee(Company $company, Employee $employee, ?int $year = null): array
// Returns :
// [
//   { type: 'cp', entitled: 25.00, used: 10.00, pending: 3.00, available: 12.00, carried: 5.00 },
//   { type: 'rtt', entitled: 10.00, used: 4.00, pending: 0.00, available: 6.00, carried: 0.00 },
//   ...
// ]
// Calculé dynamiquement depuis LeaveLedger — jamais caché en colonne mutable
```

### Pièges à éviter

1. **Ne pas stocker `balance` comme colonne mutable** — toujours calculer depuis ledger
2. **Ne pas oublier le prorata** — CDD de 6 mois = 12.5 jours, pas 25
3. **Ne pas oublier le temps partiel** — 80% = 20 jours, pas 25
4. **Ne pas bloquer sur pending** — `available = entitled - used - pending + carried`
5. **Carryover = entrée ledger explicite** — `amount = min(solde_restant, carryover_max)`
6. **Expiration carryover = entrée ledger négative** — `amount = -carried_amount, reason = 'expiration'`

---

## 15. PAYROLL STRATEGY — 5 BLOCS

### Vision cible

Leezr évolue vers un **moteur de paie complet**. L'architecture est conçue dès le départ pour supporter à terme :

- Calcul des cotisations sociales (URSSAF, retraite, mutuelle, prévoyance)
- Calcul du net à payer (net avant IR, net après PAS)
- Génération de bulletins de paie officiels (PDF, archivage légal)
- Déclarations légales (DSN mensuelle, DSN événementielle)
- Règles versionnées par marché (tranches, taux, plafonds)
- Règles conventionnelles / company (accords d'entreprise, primes)

**Principe cardinal** : les 5 blocs sont **strictement séparés**. Chaque bloc peut évoluer indépendamment. Le MVP active les blocs 1 et 5, les autres arrivent progressivement.

### Architecture en 5 blocs

```
┌─────────────────────────────────────────────────────────────────┐
│ PAYROLL DOMAIN                                                  │
│                                                                 │
│  ┌──────────────────┐   ┌──────────────────────┐               │
│  │ 1. Preparation   │──▶│ 2. CalculationEngine │               │
│  │   (MVP)          │   │    (Phase 3)         │               │
│  │ Collecte, valid. │   │ Brut, cotis, net     │               │
│  │ anomalies        │   │ Règles versionnées   │               │
│  └──────────────────┘   └──────────┬───────────┘               │
│                                    │                            │
│                         ┌──────────▼───────────┐               │
│                         │ 3. PayslipGeneration │               │
│                         │    (Phase 3+)        │               │
│                         │ Bulletin PDF, snap   │               │
│                         │ archivage            │               │
│                         └──────────┬───────────┘               │
│                                    │                            │
│                         ┌──────────▼───────────┐               │
│                         │ 4. Declarations      │               │
│                         │    (Phase 4)         │               │
│                         │ DSN, statuts envoi   │               │
│                         └──────────────────────┘               │
│                                                                 │
│  ┌──────────────────┐                                          │
│  │ 5. Export        │ ← Bloc autonome, dès le MVP              │
│  │   (MVP)          │                                          │
│  │ CSV, JSON, Silae │                                          │
│  │ Sage, custom     │                                          │
│  └──────────────────┘                                          │
└─────────────────────────────────────────────────────────────────┘
```

### Bloc 1 : PayrollPreparation (MVP — Phase 2)

**Responsabilité** : Collecter, valider, préparer les données de paie.

**Fait** :
- Collecter les données de temps (timesheets validés)
- Collecter les données de congés (pris, soldes)
- Collecter les données de contrat (type, durée, salaire)
- Collecter les avantages (primes, benefits, avantages en nature)
- Calculer le brut total (salaire base + heures sup + primes + avantages - retenues)
- Détecter les anomalies (absences non justifiées, heures sup non validées, contrat expiré)
- Permettre la validation par le responsable paie
- Produire un `estimated_net_preview_cents` en preview (si CalculationEngine disponible)

```
workforce_payroll_periods
├── id, company_id
├── year (smallint), month (tinyint)
├── status (open → collecting → review → validated → calculated → exported)
├── validated_by (FK users), validated_at
├── exported_at, export_format, export_path
├── rule_snapshot (JSON — MarketRuleSet + CompanyPolicy au moment de la validation)
├── metadata (JSON)
├── timestamps
UNIQUE(company_id, year, month)
```

```
workforce_payroll_lines
├── id, company_id, payroll_period_id, employee_id
├── contract_id (FK — contrat actif au moment du calcul)
├── gross_base_cents (salaire base)
├── overtime_cents (heures sup valorisées)
├── night_premium_cents (prime nuit)
├── sunday_premium_cents (prime dimanche)
├── holiday_premium_cents (prime jour férié)
├── benefits_cents (avantages en nature, primes)
├── deductions_cents (absences non payées, avances)
├── gross_total_cents (= base + overtime + primes + benefits - deductions)
├── payroll_breakdown (JSON — détail de chaque composant du calcul brut)
├── estimated_net_preview_cents (integer NULLABLE — preview, dépend de rule_version, NON officiel)
├── export_payload (JSON — données pré-formatées pour export vers logiciel externe)
├── currency (char 3)
├── worked_hours (decimal — heures normales)
├── overtime_hours (decimal)
├── leave_days_taken (decimal — congés pris ce mois)
├── absence_days (decimal — absences non congés)
├── timestamps
```

```
workforce_payroll_adjustments
├── id, company_id, payroll_line_id
├── type (string — 'bonus', 'deduction', 'correction', 'regularization')
├── label (string)
├── amount_cents (integer — positif = ajout, négatif = retrait)
├── reason (text)
├── created_by (FK users)
├── timestamps
```

**`payroll_breakdown` JSON** (exemple) :
```json
{
  "base": { "hours": 151.67, "rate_cents": 1582, "total_cents": 239900 },
  "overtime": {
    "25pct": { "hours": 8, "rate_cents": 1978, "total_cents": 15824 },
    "50pct": { "hours": 2, "rate_cents": 2373, "total_cents": 4746 }
  },
  "premiums": {
    "night": { "hours": 0, "rate_cents": 0, "total_cents": 0 },
    "sunday": { "hours": 4, "rate_cents": 395, "total_cents": 1580 }
  },
  "benefits": [
    { "code": "meal_voucher", "label": "Titres restaurant", "employer_cents": 560, "days": 20 }
  ],
  "deductions": [
    { "code": "absence_unpaid", "days": 1, "total_cents": -10900 }
  ]
}
```

**`estimated_net_preview_cents`** — CADRAGE JURIDIQUE ET UX :
- **Nullable** — absent si CalculationEngine pas encore activé
- **preview_only** — affiché UNIQUEMENT avec un badge UI « Estimation non contractuelle »
- **Non officiel** — ne peut JAMAIS apparaître sur un bulletin de paie, document contractuel, ou déclaration légale
- **Dépend de `rule_version`** — DOIT être snapshoté dans `payroll_breakdown.estimated_net` avec la `rule_version` utilisée
- **Invalidation automatique** — tout changement de `rule_version` invalide les previews existants (recalcul requis)
- **Pas de stockage isolé** — toujours accompagné du `rule_version` et de la date de calcul dans le breakdown
- Calculé par le Bloc 2 quand disponible, sinon `null`
- **UI obligatoire** : badge `VChip color="warning" variant="tonal"` avec texte i18n `payroll.estimatedNetDisclaimer`
- **API** : le endpoint retourne `{ estimated_net_preview_cents, is_preview: true, rule_version, calculated_at, disclaimer_key }`

### Bloc 2 : PayrollCalculationEngine (Phase 3)

**Responsabilité** : Transformer le brut en net via les règles légales versionnées.

```
workforce_payroll_calculation_rulesets
├── id, market_key (FK markets)
├── rule_version (string — ex: '2026-Q1-FR')
├── effective_from (date), effective_until (date nullable)
├── contribution_rules (JSON — tranches, taux URSSAF, retraite, CSG, etc.)
├── tax_rules (JSON — barème IR, abattements, PAS)
├── convention_rules (JSON NULLABLE — règles conventionnelles applicables)
├── is_active (boolean)
├── timestamps
UNIQUE(market_key, rule_version)
```

```
workforce_payroll_calculation_results
├── id, company_id, payroll_line_id (FK)
├── rule_version (string — version des règles utilisées)
├── gross_total_cents
├── contributions_employee_cents (cotisations salariales totales)
├── contributions_employer_cents (cotisations patronales totales)
├── taxable_income_cents (assiette imposable)
├── tax_cents (PAS — prélèvement à la source)
├── net_before_tax_cents (net avant PAS)
├── net_cents (net à payer)
├── calculation_breakdown (JSON — détail par ligne de cotisation)
├── is_official (boolean — false = preview/estimation, true = calcul validé)
├── calculated_at (timestamp)
├── timestamps
```

**`calculation_breakdown` JSON** (exemple) :
```json
{
  "contributions_employee": [
    { "code": "urssaf_maladie", "label": "Maladie", "base_cents": 239900, "rate_bps": 0, "amount_cents": 0 },
    { "code": "urssaf_vieillesse_plaf", "label": "Vieillesse plafonnée", "base_cents": 239900, "rate_bps": 690, "amount_cents": 16553 },
    { "code": "agirc_arrco_t1", "label": "Retraite complémentaire T1", "base_cents": 239900, "rate_bps": 386, "amount_cents": 9260 },
    { "code": "csg_deductible", "label": "CSG déductible", "base_cents": 234222, "rate_bps": 682, "amount_cents": 15974 }
  ],
  "contributions_employer": [
    { "code": "urssaf_maladie_pat", "label": "Maladie patronale", "base_cents": 239900, "rate_bps": 700, "amount_cents": 16793 },
    { "code": "urssaf_at", "label": "AT/MP", "base_cents": 239900, "rate_bps": 120, "amount_cents": 2879 }
  ],
  "tax": {
    "code": "pas", "rate_bps": 750, "base_cents": 200000, "amount_cents": 15000
  }
}
```

**Architecture interne — 6 sous-composants** :

Le CalculationEngine n'est PAS un monolithe. Il est composé de 6 calculateurs indépendants, chacun produisant un breakdown traçable :

```
PayrollCalculationEngine (orchestrateur)
├── RuleResolver
│   ├── Résout les règles applicables pour un employé donné
│   ├── Cascade : MarketRuleSet → CompanyPolicy → Contract overrides
│   └── Retourne : ResolvedRuleSet (immutable, snapshotable)
│
├── ContributionCalculator
│   ├── Calcule cotisations salariales et patronales
│   ├── Lit : ResolvedRuleSet.contribution_rules (tranches, taux, plafonds)
│   └── Produit : ContributionBreakdown[] (code, base, rate, amount par ligne)
│
├── TaxCalculator
│   ├── Calcule le PAS (prélèvement à la source)
│   ├── Lit : ResolvedRuleSet.tax_rules (barème, abattements, taux PAS)
│   └── Produit : TaxBreakdown (taxable_income, rate, amount)
│
├── BenefitCalculator
│   ├── Calcule les avantages (titres restaurant, transport, mutuelle)
│   ├── Lit : EmployeeBenefits + ResolvedRuleSet (exonérations, plafonds)
│   └── Produit : BenefitBreakdown[] (code, employer_share, employee_share)
│
├── DeductionCalculator
│   ├── Calcule les retenues (absences, avances, saisies)
│   ├── Lit : PayrollLine.deductions_cents + ResolvedRuleSet (protections)
│   └── Produit : DeductionBreakdown[] (code, base, amount)
│
└── NetAggregator
    ├── Agrège les résultats des 5 calculateurs
    ├── Calcule : gross → contributions → taxable → tax → net
    └── Produit : PayrollCalculationResult (immutable)
```

**Règles pour chaque sous-composant** :
- Lit un ruleset versionné (jamais de valeurs hardcodées)
- Produit un breakdown traçable (JSON sérialisable)
- Ne modifie JAMAIS PayrollLine (lecture seule sur le brut)
- Retourne un résultat immutable (Value Object ou DTO final)
- Peut être testé unitairement de façon isolée

**Règles clés** :
- Les rulesets sont **versionnés** (effective_from/until) — jamais modifiés en place
- Une company peut avoir des **overrides conventionnels** via CompanyPolicyStore
- Le calcul peut fonctionner en mode **preview** (`is_official = false`) dès la Phase 3
- Le passage en **officiel** (`is_official = true`) verrouille le résultat
- Chaque sous-composant est un service Laravel injectable et remplaçable

### Bloc 3 : PayslipGeneration (Phase 3+)

**Responsabilité** : Générer des bulletins de paie officiels conformes.

```
workforce_payslips
├── id, company_id, payroll_line_id (FK)
├── calculation_result_id (FK — résultat de calcul utilisé)
├── template_version (string — version du template bulletin)
├── document_id (FK member_documents — PDF archivé via Core Documents)
├── snapshot (JSON — TOUTES les données figées au moment de la génération)
├── status (draft → generated → sent → archived)
├── generated_at, sent_at, archived_at
├── timestamps
```

**Principes** :
- Le bulletin est un **snapshot immutable** — toutes les données y sont figées
- Le PDF est archivé via le système Documents existant (MemberDocument)
- Le template est versionné — un changement de template ne touche pas les bulletins existants
- Archivage légal 5 ans minimum (configurable par market)

### Bloc 4 : PayrollDeclarations (Phase 4)

**Responsabilité** : Produire et soumettre les déclarations légales.

```
workforce_payroll_declarations
├── id, company_id
├── type (string — 'dsn_mensuelle', 'dsn_evenementielle', 'dpae', etc.)
├── period_year, period_month
├── status (draft → generated → submitted → accepted → rejected → corrected)
├── payload (JSON — contenu complet de la déclaration)
├── submission_reference (string nullable — référence retour organisme)
├── submitted_at, response_at
├── error_details (JSON nullable — détail erreurs si rejeté)
├── metadata (JSON)
├── timestamps
```

**Principes** :
- Chaque déclaration est autonome (pas de dépendance circulaire avec les bulletins)
- Le payload est un snapshot complet — reproductible
- Les erreurs de soumission sont traçables et corrigeables (statut `corrected` → re-soumission)

### Bloc 5 : PayrollExport (MVP — Phase 2)

**Responsabilité** : Exporter les données de paie vers des outils externes.

```php
PayrollExportService::export(PayrollPeriod $period, string $format): string
// Formats supportés :
// - 'csv' : colonnes standard (matricule, nom, brut, heures, congés, etc.)
// - 'json' : structure complète pour intégration API
// - 'excel' : format xlsx avec mise en forme
// Formats Phase 3+ :
// - 'silae' : format Silae (logiciel paie français populaire)
// - 'sage' : format Sage
// - 'custom' : format configurable par company
```

**Principes** :
- Utilise `export_payload` de PayrollLine (pré-formaté par la Preparation)
- Indépendant du CalculationEngine — fonctionne avec le brut seul ou avec le net si disponible
- Chaque format est un `PayrollExportFormatter` (strategy pattern)

### Séparation stricte des blocs

| Bloc | Peut lire | Ne peut PAS lire |
|------|-----------|-----------------|
| Preparation | Timesheets, Congés, Contrats, Compensation | Résultats de calcul, Bulletins |
| CalculationEngine | PayrollLine (brut), MarketRuleSet | Bulletins, Déclarations |
| PayslipGeneration | PayrollLine + CalculationResult | Déclarations |
| Declarations | PayrollLine + CalculationResult + Payslips | — |
| Export | PayrollLine.export_payload | Résultats de calcul internes |

### Invariants Payroll

```
INV-PAY-001: PayrollPeriod.status 'validated' requiert que TOUS les Timesheets de la période soient 'approved'
INV-PAY-002: PayrollPeriod.status 'validated' requiert que TOUTES les anomalies soient résolues ou acceptées
INV-PAY-003: Une fois 'validated', les PayrollLines sont IMMUTABLES (seuls les Adjustments sont autorisés)
INV-PAY-004: Une fois 'calculated', les CalculationResults sont IMMUTABLES
INV-PAY-005: Une fois 'exported', le PayrollPeriod est FIGÉ — aucune modification
INV-PAY-006: Le rule_snapshot est capturé à la validation (pas à l'export)
INV-PAY-007: estimated_net_preview_cents est NULLABLE et marqué preview_only — jamais sur document officiel
INV-PAY-008: Chaque PayrollLine référence le contract_id actif au moment du calcul
INV-PAY-009: Un Payslip avec is_official=true ne peut PAS être supprimé (archivage légal)
INV-PAY-010: Chaque CalculationResult référence la rule_version utilisée — traçabilité totale
INV-PAY-011: Les blocs sont découplés — Preparation fonctionne SANS CalculationEngine
INV-PAY-012: PayrollDeclaration.payload est un snapshot complet — indépendant de l'état courant
```

### Phases de déploiement Payroll

| Phase | Blocs activés | Capacités |
|-------|---------------|-----------|
| **Phase 2 (MVP)** | Preparation + Export | Collecte, brut, anomalies, validation, export CSV/JSON |
| **Phase 3** | + CalculationEngine + PayslipGeneration | Cotisations, net officiel via `PayrollCalculationResult.net_cents` (is_official=true), bulletins PDF. `estimated_net_preview_cents` reste NON officiel même en Phase 3. |
| **Phase 3+** | + Export enrichi | Formats Silae, Sage, custom |
| **Phase 4** | + Declarations | DSN mensuelle, DSN événementielle, DPAE |

---

## 16. MVP RECOMMANDÉ

### Phase 1 — Fondation (4-6 semaines)

**Scope** : Employee + Contract + Compensation + TimeTracking basique

| Composant | Détail |
|-----------|--------|
| `Employee` model + CRUD | Dans Core Workforce (`app/Core/Workforce/Employee.php`). Entité distincte de User et Membership. Lien User nullable. Status state machine. |
| `EmploymentContract` model + state machine | draft → active → suspended → terminated. is_current guard. |
| `CompensationPlan` model | Versionné effective_from/to. Salaire, devise, fréquence. |
| `TimeEntry` + `TimeEntryBreak` | State machine clock_in/out/break. Source tracking. |
| `CompanyPolicyStore (domain='workforce')` | Enforcement modes per rule. |
| `MarketRuleSet` (France) | 8 règles légales de base. |
| `WorkforceModule` manifest | 15 permissions, 4 bundles. |
| `ComplianceEngine` basique | Check durée max, repos, pauses. |
| `AnomalyDetector` basique | Overtime, break missing, rest insufficient. |
| Pages Vue : liste employés, fiche, pointage | VDataTableServer + drawer pattern. |
| 5 UseCases | CreateEmployee, CreateContract, ClockIn, ClockOut, ForceComplete |
| 3 ReadModels | EmployeeList, EmployeeSummary, TimeTrackingDaily |
| i18n FR + EN | |
| Tests : state machines, invariants, tenant isolation | |
| ADR-463 à ADR-466 | |

### Phase 2 — Planning + Congés + Documents (4-6 semaines)

| Composant | Détail |
|-----------|--------|
| `WorkforceLeaveModule` | LeaveType, LeaveRequest, LeaveLedger, accrual, balance |
| `WorkforcePlanningModule` | Shift, WorkScheduleTemplate, assignment |
| `WorkforceDocumentsModule` | DocumentTemplate, variable injection, PDF generation |
| `Timesheet` model + validation workflow | Agrégation période, submit → approve |
| `EmployeeBenefit` model | Primes, avantages, ticketrestaurant |
| `AttendanceReadModel` | Vue consolidée temps + congés |
| `LeaveBalanceReadModel` | Soldes calculés depuis ledger |
| Pages Vue : planning, congés, timesheets, documents | |
| Scheduler : accrual mensuel, anomaly scan | |

### Phase 2bis — Paie Préparation + Export (2-4 semaines)

| Composant | Détail |
|-----------|--------|
| `WorkforcePayrollModule` (Bloc 1+5) | PayrollPeriod, PayrollLine, PayrollAdjustment, PayrollExportService |
| `PayrollPreviewReadModel` | Aperçu avant validation (brut uniquement, pas de net) |
| `WorkforceDashboardReadModel` | KPIs RH (effectif, absents, coûts bruts, anomalies) |
| Export formats CSV/JSON/Excel | |
| Pages Vue : préparation paie, anomalies, export | |

### Phase 3 — Calcul + Bulletins + E-Signature (6-8 semaines)

| Composant | Détail |
|-----------|--------|
| `PayrollCalculationEngine` (Bloc 2) | PayrollCalculationRuleSet, PayrollCalculationResult, cotisations, net |
| `PayslipGeneration` (Bloc 3) | Payslip, templates bulletins, PDF, archivage |
| `WorkforceESignModule` | SignatureRequest, provider integration |
| `PayrollCalculationResult.net_cents` (is_official=true) | Le net officiel vit **uniquement** dans PayrollCalculationResult. `estimated_net_preview_cents` reste toujours une estimation non officielle — il ne doit **jamais** apparaître dans un bulletin officiel, une déclaration légale ou un document contractuel. |
| Pages Vue : calcul paie, bulletins, signature | |

### Phase 4 — Déclarations légales (4-6 semaines)

| Composant | Détail |
|-----------|--------|
| `PayrollDeclarations` (Bloc 4) | PayrollDeclaration, DSN mensuelle/événementielle |
| Formats export enrichis | Silae, Sage, custom |
| Suivi soumissions | Statuts, erreurs, re-soumission |

### Ce qu'il faut EXCLURE au départ (MVP uniquement)

- Convention collective (trop complexe, MVP = droit commun — architecture prête pour Phase 3+)
- Multi-pays (MVP = France uniquement — architecture MarketRuleSet prête)
- Calcul cotisations et net officiel (Phase 3 — estimated_net_preview_cents preview autorisé dès Phase 2)
- DSN et déclarations légales (Phase 4)
- Bulletins de paie officiels (Phase 3)
- Pointage biométrique / badge physique
- Multi-contrat simultané (1 contrat actif par employee en MVP)
- Workflow manager_approval pour compliance (MVP = warn/block uniquement)
- Virements de salaire (hors scope — intégration bancaire tierce)

---

## 17. TABLES PRINCIPALES

### Nouvelles tables

| Table | Phase | Company-scoped | Commentaire |
|-------|-------|---------------|-------------|
| `workforce_employees` | MVP | OUI | Entité Core Employee |
| `workforce_employment_contracts` | MVP | OUI | Agrégat contrat |
| `workforce_compensation_plans` | MVP | OUI | Versionné effective_from |
| `workforce_time_entries` | MVP | OUI | Pointage |
| `workforce_time_entry_breaks` | MVP | NON (FK time_entry) | Pauses |
| `company_policies` | MVP | OUI | CompanyPolicyStore transverse (domain='workforce' en MVP) |
| `market_rule_sets` | MVP | NON (market scope) | Règles légales versionnées |
| `workforce_leave_types` | Phase 2 | OUI | Types congés company |
| `workforce_leave_requests` | Phase 2 | OUI | Demandes congés |
| `workforce_leave_ledger` | Phase 2 | OUI | Append-only ULID |
| `workforce_shifts` | Phase 2 | OUI | Planning shifts |
| `workforce_schedule_templates` | Phase 2 | OUI | Patrons planning |
| `workforce_timesheets` | Phase 2 | OUI | Validation période |
| `workforce_employee_benefits` | Phase 2 | OUI | Avantages |
| `workforce_payroll_periods` | Phase 2 | OUI | Période paie (Bloc 1) |
| `workforce_payroll_lines` | Phase 2 | OUI | Lignes paie + payroll_breakdown + estimated_net_preview_cents (Bloc 1) |
| `workforce_payroll_adjustments` | Phase 2 | OUI | Corrections post-calcul (Bloc 1) |
| `workforce_payroll_calculation_rulesets` | Phase 3 | NON (market scope) | Règles cotisations versionnées (Bloc 2) |
| `workforce_payroll_calculation_results` | Phase 3 | OUI | Résultats calcul net (Bloc 2) |
| `workforce_payslips` | Phase 3+ | OUI | Bulletins de paie officiels (Bloc 3) |
| `workforce_payroll_declarations` | Phase 4 | OUI | DSN et déclarations légales (Bloc 4) |
| `document_templates` | Phase 2 | OUI (nullable=platform) | TRANSVERSE |
| `signature_requests` | Phase 3 | OUI | TRANSVERSE |

### Extensions de tables existantes

| Table existante | Modification | Phase |
|----------------|-------------|-------|
| `jobdomains` | ADD `workforce_presets` JSON NULLABLE | MVP |
| `platform_settings` | ADD `workforce` à fillable (JSON) | MVP |
| `field_definitions` | ADD `encrypted`, `visibility_roles`, `recommended` colonnes pour custom fields | MVP |
| `member_documents` | ADD `document_subject_type`, `document_subject_id`, `relation_type`, `generation_snapshot` | Phase 2 |
| `document_types` | ADD nouveaux types via DocumentTypeCatalog::sync() | MVP |

---

## 18. AGRÉGATS MÉTIER

### Root Aggregates

```
Employee (Core Workforce — app/Core/Workforce/Employee.php — root)
│   Entité fondamentale, distincte de User et Membership.
├── first_name, last_name, email, phone, status, hire_date
├── EmploymentContract (1:N, 1 current)
│   ├── contract_type, work_model_key, weekly_hours, status
│   └── CompensationPlan (1:N versioned)
│       └── base_salary_cents, currency, pay_frequency
├── TimeEntry (1:N per day)
│   └── TimeEntryBreak (1:N)
├── LeaveRequest (1:N)
├── EmployeeBenefit (1:N)
└── FieldValues (via EAV polymorphe)

WorkScheduleTemplate (Company — root)
└── Shift (1:N instances)

LeaveType (Company — root)
├── LeaveBalance (computed ReadModel, not stored)
└── LeaveLedger (1:N entries, append-only)

PayrollPeriod (Company — root, Bloc 1)
├── PayrollLine (1:N per employee)
│   ├── PayrollAdjustment (1:N per line, Bloc 1)
│   ├── PayrollCalculationResult (0:1, Bloc 2 — Phase 3)
│   └── Payslip (0:1, Bloc 3 — Phase 3+)
└── PayrollDeclaration (0:N, Bloc 4 — Phase 4)

CompanyPolicyStore (Company — root, transverse)
├── domain='workforce' : enforcement_mode, break_policy, overtime_policy
└── Versionné via effective_from/until

MarketRuleSet (Market — root)
└── rule_key, value, effective_from/until
```

### Value Objects (pas de models séparés)

- `Money` : amount_cents + currency (déjà dans Core/Billing)
- `DateRange` : start_date + end_date (inline dans Contract)
- `TimeRange` : start_time + end_time (inline dans Shift)
- `ComplianceResult` : compliant, mode, rule, actual, limit, message (DTO retour)

---

## 19. USECASES LARAVEL PRIORITAIRES

### MVP

```php
// Employee
CreateEmployeeUseCase(CreateEmployeeData): Employee
UpdateEmployeeUseCase(UpdateEmployeeData): Employee
TerminateEmployeeUseCase(TerminateEmployeeData): Employee
LinkEmployeeToUserUseCase(int $employeeId, int $userId): Employee

// Contract
CreateContractUseCase(CreateContractData): EmploymentContract
ActivateContractUseCase(int $contractId): EmploymentContract
SuspendContractUseCase(int $contractId, string $reason): EmploymentContract
TerminateContractUseCase(TerminateContractData): EmploymentContract

// Compensation
SetCompensationUseCase(SetCompensationData): CompensationPlan

// Time Tracking
ClockInUseCase(ClockInData): TimeEntry
StartBreakUseCase(int $timeEntryId, string $breakType): TimeEntry
EndBreakUseCase(int $timeEntryId): TimeEntry
ClockOutUseCase(int $timeEntryId): TimeEntry
ForceCompleteTimeEntryUseCase(ForceCompleteData): TimeEntry  // admin, audit obligatoire
```

### Phase 2

```php
// Leave
RequestLeaveUseCase(RequestLeaveData): LeaveRequest
ApproveLeaveUseCase(int $requestId, int $approverId): LeaveRequest
RejectLeaveUseCase(int $requestId, int $approverId, string $reason): LeaveRequest
CancelLeaveUseCase(int $requestId): LeaveRequest
AccrueMonthlyLeaveUseCase(int $companyId, int $year, int $month): int  // returns count

// Planning
CreateShiftUseCase(CreateShiftData): Shift
AssignShiftUseCase(int $shiftId, int $employeeId): Shift
BulkCreateShiftsUseCase(BulkCreateShiftsData): array

// Timesheet
SubmitTimesheetUseCase(int $timesheetId): Timesheet
ApproveTimesheetUseCase(int $timesheetId, int $approverId): Timesheet
RejectTimesheetUseCase(int $timesheetId, string $reason): Timesheet

// Documents
GenerateDocumentUseCase(GenerateDocumentData): MemberDocument
```

### Phase 2bis (Bloc 1 + 5 : Preparation + Export)

```php
// Payroll Preparation
OpenPayrollPeriodUseCase(int $companyId, int $year, int $month): PayrollPeriod
CollectPayrollDataUseCase(int $periodId): PayrollPeriod   // collecte timesheets, congés, contrats
DetectPayrollAnomaliesUseCase(int $periodId): array
AddPayrollAdjustmentUseCase(AddAdjustmentData): PayrollAdjustment
ValidatePayrollUseCase(int $periodId, int $validatorId): PayrollPeriod
ExportPayrollUseCase(int $periodId, string $format): string
```

### Phase 3 (Bloc 2 + 3 : Calcul + Bulletins)

```php
// Payroll Calculation
CalculatePayrollUseCase(int $periodId, ?string $ruleVersion): PayrollPeriod
PreviewNetEstimateUseCase(int $lineId): PayrollCalculationResult  // is_official=false
FinalizeCalculationUseCase(int $periodId): PayrollPeriod  // is_official=true, verrouille

// Payslip Generation
GeneratePayslipUseCase(int $lineId): Payslip
BulkGeneratePayslipsUseCase(int $periodId): array
SendPayslipToEmployeeUseCase(int $payslipId): Payslip

// E-Signature
CreateSignatureRequestUseCase(CreateSignatureData): SignatureRequest
HandleSignatureWebhookUseCase(array $payload): SignatureRequest
```

### Phase 4 (Bloc 4 : Déclarations)

```php
// Payroll Declarations
GenerateDsnUseCase(int $companyId, int $year, int $month, string $type): PayrollDeclaration
SubmitDeclarationUseCase(int $declarationId): PayrollDeclaration
HandleDeclarationResponseUseCase(int $declarationId, array $response): PayrollDeclaration
```

---

## 20. READMODELS PRIORITAIRES

### MVP

```php
EmployeeListReadModel::paginated(Company, filters, perPage): LengthAwarePaginator
// Liste paginée : nom, statut, contrat actif, rôle, embauche

EmployeeSummaryReadModel::get(Company, Employee): array
// Fiche complète : employee + contrat actif + compensation + dernière entrée temps + fields

TimeTrackingDailyReadModel::forDate(Company, Carbon): array
// Vue journée : tous les employés + statut temps réel (working/break/idle/completed)

TimeTrackingSummaryReadModel::forPeriod(Company, Employee, Carbon $start, Carbon $end): array
// Résumé période : heures normales, sup, nuit, dimanche, fériés

ComplianceAlertReadModel::forCompany(Company): array
// Anomalies en cours : overtime, breaks manquants, repos insuffisant
```

### Phase 2

```php
LeaveBalanceReadModel::forEmployee(Company, Employee, ?int $year): array
// Soldes tous types : entitled, used, pending, available, carried

PlanningWeekReadModel::forWeek(Company, Carbon $weekStart): array
// Planning semaine : shifts + employés + couverture + conflits

TimesheetPeriodReadModel::forPeriod(Company, Carbon $start, Carbon $end): array
// Feuilles de temps : status, heures, anomalies, validation

AttendanceReadModel::forPeriod(Company, Carbon $start, Carbon $end): array
// Présence/absence : consolidation TimeEntry + Leave + Anomalies
```

### Phase 2bis (Preparation)

```php
PayrollPreviewReadModel::forPeriod(PayrollPeriod): array
// Aperçu paie : tous les employés + lignes brut + ajustements + anomalies
// Inclut estimated_net_preview_cents si CalculationEngine disponible (sinon null)

WorkforceDashboardReadModel::summary(Company): array
// KPIs : effectif total, absents, heures mois, coût brut, anomalies, contrats expirant
```

### Phase 3 (Calcul + Bulletins)

```php
PayrollCalculationReadModel::forPeriod(PayrollPeriod): array
// Détail calcul : brut → cotisations → net par employé + totaux company

PayslipReadModel::forEmployee(Company, Employee, ?int $year): array
// Historique bulletins : liste par période, status, PDF link
```

---

## 21. INVARIANTS MÉTIER CRITIQUES

### Employee

```
INV-EMP-001: Employee.company_id est immutable après création
INV-EMP-002: Employee.employee_number est unique par company
INV-EMP-003: Employee.status ne change que via les transitions autorisées (active→on_leave→active, active→terminated, etc.)
INV-EMP-004: Employee.termination_date DOIT être set quand status = 'terminated'
INV-EMP-005: Employee.user_id est nullable — un employé peut exister sans User
```

### Contract

```
INV-CTR-001: Un Employee ne peut avoir qu'un seul Contract avec is_current=true
INV-CTR-002: Contract.status suit la matrice : draft→active, active→suspended, active→terminated, suspended→active, suspended→terminated
INV-CTR-003: Contract.end_date DOIT être set si contract_type ∈ [cdd, interim, stage, alternance]
INV-CTR-004: Contract.start_date ≤ Contract.end_date (si end_date set)
INV-CTR-005: Activer un contrat DOIT d'abord terminer le contrat is_current existant
```

### Compensation

```
INV-CMP-001: CompensationPlan.effective_from ≥ Contract.start_date
INV-CMP-002: CompensationPlan.effective_from de la nouvelle version > effective_from de la version précédente
INV-CMP-003: Toute modification de compensation crée une NOUVELLE version (jamais UPDATE)
INV-CMP-004: Toute modification crée un AuditLog severity=warning avec reason obligatoire
```

### Time Tracking

```
INV-TIM-001: TimeEntry.status suit : idle→working, working→on_break, on_break→working, working→completed
INV-TIM-002: clock_out > clock_in (même si jour différent — travail de nuit autorisé, PAS de split à minuit)
INV-TIM-003: total_break_minutes = SUM(breaks.duration_minutes)
INV-TIM-004: total_worked_minutes = (clock_out - clock_in en minutes) - total_break_minutes
INV-TIM-005: Un Employee ne peut avoir qu'un seul TimeEntry avec status ∈ [working, on_break] à un instant T
INV-TIM-006: Un Employee DOIT avoir un contrat actif pour pointer
INV-TIM-007: TimeEntry est la source de vérité unique — JAMAIS de split automatique dans la table source
INV-TIM-008: La répartition par jour comptable se fait dans AccountingDayAllocator (ReadModel/Service), pas dans TimeEntry
```

### Leave

```
INV-LEA-001: LeaveLedger est APPEND-ONLY — jamais UPDATE ni DELETE
INV-LEA-002: Balance = SUM(ledger.amount) WHERE employee_id AND leave_type_id AND year
INV-LEA-003: LeaveRequest.days_count ne peut pas rendre balance < 0 (sauf allow_negative)
INV-LEA-004: LeaveRequest.start_date ne peut pas chevaucher un autre LeaveRequest approved/taken
INV-LEA-005: Corrections = entrée storno (inverse + correction, JAMAIS modification)
```

### Payroll (5 blocs)

```
── Bloc 1 : Preparation ──
INV-PAY-001: PayrollPeriod.status 'validated' requiert TOUS les Timesheets 'approved'
INV-PAY-002: PayrollPeriod.status 'validated' requiert TOUTES les anomalies résolues ou acceptées
INV-PAY-003: Une fois 'validated', PayrollLines IMMUTABLES (seuls Adjustments autorisés)
INV-PAY-004: Une fois 'exported', PayrollPeriod FIGÉ — aucune modification
INV-PAY-005: rule_snapshot capturé à validation (pas à export)
INV-PAY-006: Chaque PayrollLine référence le contract_id actif au moment du calcul
INV-PAY-007: estimated_net_preview_cents NULLABLE et preview_only — JAMAIS sur document officiel
INV-PAY-008: payroll_breakdown JSON obligatoire — détail de chaque composant du brut

── Bloc 2 : CalculationEngine ──
INV-PAY-009: CalculationResult.is_official=true → IMMUTABLE
INV-PAY-010: Chaque CalculationResult référence rule_version — traçabilité totale
INV-PAY-011: Changement de rule_version invalide estimated_net_preview_cents existants
INV-PAY-012: Cotisations calculées à partir de contribution_rules versionnées — jamais hardcodées

── Bloc 3 : PayslipGeneration ──
INV-PAY-013: Payslip snapshot contient TOUTES les données — autonome sans DB
INV-PAY-014: Payslip avec is_official ne peut PAS être supprimé (archivage légal)
INV-PAY-015: Un Payslip nécessite un CalculationResult avec is_official=true

── Bloc 4 : Declarations ──
INV-PAY-016: PayrollDeclaration.payload est snapshot complet — indépendant de l'état courant
INV-PAY-017: Déclaration 'submitted' ne peut pas être modifiée — correction = nouvelle déclaration

── Séparation des blocs ──
INV-PAY-018: Bloc 1 (Preparation) fonctionne SANS Bloc 2 (CalculationEngine)
INV-PAY-019: Bloc 2 ne peut PAS modifier les PayrollLines du Bloc 1
INV-PAY-020: Chaque bloc a ses propres tables — pas de colonnes partagées entre blocs
```

---

## 22. TESTS INDISPENSABLES

### Unit tests

```
// State machines (3 fichiers)
TimeEntryTransitionTest — transitions valides/invalides, guards
EmploymentContractTransitionTest — draft→active→terminated, is_current guard
LeaveRequestTransitionTest — pending→approved→taken, rejected, cancelled

// Invariants (4 fichiers)
TimeEntryInvariantsTest — clock_out > clock_in, breaks calc, night work (no split)
AccountingDayAllocatorTest — répartition J-1/J pour travail de nuit, impact Timesheet/Payroll
LeaveBalanceConsistencyTest — ledger sum = balance, overdraft guard
ContractUniquenessTest — un seul is_current, activation force terminaison ancien
CompensationVersioningTest — effective_from guard, immutabilité versions

// Compliance (2 fichiers)
ComplianceEngineTest — check tous les rule_keys, modes enforcement
AnomalyDetectorTest — détection overtime, break, rest, night, sunday

// Payroll Preparation (2 fichiers)
PayrollPreparationTest — collecte données, brut calc, anomalies, breakdown JSON
PayrollAdjustmentTest — types, montants, immutabilité post-validation
```

### Integration tests

```
// Workflows complets (3 fichiers)
EmployeeLifecycleTest — create → contract → compensation → clock_in → clock_out → timesheet
LeaveAccrualCycleTest — accrual mensuel → request → approve → debit → balance check
PayrollPeriodWorkflowTest — open → collect → review → validate → calculate → export
PayrollCalculationEngineTest — rulesets versionnés, cotisations, net, preview vs officiel (Phase 3)
PayslipGenerationTest — snapshot, PDF, archivage, immutabilité (Phase 3+)

// Compliance end-to-end (1 fichier)
ComplianceEnforcementTest — overtime block, break warning, rest violation
```

### Tenant isolation

```
// Isolation (2 fichiers)
WorkforceTenantIsolationTest — employee company A invisible de company B (BelongsToCompany)
WorkforceCompanyScopeTest — TOUTES les requêtes filtrées par company_id
```

### RBAC

```
// Permissions (2 fichiers)
WorkforcePermissionTest — manager peut approuver, employee peut demander, admin peut forcer
WorkforceSensitiveFieldsTest — SSN/IBAN visible seulement avec workforce.sensitive_read
WorkforceCompensationAccessTest — salaires visibles seulement avec workforce.compensation_read
```

### Performance

```
// Benchmarks (1 fichier)
WorkforcePerformanceTest
— EmployeeListReadModel avec 500 employees < 500ms
— PayrollPreparation (collecte + brut) pour 200 employees < 5s
— PayrollCalculation (cotisations + net) pour 200 employees < 15s (queue job)
— PayslipGeneration (PDF) pour 200 employees < 60s (queue batch)
— LeaveBalanceReadModel pour 500 employees < 2s
— TimeTrackingDailyReadModel pour 200 employees < 500ms
```

---

## 23. ADR À CRÉER

### ADR-463 : Module Workforce — Architecture générale

**Décisions** :
1. Workforce est une famille de 6 modules activables
2. Module racine `workforce` inclut Employee, Contract, Compensation, TimeTracking
3. Leezr évolue vers un moteur de paie complet en 5 blocs séparés (Preparation, CalculationEngine, PayslipGeneration, Declarations, Export)
4. MVP Payroll = Preparation + Export. Phase 3 = Calcul + Bulletins. Phase 4 = DSN.
5. MVP = France uniquement pour règles légales (architecture MarketRuleSet multi-pays prête)
6. 15 permissions regroupées en 4 bundles
7. estimated_net_preview_cents autorisé en preview dès le MVP (nullable, non officiel)

**Conséquences** : Nouvelle famille de modules, nouveau domaine Core (Employee), 24 nouvelles tables (19 + 4 tables Payroll Blocs 2-4 + company_policies). 10 ADR (ADR-463 à ADR-472).

### ADR-464 : Employee — Entité Core séparée

**Décisions** :
1. Employee est dans `app/Core/Workforce/Employee.php` — pas dans un module
2. Employee.user_id est nullable (employé sans accès SPA)
3. Employee ≠ User ≠ Membership
4. Un User peut être Employee dans N companies
5. Lien Employee→User via employee.user_id (FK nullable)

**Conséquences** : Nouvelle entité fondamentale Core. Membership reste pour l'accès SPA.

### ADR-465 : MarketRuleSet — Règles légales versionnées

**Décisions** :
1. Nouvelle table `market_rule_sets` dans Core/Markets
2. Versioning via effective_from/effective_until
3. Domain = 'workforce' en MVP, extensible à d'autres domaines
4. CompanyPolicyStore (domain='workforce') override enforcement mode (allow/warn/block)
5. Contract peut override règles spécifiques

**Conséquences** : Extension du modèle Market. Bénéficie à tous les futurs modules nécessitant des règles légales.

### ADR-466 : Leave Ledger — Pattern comptable append-only

**Décisions** :
1. LeaveLedger est append-only, ULID PK, pas de updated_at
2. Balance = SUM(amount) dynamique, jamais stockée en colonne mutable
3. Corrections = entrées storno (inverse + nouveau montant)
4. Accrual mensuel par scheduler

**Conséquences** : Intégrité comptable garantie. Performances acceptables jusqu'à ~5 ans d'historique par employee.

### ADR-467 : Field Strategy — Structured vs Dynamic

**Décisions** :
1. Champs qui participent à des calculs = colonnes structurées (salaire, heures, dates)
2. Champs d'enrichissement = dynamic Fields (badge_number, shoe_size)
3. FieldValue.model_type étendu pour cibler Employee et Contract
4. Migration one-shot des FieldValues HR vers Employee structurel à l'activation du module
5. SENSITIVE_CODES étendu pour workforce

**Conséquences** : Pas de duplication Fields. Migration transparente.

### ADR-468 : Document Generation — Infrastructure transverse

**Décisions** :
1. DocumentTemplate dans Core (pas dans un module Workforce)
2. Variables résolues depuis Core models + FieldValues
3. Company peut override templates platform (copie locale)
4. Document généré = MemberDocument immutable, avec generation_snapshot obligatoire
5. E-signature via SignatureRequest (Core, Phase 3)
6. Documents rattachés à un sujet via document_subject_type/document_subject_id (polymorphe)

**Conséquences** : Tous les modules bénéficient de la génération de documents, pas seulement Workforce.

### ADR-469 : CompanyPolicyStore — Policies company transverses

**Décisions** :
1. Nouvelle table `company_policies` dans Core (pas une table spécifique Workforce)
2. Versionné via effective_from/effective_until (historique des changements)
3. Domain-scoped (workforce, billing, documents, etc.)
4. Source tracking (jobdomain_preset, admin_manual, contract_sync, platform_default)
5. Snapshotable pour PayrollPeriod.rule_snapshot et Timesheet.metadata

**Conséquences** : Unifie les settings company éparses (CompanyModule.config_json, etc.). Tous les modules en bénéficient.

### ADR-470 : PayrollCalculationEngine — Moteur composé

**Décisions** :
1. Le CalculationEngine est composé de 6 sous-composants indépendants (RuleResolver, ContributionCalculator, TaxCalculator, BenefitCalculator, DeductionCalculator, NetAggregator)
2. Chaque composant lit un ruleset versionné, produit un breakdown traçable, ne modifie jamais PayrollLine
3. `estimated_net_preview_cents` est nullable, preview_only, badge UI "Estimation non contractuelle" obligatoire
4. Pas de valeurs hardcodées — tout vient de MarketRuleSet versionné

**Conséquences** : Testabilité unitaire par composant. Extensibilité (ajout d'un calculateur CSG sans toucher les autres).

### ADR-471 : TimeEntry Night Work — Pas de split automatique

**Décisions** :
1. TimeEntry est la source de vérité unique, même pour le travail de nuit (clock_in J, clock_out J+1)
2. Pas de split automatique dans la table source
3. La répartition par jour comptable se fait via AccountingDayAllocator (service, pas table)
4. AccountingDayAllocator est utilisé par Timesheet, PayrollPreparation, et ReadModels

**Conséquences** : Source de vérité unique. Simplicité. Pas de données dupliquées.

### ADR-472 : AI Workforce Assistant — Guardrails

**Décisions** :
1. L'IA lit via ReadModels, exécute via UseCases, respecte RBAC et tenant isolation
2. Actions sensibles (contrat, paie, signature) : human_confirmation_required = true
3. Chaque action IA loggée dans AuditLogger avec actor_type='ai'
4. L'IA ne produit jamais de valeur officielle (paie, bulletin, déclaration)
5. 2 permissions dédiées : workforce.ai_assist et workforce.ai_execute
6. Réutilise l'infrastructure AiGatewayManager/AiPolicyResolver/AiQuotaManager existante

**Conséquences** : L'IA est un assistant, pas un décideur. Traçabilité totale.

---

## 24. RISQUES

### Risques techniques

| # | Risque | Impact | Probabilité | Mitigation |
|---|--------|--------|-------------|------------|
| T1 | Employee ≠ User confusion dans le code | Haut | Moyenne | Entité séparée + interdiction d'importer User directement |
| T2 | State machine TimeEntry complexe | Moyen | Haute | Guards stricts booted(), tests exhaustifs |
| T3 | LeaveLedger consistency sous charge | Moyen | Basse | Transactions, pas de UPDATE/DELETE |
| T4 | Performance PayrollCalculation 500+ employees | Moyen | Moyenne | Batch processing, indexes composites, calcul asynchrone (queue) |
| T7 | Versioning règles cotisations (changements trimestriels) | Haut | Haute | MarketRuleSet versionné, effective_from/until, tests de non-régression |
| T8 | Conformité bulletins de paie officiels | Très haut | Moyenne | Validation juridique externe, template versionné, archivage légal 5 ans |
| T5 | Migration FieldValues → Employee structurel | Moyen | Moyenne | UseCase one-shot + rollback possible |
| T6 | Midnight crossing TimeEntry | Faible | Haute | TimeEntry unique (pas de split), AccountingDayAllocator pour répartition comptable dans ReadModels/Timesheets/Payroll |

### Risques métier

| # | Risque | Impact | Probabilité | Mitigation |
|---|--------|--------|-------------|------------|
| B1 | Mélange des blocs Payroll (Preparation + Calcul couplés) | Très haut | Haute | Séparation stricte en 5 blocs, tables séparées, invariant INV-PAY-018/019/020 |
| B2 | Droit du travail multi-pays trop tôt | Haut | Moyenne | MVP = France, architecture MarketRuleSet prête |
| B3 | Convention collective | Haut | Moyenne | Reporté — droit commun en MVP |
| B4 | Admin veut se voir comme employee + admin | Moyen | Haute | Employee.user_id → même User, 2 contextes distincts |
| B5 | Company veut 40 types de congés custom | Moyen | Moyenne | LeaveType company-scoped, pas de limite artificielle |

### Risques UX

| # | Risque | Impact | Probabilité | Mitigation |
|---|--------|--------|-------------|------------|
| U1 | Trop de permissions dans l'UI admin | Haut | Haute | 4 bundles haut-niveau, pas 15 permissions unitaires |
| U2 | Fiche employee trop dense | Moyen | Haute | Tabs (identité, contrat, temps, congés, documents) |
| U3 | Pointage mobile compliqué | Moyen | Moyenne | Clock in/out = 1 bouton, pas de formulaire |
| U4 | Planning illisible | Moyen | Moyenne | Vue semaine grid, preset Vuexy existant |

### Risques conformité

| # | Risque | Impact | Probabilité | Mitigation |
|---|--------|--------|-------------|------------|
| C1 | Données sensibles (SSN, salaires) mal protégées | Très haut | Basse | Permissions is_admin, masquage, audit |
| C2 | Archivage documents RH non conforme | Haut | Moyenne | Documents immutables, pas de suppression |
| C3 | RGPD données employee | Haut | Basse | Purge possible via UseCase dédié (anonymisation) |
| C4 | Audit trail incomplet pour inspection travail | Haut | Basse | Audit obligatoire sur toutes les opérations sensibles |

---

## 24bis. AI WORKFORCE ASSISTANT

### Principe obligatoire

L'IA aide les companies dans Workforce, mais ne contourne JAMAIS l'architecture :

```
AI Workforce Assistant
├── Lit UNIQUEMENT via ReadModels (jamais de query directe)
├── Propose des actions (suggestions, pas exécutions)
├── Exécute UNIQUEMENT via UseCases autorisés (jamais de mutation directe DB)
├── Respecte RBAC (permissions Workforce vérifiées avant chaque action)
├── Respecte tenant isolation (company_id scopé, jamais cross-tenant)
├── Respecte field/document permissions (sensitive_read, compensation_read)
├── Ne modifie JAMAIS la DB directement (passe par UseCases)
├── Ne remplace JAMAIS les invariants métier (state machines, compliance)
├── Ne produit JAMAIS de décision légale/paie officielle sans validation humaine
└── Chaque action IA est loggée dans AuditLogger (actor_type='ai')
```

### Architecture technique

```
WorkforceAiOrchestrator (extends existing AiModuleContract pattern)
├── reçoit intention utilisateur (prompt ou action UI)
├── vérifie permissions via useCan() / CompanyRole
├── récupère contexte via ReadModels (EmployeeSummary, LeaveBalance, etc.)
├── résout AiPolicy via AiPolicyResolver::forModule(company_id, 'workforce')
├── vérifie quota via AiQuotaManager::canProcess(company, 'workforce')
├── appelle AiGatewayManager → adapter (Ollama/Anthropic)
├── produit action_plan (suggestions structurées, pas texte libre)
├── si action sensible : human_confirmation_required = true
├── si confirmé : exécute UseCases via dispatch
├── écrit AiRequestLog + CompanyAuditLog (actor_type='ai')
└── retourne résultat au frontend
```

**Réutilisation de l'infrastructure existante** :
- `AiGatewayManager` : résolution du provider (Ollama, Anthropic, Null)
- `AiPolicyResolver` : résolution de la politique AI par company/module
- `AiQuotaManager` : quotas par company/mois
- `AiRequestLog` : logging centralisé
- `AiModuleContract` : interface que `WorkforceAiModule` implémente
- `ProcessDocumentAiJob` pattern : même pattern async pour Workforce

### Cas d'usage IA

#### 1. Assistant onboarding Workforce
```
Contexte : Company vient d'activer le module Workforce
ReadModels utilisés : CompanyModuleReadModel, FieldDefinitionReadModel
Propose : config selon JobDomain, fields/documents/policies manquants
Exécute : aucun UseCase — suggestions uniquement
Permission : workforce.admin
Human confirmation : NON (suggestions seulement)
```

#### 2. Assistant contrat
```
Contexte : Admin crée un contrat pour un employee
ReadModels utilisés : EmployeeSummaryReadModel, MarketRuleSetReadModel
Propose : préremplit selon Employee/Contract/Compensation/Benefits existants
Explique : clauses légales, durée probation, convention applicable
Exécute : AUCUN — l'admin confirme et le UseCase CreateContractUseCase s'exécute normalement
Permission : workforce.contracts
Human confirmation : OUI (toujours pour contrat)
```

#### 3. Assistant planning / pointage
```
Contexte : Manager consulte le planning ou le suivi temps
ReadModels utilisés : TimeTrackingDailyReadModel, ComplianceAlertReadModel, PlanningWeekReadModel
Détecte : anomalies (retards, pauses manquantes, repos insuffisant)
Propose : corrections, réaffectations de shift
Exécute : ForceCompleteTimeEntryUseCase (si confirmé par manager)
Permission : workforce.time_manage
Human confirmation : OUI (pour corrections)
```

#### 4. Assistant congés
```
Contexte : Manager ou employee consulte les congés
ReadModels utilisés : LeaveBalanceReadModel, PlanningWeekReadModel
Explique : soldes CP, acquisition projetée, impact sur planning
Détecte : conflits planning, sous-effectif
Propose : validation ou refus avec justification
Exécute : ApproveLeaveUseCase / RejectLeaveUseCase (si manager confirme)
Permission : workforce.leave_approve (manager), workforce.leave_request (employee)
Human confirmation : OUI (pour approbation/refus)
```

#### 5. Assistant paie
```
Contexte : Responsable paie consulte la préparation
ReadModels utilisés : PayrollPreviewReadModel, PayrollCalculationReadModel
Explique : payroll_breakdown détaillé, comparaison mois précédent
Détecte : variations anormales (salaire +/- 20%, heures sup excessives)
Explique : estimated_net_preview_cents comme estimation NON contractuelle
NE VALIDE JAMAIS seul la paie — toujours human confirmation
Exécute : AUCUN — suggestions et explications
Permission : workforce.payroll_prepare
Human confirmation : N/A (lecture seule)
```

#### 6. Assistant conformité
```
Contexte : Admin ou manager consulte les alertes conformité
ReadModels utilisés : ComplianceAlertReadModel, MarketRuleSetReadModel
Explique : règles appliquées, cite rule_version et MarketRuleSet
Signale : risques, zones de non-conformité
NE REMPLACE PAS un conseil juridique (disclaimer obligatoire)
Exécute : AUCUN
Permission : workforce.admin
Human confirmation : N/A (lecture seule)
```

#### 7. Assistant documents
```
Contexte : Admin prépare un document RH
ReadModels utilisés : EmployeeSummaryReadModel, DocumentTemplateReadModel
Propose : template adapté, vérifie variables manquantes
Prépare : envoi signature (pré-remplit signers)
NE SIGNE JAMAIS seul — SignatureRequest requiert human action
Exécute : GenerateDocumentUseCase (si admin confirme)
Permission : workforce.contracts (contrats), workforce.manage (autres docs)
Human confirmation : OUI (pour génération et signature)
```

### Permissions IA spécifiques

| Key | Label | is_admin | Module |
|-----|-------|----------|--------|
| `workforce.ai_assist` | Utiliser l'assistant IA Workforce | false | workforce |
| `workforce.ai_execute` | Autoriser l'IA à exécuter des actions | true | workforce |

**Bundles IA** :
- `workforce.ai_assist` dans le bundle `workforce.team` (tous les managers)
- `workforce.ai_execute` dans le bundle `workforce.hr_management` (admin RH seulement)

### Guardrails IA

```
GUARD-AI-001: L'IA ne peut PAS modifier PayrollLine directement — passe par UseCases
GUARD-AI-002: L'IA ne peut PAS approuver une paie — human_confirmation_required = true
GUARD-AI-003: L'IA ne peut PAS signer un document — SignatureRequest est humain
GUARD-AI-004: L'IA ne peut PAS terminer un contrat — human_confirmation_required = true
GUARD-AI-005: L'IA ne peut PAS accéder aux champs sensibles (SSN, IBAN) sans workforce.sensitive_read
GUARD-AI-006: L'IA ne peut PAS voir les salaires sans workforce.compensation_read
GUARD-AI-007: L'IA ne peut PAS produire de conseil juridique (disclaimer i18n obligatoire)
GUARD-AI-008: L'IA ne peut PAS produire de valeur officielle pour estimated_net_preview_cents
GUARD-AI-009: Chaque action IA est loggée avec actor_type='ai', metadata.ai_model, metadata.prompt_hash
GUARD-AI-010: L'IA respecte le quota AiQuotaManager — pas de retry illimité
```

### Séparation suggestions vs actions

| Type | Exemple | Human confirmation | UseCase exécuté |
|------|---------|-------------------|----------------|
| **Suggestion** | "Cet employé dépasse 48h/sem" | NON | AUCUN |
| **Explication** | "Le breakdown montre 8h sup à 25%" | NON | AUCUN |
| **Proposition** | "Proposer un shift de remplacement" | NON (affichage) | AUCUN |
| **Action sûre** | "Pré-remplir le contrat" | NON | AUCUN (pré-remplissage UI) |
| **Action sensible** | "Forcer clock_out à 18:00" | OUI | ForceCompleteTimeEntryUseCase |
| **Action critique** | "Valider la paie du mois" | TOUJOURS | ValidatePayrollUseCase |

### ReadModels nécessaires pour l'IA

Les ReadModels existants suffisent. L'IA n'a PAS besoin de ReadModels dédiés — elle consomme les mêmes que l'UI :
- `EmployeeListReadModel`, `EmployeeSummaryReadModel`
- `TimeTrackingDailyReadModel`, `TimeTrackingSummaryReadModel`
- `LeaveBalanceReadModel`, `ComplianceAlertReadModel`
- `PayrollPreviewReadModel`, `PayrollCalculationReadModel`
- `PlanningWeekReadModel`, `AttendanceReadModel`

### MVP IA recommandé

**Phase 1 (avec Workforce MVP)** : Assistant conformité (read-only, explications) + Assistant onboarding (suggestions)
**Phase 2** : Assistant congés + planning (suggestions + actions avec confirmation)
**Phase 3** : Assistant paie + contrat + documents (explications + génération avec confirmation)

### Risques IA

| # | Risque | Impact | Mitigation |
|---|--------|--------|------------|
| AI-1 | Hallucination sur règles légales | Très haut | Toujours citer rule_version + MarketRuleSet, disclaimer |
| AI-2 | Action IA non auditée | Haut | AuditLogger obligatoire, actor_type='ai' |
| AI-3 | Bypass RBAC par l'IA | Très haut | Vérification permissions dans WorkforceAiOrchestrator |
| AI-4 | Cross-tenant data leak via prompt | Très haut | CompanyScope global scope, jamais de query cross-company |
| AI-5 | estimated_net_preview_cents présenté comme officiel par l'IA | Haut | GUARD-AI-008, disclaimer i18n obligatoire |

---

## 25. RECOMMANDATION FINALE

### Plan d'action concret

```
SEMAINE 1-2 : Fondations
├── ADR-463 à ADR-468 dans docs/bmad/04-decisions.md
├── Migration MarketRuleSet + seed France
├── Migration Employee + EmploymentContract + CompensationPlan
├── Migration TimeEntry + TimeEntryBreak
├── Migration CompanyPolicyStore (domain='workforce')
├── Employee model + state machine + BelongsToCompany
├── EmploymentContract model + state machine + is_current guard
├── CompensationPlan model + versioning

SEMAINE 3-4 : Core opérationnel
├── WorkforceModule manifest (permissions, bundles, nav items)
├── 5 UseCases Employee (create, update, terminate, link user)
├── 4 UseCases Contract (create, activate, suspend, terminate)
├── 1 UseCase Compensation (set)
├── 5 UseCases TimeTracking (clock_in, start_break, end_break, clock_out, force_complete)
├── ComplianceEngine + AnomalyDetector
├── FieldDefinitionCatalog extension (badge_number, etc.)
├── DocumentTypeCatalog extension (employment_contract, etc.)
├── TagDictionary extension (PAYROLL, WORKFORCE, etc.)

SEMAINE 5-6 : Frontend + Tests
├── Pages Vue : /company/workforce/employees, /company/workforce/time-tracking
├── Stores Pinia : workforce.store.js
├── 3 ReadModels MVP (EmployeeList, EmployeeSummary, TimeTrackingDaily)
├── i18n (fr.json + en.json)
├── Tests state machines + invariants + tenant isolation + RBAC
├── pnpm build clean + php artisan test green
```

### Décisions à prendre AVANT codage

1. **Confirmer** que Employee est dans Core Workforce (`app/Core/Workforce/Employee.php`) — entité fondamentale distincte de User et Membership, pas dans un module
2. **Confirmer** que Workforce est 6 modules (pas 1 seul)
3. **Confirmer** MVP = France uniquement (architecture multi-pays prête)
4. **Confirmer** Payroll en 5 blocs séparés (Preparation → CalculationEngine → PayslipGeneration → Declarations → Export)
5. **Confirmer** MVP Payroll = Preparation + Export. Phase 3 = Calcul + Bulletins. Phase 4 = DSN.
6. **Confirmer** architecture prête pour CalculationEngine / PayslipGeneration / Declarations à terme
7. **Confirmer** que `estimated_net_preview_cents` est NON officiel, preview seulement, badge UI obligatoire
8. **Confirmer** 15 permissions / 4 bundles + 2 permissions IA (pas plus)
9. **Valider** le schéma MarketRuleSet (transverse, pas spécifique Workforce)
10. **Valider** CompanyPolicyStore transverse (Option B recommandée) vs table dédiée (Option A)
11. **Valider** la stratégie Fields (structured vs dynamic, migration sans double source, rollback)
12. **Valider** la stratégie Documents subject-based (document_subject_type/id sur member_documents)
13. **Choisir** le moteur PDF pour document generation (dompdf vs Browsershot)
14. **Choisir** le provider e-signature initial (Yousign recommandé pour France)
15. **Décider** si `document_templates` est Core ou Module (recommandation : Core)

### Ordre de construction

```
1. ADR (documentation)
2. Migrations (tables)
3. Models Core (Employee, Contract, Compensation)
4. State machines + guards
5. Module manifest + permissions
6. UseCases (orchestration)
7. ReadModels (lecture)
8. ComplianceEngine (conformité)
9. Controllers passifs
10. Pages Vue
11. i18n
12. Tests
13. Build + deploy staging
```

---

## 26. ÉVOLUTION NÉCESSAIRE DE L'EXISTANT

| Brique | Suffisante ? | Évolution recommandée | Pourquoi | Risque si inchangé | Impact modules existants | Impact Workforce | Priorité |
|--------|-------------|----------------------|----------|-------------------|------------------------|-----------------|----------|
| **FieldDefinitionCatalog** | PARTIEL | Ajouter target_entity extensible via FieldValue.model_type polymorphe (Employee, Contract). Étendre SENSITIVE_CODES. | Workforce a besoin de fields sur Employee et Contract, pas seulement User | Duplication — système Fields parallèle | AUCUN — model_type polymorphe déjà supporté | BLOQUANT — sans ça, pas de fields dynamiques Employee | **P0** |
| **FieldResolverService** | PARTIEL | Accepter tout Model (pas seulement User/Company) comme $model. Ajouter paramètre $sensitiveKeys extensible. | Actuellement optimisé pour Company/User. Workforce passe Employee. | Fork du resolver — 2 résolveurs parallèles | AUCUN — signature compatible | BLOQUANT | **P0** |
| **DocumentTypeCatalog** | OUI | Ajouter 7 types Workforce via sync(). Aucun changement de structure. | Workforce a besoin de types documents RH | — | AUCUN | Facile | P1 |
| **DocumentResolverService** | PARTIEL | Supporter model_type Employee (pas seulement User). | Documents employee ≠ documents member/user | Fork du resolver | AUCUN | IMPORTANT | P1 |
| **Document system** | NON | Créer DocumentTemplate + DocumentVariableResolver + PDF generation. Transverse. | Pas de génération de documents actuellement. Upload-only. | Workforce crée son propre système de génération | POSITIF — tous les modules en bénéficient | BLOQUANT Phase 2 | P1 |
| **JobdomainRegistry** | PARTIEL | Ajouter colonne `workforce_presets` JSON sur table `jobdomains`. Platform admin peut modifier. | Workforce a besoin de presets par métier (types contrats, modes planning, etc.) | JobDomain trop rigide — pas de presets Workforce | AUCUN — colonne additionnelle | IMPORTANT | P1 |
| **ModuleManifest** | OUI | Aucune modification. Le système `requires` existant suffit pour les dépendances Workforce. | 6 modules avec dépendances = pattern existant | — | AUCUN | Aucun | — |
| **EntitlementResolver** | OUI | Aucune modification. Gates existantes suffisent. | Type core/addon + minPlan + jobdomain = ok | — | AUCUN | Aucun | — |
| **CompanyRole + Permissions** | OUI | Ajouter 15 permissions + 4 bundles via ModuleManifest. Pattern existant. | Architecture bundle/permission mature | — | AUCUN | Facile | P1 |
| **Market** | NON | Créer `market_rule_sets` (table séparée). Règles versionnées par domain. | Market actuel = info statique (devise, TVA). Pas de règles légales. | Workforce hardcode les règles — pas de multi-pays futur | POSITIF — billing et compliance en bénéficient | BLOQUANT | **P0** |
| **Company Settings** | NON | Créer `company_policies` (CompanyPolicyStore transverse, versionné, domain-scoped). | Pas de système unifié de settings/policies company. | Config éparpillée — impossible à snapshotter pour paie | POSITIF — tous les modules en bénéficient (billing, documents, etc.) | BLOQUANT | **P0** |
| **AuditLogger** | PARTIEL | Ajouter helper `AuditSanitizer::sanitize()` pour masquage automatique. Convention `metadata.category` + `metadata.reason`. | Pas de masquage auto. Pas de catégorisation. Pas de reason obligatoire. | Données sensibles (salaires) dans les logs non masquées | AUCUN — backward compatible | IMPORTANT | P1 |
| **CompanyAuditLog** | OUI | Aucune modification structurelle. Utiliser `metadata` pour category + reason. | Schema ULID append-only déjà optimal | — | AUCUN | Aucun | — |
| **ReadModel pattern** | OUI | Aucune modification. Pattern statique existant suffit. | 25 ReadModels existants prouvent le pattern | — | AUCUN | Aucun | — |
| **PlatformSetting** | OUI | Ajouter `workforce` aux fillable + migration. Pattern JSON singleton extensible. | — | — | AUCUN | Facile | P1 |
| **TagDictionary** | OUI | Ajouter constantes PAYROLL, WORKFORCE, ONBOARDING_HR, OFFBOARDING. | Extension simple d'un dictionnaire de tags | — | AUCUN | Facile | P1 |
| **UseCase/DTO pattern** | OUI | Aucune modification. Pattern Data→execute()→Result mature. | 39 UseCases + 17 DTOs existants | — | AUCUN | Aucun | — |
| **State machine pattern** | OUI | Réutiliser pattern Subscription (boot guards, transition matrix). | Pattern éprouvé dans Subscription | — | AUCUN | Aucun | — |
| **BelongsToCompany trait** | OUI | Aucune modification. Employee l'utilise directement. | Trait autonome, CompanyScope global scope | — | AUCUN | Aucun | — |
| **FeatureFlags (en cours)** | PARTIEL | Intégrer avec modules Workforce pour activer/désactiver features intra-module. | Système en cours d'implémentation. Pas encore lié à ModuleManifest. | Features Workforce non togglables | Dépend de la finalisation | Utile mais non bloquant | P2 |

### Résumé priorités

**P0 (avant tout code Workforce)** :
1. FieldResolverService → accepter Employee comme model (1h de travail)
2. MarketRuleSet → nouvelle table + seed France (1 jour)
3. CompanyPolicyStore (domain='workforce') → nouvelle table (0.5 jour)

**P1 (pendant le développement MVP)** :
4. DocumentTypeCatalog → ajouter types Workforce
5. AuditSanitizer → helper masquage
6. JobDomain workforce_presets → migration colonne
7. PlatformSetting workforce → migration colonne
8. 15 permissions + 4 bundles

**P2 (Phase 2)** :
9. DocumentTemplate + génération PDF (transverse)
10. FeatureFlags intégration
11. SignatureRequest (Phase 3)

---

> **FIN DE L'AUDIT**
>
> Ce document est la source de vérité pour l'architecture Workforce.
> Toute déviation doit être documentée dans un ADR.
> BMAD : Business ✓ → Domain ✓ → Architecture ✓ → Decisions (à écrire) → UI (à auditer) → Implementation (à planifier)
