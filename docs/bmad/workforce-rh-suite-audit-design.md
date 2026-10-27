# Workforce → Suite « Ressources Humaines » — Audit, Architecture cible & Plan d'évolution

> **Nature : AUDIT + CONCEPTION + PLAN. Aucune implémentation.** Aucune migration, aucune donnée RH réelle, aucune permission opérationnelle, aucun module activé, aucun déploiement PROD/main dans le cadre de ce dossier.
> Convention : *« existant »* = code réel cité (fichier\:ligne). *« proposé »* = conception à exécuter après GO. Code/clés en anglais, prose en français.

---

## 0. Synthèse exécutive & arbitrage stratégique (à trancher AVANT tout lot)

### 0.1. Ce que l'audit démontre
Le backend Workforce est **beaucoup plus mûr que l'UI ne le laisse paraître**. Existent déjà et sont production-grade :
- **Entités métier complètes** : `Employee`, `EmploymentContract` + `CompensationPlan` (machine à états, intégrité ANO-016/ADR-578), `Department` (auto-imbriqué) + `JobRole` + `manager_id`, `Shift`/`ScheduleTemplate`/`WorkLocation`, `TimeEntry`/`TimesheetPeriod`/`TimesheetLine`, `LeaveType`/`LeaveRequest`/`LeaveLedgerEntry` (grand livre immuable), `PayrollRun`/`PayrollLine`/`PayrollCalculation`/`PayrollYtdSnapshot` (moteur de calcul complet), `DsnDeclaration` (+ sous-système NetEntreprises).
- **Moteur normatif multi-dimensionnel** (ADR-576) : `WorkforceRuleResolver` à 4 couches (market_law → convention → **jobdomain (vide)** → company), validations planning serveur (ADR-577).
- **Moteur documentaire** : `DocumentTemplate` (HTML versionné, **surcharge Company via `platform_template_id`**, `variables_schema`, `subject_type` ∈ {employee, **contract**, leave_request, payroll_run, payroll_line}), `DocumentVariableResolver` (**résout déjà `contract.*`/`compensation.*`**), `GenerateDocumentUseCase` → `DocumentPdfGenerator` (DomPDF offline), `GeneratedDocument` (immuable, **colonnes `signature_*` déjà présentes**).
- **Moteur de scaffolding sectoriel EN PRODUCTION** : `JobdomainGate::assignToCompany()` active modules/fields/documents/**rôles**/dashboards + snapshot ; `JobdomainPresetResolver::resolve(jobdomain, market)` + `JobdomainMarketOverlay` = moteur de résolution **Market × JobDomain** ; `PresetReconciler` + `CompanyPresetSnapshot` = dérive/réparation non destructive.

