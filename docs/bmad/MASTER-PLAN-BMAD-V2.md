# MASTER PLAN BMAD V2 — Audit global produit & architecture Leezr

> **Statut : DIAGNOSTIC — mode audit uniquement. Aucune implémentation, migration, commit ni déploiement.**
> Date : 2026-11-07 · Base : `origin/dev` @ `89767a7` · Méthode : 7 audits de code parallèles (sous-agents) réconciliés + connaissance directe Smart Planning M1→M6.C.
> Convention : **[FAIT]** = vérifié dans le code (chemin cité) · **[HYP]** = hypothèse à confirmer · **[RECO]** = recommandation · **[DÉCISION]** = arbitrage ouvert.
> Classification modules : **A** Fonctionnel · **B** Partiel · **C** Infra seule · **D** Démo/mock · **E** Catalogue fictif · **F** Legacy/absent.

---

## 1. EXECUTIVE SUMMARY — Ce que Leezr sait réellement faire aujourd'hui

Leezr est un **SaaS B2B multi-tenant techniquement mûr sur son ossature**, mais dont **le socle de commercialisation n'est pas prêt pour la production** et dont **le catalogue surpromet par rapport au produit vivant**.

**Ce qui marche réellement [FAIT] :**
- **Un vertical vivant** : la logistique (1 seul jobdomain peuplé : `logistique`) + une **suite RH/Workforce horizontale large et testée** (employés, congés, pointage, planning, documents, organisation, onboarding = classe A).
- **Le back-office Platform** administre réellement le SaaS de bout en bout (companies, users, RBAC, plans, jobdomains, markets, billing, presets, support/ops) avec un **RBAC par utilisateur** (pas superadmin-only), fail-closed, et un **menu 100 % manifest-driven**.
- **Les parcours B2B Company** sont câblés de bout en bout : inscription self-service → jobdomain → plan/modules → **invitation de collaborateurs (flux complet)** → rôles → sites/équipes → exploitation → facturation/relance automatiques.
- **Le moteur de facturation V1** est complet et instrumenté (state-machine gardée, anti-rétrofacturation, grand livre, dunning, reconciliation).

**Ce que nous prétendons offrir mais qui n'existe pas / n'est pas vendable honnêtement [FAIT] :**
- **3 modules logistiques fictifs** (`logistics_fleet`, `logistics_tracking`, `logistics_analytics`) : **coquilles vides** (zéro route/page/UseCase) mais **seedés vendables** avec un prix → **un client peut payer et « activer » du vide**.
- **Aucun encaissement réel en PROD par défaut** : Stripe n'est pas activé en production, le provider par défaut (`internal`) **lève une exception** à l'encaissement → abonnements payants `active` **sans jamais prélever**.
- **Paie, DSN, Signature** sont des moteurs réels mais **volontairement bridés** (indicatif / transmission OFF / pas de prestataire certifié) → vendables uniquement en « Bêta/préparation », pas comme produit réglementaire garanti.

**La vérité produit en une phrase** : *Leezr est un excellent logiciel RH/planning logistique mono-vertical, emballé dans un catalogue multi-module/multi-vertical dont une partie est fictive et dont la chaîne d'encaissement n'est pas branchée.* La priorité n'est pas d'ajouter des fonctionnalités : c'est de **réconcilier la promesse commerciale avec la réalité du code** et de **brancher l'encaissement** avant toute vente.

---

## 2. INVENTAIRE EXHAUSTIF DES MODULES (52 manifestes)

Source de vérité = code (`ModuleDefinition` scannés par `ModuleRegistry::discoverModules()` `app/Core/Modules/ModuleRegistry.php:33`). **[FAIT] Il n'existe AUCUN flag `is_fictional`** (0 occurrence repo). Les flags commerciaux (`is_listed`, `is_sellable`, `addon_pricing`) vivent en base (`platform_modules`) et sont positionnés par `ModuleProductConfigSeeder` **sans contrôle d'existence d'implémentation**.

### 2a. Modules COMMERCIAUX (scope company)
| Module | Classe | Vendable (seed) | Preuve |
|---|---|---|---|
| `logistics_shipments` | **A** | via jobdomain default | routes `company.php:356-375`, UseCases Shipment, migration shipments, pages shipments |
| `workforce` (employés) | **A** | per-seat | `EmployeeController`, tables employees/contracts, tests |
| `workforce_leave` | **A** | 19€ | UseCases leave + ledger, tables leave_* |
| `workforce_planning` | **A** | 29€ | Planning/Http, shifts/coverage/templates (M1→M6.C) |
| `workforce_documents` | **A** | 9€ | GenerateDocument (dompdf), coffre |
| `workforce_payroll` | **B** | per-seat | moteur brut→net testé mais « indicatif » (`_PayrollDisclaimer.vue:2-5`) ; DSN embarquée |
| `workforce_contract` | **A** | ❌ non tarifé → `contact_sales` | CreateContract/ActivateContract, templates |
| `workforce_signature` | **B** | ❌ non tarifé → `contact_sales` | PAdES natif réel ; **aucun prestataire eIDAS certifié** (`InternalSignatureAdapter` DEV-only) |
| **`logistics_fleet`** | **E** | ✅ **vendable, per-seat** | **coquille vide** `FleetModule.php`, navItems/routes/perms `[]` |
| **`logistics_tracking`** | **E** | ✅ **vendable, 29€** | **coquille vide** `TrackingModule.php` |
| **`logistics_analytics`** | **E** | ✅ **vendable, 19-49€** | **coquille vide** `AnalyticsModule.php` |

### 2b. Modules CORE (scope company, toujours inclus) — 14
`core.{audit, workflows, billing, dashboard, documentation, documents, jobdomain, members, modules, notifications, roles, settings, support, theme}` — entitled inconditionnellement (`EntitlementResolver:36`). Classés **A/C** (présumé, consommés partout : members, roles, billing, documents, audit confirmés fonctionnels par les autres audits).

### 2c. Modules PLATEFORME/ADMIN (scope admin, non commercialisés) — 25 + 1
`platform.{activity, ai, alerts, audience, audit, automations, billing, companies, dashboard, documentation, documents, email, fields, jobdomains, markets, modules, notifications, plans, realtime, roles, security, settings, support, translations, users}` + `payments.stripe` (hidden). **[FAIT]** Tous servis par des pages Platform fonctionnelles (voir §4). Classés **A** majoritairement.

**Sous-domaines Workforce (détail, classe) :** Employés **A** · Congés **A** · Pointage/Temps **A** · Paie **B** · DSN **B** · Documents **A** · Signature **B** · Organisation **A** · Onboarding **A** · Notifications **B** (planning+signature seulement).

---

## 3. TABLEAU DES ÉCARTS catalogue / code / UX

| Module/Fonction | Catalogue (vendu ?) | Code (implémenté ?) | UX (accessible ?) | Écart | Gravité |
|---|---|---|---|---|---|
| `logistics_fleet` | **OUI** (sellable+prix) | **NON** (vide) | **NON** (0 page) | Vendu sans produit | **P0** |
| `logistics_tracking` | **OUI** | **NON** | **NON** | Vendu sans produit | **P0** |
| `logistics_analytics` | **OUI** | **NON** | **NON** | Vendu sans produit | **P0** |
| `workforce_contract` | NON (contact_sales) | **OUI** (A) | OUI (embarqué Documents) | Livrable non monétisé | P2 |
| `workforce_signature` | NON (contact_sales) | **OUI** mais non certifié | OUI (embarqué Documents) | Livrable non certifié, non tarifé | P2 |
| `workforce_payroll` | OUI (per-seat) | **OUI** mais « indicatif » | OUI | Vendu sans état « Bêta » explicite | P1 |
| DSN (dans payroll) | — | **OUI** mais transmission OFF | OUI | Capacité réglementaire non activée | P2 |
| Encaissement Stripe | implicite (plans payants) | Adapter complet mais **non seedé PROD** | — | Paiement impossible par défaut | **P0** |

---

## 4. CARTOGRAPHIE PAGES PLATFORM (synthèse)

**[FAIT] Toutes fonctionnelles, aucune page vide/mock, aucun menu mort.** Détail : `platform/{dashboard, companies, access(users/roles/logs), plans, catalog(modules/jobdomains/fields/documents/plans), modules, jobdomains, markets, organization-presets, contract-templates, billing(15 onglets), communications, support, messaging, operations(health/alerts/security/ai/usage/automations/realtime), settings, account, documentation}`. Capacités d'admin classées **A** sauf : **Entitlements** (pas d'écran dédié, dérivé = **C**), **Stockage** (absent = **F**). RBAC par user respecté (`EnsurePlatformPermission` 403 fail-closed, `routes/platform.php` gating `view_*`/`manage_*` séparés). Menu via `NavBuilder::forAdmin` (manifest-driven).