**Le déficit réel n'est donc PAS « pas de backend » mais :** (1) **UX/assemblage** (fiche collaborateur fragmentée 4/10, config RH éparpillée, saisie par ID numérique, nav sous un groupe générique « Entreprise ») ; (2) **génération/édition documentaire non câblée** (templates CRUD + éditeur WYSIWYG absents côté UI ; aucun template de contrat seedé) ; (3) **signature = stub de schéma** (aucun workflow, aucun prestataire, aucun eIDAS) ; (4) **presets d'organisation absents** (hook existe, rien ne seede l'org) ; (5) **RBAC trop grossier** (pas de scope manager/établissement, salaire non protégé finement) ; (6) **pas d'événements/notifications workforce**.

### 0.2. Tension stratégique — DÉCISION #1 (comité requis)
**ADR-561 + ADR-566.1 interdisent explicitement d'« étendre » Workforce** (nouvelles fonctionnalités majeures, nouveaux sous-modules) **tant que le socle horizontal (ADR-563) et le 2ᵉ vertical (ADR-564) ne sont pas livrés.** Seule la **stabilisation** (P0/P1, UX, automatisations, correctifs) est autorisée. ADR-566.7 (règle de proportion) interdit qu'un module ré-aspire tout l'effort sans décision explicite de comité.

La demande (suite RH complète + Workforce Contract + Workforce Signature) est, telle quelle, une **extension majeure → en conflit frontal avec la doctrine.** Reclassement proposé pour la rendre **doctrine-compatible** (à valider) :

| Chantier demandé | Reclassement proposé | Doctrine |
|---|---|---|
| Nav « Ressources humaines », fiche collaborateur unifiée, tableaux de bord, refonte UX, pickers | **Stabilisation Workforce** (UX/finition) | ✅ Autorisé (ADR-561.2) |
| **Workforce Signature** (réutilisable « pas seulement pour les contrats ») | **Service HORIZONTAL `signature`** (consommable par invoicing, documents, verticales…) | ✅ Socle horizontal (ADR-563 esprit) |
| **Éditeur de templates + dépôt documentaire** | **Capacité HORIZONTALE** (`Core/Documents` existe déjà) | ✅ Core/composition |
| **Presets d'organisation (Market × JobDomain × type)** | **Infrastructure de composition/onboarding** (cœur de valeur reconnu ADR-561.3) | ✅ Composition |
| **Workforce Contract** (assemblage génération + cycle) | **Fine couche RH** sur le socle horizontal (templates+signature+documents) | ⚠️ Borderline → arbitrage |
| Paie avancée, DSN élargie, conventions étendues | **Reporté** (extension profonde HR, FR-spécifique) | ⛔ Différé (ADR-561.5) |

**Recommandation** : approuver le programme **en tant que (a) stabilisation Workforce + (b) construction de capacités horizontales** (signature, documents/templates, org-presets) qui servent *aussi* le futur `invoicing` et les verticales — ce qui **sert** la doctrine au lieu de la violer. Acter que la **paie/DSN profonde reste gelée**. **Opportunité à saisir** : ce programme est le bon moment pour **remédier la violation P1 `app/Core/Workforce/` + `if('FR')`** (voir §4.4, déjà au plan de remédiation `docs/audit-2026-10/06-plan-remediation.md:52`).

### 0.3. Verdict
GO **conditionné** à : (1) décision de comité sur 0.2 ; (2) séquencement **largeur-compatible** (les capacités horizontales d'abord, l'assemblage RH ensuite) ; (3) arbitrage des décisions métier §22 (avenants, buckets congés, établissements, niveau de signature, prestataire). **Aucun lot ne démarre sans GO explicite.**

---

## 1. Inventaire réel des modules Workforce

5 classes de module, toutes `scope:'company'`, `type:'addon'`, `surface:'hr'`, implémentant `ModuleDefinition::manifest()`. minPlan/addon_pricing en DB (`ModuleProductConfigSeeder`), pas au manifeste.

| key | Nom commercial (override) | Classe | Resp. fonctionnelle | Permissions | Dépend. | Tarif | Maturité |
|---|---|---|---|---|---|---|---|
| `workforce` | RH & Effectifs | `Employees/WorkforceModule.php` | Employés, contrats, org (dept/job-role), temps/timesheets, config (pauses/temps de travail), self-service `/me` | view, manage, contracts, compensation_read/manage*, sensitive_read*, time_manage/approve, timesheet_view/submit/approve, admin | — | per_seat (employees) 5/15/50 | **6/10** (back mûr, UI fragmentée) |
| `workforce_planning` | Planning & Pointage | `WorkforcePlanningModule.php` | Shifts hebdo, templates, lieux, publication, bulk | planning_view/manage/publish | workforce | flat 29 € | **7/10** |
| `workforce_leave` | Congés & Absences | `WorkforceLeaveModule.php` | Types, demandes, calendrier, soldes (ledger), config | leave_request/approve/view_all*/manage/adjust* | workforce | flat 19 € | **6/10** |
| `workforce_payroll` | Paie & Déclarations | `Payroll/WorkforcePayrollModule.php` | Runs paie, calculs, YTD, export, **DSN** | payroll_prepare/validate/export | workforce, workforce_leave | per_seat 5/15/50 | **7/10** (FR-only, gelé) |
| `workforce_documents` | Documents Employés | `Documents/WorkforceDocumentsModule.php` | Génération docs (bulletins), liste, stats | documents.view/generate/**manage_templates\*/sign\*** | workforce | flat 9 € | **5/10** (UI « dev ») |

`*` = permission **déclarée mais sans route** (morte) → crochets prêts pour les nouveaux chantiers. Détails nav/routes/modèles : §2, §17, §18.

**Classement global** : `planning`, `payroll`, `time`, `me` = *fonctionnel mais insuffisant (UX)* ; `employees/[id]`, `documents`, `configuration` = *à refondre / mal intégré* ; **Contract (génération/PDF), Signature (workflow), Org-presets, Nav RH, Établissements = à créer**.

---

## 2. Audit UX & fonctionnel — page par page

Hub-and-spoke (`index.vue` → 6 pages sœurs). Pattern Vuexy : `VDataTableServer` + drawer `end` + dialog + tabs `[tab].vue` + `v-can`. Scores et classes (agent UX) :

| Page (`resources/js/pages/company/workforce/…`) | /10 | Constat | Classe |
|---|---|---|---|
| `index.vue` (hub) | 7 | KPIs + cartes modules ; quick-actions = simples navigations, bandeau partiel | fonctionnel-bon |
| `me.vue` (self-service) | 8 | Pointage/pauses poli, vue perso consolidée ; form congé **sans validation**, bulletins non téléchargeables | fonctionnel-bon |
| `configuration.vue` | 6 | Cockpit règles (pauses/temps) élégant mais **2 domaines seulement**, save par ligne ; config RH éparpillée ailleurs | partiel |
| `employees/index.vue` | 6 | Table serveur OK ; **pas de bulk/import-export**, colonnes pauvres (ni dept/manager) | fonctionnel-insuffisant |
| **`employees/[id].vue`** | **4** | **Fiche = seulement onglets Profil + Contrat.** Aucune agrégation temps/congés/planning/paie/documents | **à refondre** |
| `employees/_EmployeeProfile.vue` | 8 | Édition inline propre, dropdowns, RBAC | fonctionnel-bon |
| `employees/_EmployeeContract.vue` | 7 | Cycle contrat (création, **activation brouillon**, historique) ; **saisie en centimes**, pas d'édition/rupture | fonctionnel-bon |
| `organization/[tab].vue` | 6 | CRUD dept/job-role + stats ; **pas d'organigramme**, **suppression sans confirmation**, centimes | partiel |
| `planning/index.vue` (~2075 l.) | 7 | Grille riche + **bulk create + batch publish** ; table non virtualisée (scale), semaine-only, dates fragiles | fonctionnel-bon |
| `time/index.vue` + `[id].vue` | 7–8 | Workflow entrées+timesheets + anomalies ; actions denses, pas de bulk-approve/export | fonctionnel-bon |
| `leave/index.vue` | 6 | 4-en-1 large ; **« calendrier » = liste**, search ignoré par le store, durée en centièmes, config mêlée | partiel |
| `documents/index.vue` + drawers | 5 | Génération via **code template libre + IDs numériques** (UX « dev ») ; `:href="item.file_path"` **non protégé** | fonctionnel-insuffisant |
| `payroll/index.vue` + `[id].vue` | 7–8 | Workflow compute→export profond, par-ligne ; très « machine à états », pas de bulk | fonctionnel-bon |
| `dsn/index.vue` + `[id].vue` | 6–8 | Traçabilité gateway/audit excellente ; export par run-ID tapé à la main | partiel/bon |

**Problèmes transversaux** : (1) **pas de fiche collaborateur unifiée** (naviguer 5–6 modules par salarié) ; (2) **config RH fragmentée** (≥4 endroits) ; (3) **saisie par ID** au lieu de sélecteurs (documents/DSN/paie) ; (4) **centimes bruts** dans plusieurs formulaires ; (5) **pas d'import/export ni bulk** (sauf planning) ; (6) **onboarding faible** (empty-states à CTA mais pas de first-run guidé) ; (7) confirmations de suppression incohérentes ; (8) grille planning non responsive/virtualisée.

---

## 3. Faiblesses principales & causes techniques

| # | Faiblesse | Cause technique (fichier) | Gravité |
|---|---|---|---|
| F1 | Nav RH sous « Entreprise » générique | `NavBuilder::resolveGroup()` ne mappe pas `surface:'hr'→'hr'` ; aucun navItem ne pose `group:'hr'` ; groupe `hr` (« RH ») **enregistré mais mort** | P1 UX |
| F2 | Fiche collaborateur fragmentée | `employees/[id].vue` n'agrège que Profil+Contrat ; les autres données scoppées par `employee_id` dans des modules séparés | P0 UX |
| F3 | Salaire/données sensibles non protégés finement | `compensation_read/manage`, `sensitive_read` **déclarées, 0 route** ; `ContractController`/`EmployeeReadModel` exposent `compensationPlans` sous `workforce.view` | **P0 sécurité** |
| F4 | Pas de scope manager/équipe/établissement | `manager_id` jamais utilisé en filtre ; `leave_view_all` jamais vérifié ; tout détenteur employé lit via `employee_id` arbitraire | **P0 sécurité** |
| F5 | Génération doc non consommable | templates CRUD/éditeur absents ; `GenerateDocumentUseCase` piloté par code+ID bruts ; aucun template de contrat | P1 fonctionnel |
| F6 | Signature inexistante (stub) | colonnes `signature_*` + `UpdateSignatureStatusUseCase` **jamais appelés** ; pas de provider/eIDAS/page | P1 fonctionnel |
| F7 | Org pauvre + pas de presets | seulement `Department`+`JobRole` ; pas d'établissement/service/équipe/poste ; `jobdomains.workforce_presets` **stub inutilisé** ; Layer 3 jobdomain **vide** | P1 valeur |
| F8 | Téléchargement généré non sécurisé | `documents/index.vue` → `file_path` brut au lieu d'une route auth-gated (contraste : coffre `Core/Documents` fait bien, ADR-401) | **P0 sécurité** |
| F9 | Pas d'événements/notifications workforce | usecases synchrones ; aucun `Event`/`Listener`/`notify()` workforce → workflows transverses impossibles sans refactor | P1 architecture |
| F10 | Violation doctrine P1 | `app/Core/Workforce/` existe + `if($marketKey==='FR')` (`PayrollCalculationEngine.php:324`, défauts DSN) — contraire ADR-562/566.8 | **P1 dette** |

---

## 4. Architecture cible — Suite RH

### 4.1. Principe directeur
Une **suite RH perçue comme un seul logiciel**, assemblée par modules indépendants communiquant par **capacités déclarées + événements**, avec **UX adaptative** (Market × JobDomain × type × taille × modules × permissions × personnalisations). On **réutilise** les abstractions existantes ; on **n'invente pas** de second design-system (politique UI Vuexy) ni de second moteur de résolution.

### 4.2. Couches cibles
```
UX RH (Vue) : nav « Ressources humaines » + fiche unifiée + dashboards différenciés par rôle
   ↑ consomme read-models composites
Fine couche RH : Workforce Contract (assemblage), paramètres RH consolidés
   ↑ compose
Capacités HORIZONTALES (nouvelles/étendues) :
   • signature (service transverse, provider-abstrait)        [NOUVEAU — horizontal]
   • documents/templates (éditeur + dépôt)   [Core/Documents EXISTE — à câbler/étendre]
   • org-presets (Market × JobDomain × type → org)  [extension JobdomainGate — EXISTE à 90%]
   ↑ réutilisent
Core existant : Notifications, Audit, Fields, Realtime, RBAC, MarketRuleSet/Convention,
                WorkforceRuleResolver, PDF (DomPDF), stockage privé auth-gated (ADR-401)
```

### 4.3. Règles d'intégration
- **Capacités, pas dépendances dures** : chaque module expose des *capabilities* (déjà au manifeste) ; Contract dépend de Signature **optionnellement** (si Signature inactif, Contract génère+archive sans signature électronique — exigence §8).
- **Pas de cycle** : Documents/Signature ne connaissent pas Workforce ; Workforce consomme Documents/Signature via interfaces + events.
- **Événements métier** (nouveau, §16) : `EmployeeHired`, `ContractGenerated`, `ContractActivated`, `DocumentGenerated`, `SignatureRequested/Completed/Declined` → listeners (notifications, archivage, mises à jour).

### 4.4. Remédiation doctrine (opportunité, à cadrer avec le comité)
Profiter du programme pour : sortir le métier de `app/Core/Workforce/` vers `app/Modules/Workforce/` (ou un namespace module) ; remplacer les `if('FR')` par `MarketRuleSet`/overlays ; garder les capacités **signature/documents/org-presets horizontales** hors de tout `Core/{Vertical}`.

---

## 5. Navigation dynamique « Ressources humaines »

**Existant** : pipeline 100 % manifest-driven (`NavBuilder` → groupes via `NavGroupRegistry` → pruning des groupes vides → i18n `nav.company.{key}` / `nav.groups.{key}`). Le groupe **`hr` « RH » existe (ordre 50) mais aucun item ne s'y route**.

**Proposé (pur déclaratif, zéro Vue en dur)** :
1. Renommer `nav.groups.hr` → **« Ressources humaines »** (fr/en/ar).
2. Router les items : soit `group:'hr'` dans chaque navItem des 5 manifestes, soit — plus DRY — étendre `NavBuilder::resolveGroup()` pour mapper `surface==='hr' → 'hr'` (tout futur module `surface:'hr'` rejoint automatiquement).
3. Structure cible (ajustée aux modules réels), ordonnée par `sort` :
   - **Vue d'ensemble RH** (`workforce-overview`, nouveau) · **Organisation** (parent → Organigramme, Établissements\*, Départements, Services/Équipes\*, Postes/Fonctions\*) · **Collaborateurs** · **Planning & pointage** · **Congés & absences** · **Contrats de travail** (nouveau) · **Signatures** (nouveau, si actif) · **Documents RH** · **Demandes & validations** (agrégateur, nouveau) · **Paramètres RH** (hub consolidé, nouveau).
   (`*` = dépend des décisions §22 établissement/service/équipe.)
4. **Sous-navigation par parent** (déjà supporté : `parent` key + `buildTree`). Les entrées sous-abonnées/non permises disparaissent ; le groupe n'apparaît que si ≥1 entrée accessible (déjà géré par `pruneGroups` + `orphanHeadingFilter`).
5. **RBAC différencié** : les items portent déjà `permission`/`anyPermission`/`excludePermission` → admin/RH/manager/collaborateur voient des sous-ensembles distincts (§11, §17).
6. **Distinguer Paramètres généraux vs RH** : « Paramètres RH » = nouveau hub (settingsRoute) regroupant pauses/temps + types de congés + org + templates planning + **paramètres signature** ; distinct de `Core/Settings` (groupe `account`).

**Critère d'acceptation** : `PageLayoutMetaTest` + un nouveau test « nav RH manifest-driven » (le groupe RH contient exactement les items des manifestes actifs, se cache si vide, respecte les permissions). Aucune liste statique côté Vue.

---

## 6. Système de presets organisationnels (versionnés)

**Existant réutilisable (~90 %)** : `JobdomainGate::assignToCompany()` (seam transactionnel seedant déjà rôles/fields/documents/dashboards + `CompanyPresetSnapshot`), `JobdomainPresetResolver::resolve(jobdomain, market)` → `ResolvedPresets` (moteur de merge **Market × JobDomain**), `JobdomainMarketOverlay` (override/remove par (jobdomain, market)), `PresetReconciler` (dérive/réparation **non destructive** aux rôles custom), tables cibles `Department`(auto-imbriqué)/`JobRole` déjà company-scoped, slot `WorkforceRuleResolver` Layer 3 `jobdomain_preset` (vide).

**Proposé (extension additive)** :
1. **Payload** : ajouter aux définitions jobdomain `default_departments`, `default_job_roles`, `default_reporting_lines` (et, si décidé, `default_services/teams/positions/establishment_types`), s'appuyant sur les `archetypes` existants (field_worker/operations_center/management).
2. **Overlay** : colonnes `override_departments/job_roles` + `remove_*` sur `jobdomain_market_overlays` (miroir du pattern existant).
3. **Résolution** : étendre `ResolvedPresets` + `JobdomainPresetResolver` (mêmes règles merge-by-key / mandatory-guards).
4. **Discriminant « type d'entreprise »** (la **seule** vraie nouveauté) : ajouter `company_type`/`org_profile_key` (ou dériver de `naf_code` + bandes `average_headcount`) et passer `(jobdomain, market, companyType)` au resolver.
5. **Application** : étendre `assignToCompany()` pour seeder `Department`/`JobRole` depuis `ResolvedPresets` — **idempotent**, **jamais écraser** une org personnalisée (réutiliser la logique `PresetReconciler` : dry-run + non-destructif + snapshot).
6. **Versionnement/provenance** : chaque row org porte `source` (`jobdomain_preset`/`market_overlay`/`company_manual`) + version du preset (via `CompanyPresetSnapshot`). Réinitialisation possible d'une proposition sans perte des données company.

**Flux** : Market + JobDomain + type + caractéristiques → proposition → **prévisualisation** → validation Company → création → personnalisation ultérieure (renommer/ajouter/supprimer/fusionner/réorganiser/déplacer collaborateurs). **Aucune modification d'un preset Platform n'écrase une org Company personnalisée.**

**Garde-fous** : voie **`JobdomainMarketOverlay`** pour les différences pays (jamais `if('FR')`). Système pleinement utile seulement quand ≥2 jobdomains déclarés (aujourd'hui `logistique` seul) → livrer le mécanisme + 1 jobdomain de preuve.

---

## 7. Adaptation Market × JobDomain × type d'entreprise

**Déjà modélisé** : couche pays via `MarketRuleSet` (domain=`workforce`, effective-dated) + `ConventionCollective`/`Rule` (IDCC) ; résolution par `WorkforceRuleResolver` (4 couches). **Manquant** : la couche **jobdomain** (presets org + défauts RH sectoriels) — à remplir (§6). **Type d'entreprise** : nouveau discriminant (§6.4).

| Dimension | Source existante | Extension proposée |
|---|---|---|
| Pays/juridiction/fiscalité/social | `Market` + `MarketRuleSet` + `ConventionCollective` + `LegalStatus` | aucune (réutiliser) ; **retirer `if('FR')`** du Core |
| Langue/locale/devise | `Market.locale/currency` + `world.js` + `useMarketFormatting` | aucune |
| Secteur | `Jobdomain` (default_modules/fields/documents/roles/archetypes) | + `default_departments/job_roles` (presets org) + Layer 3 RH |
| Type d'entreprise | — (seuls `naf_code`, `legal_status_key`, `average_headcount`) | **`company_type`/`org_profile_key`** (3ᵉ discriminant) |
| Taille/établissements | `average_headcount` ; **pas d'`Establishment`** | décision §22 (établissements) |
| Modules abonnés | `EntitlementResolver` (jobdomain-driven) | aucune |
| Permissions | `PermissionCatalogBuilder` + bundles | + scopes manager/établissement (§17) |
| Personnalisations Company | `company_policies`, overrides, rôles custom | presets org non destructifs (§6) |

---

## 8. Onboarding RH (configuration progressive)

**Existant** : `JobdomainGate::assignToCompany` orchestré au rattachement (cœur de valeur ADR-561.3) ; `onboarding_dismissed_at` sur Company ; empty-states à CTA.

**Proposé — first-run RH guidé (wizard), sans config exhaustive préalable** :
1. Leezr lit `market_key` + `jobdomain_key` (+ type si décidé). 2. Propose une **organisation minimale** (presets §6). 3. **Prévisualisation** (organigramme proposé + rôles + types de congés market-seedés). 4. Confirme/modifie. 5. Les modules actifs initialisent leurs paramètres pertinents (règles pauses/temps déjà résolues par le moteur normatif). 6. Utilisation immédiate (ajouter 1 collaborateur sans tout configurer). 7. Personnalisation progressive.

**Priorité d'override** (à documenter en ADR) : Platform defaults < Market presets < JobDomain presets < **Company config** < préférences individuelles. Le moteur `WorkforceRuleResolver` + `JobdomainPresetResolver` portent déjà cette sémantique de couches.

**Parcours** : `Wizard onboarding RH` (nouveau composant, assemblé de presets Vuexy — stepper existant) → étape Organisation (preview+edit) → étape Rôles → étape Types de congés → étape Paramètres (pauses/temps) → terminé.

---

## 9. Fiche collaborateur unifiée (point central — priorité P0)

**Existant** : `employees/[id].vue` = header + onglets **Profil + Contrat** seulement ; toutes les autres données existent mais scoppées par `employee_id` dans des endpoints séparés.

**Proposé** : fiche à **onglets conditionnés par modules actifs + permissions**, alimentée par un **read-model composite** (nouveau `EmployeeFileReadModel` agrégeant, par `employee_id`, les lectures existantes — aucune nouvelle table) :
- **Identité/coordonnées** (existant `_EmployeeProfile`) · **Poste/Département/Responsable/Établissement** · **Historique des affectations** (nouveau, dérivé contrats + org) · **Planning** (shifts de l'employé) · **Pointages/Timesheets** · **Congés** (demandes + soldes) · **Contrats** (existant + **génération**) · **Documents** (coffre + générés) · **Signatures** (demandes liées) · **Demandes** (agrégat) · **Historique d'événements autorisés** (audit filtré).
- **RBAC par onglet/section** : salaire/contrat/documents sensibles masqués sans permission fine (§17, modèle `FieldResolverService` de Core Members).
- **Actions rapides contextuelles** : générer un contrat, demander une signature, poser un congé, corriger un pointage — sans quitter la fiche (drawers).

**Critère d'acceptation** : un responsable RH gère un collaborateur de bout en bout depuis **une** page ; chaque onglet n'apparaît que si module actif + permission ; aucun recalcul côté Vue ; tenant/scope respectés.

---

## 10. Architecture **Workforce Contract** (fine couche sur socle horizontal)

**Existant** : `EmploymentContract`+`CompensationPlan`+`ConventionCollective` (données + intégrité ANO-016/ADR-578), `Create/ActivateContractUseCase`, `ContractController` (routes 466-471), `_EmployeeContract.vue`. `DocumentTemplate` (subject `contract`) + `DocumentVariableResolver` (`contract.*`/`compensation.*`) + `GenerateDocumentUseCase` + PDF. **Manquant** : templates de contrat seedés, **génération de contrat câblée depuis l'UI contrat**, cycle document (prêt-à-signer…), avenants/renouvellements, alertes d'échéance, téléchargement protégé.

**Proposé** :
- **Bibliothèque de modèles** : templates `subject_type=contract` seedés **par Market/JobDomain/type/type-de-contrat/date d'effet** (CDI/CDD/temps partiel/apprentissage/autres selon juridiction — catégories **issues du Market**, jamais liste FR imposée). Réutilise `DocumentTemplate` + overlay market.
- **Génération** : parcours Collaborateur → Nouveau contrat → type → modèle recommandé → préremplissage (variables résolues) → compléments → **prévisualisation PDF** → validation RH → génération (`GenerateDocumentUseCase` `subject_type=contract`) → signature éventuelle (Signature) → archivage (coffre). États document : brouillon/validé/généré/prêt-à-signer/envoyé/signé/refusé/expiré/annulé/archivé (étend la machine `GeneratedDocument.signature_status`).
- **Avenants/renouvellements/versions/annexes** : **DÉCISION #2 (ADR-578 explicitement différé)** — avenant = nouvelle version du contrat courant (recommandé) vs nouveau contrat ; tant que non tranché, pas de création chevauchante. Alertes d'échéance = via scheduler + events (§16).
- **Sécurité juridique** : versionnement par juridiction + date d'effet (déjà dans `DocumentTemplate.version` + à enrichir `jurisdiction`/`legal_status`/`reference`/`market_restrictions`) ; **jamais prétendre la conformité automatique** (avertissement obligatoire si non vérifiée). Une modification de modèle **ne change jamais** un contrat déjà généré/signé (immutabilité `GeneratedDocument` + `generation_snapshot` — déjà garantie).

---

## 11. Éditeur de modèles Company (WYSIWYG sécurisé)

**Existant** : `DocumentTemplate` (versionné, surcharge Company via `platform_template_id`), `Create/UpdateDocumentTemplateUseCase` (transactionnel + validation variables), `TemplateVariableSchemaReadModel`, parser strict `{{ var.name }}` (**jamais Blade**), `TiptapEditor.vue` (@core, utilisé email-only). Permission **`workforce_documents.manage_templates` déclarée (morte)**.

**Proposé** :
- **API/route/UI** exposant le CRUD templates (nouveau `DocumentTemplateController` + routes gardées par `manage_templates`).
- **Éditeur** = `TiptapEditor` réutilisé + **palette d'insertion de merge-fields contrôlés** (liste depuis `TemplateVariableSchemaReadModel` : `employee.*`, `employer.*`, `establishment.*`, `position.*`, `department.*`, `salary.*`, dates, période d'essai, temps de travail, lieu). Dupliquer un modèle catalogue → version Company ; éditer textes/clauses/sections/mise en page ; logo + infos entreprise ; brouillon → **prévisualisation PDF fidèle** → publier version → archiver ancienne ; défaut par type ; restreindre les droits de modification.
- **Sécurité** : **aucune exécution de code arbitraire** (le parser regex actuel l'interdit déjà ; HTML échappé dans `DocumentPdfGenerator`). Les templates Platform **restent inchangés** quand une Company personnalise (la Company travaille sur une copie/override — déjà modélisé).

---

## 12. Architecture **Workforce Signature** (service HORIZONTAL)

**Existant** : **stub de schéma uniquement** — `GeneratedDocument.signature_status/provider/metadata/signed_at` + `UpdateSignatureStatusUseCase` (jamais appelés), permission **`workforce_documents.sign` morte**. Stack email/notifications réutilisable (`NotificationDispatcher`, `EmailService`). **Aucun** provider/eIDAS/page de signature.

**Proposé (réutilisable pour tout document, pas seulement contrats)** :
- **Modèle** : promouvoir en entité dédiée `SignatureRequest` + `SignatureSigner` (ordre, rôle, statut, preuves) liées polymorphiquement à un document (generated ou uploadé) — plutôt que colonnes seules. Conserver `GeneratedDocument.signature_status` en reflet.
- **Workflow** : sélectionner document → signataires + rôles + **ordre** → envoi sécurisé → suivi → relance → refus → expiration → preuves → téléchargement signé → **archivage auto** (coffre). Interface de **supervision des signatures en attente**.
- **Prestataires** : **interface abstraite `SignatureProvider`** (miroir du pattern `PaymentProviderAdapter` existant) + adaptateurs (Null/manual, puis un prestataire eIDAS) + **webhooks**. **Jamais** de dépendance structurelle à un seul prestataire. Comparatif à produire (fonctionnalités, markets couverts, niveaux, API/webhooks, coûts, conservation des preuves, réversibilité, résidence des données).
- **Valeur probante** : niveaux simple/avancé/qualifié ; identification signataires ; horodatage ; intégrité (hash `payload_hash` déjà présent sur docs) ; **journal d'audit** (Core Audit) ; dossier de preuve ; vérification ; archivage. **Ne jamais** présenter un simple dessin écran comme signature juridiquement garantie. **eIDAS** pour l'UE ; **régimes locaux** à identifier par Market (ex. Maroc — **à qualifier, aucun market MA n'existe aujourd'hui**).
- **Distinguer** : invitation à signer ≠ confirmation de signature ≠ envoi de copie ≠ mise à disposition espace collaborateur ≠ téléchargement manuel ≠ **simple accusé de lecture** (feature séparée si besoin, **n'est pas** une signature).
- **Import hors plateforme** : un document signé hors Leezr, importé, porte une **provenance distincte** d'une signature réalisée+vérifiée par le module.

---

## 13. Configuration des envois de copies signées (Paramètres RH → Signatures)

**Proposé** (exigence impérative) :
- `send_signed_copy_to_employee` (oui/non) · canal (email / espace collaborateur / autre autorisé) · modèle de message · notification RH · notification autres signataires selon droits · relances auto · expiration des demandes · politique de téléchargement · **journal des envois**.
- **Désactiver l'envoi auto ≠ supprimer le droit du salarié** à obtenir un document auquel il doit légalement avoir accès → la **mise à disposition** dans l'espace collaborateur reste garantie même si l'email auto est off (à vérifier par Market + exigences prestataire + confidentialité).

---

## 14. **Workforce Documents** — référentiel documentaire RH

**Existant** : deux surfaces — `workforce_documents` (génération/traçage, UI « dev ») **et** le **coffre mûr `Core/Documents`** (`CompanyDocument`/`MemberDocument`, catégories, expiration, visibilité, OCR+IA, conformité+CSV, demandes, auto-renouvellement, **téléchargement auth-gated ADR-401/174/169**). `GenerateDocumentUseCase` crée déjà un `MemberDocument` pour les sujets employé/contrat.

**Proposé** :
- **Unifier** la vue : le dépôt RH centralise docs importés + contrats générés + contrats signés + avenants + administratifs + attestations + pièces remises + expirables + versions, en **réutilisant `Core/Documents`** (ne pas réinventer).
- Classement/catégories/expiration/visibilité/permissions/historique/recherche/téléchargement/confidentialité/**rétention par Market**.
- **Corriger F8** : servir **tout** document (généré inclus) via route authentifiée (pattern `CompanyDocumentController::download`), jamais `file_path` brut. Docs signés **protégés contre modification silencieuse** (immutabilité `GeneratedDocument` déjà en place). **Accès aux dossiers RH sensibles plus restrictif** qu'un accès Company général (§17).

---

## 15. Workflows transversaux (events-driven)

**Existant** : usecases synchrones, **aucun event workforce** (F9). **Proposé** : bus d'événements métier + listeners (réutilise Core Notifications/Audit).

- **Embauche** : `CreateEmployee` → affectation org → poste → **génération contrat** (`ContractGenerated`) → validation → **signature** (`SignatureRequested`→`SignatureCompleted`) → archivage → activation workflows RH pertinents.
- **Avenant** : changement de poste → proposition d'avenant → validation → signature → **mise à jour contractuelle à la date effective** → archivage (dépend décision §22 avenant).
- **Document RH** : import → affectation collaborateur → demande de signature si nécessaire → suivi → archivage.
- **Capacités déclarées** : chaque module publie ses capacités (déjà au manifeste) ; contrats stables ; **pas de cycle** ; **Signature optionnelle** (Contract fonctionne sans). Provenance « signé sur plateforme » vs « importé signé » distincte.

---

## 16. Matrice RBAC (cible)

**Existant** (agent RBAC) : owner bypass tout ; permissions `is_admin` réservées aux rôles administratifs ; self-service `/me` sans permission (module + employee record) ; **aucun scope manager/établissement** ; **salaire sous `workforce.view`** ; pas de rôle préset `hr`. Modèle à suivre : **Core Members** masque les champs sensibles via `members.sensitive_read` + `FieldResolverService`.

**Proposé — matrice (rôle × capacité)**, à seeder comme **bundles/rôles presets** (jobdomain `default_roles`) :

| Capacité | Admin Company | Responsable RH | Manager | Collaborateur |
|---|---|---|---|---|
| Config org / presets / accès | ✅ | partiel | — | — |
| Voir collaborateurs | ✅ | ✅ (tous) | ✅ (**ses rapports**) | soi (`/me`) |
| Modifier collaborateur | ✅ | ✅ | limité | soi (limité) |
| **Voir salaire/compensation** | ✅ (`compensation_read`) | ✅ | ❌ | soi |
| **Modifier salaire** | ✅ (`compensation_manage`) | ✅ | ❌ | ❌ |
| **Données sensibles (SSN/IBAN)** | ✅ (`sensitive_read`) | ✅ | ❌ | soi |
| Contrats (générer/activer) | ✅ | ✅ | ❌ | voir les siens |
| **Templates** (`manage_templates`) | ✅ | ✅ | ❌ | ❌ |
| **Signature** (`sign`/demander) | ✅ | ✅ | selon droits | signer (siens) |
| Planning | ✅ | ✅ | ✅ (équipe) | voir le sien |
| Congés : demander / approuver | ✅/✅ | ✅/✅ | demander/✅(équipe) | demander |
| Temps : gérer / approuver | ✅/✅ | ✅/✅ | selon droits/✅(équipe) | pointer |
| Paie / DSN | ✅ | ✅ | ❌ | voir bulletins |
| Documents sensibles | ✅ | ✅ | restreint | les siens |

**Changements requis** : (1) **activer réellement** `compensation_read/manage` + `sensitive_read` sur les routes exposant ces champs + masquage read-model (modèle Members) ; (2) **scope manager** (`manager_id` → filtre « ses rapports ») et, si décidé, **scope établissement** ; (3) **rôles presets RH** (`hr_manager`, `manager`, `employee`) dans un jobdomain ; (4) vérifier enfin `leave_view_all` ; (5) **split DSN** (submit vs view) ; (6) Platform : **aucun accès HR aujourd'hui** — tout futur accès support doit être permission-gated + audité + jamais superadmin-implicite. Révocation d'accès + audit via Core RBAC/Audit.

---

## 17. Modèle de données & APIs proposés (additif)

**Réutilisés tels quels** : tous les modèles du §(data-model) ; `DocumentTemplate`/`GeneratedDocument`/`DocumentVariableResolver`/`DocumentPdfGenerator` ; `JobdomainGate`/`JobdomainPresetResolver`/`JobdomainMarketOverlay`/`ResolvedPresets`/`PresetReconciler` ; `MarketRuleSet`/`ConventionCollective` ; Core Documents (coffre) ; Notifications/Audit/Fields.

**Nouvelles tables/colonnes proposées (additives, non destructives)** :
- Presets org : `jobdomains.default_departments/default_job_roles/default_reporting_lines` (JSON) ; `jobdomain_market_overlays.override_/remove_departments/job_roles` ; `companies.company_type` (ou `org_profile_key`) ; `workforce_departments.source/preset_version` + `workforce_job_roles.source/preset_version`.
- Org enrichie (**selon décisions §22**) : `workforce_establishments`, `workforce_services`/`teams`, `workforce_positions` (si on dépasse Department+JobRole).
- Signature : `signature_requests`, `signature_signers` (+ events/webhooks) ; `generated_documents`/`company_documents` reliés.
- Contrat doc : enrichir `document_templates` (`jurisdiction`, `contract_type`, `effective_from`, `legal_validation_status`, `reference`, `market_restrictions`).

**Nouvelles APIs (gardées par permissions/§16)** :
- `GET /workforce/employees/{id}/file` (read-model composite) · `GET /workforce/overview` (dashboard RH) · templates CRUD `/workforce/document-templates*` (`manage_templates`) · génération contrat `/workforce/employees/{id}/contracts/{cid}/generate` · signatures `/signatures*` (demander/suivre/relancer/preuves/webhooks, `sign`) · presets org `/workforce/organization/preview` + `/apply` + `/reset` · paramètres RH consolidés `/workforce/settings/*` (dont `send_signed_copy_to_employee`).

---

## 18. Parcours UX détaillés (différenciés par profil — §11 demande)

- **Admin Company** : onboarding → organisation (preview presets) → accès/rôles → supervision. 
- **Responsable RH** : fiche collaborateur unifiée (hub quotidien) → générer contrat → demander signature → gérer congés/absences → documents → validations.
- **Manager** : vue **équipe** (ses rapports) → planning → valider congés/temps de l'équipe → demandes.
- **Collaborateur** (`/me` enrichi) : pointage → mes congés/planning → **mes contrats & signatures** → mes documents (téléchargement protégé).

**Qualité UX transverse à livrer** : wizards onboarding ; **actions rapides** depuis la fiche ; drawers/dialogs cohérents ; recherche + filtres (dont filtre département/manager sur la liste employés) ; vues liste/cartes selon pertinence ; **actions groupées** ; **import/export** collaborateurs ; empty-states pédagogiques ; messages d'erreur exploitables ; notifications pertinentes ; responsive (corriger grille planning) ; dark mode ; accessibilité ; **remplacer les saisies par ID numérique par des sélecteurs** (documents/DSN/paie) ; **cesser la saisie en centimes bruts** (composant montant). Réutiliser Vuexy/Vuetify/composants existants ; **aucun design-system parallèle**.

---

## 19. Plan de refonte en lots (ordonné largeur-compatible)

> Chaque lot se termine par **ADR + tests verts + build clean + revue anti-dérive (ADR-566)**. Estimation en T-shirt (S/M/L/XL). Les capacités **horizontales** d'abord (doctrine), l'assemblage RH ensuite.

| Lot | Objectif | Portée (existant→proposé) | Dépend. | Migrations | Tests | Critère d'acceptation | Risque | Cplx | Valeur visible |
|---|---|---|---|---|---|---|---|---|---|
| **W-A** | **Nav « Ressources humaines »** | renommer `nav.groups.hr` + router `surface:'hr'→hr` (ou `group:'hr'`) | — | non | nav manifest-driven + `PageLayoutMetaTest` | groupe RH dynamique, se cache si vide, RBAC OK | faible | **S** | ✅ immédiat |
| **W-B** | **Hub Paramètres RH** consolidé | regrouper pauses/temps + types congés + org + templates planning + (signature) | W-A | non | settings hub | 1 seul point de config RH, distinct Core settings | faible | M | ✅ |
| **W-C** | **Fiche collaborateur unifiée** | `EmployeeFileReadModel` composite + onglets conditionnés | W-A | non | read-model + RBAC par onglet | gestion bout-en-bout 1 page ; scope/permissions | moyen | **L** | ✅✅ fort |
| **W-D** | **RBAC sensible + scope** | activer `compensation_*`/`sensitive_read` + masquage (modèle Members) + scope manager | — | peut-être (pivots) | matrice RBAC | salaire/sensibles gardés ; manager voit ses rapports | **élevé (sécu)** | L | (invisible, critique) |
| **W-E** | **Presets organisationnels** | extension `JobdomainGate`/resolver/overlay + `default_departments/job_roles` + 1 jobdomain preuve | — | **additives** | preset idempotent + non-destructif | preview→apply→reset sans écraser le custom | moyen | **L** | ✅ |
| **W-F** | **Onboarding RH guidé** | wizard first-run sur presets | W-E | non | e2e onboarding | ajouter 1 collaborateur sans config exhaustive | faible | M | ✅✅ |
| **W-G** | **Modernisation modules existants** | pickers, montants, bulk, import/export, calendrier congés réel, confirmations | W-C | non | e2e par page | suppression saisie ID/centimes ; import employés | faible | L | ✅ |
| **W-H** | **Éditeur de templates (horizontal)** | câbler CRUD `DocumentTemplate` + Tiptap + merge-fields + preview | — | non | templates CRUD + versioning | Company édite/publie/version un modèle ; Platform intact | moyen | **L** | ✅ |
| **W-I** | **Génération documentaire câblée** | génération contrat depuis la fiche + téléchargement **auth-gated** (fix F8) | W-C,W-H | non | génération + download protégé | contrat généré/prévisualisé/archivé ; download sécurisé | moyen | M | ✅✅ |
| **W-J** | **Workforce Contract (assemblage)** | bibliothèque modèles market/jobdomain + cycle doc + échéances | W-I,W-E | additives (template meta) | cycle contrat e2e | parcours embauche contrat complet | moyen | L | ✅✅ |
| **W-K** | **Workforce Signature (horizontal)** | `SignatureRequest/Signer` + `SignatureProvider` abstrait + Null/manual + workflow + supervision + **config copies** (§13) | W-I | **additives** | workflow + provider mock + webhooks | demander/suivre/signer/preuves ; copie salarié config | **élevé (juridique)** | **XL** | ✅✅ |
| **W-L** | **Intégration documentaire** | unifier dépôt RH sur `Core/Documents` + rétention par Market | W-I | non | dépôt + rétention | un dépôt, docs signés protégés | moyen | M | ✅ |
| **W-M** | **Parcours collaborateur** `/me` enrichi | mes contrats/signatures/documents protégés | W-I,W-K | non | e2e self-service | collaborateur signe/consulte ses docs | faible | M | ✅ |
| **W-N** | **Tableaux de bord RH différenciés** | `GET /workforce/overview` + widgets par rôle | W-C | non | dashboard par rôle | effectifs/présences/congés/contrats/docs/signatures/actions | faible | M | ✅ |
| **W-O** | **Événements & notifications** | bus events workforce + listeners (notifications/archivage/alertes) | W-J,W-K | non | events + notifications | embauche/avenant/signature notifiés | moyen | M | ✅ |
| **W-P** | **Remédiation doctrine P1** | sortir métier de `Core/Workforce` + retirer `if('FR')` | transverse | non (refactor) | régression complète | 0 `if('FR')` Core ; conformité ADR-562 | **élevé** | L | (invisible) |
| **W-Q** | **Établissements/services/équipes/postes** | nouvelles entités org (**si décidé §22**) | W-E | **additives** | org étendue | multi-site + équipes si validé | élevé | XL | ✅ (si décidé) |
| **W-R** | **Tests E2E & sécurité** | Playwright par parcours + audit sécu | tous | non | e2e + sécu | parcours 4 profils verts ; 0 fuite | moyen | L | (qualité) |

**Priorisation valeur rapide** : W-A → W-C → W-D → W-G (quick wins UX + sécurité) ; puis horizontaux W-E/W-H/W-K ; puis assemblage W-J/W-I/W-L/W-M/W-N ; W-P en continu ; W-Q/W-R selon décisions.

---

## 20. Tests & critères d'acceptation (transverses)

- **Backend** : `php artisan test` vert à chaque lot ; nouveaux tests ciblés (nav RH manifest-driven ; read-model fiche ; RBAC sensible + scope manager ; presets idempotents/non-destructifs ; templates versioning ; génération+download protégé ; signature workflow + provider mock + webhooks ; events).
- **Frontend** : `pnpm build` clean ; Playwright par parcours (admin/RH/manager/collaborateur), responsive, dark mode, 0 erreur JS, states vides/erreurs.
- **Sécurité (bloquant)** : aucun accès salaire/sensibles/documents sans permission fine ; scope manager/établissement effectif ; téléchargements auth-gated ; isolation tenant ; Platform sans accès HR implicite.
- **Doctrine** : revue anti-dérive ADR-566 ; aucun `if('FR')` ajouté ; capacités horizontales hors `Core/{Vertical}` ; aucun module vendable sans surface livrée ; aucune conformité juridique auto-affirmée.
- **Données** : aucune migration destructive ; aucun contrat/doc signé modifié rétroactivement ; presets jamais destructifs du custom.

---

## 21. Décisions métier à arbitrer (bloquantes, par lot concerné)

1. **[STRATÉGIE — §0.2]** Approuver le reclassement (stabilisation + capacités horizontales) malgré ADR-561/566, ou reporter l'extension. **Décision de comité (ADR-566.7).** — *bloque tout.*
2. **[Avenants — W-J]** Avenant = nouvelle **version** du contrat courant (recommandé) vs nouveau contrat ? (ADR-578 explicitement différé.)
3. **[Congés — W-G]** Buckets annuels / acquisition : garde par bucket (strict FR) vs moteur d'acquisition+report **par Market** (ADR-573 différé).
4. **[Établissements — W-Q]** Introduire `Establishment` (multi-site) et services/équipes/postes, ou rester Department(auto-imbriqué)+JobRole ? (« NON-DÉCIDÉ » repo audit.)
5. **[Type d'entreprise — W-E]** Nouveau `company_type`/`org_profile_key` explicite, ou dériver de `naf_code`+`average_headcount` ?
6. **[Signature — W-K]** Niveau juridique cible (simple/avancé/qualifié) par Market ; **prestataire** (eIDAS UE ; régime Maroc à qualifier — aucun market MA n'existe) ; accusé de lecture requis ?
7. **[Markets — transverse]** Ouvrir `MA`/autres markets (aujourd'hui FR+GB) impacte presets, conventions, signature, fiscalité.
8. **[Copies signées — W-K/§13]** Politique par défaut `send_signed_copy_to_employee` + obligations légales de mise à disposition par Market.
9. **[Remédiation P1 — W-P]** Timing de sortie du métier hors `Core/Workforce` (gros refactor) vs priorité valeur.
10. **[Paie/DSN]** Confirmer le **gel** (ADR-561.5) : aucune extension paie/DSN dans ce programme.

---

### Références (existant, ancrage)
Modules/nav : `app/Modules/Workforce/*Module.php`, `app/Core/Modules/{ModuleManifest,ModuleRegistry,ModuleGate,EntitlementResolver}.php`, `app/Core/Navigation/{NavBuilder,NavGroupRegistry,NavItem}.php`, `resources/js/composables/useCompanyNav.js`. · Données : `app/Core/Workforce/**` (Employee, EmploymentContract, CompensationPlan, Department, JobRole, Shift, TimeEntry, Timesheet*, Leave*, Payroll*, Dsn*), `app/Core/Documents/{DocumentTemplate,GeneratedDocument}.php` + `Services/{DocumentPdfGenerator,DocumentVariableResolver,DocumentTemplateResolver}.php`. · API/RBAC : `routes/company.php:409-593`, `app/Company/RBAC/*`, `app/Core/RBAC/PermissionCatalogBuilder.php`, `app/Company/Security/CompanyAccess.php`. · Composition : `app/Core/Jobdomains/{JobdomainRegistry,JobdomainGate,JobdomainPresetResolver,JobdomainMarketOverlay,PresetReconciler}.php`, `app/Core/Markets/{Market,MarketRuleSet,ConventionCollective}.php`, `app/Core/Workforce/Services/WorkforceRuleResolver.php`. · Réutilisables : `resources/js/@core/components/TiptapEditor.vue`, `resources/views/billing/invoice-pdf.blade.php` (pattern PDF brandé), `app/Modules/Core/Documents/**` (coffre + download auth-gated). · Doctrine : ADR-561→567, 576→579 (`docs/bmad/04-decisions.md`).