Incohérences mineures **[HYP]** : quelques routes frontend sans `meta.permission` (companies/index, markets, settings…) s'appuyant sur le 403 API ; `companies/[id]` sur-gardé (`manage_companies`) vs API détail (`view_companies`) ; `operations/[tab]` gaté par `platform.alerts` alors qu'il héberge Health/AI/Security.

---

## 5. CARTOGRAPHIE PAGES COMPANY (synthèse)

**[FAIT] Base mature, branchée, aucune « coming soon ».** Pages OK : dashboard, roles, audit, billing(+tabs/invoices/pay/consolidated), documents, members(+[id]), modules(+[key]), my-deliveries, notifications, profile, shipments, support, workflows, workforce/{me, onboarding, employees, time, leave, planning, documents, payroll, dsn, signature, contract/templates, organization, settings}.
**Stubs REDIRECT (compat, voulus)** : `company/plan`, `billing/index`, `workforce/index`→dashboard, `workforce/configuration`→settings/worktime.
**MOCK/DEV exposé [FAIT]** : `company/dev-lab.vue` gardé par `workforce.view` (atteignable par URL par tout manager RH), données factices, **pas de flag d'environnement** → **P1**.
**Routes orphelines [FAIT]** : `dsn/`, `signature/`, `contract/templates` conçues `embedded` mais exposent aussi une route plein-écran sans menu/fil d'Ariane → **P3**.

Menu company = `NavBuilder::forCompany` (manifest + filtres plan/jobdomain/permission/surface/roleLevel), titres i18n `nav.company.*`. **[FAIT] Groupes morts** `identity/contact/dispatch/driving` (`NavGroupRegistry.php:36-39`) déclarés sans items → élagués mais dette de config.

---

## 6. CARTOGRAPHIE PERMISSIONS & ENTITLEMENTS

**[FAIT] Deux RBAC distincts** : Company (`CompanyRole`/`CompanyPermission` + `Membership.company_role_id`) et Platform (`PlatformRole`/`PlatformPermission`). Catalogues agrégés depuis les manifestes (`CompanyPermissionCatalog`, `PlatformPermissionCatalog`). Enforcement : middleware route (`EnsurePlatformPermission`, `company.access:use-module`), guard routeur frontend, filtrage hub/onglet, `useCan` colonne/action. **Aucune action affichée sans droit détectée.**

**[FAIT] Entitlements = calcul dynamique, PAS de table.** `EntitlementResolver::check` (5 gates) : Core→toujours · minPlan · jobdomain compatible · source (jobdomain default / dépendance) · **addon (`addon_pricing != null`)**. `ModuleGate::isActive` ajoute `is_enabled_globally` + existence d'un `CompanyModuleActivationReason`. Accès métier global coupé si essai expiré / statut sub ∈ {expired, suspended, cancelled} (`restrictsBusinessAccess`, request-time).

**[FAIT] Faille commerciale** : Gate 5 autorise l'accès **dès `addon_pricing != null`, sans vérifier le paiement** → la facture addon part en dunning mais l'accès reste ouvert.

---

## 7. ANALYSE DES PARCOURS CLIENTS (10)

| # | Parcours | Maturité | Réserve clé |
|---|---|---|---|
| 1 | Création Company | **A** | self-service `POST /register` → `RegisterCompanyUseCase` |
| 2 | JobDomain unique | **A** (immuable) | choix définitif à l'inscription (`CompanyJobdomainController:42-47`) |
| 3 | Plan + modules | **A** | — |
| 4 | **Invitation collaborateurs** | **A** | 2 chemins (Membres + fiche RH) complets ; **email = reset-password générique** (pas d'invitation brandée) |
| 5 | Rôles/permissions | **A** | invariant anti-admin |
| 6 | Sites & équipes | **A** | departments + work locations + presets |
| 7 | Démarrage module métier | **A** | Workforce onboarding |
| 8 | Exploitation quotidienne | **A** (Workforce) | largeur hors-Workforce non prouvée |
| 9 | Documents/notifs/audit | **A** | notifications RH partielles (B) |
| 10 | Facturation/renew/suspension | **A** moteur / **réserve** | **suspension non enforced au request-time** (`EnsureCompanyAccess` ne check pas `isSuspended()`) ; checkout payant incomplet |

**[FAIT] Aucun parcours structurellement bloquant.** Les deux vrais risques avant PROD : (1) enforcement serveur de la suspension ; (2) traitement des inscriptions payantes dont le checkout échoue.

---

## 8. AUDIT UX TRANSVERSAL

**Points forts [FAIT]** : navigation 100 % manifest-driven (un moteur `NavBuilder`), gating cohérent multi-niveaux, i18n maîtrisé côté Company, pas de menu mort (pruning), pas de bouton sans handler.
**À corriger** : (P0) 3 modules vendus sans écran ; (P1) `dev-lab` non env-gated ; (P3) routes embarquées orphelines, groupes nav morts, jargon finance côté Platform (`forensics/dunning/ledger`), absence d'écran Entitlements/Stockage. **Différences Platform/Company expliquées** (même moteur, taxonomies et `roleLevel` distincts — voulu).

---

## 9. ANALYSE DES RISQUES COMMERCIAUX / CONTRACTUELS

| Risque | Gravité | Preuve | Effet |
|---|---|---|---|
| **R1 — Vente de modules vides** | **P0** | `ModuleProductConfigSeeder:48-83` + `EntitlementResolver:75` + manifestes vides | Client paie pour fleet/tracking/analytics sans aucun écran |
| **R2 — Pas d'encaissement PROD** | **P0** | `PaymentModuleSeeder:49` (Stripe local/testing only), `InternalPaymentAdapter:124-137` (throw) | Abonnements payants `active` sans prélèvement → **revenu fantôme** |
| **R3 — Accès avant paiement** | **P0** | `EntitlementResolver:73-77` | Addon fourni gratuitement si dunning échoue |
| **R4 — Clés Stripe test en dur** | P1 | `PaymentModuleSeeder:60-62` | Secret exécutable, à ne jamais laisser fuiter |
| **R5 — Paie/DSN/Signature « vendus » sans garantie** | P1 | disclaimers + kill-switches | Risque contractuel si présentés comme conformes |
| **R6 — Renouvellement différé silencieux** | P2 | `BillingRenewCommand:182-198` (`pricing_review_required`) | Perte de CA si files non traitées |
| **R7 — Billing dépend du cron daily** | P2 | `routes/console.php:35,43` | Cron mort = ni renew ni suspension |

**États de cycle de vie module [RECO]** à introduire (remplace l'absence de `is_fictional`) : `Disponible / Bêta / En préparation / Interne / Démo / Désactivé`, avec **garde** : `is_sellable=true` interdit si (capacités vides) OU (statut ∉ {Disponible, Bêta}).

---

## 10. ARCHITECTURE CIBLE V2 (consolidation, PAS réécriture)

1. **Core = socle horizontal SANS métier.** Déménager `app/Core/Workforce/**` (~110 fichiers : modèles RH, `PayrollCalculationEngine`, `Services/*Calculator`, `Dsn/**`, DTOs) vers `app/Modules/Workforce/**`. Les 134 consommateurs sont déjà dans Modules → déplacement + alias de namespace, pas de refonte logique. La Signature est déjà conforme (modèle de référence).
2. **Multi-tenant garanti hors HTTP.** Introduire `RunWithCompanyContext($company, fn)` (bind + forget) pour jobs/commandes/listeners ; faire du `CompanyScope` no-op un **fail-fast** en contexte queue. Auditer les ~40 `withoutGlobalScope` sur données salarié.
3. **Spécificité pays dans Markets.** Porter les 2 `if('FR')` durs (`WorkTimeComplianceChecker:148`, `PayrollCalculationEngine:324`) dans `MarketRuleSet` ; centraliser les `?? 'FR'`.
4. **Billing : sens de dépendance unique.** Casser le cycle `Core/Billing ↔ Modules/Core/Billing` (6 lignes, 3 fichiers) : le module dépend du Core, jamais l'inverse.
5. **Vérité catalogue = code.** Ajouter un `lifecycle_status` module + test d'invariant `sellable ⇒ capacités non vides`. Le registre jobdomain est sain (composition) mais **vide à 1 vertical** → ne pas multiplier les jobdomains tant que le 2ᵉ n'est pas prouvé.
6. **Encaissement.** Décider et brancher le rail de paiement PROD (Stripe réel hors-code) OU assumer une facturation manuelle/hors-ligne explicite.

---

## 11. MASTER PLAN BMAD V2 PRIORISÉ

### P0 — Intégrité, sécurité, promesses trompeuses, blocages
| Chantier | Problème [preuve] | Critère d'acceptation | Tests | Complexité |
|---|---|---|---|---|
| **P0-A Modules fictifs non vendables** | 3 coquilles logistics vendables [`FleetModule.php`, seeder:48-83] | `is_sellable=false` ou `visibility:hidden` pour fleet/tracking/analytics ; invariant « sellable ⇒ capacités non vides » | test PHPUnit d'invariant catalogue ; e2e catalogue ne les propose plus | **S** |
| **P0-B Encaissement PROD** | Stripe non seedé, `internal` throw [`PaymentModuleSeeder:49`] | Décision rail paiement ; si Stripe : module paiement réel PROD (clés hors-code) + `billing.driver` défini ; si manuel : plans payants bloqués en self-service | test : souscription payante → facture encaissée (ou refus explicite) | **M** |
| **P0-C Accès avant paiement** | Gate 5 sans paiement [`EntitlementResolver:73-77`] | accès addon révoqué après échec dunning (grace défini) | test : dunning épuisé → module coupé | **M** |
| **P0-D Isolation multi-tenant hors HTTP** | `CompanyScope` no-op [`CompanyScope:17`] | wrapper contexte pour jobs/CLI + fail-fast ; audit `withoutGlobalScope` données salarié | test : job tenant-scoped sans contexte lève/filtre | **M** |
| **P0-E Suspension enforced** | `EnsureCompanyAccess` ne check pas `isSuspended()` [à vérifier] | company suspendue → 402/403 API | test : company suspended → API bloquée | **S** |
| **P0-F Secret Stripe en dur** | clés test seeder [`PaymentModuleSeeder:60-62`] | clés hors-code (env/secret) | revue sécurité | **S** |

### P1 — Parcours B2B essentiels
- **P1-A** Email d'invitation brandé (dédier une `InvitationNotification` vs reset-password générique). **S**
- **P1-B** Inscription payante : état « inscription incomplète » si checkout échoue (pas de compte à moitié exploitable). **M**
- **P1-C** `dev-lab.vue` : gater par flag d'environnement. **S**
- **P1-D** États commerciaux explicites Paie/DSN/Signature (« Bêta / indicatif ») dans le catalogue. **S**

### P2 — Consolidation modules exploitables
- **P2-A** Déménagement `app/Core/Workforce/**` → Modules (règle d'or). **L** (mécanique, risque faible, gros volume).
- **P2-B** Tarifer `workforce_contract` + `workforce_signature` (ou garder contact_sales assumé). **S**
- **P2-C** Smart Planning : M6.D (vues Jour/Agenda/Postes/Mois) + M6.E (mobile, fixtures e2e isolées, perf 100). **L**
- **P2-D** Dette M4 : plafond 2000 cellules, perf ~57 req/shift, charge 100×8 non prouvée → exécution batchée tâche de fond. **M**
- **P2-E** Tests e2e instables (sp1a1/phaseA/lotA-inline/lot4b3-copy) → fixtures isolées, fin des `.first()`. **M**

### P3 — Expérience & cohérence UX
- Cycle de vie module (`lifecycle_status`) + écran Entitlements + admin Stockage. Routes embarquées orphelines → redirect hub. Nettoyage groupes nav morts. Libellés i18n jargon Platform.

### P4 — Extensions métier
- 2ᵉ vertical léger (prouver la composition jobdomain). Fleet/tracking/analytics réels **si** stratégiques. Prestataire eIDAS certifié (signature). Activation DSN production. Facturation consolidée V2.

---

## 12. DÉCOUPAGE EN CHANTIERS EXÉCUTABLES (ordre de livraison recommandé)

1. **Sprint Intégrité commerciale (P0-A, P0-F, P1-D)** — retirer/masquer les modules vides, sécuriser les secrets, états Bêta. *Faible effort, supprime le risque juridique immédiat.*
2. **Sprint Encaissement & accès (P0-B, P0-C, P0-E)** — brancher le rail de paiement, conditionner l'accès au paiement, enforcer la suspension. *Débloque la commercialisation réelle.*
3. **Sprint Isolation tenant (P0-D)** — wrapper contexte + audit scopes. *Sécurité données.*
4. **Sprint Onboarding B2B (P1-A, P1-B, P1-C)** — invitation brandée, checkout robuste, dev-lab gated.
5. **Sprint Consolidation Core (P2-A)** — déménagement Workforce hors Core.
6. **Sprint Smart Planning (P2-C, P2-D, P2-E)** — M6.D/E + dettes M4 + e2e isolés.
7. **Sprint UX/catalogue (P3)**.
8. **Sprint Extensions (P4)**.

**[FAIT] Acquis M1→M6.C conservés** mais **non présumés PROD-ready** : Smart Planning reste en stabilisation (M6.D/E non commencés, dette M4, 100 collaborateurs non mesuré, 4 e2e ambiants ouverts).

---

## 13. QUESTIONS D'ARBITRAGE (décisions à prendre)

1. **Modules fictifs (fleet/tracking/analytics)** : les **retirer du catalogue**, les passer **« En préparation » non vendables**, ou **les livrer** (2ᵉ vertical logistique) ? *[RECO : les masquer/retirer immédiatement, décider la livraison plus tard.]*
2. **Encaissement PROD** : activer **Stripe réel maintenant** (go-to-market payant) ou assumer une **facturation manuelle/hors-ligne** tant qu'il n'y a pas de clients payants ? *[RECO : décision stratégique — sans l'un des deux, pas de vente honnête.]*
3. **Accès avant paiement (Gate 5)** : **révoquer l'accès addon post-dunning** (grace configurable) ou garder « accès puis facture » ?
4. **Consolidation Core/Workforce (P2-A)** : lancer le déménagement **maintenant** (dette doctrine) ou **après** le 2ᵉ vertical ?
5. **Paie / DSN / Signature** : les positionner **commercialement « Bêta/indicatif »**, ou **investir pour certification** (eIDAS signature, DSN production, paie garantie) ?
6. **Ordre** : stabiliser le **socle commercial (P0)** AVANT de finir **Smart Planning M6.D/E** ? *[RECO : oui — un Smart Planning parfait dans un SaaS qui n'encaisse pas ne génère pas de revenu.]*
7. **`workforce_contract` / `workforce_signature`** livrables mais non tarifés : les **tarifer** ou les garder en **contact_sales** ?

---

---

## ADDENDUM (2026-11-07) — Réconciliation `is_fictional` & livraison P0-A

**[FAIT] Contradiction `is_fictional` résolue.** Grep du working tree + **tout l'historique git** (`git log --all -S "is_fictional"` / `"fictional"` / `"MigrateExistingModulesToFictional"`) = **ZÉRO occurrence** ; aucune migration, aucun fichier, aucun commit. Branche/SHA audités = `dev @ 89767a7` ; les 7 agents ont analysé le **même** état. **Le champ `is_fictional` / la migration `MigrateExistingModulesToFictional` n'ont JAMAIS existé dans ce repo.** Le contrat réel de commercialisation est porté par `is_listed` + `is_sellable` + `addon_pricing` (table `platform_modules`). Toute référence antérieure à `is_fictional` (mémoire/échange) était une **intention jamais implémentée**. Aucune décision du Master Plan ne dépend de ce champ ; §2/§9 confirmés.

**[FAIT] P0-A livré (ADR-732).** L'autorité serveur unique **`ModuleCommercialPolicy`** (statut déclaré `commercialStatus` au manifeste, sans migration) garantit qu'un module n'est achetable que s'il est réellement commercialisable (`available`) — un prix ne suffit jamais. Les 3 modules fictifs (`logistics_fleet/tracking/analytics`) passent `preview` : conservés au référentiel, retirés des surfaces commerciales (catalogue sans prix « En préparation », activation refusée 422), **sans suppression ni révocation** (cf. `P0A-IMPACT-REPORT.md`). Tests : 9 PHPUnit P0-A + 1214 modules/billing + 837 workforce/nav verts + 2 Playwright. **P0-B/C/D/E restent ouverts** (voir §11).

---

*Fin du Master Plan BMAD V2. P0-A traité ; P0-B→P4 en attente d'arbitrage. Aucune donnée/plan muté.*
